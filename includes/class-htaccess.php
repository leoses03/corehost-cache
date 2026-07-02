<?php
if (!defined('ABSPATH')) { exit; }

/** Genera y administra el bloque de reglas de servicio en el .htaccess raíz. */
class CHC_Htaccess
{
    public const BEGIN = '# BEGIN CoreHost Cache';
    public const END   = '# END CoreHost Cache';

    /**
     * @param string $cache_url_path Ruta URL desde docroot al dir de cache, sin barra final.
     *   Root install: '/wp-content/cache/corehost-cache'
     *   Subdir /key:  '/key/wp-content/cache/corehost-cache'
     */
    public static function rules(string $cache_url_path): string
    {
        $c   = '/' . trim($cache_url_path, '/');
        $doc = '%{DOCUMENT_ROOT}' . $c . '/%{HTTP_HOST}%{REQUEST_URI}';
        $srv = $c . '/%{HTTP_HOST}%{REQUEST_URI}';

        $skip_cookies = 'chc_nocache|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_';
        $skip_uris    = '(^|/)(wp-admin|wp-login|wp-cron|wp-json|xmlrpc)';

        $common =
              "RewriteCond %{REQUEST_METHOD} GET\n"
            . "RewriteCond %{QUERY_STRING} ^$\n"
            . "RewriteCond %{HTTP_COOKIE} !($skip_cookies) [NC]\n"
            . "RewriteCond %{REQUEST_URI} !$skip_uris [NC]\n";

        $mk = function (string $enc, string $ext, string $envenc) use ($doc, $srv, $common): string {
            $r = $common;
            if ($enc !== '') { $r .= "RewriteCond %{HTTP:Accept-Encoding} $enc\n"; }
            $r .= "RewriteCond {$doc}/index.html{$ext} -f\n";
            $flags = 'E=CHC_HIT:1';
            if ($envenc !== '') { $flags .= ',E=' . $envenc . ':1'; }
            $flags .= ',L,T=text/html';
            return $r . "RewriteRule .* {$srv}/index.html{$ext} [{$flags}]\n";
        };

        return self::BEGIN . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . $mk('br',   '.br', 'CHC_ENC_BR')
            . $mk('gzip', '.gz', 'CHC_ENC_GZ')
            . $mk('',     '',    '')
            . "</IfModule>\n"
            . "<IfModule mod_headers.c>\n"
            // Tras el rewrite interno los env llevan prefijo REDIRECT_.
            . "Header set Content-Encoding \"br\"   env=REDIRECT_CHC_ENC_BR\n"
            . "Header set Content-Encoding \"gzip\" env=REDIRECT_CHC_ENC_GZ\n"
            . "Header set X-CoreHost-Cache \"HIT\"  env=REDIRECT_CHC_HIT\n"
            . "Header set Vary \"Accept-Encoding\"  env=REDIRECT_CHC_HIT\n"
            . "Header set Cache-Control \"public, max-age=600\" env=REDIRECT_CHC_HIT\n"
            . "</IfModule>\n"
            . self::END . "\n";
    }

    /** Instala el bloque justo ANTES de `# BEGIN WordPress` (o al inicio si no existe). */
    public static function install(string $file, string $block): bool
    {
        $current  = is_file($file) ? (string) file_get_contents($file) : '';
        $stripped = self::strip($current);
        if (str_contains($stripped, '# BEGIN WordPress')) {
            $new = preg_replace('/# BEGIN WordPress/', rtrim($block) . "\n\n# BEGIN WordPress", $stripped, 1);
        } else {
            $new = rtrim($block) . "\n\n" . ltrim($stripped);
        }
        return @file_put_contents($file, $new) !== false;
    }

    public static function remove(string $file): bool
    {
        if (!is_file($file)) { return true; }
        $current  = (string) file_get_contents($file);
        $stripped = self::strip($current);
        return $stripped === $current ? true : (@file_put_contents($file, $stripped) !== false);
    }

    private static function strip(string $content): string
    {
        $p = '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . "\n?/s";
        return (string) preg_replace($p, '', $content);
    }
}
