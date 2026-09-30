<?php
/**
 * SEO Pro Stack Free Plugins tab.
 *
 * Category filter plus a `#plugin-filter` container: core's updates.js binds
 * its install/update handlers to that id, so cards loaded into it install in
 * place exactly like Plugins → Add New.
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
        $active = isset($_GET['category']) ? sanitize_key(wp_unslash($_GET['category'])) : 'minimal';
        if (!isset($labels[$active])) {
            $active = (string) key($labels);
        }

        if (!current_user_can('install_plugins')) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Only users who can install plugins can browse these recommendations.', 'seoprostack') . '</p></div>';
            return;
        }
        ?>
        <div class="sps-plugins">
            <div class="wp-filter sps-filter">
                <ul class="filter-links" role="list">
                    <?php foreach ($labels as $slug => $label) : ?>
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
                <div class="wp-list-table widefat plugin-install">
                    <div id="the-list" data-sps-plugin-list data-category="<?php echo esc_attr($active); ?>" aria-live="polite" aria-busy="true">
                        <div class="sps-loading"><span class="spinner is-active"></span> <?php esc_html_e('Loading plugins…', 'seoprostack'); ?></div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
}
