<?php
/**
 * Add database keys: a small set of indexes on WordPress's tables that
 * speed up common slow lookups, each added only when someone asks, never
 * when an existing key already covers it, and removed on request or on
 * uninstall.
 *
 * Keys are added online (ALGORITHM=INPLACE, LOCK=NONE); if the database
 * refuses, nothing changes. Only keys named with PREFIX are ever removed,
 * and Database keys never offers them. Each change is logged in OPTION
 * (not autoloaded).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Added_Keys extends SEOProStack_Feature {

    const KEY    = 'added_keys';
    const OPTION = 'seoprostack_added_keys';
    const PAGE   = 'seoprostack-add-keys';
    const ACTION = 'seoprostack_add_keys';

    /** Names of the keys SEO Pro Stack adds start with this. */
    const PREFIX = 'sps_';

    /** Rows above which adding is left to WP-CLI: a web request may time out. */
    const LARGE = 1000000;

    /** Log entries kept. */
    const LOG_MAX = 50;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'server',
                'label'       => __('Add database keys', 'seoprostack'),
                'description' => __('Offers a few database indexes that speed up finding posts and people by a custom field’s value, under Tools → Add database keys. Each is added only when you click, never when the table already has a key that covers it, and removed when you ask or delete SEO Pro Stack. Back up the database first.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack keys', array(__CLASS__, 'cli'));
        }
        if (!is_admin() || !self::enabled()) {
            return;
        }
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    /**
     * Add the Tools page.
     */
    public static function menu() {
        if (self::allowed()) {
            add_management_page(__('Add database keys', 'seoprostack'), __('Add database keys', 'seoprostack'), 'manage_options', self::PAGE, array(__CLASS__, 'page'));
        }
    }

    /**
     * Whether this person may change the site's tables: administrators of a
     * single site, super admins on multisite.
     *
     * @return bool
     */
    private static function allowed() {
        return self::enabled() && (is_multisite() ? is_super_admin() : current_user_can('manage_options'));
    }

    /**
     * The keys offered: ID => table (without prefix), key name, columns
     * (column => prefix length, 0 for the whole column), what it speeds up.
     * The usermeta key is for single sites: on multisite the table is shared.
     *
     * @return array<string,array{table:string,name:string,columns:array<string,int>,why:string}>
     */
    public static function offered() {
        $keys = array(
            'postmeta' => array(
                'table'   => 'postmeta',
                'name'    => 'sps_meta_key_value',
                'columns' => array('meta_key' => 191, 'meta_value' => 32), // phpcs:ignore WordPress.DB.SlowDBQuery -- index column names, not a query.
                'why'     => __('Finding posts by a custom field’s value: WooCommerce, ACF, membership, directory and event plugins, and any meta query. WordPress’s own key covers only the field name.', 'seoprostack'),
            ),
            'usermeta' => array(
                'table'   => 'usermeta',
                'name'    => 'sps_meta_key_value',
                'columns' => array('meta_key' => 191, 'meta_value' => 32), // phpcs:ignore WordPress.DB.SlowDBQuery -- index column names, not a query.
                'why'     => __('Finding people by a profile field’s value: membership, LMS and shop customer lists.', 'seoprostack'),
            ),
            'actionscheduler' => array(
                'table'   => 'actionscheduler_actions',
                'name'    => 'sps_status_scheduled',
                'columns' => array('status' => 0, 'scheduled_date_gmt' => 0),
                'why'     => __('Finding the next scheduled tasks (Action Scheduler, used by WooCommerce and many plugins). Its newer versions have this key already.', 'seoprostack'),
            ),
        );
        if (is_multisite()) {
            unset($keys['usermeta']);
        }
        return $keys;
    }

    /**
     * Full table name of an offered key.
     *
     * @param array $key Offered key.
     * @return string
     */
    private static function table(array $key) {
        global $wpdb;
        return 'usermeta' === $key['table'] ? $wpdb->usermeta : $wpdb->prefix . $key['table'];
    }

    /**
     * Where an offered key stands: missing (no such table), added, covered
     * (by covered_by), can_add, or error.
     *
     * @param array $key Offered key.
     * @return array{state:string,covered_by:string,rows:int,size:int,table:string}
     */
    public static function state(array $key) {
        global $wpdb;
        $table = self::table($key);
        $state = array('state' => 'missing', 'covered_by' => '', 'rows' => 0, 'size' => 0, 'table' => $table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- on-demand metadata; nothing cached.
        $info = $wpdb->get_row($wpdb->prepare('SELECT TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS SIZE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table), ARRAY_A);
        if (!$info) {
            return $state;
        }
        $state['rows'] = (int) $info['TABLE_ROWS'];
        $state['size'] = (int) $info['SIZE'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- on-demand metadata; nothing cached.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', $table), ARRAY_A);
        if ($wpdb->last_error) {
            $state['state'] = 'error';
            return $state;
        }
        $existing = array();
        foreach ((array) $rows as $row) {
            $existing[(string) $row['INDEX_NAME']][] = $row;
        }
        if (isset($existing[$key['name']])) {
            $state['state'] = 'added';
            return $state;
        }
        $wanted = array();
        foreach ($key['columns'] as $column => $length) {
            $wanted[] = array('COLUMN_NAME' => $column, 'SUB_PART' => $length ? (string) $length : null, 'COLLATION' => 'A');
        }
        foreach ($existing as $name => $other) {
            if (SEOProStack_Database_Keys::covered($wanted, $other)) {
                $state['state']      = 'covered';
                $state['covered_by'] = (string) $name;
                return $state;
            }
        }
        $state['state'] = 'can_add';
        return $state;
    }

    /**
     * Add or remove an offered key, one change at a time across requests.
     *
     * @param string $id  Offered key ID.
     * @param bool   $add Add (true) or remove (false).
     * @return true|WP_Error
     */
    public static function change($id, $add) {
        global $wpdb;
        $offered = self::offered();
        if (!isset($offered[$id])) {
            return new WP_Error('unknown', __('There is no such key.', 'seoprostack'));
        }
        $key  = $offered[$id];
        $lock = 'sps_add_keys_' . substr(hash('sha256', DB_NAME), 0, 30);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock, not stored state.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return new WP_Error('busy', __('Another change to database keys is running. Nothing was changed.', 'seoprostack'));
        }
        try {
            $state = self::state($key);
            if ($add && 'can_add' !== $state['state']) {
                return new WP_Error('state', __('This key cannot be added now: the table changed, or a key already covers it. Look at the list again.', 'seoprostack'));
            }
            if (!$add && 'added' !== $state['state']) {
                return new WP_Error('state', __('This key is not there. Look at the list again.', 'seoprostack'));
            }
            $columns = array();
            foreach ($key['columns'] as $column => $length) {
                $columns[] = '`' . $column . '`' . ($length ? '(' . (int) $length . ')' : '');
            }
            // Adding a key to a large table takes a while; the database keeps the table usable meanwhile.
            if (function_exists('set_time_limit')) {
                set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- online DDL on a large table.
            }
            $previous = $wpdb->suppress_errors(true);
            $sql      = $add
                ? 'ALTER TABLE %i ADD INDEX %i (' . implode(', ', $columns) . '), ALGORITHM=INPLACE, LOCK=NONE'
                : 'ALTER TABLE %i DROP INDEX %i, ALGORITHM=INPLACE, LOCK=NONE';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- confirmed DDL; identifiers through %i (WordPress 6.2), columns from offered().
            $ok    = $wpdb->query($wpdb->prepare($sql, $state['table'], $key['name']));
            $error = $wpdb->last_error;
            $wpdb->suppress_errors($previous);
            self::log($state['table'], $key['name'], $add ? 'add' : 'remove', false === $ok ? 'failed: ' . substr($error, 0, 200) : 'done');
            if (false === $ok) {
                /* translators: %s: database error */
                return new WP_Error('ddl', sprintf(__('The database refused the change, so nothing changed: %s', 'seoprostack'), $error));
            }
            return true;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release our connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Log a change.
     *
     * @param string $table  Table.
     * @param string $name   Key.
     * @param string $action add or remove.
     * @param string $result done or failed: …
     */
    private static function log($table, $name, $action, $result) {
        $log   = get_option(self::OPTION, array());
        $log   = is_array($log) ? $log : array();
        $log[] = array('time' => time(), 'user' => get_current_user_id(), 'table' => $table, 'key' => $name, 'action' => $action, 'result' => $result);
        update_option(self::OPTION, array_slice($log, -self::LOG_MAX), false);
    }

    /**
     * Remove every key SEO Pro Stack added on this site (uninstall).
     */
    public static function remove_all() {
        foreach (self::offered() as $id => $key) {
            if ('added' === self::state($key)['state']) {
                self::change($id, false);
            }
        }
    }

    /**
     * Add or Remove.
     */
    public static function handle() {
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $id     = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        $add    = isset($_POST['do']) && 'add' === $_POST['do'];
        $result = self::change($id, $add);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', array('response' => 409, 'back_link' => true));
        }
        wp_safe_redirect(add_query_arg('sps_done', $add ? 'added' : 'removed', admin_url('tools.php?page=' . self::PAGE)));
        exit;
    }

    /**
     * The Tools page.
     */
    public static function page() {
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'));
        }
        $done    = isset($_GET['sps_done']) ? sanitize_key(wp_unslash($_GET['sps_done'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect.
        $notices = array(
            'added'   => __('Key added.', 'seoprostack'),
            'removed' => __('Key removed.', 'seoprostack'),
        );
        echo '<div class="wrap"><h1>' . esc_html__('Add database keys', 'seoprostack') . '</h1>';
        if (isset($notices[$done])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notices[$done]) . '</p></div>';
        }
        echo '<p>' . esc_html__('A database key (index) lets the database find rows without reading the whole table, at the cost of a little disk space and slightly slower writes. These are added only when you click, and never when the table already has a key that covers the same columns. Back up the database first. The table stays usable while a key is added; on a large table it can take minutes.', 'seoprostack') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach (array(__('Table / key', 'seoprostack'), __('Speeds up', 'seoprostack'), __('Table', 'seoprostack'), __('State', 'seoprostack'), __('Action', 'seoprostack')) as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (self::offered() as $id => $key) {
            self::row($id, $key, self::state($key));
        }
        echo '</tbody></table>';
        echo '<h2>' . esc_html__('Changes', 'seoprostack') . '</h2>';
        $log = array_reverse((array) get_option(self::OPTION, array()));
        if (!$log) {
            echo '<p>' . esc_html__('None yet.', 'seoprostack') . '</p>';
        }
        foreach ($log as $entry) {
            echo '<p>' . esc_html(wp_date('Y-m-d H:i', (int) $entry['time']) . ' / ' . $entry['action'] . ' / ' . $entry['table'] . ' / ' . $entry['key'] . ' / ' . $entry['result']) . '</p>';
        }
        echo '<p>' . esc_html__('WP-CLI: wp seoprostack keys list, wp seoprostack keys add <key>, wp seoprostack keys remove <key>. Deleting SEO Pro Stack removes the keys it added.', 'seoprostack') . '</p></div>';
    }

    /**
     * One row of the Tools page.
     *
     * @param string $id    Offered key ID.
     * @param array  $key   Offered key.
     * @param array  $state Its state.
     */
    private static function row($id, array $key, array $state) {
        $columns = array();
        foreach ($key['columns'] as $column => $length) {
            $columns[] = $column . ($length ? '(' . $length . ')' : '');
        }
        $labels = array(
            'missing' => __('This site has no such table.', 'seoprostack'),
            'error'   => __('The database did not allow reading the table’s keys.', 'seoprostack'),
            'added'   => __('Added', 'seoprostack'),
            /* translators: %s: name of the existing database key */
            'covered' => sprintf(__('Not needed: %s already covers it.', 'seoprostack'), $state['covered_by']),
            'can_add' => __('Can be added', 'seoprostack'),
        );
        /* translators: 1: number of rows; 2: table size */
        $table = 'missing' === $state['state'] ? '–' : sprintf(__('%1$s rows, %2$s', 'seoprostack'), number_format_i18n($state['rows']), size_format($state['size']));
        echo '<tr><td>' . esc_html($state['table'] . ' / ' . $key['name']) . '<br><code>' . esc_html(implode(', ', $columns)) . '</code></td>';
        echo '<td>' . esc_html($key['why']) . '</td><td>' . esc_html($table) . '</td><td>' . esc_html($labels[$state['state']]) . '</td><td>';
        if ('can_add' === $state['state'] && $state['rows'] > self::LARGE) {
            /* translators: %s: WP-CLI command */
            echo esc_html(sprintf(__('Large table: add it with WP-CLI, as a web request may time out: %s', 'seoprostack'), 'wp seoprostack keys add ' . $id));
        } elseif ('can_add' === $state['state'] || 'added' === $state['state']) {
            $add = 'can_add' === $state['state'];
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::ACTION);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="key" value="' . esc_attr($id) . '"><input type="hidden" name="do" value="' . ($add ? 'add' : 'remove') . '">';
            submit_button($add ? __('Add this key', 'seoprostack') : __('Remove', 'seoprostack'), $add ? 'primary small' : 'secondary small', 'submit', false);
            echo '</form>';
        }
        echo '</td></tr>';
    }

    /**
     * Lists, adds or removes the database keys SEO Pro Stack offers.
     *
     * ## OPTIONS
     *
     * <command>
     * : list, add or remove.
     *
     * [<key>]
     * : postmeta, usermeta (single sites) or actionscheduler.
     *
     * @param array $args Positional arguments.
     */
    public static function cli($args) {
        if (!self::enabled()) {
            WP_CLI::error('Turn on Add database keys (Server tab) first.');
        }
        $command = $args[0] ?? 'list';
        if ('list' === $command) {
            $items = array();
            foreach (self::offered() as $id => $key) {
                $state   = self::state($key);
                $items[] = array('key' => $id, 'table' => $state['table'], 'name' => $key['name'], 'rows' => $state['rows'], 'state' => $state['state'], 'covered_by' => $state['covered_by']);
            }
            WP_CLI\Utils\format_items('table', $items, array('key', 'table', 'name', 'rows', 'state', 'covered_by'));
            return;
        }
        if (!in_array($command, array('add', 'remove'), true) || empty($args[1])) {
            WP_CLI::error('Usage: wp seoprostack keys list|add <key>|remove <key>');
        }
        $result = self::change($args[1], 'add' === $command);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('add' === $command ? 'Key added.' : 'Key removed.');
    }
}
