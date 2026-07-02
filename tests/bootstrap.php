<?php
// Contexto mínimo para probar la lógica pura sin cargar WordPress.
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/chc-abspath/'); }
$root = dirname(__DIR__);
foreach ([
    '/includes/class-cache-store.php',
    '/includes/class-htaccess.php',
    '/includes/class-request-rules.php',
    '/includes/class-role-gate.php',
] as $f) {
    if (is_file($root . $f)) { require $root . $f; }
}
