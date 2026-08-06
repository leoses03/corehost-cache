<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Chequeos de integridad del HTML capturado, para no almacenar páginas rotas.
 * Lógica pura (sin WordPress): el contexto lo aporta CHC_Page_Generator.
 */
class CHC_Html_Integrity
{
    /**
     * Rutas de disco de los <link rel="stylesheet"> cuyo href cae bajo la URL de uploads
     * (los CSS generados por Elementor). Ignora query strings y links que no son stylesheet.
     *
     * @return string[]
     */
    public static function local_upload_styles(string $html, string $uploads_url, string $uploads_dir): array
    {
        $uploads_url = rtrim($uploads_url, '/');
        $uploads_dir = rtrim($uploads_dir, '/');
        $out = [];
        if (!preg_match_all('/<link\b[^>]*>/i', $html, $links)) { return $out; }
        foreach ($links[0] as $tag) {
            if (!preg_match('/\brel\s*=\s*[\'"]stylesheet[\'"]/i', $tag)) { continue; }
            if (!preg_match('/\bhref\s*=\s*[\'"]([^\'"]+)[\'"]/i', $tag, $m)) { continue; }
            $href = html_entity_decode($m[1], ENT_QUOTES);
            if (strpos($href, $uploads_url . '/') !== 0) { continue; }
            $path = parse_url($href, PHP_URL_PATH);
            $url_path = parse_url($uploads_url, PHP_URL_PATH) ?: '';
            if (!is_string($path) || strpos($path, $url_path . '/') !== 0) { continue; }
            $rel = substr($path, strlen($url_path));
            if (strpos($rel, '..') !== false) { continue; }
            $out[] = $uploads_dir . $rel;
        }
        return $out;
    }

    /**
     * Veto si el HTML referencia un CSS de uploads que no existe en disco
     * (ventana en que Elementor borra/reescribe su carpeta de CSS).
     *
     * @param callable|null $file_exists inyectable en tests
     */
    public static function assets_veto(string $html, string $uploads_url, string $uploads_dir, ?callable $file_exists = null): ?string
    {
        $file_exists = $file_exists ?: 'file_exists';
        foreach (self::local_upload_styles($html, $uploads_url, $uploads_dir) as $path) {
            if (!$file_exists($path)) { return 'assets:' . $path; }
        }
        return null;
    }

    /**
     * Veto si la página tiene datos de Elementor pero se renderizó sin él
     * (sin su wrapper data-elementor-id). Una meta presente con valor distinto
     * de "builder" es un cambio deliberado al editor de WP: no se veta.
     */
    public static function elementor_veto(string $html, int $post_id, int $data_len, bool $mode_exists, string $mode): ?string
    {
        if ($post_id <= 0) { return null; }
        $has_wrapper = strpos($html, 'data-elementor-id="' . $post_id . '"') !== false;
        if ($has_wrapper) { return null; }
        if ($mode_exists && $mode === 'builder') { return 'elementor:sin-wrapper'; }
        if (!$mode_exists && $data_len > 100) { return 'elementor:meta-perdida'; }
        return null;
    }
}
