<?php
/**
 * Plugin Name:       Imagina Reports Agent
 * Plugin URI:        https://imaginawp.com
 * Description:        Expone, de forma segura, el estado de respaldos y la salud del sitio para Imagina Reports. Imagina Reports lo consulta por HTTPS al sincronizar; no abre puertos ni almacena datos crudos.
 * Version:           1.9.1
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
 *   Respuesta: { success, generated_at, agent_version, site, plugins, updates, activity,
 *               storage, ssl, backups, security, performance, content, leads, ecommerce,
 *               logins, images }
 *
 * `activity` es el historial LOCAL de actualizaciones aplicadas: el plugin registra cada
 * actualización de plugin/tema/núcleo en el momento en que ocurre (vía el hook
 * upgrader_process_complete) y la guarda en el propio sitio. Por eso, basta tener el plugin
 * instalado para acumular historial — aunque el sitio se conecte a Imagina Reports meses
 * después y a mitad de mes.
 *
 * Regla de oro (§3.3): agrega EN EL ORIGEN. Los respaldos se miden escaneando las
 * carpetas de backup en disco (mtime + tamaño), no leyendo el esquema interno de cada
 * plugin de backup (frágil) ni descargando archivos. La salud del sitio sale de
 * transients/opciones ya presentes; nunca se llama a WordPress.org desde aquí.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('IMAGINA_REPORTS_AGENT_VERSION', '1.9.1');
define('IMAGINA_REPORTS_AGENT_KEY_OPTION', 'imagina_reports_agent_key');
// Registro local de actualizaciones aplicadas (historial propio del sitio) + el mapa de
// versiones conocidas con el que se calcula el "de→a" de cada actualización.
define('IMAGINA_REPORTS_AGENT_LOG_OPTION', 'imagina_reports_agent_activity_log');
define('IMAGINA_REPORTS_AGENT_VERSIONS_OPTION', 'imagina_reports_agent_versions');
// Tope del anillo de eventos guardados (suficiente para años de historial; acota el option).
define('IMAGINA_REPORTS_AGENT_LOG_MAX', 1000);

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

/**
 * Al activar: SOLO genera la clave (operación trivial, sin tocar plugins/temas). El mapa de
 * versiones se siembra de forma perezosa y defensiva en la primera petición de métricas, no
 * en la activación — para que la activación nunca pueda fallar por el entorno del sitio.
 */
function imagina_reports_agent_activate() {
    imagina_reports_agent_ensure_key();
}

register_activation_hook(__FILE__, 'imagina_reports_agent_activate');

/* -------------------------------------------------------------------------- */
/*  Historial local de actualizaciones (se registra desde la instalación)     */
/* -------------------------------------------------------------------------- */

// Captura cada actualización/instalación de plugin, tema o núcleo en el momento en que
// ocurre — vía WordPress, auto-updates o WP-CLI (todos disparan este hook). El historial
// vive en el propio sitio, así que tener el plugin instalado basta para acumularlo.
add_action('upgrader_process_complete', 'imagina_reports_agent_record_upgrade', 10, 2);

/**
 * Mapa de versiones instaladas ahora mismo: 'core' + 'plugin:<file>' + 'theme:<slug>'.
 * Cada entrada guarda nombre y versión, para poder calcular el "de→a" al actualizar.
 *
 * @return array<string,array{name:string,version:string}>
 */
function imagina_reports_agent_current_versions() {
    $map = array();

    global $wp_version;
    $map['core'] = array('name' => 'WordPress', 'version' => (string) $wp_version);

    if (! function_exists('get_plugins') && defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (function_exists('get_plugins')) {
        foreach ((array) get_plugins() as $file => $data) {
            $map['plugin:' . $file] = array(
                'name'    => isset($data['Name']) ? (string) $data['Name'] : $file,
                'version' => isset($data['Version']) ? (string) $data['Version'] : '',
            );
        }
    }

    if (function_exists('wp_get_themes')) {
        foreach (wp_get_themes() as $slug => $theme) {
            $map['theme:' . $slug] = array(
                'name'    => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
            );
        }
    }

    return $map;
}

/**
 * Registra las actualizaciones recién aplicadas comparando contra el mapa de versiones
 * conocido, y luego refresca ese mapa. Defensivo: nunca lanza ni interrumpe la actualización.
 *
 * @param mixed $upgrader
 * @param array $hook_extra
 */
function imagina_reports_agent_record_upgrade($upgrader, $hook_extra) {
    // Blindaje total: registrar el historial JAMÁS debe interrumpir una actualización ni
    // tumbar el admin. Cualquier error se traga silenciosamente.
    try {
        imagina_reports_agent_do_record_upgrade($hook_extra);
    } catch (\Throwable $e) {
        // no-op
    }
}

/**
 * @param mixed $hook_extra
 */
function imagina_reports_agent_do_record_upgrade($hook_extra) {
    if (! is_array($hook_extra)) {
        return;
    }

    $type   = isset($hook_extra['type']) ? (string) $hook_extra['type'] : '';
    $action = isset($hook_extra['action']) ? (string) $hook_extra['action'] : 'update';

    if (! in_array($type, array('plugin', 'theme', 'core'), true)) {
        return;
    }

    $known   = get_option(IMAGINA_REPORTS_AGENT_VERSIONS_OPTION);
    $known   = is_array($known) ? $known : array();
    $current = imagina_reports_agent_current_versions();
    $now     = gmdate('Y-m-d H:i:s');
    $entries = array();

    // Claves afectadas: las que WordPress declara en $hook_extra, o todo el tipo si no las da.
    $keys = array();
    if ($type === 'plugin') {
        $plugins = array();
        if (! empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $plugins = $hook_extra['plugins'];
        } elseif (! empty($hook_extra['plugin'])) {
            $plugins = array($hook_extra['plugin']);
        }
        foreach ($plugins as $file) {
            $keys[] = 'plugin:' . $file;
        }
    } elseif ($type === 'theme') {
        $themes = array();
        if (! empty($hook_extra['themes']) && is_array($hook_extra['themes'])) {
            $themes = $hook_extra['themes'];
        } elseif (! empty($hook_extra['theme'])) {
            $themes = array($hook_extra['theme']);
        }
        foreach ($themes as $slug) {
            $keys[] = 'theme:' . $slug;
        }
    } else {
        $keys[] = 'core';
    }

    foreach ($keys as $key) {
        $to   = isset($current[$key]['version']) ? (string) $current[$key]['version'] : '';
        $from = isset($known[$key]['version']) ? (string) $known[$key]['version'] : '';
        $name = isset($current[$key]['name']) ? (string) $current[$key]['name'] : (isset($known[$key]['name']) ? (string) $known[$key]['name'] : $key);

        // Sin cambio real de versión en una "update" → no es trabajo que mostrar.
        if ($action === 'update' && $from !== '' && $to !== '' && $from === $to) {
            continue;
        }

        $entries[] = array(
            'at_gmt' => $now,
            'type'   => $type,
            'action' => $action,
            'name'   => $name,
            'slug'   => $key,
            'from'   => $from,
            'to'     => $to,
        );
    }

    if ($entries !== array()) {
        imagina_reports_agent_append_log($entries);
    }

    // Refresca el mapa para el próximo "de→a".
    update_option(IMAGINA_REPORTS_AGENT_VERSIONS_OPTION, $current, false);
}

/**
 * Añade eventos al registro (anillo acotado, más recientes al final).
 *
 * @param array<int,array<string,mixed>> $entries
 */
function imagina_reports_agent_append_log($entries) {
    $log = get_option(IMAGINA_REPORTS_AGENT_LOG_OPTION);
    $log = is_array($log) ? $log : array();

    foreach ($entries as $entry) {
        $log[] = $entry;
    }

    if (count($log) > IMAGINA_REPORTS_AGENT_LOG_MAX) {
        $log = array_slice($log, -IMAGINA_REPORTS_AGENT_LOG_MAX);
    }

    update_option(IMAGINA_REPORTS_AGENT_LOG_OPTION, $log, false);
}

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

    // Cadenas GMT para los conteos por periodo en SQL (columnas *_gmt), acotadas a un
    // rango de fecha válido para MySQL (máx. 9999-12-31).
    $from_gmt = gmdate('Y-m-d H:i:s', max(0, $from));
    $to_gmt   = gmdate('Y-m-d H:i:s', min($to, 253402300799));

    // Variante en hora local del sitio: las tablas propias de los plugins de formularios
    // (Bit Form, Fluent Forms) guardan created_at con current_time('mysql') = local.
    $from_local = function_exists('get_date_from_gmt') ? get_date_from_gmt($from_gmt) : $from_gmt;
    $to_local   = function_exists('get_date_from_gmt') ? get_date_from_gmt($to_gmt) : $to_gmt;

    // Auto-siembra del mapa de versiones si falta (p. ej. el plugin se actualizó sin
    // re-activarse): a partir de aquí, las siguientes actualizaciones ya registran el "de→a".
    // Defensivo: nunca debe romper la sincronización.
    try {
        if (get_option(IMAGINA_REPORTS_AGENT_VERSIONS_OPTION) === false) {
            update_option(IMAGINA_REPORTS_AGENT_VERSIONS_OPTION, imagina_reports_agent_current_versions(), false);
        }
    } catch (\Throwable $e) {
        // no-op
    }

    $payload = array(
        'success'       => true,
        'generated_at'  => gmdate('c'),
        'agent_version' => IMAGINA_REPORTS_AGENT_VERSION,
        'site'          => imagina_reports_agent_site(),
        'plugins'       => imagina_reports_agent_plugins(),
        'updates'       => imagina_reports_agent_updates(),
        'activity'      => imagina_reports_agent_activity($from_gmt, $to_gmt),
        'storage'       => imagina_reports_agent_storage(),
        'ssl'           => imagina_reports_agent_ssl(),
        'backups'       => imagina_reports_agent_backups($from, $to),
        'security'      => imagina_reports_agent_security($from_gmt, $to_gmt),
        'performance'   => imagina_reports_agent_performance(),
        'content'       => imagina_reports_agent_content($from_gmt, $to_gmt),
        'leads'         => imagina_reports_agent_leads($from_gmt, $to_gmt, $from_local, $to_local),
        'ecommerce'     => imagina_reports_agent_ecommerce(),
        'logins'        => imagina_reports_agent_logins($from, $to),
        'images'        => imagina_reports_agent_images(),
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
        'success'        => true,
        'agent_version'  => IMAGINA_REPORTS_AGENT_VERSION,
        'active_plugins' => array_values((array) get_option('active_plugins', array())),
        'wpvivid'        => imagina_reports_agent_probe('wpvivid'),
        'updraft'        => imagina_reports_agent_probe('updraft'),
        // 2º lote: descubre DÓNDE guardan sus datos los plugins de formularios,
        // seguridad e imágenes. Solo estructura (nombres de opción + columnas y conteo
        // de filas), NUNCA valores: las entradas de formularios contienen datos
        // personales y la config de seguridad puede contener secretos.
        'forms'          => imagina_reports_agent_probe_structure(array(
            'wpforms', 'gravity', 'gf_', 'gform', 'forminator', 'frmt', 'ninja_forms', 'nf3', 'fluentform', 'frm_', 'flamingo', 'bitform', 'bitapps', 'e_submissions', 'jet_fb', 'jetform',
        )),
        'security'       => imagina_reports_agent_probe_structure(array(
            'wordfence', 'wfls', 'wflogins', 'wfhits', 'wfblocks', 'wfconfig', 'limit_login', 'itsec', 'ithemes_security', 'cerber', 'loginizer', 'lockdown',
        )),
        'images'         => imagina_reports_agent_probe_structure(array(
            'smush', 'shortpixel', 'short_pixel', 'imagify', 'ewww',
        )),
    ), 200);
}

/**
 * Sondea opciones y tablas que coincidan con cualquiera de los $needles y devuelve solo
 * la ESTRUCTURA: nombres de opción (sin sus valores) y, por tabla, sus columnas y el
 * número de filas. Nunca devuelve valores de filas ni de opciones (pueden contener datos
 * personales/secretos). Esto basta para programar luego el lector exacto sin adivinar.
 *
 * @param array<int,string> $needles
 * @return array<string,mixed>
 */
function imagina_reports_agent_probe_structure($needles) {
    global $wpdb;

    $options = array();
    $tables  = array();

    if (is_object($wpdb)) {
        foreach ((array) $needles as $needle) {
            $like = '%' . $wpdb->esc_like($needle) . '%';

            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 40",
                $like
            ));
            if (is_array($names)) {
                $options = array_merge($options, $names);
            }

            $found = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            if (is_array($found)) {
                foreach ($found as $table) {
                    // Identificador no parametrizable: los needles son constantes del
                    // plugin (no entrada de usuario) y se eliminan los backticks.
                    $safe = '`' . str_replace('`', '', (string) $table) . '`';

                    $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $safe, 0);
                    $rows    = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $safe);

                    $tables[(string) $table] = array(
                        'columns' => is_array($columns) ? $columns : array(),
                        'rows'    => $rows,
                    );
                }
            }
        }
    }

    return array(
        'options' => array_values(array_unique($options)),
        'tables'  => $tables,
    );
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

    // Lista ligera (slug derivado de la carpeta del plugin, igual que usa wp.org) para
    // que el lado Laravel pueda detectar plugins abandonados consultando wp.org. No se
    // llama a wp.org desde aquí (regla de oro §3.3).
    $list = array();
    foreach ($all as $file => $info) {
        $dir  = dirname($file);
        $slug = ($dir !== '.' && $dir !== '') ? $dir : basename($file, '.php');

        $list[] = array(
            'slug'    => $slug,
            'name'    => isset($info['Name']) ? (string) $info['Name'] : $slug,
            'version' => isset($info['Version']) ? (string) $info['Version'] : '',
            'active'  => in_array($file, $active, true),
        );
    }

    return array(
        'total'    => $total,
        'active'   => $active_count,
        'inactive' => max(0, $total - $active_count),
        'list'     => $list,
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
 * Historial de actualizaciones APLICADAS en el periodo, desde el registro local que el
 * plugin acumula desde su instalación (independiente de cuándo se conecte a Imagina
 * Reports). Devuelve los totales por tipo y la lista de cada actualización con su fecha y
 * el cambio de versión "de→a".
 *
 * @param string $from_gmt
 * @param string $to_gmt
 * @return array<string,mixed>
 */
function imagina_reports_agent_activity($from_gmt, $to_gmt) {
    $log = get_option(IMAGINA_REPORTS_AGENT_LOG_OPTION);
    $log = is_array($log) ? $log : array();

    $entries = array();
    $counts  = array('core' => 0, 'plugin' => 0, 'theme' => 0);
    $since   = null;

    foreach ($log as $row) {
        if (! is_array($row) || empty($row['at_gmt'])) {
            continue;
        }
        $at = (string) $row['at_gmt'];

        if ($since === null || $at < $since) {
            $since = $at;
        }

        if ($at < $from_gmt || $at > $to_gmt) {
            continue;
        }

        $type = isset($row['type']) ? (string) $row['type'] : '';
        if (isset($counts[$type])) {
            $counts[$type]++;
        }

        $entries[] = array(
            'date'   => $at,
            'type'   => $type,
            'action' => isset($row['action']) ? (string) $row['action'] : 'update',
            'name'   => isset($row['name']) ? (string) $row['name'] : '',
            'from'   => isset($row['from']) ? (string) $row['from'] : '',
            'to'     => isset($row['to']) ? (string) $row['to'] : '',
        );
    }

    // Más recientes primero, acotado para no inflar la respuesta.
    $entries = array_reverse($entries);
    if (count($entries) > 200) {
        $entries = array_slice($entries, 0, 200);
    }

    return array(
        'applied_in_period' => $counts['core'] + $counts['plugin'] + $counts['theme'],
        'core'              => $counts['core'],
        'plugins'           => $counts['plugin'],
        'themes'            => $counts['theme'],
        'logging_since'     => $since,
        'entries'           => $entries,
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
 * Certificado SSL del propio sitio (igual que un monitor SSL tipo MainWP): abre una
 * conexión TLS al dominio y lee el certificado presentado — caducidad, emisor, validez.
 * Es una sola lectura en el origen (§3.3); no llama a servicios externos.
 *
 * @return array<string,mixed>
 */
function imagina_reports_agent_ssl() {
    $url  = home_url();
    $host = wp_parse_url($url, PHP_URL_HOST);

    if (strpos($url, 'https://') !== 0 || ! is_string($host) || $host === '' || ! function_exists('openssl_x509_parse')) {
        return array('checked' => false);
    }

    $port = (int) wp_parse_url($url, PHP_URL_PORT);
    if ($port === 0) {
        $port = 443;
    }

    $context = stream_context_create(array('ssl' => array(
        'capture_peer_cert' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'SNI_enabled'       => true,
        'peer_name'         => $host,
    )));

    $client = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno,
        $errstr,
        7,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($client === false) {
        return array('checked' => true, 'valid' => false, 'error' => 'No se pudo establecer la conexión TLS.');
    }

    $params = stream_context_get_params($client);
    fclose($client);

    $cert = isset($params['options']['ssl']['peer_certificate']) ? $params['options']['ssl']['peer_certificate'] : null;
    if ($cert === null) {
        return array('checked' => true, 'valid' => false, 'error' => 'No se recibió certificado.');
    }

    $parsed = openssl_x509_parse($cert);
    if (! is_array($parsed) || empty($parsed['validTo_time_t'])) {
        return array('checked' => true, 'valid' => false, 'error' => 'Certificado ilegible.');
    }

    $valid_to   = (int) $parsed['validTo_time_t'];
    $valid_from = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : 0;
    $now        = time();

    $issuer = '';
    if (isset($parsed['issuer']['O'])) {
        $issuer = is_array($parsed['issuer']['O']) ? implode(', ', $parsed['issuer']['O']) : (string) $parsed['issuer']['O'];
    } elseif (isset($parsed['issuer']['CN'])) {
        $issuer = (string) $parsed['issuer']['CN'];
    }

    return array(
        'checked'           => true,
        'valid'             => ($valid_to > $now && $valid_from <= $now),
        'expires_at'        => gmdate('c', $valid_to),
        'days_until_expiry' => (int) floor(($valid_to - $now) / 86400),
        'issuer'            => $issuer,
        'common_name'       => isset($parsed['subject']['CN']) ? (string) $parsed['subject']['CN'] : '',
    );
}

/**
 * Seguridad activa: auditoría de administradores/usuarios, spam bloqueado (Akismet +
 * comentarios marcados spam en el periodo) y banderas de endurecimiento. Todo por
 * conteos agregados en SQL (§3.3), columnas GMT para el periodo.
 *
 * @param string $from_gmt
 * @param string $to_gmt
 * @return array<string,mixed>
 */
function imagina_reports_agent_security($from_gmt, $to_gmt) {
    global $wpdb;

    $counts      = function_exists('count_users') ? count_users() : array();
    $admin_count = isset($counts['avail_roles']['administrator']) ? (int) $counts['avail_roles']['administrator'] : 0;
    $users_total = isset($counts['total_users']) ? (int) $counts['total_users'] : 0;

    $users_added = 0;
    if (is_object($wpdb)) {
        $users_added = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered BETWEEN %s AND %s",
            $from_gmt,
            $to_gmt
        ));
    }

    $spam_total  = (int) get_option('akismet_spam_count', 0);
    $spam_period = 0;
    if (is_object($wpdb)) {
        $spam_period = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND comment_date_gmt BETWEEN %s AND %s",
            $from_gmt,
            $to_gmt
        ));
    }

    $blog_public = get_option('blog_public');

    return array(
        'admins'                 => $admin_count,
        'users_total'            => $users_total,
        'users_added'            => $users_added,
        'spam_blocked_total'     => $spam_total,
        'spam_blocked_period'    => $spam_period,
        'search_engines_blocked' => ($blog_public === '0' || $blog_public === 0),
        'file_editing_disabled'  => (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT),
        'debug_off'              => ! (defined('WP_DEBUG') && WP_DEBUG),
        'https'                  => (strpos(home_url(), 'https://') === 0),
    );
}

/**
 * Rendimiento y salud técnica: caché (objetos + página), cron atrasado, y oportunidad
 * de limpieza de la base de datos (autoload, revisiones, papelera, spam, transients
 * caducados), más espacio en disco. Conteos agregados.
 *
 * @return array<string,mixed>
 */
function imagina_reports_agent_performance() {
    global $wpdb;

    // Caché de objetos.
    $object_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    $object_cache_type = '';
    if ($object_cache) {
        if (defined('WP_REDIS_HOST') || class_exists('Redis')) {
            $object_cache_type = 'Redis';
        } elseif (class_exists('Memcached') || class_exists('Memcache')) {
            $object_cache_type = 'Memcached';
        } else {
            $object_cache_type = 'Activa';
        }
    }

    // Caché de página (plugin conocido activo).
    $active = (array) get_option('active_plugins', array());
    $cache_plugins = array(
        'wp-rocket/wp-rocket.php'             => 'WP Rocket',
        'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
        'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
        'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
        'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
        'sg-cachepress/sg-cachepress.php'     => 'SG Optimizer',
        'cache-enabler/cache-enabler.php'     => 'Cache Enabler',
        'breeze/breeze.php'                   => 'Breeze',
        'wp-optimize/wp-optimize.php'         => 'WP-Optimize',
    );
    $page_cache = '';
    foreach ($cache_plugins as $slug => $name) {
        if (in_array($slug, $active, true)) {
            $page_cache = $name;
            break;
        }
    }

    // Cron atrasado.
    $cron_overdue = 0;
    if (function_exists('_get_cron_array')) {
        $crons = _get_cron_array();
        if (is_array($crons)) {
            $now = time();
            foreach ($crons as $timestamp => $hooks) {
                if ((int) $timestamp < $now && is_array($hooks)) {
                    $cron_overdue += count($hooks);
                }
            }
        }
    }

    // Limpieza de BD (agregados).
    $autoload_bytes     = 0;
    $revisions          = 0;
    $trashed_posts      = 0;
    $spam_comments      = 0;
    $trash_comments     = 0;
    $expired_transients = 0;

    if (is_object($wpdb)) {
        $autoload_bytes     = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off','auto-off')");
        $revisions          = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $trashed_posts      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
        $spam_comments      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $trash_comments     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
        $expired_transients = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            time()
        ));
    }

    $disk_free  = @disk_free_space(ABSPATH);
    $disk_total = @disk_total_space(ABSPATH);

    return array(
        'object_cache'       => $object_cache,
        'object_cache_type'  => $object_cache_type,
        'page_cache'         => $page_cache,
        'cron_overdue'       => $cron_overdue,
        'autoload_mb'        => imagina_reports_agent_mb($autoload_bytes),
        'revisions'          => $revisions,
        'trashed_posts'      => $trashed_posts,
        'spam_comments'      => $spam_comments,
        'trash_comments'     => $trash_comments,
        'expired_transients' => $expired_transients,
        'disk_free_mb'       => is_numeric($disk_free) ? imagina_reports_agent_mb((int) $disk_free) : null,
        'disk_total_mb'      => is_numeric($disk_total) ? imagina_reports_agent_mb((int) $disk_total) : null,
    );
}

/**
 * Contenido y actividad del periodo: publicaciones, páginas y comentarios.
 *
 * @param string $from_gmt
 * @param string $to_gmt
 * @return array<string,int>
 */
function imagina_reports_agent_content($from_gmt, $to_gmt) {
    global $wpdb;

    if (! is_object($wpdb)) {
        return array('posts_published' => 0, 'pages_published' => 0, 'comments_received' => 0, 'comments_approved' => 0);
    }

    $posts = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt BETWEEN %s AND %s",
        $from_gmt,
        $to_gmt
    ));
    $pages = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_date_gmt BETWEEN %s AND %s",
        $from_gmt,
        $to_gmt
    ));
    $received = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type IN ('comment','') AND comment_date_gmt BETWEEN %s AND %s",
        $from_gmt,
        $to_gmt
    ));
    $approved = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type IN ('comment','') AND comment_approved = '1' AND comment_date_gmt BETWEEN %s AND %s",
        $from_gmt,
        $to_gmt
    ));

    return array(
        'posts_published'   => $posts,
        'pages_published'   => $pages,
        'comments_received' => $received,
        'comments_approved' => $approved,
    );
}

/**
 * Captación / leads. Cuenta los envíos de formularios (total + del periodo) con esquemas
 * descubiertos vía /diagnostics — no se adivina (§0). Soporta varios plugins; si hay más
 * de uno instalado elige el que MÁS envíos tiene (el realmente usado), evitando falsos
 * positivos de un plugin instalado pero vacío:
 *   - Bit Form        → {prefix}bitforms_form_entries     (created_at, local)
 *   - Fluent Forms    → {prefix}fluentform_submissions    (created_at local, excl. trashed)
 *   - Elementor Pro   → {prefix}e_submissions             (created_at, local)
 *   - JetFormBuilder  → {prefix}jet_fb_records            (created_at, local)
 *   - Contact Form 7  → posts `flamingo_inbound`          (post_date_gmt)
 *
 * Cada fuente se valida por existencia de tabla; si su esquema difiere, degrada a 0 sin
 * romper. Las tablas propias filtran por hora local (current_time('mysql')).
 *
 * @param string $from_gmt
 * @param string $to_gmt
 * @param string $from_local
 * @param string $to_local
 * @return array<string,mixed>
 */
function imagina_reports_agent_leads($from_gmt, $to_gmt, $from_local, $to_local) {
    global $wpdb;

    $out = array('provider' => '', 'count_total' => 0, 'count_period' => 0);

    if (! is_object($wpdb)) {
        return $out;
    }

    $sources = array(
        array('provider' => 'Bit Form', 'table' => $wpdb->prefix . 'bitforms_form_entries', 'date' => 'created_at', 'where' => '', 'tz' => 'local'),
        array('provider' => 'Fluent Forms', 'table' => $wpdb->prefix . 'fluentform_submissions', 'date' => 'created_at', 'where' => "status != 'trashed'", 'tz' => 'local'),
        array('provider' => 'Elementor Pro', 'table' => $wpdb->prefix . 'e_submissions', 'date' => 'created_at', 'where' => '', 'tz' => 'local'),
        array('provider' => 'JetFormBuilder', 'table' => $wpdb->prefix . 'jet_fb_records', 'date' => 'created_at', 'where' => '', 'tz' => 'local'),
    );

    $best = null;

    foreach ($sources as $source) {
        $table = $source['table'];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            continue;
        }

        $safe = '`' . str_replace('`', '', $table) . '`';
        $cond = $source['where'] !== '' ? ' WHERE ' . $source['where'] : '';
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $safe . $cond);

        if ($best === null || $total > $best['total']) {
            $best = array('provider' => $source['provider'], 'safe' => $safe, 'date' => $source['date'], 'where' => $source['where'], 'tz' => $source['tz'], 'total' => $total);
        }
    }

    // Contact Form 7 (Flamingo) compite también, pero es un post type, no una tabla.
    $flamingo_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound'");
    if ($flamingo_total > 0 && ($best === null || $flamingo_total > $best['total'])) {
        $best = array('provider' => 'Contact Form 7', 'flamingo' => true, 'total' => $flamingo_total);
    }

    if ($best === null) {
        return $out;
    }

    $out['provider']    = $best['provider'];
    $out['count_total'] = $best['total'];

    if (! empty($best['flamingo'])) {
        $out['count_period'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound' AND post_date_gmt BETWEEN %s AND %s",
            $from_gmt,
            $to_gmt
        ));

        return $out;
    }

    $from = $best['tz'] === 'local' ? $from_local : $from_gmt;
    $to   = $best['tz'] === 'local' ? $to_local : $to_gmt;
    $prefix_cond = $best['where'] !== '' ? ' ' . $best['where'] . ' AND' : '';

    $out['count_period'] = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $best['safe'] . ' WHERE' . $prefix_cond . ' ' . $best['date'] . ' BETWEEN %s AND %s',
        $from,
        $to
    ));

    return $out;
}

/**
 * E-commerce operativo (si WooCommerce está activo): stock agotado/bajo y pedidos por
 * atender. Usa la API de WooCommerce para pedidos (compatible con HPOS) y conteos de
 * postmeta para el stock de productos.
 *
 * @return array<string,mixed>
 */
function imagina_reports_agent_ecommerce() {
    if (! class_exists('WooCommerce')) {
        return array('active' => false);
    }

    global $wpdb;

    $out_of_stock = 0;
    $low_stock    = 0;
    if (is_object($wpdb)) {
        $out_of_stock = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_stock_status' AND meta_value = %s",
            'outofstock'
        ));

        $threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
        $low_stock = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} ss ON ss.post_id = pm.post_id AND ss.meta_key = '_stock_status' AND ss.meta_value = 'instock'
             WHERE pm.meta_key = '_stock' AND pm.meta_value <> '' AND CAST(pm.meta_value AS SIGNED) <= %d",
            $threshold
        ));
    }

    $pending    = 0;
    $processing = 0;
    if (function_exists('wc_get_orders')) {
        $pending = count(wc_get_orders(array('status' => array('pending', 'on-hold'), 'limit' => -1, 'return' => 'ids')));
        $processing = count(wc_get_orders(array('status' => 'processing', 'limit' => -1, 'return' => 'ids')));
    }

    return array(
        'active'           => true,
        'out_of_stock'     => $out_of_stock,
        'low_stock'        => $low_stock,
        'pending_orders'   => $pending,
        'processing_orders' => $processing,
    );
}

/**
 * Logins: intentos fallidos y bloqueos en el periodo. Fuente preferente Wordfence
 * (tabla wflogins: columna `fail` + `ctime` timestamp Unix; tabla wfblocks7: `blockedTime`),
 * descubierta vía /diagnostics. Fallback: contador de por vida de Limit Login Attempts.
 * Conteos agregados (§3.3).
 *
 * @param int $from_ts
 * @param int $to_ts
 * @return array<string,mixed>
 */
function imagina_reports_agent_logins($from_ts, $to_ts) {
    global $wpdb;

    $out = array('provider' => '', 'failed_period' => 0, 'blocked_period' => 0, 'blocked_total' => 0);

    if (! is_object($wpdb)) {
        return $out;
    }

    $logins = $wpdb->prefix . 'wflogins';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($logins))) === $logins) {
        $out['provider'] = 'Wordfence';

        $safe_logins = '`' . str_replace('`', '', $logins) . '`';
        $out['failed_period'] = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $safe_logins . ' WHERE fail = 1 AND ctime BETWEEN %f AND %f',
            (float) $from_ts,
            (float) $to_ts
        ));

        $blocks = $wpdb->prefix . 'wfblocks7';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($blocks))) === $blocks) {
            $safe_blocks = '`' . str_replace('`', '', $blocks) . '`';
            $out['blocked_period'] = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $safe_blocks . ' WHERE blockedTime BETWEEN %d AND %d',
                $from_ts,
                $to_ts
            ));
        }

        return $out;
    }

    // Fallback: Limit Login Attempts (solo contador acumulado, sin periodo).
    $total = get_option('limit_login_lockouts_total', null);
    if ($total !== null) {
        $out['provider']      = 'Limit Login Attempts';
        $out['blocked_total'] = (int) $total;
    }

    return $out;
}

/**
 * Imágenes optimizadas. Fuente ShortPixel (tabla shortpixel_postmeta), descubierta vía
 * /diagnostics. Cuenta solo filas ya optimizadas (compressed_size > 0) y suma el ahorro
 * real, sin asumir códigos de estado internos. Conteos agregados (§3.3).
 *
 * @return array<string,mixed>
 */
function imagina_reports_agent_images() {
    global $wpdb;

    $out = array('provider' => '', 'optimized' => 0, 'saved_mb' => 0.0);

    if (! is_object($wpdb)) {
        return $out;
    }

    $table = $wpdb->prefix . 'shortpixel_postmeta';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
        $safe = '`' . str_replace('`', '', $table) . '`';

        $out['provider']  = 'ShortPixel';
        $out['optimized'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $safe . ' WHERE compressed_size > 0');
        $saved            = (int) $wpdb->get_var('SELECT SUM(original_size - compressed_size) FROM ' . $safe . ' WHERE compressed_size > 0 AND original_size >= compressed_size');
        $out['saved_mb']  = imagina_reports_agent_mb($saved);
    }

    return $out;
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

    // Patrón de carpeta => proveedor. Soporta comodines via glob. WPvivid usa varios
    // nombres según versión/config (y Linux distingue mayúsculas), así que cubrimos todos.
    $dirs = array(
        $content . '/updraft'           => 'UpdraftPlus',
        $content . '/wpvividbackups'    => 'WPvivid',
        $content . '/wpvivid_uploads'   => 'WPvivid',
        $content . '/WPvivid_Uploads'   => 'WPvivid',
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
