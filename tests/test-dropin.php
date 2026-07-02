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
