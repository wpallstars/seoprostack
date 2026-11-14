<?php
/**
 * Faster database keys: the key sets. The target keys (Index WP MySQL For
 * Speed 1.6, Barracuda) and WordPress's and WooCommerce's own keys for the
 * same tables, read from the running version's schema.
 *
 * A key set is array('keys' => name => (column => prefix length, 0 for the
 * whole column), 'unique' => names). 'PRIMARY' is the primary key.
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

final class SEOProStack_Fast_Keys_Schema {

    /** A table with no keys known. */
    const NONE = array('keys' => array(), 'unique' => array());

    /**
     * The target keys per table (without prefix). From Index WP MySQL For
     * Speed 1.6 (getHighPerformanceIndexes1_6, Barracuda), so its keys are
     * adopted.
     *
     * @return array<string,array{keys:array<string,array<string,int>>,unique:string[]}>
     */
    public static function targets() {
        // phpcs:disable WordPress.DB.SlowDBQuery -- key definitions (column names), not queries.
        return array(
            'posts'                      => array(
                'keys'   => array(
                    'PRIMARY'                       => array('ID' => 0),
                    'post_name'                     => array('post_name' => 0),
                    'post_parent'                   => array('post_parent' => 0, 'post_type' => 0, 'post_status' => 0),
                    'type_status_date'              => array('post_type' => 0, 'post_status' => 0, 'post_date' => 0, 'post_author' => 0),
                    'post_author'                   => array('post_author' => 0, 'post_type' => 0, 'post_status' => 0, 'post_date' => 0),
                    'type_status_post_date_gmt'     => array('post_type' => 0, 'post_status' => 0, 'post_date_gmt' => 0),
                    'type_status_modified_date_gmt' => array('post_type' => 0, 'post_status' => 0, 'post_modified_gmt' => 0),
                ),
                'unique' => array(),
            ),
            'postmeta'                   => self::meta('meta_id', 'post_id'),
            'usermeta'                   => self::meta('umeta_id', 'user_id'),
            'termmeta'                   => self::meta('meta_id', 'term_id'),
            'options'                    => array(
                'keys'   => array(
                    'option_id' => array('option_id' => 0),
                    'PRIMARY'   => array('option_name' => 0),
                    'autoload'  => array('autoload' => 0),
                ),
                'unique' => array('option_id'),
            ),
            'comments'                   => array(
                'keys'   => array(
                    'comment_ID'                   => array('comment_ID' => 0),
                    'PRIMARY'                      => array('comment_post_ID' => 0, 'comment_ID' => 0),
                    'comment_approved_date_gmt'    => array('comment_approved' => 0, 'comment_date_gmt' => 0, 'comment_ID' => 0),
                    'comment_date_gmt'             => array('comment_date_gmt' => 0, 'comment_ID' => 0),
                    'comment_parent'               => array('comment_parent' => 0, 'comment_ID' => 0),
                    'comment_author_email'         => array('comment_author_email' => 0, 'comment_post_ID' => 0, 'comment_ID' => 0),
                    'comment_post_parent_approved' => array('comment_post_ID' => 0, 'comment_parent' => 0, 'comment_approved' => 0, 'comment_type' => 0, 'user_id' => 0, 'comment_date_gmt' => 0, 'comment_ID' => 0),
                ),
                'unique' => array('comment_ID'),
            ),
            'commentmeta'                => array(
                'keys'   => array(
                    'meta_id'    => array('meta_id' => 0),
                    'PRIMARY'    => array('meta_key' => 0, 'comment_id' => 0, 'meta_id' => 0),
                    'comment_id' => array('comment_id' => 0, 'meta_key' => 0, 'meta_value' => 32),
                    'meta_value' => array('meta_value' => 32),
                ),
                'unique' => array('meta_id'),
            ),
            'users'                      => array(
                'keys'   => array(
                    'PRIMARY'        => array('ID' => 0),
                    'user_login_key' => array('user_login' => 0),
                    'user_nicename'  => array('user_nicename' => 0),
                    'user_email'     => array('user_email' => 0),
                    'display_name'   => array('display_name' => 0),
                ),
                'unique' => array(),
            ),
            'woocommerce_order_itemmeta' => self::meta('meta_id', 'order_item_id'),
            'wc_orders_meta'             => array(
                'keys'   => array(
                    'id'             => array('id' => 0),
                    'PRIMARY'        => array('order_id' => 0, 'meta_key' => 0, 'id' => 0),
                    'meta_key_value' => array('meta_key' => 0, 'meta_value' => 32, 'order_id' => 0, 'id' => 0),
                ),
                'unique' => array('id'),
            ),
        );
        // phpcs:enable
    }

    /**
     * Target keys of a meta table.
     *
     * @param string $id    Row ID column.
     * @param string $owner Owner ID column.
     * @return array{keys:array<string,array<string,int>>,unique:string[]}
     */
    private static function meta($id, $owner) {
        // phpcs:disable WordPress.DB.SlowDBQuery -- key definitions (column names), not queries.
        return array(
            'keys'   => array(
                $id          => array($id => 0),
                'PRIMARY'    => array($owner => 0, 'meta_key' => 0, $id => 0),
                'meta_key'   => array('meta_key' => 0, 'meta_value' => 32, $owner => 0, $id => 0),
                'meta_value' => array('meta_value' => 32, $id => 0),
            ),
            'unique' => array($id),
        );
        // phpcs:enable
    }

    /**
     * WooCommerce's own keys for its tables (WC_Install and
     * OrdersTableDataStore), which are not in WordPress's schema.
     *
     * @return array<string,array{keys:array<string,array<string,int>>,unique:string[]}>
     */
    private static function woo_standard() {
        // phpcs:disable WordPress.DB.SlowDBQuery -- key definitions (column names), not queries.
        return array(
            'woocommerce_order_itemmeta' => array(
                'keys'   => array(
                    'PRIMARY'       => array('meta_id' => 0),
                    'order_item_id' => array('order_item_id' => 0),
                    'meta_key'      => array('meta_key' => 32),
                ),
                'unique' => array(),
            ),
            'wc_orders_meta'             => array(
                'keys'   => array(
                    'PRIMARY'                      => array('id' => 0),
                    'meta_key_value'               => array('meta_key' => 50, 'meta_value' => 20),
                    'order_id_meta_key_meta_value' => array('order_id' => 0, 'meta_key' => 100, 'meta_value' => 82),
                ),
                'unique' => array(),
            ),
        );
        // phpcs:enable
    }

    /**
     * WordPress's own keys for the tables, from the running version's schema
     * (and WooCommerce's), so switching off restores this version's keys.
     *
     * @return array<string,array{keys:array<string,array<string,int>>,unique:string[]}>
     */
    public static function standard() {
        static $standard = null;
        if (null !== $standard) {
            return $standard;
        }
        require_once ABSPATH . 'wp-admin/includes/schema.php';
        $schema   = wp_get_db_schema('all');
        $standard = self::woo_standard();
        foreach (array_keys(self::targets()) as $suffix) {
            $table = self::table($suffix);
            if (isset($standard[$suffix]) || !preg_match('/CREATE TABLE\s+`?' . preg_quote($table, '/') . '`?\s*\((.*?)^\)/msi', $schema, $match)) {
                continue;
            }
            $standard[$suffix] = self::parse_keys($match[1]);
        }
        return $standard;
    }

    /**
     * One table's standard keys (none when unknown).
     *
     * @param string $suffix Table without prefix.
     * @return array{keys:array<string,array<string,int>>,unique:string[]}
     */
    public static function standard_for($suffix) {
        $standard = self::standard();
        return isset($standard[$suffix]) ? $standard[$suffix] : self::NONE;
    }

    /**
     * Keys in the body of a CREATE TABLE statement.
     *
     * @param string $body Lines between the brackets.
     * @return array{keys:array<string,array<string,int>>,unique:string[]}
     */
    private static function parse_keys($body) {
        $keys   = array();
        $unique = array();
        foreach (explode("\n", $body) as $line) {
            $key = self::parse_key(trim($line, " \t\r\0\x0B,"));
            if (null === $key) {
                continue;
            }
            $keys[$key['name']] = $key['columns'];
            if ($key['unique']) {
                $unique[] = $key['name'];
            }
        }
        return array('keys' => $keys, 'unique' => $unique);
    }

    /**
     * One key line of a CREATE TABLE statement, or null for other lines.
     *
     * @param string $line Line, trimmed.
     * @return array{name:string,columns:array<string,int>,unique:bool}|null
     */
    private static function parse_key($line) {
        if (!preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)|KEY|INDEX)\s*`?([\w$-]*)`?\s*\((.+)\)$/i', $line, $match)) {
            return null;
        }
        $primary = 0 === stripos($match[1], 'PRIMARY');
        $name    = $primary ? 'PRIMARY' : $match[2];
        $columns = array();
        foreach (explode(',', $match[3]) as $column) {
            if (preg_match('/^\s*`?([\w$-]+)`?\s*(?:\(\s*(\d+)\s*\))?/', $column, $part)) {
                $columns[$part[1]] = isset($part[2]) ? (int) $part[2] : 0;
            }
        }
        if ('' === $name || !$columns) {
            return null;
        }
        return array('name' => $name, 'columns' => $columns, 'unique' => !$primary && 0 === stripos($match[1], 'UNIQUE'));
    }

    /**
     * Full table name.
     *
     * @param string $suffix Table without prefix.
     * @return string
     */
    public static function table($suffix) {
        global $wpdb;
        return in_array($suffix, array('users', 'usermeta'), true) ? $wpdb->base_prefix . $suffix : $wpdb->prefix . $suffix;
    }
}
