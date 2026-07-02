<?php
if (!defined('ABSPATH')) { exit; }

/** Decide si una request es cacheable. `should_cache()` es pura; `is_cacheable()` es el glue WP. */
class CHC_Request_Rules
{
    public static function should_cache(array $c): bool
    {
        if (!empty($c['is_admin']))  { return false; }
        if (!empty($c['logged_in'])) { return false; }
        if (($c['method'] ?? 'GET') !== 'GET') { return false; }
        if (($c['query'] ?? '') !== '') { return false; }
        if ((int) ($c['status'] ?? 200) !== 200) { return false; }
        if (stripos((string) ($c['content_type'] ?? 'text/html'), 'text/html') === false) { return false; }
        if (!empty($c['is_feed']))    { return false; }
        if (!empty($c['donotcache'])) { return false; }
        if (!empty($c['excluded_url'])) { return false; }
        if (!empty($c['woo_dynamic'])) { return false; }
        if (!empty($c['password_required'])) { return false; }
        if (!empty($c['bypass_cookie']))     { return false; }
        return true;
    }

    public static function matches_any(string $uri, array $patterns): bool
    {
        foreach ($patterns as $p) {
            $p = trim((string) $p);
            if ($p !== '' && stripos($uri, $p) !== false) { return true; }
        }
        return false;
    }

    /** Glue WP: reúne el contexto y llama a should_cache(). */
    public static function is_cacheable(): bool
    {
        if (is_admin() || is_user_logged_in()) { return false; }

        $woo = false;
        if (function_exists('is_cart')) {
            $woo = is_cart() || is_checkout() || is_account_page();
            if (!$woo && function_exists('WC') && WC()->cart) {
                $woo = WC()->cart->get_cart_contents_count() > 0;
            }
        }
        $s = chc_settings();
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $status = http_response_code() ?: 200;

        return self::should_cache([
            'is_admin'          => false,
            'logged_in'         => false,
            'method'            => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'query'             => $_SERVER['QUERY_STRING'] ?? '',
            'status'            => $status,
            'content_type'      => 'text/html',
            'is_feed'           => is_feed(),
            'donotcache'        => defined('DONOTCACHEPAGE') && DONOTCACHEPAGE,
            'excluded_url'      => self::matches_any($uri, array_map('trim', explode("\n", (string) ($s['excluded_urls'] ?? '')))),
            'woo_dynamic'       => $woo,
            'password_required' => function_exists('post_password_required') && post_password_required(),
            'bypass_cookie'     => (bool) preg_match('/(chc_nocache|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/', (string) ($_SERVER['HTTP_COOKIE'] ?? '')),
        ]);
    }
}
