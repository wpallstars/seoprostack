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

require_once dirname(__DIR__) . '/class-seoprostack-update-offers.php';

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
        add_action('set_site_transient_update_plugins', array('SEOProStack_Update_Offers', 'record_plugins'));
        add_action('set_site_transient_update_themes', array('SEOProStack_Update_Offers', 'record_themes'));
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

    /**
     * A missing/corrupt record starts a fresh hold, including cached offers from
     * before the feature was enabled. No release date supplied by a vendor is trusted.
     *
     * @param string $type Plugin or theme.
     * @param mixed  $item Core update object.
     * @return int Hold deadline, or zero when not held.
     */
    private static function held_until($type, $item) {
        if (self::bypass()) {
            return 0;
        }
        return SEOProStack_Update_Offers::deadline($type, $item, self::hours());
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
            foreach (SEOProStack_Update_Offers::response(get_site_transient($transient)) as $slug => $offer) {
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
