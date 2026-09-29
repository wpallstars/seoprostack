<?php
/**
 * SEO Pro Stack admin loader.
 *
 * Loads admin data files and tab managers, then initialises the admin
 * screen once. Included from the main plugin file for admin requests only.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

$seoprostack_admin_files = array(
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

foreach ($seoprostack_admin_files as $seoprostack_file) {
    require_once SEOPROSTACK_DIR . $seoprostack_file;
}
unset($seoprostack_admin_files, $seoprostack_file);

SEOProStack_Admin_Manager::init();
