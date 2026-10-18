<?php
/**
 * File and account integrity checks: every installed plugin, active or not,
 * against WordPress.org's checksums or the record kept of it. Files already
 * matched, with the same size and time, are not hashed again.
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

class SEOProStack_Integrity_Plugins {

    /**
     * Check plugins one at a time, from where the last part stopped.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    public static function step(array &$state, $start) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        ksort($plugins);
        $files   = array_keys($plugins);
        $updates = get_site_transient('update_plugins');
        $from    = (int) $state['pos'];
        $count   = count($files);
        for ($i = $from; $i < $count; $i++) {
            if ($i > $from && !SEOProStack_Feature::more_time($start, SEOProStack_Integrity_Checks::BUDGET)) {
                $state['pos'] = $i;
                return false;
            }
            $file = (string) $files[$i];
            if ('hello.php' === $file) {
                continue; // WordPress's own copy: checked with core.
            }
            $state['records'][] = SEOProStack_Integrity_Records::name($file);
            self::check($state, $file, $plugins[$file], $updates);
        }
        return true;
    }

    /**
     * Check one plugin against WordPress.org's checksums or its own record.
     *
     * @param array<string,mixed> $state   Check in progress.
     * @param string              $file    Plugin file.
     * @param array<string,mixed> $data    Plugin headers.
     * @param mixed               $updates The update_plugins transient.
     */
    private static function check(array &$state, $file, array $data, $updates) {
        list($base, $label, $list) = SEOProStack_Integrity_Files::plugin_files($file);
        $name = isset($data['Name']) ? (string) $data['Name'] : $file;
        if (null === $list) {
            /* translators: %s: plugin name */
            SEOProStack_Integrity_Scan::unchecked($state, 'local', sprintf(__('%s: too many files to check.', 'seoprostack'), $name));
            return;
        }
        $plugin = array(
            'file'    => $file,
            'name'    => $name,
            'version' => isset($data['Version']) ? (string) $data['Version'] : '',
            'base'    => $base,
            'label'   => $label,
        );
        $record = get_option(SEOProStack_Integrity_Records::name($file));
        $slug   = SEOProStack_Integrity_Records::wporg_slug($file, $data, $updates);
        if ('' !== $slug && self::check_wporg($state, $plugin, $slug, $list, $record)) {
            return;
        }
        // Not from WordPress.org, or no checksums for this version (a development copy).
        self::check_local($state, $plugin, $list, $record);
    }

    /**
     * Check a WordPress.org plugin with its checksums.
     *
     * @param array<string,mixed>              $state  Check in progress.
     * @param array<string,string>             $plugin File, name, version, base and label.
     * @param string                           $slug   WordPress.org slug.
     * @param array<string,array{0:int,1:int}> $list   Code files.
     * @param mixed                            $record Stored record.
     * @return bool False when WordPress.org has no checksums for this version: check with a record.
     */
    private static function check_wporg(array &$state, array $plugin, $slug, array $list, $record) {
        $known  = SEOProStack_Integrity_Records::files($record, $plugin['version'], 'wporg');
        $result = self::compare_wporg($state, $slug, $plugin, $list, null === $known ? array() : $known);
        if (false === $result) {
            /* translators: %s: plugin name */
            SEOProStack_Integrity_Scan::unchecked($state, 'wporg', sprintf(__('%s: WordPress.org’s checksums could not be fetched.', 'seoprostack'), $plugin['name']));
            return true;
        }
        if (!is_array($result)) {
            return false;
        }
        if ($result !== $known) {
            SEOProStack_Integrity_Records::save($plugin['file'], $plugin['version'], 'wporg', $result);
        }
        return true;
    }

    /**
     * Compare a WordPress.org plugin's code with its checksums. Checksums
     * are fetched only when a file needs them.
     *
     * @param array<string,mixed>                       $state  Check in progress.
     * @param string                                    $slug   WordPress.org slug.
     * @param array<string,string>                      $plugin File, name, version, base and label.
     * @param array<string,array{0:int,1:int}>          $list   Code files.
     * @param array<string,array{0:int,1:int,2:string}> $known  Files matched before.
     * @return array<string,array{0:int,1:int,2:string}>|false|null Matched files; false when the checksums
     *                                                              could not be fetched; null when there are none.
     */
    private static function compare_wporg(array &$state, $slug, array $plugin, array $list, array $known) {
        list($matched, $pending) = self::split_known($list, $known);
        if (!$pending) {
            return $matched;
        }
        $sums = SEOProStack_Integrity_Wporg::plugin_sums($slug, $plugin['version']);
        if (!is_array($sums)) {
            return $sums;
        }
        foreach ($pending as $path => $meta) {
            $md5 = isset($sums[$path]) ? SEOProStack_Integrity_Wporg::matches($plugin['base'] . '/' . $path, $sums[$path]) : '';
            if ('' !== $md5) {
                $matched[$path] = array($meta[0], $meta[1], $md5);
            } elseif (isset($sums[$path]) || SEOProStack_Integrity_Files::is_php($path)) {
                SEOProStack_Integrity_Scan::found_path($state, 'wporg', $plugin['label'] . $path);
            }
        }
        ksort($matched);
        return $matched;
    }

    /**
     * Files matched before, with the same size and time, and the rest.
     *
     * @param array<string,array{0:int,1:int}>          $list  Code files.
     * @param array<string,array{0:int,1:int,2:string}> $known Files matched before.
     * @return array{0:array<string,array{0:int,1:int,2:string}>,1:array<string,array{0:int,1:int}>}
     */
    private static function split_known(array $list, array $known) {
        $matched = array();
        $pending = array();
        foreach ($list as $path => $meta) {
            if (isset($known[$path]) && $known[$path][0] === $meta[0] && $known[$path][1] === $meta[1]) {
                $matched[$path] = $known[$path];
            } else {
                $pending[$path] = $meta;
            }
        }
        return array($matched, $pending);
    }

    /**
     * Check a plugin with the record kept of it. Installed, updated or
     * changed version outside the updater: a new record, nothing found.
     *
     * @param array<string,mixed>              $state  Check in progress.
     * @param array<string,string>             $plugin File, name, version, base and label.
     * @param array<string,array{0:int,1:int}> $list   Code files.
     * @param mixed                            $record Stored record.
     */
    private static function check_local(array &$state, array $plugin, array $list, $record) {
        $known = SEOProStack_Integrity_Records::files($record, $plugin['version'], 'local');
        if (null === $known) {
            SEOProStack_Integrity_Records::save($plugin['file'], $plugin['version'], 'local', SEOProStack_Integrity_Files::hash_all($plugin['base'], $list));
            return;
        }
        foreach ($list as $path => $meta) {
            if (self::local_changed($plugin['base'], $path, $meta, $known)) {
                SEOProStack_Integrity_Scan::found_path($state, 'local', $plugin['label'] . $path);
            }
        }
    }

    /**
     * Whether a file differs from the record: a new PHP file, or a changed
     * size or time with a different hash.
     *
     * @param string                                    $base  Plugin folder.
     * @param string                                    $path  File.
     * @param array{0:int,1:int}                        $meta  Size and time.
     * @param array<string,array{0:int,1:int,2:string}> $known Record.
     * @return bool
     */
    private static function local_changed($base, $path, array $meta, array $known) {
        if (!isset($known[$path])) {
            return SEOProStack_Integrity_Files::is_php($path);
        }
        if ($known[$path][0] === $meta[0] && $known[$path][1] === $meta[1]) {
            return false;
        }
        $hash = SEOProStack_Integrity_Files::hash($base . '/' . $path);
        return false !== $hash && $hash !== $known[$path][2];
    }
}
