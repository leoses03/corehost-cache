<?php
// Contexto mínimo para probar la lógica pura sin cargar WordPress.
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/chc-abspath/'); }
$root = dirname(__DIR__);
foreach ([
    '/includes/class-cache-store.php',
    '/includes/class-html-integrity.php',
    '/includes/class-htaccess.php',
    '/includes/class-request-rules.php',
    '/includes/class-role-gate.php',
    '/includes/class-cloudflare.php',
    '/includes/class-dropin.php',
] as $f) {
    if (is_file($root . $f)) { require $root . $f; }
}

// Drop-in en modo test: define chc_dropin_cache_file() sin disparar el bloque de servicio (exit).
define('CHC_DROPIN_TEST', true);
require $root . '/dropin/advanced-cache.php';
