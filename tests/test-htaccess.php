<?php
$block = CHC_Htaccess::rules('/key/wp-content/cache/corehost-cache');

t_assert(str_contains($block, '# BEGIN CoreHost Cache'), 'marcador BEGIN');
t_assert(str_contains($block, '# END CoreHost Cache'), 'marcador END');
t_assert(str_contains($block, 'RewriteCond %{REQUEST_METHOD} GET'), 'solo GET');
t_assert(str_contains($block, 'RewriteCond %{QUERY_STRING} ^$'), 'query vacío');
t_assert(str_contains($block, 'chc_nocache'), 'bypass por cookie de rol');
t_assert(str_contains($block, 'woocommerce_items_in_cart'), 'bypass carrito woo');
t_assert(str_contains($block, 'RewriteRule .* /key/wp-content/cache/corehost-cache/%{HTTP_HOST}%{REQUEST_URI}index.html [E=CHC_HIT:1,L,T=text/html]'), 'sirve index.html plano');
t_assert(str_contains($block, '%{DOCUMENT_ROOT}/key/wp-content/cache/corehost-cache/%{HTTP_HOST}%{REQUEST_URI}index.html -f'), 'test -f con prefijo subdir, sin doble barra');
t_assert(!str_contains($block, '%{REQUEST_URI}/index.html'), 'no debe haber barra entre REQUEST_URI e index.html (evita doble barra)');
t_assert(!str_contains($block, 'index.html.gz') && !str_contains($block, 'index.html.br'), 'no sirve variantes comprimidas por rewrite (LiteSpeed comprime al vuelo)');
t_assert(str_contains($block, 'RewriteCond %{REQUEST_URI} /$'), 'solo sirve URLs con barra final');
t_assert(str_contains($block, 'X-CoreHost-Cache "HIT" env=CHC_HIT'), 'header HIT env=CHC_HIT');
t_assert(str_contains($block, 'X-CoreHost-Cache "HIT" env=REDIRECT_CHC_HIT'), 'header HIT env=REDIRECT_CHC_HIT');
t_assert(str_contains($block, 'RewriteCond %{HTTP_HOST} ^[a-zA-Z0-9'), 'guard de host charset');
t_assert(str_contains($block, 'RewriteCond %{REQUEST_URI} !\\.\\.'), 'rechaza .. en la URI');

// install: nuestro bloque va ANTES de # BEGIN WordPress; remove lo quita idempotente
$tmp = sys_get_temp_dir() . '/chc-ht-' . getmypid() . '.htaccess';
file_put_contents($tmp, "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n");
CHC_Htaccess::install($tmp, $block);
$after1 = file_get_contents($tmp);
$c = $after1;
t_assert(strpos($c, '# BEGIN CoreHost Cache') < strpos($c, '# BEGIN WordPress'), 'bloque arriba de WordPress');
t_assert(str_contains($c, '# END WordPress'), 'conserva bloque WordPress');

CHC_Htaccess::install($tmp, $block); // idempotente
t_eq(substr_count(file_get_contents($tmp), '# BEGIN CoreHost Cache'), 1, 'install idempotente (un solo bloque)');
t_eq(file_get_contents($tmp), $after1, 'install byte-idempotente (2da vez)');
CHC_Htaccess::install($tmp, $block);
t_eq(file_get_contents($tmp), $after1, 'install byte-idempotente (3ra vez)');

CHC_Htaccess::remove($tmp);
$c2 = file_get_contents($tmp);
t_assert(!str_contains($c2, 'CoreHost Cache'), 'remove quita nuestro bloque');
t_assert(str_contains($c2, '# BEGIN WordPress'), 'remove conserva WordPress');
@unlink($tmp);
