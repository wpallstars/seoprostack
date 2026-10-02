<?php
/**
 * Change post type.
 *
 * A "Post type" choice in the block editor's summary panel, the classic
 * Publish box, Quick Edit and Bulk Edit, for turning a post into a page or a
 * custom post type and back. Offered between public post types with an admin
 * screen that the person can publish. Uses core set_post_type() (block
 * editor) or the post's own save (classic editor, Quick Edit, Bulk Edit), so
 * the post keeps its ID, content, fields and address slug.
 *
 * Replaces Post Type Switcher, which has no settings.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Post_Type_Switch extends SEOProStack_Feature {

    const KEY = 'post_type_switch';

    /** AJAX action and nonce action. */
    const AJAX = 'seoprostack_post_type_switch';

    /** Form field. */
    const FIELD = 'seoprostack_post_type';

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
                'label'       => __('Change post type', 'seoprostack'),
                'description' => __('Turn a post into a page, a product or another post type, and back. Choose the type in the editor’s summary panel, Quick Edit or Bulk Edit.', 'seoprostack'),
                'replaces'    => array('post-type-switcher' => 'Post Type Switcher'),
            ),
        );
    }

    /**
     * Switch on while Post Type Switcher is active (it has no settings).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['post-type-switcher']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        add_action('post_submitbox_misc_actions', array(__CLASS__, 'submitbox'));
        add_filter('wp_insert_post_data', array(__CLASS__, 'insert_post_data'), 10, 2);
        add_action('wp_insert_post', array(__CLASS__, 'after_save'), 10, 2);
        add_action('admin_footer-edit.php', array(__CLASS__, 'list_script'));
    }

    /**
     * Post types the current user can switch to.
     *
     * @return array<string,string> name => singular label
     */
    public static function types() {
        $types = array();
        foreach (get_post_types(array('public' => true, 'show_ui' => true), 'objects') as $type) {
            if ('attachment' === $type->name) {
                continue;
            }
            if (current_user_can($type->cap->edit_posts) && current_user_can($type->cap->publish_posts)) {
                $types[$type->name] = $type->labels->singular_name;
            }
        }
        /**
         * Post types offered by Change post type.
         *
         * @param array<string,string> $types name => label.
         */
        return (array) apply_filters('seoprostack_switchable_post_types', $types);
    }

    /**
     * Whether a post can be moved from its type to another one.
     *
     * @param WP_Post $post Post.
     * @param string  $to   New post type.
     * @return bool
     */
    public static function can_switch($post, $to) {
        $types = self::types();
        return $post instanceof WP_Post
            && isset($types[$post->post_type], $types[$to])
            && $post->post_type !== $to
            && current_user_can('edit_post', $post->ID);
    }

    /**
     * Block editor control.
     */
    public static function block_editor() {
        $post  = get_post();
        $types = self::types();
        if (!$post || !isset($types[$post->post_type]) || count($types) < 2 || !current_user_can('edit_post', $post->ID)) {
            return;
        }
        $options = array();
        foreach ($types as $name => $label) {
            $options[] = array('value' => $name, 'label' => $label);
        }
        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'nonce'   => wp_create_nonce(self::AJAX),
            'post'    => $post->ID,
            'type'    => $post->post_type,
            'options' => $options,
            'label'   => __('Post type', 'seoprostack'),
            /* translators: %s: post type name, such as Page */
            'confirm' => __('Change this to a %s? Unsaved changes are saved first, then the editor reloads.', 'seoprostack'),
            'failed'  => __('Could not change the post type. Please try again.', 'seoprostack'),
        );
        // wp-edit-post: before WordPress 6.6, PluginPostStatusInfo is only
        // there. Only the post editor gets this far, and it loads it anyway.
        wp_register_script('seoprostack-post-type-switch', SEOPROSTACK_URL . 'admin/js/seoprostack-post-type-switch.js', array('wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-editor', 'wp-edit-post'), SEOPROSTACK_VERSION, true);
        wp_add_inline_script('seoprostack-post-type-switch', 'window.seoprostackPostTypeSwitch = ' . wp_json_encode($cfg) . ';', 'before');
        wp_enqueue_script('seoprostack-post-type-switch');
    }

    /**
     * Select options for a form.
     *
     * @param string $current Selected type, or '' for "No change".
     * @return string HTML.
     */
    private static function options_html($current) {
        $html = '' === $current ? '<option value="">' . esc_html__('— No change —', 'seoprostack') . '</option>' : '';
        foreach (self::types() as $name => $label) {
            $html .= '<option value="' . esc_attr($name) . '"' . selected($current, $name, false) . '>' . esc_html($label) . '</option>';
        }
        return $html;
    }

    /**
     * Classic editor: Publish box.
     *
     * @param WP_Post $post Post.
     */
    public static function submitbox($post) {
        $types = self::types();
        if (!$post instanceof WP_Post || !isset($types[$post->post_type]) || count($types) < 2 || !current_user_can('edit_post', $post->ID)) {
            return;
        }
        printf(
            '<div class="misc-pub-section seoprostack-post-type"><label for="seoprostack-post-type">%1$s</label> <select id="seoprostack-post-type" name="%2$s">%3$s</select><input type="hidden" name="%4$s" value="%5$s" /></div>',
            esc_html__('Post type:', 'seoprostack'),
            esc_attr(self::FIELD),
            self::options_html($post->post_type), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in options_html().
            esc_attr(self::FIELD . '_nonce'),
            esc_attr(wp_create_nonce(self::AJAX))
        );
    }

    /**
     * Quick Edit and Bulk Edit fields, added to core's forms.
     */
    public static function list_script() {
        $screen = get_current_screen();
        $types  = self::types();
        if (!$screen || !isset($types[$screen->post_type]) || count($types) < 2) {
            return;
        }
        $field = '<label class="inline-edit-seoprostack-type alignleft"><span class="title">' . esc_html__('Post type', 'seoprostack') . '</span>'
            . '<select name="' . esc_attr(self::FIELD) . '">%s</select></label>'
            . '<input type="hidden" name="' . esc_attr(self::FIELD . '_nonce') . '" value="' . esc_attr(wp_create_nonce(self::AJAX)) . '" />';
        ?>
        <script>
        (function () {
            var quick = <?php echo wp_json_encode(sprintf($field, self::options_html($screen->post_type))); ?>;
            var bulk = <?php echo wp_json_encode(sprintf($field, self::options_html(''))); ?>;
            function add(rowId, html) {
                var row = document.getElementById(rowId);
                var col = row && row.querySelector('.inline-edit-col-right .inline-edit-col');
                if (!col) { col = row && row.querySelector('.inline-edit-col-right'); }
                if (col && !col.querySelector('.inline-edit-seoprostack-type')) {
                    var wrap = document.createElement('div');
                    wrap.className = 'inline-edit-group wp-clearfix';
                    wrap.innerHTML = html;
                    col.appendChild(wrap);
                }
            }
            add('inline-edit', quick);
            add('bulk-edit', bulk);
        })();
        </script>
        <?php
    }

    /**
     * Classic editor, Quick Edit and Bulk Edit: change the type as the post
     * is saved.
     *
     * @param array $data    Post data to save.
     * @param array $postarr Submitted data.
     * @return array
     */
    public static function insert_post_data($data, $postarr) {
        // Bulk Edit sends the list's form with GET, the others with POST.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
        if (empty($_REQUEST[self::FIELD]) || empty($postarr['ID']) || empty($_REQUEST[self::FIELD . '_nonce'])) {
            return $data;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST[self::FIELD . '_nonce'])), self::AJAX)) {
            return $data;
        }
        $to = sanitize_key(wp_unslash($_REQUEST[self::FIELD]));
        // phpcs:enable
        if (wp_is_post_revision((int) $postarr['ID']) || wp_is_post_autosave((int) $postarr['ID']) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return $data;
        }
        $post = get_post((int) $postarr['ID']);
        if (!$post || $post->post_type !== $data['post_type'] || !self::can_switch($post, $to)) {
            return $data;
        }
        self::$switched[$post->ID] = $post->post_type;
        $data['post_type']         = $to;
        return $data;
    }

    /**
     * Posts whose type changes in this request: ID => old type.
     *
     * @var array<int,string>
     */
    private static $switched = array();

    /**
     * After a save that changed the type, tell listeners.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function after_save($post_id, $post) {
        if (!isset(self::$switched[$post_id])) {
            return;
        }
        $from = self::$switched[$post_id];
        unset(self::$switched[$post_id]);
        /** This action is documented in ajax(). */
        do_action('seoprostack_post_type_switched', (int) $post_id, $post->post_type, $from);
    }

    /**
     * Block editor: change the type of a saved post.
     */
    public static function ajax() {
        check_ajax_referer(self::AJAX, 'nonce');
        $post_id = isset($_POST['post']) ? absint(wp_unslash($_POST['post'])) : 0;
        $to      = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        $post    = get_post($post_id);
        if (!$post || !self::can_switch($post, $to)) {
            wp_send_json_error(array('message' => __('You cannot change this item to that type.', 'seoprostack')), 403);
        }
        $from = $post->post_type;
        set_post_type($post_id, $to);
        clean_post_cache($post_id);
        /**
         * A post's type was changed.
         *
         * @param int    $post_id Post ID.
         * @param string $to      New post type.
         * @param string $from    Old post type.
         */
        do_action('seoprostack_post_type_switched', $post_id, $to, $from);
        wp_send_json_success(array('url' => get_edit_post_link($post_id, 'raw')));
    }
}
