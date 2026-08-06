<?php
if (!defined('ABSPATH')) { exit; }

/** Bufferiza la salida de páginas anónimas cacheables y las escribe al terminar. */
class CHC_Page_Generator
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_start'], 0);
    }

    public function maybe_start(): void
    {
        if (empty(chc_settings()['enabled'])) { return; }
        if (!CHC_Request_Rules::is_cacheable()) { return; }
        ob_start([$this, 'finish']);
    }

    public function finish(string $html): string
    {
        if ($html !== ''
            && http_response_code() === 200
            && !is_user_logged_in()
            && self::host_allowed($_SERVER['HTTP_HOST'] ?? '')
            && !self::has_nocache_headers()
            && CHC_Request_Rules::is_cacheable()
            && self::html_complete($html)
        ) {
            $marked = $html . "\n<!-- corehost-cache " . gmdate('Y-m-d H:i:s') . " UTC -->";
            chc_store()->write(
                $_SERVER['HTTP_HOST'] ?? '',
                $_SERVER['REQUEST_URI'] ?? '/',
                $marked
            );
        }
        return $html;
    }

    /**
     * No almacenar capturas de HTML que se saben rotas (CSS de Elementor aún no
     * reescrito en disco, o página con datos de Elementor renderizada sin él).
     * El visitante recibe su HTML igual; solo se omite el guardado.
     */
    private static function html_complete(string $html): bool
    {
        $reason = null;
        $uploads = wp_get_upload_dir();
        if (empty($uploads['error'])) {
            $reason = CHC_Html_Integrity::assets_veto($html, (string) $uploads['baseurl'], (string) $uploads['basedir']);
        }
        if ($reason === null && function_exists('is_singular') && is_singular()) {
            $id = (int) get_queried_object_id();
            if ($id > 0) {
                $reason = CHC_Html_Integrity::elementor_veto(
                    $html,
                    $id,
                    strlen((string) get_post_meta($id, '_elementor_data', true)),
                    metadata_exists('post', $id, '_elementor_edit_mode'),
                    (string) get_post_meta($id, '_elementor_edit_mode', true)
                );
            }
        }
        if ($reason === null && !apply_filters('chc_html_complete', true, $html)) {
            $reason = 'filtro';
        }
        if ($reason !== null) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            update_option('chc_last_veto', ['ts' => time(), 'uri' => $uri, 'reason' => $reason], false);
            do_action('chc_store_vetoed', $reason, $uri);
            return false;
        }
        return true;
    }

    /** Solo cachear el host canónico del sitio (o su variante www). Evita cache poisoning por Host. */
    private static function host_allowed(string $host): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));
        if ($host === '') { return false; }
        $canon = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        return $host === $canon || $host === 'www.' . $canon || 'www.' . $host === $canon;
    }

    private static function has_nocache_headers(): bool
    {
        foreach (headers_list() as $h) {
            $l = strtolower($h);
            if (strpos($l, 'set-cookie:') === 0) { return true; }
            if (strpos($l, 'cache-control:') === 0 && preg_match('/no-cache|no-store|private/', $l)) { return true; }
        }
        return false;
    }
}
