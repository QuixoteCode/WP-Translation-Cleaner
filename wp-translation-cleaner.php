<?php
/**
 * Plugin Name: WP Translation Cleaner
 * Description: Analiza y pone en cuarentena archivos de traduccion de WordPress que no quieras conservar. Se accede desde Herramientas > Limpiador de traducciones en el panel de administracion de WordPress. Tal y como esta el plugin tiene varios riesgos de seguridad y de integridad de archivos que conviene corregir antes de utilizarlo en produccion
 * Version: 0.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Lucas
 */

 // Impide la ejecucion directa fuera del entorno de WordPress
if (!defined('ABSPATH')) {
    exit;
}

final class WP_Translation_Cleaner {

    // Identificador utilizado en el menu de administracion
    const SLUG = 'wp-translation-cleaner';

    const QUARANTINE_DIR_NAME = 'wp-translation-cleaner-quarantine';

    /**
     * Registra las acciones administrativas del plugin
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));

        // Procesa las solicitudes POST recibidas desde admin-post.php
        add_action('admin_post_wptc_scan', array(__CLASS__, 'handle_scan'));
        add_action('admin_post_wptc_quarantine', array(__CLASS__, 'handle_quarantine'));
        add_action('admin_post_wptc_restore', array(__CLASS__, 'handle_restore'));
        add_action('admin_post_wptc_purge', array(__CLASS__, 'handle_purge'));

        // Permite borrar todos los idiomas en cuarentena
        add_action('admin_post_wptc_purge_all', array(__CLASS__, 'handle_purge_all'));
    }

    /**
     * Agrega el plugin al menu Herramientas de WordPress
     */
    public static function menu() {
        add_management_page(
            'Limpiador de traducciones',
            'Limpiador de traducciones',
            'manage_options',
            self::SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Devuelve la ruta absoluta del directorio de traducciones
     */
    private static function languages_dir() {
        return trailingslashit(WP_CONTENT_DIR) . 'languages';
    }

    /**
     * Devuelve la ruta absoluta del directorio de cuarentena
     */
    private static function quarantine_dir() {
        return trailingslashit(dirname(untrailingslashit(ABSPATH)))
            . self::QUARANTINE_DIR_NAME;
    }

    /**
     * Crea la cuarentena y agrega protecciones basicas para servidores Apache
     * Devuelve la ruta del directorio o un WP_Error
     */
    private static function ensure_quarantine() {
        $dir = self::quarantine_dir();

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error(
                'wptc_mkdir',
                'No se pudo crear la carpeta de cuarentena.'
            );
        }

        // Archivo indice para evitar el listado de directorios en algunos servidores
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }

        // Bloquea el acceso HTTP en Apache; no protege por si solo en todos los servidores
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents(
                $dir . '/.htaccess',
                "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }

        return $dir;
    }

    /**
     * Valida el ambito solicitado para limitar el procesamiento a grupos conocidos
     */
    private static function valid_scope($scope) {
        return in_array($scope, array('core', 'plugins', 'themes', 'all'), true)
            ? $scope
            : 'core';
    }

    /**
     * Extrae el codigo regional del idioma a partir del nombre del archivo
     * Admite formatos como es_ES y es-ES
     * Devuelve una cadena vacia cuando no reconoce un codigo
     */
    private static function get_language_code($filename) {
        // Detecta codigos habituales como fr_FR, es_ES, pt_BR y zh_CN
        if (preg_match('/(?:^|[-_.])([a-z]{2,3}_[A-Z]{2})(?=[-_.]|$)/', $filename, $m)) {
            return $m[1];
        }

        // Normaliza variantes con guion al formato con guion bajo
        if (preg_match('/(?:^|[-_.])([a-z]{2,3}-[A-Z]{2})(?=[-_.]|$)/', $filename, $m)) {
            return str_replace('-', '_', $m[1]);
        }

        return '';
    }

    /**
     * Clasifica un archivo segun su ubicacion dentro del directorio de idiomas
     * Los archivos que no pertenecen a plugins ni temas se consideran del nucleo
     */
    private static function classify_file($path) {
        $name = basename($path);

        // Obtiene la ruta relativa para identificar el grupo del archivo
        $relative = ltrim(str_replace(self::languages_dir(), '', $path), '/\\');

        if (
            strpos($relative, 'plugins' . DIRECTORY_SEPARATOR) === 0
            || strpos($relative, 'plugins/') === 0
        ) {
            return 'plugins';
        }

        if (
            strpos($relative, 'themes' . DIRECTORY_SEPARATOR) === 0
            || strpos($relative, 'themes/') === 0
        ) {
            return 'themes';
        }

        // Clasificacion por defecto: traducciones del nucleo
        return 'core';
    }

    /**
     * Recorre recursivamente el directorio de idiomas y genera el inventario
     * Solo incluye extensiones permitidas y nombres con idioma reconocible
     * Devuelve una lista de archivos o un WP_Error
     */
    private static function scan_files() {
        $root = self::languages_dir();
        $results = array();

        // Comprueba que el directorio de origen exista y sea legible
        if (!is_dir($root) || !is_readable($root)) {
            return new WP_Error(
                'wptc_no_languages',
                'No se encuentra o no se puede leer wp-content/languages/.'
            );
        }

        // Formatos de traduccion admitidos por el analisis
        $allowed_extensions = array('mo', 'po', 'json', 'php');

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {

                // Omite elementos que no sean archivos y enlaces simbolicos
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }

                $path = $file->getPathname();
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                // Ignora formatos ajenos a la lista permitida
                if (!in_array($ext, $allowed_extensions, true)) {
                    continue;
                }

                $name = $file->getFilename();
                $language = self::get_language_code($name);

                // Evita seleccionar automaticamente archivos de idioma dudoso
                if ($language === '') {
                    continue;
                }

                // Registra los metadatos necesarios para la interfaz y las acciones
                $results[] = array(
                    'path' => $path,
                    'relative' => ltrim(str_replace($root, '', $path), '/\\'),
                    'name' => $name,
                    'language' => $language,
                    'scope' => self::classify_file($path),
                    'size' => (int) $file->getSize(),
                );
            }
        } catch (Exception $e) {
            // Convierte los errores de recorrido en un resultado controlado
            return new WP_Error(
                'wptc_scan_error',
                'Error al analizar archivos: ' . $e->getMessage()
            );
        }

        // Ordena primero por idioma y despues por ruta relativa
        usort($results, function($a, $b) {
            return strcmp(
                $a['language'] . $a['relative'],
                $b['language'] . $b['relative']
            );
        });

        return $results;
    }

    /**
     * Obtiene el idioma actual del sitio con un valor alternativo
     */
    private static function current_site_language() {
        $locale = function_exists('get_locale') ? get_locale() : 'en_US';

        // Normaliza la respuesta al formato regional admitido por el plugin
        return preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $locale)
            ? $locale
            : 'es_ES';
    }

    /**
     * Convierte un tamanho en bytes al formato legible de WordPress
     */
    private static function format_size($bytes) {
        return size_format((float) $bytes, 2);
    }

    /**
     * Redirige a la pagina del plugin mostrando un aviso administrativo
     */
    private static function redirect_notice($message, $type = 'success') {
        $url = add_query_arg(
            array(
                'page' => self::SLUG,
                'wptc_notice' => rawurlencode($message),
                'wptc_type' => $type,
            ),
            admin_url('tools.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Ejecuta el analisis y almacena temporalmente sus resultados por usuario
     */
    public static function handle_scan() {

        // Exige permisos administrativos.
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta accion.');
        }

        // Verifica el nonce para mitigar solicitudes CSRF
        check_admin_referer('wptc_scan');

        $files = self::scan_files();

        if (is_wp_error($files)) {
            self::redirect_notice($files->get_error_message(), 'error');
        }

        // Conserva el inventario durante 15 minutos para la fase de seleccion
        set_transient(
            'wptc_scan_' . get_current_user_id(),
            $files,
            15 * MINUTE_IN_SECONDS
        );

        self::redirect_notice(
            'Analisis completado. Revisa los resultados antes de mover archivos.'
        );
    }

    /**
     * Mueve a cuarentena los archivos seleccionados explicitamente
     * Conserva sus metadatos en un manifiesto para permitir su restauracion
     */
    public static function handle_quarantine() {

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta accion.');
        }

        check_admin_referer('wptc_quarantine');

        // Solo se procesan resultados de un analisis previo no caducado
        $files = get_transient('wptc_scan_' . get_current_user_id());

        if (!is_array($files)) {
            self::redirect_notice(
                'El analisis ha caducado. Vuelve a analizar antes de limpiar.',
                'error'
            );
        }

        // Normaliza el idioma, el ambito y los identificadores seleccionados
        $keep = isset($_POST['keep_language'])
            ? sanitize_text_field(wp_unslash($_POST['keep_language']))
            : self::current_site_language();

        $scope = isset($_POST['scope'])
            ? self::valid_scope(sanitize_text_field(wp_unslash($_POST['scope'])))
            : 'core';

        $selected = isset($_POST['selected'])
            ? array_map('sanitize_text_field', (array) wp_unslash($_POST['selected']))
            : array();

        // Convierte la seleccion en un conjunto para comprobar pertenencias
        $selected = array_fill_keys($selected, true);

        // Admite un codigo regional valido o la opcion de conservar todos
        if ($keep !== 'all' && !preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $keep)) {
            self::redirect_notice('Codigo de idioma no valido.', 'error');
        }

        $qdir = self::ensure_quarantine();

        if (is_wp_error($qdir)) {
            self::redirect_notice($qdir->get_error_message(), 'error');
        }

        // El manifiesto relaciona cada archivo almacenado con su ruta original
        $manifest_file = trailingslashit($qdir) . 'manifest.json';
        $manifest = array();

        if (file_exists($manifest_file)) {
            $old = json_decode((string) file_get_contents($manifest_file), true);

            if (is_array($old)) {
                $manifest = $old;
            }
        }

        $moved = 0;
        $errors = 0;

        foreach ($files as $file) {

            // Aplica el filtro de nucleo, plugins, temas o todos los grupos
            if ($scope !== 'all' && $file['scope'] !== $scope) {
                continue;
            }

            // Excluye el idioma que el administrador desea conservar
            if ($keep !== 'all' && $file['language'] === $keep) {
                continue;
            }

            // Solo procesa archivos seleccionados en la interfaz
            if (!isset($selected[hash('sha256', $file['relative'])])) {
                continue;
            }

            // Resuelve las rutas reales antes de operar sobre el archivo
            $root = realpath(self::languages_dir());
            $source = realpath($file['path']);

            // Rechaza rutas externas, archivos inexistentes y enlaces simbolicos
            if (
                !$root
                || !$source
                || strpos($source, $root . DIRECTORY_SEPARATOR) !== 0
                || !is_file($source)
                || is_link($source)
            ) {
                $errors++;
                continue;
            }

            // Genera un identificador para evitar colisiones de nombres
            $id = gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);

            // Almacena el archivo con un nombre unico y una extension saneada
            $target = trailingslashit($qdir)
                . $id . '--' . sanitize_file_name($file['name']);

            // rename() mueve el archivo sin copiar su contenido
            if (!@rename($source, $target)) {
                $errors++;
                continue;
            }

            // Registra la informacion necesaria para restaurar o eliminar el archivo
            $manifest[$id] = array(
                'id' => $id,
                'original' => $file['relative'],
                'stored' => basename($target),
                'language' => $file['language'],
                'scope' => $file['scope'],
                'size' => $file['size'],
                'date' => current_time('mysql'),
            );

            $moved++;
        }

        // Persiste el manifiesto; el resultado de escritura deberia comprobarse
        @file_put_contents(
            $manifest_file,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        // Invalida el inventario para exigir un nuevo analisis antes de otra limpieza
        delete_transient('wptc_scan_' . get_current_user_id());

        self::redirect_notice(
            sprintf(
                'Archivos puestos en cuarentena: %d. Errores: %d. Revisa la web antes de eliminarlos definitivamente.',
                $moved,
                $errors
            ),
            $errors ? 'warning' : 'success'
        );
    }

    /**
     * Restaura un archivo de cuarentena en su ubicacion original
     */
    public static function handle_restore() {

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta accion.');
        }

        check_admin_referer('wptc_restore');

        // Sanitiza el identificador recibido desde el formulario.
        $id = isset($_POST['restore_id'])
            ? sanitize_file_name(wp_unslash($_POST['restore_id']))
            : '';

        $qdir = self::quarantine_dir();
        $manifest_file = trailingslashit($qdir) . 'manifest.json';

        // Carga el registro que vincula el identificador con el archivo original
        $manifest = file_exists($manifest_file)
            ? json_decode((string) file_get_contents($manifest_file), true)
            : array();

        if (!$id || !isset($manifest[$id])) {
            self::redirect_notice('No se encontro el archivo para restaurar.', 'error');
        }

        $item = $manifest[$id];

        // Limita el nombre almacenado a un archivo dentro de la cuarentena
        $stored = trailingslashit($qdir) . basename($item['stored']);

        // Reconstruye la ruta original eliminando segmentos de traversal basicos (esta limpieza no sustituye una validacion de ruta canonica completa)
        $dest = trailingslashit(self::languages_dir())
            . ltrim(str_replace(array('../', '..\\'), '', $item['original']), '/\\');

        $base = realpath(self::languages_dir());
        $parent = dirname($dest);

        // Comprueba el prefijo de la ruta de destino para limitar la restauracion, conviene reforzar esta comprobacion con rutas canonicas y realpath()
        if (
            !$base
            || strpos(
                wp_normalize_path($dest),
                trailingslashit(wp_normalize_path(self::languages_dir()))
            ) !== 0
        ) {
            self::redirect_notice('Ruta de restauración no valida.', 'error');
        }

        // Recrea la estructura de directorios si ya no existe
        if (!is_dir($parent) && !wp_mkdir_p($parent)) {
            self::redirect_notice('No se pudo crear la carpeta original.', 'error');
        }

        // Evita sobrescribir una traduccion que se haya creado posteriormente
        if (file_exists($dest)) {
            self::redirect_notice(
                'Ya existe un archivo en la ruta original. No se ha sobrescrito.',
                'error'
            );
        }

        // Comprueba que el archivo exista antes de devolverlo a su ubicacion
        if (!is_file($stored) || !@rename($stored, $dest)) {
            self::redirect_notice('No se pudo restaurar el archivo.', 'error');
        }

        // Elimina el registro solo despues de restaurar correctamente el archivo
        unset($manifest[$id]);

        @file_put_contents(
            $manifest_file,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        self::redirect_notice('Archivo restaurado.');
    }

    /**
     * Elimina definitivamente un archivo de cuarentena
     */
    public static function handle_purge() {

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta accion.');
        }

        check_admin_referer('wptc_purge');

        $id = isset($_POST['purge_id'])
            ? sanitize_file_name(wp_unslash($_POST['purge_id']))
            : '';

        $qdir = self::quarantine_dir();
        $manifest_file = trailingslashit($qdir) . 'manifest.json';

        // Recupera el registro del archivo que se desea eliminar
        $manifest = file_exists($manifest_file)
            ? json_decode((string) file_get_contents($manifest_file), true)
            : array();

        if (!$id || !isset($manifest[$id])) {
            self::redirect_notice('No se encontró el archivo.', 'error');
        }

        // Restringe el nombre a un unico componente de ruta
        $stored = trailingslashit($qdir) . basename($manifest[$id]['stored']);

        // Solo elimina archivos regulares y comunica los fallos de unlink()
        if (is_file($stored) && !@unlink($stored)) {
            self::redirect_notice(
                'No se pudo eliminar el archivo.',
                'error'
            );
        }

        // Actualiza el manifiesto despues de la eliminacion
        unset($manifest[$id]);

        @file_put_contents(
            $manifest_file,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        self::redirect_notice('Archivo eliminado.');
    }

    /**
     * Elimina todos los archivos de la cuarentena
     */
    public static function handle_purge_all() {

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta accion.');
        }

        check_admin_referer('wptc_purge_all');

        $qdir = self::quarantine_dir();
        $manifest_file = trailingslashit($qdir) . 'manifest.json';

        $manifest = file_exists($manifest_file)
            ? json_decode((string) file_get_contents($manifest_file), true)
            : array();

        if (!is_array($manifest)) {
            self::redirect_notice('El manifiesto no es válido.', 'error');
        }

        $deleted = 0;
        $errors = 0;

        foreach ($manifest as $item) {
            $stored = trailingslashit($qdir)
                . basename($item['stored']);

            if (is_file($stored) && !is_link($stored)) {
                if (@unlink($stored)) {
                    $deleted++;
                } else {
                    $errors++;
                }
            }
        }

        // Conserva en el manifiesto los archivos que no se pudieron borrar.
        $remaining = array();

        foreach ($manifest as $id => $item) {
            $stored = trailingslashit($qdir)
                . basename($item['stored']);

            if (is_file($stored)) {
                $remaining[$id] = $item;
            }
        }

        file_put_contents(
            $manifest_file,
            wp_json_encode(
                $remaining,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ),
            LOCK_EX
        );

        self::redirect_notice(
            sprintf(
                'Archivos eliminados definitivamente: %d. Errores: %d.',
                $deleted,
                $errors
            ),
            $errors ? 'warning' : 'success'
        );
    }

    /**
     * Renderiza la interfaz administrativa, los resultados y la cuarentena
     */
    public static function render_page() {

        // Restringe el contenido a usuarios con permisos de administracion
        if (!current_user_can('manage_options')) {
            return;
        }

        // Recupera los avisos de la ultima operacion y los sanea para mostrarlos
        $notice = isset($_GET['wptc_notice'])
            ? sanitize_text_field(wp_unslash($_GET['wptc_notice']))
            : '';

        $type = isset($_GET['wptc_type'])
            ? sanitize_key(wp_unslash($_GET['wptc_type']))
            : 'success';

        // Recupera el inventario temporal del usuario actual
        $files = get_transient('wptc_scan_' . get_current_user_id());
        $current = self::current_site_language();

        // Carga el manifiesto de cuarentena y valida su estructura basica
        $qdir = self::quarantine_dir();
        $manifest_file = trailingslashit($qdir) . 'manifest.json';

        $manifest = file_exists($manifest_file)
            ? json_decode((string) @file_get_contents($manifest_file), true)
            : array();

        if (!is_array($manifest)) {
            $manifest = array();
        }

        echo '<div class="wrap"><h1>Limpiador de traducciones</h1>';

        // Explica el alcance del analisis y su caracter
        echo '<p>Analiza archivos de traducción en <code>wp-content/languages/</code>. Los archivos sin codigo de idioma reconocible se omiten. La primera fase solo analiza; no borra ni mueve nada.</p>';

        if ($notice !== '') {
            // Asigna una clase visual segun el tipo de aviso
            $class = $type === 'error'
                ? 'notice notice-error'
                : ($type === 'warning' ? 'notice notice-warning' : 'notice notice-success');

            echo '<div class="' . esc_attr($class) . '"><p>'
                . esc_html($notice)
                . '</p></div>';
        }

        echo '<p><strong>Idioma del sitio:</strong> '
            . esc_html($current)
            . '</p>';

        // Formulario para iniciar el analisis mediante una solicitud POST
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wptc_scan">';
        wp_nonce_field('wptc_scan');
        submit_button('Analizar archivos de traducción');
        echo '</form>';

        if (is_array($files)) {
            // Calcula el tamanho acumulado de los archivos detectados
            $total = 0;

            foreach ($files as $f) {
                $total += (int) $f['size'];
            }

            echo '<h2>Resultado del análisis</h2><p>Archivos reconocidos: '
                . esc_html((string) count($files))
                . ' · Tamaño total reconocido: <strong>'
                . esc_html(self::format_size($total))
                . '</strong></p>';

            echo '<p>Marca únicamente archivos que quieras poner en cuarentena. Se recomienda empezar solo con archivos del núcleo. No marques el idioma principal ni archivos de idioma dudoso.</p>';

            // Formulario para seleccionar y mover los archivos reconocidos
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wptc_quarantine">';
            wp_nonce_field('wptc_quarantine');

            // Los filtros se validan de nuevo en el servidor al procesar el formulario
            echo '<p><label>Alcance: <select name="scope"><option value="core">Solo núcleo</option><option value="plugins">Solo plugins</option><option value="themes">Solo temas</option><option value="all">Todos los grupos</option></select></label> ';

            echo '<label>Idioma a conservar: <select name="keep_language">';
            echo '<option value="' . esc_attr($current) . '">'
                . esc_html($current)
                . ' (idioma del sitio)</option>';
            echo '<option value="es_ES">es_ES (Español de España)</option><option value="es_MX">es_MX (Español de México)</option><option value="all">Conservar todos</option>';
            echo '</select></label></p>';

            // Estos controles solo modifican la seleccion en el navegador
            echo '<p><button type="button" class="button" id="wptc-select-visible">Marcar todos los archivos visibles</button> <button type="button" class="button" id="wptc-unselect-visible">Desmarcar todos</button></p>';

            echo '<div style="max-height:520px;overflow:auto;border:1px solid #c3c4c7;background:#fff"><table class="widefat striped"><thead><tr><th></th><th>Idioma</th><th>Grupo</th><th>Archivo</th><th>Tamaño</th></tr></thead><tbody>';

            foreach ($files as $f) {
                // El hash identifica la ruta relativa sin enviar la ruta absoluta
                $hash = hash('sha256', $f['relative']);

                echo '<tr><td><input class="wptc-file-check" type="checkbox" name="selected[]" value="'
                    . esc_attr($hash)
                    . '"></td>';

                echo '<td>' . esc_html($f['language']) . '</td><td>'
                    . esc_html($f['scope']) . '</td>';

                echo '<td><code>' . esc_html($f['relative']) . '</code></td><td>'
                    . esc_html(self::format_size($f['size']))
                    . '</td></tr>';
            }

            echo '</tbody></table></div>';

            echo '<p><strong>Nota:</strong> la lista puede contener traducciones necesarias para plugins/temas. La cuarentena es reversible (a diferencia de la eliminación), pero revisa la web después de cada lote.</p>';

            // Solicita confirmacion antes de enviar la seleccion al servidor
            submit_button(
                'Poner en cuarentena los archivos marcados',
                'primary',
                'submit',
                true,
                array(
                    'onclick' => "return confirm('¿Poner en cuarentena únicamente los archivos marcados?');",
                )
            );

            echo '</form>';

            // Permite marcar o desmarcar todas las casillas de la tabla
            echo '<script>(function(){var boxes=function(){return document.querySelectorAll(".wptc-file-check")};document.getElementById("wptc-select-visible").addEventListener("click",function(){boxes().forEach(function(b){b.checked=true;});});document.getElementById("wptc-unselect-visible").addEventListener("click",function(){boxes().forEach(function(b){b.checked=false;});});})();</script>';
        }

        echo '<hr><h2>Cuarentena</h2>';

        if (!empty($manifest)) {
            echo '<form method="post" action="'
                . esc_url(admin_url('admin-post.php'))
                . '" onsubmit="return confirm(\'¿Eliminar TODAS las traducciones en cuarentena? Esta acción no se puede deshacer.\');">';

            echo '<input type="hidden" name="action" value="wptc_purge_all">';

            wp_nonce_field('wptc_purge_all');

            echo '<button type="submit" class="button button-link-delete">'
                . 'Eliminar todas las traducciones en cuarentena'
                . '</button>';

            echo '</form>';
        }

        if (empty($manifest)) {
            echo '<p>No hay archivos en cuarentena.</p>';
        } else {
            echo '<p>Los archivos de esta lista se han movido fuera de la carpeta activa de traducciones. Comprueba tu web antes de eliminarlos definitivamente.</p>';

            echo '<table class="widefat striped"><thead><tr><th>Idioma</th><th>Ruta original</th><th>Tamaño</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>';

            foreach ($manifest as $id => $item) {
                // Escapa todos los metadatos antes de insertarlos en el HTML
                echo '<tr><td>' . esc_html($item['language'])
                    . '</td><td><code>' . esc_html($item['original'])
                    . '</code></td><td>'
                    . esc_html(self::format_size((int) $item['size']))
                    . '</td><td>' . esc_html($item['date'])
                    . '</td><td>';

                // Cada restauracion utiliza un nonce propio
                echo '<form style="display:inline-block;margin-right:6px" method="post" action="'
                    . esc_url(admin_url('admin-post.php'))
                    . '"><input type="hidden" name="action" value="wptc_restore"><input type="hidden" name="restore_id" value="'
                    . esc_attr($id)
                    . '">';

                wp_nonce_field('wptc_restore');

                echo '<button class="button" type="submit">Restaurar</button></form>';

                // La eliminacion permanente requiere confirmacion en el navegador
                echo '<form style="display:inline-block" method="post" action="'
                    . esc_url(admin_url('admin-post.php'))
                    . '" onsubmit="return confirm(\'¿Eliminar este archivo de forma permanente? Esta acción no se puede deshacer salvo mediante backup.\');"><input type="hidden" name="action" value="wptc_purge"><input type="hidden" name="purge_id" value="'
                    . esc_attr($id)
                    . '">';

                wp_nonce_field('wptc_purge');

                echo '<button class="button button-link-delete" type="submit">Eliminar definitivamente</button></form>';
                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        // Advierte de que el plugin no puede determinar las dependencias reales
        echo '<p style="margin-top:24px;color:#646970">Consejo: haz una copia de seguridad antes de usar la limpieza. El plugin no puede determinar con certeza si cada traducción la necesita un componente activo.</p>';

        echo '</div>';
    }
}

// Registra los hooks del plugin al cargar este archivo
WP_Translation_Cleaner::init();