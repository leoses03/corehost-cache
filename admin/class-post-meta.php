<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Meta box "No cachear esta página" en el editor de todos los post types
 * públicos. Guarda el post meta `_chc_no_cache`, que
 * CHC_Request_Rules::is_cacheable() lee para excluir la página del cache
 * estático aunque cumpla el resto de las reglas.
 */
class CHC_Post_Meta
{
    private const NONCE_ACTION = 'chc_no_cache_meta';
    private const NONCE_FIELD  = 'chc_no_cache_nonce';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add_box']);
        add_action('save_post', [$this, 'save']);
    }

    public function add_box(): void
    {
        $types = array_values(array_diff(get_post_types(['public' => true]), ['attachment']));
        add_meta_box(
            'chc_no_cache_box',
            __('CoreHost Cache', 'corehost-cache'),
            [$this, 'box'],
            $types,
            'side'
        );
    }

    public function box($post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        $on = (bool) get_post_meta($post->ID, '_chc_no_cache', true);
        echo '<label><input type="checkbox" name="chc_no_cache" value="1" ' . checked($on, true, false) . '> '
           . esc_html__('No cachear esta página en CoreHost Cache', 'corehost-cache') . '</label>';
    }

    public function save($post_id): void
    {
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

        if (!empty($_POST['chc_no_cache'])) {
            update_post_meta($post_id, '_chc_no_cache', 1);
        } else {
            delete_post_meta($post_id, '_chc_no_cache');
        }
    }
}
