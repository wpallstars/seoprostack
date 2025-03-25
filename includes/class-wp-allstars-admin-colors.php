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
        
        // Add script to handle the toggle
        add_action('admin_enqueue_scripts', array($this, 'enqueue_color_scripts'));
        
        // Add admin notice for setting saved feedback
        add_action('admin_notices', array($this, 'show_saved_notice'));
    }
    
    /**
     * Show saved notice when color scheme has been updated
     */
    public function show_saved_notice() {
        // Only show on our settings page
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'wp-allstars') === false) {
            return;
        }
        
        // Check if we have a transient indicating a recent save
        $user_id = get_current_user_id();
        $transient_name = 'wp_allstars_colors_updated_' . $user_id;
        
        if (get_transient($transient_name)) {
            // Show the notice
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('Color scheme setting saved successfully.', 'wp-allstars') . 
                 '</p></div>';
            
            // Delete the transient so it only shows once
            delete_transient($transient_name);
        }
    }
    
    /**
     * Enqueue JavaScript to handle toggle and page refresh
     */
    public function enqueue_color_scripts($hook) {
        // Only load on the plugin settings page
        if (strpos($hook, 'wp-allstars') === false) {
            return;
        }
        
        // Add inline JS for handling toggle and refresh
        $color_js = '
        jQuery(document).ready(function($) {
            // Special handler for admin color scheme toggle
            $("#wp_allstars_simple_setting").on("change", function() {
                var isEnabled = $(this).is(":checked");
                
                // Save setting via AJAX and refresh page
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "wp_allstars_update_option",
                        option: "wp_allstars_simple_setting",
                        value: isEnabled ? 1 : 0,
                        nonce: "' . wp_create_nonce('wp-allstars-nonce') . '"
                    },
                    success: function() {
                        // Refresh the page after successful update
                        window.location.reload();
                    }
                });
            });
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
     */
    public function handle_color_scheme_update() {
        // Check for required params
        if (!isset($_POST['option']) || !isset($_POST['value']) || !isset($_POST['nonce'])) {
            return;
        }
        
        // Verify nonce
        check_ajax_referer('wp-allstars-nonce', 'nonce');
        
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
        
        // Update the option
        update_option($this->option_name, $value ? 1 : 0);
        
        // Set a transient to show the saved notice
        set_transient('wp_allstars_colors_updated_' . $user_id, true, 30);
        
        // Return success
        wp_send_json_success();
    }
} 