<?php
if (!defined('ABSPATH')) { exit; }

/** WP-CLI: wp corehost-cache purge|status|warm */
class CHC_CLI
{
    /** Purga todo el cache. */
    public function purge($args, $assoc): void
    {
        chc_purge_all();
        WP_CLI::success('Cache purgada.');
    }

    /** Muestra estado. */
    public function status($args, $assoc): void
    {
        $s = chc_store()->stats();
        WP_CLI::log('Páginas: ' . $s['pages'] . ' · Disco: ' . size_format($s['bytes'], 1));
        WP_CLI::log('.htaccess escribible: ' . (get_option('chc_htaccess_writable', 1) ? 'sí' : 'NO'));
        $v = get_option('chc_last_veto');
        if (is_array($v) && !empty($v['ts'])) {
            WP_CLI::log('Último veto de guardado: ' . gmdate('Y-m-d H:i:s', (int) $v['ts']) . ' UTC · ' . ($v['uri'] ?? '?') . ' · ' . ($v['reason'] ?? '?'));
        }
    }

    /** Precalienta el cache visitando las URLs del sitemap. */
    public function warm($args, $assoc): void
    {
        $sitemap = home_url('/wp-sitemap.xml');
        $body = wp_remote_retrieve_body(wp_remote_get($sitemap, ['timeout' => 20]));
        if (!$body) { WP_CLI::error('No se pudo leer el sitemap: ' . $sitemap); }
        preg_match_all('#<loc>([^<]+)</loc>#', $body, $m);
        $urls = array_slice(array_unique($m[1] ?? []), 0, 500);
        $n = 0;
        foreach ($urls as $u) {
            if (str_contains($u, 'sitemap')) { continue; }
            wp_remote_get($u, ['timeout' => 20]);
            $n++;
        }
        WP_CLI::success("Precalentadas $n URLs.");
    }
}
