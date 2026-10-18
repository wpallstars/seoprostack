<?php
/**
 * Hold WordPress automatic plugin and theme updates after first seeing a release.
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

class SEOProStack_Update_Delay extends SEOProStack_Feature {

    const KEY = 'update_delay';
    const OPTION = 'seoprostack_update_first_seen';

    /** @return array */
    public static function settings() {
        $settings = array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'security',
                'label'       => __('Delay automatic updates', 'seoprostack'),
                'description' => __('Hold WordPress’s own automatic plugin and theme updates after a release is first offered to this site. Security releases are held too. Manual updates, WP-CLI and updates pushed by remote dashboards are not held. This gives others time to spot problems; it does not scan downloads or guarantee they are safe.', 'seoprostack'),
            ),
            'update_delay_hours' => array(
                'type'        => 'int',
                'default'     => 72,
                'min'         => 0,
                'max'         => 336,
                'unit'        => __('hours', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Hold updates for', 'seoprostack'),
                'description' => __('72 hours is recommended. Set 0 to stop holding updates, or up to 336 hours (14 days).', 'seoprostack'),
            ),
        );
        if ('local' === wp_get_environment_type()) {
            $settings['update_canary'] = array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Update canary', 'seoprostack'),
                'description' => __('Let this local site take automatic updates without the hold. This choice is ignored on development, staging and production sites, even after copying this database.', 'seoprostack'),
            );
        }
        return $settings;
    }

    /** Register only when chosen; never hook the manual upgrader. */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('set_site_transient_update_plugins', array(__CLASS__, 'record_plugins'));
        add_action('set_site_transient_update_themes', array(__CLASS__, 'record_themes'));
        add_filter('auto_update_plugin', array(__CLASS__, 'filter_plugin'), PHP_INT_MAX, 2);
        add_filter('auto_update_theme', array(__CLASS__, 'filter_theme'), PHP_INT_MAX, 2);
        add_filter('plugin_auto_update_setting_html', array(__CLASS__, 'plugin_note'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'updates_notice'));
        add_action('network_admin_notices', array(__CLASS__, 'updates_notice'));
    }

    /** @return bool */
    private static function bypass() {
        return !self::enabled() || 0 === self::hours()
            || ('local' === wp_get_environment_type() && SEOProStack_Settings::get('update_canary'));
    }

    /** @return int */
    private static function hours() {
        return max(0, min(336, (int) SEOProStack_Settings::get('update_delay_hours')));
    }

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
     * Plugin basenames (not vendor slugs) also identify private plugins reliably.
     *
     * @param string $type Plugin or theme.
     * @param mixed  $value Update transient.
     */
    private static function record($type, $value) {
        if (!is_object($value) || !isset($value->response) || !is_array($value->response)) {
            return;
        }
        $installed = array();
        if ('plugin' === $type) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach (get_plugins() as $slug => $data) {
                $installed[$slug] = $data['Version'];
            }
        } else {
            foreach (wp_get_themes() as $slug => $theme) {
                $installed[$slug] = $theme->get('Version');
            }
        }
        $records = self::records();
        $times = isset($records[$type]) && is_array($records[$type]) ? $records[$type] : array();
        foreach ($times as $key => $seen) {
            $parts = explode('|', (string) $key, 2);
            if (2 !== count($parts) || !isset($installed[$parts[0]]) || version_compare($installed[$parts[0]], $parts[1], '>=')) {
                unset($times[$key]);
            }
        }
        foreach ($value->response as $slug => $item) {
            $item = (object) $item;
            if (!isset($installed[$slug], $item->new_version) || !is_string($item->new_version)
                || !version_compare($item->new_version, $installed[$slug], '>')) {
                continue;
            }
            $key = $slug . '|' . $item->new_version;
            if (!isset($times[$key]) || !is_int($times[$key]) || $times[$key] <= 0 || $times[$key] > time()) {
                $times[$key] = time();
            }
        }
        $records[$type] = $times;
        self::save($records);
    }

    /**
     * A missing/corrupt record starts a fresh hold, including cached offers from
     * before the feature was enabled. No release date supplied by a vendor is trusted.
     *
     * @param string $type Plugin or theme.
     * @param mixed  $item Core update object.
     * @return int Hold deadline, or zero when not held.
     */
    private static function held_until($type, $item) {
        if (self::bypass() || !is_object($item) || !isset($item->new_version, $item->$type)
            || !is_string($item->new_version) || !is_string($item->$type)) {
            return 0;
        }
        $records = self::records();
        $times = isset($records[$type]) && is_array($records[$type]) ? $records[$type] : array();
        $key = $item->$type . '|' . $item->new_version;
        $seen = $times[$key] ?? null;
        if (!is_int($seen) || $seen <= 0 || $seen > time()) {
            $seen = time();
            $times[$key] = $seen;
            $records[$type] = $times;
            self::save($records);
        }
        $until = $seen + self::hours() * HOUR_IN_SECONDS;
        return $until > time() ? $until : 0;
    }

    /**
     * @param mixed $update Earlier automatic-update decision.
     * @param mixed $item Update object.
     * @return mixed
     */
    public static function filter_plugin($update, $item) {
        return self::held_until('plugin', $item) ? false : $update;
    }

    /**
     * @param mixed $update Earlier automatic-update decision.
     * @param mixed $item Update object.
     * @return mixed
     */
    public static function filter_theme($update, $item) {
        return self::held_until('theme', $item) ? false : $update;
    }

    /**
     * @param int $until UTC deadline.
     * @return string
     */
    private static function message($until) {
        /* translators: %s: date and time in the site's timezone. */
        return sprintf(__('Automatic update held until %s. Manual updates are still available.', 'seoprostack'), wp_date(get_option('date_format') . ', ' . get_option('time_format') . ' T', $until));
    }

    /**
     * @param string $html Existing automatic-update controls.
     * @param string $plugin_file Plugin basename.
     * @param array  $plugin_data Plugin headers.
     * @return string
     */
    public static function plugin_note($html, $plugin_file, $plugin_data) {
        unset($plugin_data);
        $updates = get_site_transient('update_plugins');
        if (is_object($updates) && isset($updates->response[$plugin_file])) {
            $item = (object) $updates->response[$plugin_file];
            $item->plugin = $plugin_file;
            $until = self::held_until('plugin', $item);
            if ($until) {
                $html .= '<p>' . esc_html(self::message($until)) . '</p>';
            }
        }
        return $html;
    }

    /** Show held releases on the core Updates screen without changing its controls. */
    public static function updates_notice() {
        $screen = get_current_screen();
        if (!$screen || 'update-core' !== $screen->base || self::bypass()) {
            return;
        }
        $notes = array();
        foreach (array('plugin' => 'update_plugins', 'theme' => 'update_themes') as $type => $transient) {
            if (!current_user_can($transient)) {
                continue;
            }
            $updates = get_site_transient($transient);
            if (!is_object($updates) || !isset($updates->response) || !is_array($updates->response)) {
                continue;
            }
            foreach ($updates->response as $slug => $offer) {
                $item = (object) $offer;
                $item->$type = $slug;
                $until = self::held_until($type, $item);
                if ($until) {
                    $notes[] = $slug . ': ' . self::message($until);
                }
            }
        }
        if ($notes) {
            echo '<div class="notice notice-info"><p>' . implode('<br>', array_map('esc_html', $notes)) . '</p></div>';
        }
    }
}
