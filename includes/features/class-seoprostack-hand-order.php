<?php
/**
 * Order by hand.
 *
 * Drag rows, or move them with the arrow keys, in the admin lists of chosen
 * post types and taxonomies. Posts keep their order in core's menu_order
 * (the Order field pages already have); terms in term meta, so no core table
 * is changed. Lists of those types, and their queries on the site that do
 * not ask for an order themselves, follow it.
 *
 * Moving rows only swaps them among the places the moved rows already hold,
 * so ordering one page of a long list, or a filtered list, leaves the rest
 * where it was.
 *
 * Replaces Simple Custom Post Order. Its post types and taxonomies are
 * imported, and so is its term order (the `term_order` column it adds to
 * the terms table), which is read and never changed.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Hand_Order extends SEOProStack_Feature {

    const KEY = 'hand_order';

    /** Term meta holding a term's place. */
    const TERM_META = '_seoprostack_order';

    /** AJAX action and nonce action. */
    const AJAX = 'seoprostack_hand_order';

    /** Rows updated per query when renumbering. */
    const BATCH = 500;

    /** The handle column: as narrow as its icon, to leave room for the title. */
    const COLUMN_STYLE = '.fixed .column-seoprostack_order { width: 20px; padding-left: 2px; padding-right: 2px; text-align: center; }';

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
                'label'       => __('Order by hand', 'seoprostack'),
                'description' => __('Drag items into the order you want in the lists of chosen post types and categories. The site shows them in that order wherever no other order is set.', 'seoprostack'),
                'replaces'    => array('simple-custom-post-order' => 'Simple Custom Post Order'),
            ),
            'hand_order_types' => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array('page'),
                'parent'  => self::KEY,
                'label'   => __('Post types', 'seoprostack'),
                'options' => array(__CLASS__, 'post_type_options'),
            ),
            'hand_order_taxonomies' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Categories and tags', 'seoprostack'),
                'description' => __('WooCommerce sorts its own product categories and attributes.', 'seoprostack'),
                'options'     => array(__CLASS__, 'taxonomy_options'),
            ),
        );
    }

    /**
     * Post types with an admin list.
     *
     * @return array<string,string>
     */
    public static function post_type_options() {
        $options = array();
        foreach (get_post_types(array('show_ui' => true), 'objects') as $type) {
            if ('attachment' === $type->name || 0 === strpos($type->name, 'wp_')) {
                continue;
            }
            $options[$type->name] = $type->labels->name;
        }
        return $options;
    }

    /**
     * Taxonomies with an admin list, leaving out those WooCommerce sorts.
     *
     * @return array<string,string>
     */
    public static function taxonomy_options() {
        $options = array();
        foreach (get_taxonomies(array('show_ui' => true), 'objects') as $taxonomy) {
            if (self::woo_sorted($taxonomy->name) || 'post_format' === $taxonomy->name || 0 === strpos($taxonomy->name, 'wp_')) {
                continue;
            }
            $options[$taxonomy->name] = $taxonomy->labels->name;
        }
        return $options;
    }

    /**
     * Whether WooCommerce sorts a taxonomy itself.
     *
     * @param string $taxonomy Taxonomy.
     * @return bool
     */
    private static function woo_sorted($taxonomy) {
        return 'product_cat' === $taxonomy || 0 === strpos($taxonomy, 'pa_');
    }

    /**
     * Import Simple Custom Post Order's choices and term order.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $theirs = get_option('scporder_options');
        if (!is_array($theirs)) {
            return $options;
        }
        $types = isset($theirs['objects']) ? array_values(array_filter((array) $theirs['objects'], 'is_string')) : array();
        $taxes = isset($theirs['tags']) ? array_values(array_filter((array) $theirs['tags'], function ($tax) {
            return is_string($tax) && !self::woo_sorted($tax);
        })) : array();
        if (!$types && !$taxes) {
            return $options;
        }
        $options = self::import_setting($options, self::KEY, true);
        $options = self::import_setting($options, 'hand_order_types', $types);
        $options = self::import_setting($options, 'hand_order_taxonomies', $taxes);
        self::import_term_order($taxes);
        return $options;
    }

    /**
     * Copy Simple Custom Post Order's term order into term meta, for terms
     * that have no place here yet. Its column is only read.
     *
     * @param string[] $taxonomies Taxonomies.
     */
    private static function import_term_order(array $taxonomies) {
        global $wpdb;
        if (!$taxonomies) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off check during the settings upgrade.
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$wpdb->terms} LIKE %s", 'term_order'));
        if (!$column) {
            return;
        }
        $in = implode(',', array_fill(0, count($taxonomies), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-off import; placeholders built above.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT t.term_id, t.term_order FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ($in) AND t.term_order <> 0", $taxonomies));
        foreach ((array) $rows as $row) {
            if ('' === (string) get_term_meta((int) $row->term_id, self::TERM_META, true)) {
                update_term_meta((int) $row->term_id, self::TERM_META, (int) $row->term_order);
            }
        }
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('pre_get_posts', array(__CLASS__, 'pre_get_posts'));
        add_filter('terms_clauses', array(__CLASS__, 'terms_clauses'), 10, 3);
        if (is_admin()) {
            add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
            add_action('load-edit.php', array(__CLASS__, 'load_posts_screen'));
            add_action('load-edit-tags.php', array(__CLASS__, 'load_terms_screen'));
        }
    }

    /**
     * Whether a post type is ordered by hand.
     *
     * @param string $type Post type.
     * @return bool
     */
    public static function type_enabled($type) {
        return is_string($type) && in_array($type, (array) SEOProStack_Settings::get('hand_order_types'), true);
    }

    /**
     * Whether a taxonomy is ordered by hand.
     *
     * @param string $taxonomy Taxonomy.
     * @return bool
     */
    public static function taxonomy_enabled($taxonomy) {
        return is_string($taxonomy) && !self::woo_sorted($taxonomy) && in_array($taxonomy, (array) SEOProStack_Settings::get('hand_order_taxonomies'), true);
    }

    /* --------------------------------------------------------------------- */
    /* Queries                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Queries of one hand-ordered type that set no order follow the hand
     * order. Searches on the site keep their relevance order.
     *
     * The admin list follows it whenever the person has not sorted by a
     * column, including searches and the Drafts and Pending views (which
     * core sorts by date changed), so the rows shown with drag handles are
     * always in the order a move saves.
     *
     * @param WP_Query $query Query.
     */
    public static function pre_get_posts($query) {
        if (is_admin() && $query->is_main_query()) {
            if (!self::list_in_hand_order()) {
                return;
            }
        } elseif (!empty($query->query['orderby']) || '' !== trim((string) $query->get('s'))) {
            // Not is_search(): the list's Filter button sends an empty search,
            // which core counts as one.
            return;
        }
        $type = $query->get('post_type');
        if (is_array($type)) {
            $type = 1 === count($type) ? reset($type) : '';
        }
        if (!$type && ($query->is_category() || $query->is_tag() || $query->is_home())) {
            $type = 'post';
        }
        if (!self::type_enabled($type)) {
            return;
        }
        $hierarchical = is_post_type_hierarchical($type);
        $query->set('orderby', $hierarchical ? array('menu_order' => 'ASC', 'title' => 'ASC') : array('menu_order' => 'ASC', 'date' => 'DESC'));
    }

    /**
     * Term lists of one hand-ordered taxonomy, ordered by name (the default),
     * follow the hand order. Terms without a place come first, by name.
     *
     * @param array    $clauses    Query clauses.
     * @param string[] $taxonomies Taxonomies.
     * @param array    $args       get_terms() arguments.
     * @return array
     */
    public static function terms_clauses($clauses, $taxonomies, $args) {
        global $wpdb;
        if (1 !== count((array) $taxonomies) || !self::taxonomy_enabled(reset($taxonomies))) {
            return $clauses;
        }
        if (empty($args['orderby']) || 'name' !== $args['orderby'] || (isset($args['fields']) && 'count' === $args['fields'])) {
            return $clauses;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sorting state.
        if (is_admin() && !wp_doing_ajax() && !empty($_GET['orderby'])) {
            return $clauses;
        }
        if (false === strpos((string) $clauses['orderby'], 't.name')) {
            return $clauses;
        }
        $clauses['join']   .= $wpdb->prepare(" LEFT JOIN {$wpdb->termmeta} AS sps_order ON sps_order.term_id = t.term_id AND sps_order.meta_key = %s", self::TERM_META);
        $clauses['orderby'] = 'ORDER BY CAST(COALESCE(sps_order.meta_value, 0) AS SIGNED) ASC, t.name';
        return $clauses;
    }

    /* --------------------------------------------------------------------- */
    /* Admin lists                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Posts list of a hand-ordered type.
     */
    public static function load_posts_screen() {
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection.
        $object = get_post_type_object($type);
        if (!self::type_enabled($type) || !$object || !current_user_can($object->cap->edit_others_posts)) {
            return;
        }
        add_filter("manage_{$type}_posts_columns", array(__CLASS__, 'add_column'));
        add_action("manage_{$type}_posts_custom_column", array(__CLASS__, 'post_column'), 10, 2);
        add_filter("views_edit-{$type}", array(__CLASS__, 'add_view'));
        self::enqueue('post', $type);
    }

    /**
     * Terms list of a hand-ordered taxonomy.
     */
    public static function load_terms_screen() {
        $taxonomy = isset($_GET['taxonomy']) ? sanitize_key(wp_unslash($_GET['taxonomy'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection.
        $object   = get_taxonomy($taxonomy);
        if (!self::taxonomy_enabled($taxonomy) || !$object || !current_user_can($object->cap->manage_terms)) {
            return;
        }
        add_filter("manage_edit-{$taxonomy}_columns", array(__CLASS__, 'add_column'));
        add_filter("manage_{$taxonomy}_custom_column", array(__CLASS__, 'term_column'), 10, 3);
        add_filter("views_edit-{$taxonomy}", array(__CLASS__, 'add_view'));
        self::enqueue('term', $taxonomy);
    }

    /**
     * Whether the list shows the hand order: not sorted by a column.
     * Searches and filters still do; a move only swaps the rows shown.
     *
     * @return bool
     */
    private static function list_in_hand_order() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list state.
        return empty($_GET['orderby']);
    }

    /**
     * The list's address in the hand order: the same filters, search and
     * view, without the column sorting (and back to the first page).
     *
     * @return string
     */
    private static function hand_order_url() {
        return remove_query_arg(array('orderby', 'order', 'paged'));
    }

    /**
     * While the list is sorted by a column, a "Custom order" view leads back
     * to the hand order, where rows can be moved again.
     *
     * @param array $views Views.
     * @return array
     */
    public static function add_view($views) {
        if (self::list_in_hand_order()) {
            return $views;
        }
        $views = (array) $views;
        $views['seoprostack_order'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url(self::hand_order_url()),
            esc_html__('Custom order', 'seoprostack')
        );
        return $views;
    }

    /**
     * Handle column after the checkbox. While the list is sorted by a
     * column, its header links back to the hand order instead.
     *
     * @param array $columns Columns.
     * @return array
     */
    public static function add_column($columns) {
        if (self::list_in_hand_order()) {
            $label = '<span class="screen-reader-text">' . esc_html__('Move', 'seoprostack') . '</span>';
        } else {
            $label = sprintf(
                '<a href="%1$s" class="seoprostack-order-back" title="%2$s"><span class="dashicons dashicons-menu" aria-hidden="true"></span><span class="screen-reader-text">%3$s</span></a>',
                esc_url(self::hand_order_url()),
                esc_attr__('Back to custom order, to move items', 'seoprostack'),
                esc_html__('Back to custom order, to move items', 'seoprostack')
            );
        }
        $new = array();
        foreach ($columns as $key => $value) {
            if ('cb' !== $key && !isset($new['seoprostack_order'])) {
                $new['seoprostack_order'] = $label;
            }
            $new[$key] = $value;
            if ('cb' === $key) {
                $new['seoprostack_order'] = $label;
            }
        }
        return $new;
    }

    /**
     * Drag handle button.
     *
     * @param string $title Item title.
     * @return string
     */
    private static function handle($title) {
        /* translators: %s: item title */
        $label = sprintf(__('Move “%s”. Drag, or use the up and down arrow keys.', 'seoprostack'), $title);
        return '<button type="button" class="button-link seoprostack-order-handle" aria-label="' . esc_attr($label) . '" title="' . esc_attr__('Drag to reorder', 'seoprostack') . '"><span class="dashicons dashicons-menu" aria-hidden="true"></span></button>';
    }

    /**
     * Posts column.
     *
     * @param string $column  Column.
     * @param int    $post_id Post ID.
     */
    public static function post_column($column, $post_id) {
        if ('seoprostack_order' === $column && self::list_in_hand_order()) {
            echo self::handle(get_the_title($post_id)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in handle().
        }
    }

    /**
     * Terms column.
     *
     * @param string $output  Output.
     * @param string $column  Column.
     * @param int    $term_id Term ID.
     * @return string
     */
    public static function term_column($output, $column, $term_id) {
        if ('seoprostack_order' !== $column || !self::list_in_hand_order()) {
            return $output;
        }
        $term = get_term($term_id);
        return self::handle($term instanceof WP_Term ? $term->name : '');
    }

    /**
     * Script and styles for a list.
     *
     * @param string $kind   post or term.
     * @param string $object Post type or taxonomy.
     */
    private static function enqueue($kind, $object) {
        if (!self::list_in_hand_order()) {
            add_action('admin_enqueue_scripts', function () {
                wp_add_inline_style('list-tables', '
                    ' . self::COLUMN_STYLE . '
                    .wp-core-ui .seoprostack-order-back { color: #8c8f94; }
                    .wp-core-ui .seoprostack-order-back:hover, .wp-core-ui .seoprostack-order-back:focus { color: #2271b1; }
                ');
            });
            return;
        }
        add_action('admin_enqueue_scripts', function () use ($kind, $object) {
            wp_enqueue_script('seoprostack-hand-order', SEOPROSTACK_URL . 'admin/js/seoprostack-hand-order.js', array('jquery', 'jquery-ui-sortable', 'wp-a11y'), SEOPROSTACK_VERSION, true);
            wp_localize_script('seoprostack-hand-order', 'seoprostackHandOrder', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action'  => self::AJAX,
                'nonce'   => wp_create_nonce(self::AJAX),
                'kind'    => $kind,
                'object'  => $object,
                'saved'   => __('Order saved.', 'seoprostack'),
                'failed'  => __('Could not save the order. Reload the page and try again.', 'seoprostack'),
            ));
            wp_add_inline_style('list-tables', '
                ' . self::COLUMN_STYLE . '
                .wp-core-ui .seoprostack-order-handle { cursor: grab; color: #8c8f94; padding: 0; }
                .wp-core-ui .seoprostack-order-handle:hover, .wp-core-ui .seoprostack-order-handle:focus { color: #2271b1; }
                .wp-core-ui .seoprostack-order-handle:focus { box-shadow: 0 0 0 2px #2271b1; border-radius: 2px; outline: none; }
                #the-list tr.ui-sortable-helper { background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
                #the-list tr.seoprostack-order-placeholder td { background: #f0f6fc; border: 1px dashed #72aee6; }
                #the-list.seoprostack-order-busy { opacity: .6; }
            ');
        });
    }

    /**
     * AJAX: save a new order for the rows shown.
     */
    public static function ajax() {
        check_ajax_referer(self::AJAX, 'nonce');
        $kind   = isset($_POST['kind']) ? sanitize_key(wp_unslash($_POST['kind'])) : '';
        $object = isset($_POST['object']) ? sanitize_key(wp_unslash($_POST['object'])) : '';
        $ids    = isset($_POST['ids']) ? array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['ids']))))) : array();

        if ('post' === $kind) {
            $type = get_post_type_object($object);
            if (!self::type_enabled($object) || !$type || !current_user_can($type->cap->edit_others_posts)) {
                wp_send_json_error(array('message' => __('You cannot reorder these items.', 'seoprostack')), 403);
            }
            self::save_post_order($object, $ids);
        } elseif ('term' === $kind) {
            $taxonomy = get_taxonomy($object);
            if (!self::taxonomy_enabled($object) || !$taxonomy || !current_user_can($taxonomy->cap->manage_terms)) {
                wp_send_json_error(array('message' => __('You cannot reorder these items.', 'seoprostack')), 403);
            }
            self::save_term_order($object, $ids);
        } else {
            wp_send_json_error(null, 400);
        }
        wp_send_json_success();
    }

    /**
     * Put the given IDs, in their new order, into the places they held in
     * the full list.
     *
     * @param int[] $all   Every ID in the current order.
     * @param int[] $moved IDs shown, in their new order.
     * @return int[] Every ID in the new order.
     */
    public static function permute(array $all, array $moved) {
        $positions = array_flip($all);
        $moved     = array_values(array_filter($moved, function ($id) use ($positions) {
            return isset($positions[$id]);
        }));
        $slots = array();
        foreach ($moved as $id) {
            $slots[] = $positions[$id];
        }
        sort($slots);
        foreach ($slots as $i => $slot) {
            $all[$slot] = $moved[$i];
        }
        return $all;
    }

    /**
     * Save a post type's order: menu_order 1…n, written only where it changes.
     *
     * @param string $type Post type.
     * @param int[]  $ids  IDs shown, in their new order.
     */
    private static function save_post_order($type, array $ids) {
        global $wpdb;
        // The whole list's order, as the list shows it.
        if (is_post_type_hierarchical($type)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads every row's place; not cached.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, menu_order FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY menu_order ASC, post_title ASC, ID ASC", $type));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads every row's place; not cached.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, menu_order FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY menu_order ASC, post_date DESC, ID ASC", $type));
        }
        $all     = array_map('intval', wp_list_pluck($rows, 'ID'));
        $current = array_combine($all, array_map('intval', wp_list_pluck($rows, 'menu_order')));
        $changed = array();
        foreach (self::permute($all, $ids) as $i => $id) {
            if ($current[$id] !== $i + 1) {
                $changed[$id] = $i + 1;
            }
        }
        foreach (array_chunk($changed, self::BATCH, true) as $chunk) {
            $args = array();
            foreach ($chunk as $id => $place) {
                $args[] = $id;
                $args[] = $place;
            }
            $args = array_merge($args, array_keys($chunk));
            // One query per batch: the first save of a list numbers every row.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one placeholder per value, built from counts only.
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET menu_order = CASE ID" . str_repeat(' WHEN %d THEN %d', count($chunk)) . ' END WHERE ID IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')', $args));
        }
        foreach (array_keys($changed) as $id) {
            clean_post_cache($id);
        }
        /**
         * Items were reordered by hand.
         *
         * @param string $kind   post or term.
         * @param string $object Post type or taxonomy.
         * @param int[]  $ids    IDs whose place changed.
         */
        do_action('seoprostack_hand_ordered', 'post', $type, array_keys($changed));
    }

    /**
     * Save a taxonomy's order in term meta, written only where it changes.
     *
     * @param string $taxonomy Taxonomy.
     * @param int[]  $ids      Term IDs shown, in their new order.
     */
    private static function save_term_order($taxonomy, array $ids) {
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
            'fields'     => 'ids',
        ));
        if (is_wp_error($terms)) {
            wp_send_json_error(null, 400);
        }
        $all     = array_map('intval', $terms);
        $changed = array();
        foreach (self::permute($all, $ids) as $i => $id) {
            if ((int) get_term_meta($id, self::TERM_META, true) !== $i + 1) {
                update_term_meta($id, self::TERM_META, $i + 1);
                $changed[] = $id;
            }
        }
        /** This action is documented in save_post_order(). */
        do_action('seoprostack_hand_ordered', 'term', $taxonomy, $changed);
    }
}
