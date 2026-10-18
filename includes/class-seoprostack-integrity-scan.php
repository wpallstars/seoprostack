<?php
/**
 * File and account integrity checks: the steps of a check, WordPress's own
 * files, recently added administrators, and noting what is found.
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

class SEOProStack_Integrity_Scan {

    /** Patterns from seoprostack_integrity_ignore. @var array<int,string>|null */
    private static $ignore = null;

    /**
     * Work through the steps until done or out of time.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether the check is complete.
     */
    public static function advance(array &$state, $start) {
        $steps = array('core', 'core_extra', 'plugins', 'uploads', 'admins');
        $count = count($steps);
        while ($state['phase'] < $count) {
            if (!self::step($steps[$state['phase']], $state, $start)) {
                return false;
            }
            $state['phase']++;
            $state['pos'] = null;
        }
        return true;
    }

    /**
     * Run a step, or the rest of one.
     *
     * @param string              $step  Step.
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether the step is done.
     */
    private static function step($step, array &$state, $start) {
        switch ($step) {
            case 'core':
                return self::step_core($state, $start);
            case 'core_extra':
                return SEOProStack_Integrity_Folders::step_core_extra($state, $start);
            case 'plugins':
                return SEOProStack_Integrity_Plugins::step($state, $start);
            case 'uploads':
                return SEOProStack_Integrity_Folders::step_uploads($state, $start);
            default:
                return self::step_admins($state);
        }
    }

    /**
     * Changed core code files.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    private static function step_core(array &$state, $start) {
        $sums = SEOProStack_Integrity_Wporg::core_sums();
        if (null === $sums) {
            self::unchecked($state, 'core', __('WordPress.org’s checksums for this version of WordPress could not be fetched.', 'seoprostack'));
            return true;
        }
        $paths = array_keys($sums);
        $from  = (int) $state['pos'];
        $count = count($paths);
        for ($i = $from; $i < $count; $i++) {
            if ($i > $from && 0 === $i % 50 && !SEOProStack_Feature::more_time($start, SEOProStack_Integrity_Checks::BUDGET)) {
                $state['pos'] = $i;
                return false;
            }
            if (self::core_changed($paths[$i], $sums[$paths[$i]])) {
                self::found_path($state, 'core', $paths[$i]);
            }
        }
        return true;
    }

    /**
     * Whether a core file differs from WordPress.org's checksums. Removed
     * files are not tampering; unreadable ones cannot be compared.
     *
     * @param string            $path Path in the checksums.
     * @param array<int,string> $md5s Checksums.
     * @return bool
     */
    private static function core_changed($path, array $md5s) {
        $file = SEOProStack_Integrity_Wporg::HELLO === $path ? untrailingslashit(WP_PLUGIN_DIR) . '/hello.php' : ABSPATH . $path;
        return is_file($file) && is_readable($file) && '' === SEOProStack_Integrity_Wporg::matches($file, $md5s);
    }

    /**
     * Administrators first seen in the last 7 days: added, or given the role.
     * The first check records those there already.
     *
     * @param array<string,mixed> $state Check in progress.
     * @return bool
     */
    private static function step_admins(array &$state) {
        $known  = get_option(SEOProStack_Integrity_Checks::ADMINS);
        $now    = time();
        $record = array();
        foreach (self::administrators() as $id => $login) {
            if (is_array($known) && isset($known[$id])) {
                $record[$id] = (int) $known[$id];
            } else {
                $record[$id] = is_array($known) ? $now : 0;
            }
            if ($record[$id] > $now - SEOProStack_Integrity_Checks::NEW_ADMIN_DAYS * DAY_IN_SECONDS) {
                $state['found']['tamper'][] = array('user' => $login, 'since' => $record[$id]);
            }
        }
        update_option(SEOProStack_Integrity_Checks::ADMINS, $record, false);
        return true;
    }

    /**
     * Administrators of this site, and on a network its super admins.
     *
     * @return array<int,string> ID => user name
     */
    private static function administrators() {
        $admins = array();
        foreach (get_users(array('role' => 'administrator', 'fields' => array('ID', 'user_login'), 'number' => 1000)) as $user) {
            $admins[(int) $user->ID] = (string) $user->user_login;
        }
        if (is_multisite()) {
            foreach (get_super_admins() as $login) {
                $user = get_user_by('login', $login);
                if ($user) {
                    $admins[(int) $user->ID] = (string) $login;
                }
            }
        }
        ksort($admins);
        return $admins;
    }

    /**
     * Note a finding, unless an ignore pattern matches its path.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param string              $check Check.
     * @param string              $path  Path as shown.
     */
    public static function found_path(array &$state, $check, $path) {
        foreach (self::ignore_patterns() as $pattern) {
            if (fnmatch($pattern, $path)) {
                return;
            }
        }
        $state['found'][$check][] = $path;
    }

    /**
     * Paths the checks ignore.
     *
     * @return array<int,string>
     */
    private static function ignore_patterns() {
        if (null === self::$ignore) {
            /**
             * Paths the integrity checks ignore, as fnmatch() patterns
             * matched against the path as shown in the finding:
             * `wp-includes/…`, `plugins/{folder}/…`, `uploads/…`.
             *
             * @param array<int,string> $patterns Patterns.
             */
            $patterns     = apply_filters('seoprostack_integrity_ignore', array());
            self::$ignore = is_array($patterns) ? array_values(array_filter(array_map('strval', $patterns))) : array();
        }
        return self::$ignore;
    }

    /**
     * Note what could not be checked.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param string              $check Check.
     * @param string              $text  Reason.
     */
    public static function unchecked(array &$state, $check, $text) {
        $state['unchecked'][$check][] = $text;
    }
}
