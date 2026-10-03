<?php
/**
 * Opt-in keyword defaults and a warning about overused pillar content.
 *
 * Rank Math owns the metadata; no pillar flags are added automatically.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Rank_Math_Defaults extends SEOProStack_Feature {

    const KEY = 'rank_math_defaults';
    const BULK_ACTION = 'seoprostack_remove_pillar';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'links',
                'label'       => __('Rank Math defaults', 'seoprostack'),
                'description' => __('Fill empty focus keywords on save and warn when more than 20% of published posts are pillar content. Only works while Rank Math is active. Pillar content is never set automatically.', 'seoprostack'),
            ),
            'rank_math_defaults_types' => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array('post', 'page'),
                'parent'  => self::KEY,
                'label'   => __('Post types', 'seoprostack'),
                'options' => array(__CLASS__, 'post_type_options'),
            ),
            'rank_math_defaults_keyword' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Set an empty focus keyword from the title', 'seoprostack'),
                'description' => __('Lowercase the title and strip tags. Existing keywords are kept, even when the title changes.', 'seoprostack'),
            ),
        );
    }

    /**
     * Public post types, without media.
     *
     * @return array<string,string>
     */
    public static function post_type_options() {
        $options = array();
        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if ('attachment' !== $type->name) {
                $options[$type->name] = $type->labels->name;
            }
        }
        return $options;
    }

    /** Register hooks only when Rank Math is loaded on this request. */
    public static function boot() {
        if (!self::enabled() || !defined('RANK_MATH_VERSION')) {
            return;
        }
        add_action('save_post', array(__CLASS__, 'save'), 100, 2);
        // These run after editor metadata is saved, including the REST editor.
        add_action('rank_math/save_post', array(__CLASS__, 'save'), 100);
        add_action('wp_after_insert_post', array(__CLASS__, 'save'), 100, 2);
        add_action('current_screen', array(__CLASS__, 'screen'));
    }

    /**
     * Whether this public post type is selected.
     *
     * @param string $type Post type.
     * @return bool
     */
    private static function selected_type($type) {
        return isset(self::post_type_options()[$type])
            && in_array($type, (array) SEOProStack_Settings::get('rank_math_defaults_types'), true);
    }

    /**
     * Fill an empty keyword, without touching pillar status or chosen keywords.
     *
     * @param int          $post_id Post ID.
     * @param WP_Post|null $post    Post, if provided by the hook.
     */
    public static function save($post_id, $post = null) {
        if (!SEOProStack_Settings::get('rank_math_defaults_keyword')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $post = $post instanceof WP_Post ? $post : get_post($post_id);
        if (!$post || !self::selected_type($post->post_type)
            || in_array($post->post_status, array('auto-draft', 'trash'), true)
            || '' !== (string) get_post_meta($post_id, 'rank_math_focus_keyword', true)) {
            return;
        }
        $keyword = trim(wp_strip_all_tags($post->post_title));
        $keyword = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
        if ('' !== $keyword) {
            update_post_meta($post_id, 'rank_math_focus_keyword', wp_slash($keyword));
        }
    }

    /**
     * Add controls only on an enabled post type's list screen.
     *
     * @param WP_Screen $screen Current screen.
     */
    public static function screen($screen) {
        $type = get_post_type_object($screen->post_type);
        if ('edit' !== $screen->base || !$type || !self::selected_type($type->name)
            || !current_user_can($type->cap->edit_posts)) {
            return;
        }
        add_filter('bulk_actions-edit-' . $type->name, array(__CLASS__, 'bulk_actions'));
        add_filter('handle_bulk_actions-edit-' . $type->name, array(__CLASS__, 'handle_bulk'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    /**
     * Offer an explicit action, never automatic cleanup.
     *
     * @param array $actions Bulk actions.
     * @return array
     */
    public static function bulk_actions($actions) {
        $actions[self::BULK_ACTION] = __('Remove Rank Math pillar status', 'seoprostack');
        return $actions;
    }

    /**
     * Remove only selected pillar flags the current user may edit.
     *
     * @param string $redirect Redirect URL.
     * @param string $action   Bulk action.
     * @param array  $ids      Selected IDs.
     * @return string
     */
    public static function handle_bulk($redirect, $action, $ids) {
        if (self::BULK_ACTION !== $action) {
            return $redirect;
        }
        check_admin_referer('bulk-posts');
        $screen = get_current_screen();
        if (!$screen || !self::selected_type($screen->post_type)) {
            return $redirect;
        }
        foreach ($ids as $id) {
            $id = absint($id);
            if (get_post_type($id) === $screen->post_type && current_user_can('edit_post', $id)) {
                delete_post_meta($id, 'rank_math_pillar_content', 'on');
            }
        }
        return $redirect;
    }

    /** Warn only on the list screen; no site-wide scan or stored counters. */
    public static function notice() {
        $screen = get_current_screen();
        if (!$screen || !self::selected_type($screen->post_type)) {
            return;
        }
        $counts = wp_count_posts($screen->post_type);
        $total = (int) $counts->publish;
        if (!$total) {
            return;
        }
        $pillars = new WP_Query(array(
            'post_type'      => $screen->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'rank_math_pillar_content', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rank Math stores this flag in meta; counted only on the selected type's admin list.
            'meta_value'     => 'on', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- must count only explicit pillar flags, not every row with the key.
        ));
        if ($pillars->found_posts * 100 <= $total * 20) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s %s</p></div>',
            esc_html(sprintf(
                /* translators: 1: pillar count, 2: published post count. */
                __('%1$s of %2$s published posts of this type are Rank Math pillar content (more than 20%). Keep pillar status for a few cornerstone pages.', 'seoprostack'),
                number_format_i18n($pillars->found_posts),
                number_format_i18n($total)
            )),
            esc_html__('Select the posts that should not be pillars, then choose “Remove Rank Math pillar status” in Bulk actions. Nothing is removed automatically.', 'seoprostack')
        );
    }
}
