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
require_once plugin_dir_path(__FILE__) . 'includes/class-wpa-superstar-auto-upload.php';

// Load admin UI
if ( is_admin() ) {
    require_once plugin_dir_path( __FILE__ ) . 'admin/settings.php';
}

// Localize script for AJAX
function wpa_superstar_localize_script() {
    wp_localize_script( 'wpa-superstar-admin', 'wpaSuperstar', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'wpa-superstar-nonce' )
    ] );
}
add_action( 'admin_enqueue_scripts', 'wpa_superstar_localize_script' );

// Admin assets
function wpa_superstar_admin_assets() {
    // Enqueue styles
    wp_enqueue_style( 
        'wpa-superstar-admin', 
        plugins_url( 'admin/css/wpa-superstar-admin.css', __FILE__ ), 
        array(), 
        WPA_SUPERSTAR_VERSION 
    );

    // Enqueue script
    wp_enqueue_script( 
        'wpa-superstar-admin', 
        plugins_url( 'admin/js/wpa-superstar-admin.js', __FILE__ ), 
        array('jquery'), 
        WPA_SUPERSTAR_VERSION, 
        true 
    );

    // Localize script for AJAX
    $ajax_data = array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'wpa-superstar-nonce' )
    );
    wp_localize_script( 'wpa-superstar-admin', 'wpaSuperstar', $ajax_data );
}
add_action( 'admin_enqueue_scripts', 'wpa_superstar_admin_assets' );

/**
 * Register and enqueue lazy loading assets
 */
function wpa_superstar_lazy_load_assets() {
    // Enqueue styles for lazy loading
    wp_enqueue_style(
        'wpa-superstar-lazy-load',
        plugins_url( 'public/css/wpa-superstar-lazy-load.css', __FILE__ ),
        [],
        WPA_SUPERSTAR_VERSION
    );
}
// Hook the function to appropriate WordPress action
add_action( 'wp_enqueue_scripts', 'wpa_superstar_lazy_load_assets' );

// Initialize classes
$wpa_superstar_auto_upload = new WPA_Superstar_Auto_Upload();