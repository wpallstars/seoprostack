<?php
/**
 * Memory ceilings in Hosting needs: each kind of process's memory limit,
 * WP-CLI's and cron's peaks, the hosting account's memory, and a warning
 * when one process has no limit or more than half the account's memory.
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

class SEOProStack_Memory_Report {

    /**
     * Each kind of process's limit, the highest peaks, the account's memory,
     * and a warning when one process could use more than half of the
     * account's memory, or has no limit.
     *
     * @param int  $server The server's memory_limit for web requests, bytes (-1: none; 0: not known).
     * @param bool $web    Whether this is a web request (WP_MAX_MEMORY_LIMIT is the web's).
     * @return array{fields: array, warning: string}
     */
    public static function report($server, $web) {
        // Switched on and applicable: with a host that forbids changing memory_limit, no ceiling is set.
        $on     = SEOProStack_Memory_Ceilings::enabled() && SEOProStack_Memory_Ceilings::changeable();
        $limits = array();
        $fields = 0 !== $server ? self::web_fields((int) $server, $web, $on, $limits) : array();
        $fields['ceiling_cli'] = self::cli_field($on, $limits);
        $fields['peaks_cli']   = self::peaks_field();
        $fields['account_memory'] = self::account_field();
        return array('fields' => $fields, 'warning' => self::warning($limits, SEOProStack_Memory_Sizes::account(), $on));
    }

    /**
     * Rows for pages, admin screens, cron and image editing.
     *
     * @param int   $server Server's limit, bytes.
     * @param bool  $web    Whether this is a web request.
     * @param bool  $on     Whether Memory ceilings is on.
     * @param array $limits Kind => limit, bytes; added to.
     * @return array
     */
    private static function web_fields($server, $web, $on, array &$limits) {
        // WordPress raises memory_limit to WP_MEMORY_LIMIT first; ceilings are worked out from that.
        $base = $server;
        if (defined('WP_MEMORY_LIMIT')) {
            $wp   = SEOProStack_Memory_Sizes::bytes(WP_MEMORY_LIMIT);
            $base = -1 === $wp || -1 === $server ? -1 : max($server, $wp);
        }
        $cap            = $on ? SEOProStack_Memory_Needs::web($base, 'site') : 0;
        $limits['page'] = $cap > 0 ? $cap : $base;
        $fields         = array(
            'ceiling_page' => array(
                'label' => __('Memory limit: pages', 'seoprostack'),
                'value' => $cap > 0
                    /* translators: 1: memory limit, 2: the server's memory limit. */
                    ? sprintf(__('%1$s, set by Memory ceilings (the server’s: %2$s)', 'seoprostack'), self::size($cap), self::size($server))
                    : self::size($base),
            ),
        );
        if ($web && defined('WP_MAX_MEMORY_LIMIT')) {
            $fields += self::raised_fields(SEOProStack_Memory_Sizes::bytes(WP_MAX_MEMORY_LIMIT), $base, $on, $limits);
        }
        return $fields;
    }

    /**
     * Rows for admin screens, cron and image editing, where WordPress raises
     * the limit to WP_MAX_MEMORY_LIMIT, never lowering it. Admin screens and
     * cron each keep their own ceiling, from their own peaks.
     *
     * @param int   $max    WP_MAX_MEMORY_LIMIT, bytes (-1: none).
     * @param int   $server Server's limit, bytes.
     * @param bool  $on     Whether Memory ceilings is on.
     * @param array $limits Kind => limit, bytes; added to.
     * @return array
     */
    private static function raised_fields($max, $server, $on, array &$limits) {
        $raised = -1 === $max || -1 === $server ? -1 : max($server, $max);
        $labels = array(
            'admin' => __('Memory limit: admin screens', 'seoprostack'),
            'cron'  => __('Memory limit: cron', 'seoprostack'),
        );
        $fields = array();
        // Hosting needs records cron with the REST API (other).
        foreach (array('admin' => 'admin', 'cron' => 'other') as $row => $kind) {
            $cap          = $on ? SEOProStack_Memory_Needs::web($server, $kind) : 0;
            $limits[$row] = $cap > 0 ? $cap : $raised;
            $fields['ceiling_' . $row] = array(
                'label' => $labels[$row],
                'value' => self::raised_value($limits[$row], $raised, $max),
            );
        }
        // With no WP_MAX_MEMORY_LIMIT, Memory ceilings keeps the admin's limit.
        $limits['image'] = -1 === $raised && $on ? $limits['admin'] : $raised;
        $fields['ceiling_image'] = array(
            'label' => __('Memory limit: editing images', 'seoprostack'),
            /* translators: %s: memory limit. */
            'value' => sprintf(__('%s (WP_MAX_MEMORY_LIMIT)', 'seoprostack'), self::size($limits['image'])),
        );
        return $fields;
    }

    /**
     * Value of a row WordPress raises to WP_MAX_MEMORY_LIMIT.
     *
     * @param int $limit  Limit in force, bytes (-1: none); not $raised when Memory ceilings set it.
     * @param int $raised Limit WordPress raises to, bytes (-1: none).
     * @param int $max    WP_MAX_MEMORY_LIMIT, bytes (-1: none).
     * @return string
     */
    private static function raised_value($limit, $raised, $max) {
        if ($limit !== $raised) {
            /* translators: 1: memory limit, 2: WP_MAX_MEMORY_LIMIT's value. */
            return sprintf(__('%1$s, set by Memory ceilings (WP_MAX_MEMORY_LIMIT: %2$s)', 'seoprostack'), self::size($limit), self::size($max));
        }
        /* translators: %s: memory limit. */
        return sprintf(__('%s (WP_MAX_MEMORY_LIMIT)', 'seoprostack'), self::size($raised));
    }

    /**
     * WP-CLI's row.
     *
     * @param bool  $on     Whether Memory ceilings is on.
     * @param array $limits Kind => limit, bytes; added to.
     * @return array
     */
    private static function cli_field($on, array &$limits) {
        $php = SEOProStack_Memory_Peaks::cli_php();
        $cli = $on ? SEOProStack_Memory_Needs::cli('') : $php;
        $row = array('label' => __('Memory limit: WP-CLI', 'seoprostack'));
        if (null === $cli) {
            $row['value'] = __('Not known yet: recorded when a WP-CLI command runs', 'seoprostack');
            return $row;
        }
        $limits['cli'] = $cli;
        $row['value']  = self::size($cli);
        if ($on) {
            /* translators: 1: memory limit, 2: WP-CLI's own memory limit. */
            $row['value'] = sprintf(__('%1$s, set by Memory ceilings (WP-CLI’s own: %2$s)', 'seoprostack'), self::size($cli), null === $php ? __('not known yet', 'seoprostack') : self::size($php));
        }
        return $row;
    }

    /** WP-CLI's and cron's highest peaks. @return array */
    private static function peaks_field() {
        $peaks = array();
        foreach (array_slice(SEOProStack_Memory_Peaks::highest(), 0, 5, true) as $key => $bytes) {
            $peaks[] = $key . ' ' . self::size($bytes);
        }
        return array(
            'label' => __('Highest memory use in 30 days: WP-CLI and cron', 'seoprostack'),
            'value' => $peaks ? implode('; ', $peaks) : __('None recorded yet', 'seoprostack'),
        );
    }

    /** The account's memory. @return array */
    private static function account_field() {
        $account = SEOProStack_Memory_Sizes::account();
        $where   = 'setting' === SEOProStack_Memory_Sizes::account_source() ? __('(Hosting needs → Account memory)', 'seoprostack') : __('(the container’s limit)', 'seoprostack');
        return array(
            'label' => __('Hosting account memory', 'seoprostack'),
            'value' => $account > 0 ? self::size($account) . ' ' . $where : __('Not known: enter it in Hosting needs → Account memory', 'seoprostack'),
        );
    }

    /**
     * Kinds of process with no limit, or one of more than half the
     * account's memory (the ceilings stop at half).
     *
     * @param array<string,int> $limits  Kind => limit, bytes.
     * @param int               $account Account memory, bytes, or 0.
     * @return array<string,string> Kind => its name and limit.
     */
    private static function risky(array $limits, $account) {
        $names = array(
            'page'  => __('Pages', 'seoprostack'),
            'admin' => __('Admin screens', 'seoprostack'),
            'cron'  => __('Cron', 'seoprostack'),
            'image' => __('Editing images', 'seoprostack'),
            'cli'   => __('WP-CLI', 'seoprostack'),
        );
        $risky = array();
        foreach ($limits as $kind => $bytes) {
            if (-1 === $bytes || ($account > 0 && 2 * $bytes > $account)) {
                /* translators: 1: kind of process, such as WP-CLI, 2: memory limit. */
                $risky[$kind] = sprintf(__('%1$s: %2$s', 'seoprostack'), $names[$kind], self::size($bytes));
            }
        }
        return $risky;
    }

    /**
     * Warning for processes with no limit, or one of more than half the
     * account's memory.
     *
     * @param array<string,int> $limits  Kind => limit, bytes.
     * @param int               $account Account memory, bytes, or 0.
     * @param bool              $on      Whether Memory ceilings is on.
     * @return string Sentence, or ''.
     */
    private static function warning(array $limits, $account, $on) {
        $risky = self::risky($limits, $account);
        if (!$risky) {
            return '';
        }
        $text = $account > 0
            /* translators: 1: account memory, 2: kinds of process with their limits. */
            ? sprintf(__('One PHP process can use more than half of the hosting account’s %1$s of memory, or has no limit (%2$s). When the account’s memory fills, the host stops processes on every site in it, not only the one that grew.', 'seoprostack'), self::size($account), implode('; ', $risky))
            /* translators: %s: kinds of process with their limits. */
            : sprintf(__('Some PHP processes have no memory limit (%s). One that grows can fill the hosting account’s memory, and the host then stops processes on every site in it.', 'seoprostack'), implode('; ', $risky));
        return $text . self::advice($risky, $account, $on);
    }

    /**
     * What to do about the risky limits.
     *
     * @param array $risky   From risky().
     * @param int   $account Account memory, bytes, or 0.
     * @param bool  $on      Whether Memory ceilings is on.
     * @return string Sentences, each with a leading space, or ''.
     */
    private static function advice(array $risky, $account, $on) {
        $text = '';
        if (!$on && SEOProStack_Memory_Ceilings::enabled()) {
            $text .= ' ' . __('The host does not let PHP change its memory limit, so Memory ceilings cannot set one: ask the host for lower limits.', 'seoprostack');
        } elseif (!$on) {
            $text .= ' ' . __('Turn on Memory ceilings on the Server tab to give each a limit.', 'seoprostack');
        } elseif (isset($risky['image']) || isset($risky['admin'])) {
            $text .= ' ' . sprintf(
                /* translators: %s: a line for wp-config.php. */
                __('WordPress raises the limit to WP_MAX_MEMORY_LIMIT for administrators and to edit images; set a lower one in wp-config.php, such as %s.', 'seoprostack'),
                "define( 'WP_MAX_MEMORY_LIMIT', '1024M' );"
            );
        }
        if (0 === $account) {
            $text .= ' ' . __('Enter the account’s memory in Hosting needs → Account memory, so ceilings stay under half of it.', 'seoprostack');
        }
        return $text;
    }

    /**
     * Size as text; "no limit" for -1.
     *
     * @param int $bytes Bytes.
     * @return string
     */
    private static function size($bytes) {
        $bytes = (int) $bytes;
        if (-1 === $bytes) {
            return __('no limit', 'seoprostack');
        }
        // 1.5 GB, not 2 GB: limits are often a part of a GB.
        $exact = 0 === $bytes % ($bytes >= GB_IN_BYTES ? GB_IN_BYTES : MB_IN_BYTES);
        return (string) size_format($bytes, $exact || ($bytes >= 10 * MB_IN_BYTES && $bytes < GB_IN_BYTES) ? 0 : 1);
    }
}
