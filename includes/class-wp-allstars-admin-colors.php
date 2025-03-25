<?php
/**
 * WP ALLSTARS Admin Colors Feature
 *
 * Handles setting the admin color scheme based on user preferences
 *
 * @package WP_ALLSTARS
 * @since 0.2.3
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Admin Colors Handler Class
 */
class WP_Allstars_Admin_Colors {
    
    /**
     * Option name for the admin color scheme setting
     *
     * @var string
     */
    private $option_name = 'wp_allstars_simple_setting';
    
    /**
     * Modern color scheme key
     *
     * @var string
     */
    private $modern_scheme = 'modern';
    
    /**
     * Default color scheme key
     *
     * @var string
     */
    private $default_scheme = 'fresh';
    
    /**
     * Initialize the class and set up hooks
     */
    public function __construct() {
        // Set up hooks
        add_action('admin_init', array($this, 'set_admin_color_scheme'));
        add_action('wp_ajax_wp_allstars_update_option', array($this, 'handle_color_scheme_update'), 5);
        add_action('wp_ajax_wp_allstars_get_admin_colors', array($this, 'get_admin_colors_ajax'));
        
        // Add script to handle dynamic color changes
        add_action('admin_enqueue_scripts', array($this, 'enqueue_color_scripts'));
    }
    
    /**
     * Enqueue JavaScript to handle dynamic color scheme changes
     */
    public function enqueue_color_scripts($hook) {
        // Only load on the plugin settings page
        if (strpos($hook, 'wp-allstars') === false) {
            return;
        }
        
        // Add inline JS for handling dynamic color scheme changes
        $color_js = '
        jQuery(document).ready(function($) {
            // Special handler for admin color scheme toggle
            $("#wp_allstars_simple_setting").on("change", function() {
                var isEnabled = $(this).is(":checked");
                
                // Apply the color scheme dynamically
                applyAdminColorScheme(isEnabled);
            });
            
            // Function to dynamically apply admin color scheme
            function applyAdminColorScheme(enableModern) {
                console.log("Applying admin color scheme:", enableModern ? "modern" : "default");
                
                // Define the color schemes 
                var scheme = enableModern ? "modern" : "fresh";
                
                // AJAX request to update color scheme
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "wp_allstars_update_admin_colors",
                        scheme: scheme,
                        _wpnonce: wpAllstars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Get the list of stylesheets to update
                            var oldScheme = response.data.previousScheme;
                            var newScheme = response.data.currentScheme;
                            
                            console.log("Switching from " + oldScheme + " to " + newScheme);
                            
                            // Update body class - this is the WordPress way of handling color scheme changes
                            $("body")
                                .removeClass(oldScheme)
                                .addClass(newScheme);
                            
                            // Replace admin-colors.css
                            var $adminColors = $("#admin-colors-css, link[href*=\'colors-\']");
                            if ($adminColors.length) {
                                // Get the new URL by replacing the scheme in the existing URL
                                var oldUrl = $adminColors.first().attr("href");
                                var newUrl = oldUrl.replace(
                                    /\/([^\/]+)\/colors/,
                                    "/" + newScheme + "/colors"
                                );
                                
                                // Create a new stylesheet
                                var $newStyle = $("<link>", {
                                    rel: "stylesheet",
                                    id: "admin-colors-css",
                                    href: newUrl,
                                    type: "text/css"
                                });
                                
                                // Add the new stylesheet and remove old ones
                                $("head").append($newStyle);
                                
                                // After a short delay, remove old stylesheets
                                setTimeout(function() {
                                    $adminColors.remove();
                                }, 200);
                            }
                        }
                    }
                });
            }
        });
        ';
        
        wp_add_inline_script('wp-allstars-admin', $color_js);
    }
    
    /**
     * Set the admin color scheme based on the setting value
     */
    public function set_admin_color_scheme() {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }
        
        // Check if our setting is enabled
        $modern_colors_enabled = get_option($this->option_name, false);
        
        // Get the scheme to set
        $scheme = $modern_colors_enabled ? $this->modern_scheme : $this->default_scheme;
        
        // Update user meta to set the color scheme
        update_user_meta($user_id, 'admin_color', $scheme);
    }
    
    /**
     * Handle color scheme update via AJAX
     * Runs early to apply the color scheme change before the general option update handler
     */
    public function handle_color_scheme_update() {
        // Check for required params
        if (!isset($_POST['option']) || !isset($_POST['value'])) {
            return;
        }
        
        // Only process our specific option
        if ($_POST['option'] !== $this->option_name) {
            return;
        }
        
        // Get the current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }
        
        // Determine which scheme to set based on the value
        $value = (bool) $_POST['value'];
        $scheme = $value ? $this->modern_scheme : $this->default_scheme;
        
        // Update the user's color scheme
        update_user_meta($user_id, 'admin_color', $scheme);
    }
    
    /**
     * AJAX handler to update and get admin color schemes - similar to WordPress core
     */
    public function get_admin_colors_ajax() {
        // Register handler for our new AJAX action
        add_action('wp_ajax_wp_allstars_update_admin_colors', array($this, 'update_admin_colors_ajax'));
    }
    
    /**
     * AJAX handler to update admin color scheme and return class names
     */
    public function update_admin_colors_ajax() {
        check_ajax_referer('wp-allstars-nonce', '_wpnonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }
        
        // Get the old scheme
        $old_scheme = get_user_meta($user_id, 'admin_color', true);
        if (!$old_scheme) {
            $old_scheme = 'fresh'; // Default WordPress admin color scheme
        }
        
        // Get the new scheme
        $scheme = isset($_POST['scheme']) ? sanitize_text_field($_POST['scheme']) : 'fresh';
        
        // Validate scheme
        global $_wp_admin_css_colors;
        if (!isset($_wp_admin_css_colors[$scheme])) {
            wp_send_json_error('Invalid color scheme');
        }
        
        // Update user meta
        update_user_meta($user_id, 'admin_color', $scheme);
        
        // Also update our plugin option
        update_option($this->option_name, $scheme === $this->modern_scheme ? 1 : 0);
        
        // Return success with old and new scheme names for CSS class updates
        wp_send_json_success(array(
            'previousScheme' => 'admin-color-' . $old_scheme,
            'currentScheme' => 'admin-color-' . $scheme
        ));
    }
} 