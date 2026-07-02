<?php
$r = CHC_Cloudflare::build_request('ZONEID', 'TOK', ['purge_everything' => true]);
t_assert(str_contains($r['url'], '/zones/ZONEID/purge_cache'), 'endpoint con zone');
t_assert(($r['args']['headers']['Authorization'] ?? '') === 'Bearer TOK', 'auth bearer');
t_assert(str_contains($r['args']['body'], 'purge_everything'), 'body correcto');
t_assert(str_contains($r['args']['body'], 'ZONEID') === false, 'zone no va en el body');

// files en el body (purga por URL)
$r2 = CHC_Cloudflare::build_request('Z2', 'T2', ['files' => ['https://example.com/', 'https://example.com/x']]);
t_assert(str_contains($r2['args']['body'], 'https://example.com/x'), 'files viaja en el body');
t_assert(($r2['args']['headers']['Content-Type'] ?? '') === 'application/json', 'content-type json');
t_assert(($r2['args']['timeout'] ?? 0) === 5, 'timeout corto (no-fatal, no cuelga el guardado)');
t_assert(str_contains($r2['args']['body'], 'T2') === false, 'token no va en el body');
