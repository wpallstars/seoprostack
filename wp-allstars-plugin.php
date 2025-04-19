<?php
/**
 * WP ALLSTARS Plugin
 *
 * A comprehensive WordPress optimization and management tool designed to enhance
 * site performance, improve workflow, and provide recommendations for plugins and hosting.
 *
 * @package WP_ALLSTARS
 * @version v0.2.3.3
 *
 * Plugin Name: WP Allstars
 * Plugin URI: https://wpallstars.com
 * Description: A superstar stack of premium WordPress functionality, designed for SEO pros.
 * Author: Marcus Quinn
 * Author URI: https://wpallstars.com
 * Text Domain: wp-allstars
 * Domain Path: /languages
 * @version v0.2.3.3
 * 
 * WP Allstars is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 * Version: v0.2.3.3 (Beta)
 *
 * WP Allstars is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with WP Allstars. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if (!defined('WPINC')) {
    exit;
}

define('WP_ALLSTARS_VERSION', 'v0.2.3.3');

/**
 * Load files safely by checking if they exist first
 */
function wp_allstars_require_if_exists($file) {
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
}

/**
 * Check for sync operations before loading the plugin
 */
// Simple sync detection - check for .syncing file
if (file_exists(__DIR__ . '/.syncing')) {
    // Only load the minimal code needed for admin notice
    if (is_admin()) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>WP Allstars:</strong> Plugin files are currently syncing. The plugin functionality is temporarily disabled to prevent errors. Please try again in a moment.</p>';
            echo '</div>';
        });
    }
    // Exit early to prevent loading other files
    return;
}

// Load the sync guard for future use and more advanced sync detection
wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-sync-guard.php');

/**
 * Plugin activation hook
 */
function wp_allstars_activate() {
    // Setup initial config
}
register_activation_hook(__FILE__, 'wp_allstars_activate');

// Core includes
wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-auto-upload.php');
wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-admin-colors.php');
wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-ui-enhancements.php');

// Admin includes
if (is_admin()) {
    $admin_includes = array(
        'admin/includes/class-admin-manager.php',
        'admin/includes/class-settings-manager.php',
        'admin/includes/class-theme-manager.php',
        'admin/includes/class-workflow-manager.php',
        'admin/includes/class-tools-manager.php',
        'admin/includes/class-hosting-manager.php',
        'admin/includes/class-pro-plugins-manager.php',
        'admin/includes/class-plugin-manager.php',
        'admin/includes/class-free-plugins-manager.php',
        'admin/includes/class-readme-manager.php'
    );
    
    foreach ($admin_includes as $file) {
        wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . $file);
    }
    
    // Settings and data
    wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . 'admin/data/pro-plugins.php');
    wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . 'admin/data/readme.php');
    
    // Admin settings
    wp_allstars_require_if_exists(plugin_dir_path(__FILE__) . 'admin/settings.php');
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
    // Initialize Admin Colors feature if the class exists
    if (class_exists('WP_Allstars_Admin_Colors')) {
        new WP_Allstars_Admin_Colors();
    }
    
    // Initialize UI Enhancements if the class exists
    if (class_exists('WP_Allstars_UI_Enhancements')) {
        new WP_Allstars_UI_Enhancements();
    }
}
add_action('plugins_loaded', 'wp_allstars_init_features');