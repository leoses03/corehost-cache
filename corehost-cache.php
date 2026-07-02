<?php
/**
 * Plugin Name: CoreHost Cache
 * Description: Page cache estático servido por .htaccess sin PHP (LiteSpeed lo comprime al vuelo), con exclusión por rol e invalidación por evento+TTL. Seguro para WooCommerce.
 * Version: 1.3.0
 * Author: CoreHost
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

define('CHC_VERSION', '1.3.0');
define('CHC_DIR', plugin_dir_path(__FILE__));

require_once CHC_DIR . 'includes/class-cache-store.php';
require_once CHC_DIR . 'includes/class-htaccess.php';
require_once CHC_DIR . 'includes/class-request-rules.php';
require_once CHC_DIR . 'includes/class-role-gate.php';
require_once CHC_DIR . 'includes/class-page-generator.php';
require_once CHC_DIR . 'includes/class-cloudflare.php';
require_once CHC_DIR . 'includes/class-purge.php';
require_once CHC_DIR . 'admin/class-admin-page.php';
require_once CHC_DIR . 'admin/class-admin-bar.php';
require_once CHC_DIR . 'admin/class-post-meta.php';

/** Ajustes con defaults; excluded_roles = TODOS los roles si no se guardó nada. */
function chc_settings(): array
{
    // Se sirve index.html PLANO y el servidor (LiteSpeed) lo comprime al vuelo,
    // así que no hay toggles de compresión pre-generada (ver spec §4).
    $d = ['enabled' => 1, 'ttl_hours' => 10, 'excluded_urls' => '', 'cf_enabled' => 0, 'cf_zone' => ''];
    $s = array_merge($d, (array) get_option('chc_settings', []));
    if (!isset($s['excluded_roles'])) {
        $s['excluded_roles'] = function_exists('wp_roles') ? array_keys(wp_roles()->get_names()) : ['administrator'];
    }
    return $s;
}

function chc_store(): CHC_Cache_Store
{
    return new CHC_Cache_Store(WP_CONTENT_DIR . '/cache/corehost-cache');
}

/** Parámetros de query que son solo de tracking (no cambian el HTML de servidor). */
function chc_tracking_params(): array
{
    $list = ['gclid','gclsrc','dclid','wbraid','gbraid','fbclid','msclkid','mc_cid','mc_eid','ttclid','twclid','igshid','yclid'];
    return array_values(array_unique(array_map('strval', (array) apply_filters('chc_tracking_params', $list))));
}

/**
 * Ruta URL (desde docroot) al dir de cache; maneja instalación en subdirectorio.
 * Se deriva de content_url() para ser correcta también bajo WP-CLI (donde
 * $_SERVER['DOCUMENT_ROOT'] suele estar vacío, p.ej. al activar por `wp plugin activate`).
 * Ej. root: /wp-content/cache/corehost-cache · subdir /key: /key/wp-content/cache/corehost-cache
 */
function chc_cache_url_path(): string
{
    $path = (string) parse_url(content_url('/cache/corehost-cache'), PHP_URL_PATH);
    return $path !== '' ? '/' . ltrim($path, '/') : '/wp-content/cache/corehost-cache';
}

function chc_root_htaccess(): string { return ABSPATH . '.htaccess'; }

function chc_refresh_htaccess(): void
{
    $ok = CHC_Htaccess::install(chc_root_htaccess(), CHC_Htaccess::rules(chc_cache_url_path()));
    update_option('chc_htaccess_writable', $ok ? 1 : 0, false);
}

/** Protege el dir de cache: sin listado de directorios ni ejecución de PHP suelto. */
function chc_protect_cache_dir(): void
{
    $base = WP_CONTENT_DIR . '/cache/corehost-cache';
    wp_mkdir_p($base);
    if (!is_file($base . '/index.php')) {
        @file_put_contents($base . '/index.php', "<?php // Silence is golden.");
    }
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "Options -Indexes\n");
    }
}

register_activation_hook(__FILE__, function () {
    wp_mkdir_p(WP_CONTENT_DIR . '/cache/corehost-cache');
    chc_protect_cache_dir();
    chc_refresh_htaccess();
    if (!wp_next_scheduled('chc_ttl_sweep')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'chc_ttl_sweep');
    }
});

register_deactivation_hook(__FILE__, function () {
    CHC_Htaccess::remove(chc_root_htaccess());
    wp_clear_scheduled_hook('chc_ttl_sweep');
    chc_store()->purge_all();
});

// Regenerar reglas al guardar ajustes.
add_action('update_option_chc_settings', 'chc_refresh_htaccess');
add_action('add_option_chc_settings', 'chc_refresh_htaccess');

// Wiring de componentes.
(new CHC_Page_Generator())->register();
(new CHC_Purge())->register();
(new CHC_Role_Gate())->register();
(new CHC_Admin_Bar())->register(); // barra de admin: sale también en el front
if (is_admin()) {
    (new CHC_Admin_Page())->register();
    (new CHC_Post_Meta())->register();
}

if (defined('WP_CLI') && WP_CLI) {
    require_once CHC_DIR . 'includes/class-cli.php';
    WP_CLI::add_command('corehost-cache', 'CHC_CLI');
}
