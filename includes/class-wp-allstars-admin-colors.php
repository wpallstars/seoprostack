<?php
/**
 * WP ALLSTARS Admin Colors Feature
 *
 * Handles setting the admin color scheme based on user preferences
 *
 * @package WP_ALLSTARS
 * @since 0.2.3.1
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
    private $option_name = 'wp_allstars_admin_color_scheme';
    
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
        add_action('wp_ajax_wp_allstars_update_color_scheme', array($this, 'handle_color_scheme_update'));
        
        // Add script to handle the toggle
        add_action('admin_enqueue_scripts', array($this, 'enqueue_color_scripts'));
    }
    
    /**
     * Enqueue scripts and styles for the color scheme toggle
     */
    public function enqueue_color_scripts() {
        // Only enqueue on our plugin pages
        $screen = get_current_screen();
        if (!isset($screen->id) || strpos($screen->id, 'wp-allstars') === false) {
            return;
        }
        
        wp_enqueue_script(
            'wp-allstars-color-toggle',
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/wp-allstars-admin-colors.js',
            array('jquery'),
            WP_ALLSTARS_VERSION,
            true
        );
        
        wp_localize_script('wp-allstars-color-toggle', 'wpAllstarsColors', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_allstars_color_nonce'),
            'option_name' => $this->option_name,
        ));
    }
    
    /**
     * Set the admin color scheme based on user preference
     */
    public function set_admin_color_scheme() {
        // Only apply for administrators
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }
        
        // Check if our option is enabled
        $enable_modern = get_option($this->option_name, false);
        
        // Set the appropriate color scheme
        if ($enable_modern) {
            // Use modern scheme if available, otherwise use default
            $this->set_user_color_scheme($user_id, $this->modern_scheme);
        }
    }
    
    /**
     * Set a user's color scheme
     *
     * @param int $user_id The user ID
     * @param string $scheme The color scheme to set
     */
    private function set_user_color_scheme($user_id, $scheme) {
        // Check if the scheme exists
        global $_wp_admin_css_colors;
        
        // If the scheme doesn't exist, use the default
        if (!isset($_wp_admin_css_colors[$scheme])) {
            $scheme = $this->default_scheme;
        }
        
        // Update the user's color scheme
        update_user_meta($user_id, 'admin_color', $scheme);
    }
    
    /**
     * Handle AJAX request to update color scheme
     */
    public function handle_color_scheme_update() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_allstars_color_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        // Verify user can manage options
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get the new value
        $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false;
        
        // Update the option
        update_option($this->option_name, $enabled);
        
        // Get current user
        $user_id = get_current_user_id();
        
        // Set the color scheme
        if ($enabled) {
            $this->set_user_color_scheme($user_id, $this->modern_scheme);
            $message = 'Modern admin colors enabled';
        } else {
            $this->set_user_color_scheme($user_id, $this->default_scheme);
            $message = 'Default admin colors restored';
        }
        
        // Send success response
        wp_send_json_success(array(
            'message' => $message,
            'enabled' => $enabled,
        ));
    }
    
    /**
     * Check if modern color scheme is enabled
     *
     * @return bool Whether modern color scheme is enabled
     */
    public function is_modern_color_scheme_enabled() {
        return (bool) get_option($this->option_name, false);
    }
} 