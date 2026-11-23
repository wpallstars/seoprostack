<?php
/**
 * The warning in Memory ceilings' Hosting needs report: processes with no
 * limit, or more than half the account's memory, and what to do about them.
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

class SEOProStack_Memory_Warning {

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
                $risky[$kind] = sprintf(__('%1$s: %2$s', 'seoprostack'), $names[$kind], SEOProStack_Memory_Report::size($bytes));
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
    public static function warning(array $limits, $account, $on) {
        $risky = self::risky($limits, $account);
        if (!$risky) {
            return '';
        }
        $text = $account > 0
            /* translators: 1: account memory, 2: kinds of process with their limits. */
            ? sprintf(__('One PHP process can use more than half of the hosting account’s %1$s of memory, or has no limit (%2$s). When the account’s memory fills, the host stops processes on every site in it, not only the one that grew.', 'seoprostack'), SEOProStack_Memory_Report::size($account), implode('; ', $risky))
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
}
