<?php
/**
 * Memory ceilings' sizing rules: the ceiling of a WP-CLI command, of one of
 * SEO Pro Stack's own jobs, and of web requests, from the memory they used.
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

class SEOProStack_Memory_Needs {

    /** Smallest WP-CLI ceiling, MB: WordPress with many plugins takes about 400 MB on the command line. */
    const CLI_MB = 2048;

    /** Smallest ceiling of commands that often need more (HEAVY), MB. */
    const HEAVY_MB = 4096;

    /** Commands that often need more memory, matched at the start of the command. */
    const HEAVY = array(
        'search-replace',
        'db export',
        'db import',
        'import',
        'media import',
        'media regenerate',
        'seoprostack media-storage migrate',
    );

    /** WP-CLI and job ceilings, MB. */
    const STEPS = array(2048, 3072, 4096, 6144, 8192, 12288, 16384, 24576, 32768);

    /** Ceiling SEO Pro Stack's own background jobs start with, MB. */
    const JOB_MB = 2048;

    /** Ceiling of web requests while PHP has no limit and nothing is measured yet, MB. */
    const WEB_MB = 2048;

    /**
     * Ceiling of a WP-CLI command: at least CLI_MB (HEAVY_MB for HEAVY
     * commands), or twice the most that command used in 30 days, rounded up
     * to STEPS; never more than half the account's memory when known. Then
     * the seoprostack_cli_memory_limit filter.
     *
     * @param string $command Command, such as "search-replace" (SEOProStack_Memory_Peaks::command()).
     * @return int Bytes.
     */
    public static function cli($command) {
        $peaks = SEOProStack_Memory_Peaks::highest();
        $key   = SEOProStack_Memory_Peaks::key_for((string) $command);
        $mb    = max(self::CLI_MB, self::step(2 * (isset($peaks[$key]) ? $peaks[$key] : 0) / MB_IN_BYTES));
        if (self::heavy($command)) {
            $mb = max($mb, self::HEAVY_MB);
        }
        return self::filtered(SEOProStack_Memory_Sizes::within_account($mb), $command);
    }

    /**
     * Ceiling of one of SEO Pro Stack's own long jobs: JOB_MB, or twice the
     * most it used in 30 days (cron's runs are recorded together), never
     * more than half the account's memory when known. Then the filter.
     *
     * @param string $name Job: a WP-CLI command, such as "seoprostack health", or a cron hook.
     * @return int Bytes.
     */
    public static function job($name) {
        $highest = SEOProStack_Memory_Peaks::highest();
        $key     = wp_doing_cron() ? 'cron' : (string) $name;
        $mb      = max(self::JOB_MB, self::step(2 * (isset($highest[$key]) ? $highest[$key] : 0) / MB_IN_BYTES));
        return self::filtered(SEOProStack_Memory_Sizes::within_account($mb), (string) $name);
    }

    /**
     * Ceiling of one kind of web request: what the site needs (Hosting
     * needs, from 7 days of peaks of that kind) when the server's limit is
     * far above it or at least half the account's memory; with no limit,
     * what it needs or WEB_MB. Never above the server's limit; 0 when the
     * host's limit stays.
     *
     * @param int         $server The server's memory_limit, bytes (-1: none).
     * @param string|null $kind   Hosting needs' kind of request (site, admin,
     *                            other); null for this request's.
     * @return int Bytes, or 0.
     */
    public static function web($server, $kind = null) {
        $need = self::web_need(null === $kind ? self::kind() : (string) $kind) * MB_IN_BYTES;
        if (-1 === $server) {
            return $need > 0 ? $need : SEOProStack_Memory_Sizes::within_account(self::WEB_MB) * MB_IN_BYTES;
        }
        return $need > 0 && $server > $need && self::too_high($server, $need) ? $need : 0;
    }

    /**
     * Whether the server's limit is far above what requests need, or at
     * least half the account's memory.
     *
     * @param int $server The server's memory_limit, bytes.
     * @param int $need   What requests need, bytes.
     * @return bool
     */
    private static function too_high($server, $need) {
        if ($server > SEOProStack_Hosting_Needs::HIGH_LIMIT * $need && $server > SEOProStack_Hosting_Needs::HIGH_LIMIT_MB * MB_IN_BYTES) {
            return true;
        }
        $account = SEOProStack_Memory_Sizes::account();
        return $account > 0 && 2 * $server >= $account;
    }

    /**
     * Kind of this request, as Hosting needs records it once the request
     * has ended: other for cron, admin for screens and AJAX, else site. The
     * REST API (other) is not known this early: it counts as site, and its
     * ceiling is still the highest of every kind.
     *
     * @return string
     */
    private static function kind() {
        if (wp_doing_cron()) {
            return 'other';
        }
        return is_admin() ? 'admin' : 'site';
    }

    /**
     * Memory one kind of web request needs, from Hosting needs' peaks: half
     * as much again as the highest of every kind, as Hosting needs suggests;
     * 0 until a peak of that kind is recorded on each of the last 7 days.
     *
     * @param string $kind Kind of request: site, admin or other.
     * @return int MB.
     */
    private static function web_need($kind) {
        $stored = get_option(SEOProStack_Hosting_Needs::MEMORY, array());
        $since  = gmdate('Y-m-d', time() - (SEOProStack_Hosting_Needs::DAYS - 1) * DAY_IN_SECONDS);
        $days   = 0;
        $peak   = 0;
        foreach (is_array($stored) ? $stored : array() as $day => $kinds) {
            if ((string) $day < $since || !is_array($kinds)) {
                continue;
            }
            $days += self::day_peak(array_intersect_key($kinds, array($kind => true))) > 0 ? 1 : 0;
            $peak  = max($peak, self::day_peak($kinds));
        }
        if ($days < SEOProStack_Hosting_Needs::DAYS) {
            return 0;
        }
        // Hosting needs' steps end at 2048 MB: never below half as much again as the peak.
        return max(SEOProStack_Hosting_Needs::need_for_peak($peak), (int) ceil($peak * 1.5 / MB_IN_BYTES));
    }

    /**
     * Highest peak of one day's kinds of request.
     *
     * @param mixed $kinds Kind => array(peak, limit, time).
     * @return int Bytes, or 0.
     */
    private static function day_peak($kinds) {
        $peak = 0;
        foreach (is_array($kinds) ? $kinds : array() as $pair) {
            $peak = max($peak, is_array($pair) && isset($pair[0]) ? (int) $pair[0] : 0);
        }
        return $peak;
    }

    /**
     * A ceiling through the seoprostack_cli_memory_limit filter; none (-1) or
     * nonsense from it keeps the default.
     *
     * @param int    $mb      Default, MB.
     * @param string $command Command or job.
     * @return int Bytes.
     */
    private static function filtered($mb, $command) {
        /**
         * Filter the memory limit Memory ceilings gives a WP-CLI command or
         * one of SEO Pro Stack's background jobs. Return a higher one for a
         * command known to need more. Add it in a must-use plugin: WP-CLI's
         * ceiling is set as SEO Pro Stack loads, before plugins that load
         * after it. -1 (no limit) is not accepted.
         *
         * @param string $limit   Limit, such as "2048M".
         * @param string $command Command words, such as "search-replace" or
         *                        "seoprostack media-storage migrate"; for a
         *                        job, its WP-CLI command or cron hook, such as
         *                        "seoprostack health" or "seoprostack_media_storage_batch".
         */
        $limit = SEOProStack_Memory_Sizes::bytes((string) apply_filters('seoprostack_cli_memory_limit', $mb . 'M', (string) $command));
        return $limit > 0 ? $limit : $mb * MB_IN_BYTES;
    }

    /**
     * Whether a command is one that often needs more memory.
     *
     * @param string $command Command words.
     * @return bool
     */
    private static function heavy($command) {
        foreach (self::HEAVY as $heavy) {
            if ($command === $heavy || 0 === strpos($command, $heavy . ' ')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Smallest STEPS value at or above a size.
     *
     * @param float $mb Size, MB.
     * @return int MB.
     */
    private static function step($mb) {
        $steps = self::STEPS;
        foreach ($steps as $step) {
            if ($step >= $mb) {
                return $step;
            }
        }
        return (int) end($steps);
    }
}
