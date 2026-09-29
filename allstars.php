<?php
/**
 * Plugin Name:       Allstars
 * Plugin URI:        https://www.wpallstars.com/
 * Description:       Opt-in admin and workflow features (magic login links, publishing queue, iFrame block, image importing) plus curated plugin, theme, hosting and tool recommendations.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marcus Quinn
 * Author URI:        https://www.wpallstars.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       allstars
 *
 * Allstars is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Allstars is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package Allstars
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALLSTARS_VERSION', '0.3.0');
define('ALLSTARS_FILE', __FILE__);
define('ALLSTARS_DIR', plugin_dir_path(__FILE__));
define('ALLSTARS_URL', plugin_dir_url(__FILE__));

require_once ALLSTARS_DIR . 'includes/class-allstars.php';
Allstars::load();

// Translations load just-in-time from WordPress.org language packs (WP 4.6+).
