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
        
        // Get all available plugin categories from the data file
        $plugin_categories = wp_allstars_get_free_plugins();
        
        // Define all categories in the desired display order
        $priority_categories = array(
            'minimal', 'admin', 'affiliates', 'ai', 'cms',
            'compliance', 'crm', 'ecommerce', 'lms', 'media',
            'seo', 'setup', 'social', 'speed', 'translation',
            'advanced', 'debug'
        );
        
        // Start HTML output
        ?>
        <div class="wp-allstars-settings-content tab-content" id="recommended">
        <div id="wpa-plugin-filters" class="wp-filter">
            <ul class="filter-links">
                <?php
                // First output priority categories
                foreach ($priority_categories as $category) {
                    if (isset($plugin_categories[$category])) {
                        $category_name = ucfirst($category);
                        if ($category == 'cms') $category_name = 'CMS';
                        if ($category == 'crm') $category_name = 'CRM';
                        if ($category == 'ecommerce') $category_name = 'eCommerce';
                        if ($category == 'lms') $category_name = 'LMS';
                        if ($category == 'seo') $category_name = 'SEO';
                        ?>
                        <li><a href="#" data-category="<?php echo esc_attr($category); ?>" class="<?php echo $active_category == $category ? 'current' : ''; ?>">
                            <?php echo esc_html($category_name); ?>
                        </a></li>
                        <?php
                    }
                }
                
                // Add any new categories that might have been added to the data file but aren't in our priority list
                $remaining_categories = array_diff(array_keys($plugin_categories), $priority_categories);
                sort($remaining_categories);
                
                foreach ($remaining_categories as $category) {
                    $category_name = ucfirst($category);
                    if ($category == 'cms') $category_name = 'CMS';
                    if ($category == 'crm') $category_name = 'CRM';
                    if ($category == 'ecommerce') $category_name = 'eCommerce';
                    if ($category == 'lms') $category_name = 'LMS';
                    if ($category == 'seo') $category_name = 'SEO';
                    ?>
                    <li><a href="#" data-category="<?php echo esc_attr($category); ?>" class="<?php echo $active_category == $category ? 'current' : ''; ?>">
                        <?php echo esc_html($category_name); ?>
                    </a></li>
                    <?php
                }
                ?>
            </ul>
        </div>

        <div class="wp-allstars-plugin-browser">
            <div id="wpa-plugin-list" class="wpa-plugin-container" style="position: relative; min-height: 200px;">
                <!-- Plugin content will be loaded via AJAX -->
                <div class="wp-allstars-loading-overlay">
                    <span class="spinner is-active"></span>
                    <p>Loading plugin data...</p>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Filter tab click handler
            $('#wpa-plugin-filters .filter-links a').on('click', function(e) {
                e.preventDefault();
                
                var category = $(this).data('category');
                var $container = $('#wpa-plugin-list');
                
                // Update filter UI
                $('#wpa-plugin-filters .filter-links a').removeClass('current');
                $(this).addClass('current');
                
                // Create new loading overlay
                $container.empty();
                var $loadingOverlay = $('<div class="wp-allstars-loading-overlay"><span class="spinner is-active"></span><p>Loading plugin data...</p></div>');
                $container.append($loadingOverlay);
                
                // Load plugins in selected category
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_allstars_get_plugins',
                        category: category,
                        _wpnonce: '<?php echo wp_create_nonce('wp-allstars-nonce'); ?>'
                    },
                    success: function(response) {
                        $loadingOverlay.remove();
                        
                        if (response.success) {
                            // Add plugins to the container
                            $container.html(response.data);
                        } else {
                            $container.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                            console.error('Error loading plugins:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        $loadingOverlay.remove();
                        $container.html('<div class="notice notice-error"><p>Failed to load plugins. Please try again. Error: ' + error + '</p></div>');
                        console.error('AJAX error:', xhr.responseText);
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
