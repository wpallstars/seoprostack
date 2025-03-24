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
