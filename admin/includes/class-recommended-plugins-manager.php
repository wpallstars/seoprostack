<?php
/**
 * WP ALLSTARS Recommended Plugins Manager
 * 
 * Manages the recommended plugins tab and functionality
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Allstars_Recommended_Plugins_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // No specific initialization needed currently
    }
    
    /**
     * Display the recommended plugins tab content
     */
    public static function display_tab_content() {
        // Get the active category from query params or default to 'minimal'
        $active_category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : 'minimal';
        ?>
        <div class="wp-allstars-settings-content tab-content" id="recommended">
        <div id="wpa-plugin-filters" class="wp-filter">
            <ul class="filter-links">
                <li><a href="#" data-category="minimal" class="<?php echo $active_category == 'minimal' ? 'current' : ''; ?>">
                    <?php esc_html_e('Minimal', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="admin" class="<?php echo $active_category == 'admin' ? 'current' : ''; ?>">
                    <?php esc_html_e('Admin', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="ai" class="<?php echo $active_category == 'ai' ? 'current' : ''; ?>">
                    <?php esc_html_e('AI', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="cms" class="<?php echo $active_category == 'cms' ? 'current' : ''; ?>">
                    <?php esc_html_e('CMS', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="compliance" class="<?php echo $active_category == 'compliance' ? 'current' : ''; ?>">
                    <?php esc_html_e('Compliance', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="crm" class="<?php echo $active_category == 'crm' ? 'current' : ''; ?>">
                    <?php esc_html_e('CRM', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="ecommerce" class="<?php echo $active_category == 'ecommerce' ? 'current' : ''; ?>">
                    <?php esc_html_e('Ecommerce', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="lms" class="<?php echo $active_category == 'lms' ? 'current' : ''; ?>">
                    <?php esc_html_e('LMS', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="media" class="<?php echo $active_category == 'media' ? 'current' : ''; ?>">
                    <?php esc_html_e('Media', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="seo" class="<?php echo $active_category == 'seo' ? 'current' : ''; ?>">
                    <?php esc_html_e('SEO', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="setup" class="<?php echo $active_category == 'setup' ? 'current' : ''; ?>">
                    <?php esc_html_e('Setup', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="social" class="<?php echo $active_category == 'social' ? 'current' : ''; ?>">
                    <?php esc_html_e('Social', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="speed" class="<?php echo $active_category == 'speed' ? 'current' : ''; ?>">
                    <?php esc_html_e('Speed', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="translation" class="<?php echo $active_category == 'translation' ? 'current' : ''; ?>">
                    <?php esc_html_e('Translation', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="advanced" class="<?php echo $active_category == 'advanced' ? 'current' : ''; ?>">
                    <?php esc_html_e('Advanced', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="debug" class="<?php echo $active_category == 'debug' ? 'current' : ''; ?>">
                    <?php esc_html_e('Debug', 'wp-allstars'); ?>
                </a></li>
            </ul>
        </div>

        <div class="wp-allstars-plugin-browser">
            <div id="wpa-plugin-list"></div>
        </div>
        </div>
        <?php
    }
    
    /**
     * Get the list of recommended plugins
     * 
     * @return array Array of recommended plugins
     */
    public static function get_recommended_plugins() {
        // Define the plugins data if it hasn't been included yet
        if (!function_exists('wp_allstars_get_recommended_plugins')) {
            require_once dirname(dirname(__FILE__)) . '/data/recommended-plugins.php';
        }
        
        return wp_allstars_get_recommended_plugins();
    }
}
