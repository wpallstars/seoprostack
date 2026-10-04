<?php
/**
 * Select all items across pages in post lists.
 *
 * Tick the "select all" box in Posts, Pages or any post type list and a bar
 * offers to select every item that matches the current filters, not just
 * the ones on screen. Bulk actions (Move to Bin, Edit, Restore, Delete
 * Permanently and plugin actions) then apply to all of them, still subject to
 * each item's permissions. Replaces "Bulk Actions Select All".
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

class SEOProStack_Bulk_Select_All extends SEOProStack_Feature {

    const KEY = 'bulk_select_all';

    /** Form field set when every matching item is selected. */
    const FIELD = 'seoprostack_select_all';

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
                'label'       => __('Select all across pages', 'seoprostack'),
                'description' => __('In post lists, select every item that matches the current filters, not just the page you can see, and apply a bulk action to all of them.', 'seoprostack'),
                'replaces'    => array('bulk-actions-select-all' => 'Bulk Actions Select All'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('load-edit.php', array(__CLASS__, 'expand_selection'));
        add_action('admin_print_footer_scripts-edit.php', array(__CLASS__, 'script'));
    }

    /**
     * Replace the ticked IDs with every matching ID before core runs the action.
     */
    public static function expand_selection() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- core verifies the bulk-posts nonce before acting on these IDs.
        if (empty($_REQUEST[self::FIELD]) || empty($_REQUEST['post'])) {
            return;
        }
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if (('' === $action || '-1' === $action) && isset($_REQUEST['action2'])) {
            $action = sanitize_key(wp_unslash($_REQUEST['action2']));
        }
        // phpcs:enable
        if ('' === $action || '-1' === $action) {
            return; // No bulk action chosen (a filter or search submit).
        }
        check_admin_referer('bulk-posts');

        $ids = self::matching_ids();
        if ($ids) {
            $_REQUEST['post'] = $ids;
            $_GET['post']     = $ids;
            if (function_exists('set_time_limit')) {
                set_time_limit(300); // phpcs:ignore Squiz.PHP.DiscouragedFunctions -- large bulk actions.
            }
            wp_raise_memory_limit('admin');
        }
    }

    /**
     * IDs of every item matching the list's current filters.
     *
     * @return int[]
     */
    public static function matching_ids() {
        $only_ids = function ($query) {
            if ($query->is_main_query()) {
                $query->set('fields', 'ids');
                $query->set('posts_per_page', -1);
                $query->set('nopaging', true);
                $query->set('no_found_rows', true);
            }
        };
        add_action('pre_get_posts', $only_ids, PHP_INT_MAX);
        // Same query the list shows, from the list's own filters (the form uses GET).
        $query = wp_unslash($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in expand_selection().
        unset($query['post'], $query['paged']);
        wp_edit_posts_query($query);
        remove_action('pre_get_posts', $only_ids, PHP_INT_MAX);

        global $wp_query;
        return array_map('intval', (array) $wp_query->posts);
    }

    /**
     * Selection bar.
     */
    public static function script() {
        global $wp_list_table;
        if (!$wp_list_table instanceof WP_Posts_List_Table) {
            return;
        }
        $total = (int) $wp_list_table->get_pagination_arg('total_items');
        $pages = (int) $wp_list_table->get_pagination_arg('total_pages');
        if ($pages < 2) {
            return;
        }
        $i18n = array(
            /* translators: %d: number of items on this page */
            'page'   => __('All %d items on this page are selected.', 'seoprostack'),
            /* translators: %s: total number of items */
            'select' => sprintf(__('Select all %s items', 'seoprostack'), number_format_i18n($total)),
            /* translators: %s: total number of items */
            'all'    => sprintf(__('All %s items are selected. Bulk actions apply to every one of them.', 'seoprostack'), number_format_i18n($total)),
            'clear'  => __('Clear selection', 'seoprostack'),
        );
        ?>
        <style>
            .seoprostack-select-all { margin: 8px 0; padding: 8px 12px; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; }
            .seoprostack-select-all .button-link { margin-inline-start: 6px; }
            .seoprostack-select-all.is-all { background: #fcf9e8; border-color: #dba617; }
        </style>
        <script>
        (function ($, i18n, field) {
            var $form = $('#posts-filter'), $table = $form.find('.wp-list-table');
            if (!$table.length) { return; }
            var $bar = $('<div class="seoprostack-select-all" role="status" hidden></div>').insertBefore($table);
            var $input = $('<input type="hidden" value="1">').attr('name', field);
            function boxes() { return $table.find('tbody .check-column input[type="checkbox"]'); }
            function render(all) {
                var n = boxes().length;
                $bar.empty().toggleClass('is-all', all);
                if (all) {
                    $form.append($input);
                    $bar.append(document.createTextNode(i18n.all + ' '))
                        .append($('<button type="button" class="button-link"></button>').text(i18n.clear).on('click', function () {
                            $table.find('.check-column input[type="checkbox"]').prop('checked', false).trigger('change');
                            reset();
                        }));
                } else {
                    $input.detach();
                    $bar.append(document.createTextNode(i18n.page.replace('%d', n) + ' '))
                        .append($('<button type="button" class="button-link"></button>').text(i18n.select).on('click', function () { render(true); }));
                }
                $bar.prop('hidden', false);
            }
            function reset() { $input.detach(); $bar.prop('hidden', true).empty(); }
            $table.on('change', 'input[type="checkbox"]', function () {
                // Core toggles every row when a "select all" box changes; check after it.
                setTimeout(function () {
                    var $b = boxes();
                    if ($b.length && $b.filter(':checked').length === $b.length) {
                        if (!$input.parent().length) { render(false); }
                    } else {
                        reset();
                    }
                }, 0);
            });
        })(jQuery, <?php echo wp_json_encode($i18n); ?>, <?php echo wp_json_encode(self::FIELD); ?>);
        </script>
        <?php
    }
}
