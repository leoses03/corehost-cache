<?php
$block = CHC_Htaccess::rules('/key/wp-content/cache/corehost-cache');

t_assert(str_contains($block, '# BEGIN CoreHost Cache'), 'marcador BEGIN');
t_assert(str_contains($block, '# END CoreHost Cache'), 'marcador END');
t_assert(str_contains($block, 'RewriteCond %{REQUEST_METHOD} GET'), 'solo GET');
t_assert(str_contains($block, 'RewriteCond %{QUERY_STRING} ^$'), 'query vacío');
t_assert(str_contains($block, 'chc_nocache'), 'bypass por cookie de rol');
t_assert(str_contains($block, 'woocommerce_items_in_cart'), 'bypass carrito woo');
t_assert(str_contains($block, '/index.html.br'), 'sirve brotli');
t_assert(str_contains($block, '/index.html.gz'), 'sirve gzip');
t_assert(str_contains($block, '%{DOCUMENT_ROOT}/key/wp-content/cache/corehost-cache/%{HTTP_HOST}%{REQUEST_URI}/index.html'), 'ruta de existencia con prefijo subdir');
t_assert(str_contains($block, 'X-CoreHost-Cache'), 'header de HIT');
t_assert(str_contains($block, 'Content-Encoding'), 'header content-encoding');

// install: nuestro bloque va ANTES de # BEGIN WordPress; remove lo quita idempotente
$tmp = sys_get_temp_dir() . '/chc-ht-' . getmypid() . '.htaccess';
file_put_contents($tmp, "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n");
CHC_Htaccess::install($tmp, $block);
$c = file_get_contents($tmp);
t_assert(strpos($c, '# BEGIN CoreHost Cache') < strpos($c, '# BEGIN WordPress'), 'bloque arriba de WordPress');
t_assert(str_contains($c, '# END WordPress'), 'conserva bloque WordPress');

CHC_Htaccess::install($tmp, $block); // idempotente
t_eq(substr_count(file_get_contents($tmp), '# BEGIN CoreHost Cache'), 1, 'install idempotente (un solo bloque)');

CHC_Htaccess::remove($tmp);
$c2 = file_get_contents($tmp);
t_assert(!str_contains($c2, 'CoreHost Cache'), 'remove quita nuestro bloque');
t_assert(str_contains($c2, '# BEGIN WordPress'), 'remove conserva WordPress');
@unlink($tmp);
