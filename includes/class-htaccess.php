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
        $c    = '/' . trim($cache_url_path, '/');
        // Se sirve el index.html PLANO y el servidor (LiteSpeed) lo comprime al vuelo.
        // No servimos .gz/.br por rewrite: en LiteSpeed el Content-Encoding vía env de
        // mod_headers no se aplica tras el rewrite interno, y serviría bytes comprimidos
        // sin cabecera (basura). Servir plano es robusto y LiteSpeed comprime igual.
        // $file usa %{REQUEST_URI} (que trae la barra final) para no generar doble barra.
        $file = $c . '/%{HTTP_HOST}%{REQUEST_URI}index.html';

        $skip_cookies = 'chc_nocache|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_';
        $skip_uris    = '(^|/)(wp-admin|wp-login|wp-cron|wp-json|xmlrpc)([/.]|$)';

        return self::BEGIN . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . "RewriteCond %{HTTP_HOST} ^[a-zA-Z0-9.\\-]+$\n"
            . "RewriteCond %{REQUEST_METHOD} GET\n"
            . "RewriteCond %{QUERY_STRING} ^$\n"
            . "RewriteCond %{REQUEST_URI} /$\n"
            . "RewriteCond %{HTTP_COOKIE} !($skip_cookies) [NC]\n"
            . "RewriteCond %{REQUEST_URI} !$skip_uris [NC]\n"
            . "RewriteCond %{DOCUMENT_ROOT}{$file} -f\n"
            . "RewriteRule .* {$file} [E=CHC_HIT:1,L,T=text/html]\n"
            . "</IfModule>\n"
            . "<IfModule mod_headers.c>\n"
            // El env tras un rewrite interno aparece como CHC_HIT (LiteSpeed) o
            // REDIRECT_CHC_HIT (Apache); seteamos en ambas formas.
            . "Header set X-CoreHost-Cache \"HIT\" env=CHC_HIT\n"
            . "Header set X-CoreHost-Cache \"HIT\" env=REDIRECT_CHC_HIT\n"
            . "Header set Cache-Control \"public, max-age=600\" env=CHC_HIT\n"
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
            // str_replace (no preg_replace): $block es texto dinámico y podría contener
            // secuencias tipo $1/\1 que preg_replace interpretaría como referencias.
            $new = str_replace('# BEGIN WordPress', rtrim($block) . "\n\n# BEGIN WordPress", $stripped);
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
        $p = '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . "\n*/s";
        return (string) preg_replace($p, '', $content);
    }
}
