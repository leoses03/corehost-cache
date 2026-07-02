<?php
if (!defined('ABSPATH')) { exit; }

/** Página de ajustes: on/off, TTL, roles, exclusiones, purga y stats. */
class CHC_Admin_Page
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_chc_purge_all', [$this, 'ajax_purge']);
    }

    public function menu(): void
    {
        add_options_page('CoreHost Cache', 'CoreHost Cache', 'manage_options', 'chc-settings', [$this, 'render']);
    }

    public function settings(): void
    {
        register_setting('chc', 'chc_settings', ['sanitize_callback' => [$this, 'sanitize']]);
    }

    public function sanitize($input): array
    {
        $input = (array) $input;
        $roles = function_exists('wp_roles') ? array_keys(wp_roles()->get_names()) : [];
        $excluded = array_values(array_intersect($roles, (array) ($input['excluded_roles'] ?? [])));
        return [
            'enabled'        => empty($input['enabled']) ? 0 : 1,
            'ttl_hours'      => max(0, (int) ($input['ttl_hours'] ?? 10)),
            'cache_404'      => empty($input['cache_404']) ? 0 : 1,
            'excluded_urls'  => sanitize_textarea_field($input['excluded_urls'] ?? ''),
            'gzip'           => empty($input['gzip']) ? 0 : 1,
            'brotli'         => empty($input['brotli']) ? 0 : 1,
            'excluded_roles' => $excluded,
        ];
    }

    public function enqueue($hook): void
    {
        if ($hook !== 'settings_page_chc-settings') { return; }
        wp_enqueue_script('chc-admin', plugins_url('admin.js', __FILE__), [], CHC_VERSION, true);
        wp_localize_script('chc-admin', 'chcAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('chc_purge'),
        ]);
    }

    public function ajax_purge(): void
    {
        check_ajax_referer('chc_purge');
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
        chc_store()->purge_all();
        update_option('chc_last_purge', time(), false);
        wp_send_json_success(['ok' => true]);
    }

    public function render(): void
    {
        $s     = chc_settings();
        $stats = chc_store()->stats();
        $roles = function_exists('wp_roles') ? wp_roles()->get_names() : [];
        ?>
        <div class="wrap">
            <h1>CoreHost Cache</h1>
            <?php if (!get_option('chc_htaccess_writable', 1)) : ?>
                <div class="notice notice-error"><p>No se pudo escribir <code><?php echo esc_html(chc_root_htaccess()); ?></code>. Pega este bloque arriba de <code># BEGIN WordPress</code>:</p>
                <textarea readonly rows="12" style="width:100%;font-family:monospace"><?php echo esc_textarea(CHC_Htaccess::rules(chc_cache_url_path())); ?></textarea></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('chc'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Cache</th><td>
                        <label><input type="checkbox" name="chc_settings[enabled]" value="1" <?php checked($s['enabled']); ?>> Activar</label>
                    </td></tr>
                    <tr><th scope="row">TTL (horas)</th><td>
                        <input type="number" min="0" name="chc_settings[ttl_hours]" value="<?php echo esc_attr($s['ttl_hours']); ?>"> <span class="description">0 = sin expiración por tiempo</span>
                    </td></tr>
                    <tr><th scope="row">Compresión</th><td>
                        <label><input type="checkbox" name="chc_settings[gzip]" value="1" <?php checked($s['gzip']); ?>> gzip</label>&nbsp;
                        <label><input type="checkbox" name="chc_settings[brotli]" value="1" <?php checked($s['brotli']); ?>> Brotli</label>
                    </td></tr>
                    <tr><th scope="row">404</th><td>
                        <label><input type="checkbox" name="chc_settings[cache_404]" value="1" <?php checked($s['cache_404']); ?>> Cachear páginas 404</label>
                    </td></tr>
                    <tr><th scope="row">Roles excluidos del cache</th><td>
                        <p class="description">Los roles marcados <strong>siempre reciben la página fresca</strong> (bypass). Los desmarcados reciben la versión cacheada anónima.</p>
                        <?php foreach ($roles as $slug => $name) : ?>
                            <label style="display:inline-block;min-width:200px">
                                <input type="checkbox" name="chc_settings[excluded_roles][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $s['excluded_roles'], true)); ?>>
                                <?php echo esc_html($name); ?> <code><?php echo esc_html($slug); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </td></tr>
                    <tr><th scope="row">URLs excluidas</th><td>
                        <textarea name="chc_settings[excluded_urls]" rows="4" style="width:100%" placeholder="/carrito&#10;/mi-cuenta"><?php echo esc_textarea($s['excluded_urls']); ?></textarea>
                        <p class="description">Una por línea; coincidencia por subcadena de la URL.</p>
                    </td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Estado</h2>
            <p><?php echo (int) $stats['pages']; ?> páginas cacheadas · <?php echo esc_html(size_format($stats['bytes'], 1)); ?> en disco.
            <?php if ($lp = (int) get_option('chc_last_purge', 0)) : ?> Última purga: <?php echo esc_html(date_i18n('Y-m-d H:i', $lp)); ?>.<?php endif; ?></p>
            <p><button type="button" class="button button-primary" id="chc-purge">Purgar todo</button> <span id="chc-purge-msg"></span></p>
        </div>
        <?php
    }
}
