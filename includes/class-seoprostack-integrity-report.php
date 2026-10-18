<?php
/**
 * File and account integrity checks: the last results in Tools → Site Health
 * (and so `wp seoprostack health`) and under the setting. They read the
 * results only and never start a check.
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

class SEOProStack_Integrity_Report {

    /**
     * The checks: key => label.
     *
     * @return array<string,string>
     */
    public static function checks() {
        return array(
            'core'   => __('WordPress core files', 'seoprostack'),
            'wporg'  => __('Plugins from WordPress.org', 'seoprostack'),
            'local'  => __('Other plugins', 'seoprostack'),
            'tamper' => __('Uploaded PHP and new administrators', 'seoprostack'),
        );
    }

    /**
     * What a check compares.
     *
     * @param string $check Check.
     * @return string
     */
    private static function about($check) {
        switch ($check) {
            case 'core':
                return __('Compares WordPress’s own code files with the checksums WordPress.org publishes for this version, and looks for PHP files in wp-admin and wp-includes that WordPress does not have, where malware can hide as a core file.', 'seoprostack');
            case 'wporg':
                return __('Compares the code of plugins from WordPress.org with the checksums WordPress.org publishes for each version, as WP-CLI’s wp plugin verify-checksums does.', 'seoprostack');
            case 'local':
                return __('Compares the code of other plugins (premium and GitHub-hosted) with the record kept when each was installed, updated or first seen. Updating through WordPress makes a new record, so it cannot tell a harmful update from a good one.', 'seoprostack');
            default:
                /* translators: %d: number of days */
                return sprintf(__('Looks for PHP files in the uploads folder, which should hold only media, and for administrators added in the last %d days.', 'seoprostack'), SEOProStack_Integrity_Checks::NEW_ADMIN_DAYS);
        }
    }

    /**
     * A finding as text.
     *
     * @param mixed $item Path, or administrator.
     * @return string
     */
    public static function item_text($item) {
        if (!is_array($item) || !isset($item['user'])) {
            return (string) $item;
        }
        return empty($item['since'])
            /* translators: %s: user name */
            ? sprintf(__('Administrator %s', 'seoprostack'), $item['user'])
            /* translators: 1: user name, 2: date */
            : sprintf(__('Administrator %1$s, first seen %2$s', 'seoprostack'), $item['user'], self::when((int) $item['since']));
    }

    /**
     * A time in the site's date and time formats.
     *
     * @param int $time Timestamp.
     * @return string
     */
    private static function when($time) {
        return (string) wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $time);
    }

    /**
     * Add the tests: they read the last results only.
     *
     * @param array<string,mixed> $tests Tests.
     * @return array<string,mixed>
     */
    public static function tests($tests) {
        foreach (self::checks() as $check => $label) {
            $tests['direct']['seoprostack_integrity_' . $check] = array(
                /* translators: %s: check name */
                'label' => sprintf(__('Integrity: %s', 'seoprostack'), $label),
                'test'  => array(__CLASS__, 'test_' . $check),
            );
        }
        return $tests;
    }

    /** @return array<string,mixed> */
    public static function test_core() {
        return self::result('core');
    }

    /** @return array<string,mixed> */
    public static function test_wporg() {
        return self::result('wporg');
    }

    /** @return array<string,mixed> */
    public static function test_local() {
        return self::result('local');
    }

    /** @return array<string,mixed> */
    public static function test_tamper() {
        return self::result('tamper');
    }

    /**
     * A check's Site Health result.
     *
     * @param string $check Check.
     * @return array<string,mixed>
     */
    private static function result($check) {
        $labels  = self::checks();
        $results = get_option(SEOProStack_Integrity_Checks::RESULTS);
        $about   = '<p>' . esc_html(self::about($check)) . '</p>';
        $answer  = array(
            'test'    => 'seoprostack_integrity_' . $check,
            'badge'   => array('label' => __('Security', 'seoprostack'), 'color' => 'blue'),
            'actions' => '',
        );
        if (!is_array($results) || empty($results['time'])) {
            return $answer + array(
                'status'      => 'recommended',
                /* translators: %s: check name */
                'label'       => sprintf(__('%s: the first integrity check has not finished yet', 'seoprostack'), $labels[$check]),
                'description' => $about . '<p>' . esc_html__('The first check starts a minute after the feature is switched on and runs in parts of up to 20 seconds; then it runs once a day. It needs WP-Cron.', 'seoprostack') . '</p>',
            );
        }
        return $answer + self::outcome($check, $labels[$check], $results, $about);
    }

    /**
     * Status, label and description from a completed check.
     *
     * @param string              $check   Check.
     * @param string              $label   Its name.
     * @param array<string,mixed> $results The last results.
     * @param string              $about   What it compares, as HTML.
     * @return array<string,string>
     */
    private static function outcome($check, $label, array $results, $about) {
        $when      = self::when((int) $results['time']);
        $found     = isset($results['found'][$check]) ? (array) $results['found'][$check] : array();
        $unchecked = isset($results['unchecked'][$check]) ? (array) $results['unchecked'][$check] : array();
        if ($found) {
            return array(
                'status'      => 'critical',
                /* translators: 1: check name, 2: number of findings */
                'label'       => sprintf(_n('%1$s: %2$d finding at the last integrity check', '%1$s: %2$d findings at the last integrity check', count($found), 'seoprostack'), $label, count($found)),
                'description' => $about . self::list_html($found)
                    /* translators: %s: date and time */
                    . '<p>' . esc_html(sprintf(__('Checked %s. SEO Pro Stack changed nothing. Compare each file with a fresh copy of WordPress or the plugin, and remove what you do not recognise; an unexpected file or administrator can mean someone has broken into the site. A file you expected (a plugin’s own cache, a deliberate edit) can be ignored with the seoprostack_integrity_ignore filter.', 'seoprostack'), $when)) . '</p>',
            );
        }
        if ($unchecked) {
            return array(
                'status'      => 'recommended',
                /* translators: %s: check name */
                'label'       => sprintf(__('%s: some of the integrity check could not run', 'seoprostack'), $label),
                /* translators: %s: date and time */
                'description' => $about . self::list_html($unchecked) . '<p>' . esc_html(sprintf(__('Checked %s. Nothing else was found.', 'seoprostack'), $when)) . '</p>',
            );
        }
        if ((int) $results['time'] < time() - 3 * DAY_IN_SECONDS) {
            return array(
                'status'      => 'recommended',
                /* translators: 1: check name, 2: date and time */
                'label'       => sprintf(__('%1$s: the last integrity check was on %2$s', 'seoprostack'), $label, $when),
                'description' => $about . '<p>' . esc_html__('The check runs once a day from WP-Cron. If it has stopped, check that WP-Cron runs (a real cron job calling wp-cron.php, or visitors when DISABLE_WP_CRON is not set).', 'seoprostack') . '</p>',
            );
        }
        return array(
            'status'      => 'good',
            /* translators: 1: check name, 2: date and time */
            'label'       => sprintf(__('%1$s: no findings at the last integrity check (%2$s)', 'seoprostack'), $label, $when),
            'description' => $about,
        );
    }

    /**
     * Findings as a list, at most 50.
     *
     * @param array<int,mixed> $items Findings.
     * @return string
     */
    private static function list_html(array $items) {
        $html = '<ul class="ul-disc">';
        foreach (array_slice($items, 0, 50) as $item) {
            $html .= '<li><code>' . esc_html(self::item_text($item)) . '</code></li>';
        }
        if (count($items) > 50) {
            /* translators: %d: number of findings */
            $html .= '<li>' . esc_html(sprintf(__('…and %d more', 'seoprostack'), count($items) - 50)) . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * The last check, under the setting.
     *
     * @param string              $key   Setting key.
     * @param array<string,mixed> $field Setting.
     */
    public static function panel($key, $field = array()) {
        unset($field);
        if (SEOProStack_Integrity_Checks::KEY !== $key || !SEOProStack_Integrity_Checks::switched_on() || !current_user_can('view_site_health_checks')) {
            return;
        }
        echo '<div class="sps-panel-note"><p>';
        if (!is_main_site()) {
            echo esc_html__('On a network, the main site runs the checks for all sites; its Site Health shows the findings.', 'seoprostack');
        } else {
            echo esc_html(self::panel_text()) . ' <a href="' . esc_url(admin_url('site-health.php')) . '">' . esc_html__('Site Health', 'seoprostack') . '</a>';
        }
        echo '</p></div>';
    }

    /**
     * The last check's time and number of findings, as text.
     *
     * @return string
     */
    private static function panel_text() {
        $results = get_option(SEOProStack_Integrity_Checks::RESULTS);
        if (!is_array($results) || empty($results['time'])) {
            return __('The first check has not finished yet.', 'seoprostack');
        }
        $count = 0;
        foreach ((array) $results['found'] as $items) {
            $count += count((array) $items);
        }
        /* translators: 1: date and time, 2: number of findings */
        return sprintf(_n('Last check: %1$s, %2$d finding.', 'Last check: %1$s, %2$d findings.', $count, 'seoprostack'), self::when((int) $results['time']), $count);
    }
}
