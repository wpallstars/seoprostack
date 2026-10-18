<?php
/**
 * File and account integrity checks: the checksums WordPress.org publishes
 * for WordPress (get_core_checksums()) and for each version of its plugins
 * (downloads.wordpress.org/plugin-checksums/, which WP-CLI's
 * `wp plugin verify-checksums` reads). Both are md5, WordPress.org's format.
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

class SEOProStack_Integrity_Wporg {

    /** Transient: WordPress.org's core checksums for this version. */
    const CORE_SUMS = 'seoprostack_integrity_core';

    /** Transient prefix: WordPress.org's checksums for a plugin version. */
    const PLUGIN_SUMS = 'seoprostack_integrity_sums_';

    /** The Hello Dolly WordPress ships, checked with core. */
    const HELLO = 'wp-content/plugins/hello.php';

    /**
     * WordPress.org's checksums for this version: code files only, kept for
     * a week. Null when they cannot be fetched.
     *
     * @return array<string,array<int,string>>|null
     */
    public static function core_sums() {
        global $wp_local_package;
        $version = (string) get_bloginfo('version');
        $locale  = isset($wp_local_package) ? (string) $wp_local_package : 'en_US';
        $id      = $version . '|' . $locale;
        $cached  = self::cached_core_sums($id);
        if (null !== $cached) {
            return $cached;
        }
        if (!function_exists('get_core_checksums')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        add_filter('http_request_args', array(__CLASS__, 'short_timeout'));
        $sums = get_core_checksums($version, $locale);
        remove_filter('http_request_args', array(__CLASS__, 'short_timeout'));
        if (!is_array($sums) || !$sums) {
            return null;
        }
        $kept = self::core_code_sums($sums);
        set_transient(self::CORE_SUMS, array('id' => $id, 'sums' => $kept), WEEK_IN_SECONDS);
        return $kept;
    }

    /**
     * The kept core checksums, when they are for this version and locale.
     *
     * @param string $id Version and locale.
     * @return array<string,array<int,string>>|null
     */
    private static function cached_core_sums($id) {
        $cached = get_transient(self::CORE_SUMS);
        if (is_array($cached) && isset($cached['id'], $cached['sums']) && $id === $cached['id'] && is_array($cached['sums'])) {
            return $cached['sums'];
        }
        return null;
    }

    /**
     * Core checksums of code files. Of wp-content, only the Hello Dolly
     * WordPress ships (hello.php), as WP-CLI does.
     *
     * @param array<string,mixed> $sums Path => md5 (or md5s).
     * @return array<string,array<int,string>>
     */
    private static function core_code_sums(array $sums) {
        $kept = array();
        foreach ($sums as $path => $md5) {
            $path = (string) $path;
            if ((0 !== strpos($path, 'wp-content/') || self::HELLO === $path) && SEOProStack_Integrity_Files::is_code($path)) {
                $kept[$path] = array_values(array_map('strval', (array) $md5));
            }
        }
        return $kept;
    }

    /**
     * Wait at most 10 seconds for WordPress.org.
     *
     * @param array<string,mixed> $args Request arguments.
     * @return array<string,mixed>
     */
    public static function short_timeout($args) {
        $args['timeout'] = isset($args['timeout']) ? min(10, (float) $args['timeout']) : 10;
        return $args;
    }

    /**
     * Whether a file matches one of WordPress.org's md5 checksums for it.
     * md5 is the format WordPress.org publishes; it is compared, not used
     * to protect anything.
     *
     * @param string            $file Path.
     * @param array<int,string> $md5s Checksums.
     * @return string The file's md5 when it matches, or ''.
     */
    public static function matches($file, array $md5s) {
        $md5 = md5_file($file); // nosemgrep -- NOSONAR: WordPress.org publishes md5 checksums; a comparison with them, not security.
        return false !== $md5 && in_array($md5, $md5s, true) ? $md5 : '';
    }

    /**
     * WordPress.org's md5 checksums of a plugin version's code files. Kept
     * for a week (a day when there are none); null when there are none,
     * false when they could not be fetched.
     *
     * @param string $slug    Slug.
     * @param string $version Version.
     * @return array<string,array<int,string>>|false|null
     */
    public static function plugin_sums($slug, $version) {
        $key    = self::PLUGIN_SUMS . hash('sha256', $slug . '|' . $version);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ('none' === $cached) {
            return null;
        }
        $sums = self::fetch_plugin_sums($slug, $version);
        if (null === $sums) {
            set_transient($key, 'none', DAY_IN_SECONDS);
        } elseif (false !== $sums) {
            set_transient($key, $sums, WEEK_IN_SECONDS);
        }
        return $sums;
    }

    /**
     * Fetch a plugin version's checksums from WordPress.org.
     *
     * @param string $slug    Slug.
     * @param string $version Version.
     * @return array<string,array<int,string>>|false|null
     */
    private static function fetch_plugin_sums($slug, $version) {
        $url      = 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($slug) . '/' . rawurlencode($version) . '.json';
        $response = wp_remote_get($url, array('timeout' => 10)); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- cron only, at most once a week per plugin version; a large plugin's list can take seconds.
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if (404 === $code) {
            return null;
        }
        $data = 200 === $code ? json_decode(wp_remote_retrieve_body($response), true) : null;
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return false;
        }
        return self::code_sums($data['files']);
    }

    /**
     * The md5 checksums of code files, from a plugin's checksums list.
     *
     * @param array<string,mixed> $files Path => hashes.
     * @return array<string,array<int,string>>
     */
    private static function code_sums(array $files) {
        $sums = array();
        foreach ($files as $path => $hashes) {
            if (is_array($hashes) && isset($hashes['md5']) && SEOProStack_Integrity_Files::is_code((string) $path)) {
                $sums[(string) $path] = array_values(array_map('strval', (array) $hashes['md5']));
            }
        }
        return $sums;
    }
}
