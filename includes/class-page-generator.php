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
