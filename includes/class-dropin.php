<?php
if (!defined('ABSPATH')) { exit; }

/** Instala/quita el drop-in `advanced-cache.php` (nginx) + `define('WP_CACHE', true)` en wp-config.php. */
class CHC_Dropin
{
    public const MARKER = 'CoreHost Cache drop-in';

    public function dropin_path(): string
    {
        return WP_CONTENT_DIR . '/advanced-cache.php';
    }

    /** ¿El advanced-cache.php presente (si lo hay) es el nuestro? */
    public function is_ours(): bool
    {
        $file = $this->dropin_path();
        return is_file($file) && strpos((string) file_get_contents($file), self::MARKER) !== false;
    }

    /** ¿Hay un advanced-cache.php de OTRO origen (sin nuestro marcador)? No lo tocamos. */
    public function foreign_exists(): bool
    {
        $file = $this->dropin_path();
        return is_file($file) && strpos((string) file_get_contents($file), self::MARKER) === false;
    }

    /** Copia nuestro template a wp-content/advanced-cache.php y activa WP_CACHE. */
    public function install(): bool
    {
        if ($this->foreign_exists()) { return false; }
        $src = CHC_DIR . 'dropin/advanced-cache.php';
        if (!is_file($src)) { return false; }
        if (@copy($src, $this->dropin_path()) === false) { return false; }
        return $this->set_wp_cache(true);
    }

    /** Quita nuestro drop-in (solo si es el nuestro) y desactiva WP_CACHE. */
    public function remove(): void
    {
        if ($this->is_ours()) { @unlink($this->dropin_path()); }
        $this->set_wp_cache(false);
    }

    /** Activa/desactiva WP_CACHE en el wp-config.php REAL del sitio. */
    public function set_wp_cache(bool $on): bool
    {
        return self::set_wp_cache_in(ABSPATH . 'wp-config.php', $on);
    }

    /**
     * Edita el wp-config indicado: quita cualquier `define('WP_CACHE', ...)` previo y, si
     * $on, inserta `define('WP_CACHE', true); // CoreHost Cache` justo tras el `<?php` inicial.
     * Backup a "<file>.chc-bak". Devuelve false si el archivo no existe o no es escribible.
     * Testeable: recibe la RUTA por parámetro (en tests, usar siempre un archivo temporal).
     */
    public static function set_wp_cache_in(string $file, bool $on): bool
    {
        if (!is_file($file) || !is_writable($file)) { return false; }
        $content = (string) file_get_contents($file);
        @copy($file, $file . '.chc-bak');

        // Quita cualquier define('WP_CACHE', ...) previo (nuestro o de otro origen).
        $stripped = (string) preg_replace(
            '/^[ \t]*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*[^)]*\)\s*;[^\n]*\n?/mi',
            '',
            $content
        );

        $new = $stripped;
        if ($on) {
            $insert = "define('WP_CACHE', true); // CoreHost Cache\n";
            if (preg_match('/^<\?php[^\n]*\n/', $stripped, $m)) {
                $new = preg_replace('/^<\?php[^\n]*\n/', $m[0] . $insert, $stripped, 1);
            } else {
                $new = "<?php\n" . $insert . $stripped;
            }
        }

        return @file_put_contents($file, $new) !== false;
    }
}
