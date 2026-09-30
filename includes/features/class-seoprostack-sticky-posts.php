<?php
/**
 * Sticky posts for any post type.
 *
 * WordPress only lets blog posts be sticky, and only lifts them to the top of
 * the blog home. This adds a star to post lists and a "Stick to the top"
 * option in the editor for the chosen post types, and lifts sticky items to
 * the top of the first page of the blog home, post type archives and
 * category/term archives you choose. Uses the core "sticky_posts" list, so
 * existing sticky posts, themes and blocks keep working. Replaces "Sticky
 * Posts Switch"; its settings are imported once.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Sticky_Posts extends SEOProStack_Feature {

    const KEY = 'sticky_posts';

    /** AJAX action. */
    const AJAX = 'seoprostack_sticky';

    /**
     * IDs lifted to the top of the main query.
     *
     * @var int[]
     */
    private static $lifted = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        $types = array('SEOProStack_Duplicate_Posts', 'post_type_options');

        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'workflow',
                'label'       => __('Sticky posts for any post type', 'seoprostack'),
                'description' => __('Pin pages, products and custom post types to the top of their lists, like sticky blog posts. Adds a star to post lists and a “Stick to the top” option in the editor.', 'seoprostack'),
                'replaces'    => array('sticky-posts-switch' => 'Sticky Posts Switch'),
            ),
            'sticky_posts_types' => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array('post'),
                'parent'  => self::KEY,
                'label'   => __('Post types that can be sticky', 'seoprostack'),
                'options' => $types,
            ),
            'sticky_posts_home' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array('post'),
                'parent'      => self::KEY,
                'label'       => __('Lift to the top of the blog home', 'seoprostack'),
                'description' => __('Sticky items of these types lead the first page of your latest posts.', 'seoprostack'),
                'options'     => $types,
            ),
            'sticky_posts_archives' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Lift to the top of their archives', 'seoprostack'),
                'description' => __('The post type’s own archive page, such as /products/.', 'seoprostack'),
                'options'     => $types,
            ),
            'sticky_posts_taxonomies' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Lift to the top of these term archives', 'seoprostack'),
                'description' => __('For example, each category page shows its sticky posts first.', 'seoprostack'),
                'options'     => array(__CLASS__, 'taxonomy_options'),
            ),
        );
    }

    /**
     * Public taxonomies.
     *
     * @return array<string,string>
     */
    public static function taxonomy_options() {
        $options = array();
        foreach (get_taxonomies(array('public' => true, 'show_ui' => true), 'objects') as $taxonomy) {
            if ('post_format' !== $taxonomy->name) {
                $options[$taxonomy->name] = $taxonomy->labels->name;
            }
        }
        return $options;
    }

    /**
     * Import Sticky Posts Switch settings.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $theirs = get_option('sticky_posts_switch_options');
        if (!is_array($theirs) || !$theirs) {
            return $options;
        }
        $list = function ($key) use ($theirs) {
            return isset($theirs[$key]) && is_array($theirs[$key]) ? array_values($theirs[$key]) : array();
        };

        $options = self::import_setting($options, self::KEY, true);
        $options = self::import_setting($options, 'sticky_posts_types', $list('post_types'));
        $options = self::import_setting($options, 'sticky_posts_home', $list('show_on_front_page'));
        $options = self::import_setting($options, 'sticky_posts_archives', $list('show_on_archive'));
        return self::import_setting($options, 'sticky_posts_taxonomies', $list('show_on_taxonomy'));
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
        add_action('admin_init', array(__CLASS__, 'admin_columns'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        add_action('post_submitbox_misc_actions', array(__CLASS__, 'submitbox'));
        add_action('save_post', array(__CLASS__, 'save_classic'), 10, 2);
        add_action('before_delete_post', array(__CLASS__, 'unstick'));

        if (!is_admin()) {
            add_action('pre_get_posts', array(__CLASS__, 'pre_get_posts'));
            add_filter('the_posts', array(__CLASS__, 'lift'), 10, 2);
            add_filter('post_class', array(__CLASS__, 'post_class'), 10, 3);
        }
    }

    /**
     * Whether a post type can be sticky here.
     *
     * @param string $type Post type.
     * @return bool
     */
    public static function type_enabled($type) {
        return in_array($type, (array) SEOProStack_Settings::get('sticky_posts_types'), true);
    }

    /**
     * Stick or unstick a post (core's stick_post() works for any post ID).
     *
     * @param int  $post_id Post ID.
     * @param bool $sticky  Sticky.
     */
    public static function set_sticky($post_id, $sticky) {
        if ($sticky) {
            stick_post($post_id);
        } else {
            unstick_post($post_id);
        }
    }

    /**
     * Keep the list clean when posts are deleted.
     *
     * @param int $post_id Post ID.
     */
    public static function unstick($post_id) {
        if (is_sticky($post_id)) {
            unstick_post($post_id);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Admin                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Star column in the lists of enabled types.
     */
    public static function admin_columns() {
        foreach ((array) SEOProStack_Settings::get('sticky_posts_types') as $type) {
            add_filter("manage_{$type}_posts_columns", array(__CLASS__, 'add_column'));
            add_action("manage_{$type}_posts_custom_column", array(__CLASS__, 'render_column'), 10, 2);
        }
        add_action('admin_print_footer_scripts-edit.php', array(__CLASS__, 'list_script'));
    }

    /**
     * Add the column after the checkbox.
     *
     * @param array $columns Columns.
     * @return array
     */
    public static function add_column($columns) {
        $label = '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Sticky', 'seoprostack') . '</span>';
        $new   = array();
        foreach ($columns as $key => $value) {
            $new[$key] = $value;
            if ('cb' === $key) {
                $new['seoprostack_sticky'] = $label;
            }
        }
        if (!isset($new['seoprostack_sticky'])) {
            $new = array('seoprostack_sticky' => $label) + $new;
        }
        return $new;
    }

    /**
     * Star toggle.
     *
     * @param string $column  Column.
     * @param int    $post_id Post ID.
     */
    public static function render_column($column, $post_id) {
        if ('seoprostack_sticky' !== $column) {
            return;
        }
        $sticky = is_sticky($post_id);
        $title  = get_the_title($post_id);
        if (!current_user_can('edit_post', $post_id) || 'publish' !== get_post_status($post_id)) {
            if ($sticky) {
                echo '<span class="dashicons dashicons-star-filled" title="' . esc_attr__('Sticky', 'seoprostack') . '"></span>';
            }
            return;
        }
        printf(
            '<button type="button" class="button-link seoprostack-sticky" data-post="%1$d" data-nonce="%2$s" aria-pressed="%3$s" aria-label="%4$s"><span class="dashicons %5$s" aria-hidden="true"></span></button>',
            (int) $post_id,
            esc_attr(wp_create_nonce(self::AJAX . '_' . $post_id)),
            $sticky ? 'true' : 'false',
            /* translators: %s: post title */
            esc_attr(sprintf(__('Stick “%s” to the top', 'seoprostack'), $title)),
            $sticky ? 'dashicons-star-filled' : 'dashicons-star-empty'
        );
    }

    /**
     * List screen styles and toggle script.
     */
    public static function list_script() {
        $screen = get_current_screen();
        if (!$screen || !self::type_enabled($screen->post_type)) {
            return;
        }
        ?>
        <style>
            .fixed .column-seoprostack_sticky { width: 2.2em; text-align: center; }
            .column-seoprostack_sticky .dashicons { color: #8c8f94; }
            td.column-seoprostack_sticky .dashicons-star-filled { color: #dba617; }
            .seoprostack-sticky { cursor: pointer; }
            .seoprostack-sticky:focus { box-shadow: 0 0 0 2px #2271b1; border-radius: 2px; outline: none; }
            .seoprostack-sticky[aria-busy="true"] { opacity: .5; }
        </style>
        <script>
        (function ($) {
            $(document).on('click', '.seoprostack-sticky', function () {
                var $btn = $(this), on = $btn.attr('aria-pressed') !== 'true';
                $btn.attr('aria-busy', 'true');
                $.post(ajaxurl, { action: <?php echo wp_json_encode(self::AJAX); ?>, post: $btn.data('post'), nonce: $btn.data('nonce'), sticky: on ? 1 : 0 })
                    .done(function (res) {
                        if (!res || !res.success) { return; }
                        $btn.attr('aria-pressed', res.data.sticky ? 'true' : 'false')
                            .find('.dashicons').toggleClass('dashicons-star-filled', res.data.sticky).toggleClass('dashicons-star-empty', !res.data.sticky);
                        if (window.wp && wp.a11y) { wp.a11y.speak(res.data.message); }
                    })
                    .always(function () { $btn.removeAttr('aria-busy'); });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Whether the editor control applies (core handles blog posts).
     *
     * @param WP_Post|null $post Post.
     * @return bool
     */
    private static function editor_applies($post) {
        return $post instanceof WP_Post && 'post' !== $post->post_type && self::type_enabled($post->post_type)
            && current_user_can('edit_post', $post->ID);
    }

    /**
     * Block editor checkbox for types other than posts.
     */
    public static function block_editor() {
        $post = get_post();
        if (!self::editor_applies($post)) {
            return;
        }
        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'post'    => $post->ID,
            'nonce'   => wp_create_nonce(self::AJAX . '_' . $post->ID),
            'sticky'  => is_sticky($post->ID),
            'label'   => __('Stick to the top', 'seoprostack'),
            'failed'  => __('Could not update. Please try again.', 'seoprostack'),
        );
        wp_register_script('seoprostack-sticky', '', array('wp-plugins', 'wp-element', 'wp-components', 'wp-editor'), SEOPROSTACK_VERSION, true);
        wp_enqueue_script('seoprostack-sticky');
        wp_add_inline_script('seoprostack-sticky', sprintf(
            '(function (wp, cfg) {
                var el = wp.element.createElement, useState = wp.element.useState;
                var Info = (wp.editor && wp.editor.PluginPostStatusInfo) || (wp.editPost && wp.editPost.PluginPostStatusInfo);
                if (!Info) { return; }
                function Sticky() {
                    var s = useState(cfg.sticky), sticky = s[0], setSticky = s[1];
                    var b = useState(false), busy = b[0], setBusy = b[1];
                    var e = useState(""), error = e[0], setError = e[1];
                    function change(on) {
                        setBusy(true); setError("");
                        var body = new FormData();
                        body.append("action", cfg.action); body.append("post", cfg.post);
                        body.append("nonce", cfg.nonce); body.append("sticky", on ? 1 : 0);
                        fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
                            .then(function (r) { return r.json(); })
                            .then(function (res) { if (!res || !res.success) { throw new Error(); } setSticky(res.data.sticky); })
                            .catch(function () { setError(cfg.failed); })
                            .then(function () { setBusy(false); });
                    }
                    return el(Info, null, el("div", null,
                        el(wp.components.CheckboxControl, { label: cfg.label, checked: sticky, disabled: busy, onChange: change, __nextHasNoMarginBottom: true }),
                        error ? el("p", { role: "alert", style: { color: "#cc1818" } }, error) : null));
                }
                wp.plugins.registerPlugin("seoprostack-sticky", { render: Sticky });
            })(window.wp, %s);',
            wp_json_encode($cfg)
        ));
    }

    /**
     * Classic editor checkbox for types other than posts.
     *
     * @param WP_Post $post Post.
     */
    public static function submitbox($post) {
        if (!self::editor_applies($post)) {
            return;
        }
        wp_nonce_field(self::AJAX . '_' . $post->ID, '_seoprostack_sticky_nonce');
        printf(
            '<div class="misc-pub-section seoprostack-sticky-field"><input type="hidden" name="seoprostack_sticky_present" value="1" /><label><input type="checkbox" name="seoprostack_sticky" value="1" %1$s /> %2$s</label></div>',
            checked(is_sticky($post->ID), true, false),
            esc_html__('Stick to the top', 'seoprostack')
        );
    }

    /**
     * Save the classic editor checkbox.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function save_classic($post_id, $post) {
        if (empty($_POST['seoprostack_sticky_present']) || wp_is_post_revision($post_id) || !self::editor_applies($post)) {
            return;
        }
        $nonce = isset($_POST['_seoprostack_sticky_nonce']) ? sanitize_text_field(wp_unslash($_POST['_seoprostack_sticky_nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::AJAX . '_' . $post_id)) {
            return;
        }
        self::set_sticky($post_id, !empty($_POST['seoprostack_sticky']));
    }

    /**
     * AJAX: set a post's sticky state.
     */
    public static function ajax() {
        $post_id = isset($_POST['post']) ? absint(wp_unslash($_POST['post'])) : 0;
        check_ajax_referer(self::AJAX . '_' . $post_id, 'nonce');

        $post = get_post($post_id);
        if (!$post || !self::type_enabled($post->post_type) || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('You cannot change this item.', 'seoprostack')), 403);
        }

        $sticky = !empty($_POST['sticky']);
        self::set_sticky($post_id, $sticky);

        wp_send_json_success(array(
            'sticky'  => is_sticky($post_id),
            'message' => $sticky ? __('Stuck to the top.', 'seoprostack') : __('No longer sticky.', 'seoprostack'),
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Front end                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Where the main query lifts sticky items, or null.
     *
     * @param WP_Query $query Query.
     * @return string[]|null Post types to lift.
     */
    private static function context(WP_Query $query) {
        if (!$query->is_main_query() || $query->is_paged() || $query->is_feed() || ($query->get('ignore_sticky_posts') && !$query->get('seoprostack_sticky'))) {
            return null;
        }
        if ($query->is_home()) {
            return (array) SEOProStack_Settings::get('sticky_posts_home');
        }
        if ($query->is_post_type_archive()) {
            $types = array_intersect((array) $query->get('post_type'), (array) SEOProStack_Settings::get('sticky_posts_archives'));
            return $types ? array_values($types) : null;
        }
        if ($query->is_category() || $query->is_tag() || $query->is_tax()) {
            $term = $query->get_queried_object();
            if ($term instanceof WP_Term && in_array($term->taxonomy, (array) SEOProStack_Settings::get('sticky_posts_taxonomies'), true)) {
                $types = get_taxonomy($term->taxonomy)->object_type;
                return array_values(array_intersect($types, (array) SEOProStack_Settings::get('sticky_posts_types')));
            }
        }
        return null;
    }

    /**
     * On the blog home, take over from core so the chosen types are lifted.
     *
     * @param WP_Query $query Query.
     */
    public static function pre_get_posts($query) {
        if ($query->is_main_query() && $query->is_home() && !$query->is_feed() && !$query->get('ignore_sticky_posts')) {
            $query->set('ignore_sticky_posts', true);
            $query->set('seoprostack_sticky', true);
        }
    }

    /**
     * Move sticky items to the top of page 1 and add ones that fall later.
     *
     * @param WP_Post[] $posts Posts.
     * @param WP_Query  $query Query.
     * @return WP_Post[]
     */
    public static function lift($posts, $query) {
        $types = self::context($query);
        $ids   = array_map('intval', (array) get_option('sticky_posts', array()));
        if (!$types || !$ids) {
            return $posts;
        }

        $vars = $query->query_vars;
        if ($query->is_home()) {
            // Blog home: only the post type differs from core's own sticky query.
            $vars = array('post_type' => $types, 'post_status' => 'publish');
        } else {
            $vars['post_type'] = $types;
        }
        $sticky = get_posts(array_merge($vars, array(
            'post__in'            => $ids,
            'posts_per_page'      => count($ids),
            'paged'               => 1,
            'offset'              => 0,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'suppress_filters'    => false,
            'orderby'             => !empty($vars['orderby']) ? $vars['orderby'] : 'date',
            'seoprostack_sticky'  => false,
        )));
        if (!$sticky) {
            return $posts;
        }

        self::$lifted = array_map('intval', wp_list_pluck($sticky, 'ID'));
        $lifted       = self::$lifted;
        $rest         = array_filter($posts, function ($post) use ($lifted) {
            return !in_array((int) $post->ID, $lifted, true);
        });
        $merged            = array_merge($sticky, array_values($rest));
        $query->post_count = count($merged);
        return $merged;
    }

    /**
     * Add the core "sticky" class where items were lifted.
     *
     * @param string[] $classes Classes.
     * @param string[] $class   Extra classes.
     * @param int      $post_id Post ID.
     * @return string[]
     */
    public static function post_class($classes, $class, $post_id) {
        if (in_array((int) $post_id, self::$lifted, true) && !in_array('sticky', $classes, true)) {
            $classes[] = 'sticky';
        }
        return $classes;
    }
}
