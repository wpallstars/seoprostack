<?php
/**
 * SEO Pro Stack Free Plugins tab.
 *
 * Category filter, then either cards for one category or the All list: every
 * recommended plugin grouped by category, with checkboxes and bulk actions
 * for setting up a new site, and the disk space each installed one takes
 * (Plugin sizes cache, measured in the background). Both live inside `#plugin-filter`, where core's
 * updates.js binds its Update Now handler; cards and rows share their
 * buttons (see SEOProStack_Plugin_Manager::state_buttons()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Free_Plugins_Manager {

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        $labels = SEOProStack_Plugin_Manager::get_category_labels();
        $links  = array(SEOProStack_Plugin_Manager::ALL => __('All', 'seoprostack')) + $labels;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
        $active = isset($_GET['category']) ? sanitize_key(wp_unslash($_GET['category'])) : 'minimal';
        if (!isset($links[$active])) {
            $active = (string) key($labels);
        }
        $is_all = SEOProStack_Plugin_Manager::ALL === $active;

        if (!current_user_can('install_plugins')) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Only users who can install plugins can browse these recommendations.', 'seoprostack') . '</p></div>';
            return;
        }
        ?>
        <div class="sps-plugins">
            <div class="wp-filter sps-filter">
                <ul class="filter-links" role="list">
                    <?php foreach ($links as $slug => $label) : ?>
                        <li>
                            <a href="<?php echo esc_url(SEOProStack_Admin_Manager::tab_url('recommended', array('category' => $slug))); ?>"
                               data-category="<?php echo esc_attr($slug); ?>"
                               class="<?php echo $slug === $active ? 'current' : ''; ?>"
                               <?php echo $slug === $active ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html($label); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <form id="plugin-filter" method="post" onsubmit="return false;">
                <div class="wp-list-table widefat plugin-install" data-sps-plugin-cards <?php echo $is_all ? 'hidden' : ''; ?>>
                    <div id="the-list" data-sps-plugin-list data-category="<?php echo esc_attr($active); ?>" aria-live="polite" aria-busy="true">
                        <div class="sps-loading"><span class="spinner is-active"></span> <?php esc_html_e('Loading plugins…', 'seoprostack'); ?></div>
                    </div>
                </div>

                <?php self::render_all_list($labels, $is_all); ?>
            </form>
            <?php
            // Shown only where WordPress needs FTP or SSH details to install or delete.
            if (function_exists('wp_print_request_filesystem_credentials_modal')) {
                wp_print_request_filesystem_credentials_modal();
            }
            ?>
        </div>
        <?php
    }

    /**
     * The All list: bulk toolbar and one row group per category. Rows load
     * per category, so each uses the same cache as its cards.
     *
     * @param array<string,string> $labels Category slug => label.
     * @param bool                 $shown  Whether All is the current view.
     */
    private static function render_all_list(array $labels, $shown) {
        $actions = array(
            'install-activate' => __('Install and activate', 'seoprostack'),
            'install'          => __('Install', 'seoprostack'),
        );
        if (current_user_can('activate_plugins')) {
            $actions['activate']   = __('Activate', 'seoprostack');
            $actions['deactivate'] = __('Deactivate', 'seoprostack');
        } else {
            unset($actions['install-activate']);
        }
        if (current_user_can('delete_plugins')) {
            $actions['uninstall'] = __('Uninstall', 'seoprostack');
        }
        ?>
        <div class="sps-bulk-list" data-sps-plugin-table <?php echo $shown ? '' : 'hidden'; ?>>
            <div class="sps-bulk" data-sps-bulk>
                <label class="screen-reader-text" for="sps-bulk-action"><?php esc_html_e('Bulk action', 'seoprostack'); ?></label>
                <select id="sps-bulk-action" data-sps-bulk-action>
                    <option value=""><?php esc_html_e('Bulk actions', 'seoprostack'); ?></option>
                    <?php foreach ($actions as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button" data-sps-bulk-apply><?php esc_html_e('Apply', 'seoprostack'); ?></button>
                <button type="button" class="button" data-sps-bulk-stop hidden><?php esc_html_e('Stop', 'seoprostack'); ?></button>
                <span class="sps-bulk__count" data-sps-bulk-count></span>
                <span class="sps-bulk__progress" data-sps-bulk-progress role="status" aria-live="polite"></span>
            </div>

            <table class="wp-list-table widefat striped sps-plugin-table">
                <thead>
                    <tr>
                        <td class="manage-column check-column">
                            <label class="screen-reader-text" for="sps-plugins-all"><?php esc_html_e('Select all', 'seoprostack'); ?></label>
                            <input type="checkbox" id="sps-plugins-all" data-sps-check-all-plugins />
                        </td>
                        <th scope="col"><?php esc_html_e('Plugin', 'seoprostack'); ?></th>
                        <th scope="col" class="sps-plugin-table__size"><?php esc_html_e('Size', 'seoprostack'); ?></th>
                        <th scope="col" class="sps-plugin-table__actions"><span class="screen-reader-text"><?php esc_html_e('Actions', 'seoprostack'); ?></span></th>
                        <th scope="col" class="sps-plugin-table__pro"><span class="screen-reader-text"><?php esc_html_e('Pro version', 'seoprostack'); ?></span></th>
                    </tr>
                </thead>
                <?php foreach ($labels as $slug => $label) : ?>
                    <tbody data-sps-plugin-group="<?php echo esc_attr($slug); ?>">
                        <tr class="sps-plugin-group-row">
                            <td class="check-column">
                                <label class="screen-reader-text" for="sps-group-<?php echo esc_attr($slug); ?>"><?php echo esc_html(sprintf(/* translators: %s: category name */ __('Select all in %s', 'seoprostack'), $label)); ?></label>
                                <input type="checkbox" id="sps-group-<?php echo esc_attr($slug); ?>" data-sps-group-check disabled />
                            </td>
                            <th scope="rowgroup" colspan="4">
                                <?php echo esc_html($label); ?>
                                <span class="sps-plugin-group-row__state" data-sps-group-state><span class="spinner is-active"></span></span>
                            </th>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }
}
