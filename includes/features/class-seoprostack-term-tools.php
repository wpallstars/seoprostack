<?php
/**
 * Term tools.
 *
 * Three bulk actions on category, tag and other term lists:
 * - Merge into: the chosen terms become one (an existing term by name, or a
 *   new one). Their posts and child terms move to it.
 * - Move to taxonomy: the terms, and the terms below them, become terms of
 *   another taxonomy with their posts, meta and IDs.
 * - Set parent (hierarchical taxonomies).
 *
 * Old term archive addresses (from a merge or a move) redirect with a 301 to
 * the term that took over, when they would otherwise be a 404.
 *
 * Replaces Term Management Tools, which has no settings.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Term_Tools extends SEOProStack_Feature {

    const KEY = 'term_tools';

    /** Term meta: old "taxonomy:slug" addresses that now lead to this term. */
    const OLD = '_seoprostack_old_term';

    /** Notice query argument. */
    const NOTICE = 'seoprostack_terms';

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
                'label'       => __('Term tools', 'seoprostack'),
                'description' => __('Merge categories or tags, move them to another taxonomy, or set their parent, from the Bulk actions menu. Old addresses of merged and moved terms redirect to the new ones.', 'seoprostack'),
                'replaces'    => array('term-management-tools' => 'Term Management Tools'),
            ),
        );
    }

    /**
     * Switch on while Term Management Tools is active (it has no settings).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['term-management-tools']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        if (is_admin()) {
            add_action('load-edit-tags.php', array(__CLASS__, 'load'));
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'redirect_old'), 9);
    }

    /**
     * Term list screen.
     */
    public static function load() {
        $taxonomy = isset($_REQUEST['taxonomy']) ? sanitize_key(wp_unslash($_REQUEST['taxonomy'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection.
        $object   = get_taxonomy($taxonomy);
        if (!$object || !current_user_can($object->cap->manage_terms)) {
            return;
        }
        add_filter("bulk_actions-edit-{$taxonomy}", array(__CLASS__, 'bulk_actions'));
        add_filter("handle_bulk_actions-edit-{$taxonomy}", array(__CLASS__, 'handle'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_footer', array(__CLASS__, 'fields'));
    }

    /**
     * Taxonomies the current user can move terms to.
     *
     * @param string $from Current taxonomy.
     * @return array<string,string>
     */
    private static function targets($from) {
        $targets = array();
        foreach (get_taxonomies(array('show_ui' => true), 'objects') as $taxonomy) {
            if ($taxonomy->name === $from || 'post_format' === $taxonomy->name || 0 === strpos($taxonomy->name, 'wp_') || !current_user_can($taxonomy->cap->manage_terms)) {
                continue;
            }
            $targets[$taxonomy->name] = $taxonomy->labels->singular_name . ' (' . $taxonomy->name . ')';
        }
        return $targets;
    }

    /**
     * Bulk actions.
     *
     * @param array $actions Actions.
     * @return array
     */
    public static function bulk_actions($actions) {
        $screen = get_current_screen();
        $actions['seoprostack_merge'] = __('Merge into…', 'seoprostack');
        if ($screen && self::targets($screen->taxonomy)) {
            $actions['seoprostack_move'] = __('Move to taxonomy…', 'seoprostack');
        }
        if ($screen && is_taxonomy_hierarchical($screen->taxonomy)) {
            $actions['seoprostack_parent'] = __('Set parent…', 'seoprostack');
        }
        return $actions;
    }

    /**
     * Fields for the chosen action, shown next to the Bulk actions menu.
     */
    public static function fields() {
        $screen = get_current_screen();
        if (!$screen || 'edit-tags' !== $screen->base) {
            return;
        }
        $taxonomy = $screen->taxonomy;
        $parent   = '';
        if (is_taxonomy_hierarchical($taxonomy)) {
            $parent = wp_dropdown_categories(array(
                'taxonomy'         => $taxonomy,
                'hide_empty'       => 0,
                'hierarchical'     => 1,
                'name'             => 'seoprostack_parent',
                'id'               => 'seoprostack-term-parent',
                'show_option_none' => __('None', 'seoprostack'),
                'option_none_value' => '0',
                'orderby'          => 'name',
                'echo'             => 0,
            ));
        }
        $move = '';
        foreach (self::targets($taxonomy) as $name => $label) {
            $move .= '<option value="' . esc_attr($name) . '">' . esc_html($label) . '</option>';
        }
        $html = '<span class="seoprostack-term-tool" data-action="seoprostack_merge" hidden>'
            . '<label class="screen-reader-text" for="seoprostack-term-merge">' . esc_html__('Name of the term to merge into', 'seoprostack') . '</label>'
            . '<input type="text" id="seoprostack-term-merge" name="seoprostack_merge_into" placeholder="' . esc_attr__('Merge into (name)', 'seoprostack') . '" /></span>'
            . ($move ? '<span class="seoprostack-term-tool" data-action="seoprostack_move" hidden><label class="screen-reader-text" for="seoprostack-term-move">' . esc_html__('Taxonomy to move to', 'seoprostack') . '</label><select id="seoprostack-term-move" name="seoprostack_move_to">' . $move . '</select></span>' : '')
            . ($parent ? '<span class="seoprostack-term-tool" data-action="seoprostack_parent" hidden><label class="screen-reader-text" for="seoprostack-term-parent">' . esc_html__('New parent', 'seoprostack') . '</label>' . $parent . '</span>' : '');
        ?>
        <script>
        (function () {
            var select = document.getElementById('bulk-action-selector-top');
            if (!select) { return; }
            var holder = document.createElement('span');
            holder.className = 'seoprostack-term-tools';
            holder.innerHTML = <?php echo wp_json_encode($html); ?>;
            select.parentNode.insertBefore(holder, select.nextSibling);
            function sync() {
                holder.querySelectorAll('.seoprostack-term-tool').forEach(function (tool) {
                    tool.hidden = tool.getAttribute('data-action') !== select.value;
                    tool.querySelectorAll('input, select').forEach(function (field) { field.disabled = tool.hidden; });
                });
            }
            select.addEventListener('change', sync);
            sync();
            // The bottom menu has no fields: use the top one.
            var bottom = document.getElementById('bulk-action-selector-bottom');
            if (bottom) {
                bottom.querySelectorAll('option[value^="seoprostack_"]').forEach(function (o) { o.remove(); });
            }
        })();
        </script>
        <style>.seoprostack-term-tools input, .seoprostack-term-tools select { margin: 0 6px 0 0; max-width: 14em; vertical-align: middle; }</style>
        <?php
    }

    /**
     * Run a bulk action. Core has checked the bulk-tags nonce.
     *
     * @param string $location Redirect address.
     * @param string $action   Action.
     * @param array  $term_ids Term IDs.
     * @return string
     */
    public static function handle($location, $action, $term_ids) {
        if (0 !== strpos((string) $action, 'seoprostack_')) {
            return $location;
        }
        check_admin_referer('bulk-tags');
        $screen   = get_current_screen();
        $taxonomy = $screen ? $screen->taxonomy : '';
        $object   = get_taxonomy($taxonomy);
        if (!$object || !current_user_can($object->cap->manage_terms)) {
            wp_die(esc_html__('You cannot change these terms.', 'seoprostack'), '', array('response' => 403));
        }
        $term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $term_ids))));
        $location = remove_query_arg(array(self::NOTICE), $location);
        if (!$term_ids) {
            return $location;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- checked above.
        switch ($action) {
            case 'seoprostack_merge':
                $name   = isset($_REQUEST['seoprostack_merge_into']) ? sanitize_text_field(wp_unslash($_REQUEST['seoprostack_merge_into'])) : '';
                $result = self::merge($taxonomy, $term_ids, $name);
                break;
            case 'seoprostack_move':
                $to     = isset($_REQUEST['seoprostack_move_to']) ? sanitize_key(wp_unslash($_REQUEST['seoprostack_move_to'])) : '';
                $result = self::move($taxonomy, $term_ids, $to);
                break;
            case 'seoprostack_parent':
                $parent = isset($_REQUEST['seoprostack_parent']) ? absint($_REQUEST['seoprostack_parent']) : 0;
                $result = self::set_parent($taxonomy, $term_ids, $parent);
                break;
            default:
                return $location;
        }
        // phpcs:enable
        return add_query_arg(self::NOTICE, rawurlencode($result), $location);
    }

    /**
     * Merge terms into one, by name.
     *
     * @param string $taxonomy Taxonomy.
     * @param int[]  $term_ids Terms to merge.
     * @param string $name     Name of the term they become.
     * @return string Notice code.
     */
    public static function merge($taxonomy, array $term_ids, $name) {
        if ('' === $name) {
            return 'merge_name';
        }
        $target = get_term_by('name', $name, $taxonomy);
        if (!$target) {
            // A name that only matches by slug, such as different capitals.
            $target = get_term_by('slug', sanitize_title($name), $taxonomy);
        }
        if (!$target) {
            $parents = array();
            foreach ($term_ids as $id) {
                $term      = get_term($id, $taxonomy);
                $parents[] = $term instanceof WP_Term ? (int) $term->parent : 0;
            }
            $parents = array_unique($parents);
            $created = wp_insert_term($name, $taxonomy, array('parent' => 1 === count($parents) && !in_array(reset($parents), $term_ids, true) ? reset($parents) : 0));
            if (is_wp_error($created)) {
                return 'failed';
            }
            $target = get_term((int) $created['term_id'], $taxonomy);
        }
        if (!$target instanceof WP_Term) {
            return 'failed';
        }

        $merged = 0;
        foreach ($term_ids as $id) {
            $term = get_term($id, $taxonomy);
            if (!$term instanceof WP_Term || $term->term_id === $target->term_id) {
                continue;
            }
            // Children move to the merged term, not to the old term's parent.
            if (is_taxonomy_hierarchical($taxonomy)) {
                foreach (get_terms(array('taxonomy' => $taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids')) as $child) {
                    if ((int) $child !== $target->term_id) {
                        wp_update_term((int) $child, $taxonomy, array('parent' => $target->term_id));
                    }
                }
                if ((int) $target->parent === $term->term_id) {
                    wp_update_term($target->term_id, $taxonomy, array('parent' => (int) $term->parent));
                }
            }
            $old = array_merge(array($taxonomy . ':' . $term->slug), (array) get_term_meta($term->term_id, self::OLD, false));
            $deleted = wp_delete_term($term->term_id, $taxonomy, array('default' => $target->term_id, 'force_default' => true));
            if (true !== $deleted) {
                continue;
            }
            self::remember_old($target->term_id, $old);
            /**
             * A term was merged into another.
             *
             * @param WP_Term $target The term it was merged into.
             * @param WP_Term $term   The merged term (deleted).
             */
            do_action('seoprostack_term_merged', $target, $term);
            ++$merged;
        }
        return 'merged:' . $merged;
    }

    /**
     * Move terms, and the terms below them, to another taxonomy.
     *
     * @param string $from     Current taxonomy.
     * @param int[]  $term_ids Terms.
     * @param string $to       New taxonomy.
     * @return string Notice code.
     */
    public static function move($from, array $term_ids, $to) {
        global $wpdb;
        $targets = self::targets($from);
        if (!isset($targets[$to])) {
            return 'failed';
        }
        $ids = array();
        foreach ($term_ids as $id) {
            $ids[] = (int) $id;
            if (is_taxonomy_hierarchical($from)) {
                $ids = array_merge($ids, array_map('intval', get_term_children((int) $id, $from)));
            }
        }
        $ids     = array_values(array_unique($ids));
        $moved   = 0;
        $skipped = 0;
        $keep    = is_taxonomy_hierarchical($from) && is_taxonomy_hierarchical($to);
        foreach ($ids as $id) {
            $term = get_term($id, $from);
            if (!$term instanceof WP_Term) {
                continue;
            }
            if (get_term_by('slug', $term->slug, $to)) {
                ++$skipped;
                continue;
            }
            $parent = $keep && in_array((int) $term->parent, $ids, true) ? (int) $term->parent : 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- core has no API to change a term's taxonomy.
            $wpdb->update($wpdb->term_taxonomy, array('taxonomy' => $to, 'parent' => $parent), array('term_taxonomy_id' => $term->term_taxonomy_id));
            self::remember_old($term->term_id, array($from . ':' . $term->slug));
            ++$moved;
        }
        clean_term_cache($ids, $from);
        clean_term_cache($ids, $to);
        delete_option("{$from}_children");
        delete_option("{$to}_children");
        foreach (array($from, $to) as $taxonomy) {
            $tt_ids = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'tt_ids'));
            if (!is_wp_error($tt_ids) && $tt_ids) {
                wp_update_term_count_now(array_map('intval', $tt_ids), $taxonomy);
            }
        }
        /**
         * Terms were moved to another taxonomy.
         *
         * @param int[]  $ids  Term IDs, with the terms below them.
         * @param string $to   New taxonomy.
         * @param string $from Old taxonomy.
         */
        do_action('seoprostack_terms_moved', $ids, $to, $from);
        return 'moved:' . $moved . ':' . $skipped;
    }

    /**
     * Give terms a new parent.
     *
     * @param string $taxonomy Taxonomy.
     * @param int[]  $term_ids Terms.
     * @param int    $parent   New parent, or 0.
     * @return string Notice code.
     */
    public static function set_parent($taxonomy, array $term_ids, $parent) {
        if (!is_taxonomy_hierarchical($taxonomy)) {
            return 'failed';
        }
        $parent = max(0, (int) $parent);
        if ($parent) {
            $term = get_term($parent, $taxonomy);
            if (!$term instanceof WP_Term) {
                return 'failed';
            }
            // A term cannot go below itself or one of its own children.
            $ancestors = array_merge(array($parent), array_map('intval', get_ancestors($parent, $taxonomy, 'taxonomy')));
            if (array_intersect($ancestors, $term_ids)) {
                return 'parent_loop';
            }
        }
        $done = 0;
        foreach ($term_ids as $id) {
            if (!is_wp_error(wp_update_term((int) $id, $taxonomy, array('parent' => $parent)))) {
                ++$done;
            }
        }
        return 'parent:' . $done;
    }

    /**
     * Remember old addresses that now lead to a term.
     *
     * @param int      $term_id Term ID.
     * @param string[] $old     "taxonomy:slug" values.
     */
    private static function remember_old($term_id, array $old) {
        $known = (array) get_term_meta($term_id, self::OLD, false);
        foreach (array_unique($old) as $value) {
            if ('' !== $value && !in_array($value, $known, true)) {
                add_term_meta($term_id, self::OLD, $value);
            }
        }
    }

    /**
     * Notice after an action.
     */
    public static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $code = isset($_GET[self::NOTICE]) ? sanitize_text_field(wp_unslash($_GET[self::NOTICE])) : '';
        if ('' === $code) {
            return;
        }
        $parts = explode(':', $code);
        $class = 'notice-success';
        switch ($parts[0]) {
            case 'merged':
                /* translators: %d: number of terms */
                $text = sprintf(_n('%d term merged.', '%d terms merged.', (int) $parts[1], 'seoprostack'), (int) $parts[1]);
                break;
            case 'moved':
                /* translators: %d: number of terms */
                $text = sprintf(_n('%d term moved.', '%d terms moved.', (int) $parts[1], 'seoprostack'), (int) $parts[1]);
                if (!empty($parts[2])) {
                    $class = 'notice-warning';
                    /* translators: %d: number of terms */
                    $text .= ' ' . sprintf(_n('%d was left where it was: the other taxonomy already has a term with its slug. Merge them first.', '%d were left where they were: the other taxonomy already has terms with their slugs. Merge them first.', (int) $parts[2], 'seoprostack'), (int) $parts[2]);
                }
                break;
            case 'parent':
                /* translators: %d: number of terms */
                $text = sprintf(_n('Parent set for %d term.', 'Parent set for %d terms.', (int) $parts[1], 'seoprostack'), (int) $parts[1]);
                break;
            case 'merge_name':
                $class = 'notice-error';
                $text  = __('Type the name of the term to merge into.', 'seoprostack');
                break;
            case 'parent_loop':
                $class = 'notice-error';
                $text  = __('A term cannot be placed below itself or below one of its own children.', 'seoprostack');
                break;
            default:
                $class = 'notice-error';
                $text  = __('Nothing was changed. Please try again.', 'seoprostack');
        }
        printf('<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr($class), esc_html($text));
    }

    /**
     * Send an old term archive address to the term that took it over.
     */
    public static function redirect_old() {
        global $wp, $wpdb;
        if (!is_404() || empty($wp->query_vars)) {
            return;
        }
        $wanted = array();
        foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) {
            $var = 'category' === $taxonomy->name ? 'category_name' : ('post_tag' === $taxonomy->name ? 'tag' : $taxonomy->query_var);
            if ($var && !empty($wp->query_vars[$var]) && is_string($wp->query_vars[$var])) {
                $parts    = explode('/', trim($wp->query_vars[$var], '/'));
                $wanted[] = $taxonomy->name . ':' . sanitize_title(end($parts));
            }
        }
        if (!$wanted) {
            return;
        }
        $in = implode(',', array_fill(0, count($wanted), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- only on 404s; placeholders built above.
        $term_id = (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value IN ($in) LIMIT 1", array_merge(array(self::OLD), $wanted)));
        if (!$term_id) {
            return;
        }
        $term = get_term($term_id);
        if (!$term instanceof WP_Term) {
            return;
        }
        $link = get_term_link($term);
        if (!is_wp_error($link)) {
            wp_safe_redirect($link, 301, 'SEO Pro Stack');
            exit;
        }
    }
}
