<?php
/**
 * Faster database keys: comparing key sets and writing the changes, as
 * ALTER TABLE clauses or as the key lines of a CREATE TABLE statement for
 * dbDelta(). No database access.
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

final class SEOProStack_Fast_Keys_Plan {

    /** Add database keys' key on meta tables, which the target keys cover. */
    const ADDED_KEY = 'sps_meta_key_value';

    /**
     * The changes that give a table a key set: drops first, then unique
     * keys, the primary key and the rest. Only keys named in either set are
     * touched; SEO Pro Stack's own Add database keys key on meta tables goes
     * when the target set covers it.
     *
     * @param array $current Table as inspected (keys, unique).
     * @param array $want    Key set to reach.
     * @param array $other   The other set (its names are managed too).
     * @return string[] ALTER TABLE clauses.
     */
    public static function plan(array $current, array $want, array $other) {
        $existing = self::existing($current);
        $drops    = array();
        $adds     = array();
        foreach ($want['keys'] as $name => $columns) {
            $lower  = strtolower($name);
            $unique = in_array($name, $want['unique'], true);
            $have   = isset($existing[$lower]) ? $existing[$lower] : null;
            if (null !== $have && $have['unique'] === $unique && self::same($have['columns'], $columns)) {
                continue;
            }
            if (null !== $have) {
                $drops[] = self::drop($have['name']);
            }
            $adds[self::order($name, $unique) . $lower] = self::add($name, $columns, $unique);
        }
        foreach (self::managed($want, $other) as $lower) {
            if (isset($existing[$lower])) {
                $drops[] = self::drop($existing[$lower]['name']);
            }
        }
        ksort($adds);
        return array_merge($drops, array_values($adds));
    }

    /**
     * A table's keys by lowercase name.
     *
     * @param array $current Table as inspected.
     * @return array<string,array{name:string,columns:array,unique:bool}>
     */
    private static function existing(array $current) {
        $existing = array();
        foreach ($current['keys'] as $name => $columns) {
            $existing[strtolower($name)] = array('name' => $name, 'columns' => $columns, 'unique' => in_array($name, $current['unique'], true));
        }
        return $existing;
    }

    /**
     * Where a new key goes: unique keys first, then the primary key, then
     * the rest.
     *
     * @param string $name   Key name.
     * @param bool   $unique Unique key.
     * @return int
     */
    private static function order($name, $unique) {
        if ('PRIMARY' === $name) {
            return 1;
        }
        return $unique ? 0 : 2;
    }

    /**
     * Lowercase names of keys to drop when the table does not need them:
     * the other set's, and Add database keys' key when this set covers it.
     *
     * @param array $want  Key set to reach.
     * @param array $other The other set.
     * @return string[]
     */
    private static function managed(array $want, array $other) {
        $managed = array_diff(self::names($other['keys']), self::names($want['keys']));
        if (isset($want['keys']['meta_value'], $want['keys']['meta_key'])) {
            $managed[] = self::ADDED_KEY;
        }
        return $managed;
    }

    /**
     * Lowercase names of a key set's keys (or columns).
     *
     * @param array $keys Name => anything.
     * @return string[]
     */
    public static function names(array $keys) {
        $names = array();
        foreach (array_keys($keys) as $name) {
            $names[] = strtolower((string) $name);
        }
        return $names;
    }

    /**
     * The keys a table gets when switched on: the target keys, plus
     * WordPress's (or WooCommerce's) own keys that no target key covers,
     * such as keys a newer version adds. A unique key is covered only by a
     * unique key or primary key on exactly its columns.
     *
     * @param array $target Target keys.
     * @param array $base   Standard keys.
     * @return array{keys:array<string,array<string,int>>,unique:string[]}
     */
    public static function combined(array $target, array $base) {
        $keys   = $target['keys'];
        $unique = $target['unique'];
        $names  = self::names($keys);
        foreach ($base['keys'] as $name => $columns) {
            $is_unique = in_array($name, $base['unique'], true);
            if (in_array(strtolower((string) $name), $names, true) || self::covered($target, $columns, $is_unique || 'PRIMARY' === $name)) {
                continue;
            }
            $keys[$name] = $columns;
            if ($is_unique) {
                $unique[] = $name;
            }
        }
        return array('keys' => $keys, 'unique' => $unique);
    }

    /**
     * Whether a target key covers a key's columns.
     *
     * @param array $target    Target keys.
     * @param array $columns   The key's columns => prefix length.
     * @param bool  $is_unique The key is unique (or primary).
     * @return bool
     */
    private static function covered(array $target, array $columns, $is_unique) {
        foreach ($target['keys'] as $other => $other_columns) {
            $other_unique = 'PRIMARY' === $other || in_array($other, $target['unique'], true);
            if ($is_unique && (!$other_unique || count($other_columns) !== count($columns))) {
                continue;
            }
            if (self::covers($other_columns, $columns)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether $other starts with $key's columns, each at least as long (0
     * for the whole column).
     *
     * @param array $other Columns => prefix length.
     * @param array $key   Columns => prefix length.
     * @return bool
     */
    private static function covers(array $other, array $key) {
        $other = array_values(array_map(null, array_map('strtolower', array_keys($other)), array_values($other)));
        $i     = 0;
        foreach ($key as $column => $length) {
            if (!isset($other[$i]) || $other[$i][0] !== strtolower($column)) {
                return false;
            }
            $have = (int) $other[$i][1];
            if (0 !== $have && (0 === (int) $length || $have < (int) $length)) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /**
     * Same columns and prefix lengths.
     *
     * @param array $have Columns => prefix length.
     * @param array $want Columns => prefix length.
     * @return bool
     */
    private static function same(array $have, array $want) {
        $have = array_map('intval', array_change_key_case($have));
        $want = array_map('intval', array_change_key_case($want));
        return $have === $want;
    }

    /**
     * A quoted identifier.
     *
     * @param string $name Table, key or column name.
     * @return string
     */
    public static function quote($name) {
        return '`' . str_replace('`', '``', (string) $name) . '`';
    }

    private static function columns(array $columns) {
        $parts = array();
        foreach ($columns as $column => $length) {
            $parts[] = self::quote($column) . ($length ? '(' . (int) $length . ')' : '');
        }
        return implode(', ', $parts);
    }

    private static function drop($name) {
        return 'PRIMARY' === $name ? 'DROP PRIMARY KEY' : 'DROP KEY ' . self::quote($name);
    }

    private static function add($name, array $columns, $unique) {
        if ('PRIMARY' === $name) {
            return 'ADD PRIMARY KEY (' . self::columns($columns) . ')';
        }
        return 'ADD ' . ($unique ? 'UNIQUE ' : '') . 'KEY ' . self::quote($name) . ' (' . self::columns($columns) . ')';
    }

    /**
     * One CREATE TABLE statement for dbDelta() with the target keys (and
     * standard keys none of them covers) in place of the key lines either
     * set names. Other lines pass; primary key columns become NOT NULL.
     *
     * @param string $query  Statement.
     * @param array  $match  Its parts: 1 table, 2 body, 3 after the brackets.
     * @param array  $target Target keys.
     * @param array  $base   Standard keys.
     * @return string
     */
    public static function rewrite($query, array $match, array $target, array $base) {
        $target  = self::combined($target, $base);
        $managed = array_merge(self::names($target['keys']), self::names($base['keys']));
        $primary = isset($target['keys']['PRIMARY']) ? self::names($target['keys']['PRIMARY']) : array();
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
