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

if (!defined('WPINC')) {
    exit;
}

// Define plugin version from the file header
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

$plugin_data = get_plugin_data(__FILE__, false, false);
define('WP_ALLSTARS_VERSION', $plugin_data['Version']);

/**
 * Plugin activation hook
 */
function wp_allstars_activate() {
    // Setup initial configuration when needed
}
register_activation_hook(__FILE__, 'wp_allstars_activate');

/**
 * Load core plugin components
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-auto-upload.php';

// Load admin-specific components
if (is_admin()) {
    // Include manager classes
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-admin-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-settings-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-theme-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-workflow-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-tools-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-hosting-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-pro-plugins-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-plugin-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-free-plugins-manager.php';
    
    // Initialize the admin manager
    add_action('plugins_loaded', array('WP_Allstars_Admin_Manager', 'init'));
    
    // Legacy files (for backward compatibility)
    require_once plugin_dir_path(__FILE__) . 'admin/data/pro-plugins.php';
    require_once plugin_dir_path(__FILE__) . 'admin/settings.php';
}



/**
 * Auto Upload feature initialization
 * 
 * Initialize the Auto Upload feature when a user is logged in
 */
function wp_allstars_init_auto_upload() {
    // Only initialize for logged-in users
    if (is_user_logged_in()) {
        new WP_Allstars_Auto_Upload();
    }
}
add_action('init', 'wp_allstars_init_auto_upload');

/**
 * Initialize core plugin classes
 */
$wp_allstars_auto_upload = new WP_Allstars_Auto_Upload();