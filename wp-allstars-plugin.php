<?php
/**
 * Plugin Name: WP ALLSTARS Plugin
 * Plugin URI: https://www.wpallstars.com
 * Description: WP ALLSTARS Plugin for WordPress. Speed Matters.
 * Version: 1.0.0
 * Author: WP ALLSTARS
 * Author URI: https://www.wpallstars.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-allstars
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin version
define( 'WP_ALLSTARS_VERSION', '1.0.0' );

// Activation hook
function wp_allstars_activate() {
    // Add activation logic later if needed
}
register_activation_hook( __FILE__, 'wp_allstars_activate' );

// Load core functionality
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-allstars-auto-upload.php';

// Load admin UI
if ( is_admin() ) {
    require_once plugin_dir_path( __FILE__ ) . 'admin/settings.php';
}

// Localize script for AJAX
function wp_allstars_localize_script() {
    wp_localize_script( 'wp-allstars-admin', 'wpAllstars', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'wp-allstars-nonce' )
    ] );
}
add_action( 'admin_enqueue_scripts', 'wp_allstars_localize_script' );

// Admin assets
function wp_allstars_admin_assets() {
    // Enqueue styles
    wp_enqueue_style( 
        'wp-allstars-admin', 
        plugins_url( 'admin/css/wp-allstars-admin.css', __FILE__ ), 
        array(), 
        WP_ALLSTARS_VERSION 
    );

    // Enqueue script
    wp_enqueue_script( 
        'wp-allstars-admin', 
        plugins_url( 'admin/js/wp-allstars-admin.js', __FILE__ ), 
        array('jquery'), 
        WP_ALLSTARS_VERSION, 
        true 
    );

    // Localize script for AJAX
    $ajax_data = array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'wp-allstars-nonce' )
    );
    wp_localize_script( 'wp-allstars-admin', 'wpAllstars', $ajax_data );
}
add_action( 'admin_enqueue_scripts', 'wp_allstars_admin_assets' );

// Initialize classes
$wp_allstars_auto_upload = new WP_Allstars_Auto_Upload();