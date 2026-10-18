<?php
/**
 * File and account integrity checks: which plugins WordPress.org's checksums
 * cover, and the records kept of the others (premium, GitHub-hosted): each
 * file's size, time and SHA-256 hash, made when the plugin is installed,
 * updated or first seen.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.56
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Integrity_Records {

    /** Option prefix of a plugin's record, + SHA-256 of the plugin file (not autoloaded). */
    const RECORD = 'seoprostack_integrity_p_';

    /**
     * The option name of a plugin's record.
     *
     * @param string $file Plugin file.
     * @return string
     */
    public static function name($file) {
        return self::RECORD . hash('sha256', (string) $file);
    }

    /**
     * The files of a record for this version and source, or null.
     *
     * @param mixed  $record  Stored record.
     * @param string $version Installed version.
     * @param string $source  wporg or local.
     * @return array<string,array{0:int,1:int,2:string}>|null
     */
    public static function files($record, $version, $source) {
        if (!is_array($record) || !isset($record['version'], $record['source'], $record['files']) || !is_array($record['files'])
            || $version !== $record['version'] || $source !== $record['source']) {
            return null;
        }
        return $record['files'];
    }

    /**
     * Save a record.
     *
     * @param string                                    $file    Plugin file.
     * @param string                                    $version Version.
     * @param string                                    $source  wporg or local.
     * @param array<string,array{0:int,1:int,2:string}> $files   Files.
     */
    public static function save($file, $version, $source, array $files) {
        update_option(self::name($file), array('plugin' => $file, 'version' => $version, 'source' => $source, 'files' => $files), false);
    }

    /**
     * The WordPress.org slug of a plugin from WordPress.org, or ''. Only
     * plugins WordPress.org's update check names as its own count (w.org/
     * plugins/ IDs), so a premium or GitHub plugin of the same name is
     * never compared with another plugin's checksums. SEO Pro Stack's own
     * GitHub builds differ from its WordPress.org build, so it keeps a record.
     *
     * @param string              $file    Plugin file.
     * @param array<string,mixed> $data    Plugin headers.
     * @param mixed               $updates The update_plugins transient.
     * @return string
     */
    public static function wporg_slug($file, array $data, $updates) {
        if (plugin_basename(SEOPROSTACK_FILE) === $file || !is_object($updates) || !self::wporg_update_uri($data)) {
            return '';
        }
        foreach (array('response', 'no_update') as $group) {
            $slug = self::listed_slug($updates, $group, $file);
            if ('' !== $slug) {
                return $slug;
            }
        }
        return '';
    }

    /**
     * Whether a plugin's Update URI, if it has one, is WordPress.org's.
     *
     * @param array<string,mixed> $data Plugin headers.
     * @return bool
     */
    private static function wporg_update_uri(array $data) {
        if (empty($data['UpdateURI'])) {
            return true;
        }
        $host = wp_parse_url(esc_url_raw((string) $data['UpdateURI']), PHP_URL_HOST);
        return in_array($host, array('w.org', 'wordpress.org'), true);
    }

    /**
     * The slug WordPress.org's update check lists for a plugin, or ''.
     *
     * @param object $updates The update_plugins transient.
     * @param string $group   response or no_update.
     * @param string $file    Plugin file.
     * @return string
     */
    private static function listed_slug($updates, $group, $file) {
        $items = isset($updates->$group) && is_array($updates->$group) ? $updates->$group : array();
        $item  = isset($items[$file]) ? (object) $items[$file] : null;
        if ($item && isset($item->id, $item->slug) && 0 === strpos((string) $item->id, 'w.org/plugins/')) {
            return (string) $item->slug;
        }
        return '';
    }

    /**
     * A plugin was installed or updated through WordPress: record its files
     * now, so later changes are found. Plugins from WordPress.org are
     * checked against its checksums instead.
     *
     * @param mixed $upgrader Upgrader.
     * @param mixed $extra    What it did.
     */
    public static function upgraded($upgrader, $extra) {
        if (!is_array($extra) || !isset($extra['type']) || 'plugin' !== $extra['type']) {
            return;
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $updates = get_site_transient('update_plugins');
        $start   = microtime(true);
        foreach (self::upgraded_files($upgrader, $extra) as $file) {
            self::record_upgraded((string) $file, $updates, $start);
        }
    }

    /**
     * The plugin files an upgrade installed or updated.
     *
     * @param mixed                $upgrader Upgrader.
     * @param array<string,mixed>  $extra    What it did.
     * @return array<int,mixed>
     */
    private static function upgraded_files($upgrader, array $extra) {
        if (!empty($extra['plugins']) && is_array($extra['plugins'])) {
            return array_values($extra['plugins']);
        }
        if (!empty($extra['plugin'])) {
            return array($extra['plugin']);
        }
        if (is_object($upgrader) && method_exists($upgrader, 'plugin_info')) {
            $info = $upgrader->plugin_info();
            return $info ? array($info) : array();
        }
        return array();
    }

    /**
     * Record one upgraded plugin, or drop its record so the next check
     * makes one: when out of time, or when it is from WordPress.org.
     *
     * @param string $file    Plugin file.
     * @param mixed  $updates The update_plugins transient.
     * @param float  $start   When recording began.
     */
    private static function record_upgraded($file, $updates, $start) {
        $path = untrailingslashit(WP_PLUGIN_DIR) . '/' . $file;
        if (!is_file($path) || !SEOProStack_Feature::more_time($start, 10)) {
            delete_option(self::name($file));
            return;
        }
        $data = get_plugin_data($path, false, false);
        list($base, , $list) = SEOProStack_Integrity_Files::plugin_files($file);
        if (null === $list || '' !== self::wporg_slug($file, $data, $updates)) {
            delete_option(self::name($file));
            return;
        }
        self::save($file, (string) $data['Version'], 'local', SEOProStack_Integrity_Files::hash_all($base, $list));
    }
}
