<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Botón "Borrar caché" en la barra de administración de WordPress (front y back),
 * visible solo para usuarios con manage_options. Purga todo el cache vía admin-post
 * con nonce y vuelve a la página anterior mostrando un aviso.
 */
class CHC_Admin_Bar
{
    private const ACTION = 'chc_purge_all';
    private const NONCE  = 'chc_purge_bar';

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'add_button'], 100);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function add_button(WP_Admin_Bar $bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $bar->add_node([
            'id'    => 'chc-purge',
            'title' => '<span class="ab-icon dashicons dashicons-trash" aria-hidden="true" style="top:2px"></span>'
                     . '<span class="ab-label">' . esc_html__('Borrar caché', 'corehost-cache') . '</span>',
            'href'  => wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::NONCE),
            'meta'  => ['title' => esc_attr__('Vaciar todo el cache de CoreHost Cache', 'corehost-cache')],
        ]);
    }

    public function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado', 'corehost-cache'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);
        chc_store()->purge_all();
        update_option('chc_last_purge', time(), false);
        wp_safe_redirect(add_query_arg('chc_purged', '1', wp_get_referer() ?: admin_url()));
        exit;
    }

    public function notice(): void
    {
        if (!empty($_GET['chc_purged']) && current_user_can('manage_options')) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Caché borrada.', 'corehost-cache')
                . '</p></div>';
        }
    }
}
