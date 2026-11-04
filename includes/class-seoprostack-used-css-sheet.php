<?php
/**
 * Used CSS: a stylesheet's rules (SEOProStack_Used_Css_Parser), read once
 * per version.
 *
 * Each stylesheet is read once per version (its file, size, modified time
 * and folder) into a cache file in uploads/seoprostack-used-css, so a page
 * only matches its classes, IDs and elements against the rules
 * (SEOProStack_Used_Css_Match).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Used_Css_Sheet {

    /** The reader's version: a change reads every stylesheet again. */
    const VERSION = '1';

    /** Folder in uploads for the cache files. */
    const DIR = 'seoprostack-used-css';

    /** Larger stylesheets are left as they are (bytes). */
    const MAX_BYTES = 1048576;

    /** Stylesheets read on this request: file => rules. */
    private static $read = array();

    /**
     * A stylesheet's rules (SEOProStack_Used_Css_Parser), read once per
     * version of the file.
     *
     * @param string $file Stylesheet file.
     * @param string $href Its address.
     * @return array|null Null when it cannot be read.
     */
    public static function rules($file, $href) {
        if (isset(self::$read[$file])) {
            return self::$read[$file];
        }
        $size = is_readable($file) ? (int) filesize($file) : 0;
        if ($size < 1 || $size > self::MAX_BYTES) {
            return null;
        }
        $base   = untrailingslashit(dirname((string) wp_parse_url($href, PHP_URL_PATH)));
        $folder = self::folder();
        $key    = substr(hash('sha256', $file), 0, 12);
        $name   = $key . '-' . substr(hash('sha256', filemtime($file) . '|' . $size . '|' . $base . '|' . self::VERSION), 0, 8) . '.json';
        $rules  = '' !== $folder ? self::cached($folder . '/' . $name) : null;
        if (null === $rules) {
            $rules = self::read($file, $base);
            if (null === $rules) {
                return null;
            }
            if ('' !== $folder) {
                self::write($folder, $name, $key, $rules);
            }
        }
        self::$read[$file] = $rules;
        return $rules;
    }

    /**
     * Read a stylesheet's file.
     *
     * @param string $file File.
     * @param string $base Its folder (URL path).
     * @return array|null
     */
    private static function read($file, $base) {
        $css = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local stylesheet.
        return is_string($css) ? SEOProStack_Used_Css_Parser::parse($css, $base) : null;
    }

    /**
     * Rules from the cache file, if it is there and whole.
     *
     * @param string $path Cache file.
     * @return array|null
     */
    private static function cached($path) {
        if (!is_file($path)) {
            return null;
        }
        $json  = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache file.
        $rules = is_string($json) ? json_decode($json, true) : null;
        return is_array($rules) ? $rules : null;
    }

    /**
     * Folder for this site's cache files.
     *
     * @return string Empty when uploads cannot be used.
     */
    public static function folder() {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }
        return untrailingslashit(wp_normalize_path($uploads['basedir'])) . '/' . self::DIR;
    }

    /**
     * Write a cache file whole (a temporary file, then renamed, as requests
     * run at once), and remove the stylesheet's older ones. A file already
     * there under the same name holds the same rules, so it stays.
     *
     * @param string $folder Folder.
     * @param string $name   File name.
     * @param string $key    The stylesheet's part of the name.
     * @param array  $rules  Rules.
     */
    private static function write($folder, $name, $key, array $rules) {
        $json = wp_json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || !wp_mkdir_p($folder)) {
            return;
        }
        $target = $folder . '/' . $name;
        $temp   = $target . '.' . wp_generate_password(8, false) . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file renamed into place.
        if (false === file_put_contents($temp, $json)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same folder, so the file appears whole.
        if (is_file($target) || !rename($temp, $target)) {
            wp_delete_file($temp);
            return;
        }
        foreach ((array) glob($folder . '/' . $key . '-*.json') as $old) {
            if (is_string($old) && $old !== $target) {
                wp_delete_file($old);
            }
        }
    }

    /** Remove this site's cache files. */
    public static function remove() {
        $folder = self::folder();
        if ('' === $folder || !is_dir($folder)) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $filesystem = new WP_Filesystem_Direct(null);
        $filesystem->delete($folder, true, 'd');
    }
}
