<?php
/**
 * Plugin Name:       WP Allstars
 * Plugin URI:        https://www.wpallstars.com/
 * Description:       A superstar stack of premium WordPress functionality, designed for SEO pros.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marcus Quinn
 * Author URI:        https://www.wpallstars.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-allstars
 * Domain Path:       /languages
 *
 * WP Allstars is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * WP Allstars is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package WP_ALLSTARS
 */

if (!defined('WPINC')) {
    exit;
}

define('WP_ALLSTARS_VERSION', '0.3.0');
define('WP_ALLSTARS_FILE', __FILE__);
define('WP_ALLSTARS_DIR', plugin_dir_path(__FILE__));
define('WP_ALLSTARS_URL', plugin_dir_url(__FILE__));

// Development sync guard: a `.syncing` flag file pauses the plugin while files are copied.
require_once WP_ALLSTARS_DIR . 'includes/class-wp-allstars-sync-guard.php';
if (WP_Allstars_Sync_Guard::handle_sync_mode()) {
    return;
}

require_once WP_ALLSTARS_DIR . 'includes/class-wp-allstars-settings.php';
require_once WP_ALLSTARS_DIR . 'includes/class-wp-allstars-admin-colors.php';
require_once WP_ALLSTARS_DIR . 'includes/class-wp-allstars-auto-upload.php';

WP_Allstars_Settings::init();

if (is_admin()) {
    require_once WP_ALLSTARS_DIR . 'admin/settings.php';
}

/**
 * Start features once all plugins are loaded.
 */
function wp_allstars_init_features() {
    new WP_Allstars_Admin_Colors();
    new WP_Allstars_Auto_Upload();
}
add_action('plugins_loaded', 'wp_allstars_init_features');

/**
 * Load translations.
 */
function wp_allstars_load_textdomain() {
    load_plugin_textdomain('wp-allstars', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'wp_allstars_load_textdomain');
