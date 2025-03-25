<?php
/**
 * Settings Manager Class
 * 
 * Handles the display and management of plugin settings tabs (General and Advanced).
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WP_Allstars_Settings_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // Register settings
        add_action('admin_init', array(self::class, 'register_settings'));
        
        // Enqueue scripts and styles if needed
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_scripts'));
    }
    
    /**
     * Register plugin settings
     */
    public static function register_settings() {
        // General settings
        register_setting('wp_allstars_settings', 'wp_allstars_simple_setting');
        
        // Advanced settings
        register_setting('wp_allstars_settings', 'wp_allstars_auto_upload_images');
    }
    
    /**
     * Enqueue scripts and styles needed for settings
     */
    public static function enqueue_scripts($hook) {
        // Only load on the plugin settings page
        if (strpos($hook, 'wp-allstars') === false) {
            return;
        }
        
        // Add inline JS for toggle functionality
        $toggle_js = '
        jQuery(document).ready(function($) {
            // Toggle expandable settings panels
            $(".wp-allstars-toggle-header").on("click", function() {
                var $this = $(this);
                var $settings = $this.next(".wp-allstars-toggle-settings");
                var isExpanded = $this.attr("aria-expanded") === "true";
                
                // Toggle aria-expanded attribute
                $this.attr("aria-expanded", !isExpanded);
                
                // Toggle settings visibility
                $settings.slideToggle(200);
            });
        });
        ';
        
        wp_add_inline_script('wp-allstars-admin', $toggle_js);
    }
    
    /**
     * Display the general tab content
     */
    public static function display_general_tab() {
        ?>
        <div class="wp-allstars-settings-section">
            <div class="wp-allstars-settings-grid">
                <!-- Modern Admin Colors Setting -->
                <div class="wp-setting-row">
                    <div class="wp-setting-header">
                        <div class="wp-setting-main">
                            <div class="wp-setting-left">
                                <div class="wp-toggle-switch">
                                    <input type="checkbox" 
                                           id="wp_allstars_simple_setting"
                                           name="wp_allstars_simple_setting" 
                                           value="1"
                                           <?php checked(get_option('wp_allstars_simple_setting', false)); ?> 
                                    />
                                    <span class="wp-toggle-slider"></span>
                                </div>
                                <label for="wp_allstars_simple_setting" class="wp-setting-label">
                                    <?php esc_html_e('Modern Admin Colors', 'wp-allstars'); ?>
                                </label>
                            </div>
                        </div>
                        <p class="wp-setting-description">
                            <?php esc_html_e('Switch to the Modern Admin colours, to remind that you\'re using an SEO Pro Stack :)', 'wp-allstars'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display the advanced tab content
     */
    public static function display_advanced_tab() {
        ?>
        <div class="wp-allstars-settings-section">
            <div class="wp-allstars-settings-grid">
                <?php
                // Display access control settings
                WP_Allstars_Access_Manager::display_access_settings();
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save settings
     */
    public static function save_settings() {
        // Check for nonce
        if (!isset($_POST['wp_allstars_settings_nonce']) || !wp_verify_nonce($_POST['wp_allstars_settings_nonce'], 'wp_allstars_save_settings')) {
            return;
        }
        
        // Save general settings
        update_option('wp_allstars_simple_setting', isset($_POST['wp_allstars_simple_setting']) ? 1 : 0);
        
        // Save advanced settings
        update_option('wp_allstars_auto_upload_images', isset($_POST['wp_allstars_auto_upload_images']) ? 1 : 0);
        
        // Add settings saved notice
        add_settings_error('wp_allstars_settings', 'settings_updated', __('Settings saved.', 'wp-allstars'), 'updated');
    }
}
