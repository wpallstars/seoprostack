<?php
/**
 * WP Allstars admin loader.
 *
 * Loads admin data files and tab managers, then initialises the admin
 * screen once. Included from the main plugin file for admin requests only.
 *
 * @package WP_ALLSTARS
 */

if (!defined('ABSPATH')) {
    exit;
}

$wp_allstars_admin_files = array(
    'admin/data/free-plugins.php',
    'admin/data/pro-plugins.php',
    'admin/data/hosting-providers.php',
    'admin/data/tools.php',
    'admin/data/readme.php',
    'admin/includes/class-link-cards.php',
    'admin/includes/class-settings-manager.php',
    'admin/includes/class-plugin-manager.php',
    'admin/includes/class-free-plugins-manager.php',
    'admin/includes/class-pro-plugins-manager.php',
    'admin/includes/class-hosting-manager.php',
    'admin/includes/class-tools-manager.php',
    'admin/includes/class-theme-manager.php',
    'admin/includes/class-readme-manager.php',
    'admin/includes/class-admin-manager.php',
);

foreach ($wp_allstars_admin_files as $wp_allstars_file) {
    require_once WP_ALLSTARS_DIR . $wp_allstars_file;
}
unset($wp_allstars_admin_files, $wp_allstars_file);

WP_Allstars_Admin_Manager::init();
