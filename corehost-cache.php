<?php
/**
 * Plugin Name: CoreHost Cache
 * Description: Page cache estático (HTML + gzip + brotli) servido por .htaccess sin PHP, con exclusión por rol e invalidación por evento+TTL. Seguro para WooCommerce.
 * Version: 1.0.0
 * Author: CoreHost
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

define('CHC_VERSION', '1.0.0');
define('CHC_DIR', plugin_dir_path(__FILE__));

require_once CHC_DIR . 'includes/class-cache-store.php';
require_once CHC_DIR . 'includes/class-htaccess.php';
require_once CHC_DIR . 'includes/class-request-rules.php';
require_once CHC_DIR . 'includes/class-role-gate.php';
require_once CHC_DIR . 'includes/class-page-generator.php';
require_once CHC_DIR . 'includes/class-purge.php';
require_once CHC_DIR . 'admin/class-admin-page.php';

/** Ajustes con defaults; excluded_roles = TODOS los roles si no se guardó nada. */
function chc_settings(): array
{
    $d = ['enabled' => 1, 'ttl_hours' => 10, 'cache_404' => 0, 'excluded_urls' => '', 'gzip' => 1, 'brotli' => 1];
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

/** Ruta URL (desde docroot) al dir de cache; maneja instalación en subdirectorio. */
function chc_cache_url_path(): string
{
    $docroot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cachefs = rtrim(str_replace('\\', '/', WP_CONTENT_DIR . '/cache/corehost-cache'), '/');
    if ($docroot !== '' && str_starts_with($cachefs, $docroot)) {
        return substr($cachefs, strlen($docroot)); // ej. /key/wp-content/cache/corehost-cache
    }
    return '/wp-content/cache/corehost-cache';
}

function chc_root_htaccess(): string { return ABSPATH . '.htaccess'; }

function chc_refresh_htaccess(): void
{
    $ok = CHC_Htaccess::install(chc_root_htaccess(), CHC_Htaccess::rules(chc_cache_url_path()));
    update_option('chc_htaccess_writable', $ok ? 1 : 0, false);
}

register_activation_hook(__FILE__, function () {
    wp_mkdir_p(WP_CONTENT_DIR . '/cache/corehost-cache');
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
if (is_admin()) { (new CHC_Admin_Page())->register(); }

if (defined('WP_CLI') && WP_CLI) {
    require_once CHC_DIR . 'includes/class-cli.php';
    WP_CLI::add_command('corehost-cache', 'CHC_CLI');
}
