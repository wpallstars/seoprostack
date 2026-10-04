<?php
/**
 * Plugin Name:       SEO Pro Stack
 * Plugin URI:        https://github.com/wpallstars/seoprostack
 * Description:       One free plugin for a faster, tidier WordPress. It does the jobs of 40+ single-purpose plugins, each a switch you turn on.
 * Version:           0.14.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marcus Quinn
 * Author URI:        https://www.wpallstars.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       seoprostack
 * GitHub Plugin URI: wpallstars/seoprostack
 * Primary Branch:    main
 * Release Asset:     true
 *
 * Copyright (C) 2025-2026 Marcus Quinn
 * Parts copyright (C) 2026 Marcus Quinn, from WP Plugin Starter (https://github.com/wpallstars/wp-plugin-starter-template-for-ai-coding)
 *
 * SEO Pro Stack is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version, with the additional terms in SEOPROSTACK-ATTRIBUTION.txt
 * and, for the parts from WP Plugin Starter, ATTRIBUTION.txt (section 7(b)
 * of the License: keep the copyright notices and the "Made from" credits).
 *
 * SEO Pro Stack is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details: LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEOPROSTACK_VERSION', '0.14.2');
define('SEOPROSTACK_FILE', __FILE__);
define('SEOPROSTACK_DIR', plugin_dir_path(__FILE__));
define('SEOPROSTACK_URL', plugin_dir_url(__FILE__));

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack.php';
SEOProStack::load();
register_deactivation_hook(__FILE__, array('SEOProStack', 'deactivate'));

// Translations load just-in-time from WordPress.org language packs (WP 4.6+).
