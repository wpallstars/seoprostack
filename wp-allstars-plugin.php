<?php
/**
 * WP ALLSTARS Plugin
 *
 * A comprehensive WordPress optimization and management tool designed to enhance
 * site performance, improve workflow, and provide recommendations for plugins and hosting.
 *
 * @package WP_ALLSTARS
 * @version v0.2.0
 *
 * Plugin Name: WP ALLSTARS Plugin
 * Plugin URI: https://www.wpallstars.com
 * Description: WP ALLSTARS Plugin for WordPress. Speed Matters.
 * Version: v0.2.0 (Beta)
 * Author: WP ALLSTARS
 * Author URI: https://www.wpallstars.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-allstars
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Define plugin version from the file header
 */
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

$plugin_data = get_plugin_data(__FILE__, false, false);
define('WP_ALLSTARS_VERSION', $plugin_data['Version']);

/**
 * Plugin activation hook
 * 
 * Called when the plugin is activated.
 * Initialize plugin settings and defaults here.
 */
function wp_allstars_activate() {
    // Create initial plugin settings
    // Register cron jobs if needed
    // Initialize defaults
}
register_activation_hook(__FILE__, 'wp_allstars_activate');

/**
 * Load core plugin components
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-auto-upload.php';

/**
 * Load admin-specific components
 */
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'admin/settings.php';
}



/**
 * Enqueue admin assets
 * 
 * Loads the CSS and JavaScript files for the admin interface.
 * Localizes the JavaScript with necessary data for AJAX operations.
 */
function wp_allstars_admin_assets() {
    // Only load assets on plugin pages to avoid conflicts
    $screen = get_current_screen();
    if (!isset($screen->id) || strpos($screen->id, 'wp-allstars') === false) {
        return;
    }
    
    // Enqueue CSS
    wp_enqueue_style(
        'wp-allstars-admin', 
        plugins_url('admin/css/wp-allstars-admin.css', __FILE__), 
        [], 
        WP_ALLSTARS_VERSION 
    );

    // Enqueue WordPress updates script for theme/plugin installation
    wp_enqueue_script('updates');
    
    // Enqueue main admin script
    wp_enqueue_script(
        'wp-allstars-admin', 
        plugins_url('admin/js/wp-allstars-admin.js', __FILE__), 
        ['jquery', 'updates'], 
        WP_ALLSTARS_VERSION, 
        true
    );

    // Localize script with AJAX and security data
    $ajax_data = [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'adminUrl' => admin_url(),
        'nonce' => wp_create_nonce('wp-allstars-nonce'),
        'updateNonce' => wp_create_nonce('updates')
    ];
    
    wp_localize_script('wp-allstars-admin', 'wpAllstars', $ajax_data);
}
add_action('admin_enqueue_scripts', 'wp_allstars_admin_assets');

/**
 * Initialize core plugin classes
 */
$wp_allstars_auto_upload = new WP_Allstars_Auto_Upload();