<?php
/**
 * WP ALLSTARS Admin System Loader
 *
 * Loads all admin components and initializes manager classes
 * responsible for different tabs in the plugin interface.
 *
 * @package WP_ALLSTARS
 */

// Include data configuration files
require_once dirname(__FILE__) . '/data/tools.php';
require_once dirname(__FILE__) . '/data/hosting-providers.php';
require_once dirname(__FILE__) . '/data/free-plugins.php';

// Include manager classes - each handles a specific plugin functionality area
require_once dirname(__FILE__) . '/includes/class-plugin-manager.php';
require_once dirname(__FILE__) . '/includes/class-pro-plugins-manager.php';
require_once dirname(__FILE__) . '/includes/class-settings-manager.php';
require_once dirname(__FILE__) . '/includes/class-tools-manager.php';
require_once dirname(__FILE__) . '/includes/class-theme-manager.php';
require_once dirname(__FILE__) . '/includes/class-hosting-manager.php';
require_once dirname(__FILE__) . '/includes/class-free-plugins-manager.php';
require_once dirname(__FILE__) . '/includes/class-admin-manager.php';

/**
 * Initialize all manager classes
 * 
 * Each manager is responsible for a specific tab or functionality area
 * within the plugin's admin interface.
 */
WP_Allstars_Plugin_Manager::init();
WP_Allstars_Pro_Plugins_Manager::init();
WP_Allstars_Settings_Manager::init();
WP_Allstars_Tools_Manager::init();
WP_Allstars_Theme_Manager::init();
WP_Allstars_Hosting_Manager::init();
WP_Allstars_Free_Plugins_Manager::init();
WP_Allstars_Admin_Manager::init();
