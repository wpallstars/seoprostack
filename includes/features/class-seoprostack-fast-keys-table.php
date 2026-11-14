<?php
/**
 * Faster database keys: one table. Reads it as it is, decides whether it
 * can change now, and changes it in one online ALTER TABLE
 * (ALGORITHM=INPLACE, LOCK=NONE, a short metadata lock wait): if the
 * database refuses, nothing changes and the reason is kept.
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

final class SEOProStack_Fast_Keys_Table {

    /** Rows above which a table is left to WP-CLI. */
    const LARGE = 1000000;

    /** Seconds before a change that failed is tried again. */
    const RETRY = WEEK_IN_SECONDS;

    /**
     * A table as it is: engine, row format, rows, columns (name => nullable)
     * and keys (name => columns), or null when there is no such table.
     *
     * @param string $suffix Table without prefix.
     * @return array|null|WP_Error
     */
    public static function inspect($suffix) {
        global $wpdb;
        $table = SEOProStack_Fast_Keys_Schema::table($suffix);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- table metadata, read on demand.
        $info = $wpdb->get_row($wpdb->prepare('SELECT ENGINE, ROW_FORMAT, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table), ARRAY_A);
        if (!$info) {
            return $wpdb->last_error ? new WP_Error('metadata', $wpdb->last_error) : null;
        }
        $columns = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table), ARRAY_A) as $row) {
            $columns[strtolower((string) $row['COLUMN_NAME'])] = 'YES' === $row['IS_NULLABLE'];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT INDEX_NAME, COLUMN_NAME, SUB_PART, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', $table), ARRAY_A);
        // phpcs:enable
        if ($wpdb->last_error) {
            return new WP_Error('metadata', $wpdb->last_error);
        }
        $keys   = array();
        $unique = array();
        foreach ((array) $rows as $row) {
            $name = (string) $row['INDEX_NAME'];
            $keys[$name][(string) $row['COLUMN_NAME']] = null === $row['SUB_PART'] ? 0 : (int) $row['SUB_PART'];
            if ('PRIMARY' !== $name && '0' === (string) $row['NON_UNIQUE']) {
                $unique[$name] = true;
            }
        }
        return array(
            'table'   => $table,
            'engine'  => strtolower((string) $info['ENGINE']),
            'format'  => strtolower((string) $info['ROW_FORMAT']),
            'rows'    => (int) $info['TABLE_ROWS'],
            'columns' => $columns,
            'keys'    => $keys,
            'unique'  => array_keys($unique),
        );
    }

    /**
     * One table: reach $want from whatever is there.
     *
     * @param string $suffix Table without prefix.
     * @param array  $want   Key set to reach.
     * @param array  $other  The other key set.
     * @param bool   $undo   Restoring WordPress's keys.
     * @param bool   $cli    From WP-CLI.
     * @param array  $last   This table's last stored result.
     * @return array<string,mixed> status, note, plan and time, with rows and
     *                             sql when known; or $last while a failed
     *                             change waits to be tried again.
     */
    public static function run($suffix, array $want, array $other, $undo, $cli, array $last) {
        $result  = array('status' => 'done', 'note' => '', 'plan' => '', 'time' => time());
        $current = self::inspect($suffix);
        if (null === $current) {
            $result['status'] = 'missing';
            return $result;
        }
        if (is_wp_error($current)) {
            return array_merge($result, array('status' => 'failed', 'note' => $current->get_error_message()));
        }
        $result['rows'] = $current['rows'];
        $plan           = SEOProStack_Fast_Keys_Plan::plan($current, $want, $other);
        if (!$plan) {
            return $result;
        }
        $result['plan'] = hash('sha256', implode(';', $plan));
        $skip           = self::skip($current, $want, $undo, $cli);
        if ('' !== $skip) {
            return array_merge($result, array('status' => 'skipped', 'note' => $skip));
        }
        if (!$cli && self::failed_recently($last, $result['plan'])) {
            return $last;
        }
        $result['sql'] = 'ALTER TABLE ' . SEOProStack_Fast_Keys_Plan::quote($current['table']) . ' ' . implode(', ', $plan) . ', ALGORITHM=INPLACE, LOCK=NONE';
        $error         = self::alter($current['table'], $result['sql']);
        if (null !== $error) {
            $result['status'] = 'failed';
            $result['note']   = substr($error, 0, 200);
        }
        return $result;
    }

    /**
     * Whether the same change failed within RETRY.
     *
     * @param array  $last Last stored result.
     * @param string $plan This change's fingerprint.
     * @return bool
     */
    private static function failed_recently(array $last, $plan) {
        if (!isset($last['status'], $last['plan'], $last['time']) || 'failed' !== $last['status']) {
            return false;
        }
        return $plan === $last['plan'] && time() - (int) $last['time'] < self::RETRY;
    }

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
    private static function alter($table, $sql) {
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

    /**
     * Why a table cannot change now, or ''.
     *
     * @param array $current inspect() result.
     * @param array $want    Key set to reach.
     * @param bool  $undo    Restoring WordPress's keys.
     * @param bool  $cli     From WP-CLI.
     * @return string
     */
    private static function skip(array $current, array $want, $undo, $cli) {
        $reason = self::skip_format($current, $undo);
        if ('' === $reason) {
            $reason = self::missing_column($current, $want);
        }
        if ('' === $reason && !$undo) {
            $reason = self::null_primary($current, $want);
        }
        if ('' === $reason && !$cli && $current['rows'] > self::LARGE) {
            $reason = __('Large table: change it with WP-CLI (wp seoprostack fast-keys apply), as a web request may stop first.', 'seoprostack');
        }
        return $reason;
    }

    /**
     * The table's engine or row format cannot take the keys, or ''.
     *
     * @param array $current inspect() result.
     * @param bool  $undo    Restoring WordPress's keys.
     * @return string
     */
    private static function skip_format(array $current, $undo) {
        if ('innodb' !== $current['engine']) {
            return __('The table is not InnoDB.', 'seoprostack');
        }
        if (!$undo && !in_array($current['format'], array('dynamic', 'compressed'), true)) {
            return __('The table’s row format is too old for long keys (it needs DYNAMIC).', 'seoprostack');
        }
        return '';
    }

    /**
     * A key's column the table does not have, or ''.
     *
     * @param array $current inspect() result.
     * @param array $want    Key set to reach.
     * @return string
     */
    private static function missing_column(array $current, array $want) {
        foreach ($want['keys'] as $columns) {
            foreach (SEOProStack_Fast_Keys_Plan::names($columns) as $column) {
                if (!isset($current['columns'][$column])) {
                    /* translators: %s: column name */
                    return sprintf(__('The table has no %s column.', 'seoprostack'), $column);
                }
            }
        }
        return '';
    }

    /**
     * A new primary key column with NULL in some rows, or ''.
     *
     * @param array $current inspect() result.
     * @param array $want    Key set to reach.
     * @return string
     */
    private static function null_primary(array $current, array $want) {
        global $wpdb;
        if (!isset($want['keys']['PRIMARY'])) {
            return '';
        }
        foreach (SEOProStack_Fast_Keys_Plan::names($want['keys']['PRIMARY']) as $column) {
            if (empty($current['columns'][$column])) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- one row; identifiers from fixed lists, quoted.
            if ($wpdb->get_var('SELECT 1 FROM ' . SEOProStack_Fast_Keys_Plan::quote($current['table']) . ' WHERE ' . SEOProStack_Fast_Keys_Plan::quote($column) . ' IS NULL LIMIT 1')) {
                /* translators: %s: column name */
                return sprintf(__('Some rows have no %s, so it cannot be part of the primary key. Nothing was changed.', 'seoprostack'), $column);
            }
        }
        return '';
    }
}
