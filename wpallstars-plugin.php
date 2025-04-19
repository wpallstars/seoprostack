<?php
/**
 * WPALLSTARS Plugin
 *
 * A comprehensive WordPress optimization and management tool designed to enhance
 * site performance, improve workflow, and provide recommendations for plugins and hosting.
 *
 * @package WPALLSTARS
 * @version 0.2.3.3
 *
 * Plugin Name: WP Allstars
 * Plugin URI: https://wpallstars.com
 * Description: A superstar stack of premium WordPress functionality, designed for SEO pros.
 * Author: Marcus Quinn
 * Author URI: https://wpallstars.com
 * Text Domain: wpallstars
 * Domain Path: /languages
 * Version: 0.2.3.3
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('WPINC')) {
    exit; // Exit if accessed directly
}

// --- Constants ---
define('WPALLSTARS_VERSION', '0.2.3.3');
define('WPALLSTARS_PATH', plugin_dir_path(__FILE__));
define('WPALLSTARS_URL', plugin_dir_url(__FILE__));
define('WPALLSTARS_BASENAME', plugin_basename(__FILE__));
define('WPALLSTARS_TEXT_DOMAIN', 'wpallstars'); // Consistent text domain constant

/**
 * Load files safely by checking if they exist first.
 *
 * @param string $file Full path to the file.
 * @return bool True if the file was loaded, false otherwise.
 */
function wpallstars_require_if_exists($file) {
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    // Optional: Add error logging here if a required file is missing
    // error_log('WP Allstars critical file missing: ' . $file);
    return false;
}

/**
 * Check for sync operations before loading the plugin.
 * Prevents errors during file synchronization (e.g., via Git or cloud storage).
 */
if (file_exists(WPALLSTARS_PATH . '.syncing')) {
    if (is_admin()) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible">';
            printf(
                '<p><strong>%s:</strong> %s</p>',
                esc_html__( 'WP Allstars', WPALLSTARS_TEXT_DOMAIN ),
                esc_html__( 'Plugin files are currently syncing. Functionality is temporarily disabled. Please try again shortly.', WPALLSTARS_TEXT_DOMAIN )
            );
            echo '</div>';
        });
    }
    return; // Exit early to prevent loading other files during sync
}

// --- Core Includes ---
// Load Sync Guard (if used for more advanced detection)
wpallstars_require_if_exists(WPALLSTARS_PATH . 'includes/class-wpallstars-sync-guard.php');
// Core features
wpallstars_require_if_exists(WPALLSTARS_PATH . 'includes/class-wpallstars-auto-upload.php');
wpallstars_require_if_exists(WPALLSTARS_PATH . 'includes/class-wpallstars-admin-colors.php');
wpallstars_require_if_exists(WPALLSTARS_PATH . 'includes/class-wpallstars-ui-enhancements.php');

// --- Admin Includes ---
if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) { // Load admin for AJAX requests too
    $admin_includes = [
        'admin/includes/class-admin-manager.php',
        'admin/includes/class-settings-manager.php',
        'admin/includes/class-theme-manager.php',
        'admin/includes/class-workflow-manager.php',
        'admin/includes/class-pro-plugins-manager.php',
        'admin/includes/class-tools-manager.php',
        'admin/includes/class-hosting-manager.php',
        'admin/includes/class-plugin-manager.php', // Assuming this exists
        'admin/includes/class-free-plugins-manager.php',
        'admin/includes/class-readme-manager.php'
    ];

    foreach ($admin_includes as $relative_path) {
        // No need to anticipate renaming anymore, use the correct path directly
        wpallstars_require_if_exists(WPALLSTARS_PATH . $relative_path);
    }

    // Data files (ensure these are still needed and paths are correct)
    wpallstars_require_if_exists(WPALLSTARS_PATH . 'admin/data/pro-plugins.php');
    wpallstars_require_if_exists(WPALLSTARS_PATH . 'admin/data/readme.php');

    // Load admin settings initialization file (if it sets up hooks, etc.)
    // Deprecated: Settings should be handled by Settings_Manager and Admin_Manager
    // wpallstars_require_if_exists(WPALLSTARS_PATH . 'admin/settings.php');
}

/**
 * Plugin activation hook.
 * Used for setting up default options, database tables (if any), etc.
 */
function wpallstars_activate() {
    // Example: Set default options on first activation
    if (get_option('wpallstars_version') === false) {
        update_option('wpallstars_version', WPALLSTARS_VERSION);
        // Add other default options here if needed
        // update_option('wpallstars_options', ['feature_x_enabled' => 1]);
    }
    // Optional: Clear rewrite rules if custom post types or taxonomies are added
    // flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wpallstars_activate');

/**
 * Plugin deactivation hook.
 * Used for cleanup tasks if necessary.
 */
function wpallstars_deactivate() {
    // Example: Remove scheduled tasks
    // wp_clear_scheduled_hook('wpallstars_daily_task');
    // Optional: Clear rewrite rules
    // flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wpallstars_deactivate');


/**
 * Load the plugin text domain for localization.
 */
function wpallstars_load_textdomain() {
    load_plugin_textdomain(
        WPALLSTARS_TEXT_DOMAIN,
        false,
        dirname(WPALLSTARS_BASENAME) . '/languages'
    );
}
add_action('plugins_loaded', 'wpallstars_load_textdomain');


/**
 * Initialize core plugin features after plugins are loaded.
 * This ensures that all necessary WordPress functions and classes are available.
 */
function wpallstars_init_features() {
    // Initialize Core Features
    if (class_exists('WPALLSTARS_Admin_Colors')) {
        new WPALLSTARS_Admin_Colors();
    }
    if (class_exists('WPALLSTARS_UI_Enhancements')) {
        new WPALLSTARS_UI_Enhancements();
    }

    // Initialize Admin Area (if in admin context or AJAX)
    if ((is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) && class_exists('WPALLSTARS_Admin_Manager')) {
        WPALLSTARS_Admin_Manager::instance(); // Use singleton pattern if implemented
    }
}
add_action('plugins_loaded', 'wpallstars_init_features', 10); // Priority 10 is standard


/**
 * Initialize Auto Upload feature later on 'init'.
 * This ensures the user is logged in and potentially allows other plugins to load first.
 */
function wpallstars_init_auto_upload() {
    // Only initialize for logged-in users
    if (is_user_logged_in() && class_exists('WPALLSTARS_Auto_Upload')) {
        new WPALLSTARS_Auto_Upload();
    }
}
add_action('init', 'wpallstars_init_auto_upload');


/**
 * Add settings link on the plugins page.
 *
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
function wpallstars_add_settings_link($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=wpallstars-settings')), // Match the menu slug from Admin_Manager
        esc_html__( 'Settings', WPALLSTARS_TEXT_DOMAIN )
    );
    array_unshift($links, $settings_link); // Add link to the beginning
    return $links;
}
add_filter('plugin_action_links_' . WPALLSTARS_BASENAME, 'wpallstars_add_settings_link');

// --- End of Plugin ---