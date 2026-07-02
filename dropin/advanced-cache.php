<?php
/* CoreHost Cache drop-in — sirve el HTML cacheado antes de cargar WP (para hosts sin .htaccess, p.ej. nginx). */
if (!defined('ABSPATH') && !defined('CHC_DROPIN_TEST')) { return; }

/** Ruta del archivo cacheado a servir, o null. PURA (sin deps de WP). Query debe ir vacía en v1. */
function chc_dropin_cache_file(array $server, array $cookies, string $base): ?string
{
    if (($server['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return null; }
    if (($server['QUERY_STRING'] ?? '') !== '') { return null; }
    $uri = $server['REQUEST_URI'] ?? '/';
    if (strpos($uri, '?') !== false) { $uri = substr($uri, 0, strpos($uri, '?')); }
    if (substr($uri, -1) !== '/') { return null; }                 // solo directorios/index.html
    if (strpos($uri, '..') !== false) { return null; }
    if (preg_match('#(^|/)(wp-admin|wp-login|wp-cron|wp-json|xmlrpc)([/.]|$)#i', $uri)) { return null; }
    $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    if (!preg_match('/^[a-z0-9.\-]+$/', $host)) { return null; }
    $cookie = $server['HTTP_COOKIE'] ?? '';
    if ($cookie === '' && $cookies) { $cookie = implode('; ', array_keys($cookies)); }
    if (preg_match('/(chc_nocache|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/', (string) $cookie)) { return null; }
    $file = rtrim($base, '/') . '/' . $host . rtrim($uri, '/') . '/index.html';
    return is_file($file) ? $file : null;
}

if (!defined('CHC_DROPIN_TEST')) {
    $chc_base = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content' : '')) . '/cache/corehost-cache';
    $chc_file = @chc_dropin_cache_file($_SERVER, $_COOKIE, $chc_base);
    if ($chc_file !== null) {
        $html = @file_get_contents($chc_file);
        if ($html !== false && $html !== '') {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-CoreHost-Cache: HIT');
            header('Cache-Control: public, max-age=600');
            header('Vary: Accept-Encoding');
            $ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            if (stripos($ae, 'gzip') !== false && function_exists('gzencode')) {
                $gz = gzencode($html, 6);
                if ($gz !== false) { header('Content-Encoding: gzip'); $html = $gz; }
            }
            header('Content-Length: ' . strlen($html));
            echo $html;
            exit;
        }
    }
    // sin cache aplicable: WP continúa normal.
}
