<?php
if (!defined('ABSPATH')) { exit; }

/** Invalidación del cache por eventos de contenido + barrido TTL. */
class CHC_Purge
{
    public function register(): void
    {
        foreach (['save_post', 'deleted_post', 'trashed_post'] as $h) { add_action($h, [$this, 'on_post'], 10, 1); }
        foreach (['comment_post', 'edit_comment', 'wp_set_comment_status'] as $h) { add_action($h, [$this, 'on_comment'], 10, 1); }
        foreach (['switch_theme', 'customize_save_after', 'wp_update_nav_menu'] as $h) { add_action($h, [$this, 'all']); }
        add_action('chc_ttl_sweep', [$this, 'ttl_sweep']);
    }

    public function on_post($post_id): void
    {
        if (wp_is_post_revision($post_id)) { return; }
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'auto-draft') { return; }
        $this->url(get_permalink($post_id));
        $this->url(home_url('/'));
    }

    public function on_comment($comment_id): void
    {
        $c = get_comment($comment_id);
        if ($c) { $this->url(get_permalink($c->comment_post_ID)); }
    }

    public function url($permalink): void
    {
        if (!$permalink) { return; }
        $p = wp_parse_url($permalink);
        chc_store()->delete($p['host'] ?? ($_SERVER['HTTP_HOST'] ?? ''), $p['path'] ?? '/');
    }

    public function all(): void { chc_store()->purge_all(); }

    public function ttl_sweep(): void
    {
        chc_store()->sweep((int) (chc_settings()['ttl_hours'] ?? 10) * HOUR_IN_SECONDS);
    }
}
