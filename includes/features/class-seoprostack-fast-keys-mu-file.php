<?php
/**
 * Faster database keys: the must-use file that adds the dbDelta filter to
 * wp-admin/upgrade.php, where plugins do not load (core updates upgrade the
 * database there). Only SEO Pro Stack's own marked file is ever written or
 * removed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Fast_Keys_Mu_File {

    /** File name in the must-use plugins folder. */
    const FILE = 'seoprostack-fast-keys.php';

    /** Marks the file as SEO Pro Stack's. */
    const MARKER = 'SEO Pro Stack faster database keys';

    /**
     * Install, update or remove the file.
     *
     * @param bool $on Whether it should be there.
     */
    public static function sync($on) {
        // Uninstall removes it without loading the main plugin file.
        if (!defined('WPMU_PLUGIN_DIR') || ($on && !defined('SEOPROSTACK_FILE'))) {
            return;
        }
        $fs   = self::filesystem();
        $path = WPMU_PLUGIN_DIR . '/' . self::FILE;
        $old  = is_file($path) ? (string) $fs->get_contents($path) : '';
        if ('' !== $old && false === strpos($old, self::MARKER)) {
            return; // Not ours.
        }
        if ($on) {
            self::write($fs, $path, $old);
        } elseif ('' !== $old) {
            $fs->delete($path);
        }
    }

    /**
     * Direct file access (the file is written by PHP, as WordPress writes
     * its own drop-ins).
     *
     * @return WP_Filesystem_Direct
     */
    private static function filesystem() {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new WP_Filesystem_Direct(null);
    }

    /**
     * Write the file when its code changed.
     *
     * @param WP_Filesystem_Direct $fs   Filesystem.
     * @param string               $path File path.
     * @param string               $old  Current contents ('' when missing).
     */
    private static function write($fs, $path, $old) {
        $code = self::code();
        if ($old !== $code && wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            $fs->put_contents($path, $code, 0644);
        }
    }

    /**
     * The file's code.
     *
     * @return string
     */
    private static function code() {
        $base = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(SEOPROSTACK_FILE)) . '/includes/';
        // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates PHP string literals.
        return "<?php\n// " . self::MARKER . ": keeps its keys when wp-admin/upgrade.php updates the database (plugins do not load there).\n"
            . 'if (defined(\'ABSPATH\') && !is_multisite() && is_file(' . var_export($base . 'features/class-seoprostack-fast-keys.php', true) . ")) {\n"
            . '    require_once ' . var_export($base . 'class-seoprostack-feature.php', true) . ";\n"
            . '    require_once ' . var_export($base . 'features/class-seoprostack-fast-keys.php', true) . ";\n"
            . "    SEOProStack_Fast_Keys::early();\n}\n";
        // phpcs:enable
    }
}
