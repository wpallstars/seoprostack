<?php
/**
 * Faster database keys: one online ALTER TABLE (ALGORITHM=INPLACE,
 * LOCK=NONE) with a short metadata lock wait, then ANALYZE TABLE. The
 * session settings it needs are put back afterwards.
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

final class SEOProStack_Fast_Keys_Alter {

    /**
     * Run the ALTER TABLE, then ANALYZE TABLE when it worked.
     *
     * @param string $table Full table name.
     * @param string $sql   ALTER TABLE statement.
     * @return string|null The database's error, or null when it worked.
     *
     * Hosts may disable set_time_limit(); its warning is not an error here.
     * @SuppressWarnings("PHPMD.ErrorControlOperator")
     */
    public static function run($table, $sql) {
        global $wpdb;
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions -- online DDL; hosts may disable it.
        }
        $previous = $wpdb->suppress_errors(true);
        $session  = self::session_start();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- DDL built from fixed key lists; identifiers quoted.
        $ok    = $wpdb->query($sql);
        $error = (string) $wpdb->last_error;
        if (false !== $ok) {
            $wpdb->query('ANALYZE TABLE ' . SEOProStack_Fast_Keys_Plan::quote($table));
        }
        // phpcs:enable
        self::session_end($session);
        $wpdb->suppress_errors($previous);
        return false === $ok ? $error : null;
    }

    /**
     * Session settings for the change; returns the ones to put back.
     *
     * @return array{saved:array|null,statement:float}
     */
    private static function session_start() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- session settings for one DDL statement.
        $saved = $wpdb->get_row('SELECT @@SESSION.sql_mode AS mode, @@SESSION.lock_wait_timeout AS wait', ARRAY_A);
        // A short wait for the table's metadata lock, so a long query
        // elsewhere makes this fail instead of queueing every request behind it.
        $wpdb->query('SET SESSION lock_wait_timeout = 10');
        // Strict mode lets MySQL make a new primary key column NOT NULL in place.
        $wpdb->query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')");
        // MariaDB only (null on MySQL): no statement time limit for the change.
        $statement = (float) $wpdb->get_var('SELECT @@SESSION.max_statement_time');
        if ($statement > 0) {
            $wpdb->query('SET SESSION max_statement_time = 0');
        }
        // phpcs:enable
        return array('saved' => is_array($saved) ? $saved : null, 'statement' => $statement);
    }

    /**
     * Put the session settings back.
     *
     * @param array{saved:array|null,statement:float} $session From session_start().
     */
    private static function session_end(array $session) {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- session settings for one DDL statement.
        if (null !== $session['saved']) {
            $wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s, lock_wait_timeout = %d', $session['saved']['mode'], (int) $session['saved']['wait']));
        }
        if ($session['statement'] > 0) {
            $wpdb->query($wpdb->prepare('SET SESSION max_statement_time = %f', $session['statement']));
        }
        // phpcs:enable
    }
}
