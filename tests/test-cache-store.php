<?php
$base = sys_get_temp_dir() . '/chc-store-' . getmypid();
$s = new CHC_Cache_Store($base);

// Mapeo de ruta
t_eq($s->dir_for('ejemplo.com', '/'), "$base/ejemplo.com", 'home');
t_eq($s->dir_for('ejemplo.com', '/about/'), "$base/ejemplo.com/about", 'trailing slash normalizado');
t_eq($s->dir_for('ejemplo.com', '/a/b/'), "$base/ejemplo.com/a/b", 'anidado');
t_eq($s->dir_for('ejemplo.com', '/x/?q=1'), "$base/ejemplo.com/x", 'ignora query');
t_assert(!str_contains($s->dir_for('e.com', '/../../etc'), '..'), 'sin traversal');
t_assert(!str_contains($s->dir_for('..', '/x'), '..'), 'host .. neutralizado');
t_eq($s->dir_for('ex@mple!.com', '/x/'), "$base/ex_mple_.com/x", 'host con chars raros => _');

// Escribir + variantes + leer de vuelta
$html = '<html>hola ' . str_repeat('x', 500) . '</html>';
$w = $s->write('ejemplo.com', '/about/', $html, true, true);
t_assert(in_array('html', $w, true), 'escribió html');
t_assert(in_array('gz', $w, true), 'escribió gz');
t_eq(file_get_contents("$base/ejemplo.com/about/index.html"), $html, 'html intacto');
t_eq(gzdecode(file_get_contents("$base/ejemplo.com/about/index.html.gz")), $html, 'gz descomprime al original');
if (function_exists('brotli_compress')) {
    t_assert(in_array('br', $w, true), 'escribió br (ext disponible)');
    t_eq(brotli_uncompress(file_get_contents("$base/ejemplo.com/about/index.html.br")), $html, 'br descomprime');
}

// Borrar una URL
$s->delete('ejemplo.com', '/about/');
t_assert(!is_file("$base/ejemplo.com/about/index.html"), 'delete quita html');
t_assert(!is_file("$base/ejemplo.com/about/index.html.gz"), 'delete quita gz');

// delete_all_hosts borra la ruta en TODOS los hosts cacheados
$s->write('a.com', '/foo/', $html);
$s->write('b.com', '/foo/', $html);
t_assert(is_file("$base/a.com/foo/index.html"), 'a.com/foo escrito');
t_assert(is_file("$base/b.com/foo/index.html"), 'b.com/foo escrito');
$s->delete_all_hosts('/foo/');
t_assert(!is_file("$base/a.com/foo/index.html"), 'delete_all_hosts borra a.com');
t_assert(!is_file("$base/b.com/foo/index.html"), 'delete_all_hosts borra b.com');

// Sweep por TTL
$s->write('ejemplo.com', '/viejo/', $html);
$s->write('ejemplo.com', '/nuevo/', $html);
touch("$base/ejemplo.com/viejo/index.html", time() - 7200);
$n = $s->sweep(3600); // borra >1h
t_eq($n, 1, 'sweep borra 1 página vieja');
t_assert(!is_file("$base/ejemplo.com/viejo/index.html"), 'vieja borrada');
t_assert(is_file("$base/ejemplo.com/nuevo/index.html"), 'nueva permanece');

// stats
$s2 = new CHC_Cache_Store($base . '-stats');
$s2->write('ejemplo.com', '/a/', $html);
$s2->write('ejemplo.com', '/b/', $html);
$st = $s2->stats();
t_eq($st['pages'], 2, 'stats cuenta 2 páginas');
t_assert($st['bytes'] > 0, 'stats bytes > 0');
$s2->purge_all();

// purge_all: borra las páginas pero re-crea las guardas anti-listado (M1)
$s->purge_all();
t_assert(count(glob("$base/*", GLOB_ONLYDIR) ?: []) === 0, 'purge_all borra todos los hosts');
t_assert(is_file("$base/index.php"), 'purge_all recrea index.php (guarda anti-listado)');
t_assert(is_file("$base/.htaccess"), 'purge_all recrea .htaccess (Options -Indexes)');
