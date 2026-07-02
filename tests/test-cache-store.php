<?php
$base = sys_get_temp_dir() . '/chc-store-' . getmypid();
$s = new CHC_Cache_Store($base);

// Mapeo de ruta
t_eq($s->dir_for('ejemplo.com', '/'), "$base/ejemplo.com", 'home');
t_eq($s->dir_for('ejemplo.com', '/about/'), "$base/ejemplo.com/about", 'trailing slash normalizado');
t_eq($s->dir_for('ejemplo.com', '/a/b/'), "$base/ejemplo.com/a/b", 'anidado');
t_eq($s->dir_for('ejemplo.com', '/x/?q=1'), "$base/ejemplo.com/x", 'ignora query');
t_assert(!str_contains($s->dir_for('e.com', '/../../etc'), '..'), 'sin traversal');

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

// Sweep por TTL
$s->write('ejemplo.com', '/viejo/', $html);
$s->write('ejemplo.com', '/nuevo/', $html);
touch("$base/ejemplo.com/viejo/index.html", time() - 7200);
$n = $s->sweep(3600); // borra >1h
t_eq($n, 1, 'sweep borra 1 página vieja');
t_assert(!is_file("$base/ejemplo.com/viejo/index.html"), 'vieja borrada');
t_assert(is_file("$base/ejemplo.com/nuevo/index.html"), 'nueva permanece');

// purge_all
$s->purge_all();
t_assert(!is_dir($base) || count(glob("$base/*")) === 0, 'purge_all vacía');
