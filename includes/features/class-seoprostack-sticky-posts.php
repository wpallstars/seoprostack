<?php
/**
 * Pinned posts for any post type.
 *
 * WordPress only lets blog posts be sticky, and only lifts them to the top of
 * the blog home. This adds a pin to post lists and a "Pin to the top"
 * option in the editor for the chosen post types, and lifts pinned items to
 * the top of the first page of the blog home, post type archives and
 * category/term archives you choose. Uses the core "sticky_posts" list, so
 * existing sticky posts, themes and blocks keep working; wp-admin calls them
 * "Pinned", the word most sites and apps use. Replaces "Sticky Posts
 * Switch"; its settings are imported once. Optionally, an ACF/SCF
 * true/false field (such as "Featured") drives the pin both ways, and
 * Kadence Blocks Pro query loops list pinned items first.
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

class SEOProStack_Sticky_Posts extends SEOProStack_Feature {

    const KEY = 'sticky_posts';

    /** AJAX action. */
    const AJAX = 'seoprostack_sticky';

    /** Query var that marks a Kadence query loop whose pins are handled here. */
    const LOOP = 'seoprostack_sticky_loop';

    /** Query var that ends a list's order with the post ID (posts_orderby()). */
    const STABLE = 'seoprostack_stable_order';

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
                'tab'         => 'content',
                'label'       => __('Pinned posts for any post type', 'seoprostack'),
                'description' => __('Pin posts, pages, products and custom post types to the top of their lists. Adds a pin to post lists and a “Pin to the top” option in the editor, and says “Pinned” where WordPress says “Sticky”.', 'seoprostack'),
                'replaces'    => array('sticky-posts-switch' => 'Sticky Posts Switch'),
            ),
            'sticky_posts_types' => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array('post'),
                'parent'  => self::KEY,
                'label'   => __('Post types that can be pinned', 'seoprostack'),
                'options' => $types,
            ),
            'sticky_posts_fields' => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'hidden'      => !self::fields_active(),
                'label'       => __('Pin from a true/false field', 'seoprostack'),
                'description' => __('ACF or Secure Custom Fields true/false fields of the pinnable types, such as “Featured”. Items are pinned while the field is on, and pinning or unpinning an item sets the field. When you choose a field, items of its type are pinned and unpinned to match it. In the editor, the field is the pin.', 'seoprostack'),
                'options'     => array(__CLASS__, 'field_options'),
            ),
            'sticky_posts_home' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array('post'),
                'parent'      => self::KEY,
                'label'       => __('Lift to the top of the blog home', 'seoprostack'),
                'description' => __('Pinned items of these types lead the first page of your latest posts.', 'seoprostack'),
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
                'description' => __('For example, each category page shows its pinned posts first.', 'seoprostack'),
                'options'     => array(__CLASS__, 'taxonomy_options'),
            ),
            'sticky_posts_kadence' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'hidden'      => !self::kadence_active(),
                'label'       => __('Lift to the top of Kadence query loops', 'seoprostack'),
                'description' => __('Pinned items lead Kadence Blocks Pro’s Query Loop (Adv) blocks, in the loop’s own order and filters, followed by the rest in the same order. They count towards each page, so every page keeps the loop’s size and nothing is repeated or skipped.', 'seoprostack'),
            ),
            'sticky_posts_kadence_loops' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array(),
                'parent'      => self::KEY,
                'hidden'      => !self::kadence_active(),
                'label'       => __('Only in these Kadence query loops', 'seoprostack'),
                'description' => __('Leave all unticked to lift pinned items in every query loop.', 'seoprostack'),
                'options'     => array(__CLASS__, 'kadence_loop_options'),
            ),
        );
    }

    /**
     * Whether Kadence Blocks Pro is active (as stored, so it does not depend
     * on the order plugins load in).
     *
     * @return bool
     */
    private static function kadence_active() {
        return isset(self::active_plugins()['kadence-blocks-pro']);
    }

    /**
     * Kadence Blocks Pro query loops (the "kadence_query" posts).
     *
     * @return array<int,string> ID => title
     */
    public static function kadence_loop_options() {
        $options = array();
        $loops   = get_posts(array(
            'post_type'      => 'kadence_query',
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => 100,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ));
        foreach ($loops as $loop) {
            $options[(int) $loop->ID] = '' !== $loop->post_title
                ? $loop->post_title
                /* translators: %d: query loop ID */
                : sprintf(__('Query loop %d', 'seoprostack'), $loop->ID);
        }
        return $options;
    }

    /**
     * Whether ACF, ACF Pro or Secure Custom Fields is active (as stored).
     *
     * @return bool
     */
    private static function fields_active() {
        $active = self::active_plugins();
        return isset($active['advanced-custom-fields-pro']) || isset($active['advanced-custom-fields']) || isset($active['secure-custom-fields']);
    }

    /**
     * Top-level true/false fields of the post types that can be chosen as
     * pinnable (all of them, so a type ticked in the same save keeps its
     * field), by type.
     *
     * @return array<string,array<string,array{key:string,label:string}>> Type => field name => key and label.
     */
    private static function true_false_fields() {
        static $found = null;
        if (null !== $found) {
            return $found;
        }
        if (!self::fields_loaded() || !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            // Not cached: the fields may be ready later on this request.
            return array();
        }
        $found = array();
        foreach (array_keys(SEOProStack_Duplicate_Posts::post_type_options()) as $type) {
            foreach ((array) acf_get_field_groups(array('post_type' => $type)) as $group) {
                foreach ((array) acf_get_fields($group) as $field) {
                    if (isset($field['type'], $field['name'], $field['key']) && 'true_false' === $field['type'] && '' !== $field['name']) {
                        $found[$type][$field['name']] = array(
                            'key'   => (string) $field['key'],
                            'label' => '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['name'],
                        );
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Whether ACF or Secure Custom Fields is loaded and has registered its
     * field groups on this request (at `acf/init`).
     *
     * @return bool
     */
    private static function fields_loaded() {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields') && did_action('acf/init') > 0;
    }

    /**
     * True/false fields that can drive the pin ("type|field_name" => label).
     *
     * A settings save checks every setting against these options, so on a
     * request where the fields cannot be read (ACF skipped by plugin loading,
     * or before `acf/init`), the chosen fields stay options and are kept.
     *
     * @return array<string,string>
     */
    public static function field_options() {
        $fields = self::true_false_fields();
        if (!self::fields_loaded()) {
            foreach (self::linked_fields() as $type => $names) {
                foreach ($names as $name) {
                    $fields[$type][$name] = array('key' => '', 'label' => $name);
                }
            }
        }
        $options = array();
        foreach ($fields as $type => $by_name) {
            $object = get_post_type_object($type);
            $name   = $object ? $object->labels->name : $type;
            foreach ($by_name as $field => $info) {
                /* translators: 1: post type name, 2: field label, 3: field name */
                $options[$type . '|' . $field] = sprintf(__('%1$s: %2$s (%3$s)', 'seoprostack'), $name, $info['label'], $field);
            }
        }
        return $options;
    }

    /**
     * Chosen pin fields.
     *
     * @param mixed $value Setting value (default: the stored one).
     * @return array<string,string[]> Post type => field names.
     */
    private static function linked_fields($value = null) {
        $value = null === $value ? SEOProStack_Settings::get('sticky_posts_fields') : $value;
        $links = array();
        foreach ((array) $value as $item) {
            $parts = explode('|', (string) $item, 2);
            if (2 === count($parts) && '' !== $parts[0] && '' !== $parts[1]) {
                $links[$parts[0]][] = $parts[1];
            }
        }
        return $links;
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
        // Also when the feature is switched on in the same save.
        add_action('update_option_' . SEOProStack_Settings::OPTION, array(__CLASS__, 'settings_saved'), 10, 2);
        if (!self::enabled()) {
            return;
        }

        if (self::linked_fields()) {
            add_action('added_post_meta', array(__CLASS__, 'field_changed'), 10, 4);
            add_action('updated_post_meta', array(__CLASS__, 'field_changed'), 10, 4);
            add_action('deleted_post_meta', array(__CLASS__, 'field_deleted'), 10, 3);
            add_action('post_stuck', array(__CLASS__, 'pin_changed'));
            add_action('post_unstuck', array(__CLASS__, 'pin_changed'));
        }
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
        add_action('admin_init', array(__CLASS__, 'admin_columns'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        add_action('post_submitbox_misc_actions', array(__CLASS__, 'submitbox'));
        add_action('save_post', array(__CLASS__, 'save_classic'), 10, 2);
        add_action('before_delete_post', array(__CLASS__, 'unstick'));

        if (is_admin()) {
            // "Pinned" where WordPress says "Sticky": lists and editors only.
            add_filter('display_post_states', array(__CLASS__, 'post_states'), 10, 2);
            add_filter('views_edit-post', array(__CLASS__, 'post_views'));
            foreach (array('load-edit.php', 'load-post.php', 'load-post-new.php') as $hook) {
                add_action($hook, array(__CLASS__, 'pinned_words'));
            }
        }
        // Front end, plus admin-ajax for query loops filtered in place.
        if (!is_admin() || wp_doing_ajax()) {
            add_action('pre_get_posts', array(__CLASS__, 'pre_get_posts'));
            add_filter('the_posts', array(__CLASS__, 'lift'), 10, 2);
            add_filter('post_class', array(__CLASS__, 'post_class'), 10, 3);
            add_filter('post_limits', array(__CLASS__, 'post_limits'), 10, 2);
            add_filter('found_posts', array(__CLASS__, 'found_posts'), 10, 2);
            add_filter('posts_orderby', array(__CLASS__, 'posts_orderby'), 99, 2);
        }
        if (SEOProStack_Settings::get('sticky_posts_kadence') && self::kadence_active()) {
            add_filter('kadence_blocks_pro_query_loop_query_vars', array(__CLASS__, 'kadence_query_vars'), 20, 3);
            if (!has_filter('found_posts', array(__CLASS__, 'found_posts'))) {
                add_filter('found_posts', array(__CLASS__, 'found_posts'), 10, 2);
            }
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
    /* Pin from a field                                                       */
    /* --------------------------------------------------------------------- */

    /**
     * Set while the pin and a field are being matched, so neither side
     * answers the other.
     *
     * @var bool
     */
    private static $syncing = false;

    /**
     * Whether a stored true/false value is on.
     *
     * @param mixed $value Meta value.
     * @return bool
     */
    private static function field_on($value) {
        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * A linked field was added or changed: pin or unpin the item.
     *
     * @param int    $meta_id  Meta ID (unused).
     * @param int    $post_id  Post ID.
     * @param string $meta_key Meta key.
     * @param mixed  $value    New value.
     */
    public static function field_changed($meta_id, $post_id, $meta_key, $value) {
        unset($meta_id);
        $type  = get_post_type($post_id);
        $links = self::linked_fields();
        if (self::$syncing || !$type || empty($links[$type]) || !in_array($meta_key, $links[$type], true) || !self::type_enabled($type)) {
            return;
        }
        self::$syncing = true;
        self::set_sticky((int) $post_id, self::field_on($value));
        self::$syncing = false;
    }

    /**
     * A linked field was deleted: unpin the item.
     *
     * @param int[]  $meta_ids Meta IDs (unused).
     * @param int    $post_id  Post ID.
     * @param string $meta_key Meta key.
     */
    public static function field_deleted($meta_ids, $post_id, $meta_key) {
        self::field_changed(0, $post_id, $meta_key, false);
    }

    /**
     * The item was pinned or unpinned (pin column, Quick Edit, core): set its
     * linked fields to match.
     *
     * @param int $post_id Post ID.
     */
    public static function pin_changed($post_id) {
        $type  = get_post_type($post_id);
        $links = self::linked_fields();
        if (self::$syncing || !$type || empty($links[$type])) {
            return;
        }
        $on     = is_sticky($post_id);
        $fields = self::true_false_fields();
        self::$syncing = true;
        foreach ($links[$type] as $field) {
            if (isset($fields[$type][$field]) && function_exists('update_field')) {
                // By key, so ACF keeps its reference to the field.
                update_field($fields[$type][$field]['key'], $on ? 1 : 0, (int) $post_id);
            } else {
                update_post_meta((int) $post_id, $field, $on ? '1' : '0');
            }
        }
        self::$syncing = false;
    }

    /**
     * Settings saved: newly chosen fields pin and unpin items to match.
     *
     * @param mixed $old Previous settings.
     * @param mixed $new New settings.
     */
    public static function settings_saved($old, $new) {
        if (!is_array($new) || empty($new[self::KEY])) {
            return;
        }
        $before = self::linked_fields(is_array($old) && !empty($old[self::KEY]) && isset($old['sticky_posts_fields']) ? $old['sticky_posts_fields'] : array());
        $after  = self::linked_fields(isset($new['sticky_posts_fields']) ? $new['sticky_posts_fields'] : array());
        $types  = isset($new['sticky_posts_types']) ? (array) $new['sticky_posts_types'] : array();
        foreach ($after as $type => $fields) {
            $added = array_diff($fields, isset($before[$type]) ? $before[$type] : array());
            if ($added && in_array($type, $types, true)) {
                self::match_fields($type, $fields);
            }
        }
    }

    /**
     * Pin the items of a type whose fields are on, and unpin the rest.
     *
     * @param string   $type   Post type.
     * @param string[] $fields Field names (pinned while any is on).
     * @return int[] IDs of the type pinned afterwards.
     */
    public static function match_fields($type, array $fields) {
        $clauses = array('relation' => 'OR');
        foreach ($fields as $field) {
            $clauses[] = array('key' => $field, 'value' => array('1', 'true', 'yes', 'on'), 'compare' => 'IN');
        }
        $on = array_map('intval', get_posts(array(
            'post_type'        => $type,
            'post_status'      => 'any',
            'posts_per_page'   => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- IDs only, once when a field is chosen; every match goes into sticky_posts.
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'meta_query'       => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- once, when a field is chosen.
        )));
        $sticky = array_map('intval', (array) get_option('sticky_posts', array()));
        $others = array_filter($sticky, function ($id) use ($type) {
            return get_post_type($id) !== $type;
        });
        $list = array_values(array_unique(array_merge($others, $on)));
        if ($list !== $sticky) {
            update_option('sticky_posts', $list);
        }
        return $on;
    }

    /* --------------------------------------------------------------------- */
    /* Admin                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * WordPress's words for sticky posts, said as "pinned".
     *
     * @return array<string,string> Core text => ours.
     */
    private static function pinned_map() {
        return array(
            'Sticky'                            => __('Pinned', 'seoprostack'),
            'Not Sticky'                        => __('Not pinned', 'seoprostack'),
            'Make this post sticky'             => __('Pin this post to the top', 'seoprostack'),
            'Public, Sticky'                    => __('Public, pinned', 'seoprostack'),
            'Stick this post to the front page' => __('Pin this post to the front page', 'seoprostack'),
        );
    }

    /**
     * Post lists and editors: core's Quick Edit, Bulk Edit and classic
     * editor say "Pinned". Only on those screens, so other text is not
     * filtered elsewhere.
     */
    public static function pinned_words() {
        add_filter('gettext', array(__CLASS__, 'gettext'), 10, 3);
        add_filter('gettext_with_context', array(__CLASS__, 'gettext_with_context'), 10, 4);
    }

    /**
     * Core's sticky words.
     *
     * @param string $translation Translated text.
     * @param string $text        Original text.
     * @param string $domain      Text domain.
     * @return string
     */
    public static function gettext($translation, $text, $domain) {
        static $map = null;
        if ('default' !== $domain) {
            return $translation;
        }
        if (null === $map) {
            $map = self::pinned_map();
        }
        return isset($map[$text]) ? $map[$text] : $translation;
    }

    /**
     * Core's "Sticky" post status.
     *
     * @param string $translation Translated text.
     * @param string $text        Original text.
     * @param string $context     Context.
     * @param string $domain      Text domain.
     * @return string
     */
    public static function gettext_with_context($translation, $text, $context, $domain) {
        return 'Sticky' === $text && 'post status' === $context && 'default' === $domain ? __('Pinned', 'seoprostack') : $translation;
    }

    /**
     * "Pinned" after a pinned item's title in lists. On post lists it is
     * marked, so the pin toggle can add and remove it without a reload.
     *
     * @param string[] $states Post states.
     * @param WP_Post  $post   Post.
     * @return string[]
     */
    public static function post_states($states, $post) {
        if (isset($states['sticky'])) {
            $screen           = function_exists('get_current_screen') ? get_current_screen() : null;
            $states['sticky'] = $screen && 'edit' === $screen->base ? self::pinned_state() : __('Pinned', 'seoprostack');
        }
        return $states;
    }

    /**
     * The marked "Pinned" post state for post lists.
     *
     * @return string
     */
    private static function pinned_state() {
        return '<span class="seoprostack-pinned-state">' . esc_html__('Pinned', 'seoprostack') . '</span>';
    }

    /**
     * The Posts list's "Sticky" view says "Pinned".
     *
     * @param array $views Views.
     * @return array
     */
    public static function post_views($views) {
        if (isset($views['sticky']) && is_string($views['sticky'])) {
            $views['sticky'] = preg_replace_callback('#(<a\b[^>]*>)[^<]*(<span class="count">)#', function ($m) {
                return $m[1] . esc_html__('Pinned', 'seoprostack') . ' ' . $m[2];
            }, $views['sticky'], 1);
        }
        return $views;
    }

    /**
     * Pin column in the lists of enabled types.
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
        $label = '<span class="dashicons dashicons-admin-post" aria-hidden="true" title="' . esc_attr__('Pinned', 'seoprostack') . '"></span><span class="screen-reader-text">' . esc_html__('Pinned', 'seoprostack') . '</span>';
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
     * Pin toggle.
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
                echo '<span class="dashicons dashicons-admin-post seoprostack-pinned" title="' . esc_attr__('Pinned', 'seoprostack') . '"></span>';
            }
            return;
        }
        printf(
            '<button type="button" class="button-link seoprostack-sticky" data-post="%1$d" data-nonce="%2$s" aria-pressed="%3$s" aria-label="%4$s" title="%5$s"><span class="dashicons dashicons-admin-post" aria-hidden="true"></span></button>',
            (int) $post_id,
            esc_attr(wp_create_nonce(self::AJAX . '_' . $post_id)),
            $sticky ? 'true' : 'false',
            /* translators: %s: post title */
            esc_attr(sprintf(__('Pin “%s” to the top', 'seoprostack'), $title)),
            esc_attr__('Pin to the top', 'seoprostack')
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
            .fixed .column-seoprostack_sticky { width: 20px; padding-left: 2px; padding-right: 2px; text-align: center; }
            <?php /* Admin Columns Pro layouts set every column's width from these variables, with !important, so a column with no width there was 0 px wide. A width set in the layout (-width-user) still wins. */ ?>
            .wp-list-table { --ac-col-seoprostack_sticky-width: 32px; }
            .column-seoprostack_sticky .dashicons { color: #8c8f94; }
            .wp-core-ui .seoprostack-sticky { cursor: pointer; padding: 0; }
            .wp-core-ui .seoprostack-sticky[aria-pressed="false"] .dashicons { opacity: .4; }
            .wp-core-ui .seoprostack-sticky:hover .dashicons, .wp-core-ui .seoprostack-sticky:focus .dashicons { opacity: 1; }
            td.column-seoprostack_sticky .seoprostack-sticky[aria-pressed="true"] .dashicons, td.column-seoprostack_sticky .seoprostack-pinned { color: var(--wp-admin-theme-color, #2271b1); }
            .wp-core-ui .seoprostack-sticky:focus { box-shadow: 0 0 0 2px var(--wp-admin-theme-color, #2271b1); border-radius: 2px; outline: none; }
            .wp-core-ui .seoprostack-sticky[aria-busy="true"] { opacity: .5; }
        </style>
        <script>
        (function ($) {
            var pinned = <?php echo wp_json_encode(self::pinned_state()); ?>;
            var sep = <?php echo wp_json_encode(wp_get_list_item_separator()); ?>;

            // Rebuild the post states after the title ("— Draft, Pinned")
            // with "Pinned" added or taken out, as WordPress prints them.
            function showState($row, on) {
                var link = $row.find('a.row-title').get(0), states = [], node, next;
                if (!link) { return; }
                for (node = link.nextSibling; node; node = next) {
                    next = node.nextSibling;
                    if (node.nodeType === 1 && $(node).hasClass('post-state')) {
                        if (!$(node).find('.seoprostack-pinned-state').length) {
                            var html = $(node).html();
                            states.push(sep && html.slice(-sep.length) === sep ? html.slice(0, -sep.length) : html);
                        }
                        node.parentNode.removeChild(node);
                    } else if (node.nodeType === 3) {
                        node.parentNode.removeChild(node);
                    }
                }
                if (on) { states.push(pinned); }
                if (!states.length) { return; }
                var out = ' \u2014 ';
                $.each(states, function (i, state) {
                    out += '<span class="post-state">' + state + (i < states.length - 1 ? sep : '') + '</span>';
                });
                $(link).after(out);
            }

            $(document).on('click', '.seoprostack-sticky', function () {
                var $btn = $(this), on = $btn.attr('aria-pressed') !== 'true';
                $btn.attr('aria-busy', 'true');
                $.post(ajaxurl, { action: <?php echo wp_json_encode(self::AJAX); ?>, post: $btn.data('post'), nonce: $btn.data('nonce'), sticky: on ? 1 : 0 })
                    .done(function (res) {
                        if (!res || !res.success) { return; }
                        $btn.attr('aria-pressed', res.data.sticky ? 'true' : 'false');
                        showState($btn.closest('tr'), res.data.sticky);
                        // Quick Edit reads this when it opens.
                        $('#inline_' + $btn.data('post') + ' .sticky').text(res.data.sticky ? 'sticky' : '');
                        if (window.wp && wp.a11y) { wp.a11y.speak(res.data.message); }
                    })
                    .always(function () { $btn.removeAttr('aria-busy'); });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Whether the editor control applies (core handles blog posts). Not for
     * types pinned from a field: the field is the pin there, and saving its
     * box would undo a pin set separately.
     *
     * @param WP_Post|null $post Post.
     * @return bool
     * @phpstan-assert-if-true WP_Post $post
     */
    private static function editor_applies($post) {
        $links = self::linked_fields();
        return $post instanceof WP_Post && 'post' !== $post->post_type && self::type_enabled($post->post_type)
            && empty($links[$post->post_type]) && current_user_can('edit_post', $post->ID);
    }

    /**
     * Block editor checkbox for types other than posts.
     */
    public static function block_editor() {
        $post = get_post();
        if ($post instanceof WP_Post && 'post' === $post->post_type) {
            // Core's own control for blog posts: "Sticky" becomes "Pinned".
            wp_add_inline_script('wp-hooks', sprintf(
                'wp.hooks.addFilter("i18n.gettext_default", "seoprostack/pinned", function (t, text) { return "Sticky" === text ? %s : t; });',
                wp_json_encode(__('Pinned', 'seoprostack'))
            ));
            return;
        }
        if (!self::editor_applies($post)) {
            return;
        }
        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'post'    => $post->ID,
            'nonce'   => wp_create_nonce(self::AJAX . '_' . $post->ID),
            'sticky'  => is_sticky($post->ID),
            'label'   => __('Pin to the top', 'seoprostack'),
            'failed'  => __('Could not update. Please try again.', 'seoprostack'),
        );
        wp_register_script('seoprostack-sticky', false, array('wp-plugins', 'wp-element', 'wp-components', 'wp-editor'), SEOPROSTACK_VERSION, true);
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
            esc_html__('Pin to the top', 'seoprostack')
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
            'message' => $sticky ? __('Pinned to the top.', 'seoprostack') : __('No longer pinned.', 'seoprostack'),
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Front end                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Kadence Blocks Pro query loops (also their filter and pagination
     * requests): pinned items of the pinnable types lead the loop, in its
     * own order and filters, then the rest in the same order. Pinned items
     * take places on the pages like any other item, so each page keeps the
     * loop's size and nothing is repeated or skipped. The loop's own query
     * leaves them out and starts that many places earlier; lift() puts the
     * page's pinned items back in front.
     *
     * @param array $query   WP_Query arguments.
     * @param mixed $meta    Query loop settings (unused).
     * @param int   $loop_id Query loop ("kadence_query") ID.
     * @return array
     */
    public static function kadence_query_vars($query, $meta = null, $loop_id = 0) {
        unset($meta);
        // Only loops of posts: lift() cannot place bare IDs.
        if (!is_array($query) || (isset($query['fields']) && !in_array($query['fields'], array('', 'all'), true))) {
            return $query;
        }
        $only = array_map('intval', (array) SEOProStack_Settings::get('sticky_posts_kadence_loops'));
        if ($only && !in_array((int) $loop_id, $only, true)) {
            return $query;
        }
        $wanted = isset($query['post_type']) && '' !== $query['post_type'] ? (array) $query['post_type'] : array('post');
        $types  = array_values(array_intersect($wanted, (array) SEOProStack_Settings::get('sticky_posts_types')));
        $ids    = array_map('intval', (array) get_option('sticky_posts', array()));
        $out    = isset($query['post__not_in']) ? array_map('intval', (array) $query['post__not_in']) : array();
        $in     = !empty($query['post__in']) ? array_map('intval', (array) $query['post__in']) : array();
        // Pins the loop leaves out itself stay out.
        $ids = array_values(array_diff($ids, $out));
        if ($in) {
            $ids = array_values(array_intersect($ids, $in));
        }
        if (!$types || !$ids) {
            return $query;
        }

        // The pinned items this loop shows, in its own order and filters.
        $pinned = self::kadence_pinned($query, $types, $ids, $in);
        if (!$pinned) {
            return $query;
        }

        if ($in) {
            // Core ignores post__not_in alongside post__in; an empty
            // post__in would list everything.
            $rest              = array_values(array_diff($in, $pinned));
            $query['post__in'] = $rest ? $rest : array(0);
        } else {
            // The loop's own exclusions plus the few pinned IDs shown first; paging by offset needs them left out in SQL.
            $query['post__not_in'] = array_merge($out, $pinned); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- see above.
        }

        $count = count($pinned);
        $per   = isset($query['posts_per_page']) ? (int) $query['posts_per_page'] : 0;
        if ($per > 0) {
            // Kadence pages with an offset: perPage × (page − 1) plus the
            // loop's own offset. Pinned items fill the first places, then
            // the rest follow, so the rest start that many places earlier.
            $paged = isset($query['paged']) ? max(1, (int) $query['paged']) : 1;
            $start = isset($query['offset']) ? max(0, (int) $query['offset']) : $per * ($paged - 1);
            $slice = array_slice($pinned, $start, $per);
            $query['offset'] = max(0, $start - $count);
            // An offset of 0 counts as none, and WP_Query would page instead.
            $query['paged'] = 1;
            $take  = $per - count($slice);
        } else {
            // No page size (an inherited query): all pinned items on page 1.
            $first = empty($query['offset']) && (empty($query['paged']) || (int) $query['paged'] <= 1);
            $slice = $first ? $pinned : array();
            $take  = -1;
        }

        $query[self::LOOP] = array('types' => $types, 'ids' => $slice, 'take' => $take, 'count' => $count);
        return $query;
    }

    /**
     * Pinned IDs a Kadence query loop shows, in its order.
     *
     * @param array $query Loop query arguments.
     * @param array $types Pinnable post types in the loop.
     * @param int[] $ids   Pinned IDs not left out by the loop.
     * @param int[] $in    The loop's own post__in, if any.
     * @return int[]
     */
    private static function kadence_pinned(array $query, array $types, array $ids, array $in) {
        $orderby = isset($query['orderby']) ? $query['orderby'] : '';
        if ($in && 'post__in' === $orderby) {
            // Chosen posts keep the order they were chosen in.
            $ids = array_values(array_intersect($in, $ids));
        }
        $vars = $query;
        unset($vars['offset'], $vars['paged'], $vars[self::LOOP]);
        $vars = array_merge($vars, array(
            'post_type'           => $types,
            'post__in'            => $ids,
            'posts_per_page'      => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- limited by post__in, the pinned IDs.
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'suppress_filters'    => false,
            'orderby'             => '' !== $orderby ? $orderby : 'date',
            'seoprostack_sticky'  => false,
            self::STABLE          => true,
        ));
        return wp_parse_id_list(get_posts($vars));
    }

    /**
     * Kadence query loops: count the pinned items in the loop's total, so
     * its result count and page numbers include them.
     *
     * @param int      $found Found posts.
     * @param WP_Query $query Query.
     * @return int
     */
    public static function found_posts($found, $query) {
        $loop = $query instanceof WP_Query ? $query->get(self::LOOP) : null;
        return is_array($loop) && !empty($loop['count']) ? (int) $found + (int) $loop['count'] : $found;
    }

    /**
     * Where the main query lifts sticky items, or null.
     *
     * @param WP_Query $query Query.
     * @return string[]|null Post types to lift.
     */
    private static function context(WP_Query $query) {
        $loop = $query->get(self::LOOP);
        if (is_array($loop) && !empty($loop['types'])) {
            // A Kadence query loop (see kadence_query_vars()).
            return in_array($query->get('fields'), array('', 'all'), true) ? array_values((array) $loop['types']) : null;
        }
        if (!$query->is_main_query() || $query->is_paged() || $query->is_feed() || ($query->get('ignore_sticky_posts') && !$query->get('seoprostack_sticky'))) {
            return null;
        }
        if ($query->is_home()) {
            return (array) SEOProStack_Settings::get('sticky_posts_home');
        }
        return self::archive_types($query);
    }

    /**
     * Post types a post type or term archive lifts, on any of its pages.
     *
     * @param WP_Query $query Main query.
     * @return string[]|null
     */
    private static function archive_types(WP_Query $query) {
        if ($query->is_post_type_archive()) {
            $types = array_intersect((array) $query->get('post_type'), (array) SEOProStack_Settings::get('sticky_posts_archives'));
            return $types ? array_values($types) : null;
        }
        if ($query->is_category() || $query->is_tag() || $query->is_tax()) {
            $term = $query->get_queried_object();
            if ($term instanceof WP_Term && in_array($term->taxonomy, (array) SEOProStack_Settings::get('sticky_posts_taxonomies'), true)) {
                $taxonomy = get_taxonomy($term->taxonomy);
                $types    = $taxonomy ? (array) $taxonomy->object_type : array();
                $types    = array_values(array_intersect($types, (array) SEOProStack_Settings::get('sticky_posts_types')));
                return $types ? $types : null;
            }
        }
        return null;
    }

    /**
     * On the blog home, take over from core so the chosen types are lifted.
     * On post type and term archives, pinned items take places on the pages.
     *
     * @param WP_Query $query Query.
     */
    public static function pre_get_posts($query) {
        if (null !== $query->get(self::LOOP, null)) {
            // Kadence query loops: pinned items are lifted here, not by core.
            $query->set('ignore_sticky_posts', true);
            $query->set(self::STABLE, true);
            return;
        }
        if (!$query->is_main_query() || $query->is_feed() || ($query->get('ignore_sticky_posts') && !$query->get('seoprostack_sticky'))) {
            return;
        }
        if ($query->is_home()) {
            $query->set('ignore_sticky_posts', true);
            $query->set('seoprostack_sticky', true);
            $query->set(self::STABLE, true);
            return;
        }
        $types = self::archive_types($query);
        if ($types) {
            $query->set(self::STABLE, true);
            self::page_archive($query, $types);
        }
    }

    /**
     * Lists this feature pages (blog home, post type and term archives,
     * Kadence query loops) end their order with the post ID. WordPress sorts
     * by date (or title) alone, so items with the same date, common after an
     * import, come back in a different order on each page: some show twice
     * and others never. The ID keeps the order and only settles those ties.
     *
     * @param string   $orderby ORDER BY clause, without the keywords.
     * @param WP_Query $query   Query.
     * @return string
     */
    public static function posts_orderby($orderby, $query) {
        global $wpdb;
        if (!$query instanceof WP_Query || !$query->get(self::STABLE) || '' === trim((string) $orderby)) {
            return $orderby;
        }
        if (preg_match('/\bRAND\s*\(|\bFIELD\s*\(|(^|[\s.,(])ID\b/i', $orderby)) {
            // Random, hand-picked (post__in) or already ordered by ID.
            return $orderby;
        }
        $order = preg_match('/\bDESC\s*$/i', $orderby) ? 'DESC' : 'ASC';
        return $orderby . ", {$wpdb->posts}.ID {$order}";
    }

    /**
     * Post type and term archives: the pinned items in the archive lead it,
     * in its order, then the rest in the same order. Like Kadence query
     * loops, pinned items take places on the pages, so each page keeps its
     * size and nothing is repeated: the archive's own query leaves them out
     * and starts that many places earlier (post_limits()), and lift() puts
     * the page's pinned items in front. The page number stays as it is, so
     * pagination links and the page title are unchanged.
     *
     * @param WP_Query $query Main query, before it runs.
     * @param string[] $types Pinnable post types the archive lists.
     */
    private static function page_archive(WP_Query $query, array $types) {
        $ids = array_map('intval', (array) get_option('sticky_posts', array()));
        $out = array_map('intval', (array) $query->get('post__not_in'));
        $in  = array_map('intval', array_filter((array) $query->get('post__in')));
        $ids = array_values(array_diff($ids, $out));
        if ($in) {
            $ids = array_values(array_intersect($ids, $in));
        }
        if (!$ids || '' !== (string) $query->get('offset')) {
            // Nothing pinned here, or a theme pages with its own offset.
            return;
        }
        $vars = $query->query_vars;
        unset($vars['post__not_in']);
        $pinned = self::kadence_pinned($vars, $types, $ids, $in);
        if (!$pinned) {
            return;
        }
        if ($in) {
            $rest = array_values(array_diff($in, $pinned));
            $query->set('post__in', $rest ? $rest : array(0));
        } else {
            $query->set('post__not_in', array_merge($out, $pinned));
        }
        $query->set('ignore_sticky_posts', true);

        $count = count($pinned);
        $per   = (int) $query->get('posts_per_page');
        if (0 === $per) {
            $per = (int) get_option('posts_per_page');
        }
        if ($per < 1 || $query->get('nopaging')) {
            // One page: all pinned items lead it.
            $query->set(self::LOOP, array('types' => $types, 'ids' => $pinned, 'take' => -1, 'count' => $count));
            return;
        }
        $start = $per * (max(1, (int) $query->get('paged')) - 1);
        $slice = array_slice($pinned, $start, $per);
        $query->set(self::LOOP, array(
            'types' => $types,
            'ids'   => $slice,
            'take'  => $per - count($slice),
            'count' => $count,
            // The rest start this many places in, a page's worth at a time.
            'limit' => array(max(0, $start - $count), $per),
        ));
    }

    /**
     * Archives paged by page_archive(): the rest of the items start where
     * the pinned items before this page leave off.
     *
     * @param string   $limits LIMIT clause.
     * @param WP_Query $query  Query.
     * @return string
     */
    public static function post_limits($limits, $query) {
        $loop = $query instanceof WP_Query ? $query->get(self::LOOP) : null;
        if (!is_array($loop) || empty($loop['limit']) || '' === $limits) {
            return $limits;
        }
        return sprintf('LIMIT %d, %d', (int) $loop['limit'][0], (int) $loop['limit'][1]);
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
        $loop  = $query->get(self::LOOP);
        $ids   = is_array($loop) && isset($loop['ids']) ? $loop['ids'] : get_option('sticky_posts', array());
        $ids   = array_map('intval', (array) $ids);
        if (!$types || !$ids) {
            return $posts;
        }

        if (is_array($loop)) {
            // Kadence query loop: this page's pinned items, already in the
            // loop's order and filters (kadence_query_vars()), then as many
            // of the rest as the page has room for.
            _prime_post_caches($ids, false, false);
            $sticky = array_values(array_filter(array_map('get_post', $ids)));
            $take   = isset($loop['take']) ? (int) $loop['take'] : -1;
            $rest   = $take >= 0 ? array_slice((array) $posts, 0, $take) : (array) $posts;

            if (!$posts && $sticky && !empty($loop['limit'])) {
                // Only pinned items on this archive page: WordPress counts
                // the rest only when it finds some, so count them here.
                self::archive_count($query, $loop);
            }

            self::$lifted      = array_map('intval', wp_list_pluck($sticky, 'ID'));
            $merged            = array_merge($sticky, $rest);
            $query->post_count = count($merged);
            return $merged;
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
            'fields'              => 'all',
            'posts_per_page'      => count($ids),
            'paged'               => 1,
            'offset'              => 0,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'suppress_filters'    => false,
            'orderby'             => !empty($vars['orderby']) ? $vars['orderby'] : 'date',
            'seoprostack_sticky'  => false,
            self::STABLE          => true,
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
     * Total and page count of an archive page that holds only pinned items.
     *
     * @param WP_Query $query Main query.
     * @param array    $loop  Plan from page_archive().
     */
    private static function archive_count(WP_Query $query, array $loop) {
        $vars = $query->query_vars;
        unset($vars[self::LOOP], $vars['offset'], $vars['paged']);
        $rest = new WP_Query(array_merge($vars, array(
            'fields'              => 'ids',
            'posts_per_page'      => 1,
            'no_found_rows'       => false,
            'ignore_sticky_posts' => true,
            'seoprostack_sticky'  => false,
        )));
        $query->found_posts   = (int) $loop['count'] + (int) $rest->found_posts;
        $query->max_num_pages = (int) ceil($query->found_posts / max(1, (int) $loop['limit'][1]));
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
