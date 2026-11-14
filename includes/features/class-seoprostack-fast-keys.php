<?php
/**
 * Faster database keys: the keys Index WP MySQL For Speed (1.6, Barracuda)
 * gives WordPress's and WooCommerce's meta, post, comment, option and user
 * tables, added in the background and kept after updates.
 *
 * Only keys named in the running WordPress (or WooCommerce) schema, or in
 * the target set, are changed; other plugins' keys stay. Each table changes
 * in one online ALTER TABLE (ALGORITHM=INPLACE, LOCK=NONE, a short metadata
 * lock wait): if the database refuses, nothing changes and the reason is
 * kept. Tables that are not InnoDB with a DYNAMIC or COMPRESSED row format,
 * have NULL in a new primary key column, or are larger than LARGE rows (left
 * to WP-CLI) are skipped. Tables already keyed this way (by Index WP MySQL
 * For Speed) are adopted as they are.
 *
 * Updates: dbdelta_queries gets this plugin's keys for the tables it keeps,
 * so dbDelta() adds no standard key back and logs no duplicate-key errors,
 * while new columns and new keys still pass. A must-use file adds the
 * filter to wp-admin/upgrade.php, where plugins do not load (core updates
 * upgrade the database there). After updates, and daily, a check puts back
 * anything that changed. Switching off or deleting SEO Pro Stack puts
 * WordPress's standard keys back on the tables it kept.
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

class SEOProStack_Fast_Keys extends SEOProStack_Feature {

    const KEY = 'fast_keys';

    /** State: tables kept, last result per table, log. Not autoloaded. */
    const STATE = 'seoprostack_fast_keys';

    /** Daily background check. */
    const HOOK = 'seoprostack_fast_keys';

    /** One check soon: after updates, setting changes and Check now. */
    const SOON = 'seoprostack_fast_keys_soon';

    /** Add database keys' key on meta tables, which the target keys cover. */
    const ADDED_KEY = 'sps_meta_key_value';

    /** Check now (admin-post action and nonce). */
    const ACTION = 'seoprostack_fast_keys_check';

    /** Must-use file that adds the dbDelta filter to upgrade.php. */
    const FILE = 'seoprostack-fast-keys.php';
    const MARKER = 'SEO Pro Stack faster database keys';

    /** Rows above which a table is left to WP-CLI. */
    const LARGE = 1000000;

    /** Seconds a background run may start new tables for. */
    const BUDGET = 20;

    /** Seconds before a change that failed is tried again. */
    const RETRY = WEEK_IN_SECONDS;

    /** Log entries kept. */
    const LOG_MAX = 30;

    /** The plugin this replaces. */
    const REPLACES = array('index-wp-mysql-for-speed' => 'Index WP MySQL For Speed');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        // Single sites only: on a network every site has its own tables.
        if (is_multisite()) {
            return array();
        }
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'server',
                'label'       => __('Faster database keys', 'seoprostack'),
                'description' => __('Give WordPress’s posts, custom fields, comments, settings and people tables (and WooCommerce’s order details) the database keys Index WP MySQL For Speed adds, in the background, and keep them after WordPress and plugin updates. Other plugins’ keys stay. The tables stay usable while it works. Turning this off puts WordPress’s own keys back. Single sites only.', 'seoprostack'),
                'replaces'    => self::REPLACES,
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (is_multisite()) {
            return;
        }
        self::early();
        add_action(self::HOOK, array(__CLASS__, 'run_scheduled'));
        add_action(self::SOON, array(__CLASS__, 'run_scheduled'));
        add_action('upgrader_process_complete', array(__CLASS__, 'soon'), 10, 0);
        add_action('activated_plugin', array(__CLASS__, 'soon'), 10, 0);
        add_action('deactivated_plugin', array(__CLASS__, 'plugin_deactivated'), 10, 1);
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        register_deactivation_hook(SEOPROSTACK_FILE, array(__CLASS__, 'deactivate'));
        if (is_admin()) {
            add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'extras'), 10, 2);
        }
        if (is_admin() && !wp_doing_ajax()) {
            add_action('admin_init', array(__CLASS__, 'sync_schedule'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'check_now'));
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack fast-keys', array(__CLASS__, 'cli'));
        }
    }

    /**
     * What Index WP MySQL For Speed has that this does not: its query
     * monitor, named when monitor logs are saved (deleting it removes them).
     *
     * @param string[] $extras Plain names.
     * @param string   $slug   Plugin folder.
     * @return string[]
     */
    public static function extras($extras, $slug) {
        global $wpdb;
        if (!isset(self::REPLACES[$slug])) {
            return $extras;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed lookup on the Plugins screen.
        $saved = $wpdb->get_var($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like('imfsQueryMonitor-Log-') . '%'));
        if ($saved) {
            $extras[] = __('its query monitor, with logs saved here that deleting it removes', 'seoprostack');
        }
        return $extras;
    }

    /**
     * Hooks that must also run where plugins do not load (the must-use
     * file calls this in wp-admin/upgrade.php).
     */
    public static function early() {
        add_filter('dbdelta_queries', array(__CLASS__, 'dbdelta'), 20);
        add_action('wp_upgrade', array(__CLASS__, 'soon'), 10, 0);
    }

    /* --------------------------------------------------------------------- */
    /* Keys                                                                   */
    /* --------------------------------------------------------------------- */

    /**
     * The target keys per table (without prefix): name => columns, with
     * prefix lengths (0 for the whole column). 'PRIMARY' is the primary key;
     * names in $unique are unique. From Index WP MySQL For Speed 1.6
     * (getHighPerformanceIndexes1_6, Barracuda), so its keys are adopted.
     *
     * @return array<string,array{keys:array<string,array<string,int>>,unique:string[]}>
     */
    public static function targets() {
        // phpcs:disable WordPress.DB.SlowDBQuery -- key definitions (column names), not queries.
        $meta = function ($id, $owner) {
            return array(
                'keys'   => array(
                    $id          => array($id => 0),
                    'PRIMARY'    => array($owner => 0, 'meta_key' => 0, $id => 0),
                    'meta_key'   => array('meta_key' => 0, 'meta_value' => 32, $owner => 0, $id => 0),
                    'meta_value' => array('meta_value' => 32, $id => 0),
                ),
                'unique' => array($id),
            );
        };
        $targets = array(
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
            'postmeta'                   => $meta('meta_id', 'post_id'),
            'usermeta'                   => $meta('umeta_id', 'user_id'),
            'termmeta'                   => $meta('meta_id', 'term_id'),
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
            'woocommerce_order_itemmeta' => $meta('meta_id', 'order_item_id'),
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
        return $targets;
    }

    /**
     * WooCommerce's own keys for its tables (WC_Install and
     * OrdersTableDataStore), which are not in WordPress's schema.
     *
     * @return array<string,array{keys:array<string,array<string,int>>,unique:string[]}>
     */
    private static function woo_standard() {
        // phpcs:disable WordPress.DB.SlowDBQuery -- key definitions (column names), not queries.
        $keys = array(
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
        return $keys;
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
     * Keys in the body of a CREATE TABLE statement.
     *
     * @param string $body Lines between the brackets.
     * @return array{keys:array<string,array<string,int>>,unique:string[]}
     */
    private static function parse_keys($body) {
        $keys   = array();
        $unique = array();
        foreach (explode("\n", $body) as $line) {
            $line = trim($line, " \t\r\0\x0B,");
            if (!preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)|KEY|INDEX)\s*`?([0-9A-Za-z_$-]*)`?\s*\((.+)\)$/i', $line, $match)) {
                continue;
            }
            $primary = 0 === stripos($match[1], 'PRIMARY');
            $name    = $primary ? 'PRIMARY' : $match[2];
            $columns = array();
            foreach (explode(',', $match[3]) as $column) {
                if (preg_match('/^\s*`?([0-9A-Za-z_$-]+)`?\s*(?:\(\s*(\d+)\s*\))?/', $column, $part)) {
                    $columns[$part[1]] = isset($part[2]) ? (int) $part[2] : 0;
                }
            }
            if ('' === $name || !$columns) {
                continue;
            }
            $keys[$name] = $columns;
            if (!$primary && 0 === stripos($match[1], 'UNIQUE')) {
                $unique[] = $name;
            }
        }
        return array('keys' => $keys, 'unique' => $unique);
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

    /**
     * A table as it is: engine, row format, rows, columns (name => nullable)
     * and keys (name => columns), or null when there is no such table.
     *
     * @param string $suffix Table without prefix.
     * @return array|null|WP_Error
     */
    public static function inspect($suffix) {
        global $wpdb;
        $table = self::table($suffix);
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
     * The changes that give a table a key set: drops first, then unique
     * keys, the primary key and the rest. Only keys named in either set are
     * touched; SEO Pro Stack's own Add database keys key on meta tables goes
     * when the target set covers it.
     *
     * @param array $current inspect() result.
     * @param array $want    Key set to reach.
     * @param array $other   The other set (its names are managed too).
     * @return string[] ALTER TABLE clauses.
     */
    public static function plan(array $current, array $want, array $other) {
        $existing = array();
        foreach ($current['keys'] as $name => $columns) {
            $existing[strtolower($name)] = array('name' => $name, 'columns' => $columns, 'unique' => in_array($name, $current['unique'], true));
        }
        $drops = array();
        $adds  = array();
        foreach ($want['keys'] as $name => $columns) {
            $lower  = strtolower($name);
            $unique = in_array($name, $want['unique'], true);
            if (isset($existing[$lower])) {
                if (self::same($existing[$lower]['columns'], $columns) && $existing[$lower]['unique'] === $unique) {
                    continue;
                }
                $drops[] = self::drop($existing[$lower]['name']);
            }
            $adds[('PRIMARY' === $name ? 1 : ($unique ? 0 : 2)) . $lower] = self::add($name, $columns, $unique);
        }
        $managed = array_diff(self::names($other['keys']), self::names($want['keys']));
        if (isset($want['keys']['meta_value'], $want['keys']['meta_key'])) {
            $managed[] = self::ADDED_KEY;
        }
        foreach ($managed as $lower) {
            if (isset($existing[$lower])) {
                $drops[] = self::drop($existing[$lower]['name']);
            }
        }
        ksort($adds);
        return array_merge($drops, array_values($adds));
    }

    /**
     * Lowercase names of a key set's keys (or columns).
     *
     * @param array $keys Name => anything.
     * @return string[]
     */
    private static function names(array $keys) {
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
            if (in_array(strtolower((string) $name), $names, true)) {
                continue;
            }
            $is_unique = 'PRIMARY' === $name || in_array($name, $base['unique'], true);
            $covered   = false;
            foreach ($target['keys'] as $other => $other_columns) {
                $other_unique = 'PRIMARY' === $other || in_array($other, $target['unique'], true);
                if ($is_unique && (!$other_unique || count($other_columns) !== count($columns))) {
                    continue;
                }
                if (self::covers($other_columns, $columns)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $keys[$name] = $columns;
                if (in_array($name, $base['unique'], true)) {
                    $unique[] = $name;
                }
            }
        }
        return array('keys' => $keys, 'unique' => $unique);
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
     * Same columns and prefix lengths. A prefix as long as the column reads
     * as the whole column.
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

    private static function quote($name) {
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

    /* --------------------------------------------------------------------- */
    /* Running                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Stored state.
     *
     * @return array{owned:array<string,int>,tables:array<string,array>,log:array}
     */
    public static function state() {
        $state = get_option(self::STATE, array());
        $state = is_array($state) ? $state : array();
        return array(
            'owned'  => isset($state['owned']) && is_array($state['owned']) ? $state['owned'] : array(),
            'tables' => isset($state['tables']) && is_array($state['tables']) ? $state['tables'] : array(),
            'log'    => isset($state['log']) && is_array($state['log']) ? $state['log'] : array(),
        );
    }

    private static function save(array $state) {
        $state['log'] = array_slice($state['log'], -self::LOG_MAX);
        update_option(self::STATE, $state, false);
    }

    /**
     * Check soon, in the background (after updates and setting changes).
     */
    public static function soon() {
        if (!wp_next_scheduled(self::SOON)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SOON);
        }
    }

    /**
     * A plugin was deactivated: when it is the one this replaces, its keys
     * can be adopted now.
     *
     * @param string $plugin Plugin file.
     */
    public static function plugin_deactivated($plugin) {
        if (isset(self::REPLACES[dirname((string) $plugin)])) {
            self::soon();
        }
    }

    /**
     * Keep the daily check, the soon check's handler and the must-use file
     * in step with the switch.
     */
    public static function sync_schedule() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $on        = self::switched_on();
        $scheduled = wp_next_scheduled(self::HOOK);
        if ($on && !$scheduled) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'daily', self::HOOK);
            self::soon();
            self::sync_file(true);
        } elseif (!$on && $scheduled) {
            // Put the standard keys back, then stop.
            wp_clear_scheduled_hook(self::HOOK);
            self::soon();
        }
    }

    /**
     * The switch changed.
     *
     * @param array $old Old settings.
     * @param array $new New settings.
     */
    public static function settings_changed($old, $new) {
        if (empty($old[self::KEY]) !== empty($new[self::KEY])) {
            self::soon();
        }
    }

    /**
     * SEO Pro Stack is deactivated: remove the must-use file (no code of a
     * deactivated plugin runs). The keys stay until it is deleted.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::SOON);
        self::sync_file(false);
    }

    /**
     * Background run: daily, or soon after a change.
     */
    public static function run_scheduled() {
        self::run(false);
    }

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
        $undo = 'undo' === $force || (null === $force && !self::switched_on());
        if (!$undo && !self::enabled()) {
            return array(); // Waiting for Index WP MySQL For Speed to be deactivated.
        }
        $lock = 'sps_fast_keys_' . substr(hash('sha256', DB_NAME . $wpdb->prefix), 0, 30); // NOSONAR: a lock name, not security.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return array();
        }
        $results = array();
        $state   = self::state();
        try {
            $targets  = self::targets();
            $standard = self::standard();
            $start    = microtime(true);
            foreach ($targets as $suffix => $target) {
                if ($undo && !isset($state['owned'][$suffix])) {
                    continue;
                }
                if (!$cli && !self::more_time($start, self::BUDGET)) {
                    self::soon();
                    break;
                }
                $base = isset($standard[$suffix]) ? $standard[$suffix] : array('keys' => array(), 'unique' => array());
                if (!$base['keys'] && $undo) {
                    continue;
                }
                $fast             = self::combined($target, $base);
                $result           = self::table_run($suffix, $undo ? $base : $fast, $undo ? $fast : $base, $undo, $cli, $state);
                $results[$suffix] = $result;
                unset($result['sql']);
                $state['tables'][$suffix] = $result;
                $result                   = $results[$suffix];
                if ('missing' === $result['status'] || ($undo && 'done' === $result['status'])) {
                    unset($state['owned'][$suffix]);
                } elseif ('done' === $result['status'] && !isset($state['owned'][$suffix])) {
                    $state['owned'][$suffix] = time();
                }
                if (!empty($result['sql'])) {
                    $state['log'][] = array('time' => time(), 'table' => self::table($suffix), 'action' => $undo ? 'undo' : 'apply', 'result' => 'done' === $result['status'] ? 'done' : 'failed: ' . $result['note']);
                }
            }
            if ($undo) {
                $state['tables'] = array_intersect_key($state['tables'], $state['owned']);
            }
            self::save($state);
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release our connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        if (!$cli) {
            self::sync_file(!$undo || !empty($state['owned']));
        }
        return $results;
    }

    /**
     * One table: reach $want from whatever is there.
     *
     * @param string $suffix Table without prefix.
     * @param array  $want   Key set to reach.
     * @param array  $other  The other key set.
     * @param bool   $undo   Restoring WordPress's keys.
     * @param bool   $cli    From WP-CLI.
     * @param array  $state  Stored state.
     * @return array{status:string,note:string,plan:string,time:int,sql?:string}
     */
    private static function table_run($suffix, array $want, array $other, $undo, $cli, array $state) {
        global $wpdb;
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
        $plan           = self::plan($current, $want, $other);
        if (!$plan) {
            return $result;
        }
        $hash           = md5(implode(';', $plan));
        $result['plan'] = $hash;
        $skip           = self::skip($current, $want, $undo, $cli);
        if ('' !== $skip) {
            return array_merge($result, array('status' => 'skipped', 'note' => $skip));
        }
        $last = isset($state['tables'][$suffix]) ? $state['tables'][$suffix] : array();
        if (!$cli && isset($last['status'], $last['plan'], $last['time']) && 'failed' === $last['status'] && $hash === $last['plan'] && time() - (int) $last['time'] < self::RETRY) {
            return $last;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions -- online DDL; hosts may disable it.
        }
        $previous = $wpdb->suppress_errors(true);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- DDL built from fixed key lists; identifiers quoted.
        $session = $wpdb->get_row('SELECT @@SESSION.sql_mode AS mode, @@SESSION.lock_wait_timeout AS wait', ARRAY_A);
        // A short wait for the table's metadata lock, so a long query
        // elsewhere makes this fail instead of queueing every request behind it.
        $wpdb->query('SET SESSION lock_wait_timeout = 10');
        // Strict mode lets MySQL make a new primary key column NOT NULL in place.
        $wpdb->query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')");
        $statement = $wpdb->get_var('SELECT @@SESSION.max_statement_time');
        if (null !== $statement && (float) $statement > 0) {
            $wpdb->query('SET SESSION max_statement_time = 0');
        }
        $sql   = 'ALTER TABLE ' . self::quote($current['table']) . ' ' . implode(', ', $plan) . ', ALGORITHM=INPLACE, LOCK=NONE';
        $ok    = $wpdb->query($sql);
        $error = $wpdb->last_error;
        if (false !== $ok) {
            $wpdb->query('ANALYZE TABLE ' . self::quote($current['table']));
        }
        if (is_array($session)) {
            $wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s, lock_wait_timeout = %d', $session['mode'], (int) $session['wait']));
        }
        if (null !== $statement && (float) $statement > 0) {
            $wpdb->query($wpdb->prepare('SET SESSION max_statement_time = %f', (float) $statement));
        }
        // phpcs:enable
        $wpdb->suppress_errors($previous);
        $result['sql'] = $sql;
        if (false === $ok) {
            $result['status'] = 'failed';
            $result['note']   = substr((string) $error, 0, 200);
        }
        return $result;
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
        global $wpdb;
        if ('innodb' !== $current['engine']) {
            return __('The table is not InnoDB.', 'seoprostack');
        }
        if (!$undo && !in_array($current['format'], array('dynamic', 'compressed'), true)) {
            return __('The table’s row format is too old for long keys (it needs DYNAMIC).', 'seoprostack');
        }
        foreach ($want['keys'] as $columns) {
            foreach (self::names($columns) as $column) {
                if (!isset($current['columns'][$column])) {
                    /* translators: %s: column name */
                    return sprintf(__('The table has no %s column.', 'seoprostack'), $column);
                }
            }
        }
        if (!$undo && isset($want['keys']['PRIMARY'])) {
            foreach (self::names($want['keys']['PRIMARY']) as $column) {
                if (empty($current['columns'][$column])) {
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- one row; identifiers from fixed lists, quoted.
                if ($wpdb->get_var('SELECT 1 FROM ' . self::quote($current['table']) . ' WHERE ' . self::quote($column) . ' IS NULL LIMIT 1')) {
                    /* translators: %s: column name */
                    return sprintf(__('Some rows have no %s, so it cannot be part of the primary key. Nothing was changed.', 'seoprostack'), $column);
                }
            }
        }
        if (!$cli && $current['rows'] > self::LARGE) {
            return __('Large table: change it with WP-CLI (wp seoprostack fast-keys apply), as a web request may stop first.', 'seoprostack');
        }
        return '';
    }

    /* --------------------------------------------------------------------- */
    /* Updates                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * dbDelta(): for the tables this keeps, swap the key lines WordPress or
     * WooCommerce would compare for this plugin's keys, so no standard key
     * is added back and no duplicate-key error is logged. Other lines,
     * including new columns and new keys, pass unchanged; primary key
     * columns stay NOT NULL if a column type changes.
     *
     * @param mixed $queries Queries.
     * @return mixed
     */
    public static function dbdelta($queries) {
        if (!is_array($queries) || is_multisite()) {
            return $queries;
        }
        $owned = self::state()['owned'];
        if (!$owned) {
            return $queries;
        }
        $targets = self::targets();
        $tables  = array();
        foreach (array_keys($owned) as $suffix) {
            if (isset($targets[$suffix])) {
                $tables[strtolower(self::table($suffix))] = $suffix;
            }
        }
        foreach ($queries as $i => $query) {
            if (!is_string($query) || !preg_match('/^\s*CREATE TABLE\s+`?([^\s`(]+)`?\s*\((.*)\)([^)]*)$/is', $query, $match)) {
                continue;
            }
            $name = strtolower($match[1]);
            if (!isset($tables[$name])) {
                continue;
            }
            $queries[$i] = self::rewrite($query, $match, $targets[$tables[$name]], self::standard_for($tables[$name]));
        }
        return $queries;
    }

    private static function standard_for($suffix) {
        $standard = self::standard();
        return isset($standard[$suffix]) ? $standard[$suffix] : array('keys' => array(), 'unique' => array());
    }

    /**
     * One CREATE TABLE statement with the target keys.
     *
     * @param string $query  Statement.
     * @param array  $match  Its parts: 1 table, 2 body, 3 after the brackets.
     * @param array  $target Target keys.
     * @param array  $base   Standard keys.
     * @return string
     */
    private static function rewrite($query, array $match, array $target, array $base) {
        $target  = self::combined($target, $base);
        $managed = array_merge(self::names($target['keys']), self::names($base['keys']));
        $primary = isset($target['keys']['PRIMARY']) ? self::names($target['keys']['PRIMARY']) : array();
        $lines   = array();
        foreach (explode("\n", $match[2]) as $line) {
            $line = trim($line, " \t\r\0\x0B,");
            if ('' === $line) {
                continue;
            }
            if (preg_match('/^(PRIMARY\s+KEY|(?:UNIQUE|FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)|KEY|INDEX)\s*`?([0-9A-Za-z_$-]*)`?\s*\(/i', $line, $key)) {
                $name = 0 === stripos($key[1], 'PRIMARY') ? 'primary' : strtolower($key[2]);
                if (!in_array($name, $managed, true)) {
                    $lines[] = $line;
                }
                continue;
            }
            $column = strtolower(trim((string) strtok($line, " \t"), '`'));
            if (in_array($column, $primary, true)) {
                // The primary key is listed below; its columns cannot be NULL.
                $line = (string) preg_replace('/\s+PRIMARY\s+KEY\b/i', '', $line);
                if (!preg_match('/\bNOT\s+NULL\b/i', $line)) {
                    $line = (string) preg_replace('/\s+(?:DEFAULT\s+NULL|NULL)\b/i', '', $line) . ' NOT NULL';
                }
            }
            $lines[] = $line;
        }
        foreach ($target['keys'] as $name => $columns) {
            $list = array();
            foreach ($columns as $column => $length) {
                $list[] = $column . ($length ? '(' . (int) $length . ')' : '');
            }
            $unique  = in_array($name, $target['unique'], true);
            $lines[] = ('PRIMARY' === $name ? 'PRIMARY KEY ' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . $name . ' ') . '(' . implode(',', $list) . ')';
        }
        $head = substr($query, 0, strpos($query, '(') + 1);
        return $head . "\n" . implode(",\n", $lines) . "\n)" . $match[3];
    }

    /**
     * Install, update or remove the must-use file. Only our own marked file
     * is ever written or removed.
     *
     * @param bool $on Whether it should be there.
     */
    public static function sync_file($on) {
        // Uninstall removes it without loading the main plugin file.
        if (!defined('WPMU_PLUGIN_DIR') || ($on && !defined('SEOPROSTACK_FILE'))) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $fs   = new WP_Filesystem_Direct(null);
        $path = WPMU_PLUGIN_DIR . '/' . self::FILE;
        $old  = is_file($path) ? $fs->get_contents($path) : false;
        if ($old && false === strpos($old, self::MARKER)) {
            return;
        }
        if (!$on) {
            if ($old) {
                $fs->delete($path);
            }
            return;
        }
        $base = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(SEOPROSTACK_FILE)) . '/includes/';
        $code = "<?php\n// " . self::MARKER . ": keeps its keys when wp-admin/upgrade.php updates the database (plugins do not load there).\n"
            . 'if (defined(\'ABSPATH\') && !is_multisite() && is_file(' . var_export($base . 'features/class-seoprostack-fast-keys.php', true) . ")) {\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . '    require_once ' . var_export($base . 'class-seoprostack-feature.php', true) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . '    require_once ' . var_export($base . 'features/class-seoprostack-fast-keys.php', true) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . "    SEOProStack_Fast_Keys::early();\n}\n";
        if ($old !== $code && wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            $fs->put_contents($path, $code, 0644);
        }
    }

    /**
     * Put WordPress's keys back and remove the file (uninstall).
     */
    public static function remove_all() {
        self::run(true, 'undo');
        self::sync_file(false);
        delete_option(self::STATE);
    }

    /**
     * Whether SEO Pro Stack keeps a key on a table (Database keys names it).
     *
     * @param string $table Full table name.
     * @param string $name  Key name.
     * @return bool
     */
    public static function keeps($table, $name) {
        static $owned = null;
        if (is_multisite()) {
            return false;
        }
        if (null === $owned) {
            $owned = self::state()['owned'];
        }
        foreach (self::targets() as $suffix => $target) {
            if (isset($owned[$suffix]) && self::table($suffix) === $table) {
                return in_array(strtolower((string) $name), array_map('strtolower', array_keys($target['keys'])), true);
            }
        }
        return false;
    }

    /* --------------------------------------------------------------------- */
    /* Admin                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Check now: run in the background straight away.
     */
    public static function check_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        wp_clear_scheduled_hook(self::SOON);
        wp_schedule_single_event(time(), self::SOON);
        spawn_cron();
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('options-general.php?page=seoprostack&tab=server'));
        exit;
    }

    /**
     * Each table's keys, in the settings panel.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel($key, $field = array()) {
        if (self::KEY !== $key || !SEOProStack_Settings::can_change()) {
            return;
        }
        // From the last background check only: no table metadata is read here.
        $state   = self::state();
        $on      = self::switched_on();
        $waiting = self::replaced_active(self::KEY);
        $lines   = array();
        foreach (array_keys(self::targets()) as $suffix) {
            $last  = isset($state['tables'][$suffix]) ? $state['tables'][$suffix] : array();
            $kept  = isset($state['owned'][$suffix]);
            $done  = isset($last['status']) && 'done' === $last['status'];
            $known = isset($last['status']) && in_array($last['status'], array('skipped', 'failed'), true) && '' !== (string) $last['note'];
            if (isset($last['status']) && 'missing' === $last['status']) {
                continue;
            }
            if ($kept && $on && ($done || !$known)) {
                $text = $done ? __('Faster keys in place.', 'seoprostack') : __('Faster keys in place; an update changed some, and the next background check puts them back.', 'seoprostack');
            } elseif ($kept && !$on) {
                $text = __('Faster keys in place; WordPress’s keys go back at the next background check.', 'seoprostack');
            } elseif ($known) {
                /* translators: %s: reason */
                $text = sprintf(__('Not changed: %s', 'seoprostack'), $last['note']);
            } elseif ($on && !$waiting) {
                $text = __('Waiting for the background check.', 'seoprostack');
            } else {
                continue;
            }
            $table = self::table($suffix);
            if (isset($last['rows'])) {
                /* translators: 1: table, 2: number of rows, 3: state */
                $lines[] = sprintf(__('%1$s (%2$s rows): %3$s', 'seoprostack'), $table, number_format_i18n((int) $last['rows']), $text);
            } else {
                /* translators: 1: table, 2: state */
                $lines[] = sprintf(__('%1$s: %2$s', 'seoprostack'), $table, $text);
            }
        }
        if (!$lines && !$state['log']) {
            return;
        }
        echo '<div class="sps-panel-note"><ul class="ul-disc">';
        foreach ($lines as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul>';
        if ($state['log']) {
            $last = end($state['log']);
            /* translators: 1: date and time, 2: table, 3: result */
            echo '<p>' . esc_html(sprintf(__('Last change %1$s: %2$s, %3$s.', 'seoprostack'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['time']), $last['table'], $last['result'])) . '</p>';
        }
        echo '<p><a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION)) . '">' . esc_html__('Check now', 'seoprostack') . '</a> ';
        echo esc_html__('WP-CLI: wp seoprostack fast-keys status, apply (also large tables) or undo (when switched off).', 'seoprostack') . '</p></div>';
    }

    /**
     * Shows, applies or undoes faster database keys.
     *
     * ## OPTIONS
     *
     * [<command>]
     * : status (default), apply or undo.
     *
     * @param array $args Positional arguments.
     */
    public static function cli($args) {
        $command = isset($args[0]) ? (string) $args[0] : 'status';
        if ('apply' === $command) {
            if (!self::enabled()) {
                WP_CLI::error('Turn on Faster database keys (Server tab) and deactivate Index WP MySQL For Speed first.');
            }
            $results = self::run(true, 'apply');
        } elseif ('undo' === $command) {
            if (self::switched_on()) {
                WP_CLI::error('Turn off Faster database keys (Server tab) first, or it adds them again.');
            }
            $results = self::run(true, 'undo');
        } elseif ('status' === $command) {
            $results = array();
            $state   = self::state();
            $standard = self::standard();
            foreach (self::targets() as $suffix => $target) {
                $current = self::inspect($suffix);
                if (null === $current || is_wp_error($current)) {
                    continue;
                }
                $base             = isset($standard[$suffix]) ? $standard[$suffix] : array('keys' => array(), 'unique' => array());
                $plan             = self::plan($current, self::combined($target, $base), $base);
                $last             = isset($state['tables'][$suffix]) ? $state['tables'][$suffix] : array();
                $results[$suffix] = array(
                    'status' => $plan ? 'to change' : 'done',
                    'note'   => $plan ? implode(', ', $plan) . (empty($last['note']) ? '' : ' (' . $last['note'] . ')') : (isset($state['owned'][$suffix]) ? 'kept' : 'not kept'),
                );
            }
        } else {
            WP_CLI::error('Usage: wp seoprostack fast-keys [status|apply|undo]');
            return;
        }
        $items = array();
        foreach ($results as $suffix => $result) {
            $items[] = array('table' => self::table($suffix), 'status' => $result['status'], 'note' => $result['note']);
        }
        WP_CLI\Utils\format_items('table', $items, array('table', 'status', 'note'));
    }
}
