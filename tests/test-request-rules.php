<?php
$ok = ['is_admin'=>false,'logged_in'=>false,'method'=>'GET','query'=>'','status'=>200,
       'content_type'=>'text/html','is_feed'=>false,'donotcache'=>false,'excluded_url'=>false,'woo_dynamic'=>false];

t_assert(CHC_Request_Rules::should_cache($ok) === true, 'anónimo GET html 200 => sí');
t_assert(CHC_Request_Rules::should_cache(['logged_in'=>true] + $ok) === false, 'logueado => no');
t_assert(CHC_Request_Rules::should_cache(['is_admin'=>true] + $ok) === false, 'admin => no');
t_assert(CHC_Request_Rules::should_cache(['method'=>'POST'] + $ok) === false, 'POST => no');
t_assert(CHC_Request_Rules::should_cache(['query'=>'p=1'] + $ok) === false, 'query => no');
t_assert(CHC_Request_Rules::should_cache(['status'=>500] + $ok) === false, '500 => no');
t_assert(CHC_Request_Rules::should_cache(['content_type'=>'application/json'] + $ok) === false, 'json => no');
t_assert(CHC_Request_Rules::should_cache(['is_feed'=>true] + $ok) === false, 'feed => no');
t_assert(CHC_Request_Rules::should_cache(['donotcache'=>true] + $ok) === false, 'DONOTCACHEPAGE => no');
t_assert(CHC_Request_Rules::should_cache(['excluded_url'=>true] + $ok) === false, 'url excluida => no');
t_assert(CHC_Request_Rules::should_cache(['woo_dynamic'=>true] + $ok) === false, 'woo cart/checkout => no');
// cualquier no-200 => no (404 incluido, sin excepciones)
t_assert(CHC_Request_Rules::should_cache(['status'=>404] + $ok) === false, '404 => no');
// contenido protegido / con cookie de bypass => nunca cachear
t_assert(CHC_Request_Rules::should_cache(['password_required'=>true] + $ok) === false, 'password-protected => no');
t_assert(CHC_Request_Rules::should_cache(['bypass_cookie'=>true] + $ok) === false, 'cookie de bypass => no');

// matches_any (para url_excluded)
t_assert(CHC_Request_Rules::matches_any('/carrito/', ['/carrito', '/checkout']) === true, 'match substring');
t_assert(CHC_Request_Rules::matches_any('/blog/post/', ['/carrito']) === false, 'no match');
t_assert(CHC_Request_Rules::matches_any('/x/', []) === false, 'lista vacía');
