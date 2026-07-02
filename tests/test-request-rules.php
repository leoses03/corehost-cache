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
// 404 solo si cache_404
t_assert(CHC_Request_Rules::should_cache(['status'=>404] + $ok) === false, '404 sin flag => no');
t_assert(CHC_Request_Rules::should_cache(['status'=>404,'cache_404'=>1] + $ok) === true, '404 con flag => sí');

// matches_any (para url_excluded)
t_assert(CHC_Request_Rules::matches_any('/carrito/', ['/carrito', '/checkout']) === true, 'match substring');
t_assert(CHC_Request_Rules::matches_any('/blog/post/', ['/carrito']) === false, 'no match');
t_assert(CHC_Request_Rules::matches_any('/x/', []) === false, 'lista vacía');
