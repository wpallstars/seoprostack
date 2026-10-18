<?php
/**
 * First-seen record store for delayed automatic updates.
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

class SEOProStack_Update_Offers {

    const OPTION = 'seoprostack_update_first_seen';

    /** @return array */
    private static function records() {
        $records = is_multisite() ? get_site_option(self::OPTION, array()) : get_option(self::OPTION, array());
        return is_array($records) ? $records : array();
    }

    /** @param array $records First-seen times, separated by update type. */
    private static function save($records) {
        if (is_multisite()) {
            update_site_option(self::OPTION, $records);
        } else {
            update_option(self::OPTION, $records, false);
        }
    }

    /**
     * @param array  $records Record store.
     * @param string $type Plugin or theme.
     * @return array
     */
    private static function times($records, $type) {
        $times = $records[$type] ?? null;
        return is_array($times) ? $times : array();
    }

    /**
     * @param mixed $seen Stored timestamp.
     * @return bool
     * @phpstan-assert-if-true int $seen
     */
    private static function valid_time($seen) {
        return is_int($seen) && $seen > 0 && $seen <= time();
    }

    /**
     * Normalize core plugin objects and theme arrays, including private offers.
     *
     * @param mixed $value Update transient.
     * @return array
     */
    public static function response($value) {
        $response = is_object($value) ? ($value->response ?? null) : null;
        return is_array($response) ? $response : array();
    }

    /**
     * @param string $type Plugin or theme.
     * @return array Installed versions keyed by basename or stylesheet.
     */
    private static function installed($type) {
        $installed = array();
        if ('plugin' === $type) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach (get_plugins() as $slug => $data) {
                $installed[$slug] = $data['Version'];
            }
            return $installed;
        }
        foreach (wp_get_themes() as $slug => $theme) {
            $installed[$slug] = $theme->get('Version');
        }
        return $installed;
    }

    /**
     * @param array $times First-seen timestamps.
     * @param array $installed Installed versions.
     * @return array
     */
    private static function prune($times, $installed) {
        foreach (array_keys($times) as $key) {
            $parts = explode('|', (string) $key, 2);
            if (2 !== count($parts) || !isset($installed[$parts[0]]) || version_compare($installed[$parts[0]], $parts[1], '>=')) {
                unset($times[$key]);
            }
        }
        return $times;
    }

    /** @param mixed $value Update transient. */
    public static function record_plugins($value) {
        self::record('plugin', $value);
    }

    /** @param mixed $value Update transient. */
    public static function record_themes($value) {
        self::record('theme', $value);
    }

    /**
     * Keep times stable across checks, pruning removed or installed releases.
     *
     * @param string $type Plugin or theme.
     * @param mixed  $value Update transient.
     */
    private static function record($type, $value) {
        $installed = self::installed($type);
        $records = self::records();
        $times = self::prune(self::times($records, $type), $installed);
        foreach (self::response($value) as $slug => $item) {
            $item = (object) $item;
            if (!isset($installed[$slug], $item->new_version) || !is_string($item->new_version)
                || !version_compare($item->new_version, $installed[$slug], '>')) {
                continue;
            }
            $key = $slug . '|' . $item->new_version;
            if (!self::valid_time($times[$key] ?? null)) {
                $times[$key] = time();
            }
        }
        $records[$type] = $times;
        self::save($records);
    }

    /**
     * Validate identity without trusting a vendor-provided release date.
     *
     * @param string $type Plugin or theme.
     * @param mixed  $item Core update object.
     * @return string
     */
    private static function key($type, $item) {
        if (!is_object($item) || !isset($item->new_version, $item->$type)
            || !is_string($item->new_version) || !is_string($item->$type)) {
            return '';
        }
        return $item->$type . '|' . $item->new_version;
    }

    /**
     * Missing/corrupt data starts a fresh hold, including cached older offers.
     *
     * @param string $type Plugin or theme.
     * @param mixed  $item Core update object.
     * @param int    $hours Chosen delay.
     * @return int Hold deadline, or zero when not held.
     */
    public static function deadline($type, $item, $hours) {
        $key = self::key($type, $item);
        if ('' === $key) {
            return 0;
        }
        $records = self::records();
        $times = self::times($records, $type);
        $seen = $times[$key] ?? null;
        if (!self::valid_time($seen)) {
            $seen = time();
            $times[$key] = $seen;
            $records[$type] = $times;
            self::save($records);
        }
        $until = $seen + $hours * HOUR_IN_SECONDS;
        return $until > time() ? $until : 0;
    }
}
