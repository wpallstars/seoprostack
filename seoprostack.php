<?php
/**
 * Plugin Name:       SEO Pro Stack
 * Plugin URI:        https://www.wpallstars.com/
 * Description:       Opt-in admin and workflow features (magic login links, publishing queue, iFrame block, image importing) plus curated plugin, theme, hosting and tool recommendations.
 * Version:           0.5.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marcus Quinn
 * Author URI:        https://www.wpallstars.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seoprostack
 * GitHub Plugin URI: wpallstars/seoprostack
 * Primary Branch:    main
 * Release Asset:     true
 *
 * SEO Pro Stack is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * SEO Pro Stack is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEOPROSTACK_VERSION', '0.5.0');
define('SEOPROSTACK_FILE', __FILE__);
define('SEOPROSTACK_DIR', plugin_dir_path(__FILE__));
define('SEOPROSTACK_URL', plugin_dir_url(__FILE__));

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack.php';
SEOProStack::load();
register_deactivation_hook(__FILE__, array('SEOProStack', 'deactivate'));

// Translations load just-in-time from WordPress.org language packs (WP 4.6+).
