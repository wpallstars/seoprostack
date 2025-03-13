<?php
/**
 * Plugin Name: WP ALLSTARS Superstar Plugin
 * Plugin URI: https://www.wpallstars.com/superstar-plugin
 * Description: WP ALLSTARS Superstar Plugin for WordPress. Speed Matters.
 * Version: 1.0.0
 * Author: WP ALLSTARS
 * Author URI: https://www.wpallstars.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wpa-superstar
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin version
define( 'WPA_SUPERSTAR_VERSION', '1.0.0' );

// Activation hook
function wpa_superstar_activate() {
    // Add activation logic later if needed
}
register_activation_hook( __FILE__, 'wpa_superstar_activate' );

// Load core functionality
require_once plugin_dir_path( __FILE__ ) . 'includes/speed-functions.php';

// Load admin UI
if ( is_admin() ) {
    require_once plugin_dir_path( __FILE__ ) . 'admin/settings.php';
}