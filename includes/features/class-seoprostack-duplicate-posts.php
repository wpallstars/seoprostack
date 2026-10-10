<?php
/**
 * Duplicate posts, pages and custom post types as drafts.
 *
 * Adds "Duplicate" to list row actions, the admin bar and the editor. The
 * copy is always a new draft; the original is never changed. Anyone who can
 * edit the original and create posts of its type can duplicate it.
 * Replaces "Carbon Copy" (a Duplicate Post fork); its settings are imported once.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Duplicate_Posts extends SEOProStack_Feature {

    const KEY = 'duplicate_posts';

    /** admin-post.php action. */
    const ACTION = 'seoprostack_duplicate';

    /** Meta key recording the original post ID on copies. */
    const ORIGINAL_META = '_seoprostack_original';

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
                'tab'         => 'content',
                'label'       => __('Duplicate posts', 'seoprostack'),
                'description' => __('Copy any post, page or custom post type to a new draft with one click. The original is never changed.', 'seoprostack'),
                'replaces'    => array(
                    'carbon-copy'    => 'Carbon Copy',
                    'duplicate-post' => 'Yoast Duplicate Post',
                ),
            ),
            'duplicate_posts_types' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array('post', 'page'),
                'parent'      => self::KEY,
                'label'       => __('Post types', 'seoprostack'),
                'options'     => array(__CLASS__, 'post_type_options'),
            ),
            'duplicate_posts_copy' => array(
                'type'        => 'multi',
                'default'     => array('excerpt', 'author', 'format', 'template', 'thumbnail', 'taxonomies', 'meta'),
                'parent'      => self::KEY,
                'label'       => __('Also copy', 'seoprostack'),
                'description' => __('The title and content are always copied. Unticked, the author is you and the date is set when you publish.', 'seoprostack'),
                'options'     => array(
                    'excerpt'    => __('Excerpt', 'seoprostack'),
                    'author'     => __('Original author', 'seoprostack'),
                    'thumbnail'  => __('Featured image', 'seoprostack'),
                    'taxonomies' => __('Categories, tags and terms', 'seoprostack'),
                    'meta'       => __('Custom fields and SEO settings', 'seoprostack'),
                    'template'   => __('Page template', 'seoprostack'),
                    'format'     => __('Post format', 'seoprostack'),
                    'menu_order' => __('Menu order', 'seoprostack'),
                    'password'   => __('Password', 'seoprostack'),
                    'date'       => __('Date', 'seoprostack'),
                ),
            ),
            'duplicate_posts_links' => array(
                'type'        => 'multi',
                'default'     => array('row', 'adminbar', 'editor'),
                'parent'      => self::KEY,
                'label'       => __('Show “Duplicate” in', 'seoprostack'),
                'options'     => array(
                    'row'      => __('Post lists', 'seoprostack'),
                    'editor'   => __('The editor', 'seoprostack'),
                    'adminbar' => __('The admin bar', 'seoprostack'),
                ),
            ),
        );
    }

    /**
     * Public post types plus any with an admin UI.
     *
     * @return array<string,string>
     */
    public static function post_type_options() {
        $options = array();
        foreach (get_post_types(array('show_ui' => true), 'objects') as $type) {
            if ('attachment' === $type->name || 'wp_block' === $type->name || 0 === strpos($type->name, 'wp_')) {
                continue;
            }
            $options[$type->name] = $type->labels->name;
        }
        return $options;
    }

    /**
     * Import Carbon Copy settings.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (false === get_option('carbon_copy_version')) {
            return $options;
        }

        $flag = function ($name) {
            return '1' === (string) get_option('carbon_copy_' . $name, '');
        };

        $copy = array();
        $map  = array(
            'copyexcerpt'   => 'excerpt',
            'copyauthor'    => 'author',
            'copythumbnail' => 'thumbnail',
            'copytemplate'  => 'template',
            'copyformat'    => 'format',
            'copymenuorder' => 'menu_order',
            'copypassword'  => 'password',
            'copydate'      => 'date',
        );
        foreach ($map as $theirs => $ours) {
            if ($flag($theirs)) {
                $copy[] = $ours;
            }
        }
        // Carbon Copy copies terms and custom fields unless they are listed as excluded.
        $copy[] = 'taxonomies';
        $copy[] = 'meta';

        $links = array();
        foreach (array('show_row' => 'row', 'show_submitbox' => 'editor', 'show_adminbar' => 'adminbar') as $theirs => $ours) {
            if ($flag($theirs)) {
                $links[] = $ours;
            }
        }

        $types = get_option('carbon_copy_types_enabled');

        $options = self::import_setting($options, self::KEY, true);
        $options = self::import_setting($options, 'duplicate_posts_types', is_array($types) ? $types : null);
        $options = self::import_setting($options, 'duplicate_posts_copy', $copy);
        return self::import_setting($options, 'duplicate_posts_links', $links);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));

        $links = array_flip((array) SEOProStack_Settings::get('duplicate_posts_links'));
        if (isset($links['row'])) {
            add_filter('post_row_actions', array(__CLASS__, 'row_action'), 10, 2);
            add_filter('page_row_actions', array(__CLASS__, 'row_action'), 10, 2);
        }
        if (isset($links['editor'])) {
            add_action('post_submitbox_misc_actions', array(__CLASS__, 'submitbox_link'));
            add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor_link'));
        }
        if (isset($links['adminbar'])) {
            add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_link'), 80);
        }
    }

    /**
     * Whether the current user may duplicate a post.
     *
     * @param WP_Post|int|null $post Post.
     * @return bool
     */
    public static function can_duplicate($post) {
        $post = get_post($post);
        if (!$post || 'auto-draft' === $post->post_status || 'trash' === $post->post_status) {
            return false;
        }
        if (!in_array($post->post_type, (array) SEOProStack_Settings::get('duplicate_posts_types'), true)) {
            return false;
        }
        $type = get_post_type_object($post->post_type);
        return $type && current_user_can('edit_post', $post->ID) && current_user_can($type->cap->create_posts);
    }

    /**
     * Link that duplicates a post.
     *
     * @param int    $post_id Post ID.
     * @param string $then    Where to go afterwards: edit (the copy) or list.
     * @return string
     */
    public static function url($post_id, $then = 'edit') {
        return wp_nonce_url(
            add_query_arg(array('action' => self::ACTION, 'post' => (int) $post_id, 'then' => $then), admin_url('admin-post.php')),
            self::ACTION . '_' . (int) $post_id
        );
    }

    /* --------------------------------------------------------------------- */
    /* Links                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Row action in post lists.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    Post.
     * @return array
     */
    public static function row_action($actions, $post) {
        if (self::can_duplicate($post)) {
            $actions['seoprostack_duplicate'] = sprintf(
                '<a href="%1$s" aria-label="%2$s">%3$s</a>',
                esc_url(self::url($post->ID, 'list')),
                /* translators: %s: post title */
                esc_attr(sprintf(__('Duplicate “%s” as a new draft', 'seoprostack'), get_the_title($post))),
                esc_html__('Duplicate', 'seoprostack')
            );
        }
        return $actions;
    }

    /**
     * Link in the classic editor's Publish box.
     *
     * @param WP_Post $post Post.
     */
    public static function submitbox_link($post) {
        if (!self::can_duplicate($post)) {
            return;
        }
        printf(
            '<div class="misc-pub-section seoprostack-duplicate"><span class="dashicons dashicons-admin-page" aria-hidden="true" style="color:#8c8f94;margin-inline-end:4px"></span><a href="%1$s">%2$s</a></div>',
            esc_url(self::url($post->ID)),
            esc_html__('Duplicate as a new draft', 'seoprostack')
        );
    }

    /**
     * Button in the block editor's Summary panel.
     */
    public static function block_editor_link() {
        $post = get_post();
        if (!$post || !self::can_duplicate($post)) {
            return;
        }
        wp_register_script('seoprostack-duplicate', false, array('wp-plugins', 'wp-element', 'wp-components', 'wp-editor'), SEOPROSTACK_VERSION, true);
        wp_enqueue_script('seoprostack-duplicate');
        wp_add_inline_script('seoprostack-duplicate', sprintf(
            '(function (wp, url, label) {
                var Info = (wp.editor && wp.editor.PluginPostStatusInfo) || (wp.editPost && wp.editPost.PluginPostStatusInfo);
                if (!Info) { return; }
                wp.plugins.registerPlugin("seoprostack-duplicate", {
                    render: function () {
                        return wp.element.createElement(Info, null,
                            wp.element.createElement(wp.components.Button, { variant: "secondary", href: url, __next40pxDefaultSize: true }, label));
                    }
                });
            })(window.wp, %1$s, %2$s);',
            wp_json_encode(self::url($post->ID)),
            wp_json_encode(__('Duplicate as a new draft', 'seoprostack'))
        ));
    }

    /**
     * Admin bar link on single views and edit screens.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function admin_bar_link($bar) {
        $post = null;
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && 'post' === $screen->base) {
                $post = get_post();
            }
        } elseif (is_singular()) {
            $post = get_queried_object();
        }
        if (!$post instanceof WP_Post || !self::can_duplicate($post)) {
            return;
        }
        $bar->add_node(array(
            'id'     => 'seoprostack-duplicate',
            'title'  => esc_html__('Duplicate', 'seoprostack'),
            'href'   => self::url($post->ID),
            'meta'   => array('title' => __('Copy to a new draft', 'seoprostack')),
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Duplicate                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * admin-post.php?action=seoprostack_duplicate
     */
    public static function handle() {
        $post_id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        check_admin_referer(self::ACTION . '_' . $post_id);

        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !self::can_duplicate($post_id)) {
            wp_die(esc_html__('Sorry, you are not allowed to duplicate this item.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }

        $new_id = self::duplicate($post);
        if (is_wp_error($new_id)) {
            wp_die(esc_html($new_id->get_error_message()), '', array('response' => 500, 'back_link' => true));
        }

        $then = isset($_GET['then']) ? sanitize_key(wp_unslash($_GET['then'])) : 'edit';
        $list = add_query_arg('post_type', $post->post_type, admin_url('edit.php'));
        if ('list' === $then) {
            $referer = wp_get_referer();
            $target  = add_query_arg('seoprostack_duplicated', $new_id, $referer ? $referer : $list);
        } else {
            // No edit link (a post type without an editor): back to the list.
            $target = get_edit_post_link($new_id, 'url') ?? add_query_arg('seoprostack_duplicated', $new_id, $list);
        }

        wp_safe_redirect($target);
        exit;
    }

    /**
     * Everything duplicate() can copy.
     *
     * @return string[]
     */
    public static function all_parts() {
        return array('excerpt', 'author', 'thumbnail', 'taxonomies', 'meta', 'template', 'format', 'menu_order', 'password', 'date');
    }

    /**
     * Create a draft copy of a post.
     *
     * @param WP_Post       $post      Original.
     * @param string[]|null $parts     What to copy besides title and content
     *                                 (see all_parts()); null uses the setting.
     * @param array         $overrides Post data that replaces the copied values.
     * @return int|WP_Error New post ID.
     */
    public static function duplicate(WP_Post $post, $parts = null, array $overrides = array()) {
        $copy = array_flip(null === $parts ? (array) SEOProStack_Settings::get('duplicate_posts_copy') : (array) $parts);

        $data = array(
            'post_type'      => $post->post_type,
            'post_status'    => 'draft',
            'post_title'     => $post->post_title,
            'post_content'   => $post->post_content,
            'post_excerpt'   => isset($copy['excerpt']) ? $post->post_excerpt : '',
            'post_author'    => isset($copy['author']) ? (int) $post->post_author : get_current_user_id(),
            'post_parent'    => (int) $post->post_parent,
            'menu_order'     => isset($copy['menu_order']) ? (int) $post->menu_order : 0,
            'post_password'  => isset($copy['password']) ? $post->post_password : '',
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'post_mime_type' => $post->post_mime_type,
        );
        if (isset($copy['date'])) {
            $data['post_date']     = $post->post_date;
            $data['post_date_gmt'] = $post->post_date_gmt;
        }
        $data = array_merge($data, $overrides);

        /**
         * Filter the data for a duplicated post before it is inserted.
         *
         * @param array   $data Post data for wp_insert_post().
         * @param WP_Post $post Original.
         */
        $data = (array) apply_filters('seoprostack_duplicate_post_data', $data, $post);

        $new_id = wp_insert_post(wp_slash($data), true);
        if (is_wp_error($new_id)) {
            return $new_id;
        }

        if (isset($copy['taxonomies'])) {
            self::copy_terms($post, $new_id);
        }
        if (isset($copy['format']) && post_type_supports($post->post_type, 'post-formats')) {
            $format = get_post_format($post);
            if ($format) {
                set_post_format($new_id, $format);
            }
        }
        if (isset($copy['meta'])) {
            self::copy_meta($post->ID, $new_id);
        }
        if (isset($copy['thumbnail']) && has_post_thumbnail($post)) {
            set_post_thumbnail($new_id, (int) get_post_thumbnail_id($post));
        }
        if (isset($copy['template'])) {
            $template = get_post_meta($post->ID, '_wp_page_template', true);
            if ($template) {
                update_post_meta($new_id, '_wp_page_template', $template);
            }
        }

        update_post_meta($new_id, self::ORIGINAL_META, $post->ID);

        /**
         * Fires after a post is duplicated.
         *
         * @param int     $new_id New post ID.
         * @param WP_Post $post   Original.
         */
        do_action('seoprostack_post_duplicated', $new_id, $post);

        return $new_id;
    }

    /**
     * Copy terms in every taxonomy except post formats (the target's terms
     * in each taxonomy are replaced, including with none).
     *
     * @param WP_Post $from Source post.
     * @param int     $to   Target post ID.
     */
    public static function copy_terms(WP_Post $from, $to) {
        foreach (get_object_taxonomies($from->post_type) as $taxonomy) {
            if ('post_format' === $taxonomy) {
                continue;
            }
            $terms = wp_get_object_terms($from->ID, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($terms)) {
                wp_set_object_terms($to, array_map('intval', $terms), $taxonomy);
            }
        }
    }

    /**
     * Meta keys that are never copied: editing state, values handled
     * separately, and our own bookkeeping.
     *
     * @return string[]
     */
    public static function skipped_meta() {
        /**
         * Filter meta keys that are never copied.
         *
         * @param string[] $skip Meta keys.
         */
        return (array) apply_filters('seoprostack_duplicate_skip_meta', array(
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_old_date',
            '_thumbnail_id',
            '_wp_page_template',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
            '_encloseme',
            '_pingme',
            '_wp_desired_post_slug',
            self::ORIGINAL_META,
            '_seoprostack_version_of',
            '_seoprostack_preview',
        ));
    }

    /**
     * Copy custom fields.
     *
     * @param int  $from    Source post ID.
     * @param int  $to      Target post ID.
     * @param bool $replace Replace the target's values for each copied key.
     */
    public static function copy_meta($from, $to, $replace = false) {
        $skip = self::skipped_meta();
        foreach ((array) get_post_meta($from) as $key => $values) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            if ($replace) {
                delete_post_meta($to, $key);
            }
            foreach ((array) $values as $value) {
                add_post_meta($to, $key, wp_slash(maybe_unserialize($value)));
            }
        }
    }

    /**
     * Drop our notice flag from the address bar after it is shown.
     *
     * @param string[] $args Query args.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = 'seoprostack_duplicated';
        return $args;
    }

    /**
     * Confirmation after duplicating from a list.
     */
    public static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $new_id = isset($_GET['seoprostack_duplicated']) ? absint(wp_unslash($_GET['seoprostack_duplicated'])) : 0;
        $link = $new_id ? get_edit_post_link($new_id) : null;
        if (null === $link || !current_user_can('edit_post', $new_id)) {
            return;
        }
        printf(
            '<div class="notice notice-success is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
            esc_html__('Draft copy created.', 'seoprostack'),
            esc_url($link),
            /* translators: %s: post title */
            esc_html(sprintf(__('Edit “%s”', 'seoprostack'), get_the_title($new_id)))
        );
    }
}
