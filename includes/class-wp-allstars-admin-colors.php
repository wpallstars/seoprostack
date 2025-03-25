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
                // Get the stylesheets we need to replace
                var $adminColors = $("link[href*=\'colors\']");
                
                // AJAX request to get the new color scheme URLs
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "wp_allstars_get_admin_colors",
                        enable_modern: enableModern ? 1 : 0,
                        _wpnonce: wpAllstars.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Replace the stylesheets
                            $.each(response.data, function(index, styleData) {
                                var $oldStyle = $("link[href*=\'" + styleData.id + "\']");
                                if ($oldStyle.length) {
                                    // Create a new link element
                                    var $newStyle = $("<link>", {
                                        rel: "stylesheet",
                                        id: styleData.id,
                                        href: styleData.url,
                                        type: "text/css"
                                    });
                                    
                                    // Replace the old style with the new one
                                    $oldStyle.after($newStyle);
                                    $oldStyle.remove();
                                }
                            });
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
     * AJAX handler to get the admin color scheme stylesheets
     */
    public function get_admin_colors_ajax() {
        // Verify nonce
        check_ajax_referer('wp-allstars-nonce', '_wpnonce');
        
        // Get current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
            return;
        }
        
        // Determine which scheme to set based on the value
        $enable_modern = isset($_POST['enable_modern']) && $_POST['enable_modern'] == 1;
        $scheme = $enable_modern ? $this->modern_scheme : $this->default_scheme;
        
        // Get color scheme information
        global $_wp_admin_css_colors;
        
        // Make sure the scheme exists
        if (!isset($_wp_admin_css_colors[$scheme])) {
            wp_send_json_error('Color scheme not found');
            return;
        }
        
        // Get the stylesheet URLs
        $color_scheme = $_wp_admin_css_colors[$scheme];
        $stylesheet_urls = array();
        
        // Colors stylesheet
        $stylesheet_urls[] = array(
            'id' => 'colors',
            'url' => $color_scheme->url . 'colors.min.css'
        );
        
        // Colors RTL stylesheet if needed
        if (is_rtl() && file_exists($color_scheme->url . 'colors-rtl.min.css')) {
            $stylesheet_urls[] = array(
                'id' => 'colors-rtl',
                'url' => $color_scheme->url . 'colors-rtl.min.css'
            );
        }
        
        // Return the URLs to the JavaScript
        wp_send_json_success($stylesheet_urls);
    }
} 