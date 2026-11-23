<?php
/**
 * Memory peaks of WP-CLI commands and cron runs, for Memory ceilings:
 * the TOP highest of 30 days per command, recorded by Hosting needs when a
 * process ends, apart from its page-request option.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.230
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Memory_Peaks {

    /**
     * Network option (an ordinary one on single sites), not autoloaded:
     * peaks (command => up to TOP of array(peak bytes, limit bytes, time)) and
     * php (WP-CLI's own memory_limit in bytes before any ceiling, time).
     */
    const OPTION = 'seoprostack_cli_memory';

    /** Days peaks are kept. */
    const DAYS = 30;

    /** Highest peaks kept per command. */
    const TOP = 3;

    /** Commands kept, the most recently run first. */
    const COMMANDS = 50;

    /**
     * WP-CLI's own memory_limit, in bytes, before any ceiling; null on the web.
     *
     * @var int|null
     */
    private static $cli_php = null;

    /**
     * Note WP-CLI's own memory limit, once WordPress has loaded.
     *
     * @param int $bytes Bytes, -1 for none.
     */
    public static function note_cli($bytes) {
        self::$cli_php = (int) $bytes;
    }

    /**
     * WP-CLI's own memory_limit: this command's, else as last recorded.
     *
     * @return int|null Bytes, -1 for none, null when no command has run yet.
     */
    public static function cli_php() {
        if (null !== self::$cli_php) {
            return self::$cli_php;
        }
        $stored = self::stored();
        return isset($stored['php'][0]) ? (int) $stored['php'][0] : null;
    }

    /**
     * This WP-CLI command, as WP-CLI resolves it, such as
     * "seoprostack media-storage migrate": command names only, never the
     * arguments ("wp user get alice" is "user get"). '' while the command
     * is not registered yet (one a plugin adds, before it loads).
     *
     * @return string
     */
    public static function command() {
        if (!(defined('WP_CLI') && WP_CLI) || !class_exists('WP_CLI', false)) {
            return '';
        }
        $runner = WP_CLI::get_runner();
        // A private property read through __get(), which isset() does not see.
        $found = $runner->find_command_to_run((array) $runner->arguments);
        return is_array($found) && isset($found[2]) ? implode(' ', (array) $found[2]) : '';
    }

    /**
     * Name a run is recorded under: cron for WP-Cron (also `wp cron …`),
     * else the command.
     *
     * @return string
     */
    public static function record_key() {
        return wp_doing_cron() ? 'cron' : self::key_for(self::command());
    }

    /**
     * Name a command's runs are recorded under: cron for `wp cron …`, wp
     * for none.
     *
     * @param string $command From command().
     * @return string
     */
    public static function key_for($command) {
        if ('cron' === $command || 0 === strpos($command, 'cron ')) {
            return 'cron';
        }
        return '' !== $command ? $command : 'wp';
    }

    /**
     * Record this process's memory peak under a key when it is among the
     * TOP highest of 30 days for that key. Most runs write nothing.
     *
     * @param string $key Command or cron.
     */
    public static function record($key) {
        $time    = time();
        $stored  = self::stored();
        $changed = self::prune($stored, $time - self::DAYS * DAY_IN_SECONDS);
        if (null !== self::$cli_php && (!isset($stored['php'][0]) || (int) $stored['php'][0] !== self::$cli_php)) {
            $stored['php'] = array(self::$cli_php, $time);
            $changed       = true;
        }
        $changed = self::add_peak($stored, (string) $key, $time) || $changed;
        if (!$changed) {
            return;
        }
        // The most recently run first, so commands no longer used go.
        uasort($stored['peaks'], function ($a, $b) {
            return self::column($b, 2) <=> self::column($a, 2);
        });
        $stored['peaks'] = array_slice($stored['peaks'], 0, self::COMMANDS, true);
        update_site_option(self::OPTION, $stored);
    }

    /**
     * Add this process's peak to a key's list when it is among the TOP.
     *
     * @param array  $stored From stored(), changed in place.
     * @param string $key    Command or cron.
     * @param int    $time   Now.
     * @return bool Whether it was added.
     */
    private static function add_peak(array &$stored, $key, $time) {
        $peak = (int) memory_get_peak_usage(true);
        $list = isset($stored['peaks'][$key]) ? $stored['peaks'][$key] : array();
        if (count($list) >= self::TOP && $peak <= self::column($list, 0, true)) {
            return false;
        }
        $list[] = array($peak, SEOProStack_Memory_Sizes::bytes(ini_get('memory_limit')), $time);
        usort($list, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        $stored['peaks'][$key] = array_slice($list, 0, self::TOP);
        return true;
    }

    /**
     * Drop peaks older than a time, and commands left with none.
     *
     * @param array $stored From stored(), changed in place.
     * @param int   $since  Oldest time kept.
     * @return bool Whether anything was dropped.
     */
    private static function prune(array &$stored, $since) {
        $changed = false;
        foreach ($stored['peaks'] as $key => $list) {
            $kept = array();
            foreach (is_array($list) ? $list : array() as $entry) {
                if (is_array($entry) && isset($entry[0], $entry[1], $entry[2]) && (int) $entry[2] >= $since) {
                    $kept[] = array((int) $entry[0], (int) $entry[1], (int) $entry[2]);
                }
            }
            $changed = $changed || count($kept) !== count((array) $list);
            if ($kept) {
                $stored['peaks'][$key] = $kept;
            } else {
                unset($stored['peaks'][$key]);
            }
        }
        return $changed;
    }

    /**
     * The stored record.
     *
     * @return array{peaks: array<string,array>, php: array}
     */
    private static function stored() {
        $stored = get_site_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        return array(
            'peaks' => isset($stored['peaks']) && is_array($stored['peaks']) ? $stored['peaks'] : array(),
            'php'   => isset($stored['php']) && is_array($stored['php']) ? $stored['php'] : array(),
        );
    }

    /**
     * Highest peak of the last 30 days per command, highest first.
     *
     * @return array<string,int> Command => bytes.
     */
    public static function highest() {
        $stored = self::stored();
        self::prune($stored, time() - self::DAYS * DAY_IN_SECONDS);
        $highest = array();
        foreach ($stored['peaks'] as $key => $list) {
            $highest[(string) $key] = self::column($list, 0);
        }
        arsort($highest);
        return $highest;
    }

    /**
     * Highest (or lowest) value of one field of the entries.
     *
     * @param array $list   Entries: array(peak, limit, time), as prune() keeps them.
     * @param int   $field  0 peak, 1 limit, 2 time.
     * @param bool  $lowest Lowest instead.
     * @return int 0 when there are none.
     */
    private static function column(array $list, $field, $lowest = false) {
        $values = array_map('intval', array_column($list, $field));
        if (!$values) {
            return 0;
        }
        return (int) ($lowest ? min($values) : max($values));
    }
}
