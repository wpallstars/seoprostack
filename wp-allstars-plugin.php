<?php
/**
 * WP ALLSTARS Plugin
 *
 * A comprehensive WordPress optimization and management tool designed to enhance
 * site performance, improve workflow, and provide recommendations for plugins and hosting.
 *
 * @package WP_ALLSTARS
 * @version v0.2.4
 *
 * Plugin Name: WP Allstars
 * Plugin URI: https://wpallstars.com
 * Description: A superstar stack of premium WordPress functionality, designed for SEO pros.
 * Author: Marcus Quinn
 * Author URI: https://wpallstars.com
 * Text Domain: wp-allstars
 * Domain Path: /languages
 * @version v0.2.4
 * 
 * WP Allstars is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 * Version: v0.2.4 (Beta)
 *
 * WP Allstars is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with WP Allstars. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 *
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
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-admin-colors.php';

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
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-readme-manager.php';
    require_once plugin_dir_path(__FILE__) . 'admin/includes/class-access-manager.php';
    
    // Initialize the admin manager
    add_action('plugins_loaded', array('WP_Allstars_Admin_Manager', 'init'));
    
    // Data files
    require_once plugin_dir_path(__FILE__) . 'admin/data/pro-plugins.php';
    require_once plugin_dir_path(__FILE__) . 'admin/data/readme.php';
    
    // Legacy files (for backward compatibility)
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
 * Initialize core features
 */
function wp_allstars_init_features() {
    // Initialize the Admin Colors feature
    new WP_Allstars_Admin_Colors();
    
    // Initialize the Access Manager
    WP_Allstars_Access_Manager::init();
}
add_action('plugins_loaded', 'wp_allstars_init_features');

/**
 * Initialize core plugin classes
 */
$wp_allstars_auto_upload = new WP_Allstars_Auto_Upload();