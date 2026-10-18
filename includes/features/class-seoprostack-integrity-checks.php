<?php
/**
 * File and account integrity checks.
 *
 * Once a day, the site (the main site, on a network) checks itself, read-only
 * and in parts of at most 20 seconds:
 *
 * 1. WordPress's own files against the checksums WordPress.org publishes
 *    for this version (get_core_checksums()), and extra PHP files in
 *    wp-admin/ and wp-includes/.
 * 2. Plugins from WordPress.org against the checksums WP-CLI's
 *    `wp plugin verify-checksums` reads (downloads.wordpress.org/plugin-checksums/).
 * 3. Other plugins (premium, GitHub-hosted) against the hashes kept when
 *    each was installed, updated or first seen.
 * 4. PHP files in the uploads folder, and administrators added recently.
 *
 * Findings show in Tools → Site Health and `wp seoprostack health` (which
 * reads the last results and never starts a check). New findings are emailed
 * once to the administration address, by path or user name only. Nothing is
 * changed, quarantined or deleted.
 *
 * This class holds the setting, the schedule and running a check; the
 * includes/class-seoprostack-integrity-*.php classes do the work.
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

require_once dirname(__DIR__) . '/class-seoprostack-integrity-files.php';
require_once dirname(__DIR__) . '/class-seoprostack-integrity-folders.php';
require_once dirname(__DIR__) . '/class-seoprostack-integrity-wporg.php';
require_once dirname(__DIR__) . '/class-seoprostack-integrity-records.php';
require_once dirname(__DIR__) . '/class-seoprostack-integrity-plugins.php';
require_once dirname(__DIR__) . '/class-seoprostack-integrity-scan.php';
require_once dirname(__DIR__) . '/class-seoprostack-integrity-email.php';
require_once dirname(__DIR__) . '/class-seoprostack-integrity-report.php';

class SEOProStack_Integrity_Checks extends SEOProStack_Feature {

    const KEY = 'integrity_checks';

    /** Email new findings. */
    const EMAIL_KEY = 'integrity_email';

    /** Daily event. */
    const HOOK = 'seoprostack_integrity_checks';

    /** The rest of a check that reached its time limit. */
    const MORE = 'seoprostack_integrity_more';

    /** The last complete check (not autoloaded). */
    const RESULTS = 'seoprostack_integrity_results';

    /** The check in progress: where it got to (not autoloaded). */
    const STATE = 'seoprostack_integrity_state';

    /** Administrators, with when each was first seen (0: before the first check). */
    const ADMINS = 'seoprostack_integrity_admins';

    /** Taken while a part runs, so two cannot overlap. */
    const LOCK = 'seoprostack_integrity_lock';

    /** Seconds per part. */
    const BUDGET = 20;

    /** Days an added administrator is reported. */
    const NEW_ADMIN_DAYS = 7;

    /**
     * Settings.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function settings() {
        return array(
            self::KEY       => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'security',
                'label'       => __('File and account integrity checks', 'seoprostack'),
                'description' => __('Once a day, compare WordPress’s files and the plugins’ code with WordPress.org’s checksums, or with a record kept when each other plugin was installed or updated, and look for PHP files in the uploads folder and new administrators. Findings show in Tools → Site Health. Read-only: nothing is changed or deleted. On a network, the main site checks for all sites.', 'seoprostack'),
            ),
            self::EMAIL_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Email new findings', 'seoprostack'),
                'description' => __('Email the site’s administration address when a check finds something new, naming files and user names only.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        $on = self::enabled() && is_main_site();
        if ($on) {
            add_filter('site_status_tests', array('SEOProStack_Integrity_Report', 'tests'));
        }
        // The health audit reads the last results only.
        if (self::auditing()) {
            return;
        }
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 1);
        if (is_admin()) {
            // Also when switched off, to take the schedule away.
            add_action('admin_init', array(__CLASS__, 'sync_schedule'));
            add_action('seoprostack_setting_panel', array('SEOProStack_Integrity_Report', 'panel'), 10, 2);
        }
        if (!$on) {
            return;
        }
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action(self::MORE, array(__CLASS__, 'run'));
        add_action('upgrader_process_complete', array('SEOProStack_Integrity_Records', 'upgraded'), 10, 2);
    }

    /**
     * Whether this is `wp seoprostack health`, which reads results only.
     *
     * @return bool
     */
    private static function auditing() {
        return defined('WP_CLI') && WP_CLI && class_exists('SEOProStack_Health_CLI') && SEOProStack_Health_CLI::auditing();
    }

    /* ------------------------------------------------------------------
     * Schedule
     * ------------------------------------------------------------------ */

    /**
     * Keep the daily event in step with the switch. The first check starts
     * a minute after switching on.
     */
    public static function sync_schedule() {
        if (self::enabled() && is_main_site()) {
            self::schedule();
            return;
        }
        if (wp_next_scheduled(self::HOOK) || wp_next_scheduled(self::MORE)) {
            wp_clear_scheduled_hook(self::HOOK);
            wp_clear_scheduled_hook(self::MORE);
        }
        if (false !== get_option(self::STATE, false)) {
            delete_option(self::STATE);
        }
    }

    /**
     * Schedule the daily event, and the first check when there has been none.
     */
    private static function schedule() {
        if (wp_next_scheduled(self::HOOK)) {
            return;
        }
        wp_schedule_event(self::quiet_time(), 'daily', self::HOOK);
        if (!get_option(self::RESULTS) && !wp_next_scheduled(self::MORE)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::MORE);
        }
    }

    /**
     * A setting changed: update the schedule.
     *
     * @param string $key Setting key.
     */
    public static function setting_saved($key) {
        if (0 === strpos((string) $key, 'integrity_')) {
            self::sync_schedule();
        }
    }

    /**
     * 4am tomorrow, site time: few visitors, after Clean the database's 3am.
     *
     * @return int
     */
    private static function quiet_time() {
        try {
            $time = new DateTimeImmutable('tomorrow 04:00', wp_timezone());
            return $time->getTimestamp();
        } catch (Exception $e) {
            return time() + DAY_IN_SECONDS;
        }
    }

    /* ------------------------------------------------------------------
     * Running a check
     * ------------------------------------------------------------------ */

    /**
     * Run the next part of the check: a new one, or the rest of one that
     * reached its time limit. The results are saved when a check completes,
     * so readers never see half of one.
     */
    public static function run() {
        if (!self::lock()) {
            return;
        }
        $start = microtime(true);
        $state = get_option(self::STATE);
        if (!is_array($state) || empty($state['started'])) {
            $state = self::new_state();
        }
        if (SEOProStack_Integrity_Scan::advance($state, $start)) {
            self::finish($state);
            delete_option(self::STATE);
            wp_clear_scheduled_hook(self::MORE);
        } else {
            update_option(self::STATE, $state, false);
            if (!wp_next_scheduled(self::MORE)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::MORE);
            }
        }
        delete_option(self::LOCK);
    }

    /**
     * A check that has not started.
     *
     * @return array<string,mixed>
     */
    private static function new_state() {
        return array(
            'started'   => time(),
            'phase'     => 0,
            'pos'       => null,
            'found'     => array(),
            'unchecked' => array(),
            'records'   => array(),
            'seen'      => 0,
        );
    }

    /**
     * Take the lock; one left by a part that stopped expires in 10 minutes.
     *
     * @return bool
     */
    private static function lock() {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }
        if ((int) get_option(self::LOCK) > time() - 10 * MINUTE_IN_SECONDS) {
            return false;
        }
        update_option(self::LOCK, time(), false);
        return true;
    }

    /**
     * Save a complete check, tidy records of removed plugins and email new
     * findings.
     *
     * @param array<string,mixed> $state Completed check.
     */
    private static function finish(array $state) {
        $before  = get_option(self::RESULTS);
        $before  = is_array($before) ? $before : array();
        $records = array_values(array_unique((array) $state['records']));
        self::tidy_records($before, $records);
        $found = self::found_by_check($state);
        update_option(self::RESULTS, array(
            'time'      => time(),
            'started'   => (int) $state['started'],
            'found'     => $found,
            'unchecked' => (array) $state['unchecked'],
            'notified'  => SEOProStack_Integrity_Email::notify($before, $found),
            'records'   => $records,
        ), false);
    }

    /**
     * Delete the records of plugins no longer installed.
     *
     * @param array<string,mixed> $before  The previous results.
     * @param array<int,string>   $records Records this check used.
     */
    private static function tidy_records(array $before, array $records) {
        foreach (isset($before['records']) ? (array) $before['records'] : array() as $name) {
            if (!in_array($name, $records, true)) {
                delete_option((string) $name);
            }
        }
    }

    /**
     * The findings of each check, in the checks' order.
     *
     * @param array<string,mixed> $state Completed check.
     * @return array<string,array<int,mixed>>
     */
    private static function found_by_check(array $state) {
        $found = array();
        foreach (array_keys(SEOProStack_Integrity_Report::checks()) as $check) {
            $found[$check] = isset($state['found'][$check]) ? array_values((array) $state['found'][$check]) : array();
        }
        return $found;
    }
}
