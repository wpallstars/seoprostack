<?php
/**
 * Opt-in keyword defaults and a warning about overused pillar content.
 *
 * Rank Math owns the metadata; no pillar flags are added automatically.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
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
            'rank_math_identity_sync' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'network'     => false,
                'label'       => __('Keep Rank Math’s site identity in sync', 'seoprostack'),
                'description' => __('Use the site name and PNG logo (or site icon) for schema, and the social image below for sharing. Fills empty settings and updates only values previously synced here; keeps your own Rank Math choices.', 'seoprostack'),
            ),
            'rank_math_social_image' => array(
                'type'        => 'media',
                'default'     => 0,
                'parent'      => self::KEY,
                'label'       => __('Default social image', 'seoprostack'),
                'description' => __('Used when a page has no sharing image. Falls back to the site’s PNG logo or icon.', 'seoprostack'),
            ),
            'rank_math_identity_previous' => array(
                'type'    => 'text',
                'default' => '',
                'parent'  => self::KEY,
                'hidden'  => true,
                'network' => false,
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
        // Settings hooks must also catch the request that first enables sync.
        add_action('seoprostack_settings_changed', array(__CLASS__, 'identity_settings'), 10, 2);
        // Rank Math defines its version even when its runtime requirements fail.
        if (!self::enabled() || !did_action('rank_math/loaded')) {
            return;
        }
        add_action('save_post', array(__CLASS__, 'save'), 100, 2);
        // Core REST saves defer this until their metadata has been written.
        add_action('wp_after_insert_post', array(__CLASS__, 'save'), 100, 2);
        // Rank Math's block editor saves its metadata in a separate request.
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'after_rank_math_rest'), 100, 3);
        add_action('current_screen', array(__CLASS__, 'screen'));
        foreach (array('blogname', 'site_icon', 'theme_mods_' . get_option('stylesheet')) as $option) {
            add_action('update_option_' . $option, array(__CLASS__, 'queue_identity'));
            add_action('add_option_' . $option, array(__CLASS__, 'queue_identity'));
            add_action('delete_option_' . $option, array(__CLASS__, 'queue_identity'));
        }
        add_action('after_switch_theme', array(__CLASS__, 'queue_identity'));
    }

    /** @param array $old Previous settings. @param array $new Current settings. */
    public static function identity_settings($old, $new) {
        foreach (array(self::KEY, 'rank_math_identity_sync', 'rank_math_social_image') as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                self::queue_identity();
                break;
            }
        }
    }

    /** Wait until all option saves (and their settings locks) have finished. */
    public static function queue_identity() {
        add_action('shutdown', array(__CLASS__, 'sync_identity'));
    }

    /** Sync only empty, unclaimed fields or fields still equal to our last write. */
    public static function sync_identity() {
        if (!self::enabled() || !SEOProStack_Settings::get('rank_math_identity_sync') || !did_action('rank_math/loaded')) {
            return;
        }
        $titles = get_option('rank-math-options-titles', array());
        if (!is_array($titles)) {
            return;
        }
        $previous = json_decode((string) SEOProStack_Settings::get('rank_math_identity_previous'), true);
        $previous = is_array($previous) ? $previous : array();
        $logo = absint(get_theme_mod('custom_logo', 0));
        if (!$logo || 'image/png' !== get_post_mime_type($logo)) {
            $logo = absint(get_option('site_icon', 0));
        }
        if (!$logo || 'image/png' !== get_post_mime_type($logo)) {
            $logo = 0;
        }
        $social = absint(SEOProStack_Settings::get('rank_math_social_image'));
        $social = $social && wp_attachment_is_image($social) ? $social : $logo;
        $wanted = array(
            'knowledgegraph_name' => wp_strip_all_tags((string) get_option('blogname')),
            'website_name' => wp_strip_all_tags((string) get_option('blogname')),
        );
        // Image URL and attachment ID are a pair: never replace half an override.
        foreach (array('knowledgegraph_logo' => $logo, 'open_graph_image' => $social) as $key => $id) {
            $url = $id ? wp_get_attachment_url($id) : '';
            $can_sync = true;
            foreach (array($key, $key . '_id') as $field) {
                $current = isset($titles[$field]) ? (string) $titles[$field] : '';
                $target = $field === $key ? (string) $url : (string) ($url ? $id : 0);
                $can_sync = $can_sync && (isset($previous[$field]) ? $current === (string) $previous[$field] : in_array($current, array('', '0', $target), true));
            }
            if ($can_sync) {
                $wanted[$key] = $url ? $url : '';
                $wanted[$key . '_id'] = $url ? $id : 0;
            }
        }
        foreach ($wanted as $key => $value) {
            $current = isset($titles[$key]) ? (string) $titles[$key] : '';
            if (isset($previous[$key]) ? $current === (string) $previous[$key] : in_array($current, array('', '0', (string) $value), true)) {
                $titles[$key] = $value;
                $previous[$key] = $value;
            }
        }
        if (update_option('rank-math-options-titles', $titles) && is_callable(array('RankMath\\Helper', 'get_settings'))) {
            // Rank Math caches settings in its request-local container.
            rank_math()->settings->reset();
        }
        SEOProStack_Settings::set('rank_math_identity_previous', (string) wp_json_encode($previous));
    }

    /**
     * Fill a keyword after Rank Math has saved (or cleared) editor metadata.
     *
     * @param WP_REST_Response|WP_Error|mixed $response Callback result.
     * @param array                          $handler  Route handler.
     * @param WP_REST_Request                $request  REST request.
     * @return mixed
     */
    public static function after_rank_math_rest($response, $handler, $request) {
        if ('/rankmath/v1/updateMeta' === $request->get_route()
            && 'POST' === $request->get_method() && !is_wp_error($response)
            && !($response instanceof WP_REST_Response && $response->get_status() >= 400)
            && 'post' === $request->get_param('objectType')) {
            self::save(absint($request->get_param('objectID')));
        }
        return $response;
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
                __('%1$s of %2$s published posts of this type are Rank Math pillar content (more than 20%%). Keep pillar status for a few cornerstone pages.', 'seoprostack'),
                number_format_i18n($pillars->found_posts),
                number_format_i18n($total)
            )),
            esc_html__('Select the posts that should not be pillars, then choose “Remove Rank Math pillar status” in Bulk actions. Nothing is removed automatically.', 'seoprostack')
        );
    }
}
