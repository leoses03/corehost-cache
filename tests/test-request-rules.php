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
t_assert(CHC_Request_Rules::should_cache(['no_cache_meta'=>true] + $ok) === false, 'meta no-cache => no');

// matches_any (para url_excluded)
t_assert(CHC_Request_Rules::matches_any('/carrito/', ['/carrito', '/checkout']) === true, 'match substring');
t_assert(CHC_Request_Rules::matches_any('/blog/post/', ['/carrito']) === false, 'no match');
t_assert(CHC_Request_Rules::matches_any('/x/', []) === false, 'lista vacía');

// query_only_tracking (params de campaña utm_*/gclid/fbclid… no cambian el HTML de servidor)
$tp = ['gclid','fbclid'];
t_assert(CHC_Request_Rules::query_only_tracking('', $tp) === true, 'query vacía');
t_assert(CHC_Request_Rules::query_only_tracking('utm_source=fb&utm_medium=cpc', $tp) === true, 'solo utm');
t_assert(CHC_Request_Rules::query_only_tracking('fbclid=abc', $tp) === true, 'fbclid');
t_assert(CHC_Request_Rules::query_only_tracking('utm_source=x&fbclid=y', $tp) === true, 'utm+fbclid');
t_assert(CHC_Request_Rules::query_only_tracking('p=1', $tp) === false, 'param real => no');
t_assert(CHC_Request_Rules::query_only_tracking('utm_source=x&p=1', $tp) === false, 'mezcla con real => no');
t_assert(CHC_Request_Rules::should_cache(['query'=>'utm_source=x','query_only_tracking'=>true] + $ok) === true, 'solo-tracking => cacheable');
t_assert(CHC_Request_Rules::should_cache(['query'=>'p=1'] + $ok) === false, 'query real sigue => no');
