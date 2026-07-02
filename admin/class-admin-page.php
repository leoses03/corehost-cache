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
        add_action('wp_ajax_chc_warm_step', [$this, 'ajax_warm']);
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

        // El token es tipo password: si llega vacío (p.ej. el usuario no lo tocó), conserva el guardado.
        $old       = (array) get_option('chc_settings', []);
        $new_token = sanitize_text_field((string) ($input['cf_token'] ?? ''));
        $cf_token  = $new_token !== '' ? $new_token : (string) ($old['cf_token'] ?? '');

        return [
            'enabled'        => empty($input['enabled']) ? 0 : 1,
            'ttl_hours'      => max(0, (int) ($input['ttl_hours'] ?? 10)),
            'excluded_urls'  => sanitize_textarea_field($input['excluded_urls'] ?? ''),
            'excluded_roles' => $excluded,
            'cf_enabled'     => empty($input['cf_enabled']) ? 0 : 1,
            'cf_zone'        => sanitize_text_field((string) ($input['cf_zone'] ?? '')),
            'cf_token'       => $cf_token,
            'auto_warm'      => empty($input['auto_warm']) ? 0 : 1,
        ];
    }

    public function enqueue($hook): void
    {
        if ($hook !== 'settings_page_chc-settings') { return; }
        wp_enqueue_script('chc-admin', plugins_url('admin.js', __FILE__), [], CHC_VERSION, true);
        wp_localize_script('chc-admin', 'chcAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('chc_purge'),
            'warmNonce' => wp_create_nonce('chc_warm'),
        ]);
    }

    public function ajax_purge(): void
    {
        check_ajax_referer('chc_purge');
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
        chc_purge_all();
        wp_send_json_success(['ok' => true]);
    }

    /** URLs a precargar: home + todas las entradas/páginas públicas publicadas (sin query string). */
    public static function warm_urls(): array
    {
        $urls  = [home_url('/')];
        $types = get_post_types(['public' => true]);
        unset($types['attachment']);
        $q = new WP_Query([
            'post_type'      => array_values($types),
            'post_status'    => 'publish',
            'posts_per_page' => 2000,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        foreach ($q->posts as $id) {
            $link = get_permalink($id);
            if (is_string($link) && $link !== '') { $urls[] = $link; }
        }
        return array_values(array_unique(array_filter($urls, static fn($u) => strpos($u, '?') === false)));
    }

    /** Precarga por lotes (AJAX): visita las URLs para que se generen en el cache. */
    public function ajax_warm(): void
    {
        check_ajax_referer('chc_warm');
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        if ($offset === 0) {
            $urls = self::warm_urls();
            set_transient('chc_warm_urls', $urls, 10 * MINUTE_IN_SECONDS);
        } else {
            $urls = (array) get_transient('chc_warm_urls');
        }
        $total = count($urls);
        foreach (array_slice($urls, $offset, 5) as $url) {
            wp_remote_get($url, [
                'timeout'    => 15,
                'sslverify'  => false,
                'user-agent' => 'CoreHostCache-Warmer',
            ]);
        }
        $processed = min($offset + 5, $total);
        if ($processed >= $total) { delete_transient('chc_warm_urls'); }
        wp_send_json_success(['total' => $total, 'processed' => $processed, 'done' => $processed >= $total]);
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
                    <tr><th scope="row">Cloudflare</th><td>
                        <label><input type="checkbox" name="chc_settings[cf_enabled]" value="1" <?php checked($s['cf_enabled']); ?>> Purgar también el edge de Cloudflare al invalidar</label>
                        <p>
                            <label>Zone ID<br>
                                <input type="text" name="chc_settings[cf_zone]" value="<?php echo esc_attr($s['cf_zone']); ?>" style="width:100%;max-width:400px" autocomplete="off">
                            </label>
                        </p>
                        <?php if (defined('CHC_CF_TOKEN')) : ?>
                            <p class="description">Token tomado de la constante <code>CHC_CF_TOKEN</code> en <code>wp-config.php</code> (recomendado). El campo de abajo se ignora mientras esa constante exista.</p>
                        <?php else : ?>
                            <p>
                                <label>API Token (permiso Zone → Cache Purge)<br>
                                    <input type="password" name="chc_settings[cf_token]" value="" placeholder="<?php echo !empty($s['cf_token']) ? esc_attr__('•••••••• (guardado, dejar vacío para conservarlo)', 'corehost-cache') : ''; ?>" style="width:100%;max-width:400px" autocomplete="off">
                                </label>
                            </p>
                            <p class="description">Se recomienda definir la constante <code>CHC_CF_TOKEN</code> en <code>wp-config.php</code> en vez de guardar el token aquí (queda en la BD en texto plano).</p>
                        <?php endif; ?>
                        <?php if ($cf_err = (string) get_option('chc_cf_last_error', '')) : ?>
                            <p class="description" style="color:#b32d2e">Último error Cloudflare: <?php echo esc_html($cf_err); ?></p>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th scope="row">Auto-precarga</th><td>
                        <label><input type="checkbox" name="chc_settings[auto_warm]" value="1" <?php checked($s['auto_warm']); ?>> Precargar el cache automáticamente tras una purga total</label>
                        <p class="description">Agenda una precarga en segundo plano (WP-Cron) unos segundos después de purgar todo.</p>
                    </td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Estado</h2>
            <p><?php echo (int) $stats['pages']; ?> páginas cacheadas · <?php echo esc_html(size_format($stats['bytes'], 1)); ?> en disco.
            <?php if ($lp = (int) get_option('chc_last_purge', 0)) : ?> Última purga: <?php echo esc_html(date_i18n('Y-m-d H:i', $lp)); ?>.<?php endif; ?></p>
            <p><button type="button" class="button button-primary" id="chc-purge">Purgar todo</button> <span id="chc-purge-msg"></span></p>

            <hr>
            <h2>Precargar cache</h2>
            <p class="description">Visita la home y todas las entradas/páginas publicadas para dejarlas guardadas en el cache.</p>
            <p><button type="button" class="button" id="chc-warm">Precargar ahora</button> <span id="chc-warm-msg"></span></p>
            <div id="chc-warm-progress" style="display:none;max-width:360px">
                <progress id="chc-warm-bar" max="100" value="0" style="width:100%"></progress>
                <span id="chc-warm-count"></span>
            </div>
        </div>
        <?php
    }
}
