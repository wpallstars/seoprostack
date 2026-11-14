<?php
/**
 * Faster database keys: dbDelta(). For the tables SEO Pro Stack keeps, the
 * key lines WordPress or WooCommerce would compare are swapped for this
 * plugin's keys, so no standard key is added back and no duplicate-key
 * error is logged. Other lines, including new columns and new keys, pass
 * unchanged; primary key columns stay NOT NULL if a column type changes.
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

final class SEOProStack_Fast_Keys_Dbdelta {

    /**
     * The dbdelta_queries filter.
     *
     * @param mixed $queries Queries.
     * @return mixed
     */
    public static function filter($queries) {
        if (!is_array($queries) || is_multisite()) {
            return $queries;
        }
        $tables = self::owned_tables();
        if (!$tables) {
            return $queries;
        }
        foreach ($queries as $i => $query) {
            $match = self::create_table($query);
            if (null === $match) {
                continue;
            }
            $name = strtolower($match[1]);
            if (isset($tables[$name])) {
                $queries[$i] = self::rewrite($query, $match, $tables[$name]);
            }
        }
        return $queries;
    }

    /**
     * A CREATE TABLE statement's parts, or null for other queries.
     *
     * @param mixed $query Query.
     * @return string[]|null 1 table, 2 body, 3 after the brackets.
     */
    private static function create_table($query) {
        if (!is_string($query) || !preg_match('/^\s*CREATE TABLE\s+`?([^\s`(]+)`?\s*\((.*)\)([^)]*)$/is', $query, $match)) {
            return null;
        }
        return $match;
    }

    /**
     * Tables SEO Pro Stack keeps: lowercase full name => suffix.
     *
     * @return array<string,string>
     */
    private static function owned_tables() {
        $targets = SEOProStack_Fast_Keys_Schema::targets();
        $tables  = array();
        foreach (array_keys(SEOProStack_Fast_Keys::state()['owned']) as $suffix) {
            if (isset($targets[$suffix])) {
                $tables[strtolower(SEOProStack_Fast_Keys_Schema::table($suffix))] = $suffix;
            }
        }
        return $tables;
    }

    /**
     * One CREATE TABLE statement with the target keys (and standard keys
     * none of them covers) in place of the key lines either set names.
     * Other lines pass; primary key columns become NOT NULL.
     *
     * @param string $query  Statement.
     * @param array  $match  Its parts: 1 table, 2 body, 3 after the brackets.
     * @param string $suffix Table without prefix.
     * @return string
     */
    private static function rewrite($query, array $match, $suffix) {
        $targets = SEOProStack_Fast_Keys_Schema::targets();
        $base    = SEOProStack_Fast_Keys_Schema::standard_for($suffix);
        $target  = SEOProStack_Fast_Keys_Schema::combined($targets[$suffix], $base);
        $managed = array_merge(SEOProStack_Fast_Keys_Plan::names($target['keys']), SEOProStack_Fast_Keys_Plan::names($base['keys']));
        $primary = isset($target['keys']['PRIMARY']) ? SEOProStack_Fast_Keys_Plan::names($target['keys']['PRIMARY']) : array();
        $lines   = array();
        foreach (explode("\n", $match[2]) as $line) {
            $line = self::rewrite_line(trim($line, " \t\r\0\x0B,"), $managed, $primary);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }
        foreach ($target['keys'] as $name => $columns) {
            $lines[] = self::key_line($name, $columns, in_array($name, $target['unique'], true));
        }
        $head = substr($query, 0, strpos($query, '(') + 1);
        return $head . "\n" . implode(",\n", $lines) . "\n)" . $match[3];
    }

    /**
     * One line of a CREATE TABLE body: '' to leave it out (a managed key).
     *
     * @param string   $line    Line, trimmed.
     * @param string[] $managed Lowercase key names either set names.
     * @param string[] $primary Lowercase primary key columns.
     * @return string
     */
    private static function rewrite_line($line, array $managed, array $primary) {
        if ('' === $line) {
            return '';
        }
        if (preg_match('/^(PRIMARY\s+KEY|(?:UNIQUE|FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)|KEY|INDEX)\s*`?([\w$-]*)`?\s*\(/i', $line, $key)) {
            $name = 0 === stripos($key[1], 'PRIMARY') ? 'primary' : strtolower($key[2]);
            return in_array($name, $managed, true) ? '' : $line;
        }
        $column = strtolower(trim((string) strtok($line, " \t"), '`'));
        if (!in_array($column, $primary, true)) {
            return $line;
        }
        // The primary key is listed below; its columns cannot be NULL.
        $line = (string) preg_replace('/\s+PRIMARY\s+KEY\b/i', '', $line);
        if (preg_match('/\bNOT\s+NULL\b/i', $line)) {
            return $line;
        }
        return (string) preg_replace('/\s+(?:DEFAULT\s+NULL|NULL)\b/i', '', $line) . ' NOT NULL';
    }

    /**
     * A key line as dbDelta() writes them.
     *
     * @param string $name    Key name.
     * @param array  $columns Columns => prefix length.
     * @param bool   $unique  Unique key.
     * @return string
     */
    private static function key_line($name, array $columns, $unique) {
        $list = array();
        foreach ($columns as $column => $length) {
            $list[] = $column . ($length ? '(' . (int) $length . ')' : '');
        }
        if ('PRIMARY' === $name) {
            $type = 'PRIMARY KEY ';
        } else {
            $type = ($unique ? 'UNIQUE KEY ' : 'KEY ') . $name . ' ';
        }
        return $type . '(' . implode(',', $list) . ')';
    }
}
