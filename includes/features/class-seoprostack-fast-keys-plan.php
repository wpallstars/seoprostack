<?php
/**
 * Faster database keys: comparing a table's keys with a key set and
 * writing the changes as ALTER TABLE clauses. No database access.
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
}
