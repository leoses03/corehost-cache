<?php
if (!defined('ABSPATH')) { exit; }

/** Almacén de páginas cacheadas: mapeo URL→ruta y operaciones de archivo. */
class CHC_Cache_Store
{
    public function __construct(private string $base_dir) {}

    public function base(): string { return $this->base_dir; }

    /** Directorio de cache para host+URI (sin el archivo). */
    public function dir_for(string $host, string $uri): string
    {
        $host = preg_replace('/[^a-z0-9.\-]/i', '_', $host);
        $host = str_replace('..', '_', $host);
        $host = $host !== '' ? $host : 'host';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = str_replace(["\0", '..'], '', $path);
        $path = '/' . trim($path, '/');           // normaliza; home => '/'
        $rel  = $path === '/' ? '' : $path;
        return rtrim($this->base_dir, '/') . '/' . $host . $rel;
    }

    /** Escribe index.html + variantes .gz/.br. Devuelve formatos escritos. */
    public function write(string $host, string $uri, string $html, bool $gzip = true, bool $brotli = true): array
    {
        $dir = $this->dir_for($host, $uri);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { return []; }
        $out = [];
        if (file_put_contents($dir . '/index.html', $html, LOCK_EX) !== false) { $out[] = 'html'; }
        if ($gzip && ($gz = gzencode($html, 7)) !== false
            && file_put_contents($dir . '/index.html.gz', $gz, LOCK_EX) !== false) { $out[] = 'gz'; }
        if ($brotli && function_exists('brotli_compress')
            && ($br = brotli_compress($html, 6)) !== false
            && file_put_contents($dir . '/index.html.br', $br, LOCK_EX) !== false) { $out[] = 'br'; }
        return $out;
    }

    /** Borra las variantes de una URL. */
    public function delete(string $host, string $uri): void
    {
        $dir = $this->dir_for($host, $uri);
        foreach (['index.html', 'index.html.gz', 'index.html.br'] as $f) {
            if (is_file("$dir/$f")) { @unlink("$dir/$f"); }
        }
    }

    /** Vacía todo el cache. */
    public function purge_all(): void { $this->rrmdir($this->base_dir); }

    /** Borra páginas (index.html + variantes) con mtime más viejo que $ttl seg. Nº de páginas. */
    public function sweep(int $ttl): int
    {
        if ($ttl <= 0 || !is_dir($this->base_dir)) { return 0; }
        $cutoff = time() - $ttl;
        $count  = 0;
        foreach ($this->iter() as $file) {
            if ($file->getFilename() === 'index.html' && $file->getMTime() < $cutoff) {
                $b = $file->getPathname();
                foreach (['', '.gz', '.br'] as $ext) { if (is_file($b . $ext)) { @unlink($b . $ext); } }
                $count++;
            }
        }
        return $count;
    }

    public function stats(): array
    {
        $pages = 0; $bytes = 0;
        if (is_dir($this->base_dir)) {
            foreach ($this->iter() as $f) {
                if ($f->isFile()) {
                    $bytes += $f->getSize();
                    if ($f->getFilename() === 'index.html') { $pages++; }
                }
            }
        }
        return ['pages' => $pages, 'bytes' => $bytes];
    }

    private function iter(): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->base_dir, FilesystemIterator::SKIP_DOTS)
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    }
}
