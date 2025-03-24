<?php
/**
 * Admin settings page - Delegates to the WP_Allstars_Admin_Manager class
 * 
 * This file serves as a compatibility layer to keep backward compatibility
 * with existing code that might still call the old procedural functions.
 * All actual functionality has been moved to dedicated manager classes.
 */

// Include data files
require_once dirname(__FILE__) . '/data/tools.php';
require_once dirname(__FILE__) . '/data/hosting-providers.php';
require_once dirname(__FILE__) . '/data/recommended-plugins.php';

// Include manager classes
require_once dirname(__FILE__) . '/includes/class-plugin-manager.php';
require_once dirname(__FILE__) . '/includes/class-pro-plugins-manager.php';
require_once dirname(__FILE__) . '/includes/class-settings-manager.php';
require_once dirname(__FILE__) . '/includes/class-tools-manager.php';
require_once dirname(__FILE__) . '/includes/class-theme-manager.php';
require_once dirname(__FILE__) . '/includes/class-hosting-manager.php';
require_once dirname(__FILE__) . '/includes/class-recommended-plugins-manager.php';
require_once dirname(__FILE__) . '/includes/class-admin-manager.php';

// Initialize the managers
WP_Allstars_Plugin_Manager::init();
WP_Allstars_Pro_Plugins_Manager::init();
WP_Allstars_Settings_Manager::init();
WP_Allstars_Tools_Manager::init();
WP_Allstars_Theme_Manager::init();
WP_Allstars_Hosting_Manager::init();
WP_Allstars_Recommended_Plugins_Manager::init();
WP_Allstars_Admin_Manager::init();

// Remove the old plugins API filter since we're handling everything in the AJAX endpoint
remove_filter('plugins_api_result', 'wp_allstars_plugins_api_result');

// Add menu item - now handled by WP_Allstars_Admin_Manager class, but kept for backward compatibility
function wp_allstars_admin_menu() {
    // This function now redirects to the Admin Manager class
    // Kept for backward compatibility
    WP_Allstars_Admin_Manager::register_admin_menu();
}

// Register settings - now handled by WP_Allstars_Admin_Manager class, but kept for backward compatibility
function wp_allstars_register_settings() {
    // This function now redirects to the Admin Manager class
    // Kept for backward compatibility
    WP_Allstars_Admin_Manager::register_settings();
}

// AJAX handler for settings - now handled by WP_Allstars_Admin_Manager class, but kept for backward compatibility
function wp_allstars_update_option() {
    // This function now redirects to the Admin Manager class
    // Kept for backward compatibility
    WP_Allstars_Admin_Manager::update_option();
}

// Register settings page HTML - now handled by WP_Allstars_Admin_Manager class, but kept for backward compatibility
function wp_allstars_settings_page() {
    // This function now redirects to the Admin Manager class
    // Kept for backward compatibility
    WP_Allstars_Admin_Manager::render_settings_page();
}

// Enqueue admin scripts and styles - now handled by WP_Allstars_Admin_Manager class, but kept for backward compatibility
function wp_allstars_admin_enqueue_scripts($hook) {
    // This function now redirects to the Admin Manager class
    // Kept for backward compatibility
    WP_Allstars_Admin_Manager::enqueue_scripts($hook);
}