<?php
$base = sys_get_temp_dir().'/chc-dropin-'.getmypid();
@mkdir($base.'/ej.com/about', 0777, true);
file_put_contents($base.'/ej.com/about/index.html', '<html>hola</html>');
$S = ['REQUEST_METHOD'=>'GET','QUERY_STRING'=>'','REQUEST_URI'=>'/about/','HTTP_HOST'=>'ej.com'];
t_eq(chc_dropin_cache_file($S, [], $base), $base.'/ej.com/about/index.html', 'sirve archivo existente');
t_assert(chc_dropin_cache_file(['QUERY_STRING'=>'x=1']+$S, [], $base) === null, 'con query => null');
t_assert(chc_dropin_cache_file(['REQUEST_METHOD'=>'POST']+$S, [], $base) === null, 'POST => null');
t_assert(chc_dropin_cache_file(['REQUEST_URI'=>'/about']+$S, [], $base) === null, 'sin barra final => null');
t_assert(chc_dropin_cache_file($S, ['chc_nocache'=>'1'], $base) === null, 'cookie bypass => null');
t_assert(chc_dropin_cache_file(['REQUEST_URI'=>'/wp-admin/']+$S, [], $base) === null, 'backend => null');
t_assert(chc_dropin_cache_file(['REQUEST_URI'=>'/nada/']+$S, [], $base) === null, 'archivo inexistente => null');

// set_wp_cache_in: edita SIEMPRE un wp-config TEMPORAL (nunca el real) para poder testearlo.
$wpc = sys_get_temp_dir().'/chc-wpconfig-'.getmypid().'.php';
file_put_contents($wpc, "<?php\n// stuff\n");
t_assert(CHC_Dropin::set_wp_cache_in($wpc, true), 'set_wp_cache_in(true) devuelve true');
t_assert(str_contains(file_get_contents($wpc), "define('WP_CACHE', true)"), 'agrega define WP_CACHE');
t_assert(CHC_Dropin::set_wp_cache_in($wpc, false), 'set_wp_cache_in(false) devuelve true');
t_assert(!str_contains(file_get_contents($wpc), 'WP_CACHE'), 'quita define WP_CACHE');
@unlink($wpc);
@unlink($wpc.'.chc-bak');
