<?php
/**
 * Faster database keys: one run over the tables, under a database lock so
 * two never overlap, recording which tables SEO Pro Stack keeps and a log
 * of changes.
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

final class SEOProStack_Fast_Keys_Run {

    /** Seconds a background run may start new tables for. */
    const BUDGET = 20;

    /** Log entries kept. */
    const LOG_MAX = 30;

    /**
     * Give the tables their keys (on) or WordPress's (off) where needed.
     *
     * @param bool        $cli   From WP-CLI: no size limit, no retry wait, no time budget.
     * @param string|null $force 'apply' or 'undo' from WP-CLI.
     * @return array<string,array> Results per table.
     */
    public static function run($cli = false, $force = null) {
        global $wpdb;
        if (is_multisite()) {
            return array();
        }
        $undo = 'undo' === $force || (null === $force && !SEOProStack_Fast_Keys::switched_on());
        if (!$undo && !SEOProStack_Fast_Keys::enabled()) {
            return array(); // Waiting for Index WP MySQL For Speed to be deactivated.
        }
        $lock = 'sps_fast_keys_' . substr(hash('sha256', DB_NAME . $wpdb->prefix), 0, 30);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return array();
        }
        $results = array();
        $state   = SEOProStack_Fast_Keys::state();
        try {
            $results = self::tables($state, $undo, $cli);
            self::save($state);
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release our connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        if (!$cli) {
            SEOProStack_Fast_Keys_Mu_File::sync(!$undo || !empty($state['owned']));
        }
        return $results;
    }

    /**
     * Store the state, with the latest log entries only. Not autoloaded.
     *
     * @param array $state State.
     */
    private static function save(array $state) {
        $state['log'] = array_slice($state['log'], -self::LOG_MAX);
        update_option(SEOProStack_Fast_Keys::STATE, $state, false);
    }

    /**
     * Each table in turn, within the time budget (none from WP-CLI).
     *
     * @param array $state Stored state, updated.
     * @param bool  $undo  Restoring WordPress's keys.
     * @param bool  $cli   From WP-CLI.
     * @return array<string,array> Results per table.
     */
    private static function tables(array &$state, $undo, $cli) {
        $results = array();
        $start   = microtime(true);
        foreach (SEOProStack_Fast_Keys_Schema::targets() as $suffix => $target) {
            $base = SEOProStack_Fast_Keys_Schema::standard_for($suffix);
            if ($undo && !self::undoable($state, $suffix, $base)) {
                continue;
            }
            if (!$cli && !SEOProStack_Fast_Keys::more_time($start, self::BUDGET)) {
                SEOProStack_Fast_Keys::soon();
                break;
            }
            $fast             = SEOProStack_Fast_Keys_Schema::combined($target, $base);
            $last             = isset($state['tables'][$suffix]) ? $state['tables'][$suffix] : array();
            $results[$suffix] = $undo
                ? SEOProStack_Fast_Keys_Table::run($suffix, $base, $fast, true, $cli, $last)
                : SEOProStack_Fast_Keys_Table::run($suffix, $fast, $base, false, $cli, $last);
            self::record($state, $suffix, $results[$suffix], $undo);
        }
        if ($undo) {
            $state['tables'] = array_intersect_key($state['tables'], $state['owned']);
        }
        return $results;
    }

    /**
     * Whether a table's standard keys can be put back: SEO Pro Stack keeps
     * it, and its standard keys are known.
     *
     * @param array  $state  Stored state.
     * @param string $suffix Table without prefix.
     * @param array  $base   Standard keys.
     * @return bool
     */
    private static function undoable(array $state, $suffix, array $base) {
        return isset($state['owned'][$suffix]) && array() !== $base['keys'];
    }

    /**
     * Store one table's result: whether it is kept, and a log line when the
     * table was changed.
     *
     * @param array  $state  Stored state, updated.
     * @param string $suffix Table without prefix.
     * @param array  $result Its result.
     * @param bool   $undo   Restoring WordPress's keys.
     */
    private static function record(array &$state, $suffix, array $result, $undo) {
        $stored = $result;
        unset($stored['sql']);
        $state['tables'][$suffix] = $stored;
        if ('missing' === $result['status'] || ($undo && 'done' === $result['status'])) {
            unset($state['owned'][$suffix]);
        } elseif ('done' === $result['status'] && !isset($state['owned'][$suffix])) {
            $state['owned'][$suffix] = time();
        }
        if (!empty($result['sql'])) {
            $state['log'][] = array(
                'time'   => time(),
                'table'  => SEOProStack_Fast_Keys_Schema::table($suffix),
                'action' => $undo ? 'undo' : 'apply',
                'result' => 'done' === $result['status'] ? 'done' : 'failed: ' . $result['note'],
            );
        }
    }
}
