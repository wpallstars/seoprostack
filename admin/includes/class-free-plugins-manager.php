<?php
/**
 * WP ALLSTARS Free Plugins Manager
 * 
 * Manages the Free Plugins tab including:
 * - Category filtering system
 * - Plugin recommendations by use case
 * - Plugin installation functionality
 * 
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WP_Allstars_Free_Plugins_Manager class
 * 
 * Provides categorized plugin recommendations based on website needs
 */
class WP_Allstars_Free_Plugins_Manager {
    
    /**
     * Initialize the class and register hooks if needed
     *
     * @return void
     */
    public static function init() {
        // We'll implement AJAX handlers in a future update if needed
        // add_action('wp_ajax_wp_allstars_load_free_plugins', array(self::class, 'ajax_load_free_plugins'));
    }
    
    /**
     * Display the free plugins tab content
     * 
     * Renders the category filter bar and plugin list container.
     * Initial view shows 'minimal' category plugins by default.
     *
     * @return void
     */
    public static function display_tab_content() {
        // Get the active category from query params or use default
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
                <li><a href="#" data-category="cms" class="<?php echo $active_category == 'cms' ? 'current' : ''; ?>">
                    <?php esc_html_e('CMS', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="ecommerce" class="<?php echo $active_category == 'ecommerce' ? 'current' : ''; ?>">
                    <?php esc_html_e('eCommerce', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="marketing" class="<?php echo $active_category == 'marketing' ? 'current' : ''; ?>">
                    <?php esc_html_e('Marketing', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="media" class="<?php echo $active_category == 'media' ? 'current' : ''; ?>">
                    <?php esc_html_e('Media', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="security" class="<?php echo $active_category == 'security' ? 'current' : ''; ?>">
                    <?php esc_html_e('Security', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="developers" class="<?php echo $active_category == 'developers' ? 'current' : ''; ?>">
                    <?php esc_html_e('Developers', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="affiliates" class="<?php echo $active_category == 'affiliates' ? 'current' : ''; ?>">
                    <?php esc_html_e('Affiliates', 'wp-allstars'); ?>
                </a></li>
                <li><a href="#" data-category="ai" class="<?php echo $active_category == 'ai' ? 'current' : ''; ?>">
                    <?php esc_html_e('AI', 'wp-allstars'); ?>
                </a></li>
            </ul>
        </div>

        <div class="wp-allstars-plugin-browser">
            <div class="wp-list-table plugin-install">
                <div id="wpa-plugin-list">
                    <div id="the-list"></div>
                </div>
            </div>
            <div class="wp-allstars-loading-overlay">
                <span class="spinner is-active"></span>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Filter tab click handler
            $('#wpa-plugin-filters .filter-links a').on('click', function(e) {
                e.preventDefault();
                
                var category = $(this).data('category');
                
                // Update filter UI
                $('#wpa-plugin-filters .filter-links a').removeClass('current');
                $(this).addClass('current');
                
                // Show loading spinner
                $('.wp-allstars-loading-overlay').show();
                
                // Clear existing plugins
                $('#the-list').empty();
                
                // Load plugins in selected category
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_allstars_load_plugins',
                        category: category,
                        nonce: '<?php echo wp_create_nonce('wp_allstars_load_plugins'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Hide loading spinner
                            $('.wp-allstars-loading-overlay').hide();
                            
                            // Add plugins to the container
                            $('#the-list').html(response.data);
                        } else {
                            console.error('Error loading plugins:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', error);
                    }
                });
            });
            
            // Load initial category (minimal or from URL)
            $('#wpa-plugin-filters .filter-links a.current').trigger('click');
        });
        </script>
        </div>
        <?php
    }
    
    /**
     * Get the recommended plugins data
     *
     * @return array Array of recommended plugins by category
     */
    public static function get_recommended_plugins() {
        // Define the plugins data if it hasn't been included yet
        if (!function_exists('wp_allstars_get_free_plugins')) {
            require_once dirname(dirname(__FILE__)) . '/data/free-plugins.php';
        }
        
        return wp_allstars_get_free_plugins();
    }
}
