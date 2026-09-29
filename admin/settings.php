<?php
/**
 * Allstars admin loader.
 *
 * Loads admin data files and tab managers, then initialises the admin
 * screen once. Included from the main plugin file for admin requests only.
 *
 * @package Allstars
 */

if (!defined('ABSPATH')) {
    exit;
}

$allstars_admin_files = array(
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

foreach ($allstars_admin_files as $allstars_file) {
    require_once ALLSTARS_DIR . $allstars_file;
}
unset($allstars_admin_files, $allstars_file);

Allstars_Admin_Manager::init();
