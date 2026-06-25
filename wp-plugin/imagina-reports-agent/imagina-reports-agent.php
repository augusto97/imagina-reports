<?php
/**
 * Plugin Name:       Imagina Reports Agent
 * Plugin URI:        https://imaginawp.com
 * Description:        Expone, de forma segura, el estado de respaldos y la salud del sitio para Imagina Reports. Imagina Reports lo consulta por HTTPS al sincronizar; no abre puertos ni almacena datos crudos.
 * Version:           1.2.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Imagina WP
 * Author URI:        https://imaginawp.com
 * License:           GPL-2.0-or-later
 * Text Domain:       imagina-reports-agent
 *
 * Diseño (espejo de App\Connectors\SiteAgent\SiteAgentConnector):
 *   GET /wp-json/imagina-reports/v1/metrics?from=YYYY-MM-DD&to=YYYY-MM-DD
 *   Cabecera: X-Imagina-Key: <clave>   (o ?key=<clave>)
 *   Respuesta: { success, generated_at, agent_version, site, plugins, updates, storage, backups }
 *
 * Regla de oro (§3.3): agrega EN EL ORIGEN. Los respaldos se miden escaneando las
 * carpetas de backup en disco (mtime + tamaño), no leyendo el esquema interno de cada
 * plugin de backup (frágil) ni descargando archivos. La salud del sitio sale de
 * transients/opciones ya presentes; nunca se llama a WordPress.org desde aquí.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('IMAGINA_REPORTS_AGENT_VERSION', '1.2.0');
define('IMAGINA_REPORTS_AGENT_KEY_OPTION', 'imagina_reports_agent_key');

/**
 * Genera una clave si no existe (al activar o al primer arranque).
 */
function imagina_reports_agent_ensure_key() {
    $key = get_option(IMAGINA_REPORTS_AGENT_KEY_OPTION);

    if (! is_string($key) || strlen($key) < 32) {
        $key = wp_generate_password(48, false, false);
        update_option(IMAGINA_REPORTS_AGENT_KEY_OPTION, $key, false);
    }

    return $key;
}

register_activation_hook(__FILE__, 'imagina_reports_agent_ensure_key');

/* -------------------------------------------------------------------------- */
/*  REST API                                                                  */
/* -------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('imagina-reports/v1', '/metrics', array(
        'methods'             => 'GET',
        'callback'            => 'imagina_reports_agent_metrics',
        'permission_callback' => 'imagina_reports_agent_authorize',
        'args'                => array(
            'from' => array('required' => false),
            'to'   => array('required' => false),
        ),
    ));

    // Diagnóstico (solo lectura, gateado por clave): revela DÓNDE y con qué ESTRUCTURA
    // guardan WPvivid/UpdraftPlus su lista de respaldos, para programar el lector exacto
    // sin adivinar. Muestra claves y tipos, NUNCA valores (no filtra tokens de la nube).
    register_rest_route('imagina-reports/v1', '/diagnostics', array(
        'methods'             => 'GET',
        'callback'            => 'imagina_reports_agent_diagnostics',
        'permission_callback' => 'imagina_reports_agent_authorize',
    ));
});

/**
 * Autoriza la petición comparando la clave (cabecera X-Imagina-Key o ?key=) con la
 * almacenada, en tiempo constante (hash_equals).
 *
 * @param WP_REST_Request $request
 * @return bool|WP_Error
 */
function imagina_reports_agent_authorize($request) {
    $provided = $request->get_header('x-imagina-key');

    if (empty($provided)) {
        $provided = $request->get_param('key');
    }

    $expected = imagina_reports_agent_ensure_key();

    if (is_string($provided) && hash_equals($expected, $provided)) {
        return true;
    }

    return new WP_Error(
        'imagina_reports_forbidden',
        'Clave del agente inválida o ausente.',
        array('status' => 403)
    );
}

/**
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function imagina_reports_agent_metrics($request) {
    $from = imagina_reports_agent_period_ts($request->get_param('from'), 0);
    $to   = imagina_reports_agent_period_ts($request->get_param('to'), PHP_INT_MAX, true);

    $payload = array(
        'success'       => true,
        'generated_at'  => gmdate('c'),
        'agent_version' => IMAGINA_REPORTS_AGENT_VERSION,
        'site'          => imagina_reports_agent_site(),
        'plugins'       => imagina_reports_agent_plugins(),
        'updates'       => imagina_reports_agent_updates(),
        'storage'       => imagina_reports_agent_storage(),
        'backups'       => imagina_reports_agent_backups($from, $to),
    );

    return new WP_REST_Response($payload, 200);
}

/**
 * Convierte una fecha YYYY-MM-DD a timestamp. $end=true la lleva al fin del día.
 *
 * @param mixed $value
 * @param int   $default
 * @param bool  $end
 * @return int
 */
function imagina_reports_agent_period_ts($value, $default, $end = false) {
    if (! is_string($value) || $value === '') {
        return $default;
    }

    $ts = strtotime($value . ($end ? ' 23:59:59' : ' 00:00:00'));

    return $ts === false ? $default : $ts;
}

/* -------------------------------------------------------------------------- */
/*  Diagnóstico (descubrir el almacenamiento de WPvivid/UpdraftPlus)          */
/* -------------------------------------------------------------------------- */

/**
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function imagina_reports_agent_diagnostics($request) {
    return new WP_REST_Response(array(
        'success'       => true,
        'agent_version' => IMAGINA_REPORTS_AGENT_VERSION,
        'wpvivid'       => imagina_reports_agent_probe('wpvivid'),
        'updraft'       => imagina_reports_agent_probe('updraft'),
    ), 200);
}

/**
 * Sondea opciones/tablas de un plugin de backup y devuelve la ESTRUCTURA (claves +
 * tipos), nunca los valores, de las opciones que parezcan una lista de respaldos.
 *
 * @param string $needle
 * @return array<string,mixed>
 */
function imagina_reports_agent_probe($needle) {
    global $wpdb;

    $option_names = array();
    $tables       = array();
    $samples      = array();

    if (is_object($wpdb)) {
        $like = '%' . $wpdb->esc_like($needle) . '%';

        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $like));
        if (is_array($names)) {
            $option_names = $names;
        }

        $found_tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if (is_array($found_tables)) {
            $tables = $found_tables;
        }

        // Muestra la estructura solo de opciones con pinta de «lista de respaldos»,
        // y evita las que suelen guardar credenciales/tokens.
        foreach ($option_names as $name) {
            $lname = strtolower($name);
            $looks_like_list = (strpos($lname, 'backup') !== false || strpos($lname, 'list') !== false || strpos($lname, 'history') !== false || strpos($lname, 'succeed') !== false || strpos($lname, 'log') !== false);
            $looks_secret    = (strpos($lname, 'remote') !== false || strpos($lname, 'setting') !== false || strpos($lname, 'token') !== false || strpos($lname, 'secret') !== false || strpos($lname, 'auth') !== false || strpos($lname, 'key') !== false);

            if ($looks_like_list && ! $looks_secret) {
                $samples[$name] = imagina_reports_agent_shape(get_option($name), 0);
            }
        }
    }

    return array(
        'option_names' => $option_names,
        'tables'       => $tables,
        'samples'      => $samples,
    );
}

/**
 * Descriptor de estructura: para arrays devuelve claves => forma (máx. 2 elementos por
 * lista, profundidad 4); para escalares devuelve solo el TIPO (y longitud en strings),
 * nunca el contenido. Así vemos la forma sin filtrar secretos.
 *
 * @param mixed $value
 * @param int   $depth
 * @return mixed
 */
function imagina_reports_agent_shape($value, $depth) {
    if (is_array($value)) {
        if ($depth >= 4) {
            return 'array(' . count($value) . ')';
        }

        $shape = array();
        $i     = 0;
        foreach ($value as $k => $v) {
            if ($i >= 2) {
                $shape['…'] = '(' . (count($value) - 2) . ' más)';
                break;
            }
            $shape[$k] = imagina_reports_agent_shape($v, $depth + 1);
            $i++;
        }

        return $shape;
    }

    if (is_object($value)) {
        return 'object(' . get_class($value) . ')';
    }

    if (is_string($value)) {
        // Si parece una fecha/hora o un timestamp, es seguro y útil mostrarlo.
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || (is_numeric($value) && (int) $value > 1000000000 && (int) $value < 4000000000)) {
            return 'string:"' . substr($value, 0, 24) . '"';
        }

        return 'string(' . strlen($value) . ')';
    }

    if (is_int($value)) {
        // Los enteros tipo timestamp son seguros y nos dicen la fecha del backup.
        return ($value > 1000000000 && $value < 4000000000) ? ('int:' . $value) : 'int';
    }

    return gettype($value);
}

/* -------------------------------------------------------------------------- */
/*  Recolectores                                                              */
/* -------------------------------------------------------------------------- */

/**
 * @return array<string,mixed>
 */
function imagina_reports_agent_site() {
    global $wp_version, $wpdb;

    $theme = wp_get_theme();

    return array(
        'url'                 => home_url(),
        'name'                => get_bloginfo('name'),
        'wp_version'          => $wp_version,
        'php_version'         => PHP_VERSION,
        'mysql_version'       => is_object($wpdb) ? $wpdb->db_version() : '',
        'server_software'     => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '',
        'locale'              => get_locale(),
        'https'               => strpos(home_url(), 'https://') === 0,
        'multisite'           => is_multisite(),
        'active_theme'        => $theme ? $theme->get('Name') : '',
        'active_theme_version'=> $theme ? $theme->get('Version') : '',
    );
}

/**
 * @return array<string,int>
 */
function imagina_reports_agent_plugins() {
    if (! function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all    = get_plugins();
    $active = (array) get_option('active_plugins', array());

    if (is_multisite()) {
        $network = (array) get_site_option('active_sitewide_plugins', array());
        $active  = array_unique(array_merge($active, array_keys($network)));
    }

    $total       = count($all);
    $active_count = count(array_intersect(array_keys($all), $active));

    return array(
        'total'    => $total,
        'active'   => $active_count,
        'inactive' => max(0, $total - $active_count),
    );
}

/**
 * Lee los transients de actualización ya presentes (no fuerza una consulta a
 * WordPress.org). Si nunca se han poblado, devuelve ceros.
 *
 * @return array<string,int>
 */
function imagina_reports_agent_updates() {
    $core    = 0;
    $plugins = 0;
    $themes  = 0;

    $core_t = get_site_transient('update_core');
    if (isset($core_t->updates) && is_array($core_t->updates)) {
        foreach ($core_t->updates as $update) {
            if (isset($update->response) && $update->response === 'upgrade') {
                $core++;
            }
        }
    }

    $plugins_t = get_site_transient('update_plugins');
    if (isset($plugins_t->response) && is_array($plugins_t->response)) {
        $plugins = count($plugins_t->response);
    }

    $themes_t = get_site_transient('update_themes');
    if (isset($themes_t->response) && is_array($themes_t->response)) {
        $themes = count($themes_t->response);
    }

    return array(
        'core'    => $core,
        'plugins' => $plugins,
        'themes'  => $themes,
        'total'   => $core + $plugins + $themes,
    );
}

/**
 * @return array<string,float>
 */
function imagina_reports_agent_storage() {
    global $wpdb;

    $db_bytes = 0;
    if (is_object($wpdb)) {
        $rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $db_bytes += (int) (isset($row['Data_length']) ? $row['Data_length'] : 0);
                $db_bytes += (int) (isset($row['Index_length']) ? $row['Index_length'] : 0);
            }
        }
    }

    $uploads = wp_get_upload_dir();
    $uploads_bytes = isset($uploads['basedir']) ? imagina_reports_agent_dir_size($uploads['basedir']) : 0;

    return array(
        'db_size_mb'      => imagina_reports_agent_mb($db_bytes),
        'uploads_size_mb' => imagina_reports_agent_mb($uploads_bytes),
    );
}

/**
 * Estado de respaldos. Combina dos fuentes para cubrir TODAS las configuraciones,
 * incluidas las que suben a la nube (Google Drive, Dropbox, S3…) sin dejar copia local:
 *
 *   1. Historial de UpdraftPlus (opción `updraft_backup_history`): registra cada
 *      respaldo con su fecha y su destino remoto AUNQUE el archivo local se haya
 *      borrado tras subirlo. Es la fuente autoritativa para UpdraftPlus.
 *   2. Escaneo de carpetas en disco para el resto de plugins (WPvivid, BackWPup,
 *      Duplicator, All-in-One…) que conservan copia local — mtime + tamaño, sin abrir
 *      los archivos (agrega en origen, §3.3).
 *
 * @param int $from_ts
 * @param int $to_ts
 * @return array<string,mixed>
 */
function imagina_reports_agent_backups($from_ts, $to_ts) {
    $content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';

    // Patrón de carpeta => proveedor. Soporta comodines via glob.
    $dirs = array(
        $content . '/updraft'           => 'UpdraftPlus',
        $content . '/wpvividbackups'    => 'WPvivid',
        $content . '/ai1wm-backups'     => 'All-in-One WP Migration',
        $content . '/backwpup-*'        => 'BackWPup',
        $content . '/backupwordpress-*' => 'BackUpWordPress',
        ABSPATH . 'wp-snapshots'        => 'Duplicator',
    );

    $exts = array('zip', 'gz', 'tar', 'wpress', 'sql', 'bz2', 'tgz');

    $files     = array();
    $providers = array();

    // 1) Historiales de plugins (incluyen respaldos solo-nube, sin copia local):
    //    UpdraftPlus (updraft_backup_history) y WPvivid (wpvivid_backup_reports).
    $covered = array();

    $updraft = imagina_reports_agent_updraft_history();
    foreach ($updraft as $entry) {
        $files[]                  = $entry;
        $providers['UpdraftPlus'] = true;
    }
    if (! empty($updraft)) {
        $covered['UpdraftPlus'] = true;
    }

    $wpvivid = imagina_reports_agent_wpvivid_history();
    foreach ($wpvivid as $entry) {
        $files[]              = $entry;
        $providers['WPvivid'] = true;
    }
    if (! empty($wpvivid)) {
        $covered['WPvivid'] = true;
    }

    // 2) Escaneo en disco del resto (y de un proveedor solo si su historial vino vacío).
    foreach ($dirs as $pattern => $provider) {
        if (isset($covered[$provider])) {
            continue; // ya cubierto por su historial; evita contar doble.
        }
        foreach (imagina_reports_agent_glob_dirs($pattern) as $dir) {
            $found = imagina_reports_agent_scan_backup_dir($dir, $exts);
            foreach ($found as $file) {
                $file['provider']     = $provider;
                $file['location']     = 'Local';
                $files[]              = $file;
                $providers[$provider] = true;
            }
        }
    }

    // Orden por mtime descendente.
    usort($files, function ($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });

    $count_total     = count($files);
    $count_in_period = 0;
    $total_bytes     = 0;
    foreach ($files as $file) {
        $total_bytes += $file['size'];
        if ($file['mtime'] >= $from_ts && $file['mtime'] <= $to_ts) {
            $count_in_period++;
        }
    }

    $latest          = $count_total > 0 ? $files[0] : null;
    $latest_provider = '';
    $latest_location = '';
    $last_at         = null;
    $last_age_days   = null;
    $last_size_mb    = null;

    if ($latest !== null) {
        $latest_provider = $latest['provider'];
        $latest_location = isset($latest['location']) ? $latest['location'] : 'Local';
        $last_at         = gmdate('c', $latest['mtime']);
        $last_age_days   = (int) floor((time() - $latest['mtime']) / DAY_IN_SECONDS);
        $last_size_mb    = $latest['size'] > 0 ? imagina_reports_agent_mb($latest['size']) : null;
    }

    $recent = array();
    foreach (array_slice($files, 0, 10) as $file) {
        $recent[] = array(
            'date'     => gmdate('Y-m-d H:i', $file['mtime']),
            'size_mb'  => $file['size'] > 0 ? imagina_reports_agent_mb($file['size']) : null,
            'provider' => $file['provider'],
            'location' => isset($file['location']) ? $file['location'] : 'Local',
        );
    }

    return array(
        'provider'             => $latest_provider,
        'providers'            => array_keys($providers),
        'last_backup_at'       => $last_at,
        'last_backup_age_days' => $last_age_days,
        'last_backup_size_mb'  => $last_size_mb,
        'last_backup_location' => $latest_location,
        'total_size_mb'        => imagina_reports_agent_mb($total_bytes),
        'count_total'          => $count_total,
        'count_in_period'      => $count_in_period,
        'recent'               => $recent,
    );
}

/**
 * Lee el historial de UpdraftPlus (`updraft_backup_history`), que está keyed por la
 * marca de tiempo del respaldo. Cada entrada conserva el destino remoto incluso si el
 * archivo local ya se subió y borró — así detectamos respaldos en Google Drive/Dropbox/
 * S3 sin copia local. Defensivo: tolera ausencias de campos y formatos entre versiones.
 *
 * @return array<int,array{mtime:int,size:int,provider:string,location:string}>
 */
function imagina_reports_agent_updraft_history() {
    $history = get_option('updraft_backup_history');

    if (! is_array($history) || empty($history)) {
        return array();
    }

    $entries = array();

    foreach ($history as $timestamp => $set) {
        if (! is_array($set) || ! is_numeric($timestamp)) {
            continue;
        }

        // Tamaño: suma cualquier subclave «*-size» numérica (no siempre presente).
        $size = 0;
        foreach ($set as $sub_key => $sub_value) {
            if (is_string($sub_key) && substr($sub_key, -5) === '-size' && is_numeric($sub_value)) {
                $size += (int) $sub_value;
            }
        }

        $entries[] = array(
            'mtime'    => (int) $timestamp,
            'size'     => $size,
            'provider' => 'UpdraftPlus',
            'location' => imagina_reports_agent_updraft_destination(isset($set['service']) ? $set['service'] : 'none'),
        );
    }

    return $entries;
}

/**
 * Traduce el «service» de UpdraftPlus (string o array de slugs) a un destino legible.
 *
 * @param mixed $service
 * @return string
 */
function imagina_reports_agent_updraft_destination($service) {
    $map = array(
        'googledrive' => 'Google Drive',
        'dropbox'     => 'Dropbox',
        's3'          => 'Amazon S3',
        's3generic'   => 'S3 compatible',
        'googlecloud' => 'Google Cloud',
        'onedrive'    => 'OneDrive',
        'ftp'         => 'FTP',
        'sftp'        => 'SFTP',
        'backblaze'   => 'Backblaze',
        'azure'       => 'Azure',
        'webdav'      => 'WebDAV',
        'email'       => 'Email',
    );

    $services = is_array($service) ? $service : array($service);
    $labels   = array();

    foreach ($services as $slug) {
        $slug = is_string($slug) ? strtolower($slug) : '';
        if ($slug === '' || $slug === 'none') {
            continue;
        }
        $labels[] = isset($map[$slug]) ? $map[$slug] : ucfirst($slug);
    }

    return empty($labels) ? 'Local' : implode(', ', $labels);
}

/**
 * Lee el historial de WPvivid desde `wpvivid_backup_reports` (un registro por respaldo
 * con `backup_time`), que persiste aunque el archivo se haya subido a la nube (Google
 * Drive/Dropbox/S3) y borrado del disco. El destino se deduce de `wpvivid_remote_list`
 * (solo el TIPO, nunca el token). Shape verificado contra datos reales (diagnostics).
 *
 * @return array<int,array{mtime:int,size:int,provider:string,location:string}>
 */
function imagina_reports_agent_wpvivid_history() {
    $destination = imagina_reports_agent_wpvivid_destination();
    $entries     = array();

    $reports = get_option('wpvivid_backup_reports');

    if (is_array($reports)) {
        foreach ($reports as $record) {
            if (! is_array($record) || ! isset($record['backup_time']) || ! is_numeric($record['backup_time'])) {
                continue;
            }

            $ts = (int) $record['backup_time'];
            if ($ts <= 0) {
                continue;
            }

            // Tamaño: suma defensiva de cualquier subclave que contenga «size» (puede no existir).
            $size = 0;
            foreach ($record as $sub_key => $sub_value) {
                if (is_string($sub_key) && stripos($sub_key, 'size') !== false && is_numeric($sub_value)) {
                    $size += (int) $sub_value;
                }
            }

            $entries[] = array(
                'mtime'    => $ts,
                'size'     => $size,
                'provider' => 'WPvivid',
                'location' => $destination,
            );
        }
    }

    // Fallback: si no hubo reports pero MainWP registró la última fecha de WPvivid.
    if (empty($entries)) {
        $last = get_option('mainwp_lasttime_backup_wpvivid');
        if (is_numeric($last) && (int) $last > 0) {
            $entries[] = array(
                'mtime'    => (int) $last,
                'size'     => 0,
                'provider' => 'WPvivid',
                'location' => $destination,
            );
        }
    }

    return $entries;
}

/**
 * Deduce el destino de WPvivid desde la lista de almacenamientos remotos configurados,
 * mapeando SOLO el tipo a una etiqueta legible (nunca expone tokens). Defensivo entre
 * versiones: si no reconoce el tipo, devuelve «Remoto».
 *
 * @return string
 */
function imagina_reports_agent_wpvivid_destination() {
    $remotes = get_option('wpvivid_remote_list');

    if (! is_array($remotes) || empty($remotes)) {
        $remotes = get_option('wpvivid_new_remote_list');
    }

    if (! is_array($remotes) || empty($remotes)) {
        return 'Remoto';
    }

    $map = array(
        'google_drive'         => 'Google Drive',
        'googledrive'          => 'Google Drive',
        'gdrive'               => 'Google Drive',
        'dropbox'              => 'Dropbox',
        'amazons3'             => 'Amazon S3',
        's3'                   => 'Amazon S3',
        'amazons3_compatible'  => 'S3 compatible',
        'onedrive'             => 'OneDrive',
        'microsoft_onedrive'   => 'OneDrive',
        'sftp'                 => 'SFTP',
        'ftp'                  => 'FTP',
        'wasabi'               => 'Wasabi',
        'backblaze'            => 'Backblaze',
        'digitalocean'         => 'DigitalOcean Spaces',
        'google_cloud_storage' => 'Google Cloud',
        'pcloud'               => 'pCloud',
        'webdav'               => 'WebDAV',
        'azure'                => 'Azure',
    );

    $labels = array();

    foreach ($remotes as $remote) {
        if (! is_array($remote)) {
            continue;
        }

        $type = '';
        if (isset($remote['type']) && is_string($remote['type'])) {
            $type = strtolower($remote['type']);
        } elseif (isset($remote['storage']) && is_string($remote['storage'])) {
            $type = strtolower($remote['storage']);
        }

        if ($type !== '' && isset($map[$type])) {
            $labels[$map[$type]] = true;
        }
    }

    return empty($labels) ? 'Remoto' : implode(', ', array_keys($labels));
}

/**
 * Resuelve un patrón de carpeta (con o sin comodín) a las carpetas existentes.
 *
 * @param string $pattern
 * @return string[]
 */
function imagina_reports_agent_glob_dirs($pattern) {
    if (strpos($pattern, '*') === false) {
        return is_dir($pattern) ? array($pattern) : array();
    }

    $matches = glob($pattern, GLOB_ONLYDIR);

    return is_array($matches) ? $matches : array();
}

/**
 * Enumera los archivos de backup de una carpeta (un nivel + subcarpetas directas).
 *
 * @param string   $dir
 * @param string[] $exts
 * @return array<int,array{mtime:int,size:int}>
 */
function imagina_reports_agent_scan_backup_dir($dir, $exts) {
    $result  = array();
    $entries = @scandir($dir);

    if (! is_array($entries)) {
        return $result;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path)) {
            // Un nivel de subcarpetas (algunos plugins agrupan por fecha).
            $sub = @scandir($path);
            if (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child === '.' || $child === '..') {
                        continue;
                    }
                    $childPath = $path . '/' . $child;
                    if (is_file($childPath) && imagina_reports_agent_is_backup($child, $exts)) {
                        $result[] = array('mtime' => (int) @filemtime($childPath), 'size' => (int) @filesize($childPath));
                    }
                }
            }
            continue;
        }

        if (is_file($path) && imagina_reports_agent_is_backup($entry, $exts)) {
            $result[] = array('mtime' => (int) @filemtime($path), 'size' => (int) @filesize($path));
        }
    }

    return $result;
}

/**
 * @param string   $filename
 * @param string[] $exts
 * @return bool
 */
function imagina_reports_agent_is_backup($filename, $exts) {
    $lower = strtolower($filename);

    foreach ($exts as $ext) {
        if (substr($lower, -(strlen($ext) + 1)) === '.' . $ext) {
            return true;
        }
    }

    return false;
}

/**
 * Suma recursiva del tamaño de una carpeta (solo stat, sin abrir archivos).
 *
 * @param string $dir
 * @return int
 */
function imagina_reports_agent_dir_size($dir) {
    if (! is_dir($dir)) {
        return 0;
    }

    $size = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }
    } catch (Exception $e) {
        return $size;
    }

    return $size;
}

/**
 * @param int $bytes
 * @return float
 */
function imagina_reports_agent_mb($bytes) {
    return round($bytes / 1048576, 1);
}

/* -------------------------------------------------------------------------- */
/*  Página de ajustes                                                         */
/* -------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_options_page(
        'Imagina Reports',
        'Imagina Reports',
        'manage_options',
        'imagina-reports-agent',
        'imagina_reports_agent_settings_page'
    );
});

add_action('admin_post_imagina_reports_agent_regenerate', function () {
    if (! current_user_can('manage_options')) {
        wp_die('No autorizado.');
    }

    check_admin_referer('imagina_reports_agent_regenerate');

    update_option(
        IMAGINA_REPORTS_AGENT_KEY_OPTION,
        wp_generate_password(48, false, false),
        false
    );

    wp_safe_redirect(admin_url('options-general.php?page=imagina-reports-agent&regenerated=1'));
    exit;
});

function imagina_reports_agent_settings_page() {
    $key      = imagina_reports_agent_ensure_key();
    $endpoint = home_url('/wp-json/imagina-reports/v1/metrics');
    ?>
    <div class="wrap">
        <h1>Imagina Reports — Agente del sitio</h1>
        <p>Este plugin expone, de forma segura, el estado de respaldos y la salud del sitio para <strong>Imagina Reports</strong>.
        Copia la clave de abajo y pégala al configurar la fuente «Agente Imagina (sitio)» en Imagina Reports.</p>

        <?php if (isset($_GET['regenerated'])) : ?>
            <div class="notice notice-success"><p>Clave regenerada. Actualízala en Imagina Reports.</p></div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Clave del agente</th>
                <td>
                    <input type="text" readonly class="regular-text code" style="width:30rem"
                           value="<?php echo esc_attr($key); ?>" onclick="this.select();" />
                    <p class="description">Trátala como una contraseña. Quien la tenga puede leer estas métricas (solo lectura).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">URL de métricas</th>
                <td>
                    <input type="text" readonly class="regular-text code" style="width:30rem"
                           value="<?php echo esc_attr($endpoint); ?>" onclick="this.select();" />
                    <p class="description">Imagina Reports la deduce sola desde la URL del sitio; aquí solo para referencia.</p>
                </td>
            </tr>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('imagina_reports_agent_regenerate'); ?>
            <input type="hidden" name="action" value="imagina_reports_agent_regenerate" />
            <?php submit_button('Regenerar clave', 'secondary'); ?>
        </form>
    </div>
    <?php
}
