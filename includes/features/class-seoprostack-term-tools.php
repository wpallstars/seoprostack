<?php
/**
 * Term tools.
 *
 * Bulk actions on category, tag and other term lists:
 * - Merge into: the chosen terms become one (an existing term by name, or a
 *   new one). Their posts and child terms move to it.
 * - Move to taxonomy: the terms, and the terms below them, become terms of
 *   another taxonomy with their posts, meta and IDs.
 * - Set parent (hierarchical taxonomies).
 * - Apply the taxonomy's slug prefix and suffix to existing terms.
 *
 * An "Unused" link above the list shows only terms with no posts, ready for
 * the core Delete bulk action (the clean-up TaxoPress' Manage Terms offers).
 *
 * Old term archive addresses (from a merge, move or slug pattern) redirect with a 301 to
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

    /** Patterns live in the shared settings option, which uninstall removes. */
    const PATTERNS = 'term_tools_slug_patterns';

    /** @var array<int,bool> Terms currently being updated by a pattern. */
    private static $applying = array();

    /** Query argument: show only unused terms. */
    const UNUSED = 'seoprostack_unused';

    /** @var string Taxonomy of the term list screen. */
    private static $screen_taxonomy = '';

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
                'description' => __('Merge categories or tags, move them to another taxonomy, set their parent or apply slug patterns from the Bulk actions menu, and list the unused ones to delete them. Old addresses of changed terms redirect to the new ones. Set slug prefixes and suffixes in Options.', 'seoprostack'),
                'replaces'    => array('term-management-tools' => 'Term Management Tools'),
                // The slug pattern table is this feature's own panel.
                'panel'       => true,
            ),
            self::PATTERNS => array(
                'type'    => 'lines',
                'default' => '',
                'parent'  => self::KEY,
                'hidden'  => true,
                'label'   => __('Term slug patterns', 'seoprostack'),
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
        // Settings remain editable even while the feature is switched off.
        add_action('seoprostack_setting_panel', array(__CLASS__, 'pattern_fields'), 10, 2);
        add_action('admin_post_seoprostack_term_patterns', array(__CLASS__, 'save_patterns'));
        if (!self::enabled()) {
            return;
        }
        // Generic term hooks also cover taxonomies registered later in init,
        // REST requests, imports and WP-CLI, not just wp-admin term screens.
        add_action('created_term', array(__CLASS__, 'term_changed'), 10, 3);
        add_action('edited_term', array(__CLASS__, 'term_changed'), 10, 3);
        if (is_admin()) {
            add_action('load-edit-tags.php', array(__CLASS__, 'load'));
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'redirect_old'), 9);
    }

    /**
     * Read patterns without depending on when custom taxonomies register.
     *
     * @return array<string,array<string,string>>
     */
    private static function patterns() {
        $patterns = json_decode((string) SEOProStack_Settings::get(self::PATTERNS), true);
        return is_array($patterns) ? $patterns : array();
    }

    /**
     * Apply the pattern after core finishes creating or editing a term.
     *
     * @param int    $term_id Term ID.
     * @param int    $tt_id   Term taxonomy ID.
     * @param string $taxonomy Taxonomy.
     */
    public static function term_changed($term_id, $tt_id, $taxonomy) {
        self::apply_pattern($term_id, $tt_id, $taxonomy);
    }

    /**
     * Normalise an affix, keeping the hyphen next to the original slug.
     *
     * @param mixed $value Prefix or suffix.
     * @param string $part Prefix or suffix field.
     * @return string
     */
    private static function affix($value, $part) {
        if (!is_string($value) || '' === $value) {
            return '';
        }
        $value = trim($value);
        // Core trims the outer slug edges, so preserve only the inner hyphen.
        $slug = sanitize_title($value);
        return '' === $slug ? '' : ('suffix' === $part && '-' === substr($value, 0, 1) ? '-' : '') . $slug . ('prefix' === $part && '-' === substr($value, -1) ? '-' : '');
    }

    /**
     * Slug prefix and suffix per public taxonomy, in Term tools' Options.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function pattern_fields($key, $field = array()) {
        if (self::KEY !== $key || !SEOProStack_Settings::can_change()) {
            return;
        }
        $patterns = self::patterns();
        ?>
        <div class="sps-panel-note sps-term-patterns">
            <p><strong><?php esc_html_e('Slug patterns', 'seoprostack'); ?></strong></p>
            <p class="description"><?php esc_html_e('Words added to the slug of every new or edited term while Term tools is on: a prefix such as best- or how-to-, a suffix such as -guide or -statistics. With best- and -guide, a new term “Technology” gets the slug best-technology-guide. Leave both empty to keep a taxonomy’s slugs as they are.', 'seoprostack'); ?></p>
            <p class="description"><?php esc_html_e('Slugs are permanent addresses, so use words that stay true, not dates. To change existing terms, select them in the term list and choose Apply slug pattern; their old addresses redirect with a 301.', 'seoprostack'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="seoprostack_term_patterns" />
                <?php wp_nonce_field('seoprostack_term_patterns'); ?>
                <table class="widefat striped">
                    <thead><tr><th scope="col"><?php esc_html_e('Taxonomy', 'seoprostack'); ?></th><th scope="col"><?php esc_html_e('Prefix', 'seoprostack'); ?></th><th scope="col"><?php esc_html_e('Suffix', 'seoprostack'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) : ?>
                        <?php if (!current_user_can($taxonomy->cap->manage_terms)) { continue; } ?>
                        <tr>
                            <td><?php echo esc_html($taxonomy->labels->name); ?> <code><?php echo esc_html($taxonomy->name); ?></code></td>
                            <?php foreach (array('prefix', 'suffix') as $part) : ?>
                                <td>
                                    <label class="screen-reader-text" for="sps-pattern-<?php echo esc_attr($taxonomy->name . '-' . $part); ?>"><?php echo esc_html($taxonomy->labels->name . ' — ' . ('prefix' === $part ? __('Prefix', 'seoprostack') : __('Suffix', 'seoprostack'))); ?></label>
                                    <input type="text" class="code" id="sps-pattern-<?php echo esc_attr($taxonomy->name . '-' . $part); ?>" name="seoprostack_patterns[<?php echo esc_attr($taxonomy->name); ?>][<?php echo esc_attr($part); ?>]" value="<?php echo esc_attr(self::affix($patterns[$taxonomy->name][$part] ?? '', $part)); ?>" placeholder="<?php echo esc_attr('prefix' === $part ? 'best-' : '-guide'); ?>" />
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><?php submit_button(__('Save slug patterns', 'seoprostack'), 'secondary', 'submit', false); ?></p>
            </form>
        </div>
        <?php
    }

    /** Save preferences only; existing terms change through the bulk action. */
    public static function save_patterns() {
        if (!SEOProStack_Settings::can_change()) {
            wp_die(esc_html__('You cannot change these settings.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer('seoprostack_term_patterns');
        $submitted = isset($_POST['seoprostack_patterns']) && is_array($_POST['seoprostack_patterns']) ? map_deep(wp_unslash($_POST['seoprostack_patterns']), 'sanitize_text_field') : array();
        $patterns  = self::patterns();
        foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) {
            if (!current_user_can($taxonomy->cap->manage_terms) || !isset($submitted[$taxonomy->name]) || !is_array($submitted[$taxonomy->name])) {
                continue;
            }
            $prefix = self::affix($submitted[$taxonomy->name]['prefix'] ?? '', 'prefix');
            $suffix = self::affix($submitted[$taxonomy->name]['suffix'] ?? '', 'suffix');
            if ('' === $prefix && '' === $suffix) {
                unset($patterns[$taxonomy->name]);
            } else {
                $patterns[$taxonomy->name] = array('prefix' => $prefix, 'suffix' => $suffix);
            }
        }
        SEOProStack_Settings::set(self::PATTERNS, wp_json_encode($patterns));
        wp_safe_redirect(admin_url('options-general.php?page=seoprostack&tab=content'));
        exit;
    }

    /**
     * Apply a taxonomy's pattern, preserving its previous slug for redirects.
     *
     * @param int    $term_id Term ID.
     * @param int    $tt_id   Term taxonomy ID (from core's term hook).
     * @param string $taxonomy Taxonomy.
     * @return bool|WP_Error Whether a slug changed, or the update error.
     */
    public static function apply_pattern($term_id, $tt_id, $taxonomy) {
        $object   = get_taxonomy($taxonomy);
        $patterns = self::patterns();
        if (!self::enabled() || isset(self::$applying[$term_id]) || !$object || !$object->public || empty($patterns[$taxonomy])) {
            return false;
        }
        $prefix = self::affix($patterns[$taxonomy]['prefix'] ?? '', 'prefix');
        $suffix = self::affix($patterns[$taxonomy]['suffix'] ?? '', 'suffix');
        if ('' === $prefix && '' === $suffix) {
            return false;
        }
        $term = get_term($term_id, $taxonomy);
        if (!$term instanceof WP_Term) {
            return false;
        }
        $slug = $term->slug;
        if ('' !== $prefix && 0 !== strpos($slug, $prefix)) {
            $slug = $prefix . $slug;
        }
        // Core may add a numeric uniqueness suffix after our suffix. Treat it
        // as already patterned so subsequent edits do not grow the slug.
        if ('' !== $suffix && !preg_match('/' . preg_quote($suffix, '/') . '(?:-[0-9]+)?$/', $slug)) {
            $slug .= $suffix;
        }
        if ($slug === $term->slug) {
            return false;
        }
        // Explicit duplicate slugs make wp_update_term() return an error.
        // Ask core for a unique slug first, using numeric rather than parent
        // suffixes so the pattern remains recognisable after a parent changes.
        $unique_term         = clone $term;
        $unique_term->parent = 0;
        $slug                = wp_unique_term_slug($slug, $unique_term);
        self::$applying[$term_id] = true;
        try {
            $result = wp_update_term($term_id, $taxonomy, array('slug' => $slug));
        } finally {
            unset(self::$applying[$term_id]);
        }
        if (is_wp_error($result)) {
            return $result;
        }
        $updated = get_term($term_id, $taxonomy);
        if (!$updated instanceof WP_Term || $updated->slug === $term->slug) {
            return false;
        }
        self::remember_old($term_id, array($taxonomy . ':' . $term->slug));
        return true;
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
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
        add_action('admin_footer', array(__CLASS__, 'fields'));
        add_action('admin_footer', array(__CLASS__, 'unused_link'));
        self::$screen_taxonomy = $taxonomy;
        if (!empty($_GET[self::UNUSED])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
            add_filter('terms_clauses', array(__CLASS__, 'only_unused'), 10, 3);
        }
    }

    /**
     * Only unused terms in the term list and its count (not in the parent
     * drop-down of the Add form).
     *
     * @param array    $clauses    Query clauses.
     * @param string[] $taxonomies Taxonomies.
     * @param array    $args       Query arguments.
     * @return array
     */
    public static function only_unused($clauses, $taxonomies, $args) {
        $list = isset($args['page']) || (isset($args['fields']) && 'count' === $args['fields']);
        if ($list && array(self::$screen_taxonomy) === array_values((array) $taxonomies)) {
            $clauses['where'] .= ' AND tt.count = 0';
        }
        return $clauses;
    }

    /**
     * "All | Unused (N)" above the term list.
     */
    public static function unused_link() {
        global $wpdb;
        $screen = get_current_screen();
        if (!$screen || 'edit-tags' !== $screen->base || '' === self::$screen_taxonomy) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one count on an admin screen; core has no API for it.
        $unused = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND count = 0", self::$screen_taxonomy));
        $on     = !empty($_GET[self::UNUSED]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
        if (!$unused && !$on) {
            return;
        }
        $base = array('taxonomy' => self::$screen_taxonomy);
        if (!empty($screen->post_type) && 'post' !== $screen->post_type) {
            $base['post_type'] = $screen->post_type;
        }
        $all  = add_query_arg($base, admin_url('edit-tags.php'));
        // Ordered, so child terms show without their parents.
        $only = add_query_arg($base + array(self::UNUSED => 1, 'orderby' => 'name', 'order' => 'asc'), admin_url('edit-tags.php'));
        ?>
        <ul class="subsubsub" id="seoprostack-unused-terms">
            <li><a href="<?php echo esc_url($all); ?>"<?php echo $on ? '' : ' class="current" aria-current="page"'; ?>><?php esc_html_e('All', 'seoprostack'); ?></a> |</li>
            <li><a href="<?php echo esc_url($only); ?>"<?php echo $on ? ' class="current" aria-current="page"' : ''; ?>><?php
                /* translators: %s: number of terms with no posts */
                echo esc_html(sprintf(__('Unused (%s)', 'seoprostack'), number_format_i18n($unused)));
            ?></a></li>
        </ul>
        <script>
        (function () {
            var links = document.getElementById('seoprostack-unused-terms');
            var form = document.querySelector('#col-right form#posts-filter') || document.getElementById('posts-filter');
            if (links && form) {
                form.parentNode.insertBefore(links, form);
                if (<?php echo $on ? 'true' : 'false'; ?>) {
                    var keep = document.createElement('input');
                    keep.type = 'hidden';
                    keep.name = '<?php echo esc_js(self::UNUSED); ?>';
                    keep.value = '1';
                    form.appendChild(keep);
                }
            }
        })();
        </script>
        <?php
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
        $taxonomy = $screen ? get_taxonomy($screen->taxonomy) : false;
        if ($taxonomy && $taxonomy->public) {
            $actions['seoprostack_pattern'] = __('Apply slug pattern', 'seoprostack');
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
            case 'seoprostack_pattern':
                $done   = 0;
                $failed = 0;
                foreach ($term_ids as $id) {
                    $changed = self::apply_pattern($id, 0, $taxonomy);
                    if (is_wp_error($changed)) {
                        ++$failed;
                    } elseif ($changed) {
                        ++$done;
                    }
                }
                $result = 'pattern:' . $done . ':' . $failed;
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
        if ('' === $taxonomy) {
            return 'failed';
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
                $children = get_terms(array('taxonomy' => $taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids'));
                foreach (is_array($children) ? $children : array() as $child) {
                    if ((int) $child !== $target->term_id) {
                        wp_update_term((int) $child, $taxonomy, array('parent' => $target->term_id));
                    }
                }
                if ((int) $target->parent === $term->term_id) {
                    wp_update_term($target->term_id, $taxonomy, array('parent' => (int) $term->parent));
                }
            }
            $old = array_merge(array($taxonomy . ':' . $term->slug), (array) get_term_meta($term->term_id, self::OLD, false));
            $deleted = wp_delete_term($term->term_id, $taxonomy, array('default' => max(1, $target->term_id), 'force_default' => true));
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
                $children = get_term_children((int) $id, $from);
                $ids      = array_merge($ids, is_array($children) ? array_map('intval', $children) : array());
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
            case 'pattern':
                /* translators: %d: number of terms */
                $text = sprintf(_n('Slug pattern applied to %d term.', 'Slug pattern applied to %d terms.', (int) $parts[1], 'seoprostack'), (int) $parts[1]);
                if (!empty($parts[2])) {
                    $class = 'notice-warning';
                    /* translators: %d: number of terms */
                    $text .= ' ' . sprintf(_n('%d term could not be updated.', '%d terms could not be updated.', (int) $parts[2], 'seoprostack'), (int) $parts[2]);
                }
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
     * Drop the notice argument from the address after showing it, so a
     * reload does not show the notice again.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::NOTICE;
        return $args;
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
