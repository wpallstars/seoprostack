<?php
/**
 * SEO Pro Stack admin loader.
 *
 * Loads the settings screen, the Read Me tab and the Plugins screen notes
 * for replaced plugins, then the plugin's own admin parts
 * (SEOProStack_Setup::admin()). Included from the main plugin file for
 * admin requests only.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

$seoprostack_admin_files = array(
    'admin/data/readme.php',
    'admin/includes/class-settings-manager.php',
    'admin/includes/class-readme-manager.php',
    'admin/includes/class-admin-page.php',
    'admin/includes/class-admin-manager.php',
    'admin/includes/class-replaced-plugins.php',
);

foreach ($seoprostack_admin_files as $seoprostack_file) {
    require_once SEOPROSTACK_DIR . $seoprostack_file;
}
unset($seoprostack_admin_files, $seoprostack_file);

SEOProStack_Admin_Manager::init();
SEOProStack_Replaced_Plugins::init();
SEOProStack_Setup::admin();
