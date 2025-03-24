<?php
/**
 * WP ALLSTARS Admin Manager
 * 
 * Handles admin-related functionality including menu registration, 
 * script enqueueing, and admin page rendering.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Allstars_Admin_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // Register hooks - we'll add more as we refactor each function
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('wp_ajax_wp_allstars_update_option', array(__CLASS__, 'update_option'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));
    }
    
    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook The current admin page hook
     */
    public static function enqueue_admin_scripts($hook) {
        if ('settings_page_wp-allstars' !== $hook) {
            return;
        }

        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', dirname(__FILE__)));
        wp_enqueue_script('wp-allstars-admin', plugins_url('js/wp-allstars-admin.js', dirname(__FILE__)), array('jquery'), WP_ALLSTARS_VERSION, true);
        
        // Localize the script with new data
        wp_localize_script('wp-allstars-admin', 'wpAllstars', array(
            'nonce' => wp_create_nonce('wp-allstars-nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }
    
    /**
     * Register plugin settings
     */
    public static function register_settings() {
        // Removed minification settings
        // This is a placeholder for future settings registration
    }
    
    /**
     * AJAX handler for updating options
     */
    public static function update_option() {
        check_ajax_referer('wp-allstars-nonce', 'nonce');
        $option = sanitize_text_field($_POST['option']);
        $value = intval($_POST['value']);
        update_option($option, $value);
        wp_send_json_success('Option updated');
    }
    
    /**
     * Register the admin menu item
     */
    public static function register_admin_menu() {
        add_options_page(
            'WP ALLSTARS Settings',
            'WP ALLSTARS',
            'manage_options',
            'wp-allstars',
            'wp_allstars_settings_page'
        );
    }
}
