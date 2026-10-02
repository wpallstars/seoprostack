<?php
/**
 * On-demand review of secondary indexes on this site's core tables.
 *
 * No scheduled scans or automatic DDL. A fresh survey is required for both
 * confirmation and removal. Restore statements are saved before DDL because
 * MySQL's ALTER TABLE cannot be rolled back with an option write.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Database_Keys extends SEOProStack_Feature {

    const KEY = 'database_keys';
    const LOG = 'seoprostack_database_keys_log';
    const PAGE = 'seoprostack-database-keys';
    const ACTION = 'seoprostack_database_keys';

    public static function settings() {
        return array(self::KEY => array(
            'type' => 'bool',
            'default' => false,
            'tab' => 'plugins',
            'label' => __('Database key cleanup', 'seoprostack'),
            'description' => __('Review leftover and duplicate database indexes under Tools → Database keys. Nothing is removed until you choose a key and confirm. Back up the database first.', 'seoprostack'),
        ));
    }

    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function menu() {
        add_management_page(__('Database keys', 'seoprostack'), __('Database keys', 'seoprostack'), 'manage_options', self::PAGE, array(__CLASS__, 'page'));
    }

    private static function allowed() {
        return self::enabled() && current_user_can('manage_options');
    }

    /** Quote only identifiers read from the allowlisted core tables. */
    private static function identifier($name) {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Read the running WordPress version's schema; fail closed on parse errors. */
    private static function core_keys() {
        require_once ABSPATH . 'wp-admin/includes/schema.php';
        $schema = wp_get_db_schema('all');
        preg_match_all('/CREATE TABLE\s+([^\s(]+)\s*\((.*?)^\)\s*[^;]*;/msi', $schema, $tables, PREG_SET_ORDER);
        $keys = array();
        foreach ($tables as $table) {
            $name = trim($table[1], '`');
            preg_match_all('/^\s*(?:UNIQUE\s+)?KEY\s+`?([^`\s(]+)`?\s*\(/mi', $table[2], $matches);
            $keys[$name] = array_merge(array('PRIMARY'), $matches[1]);
        }
        return $keys;
    }

    /**
     * Only ordinary, non-unique BTREE indexes are eligible. Do not interpret
     * expression, FULLTEXT, spatial, invisible or ignored indexes as duplicates.
     */
    private static function ordinary(array $rows, $allow_unique = false) {
        foreach ($rows as $row) {
            if (empty($row['COLUMN_NAME']) || 'BTREE' !== $row['INDEX_TYPE'] || (!$allow_unique && '1' !== (string) $row['NON_UNIQUE'])
                || !in_array($row['COLLATION'], array('A', 'D'), true) || !empty($row['INDEX_COMMENT'])
                || (isset($row['IS_VISIBLE']) && 'YES' !== $row['IS_VISIBLE'])
                || (isset($row['IGNORED']) && 'NO' !== $row['IGNORED'])) {
                return false;
            }
        }
        return true;
    }

    /** Same ordered columns, prefix lengths and directions, not just names. */
    private static function covered(array $key, array $other) {
        if (count($key) > count($other) || !self::ordinary($other, true)) {
            return false;
        }
        foreach ($key as $i => $row) {
            foreach (array('COLUMN_NAME', 'SUB_PART', 'COLLATION') as $field) {
                if ($row[$field] !== $other[$i][$field]) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function parts(array $rows) {
        $parts = array();
        foreach ($rows as $row) {
            if (null === $row['COLUMN_NAME']) {
                $parts[] = __('Unsupported expression', 'seoprostack');
                continue;
            }
            $part = self::identifier($row['COLUMN_NAME']);
            if (null !== $row['SUB_PART']) {
                $part .= '(' . (int) $row['SUB_PART'] . ')';
            }
            if ('D' === $row['COLLATION']) {
                $part .= ' DESC';
            }
            $parts[] = $part;
        }
        return implode(', ', $parts);
    }

    /**
     * Survey this site's tables plus global tables for super admins only.
     * Index sizes are optional InnoDB estimates, not table totals divided by keys.
     *
     * @return array|WP_Error
     */
    public static function survey() {
        global $wpdb;
        $core = self::core_keys();
        $tables = $wpdb->tables('blog', true);
        $global = array_merge($wpdb->tables('global', true), is_multisite() ? $wpdb->tables('ms_global', true) : array());
        if (!is_multisite() || is_super_admin()) {
            $tables = array_merge($tables, $global);
        }
        $tables = array_intersect(array_unique($tables), array_keys($core));
        if (!$tables) {
            return new WP_Error('schema', __('The core table schema could not be read. No keys can be removed.', 'seoprostack'));
        }
        $active = self::active_plugins();
        $result = array();
        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- on-demand metadata; nothing cached.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', $table), ARRAY_A);
            if ($wpdb->last_error) {
                return new WP_Error('metadata', __('The database did not allow reading index metadata. No keys can be removed.', 'seoprostack'));
            }
            // Preserve every index touching a foreign-key column (including referenced columns).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- metadata for constraints.
            $foreign = $wpdb->get_col($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL UNION SELECT REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s', $table, $table));
            if ($wpdb->last_error) {
                return new WP_Error('constraints', __('The database did not allow checking foreign keys. No keys can be removed.', 'seoprostack'));
            }
            $keys = array();
            foreach ($rows as $row) {
                $keys[$row['INDEX_NAME']][] = $row;
            }
            foreach ($keys as $name => $key) {
                $reason = '';
                $lower = strtolower($name);
                // Shared indexes may be owned by a plugin active on another site.
                $protected = (is_multisite() && in_array($table, $global, true))
                    || in_array($lower, array_map('strtolower', $core[$table]), true) || !self::ordinary($key)
                    || array_intersect(array_column($key, 'COLUMN_NAME'), $foreign);
                $known = 0 === strpos($lower, 'wpi_') || 0 === strpos($lower, 'spro_');
                if ($known && isset($active['scalability-pro'])) {
                    $protected = true;
                }
                // Index WP MySQL For Speed also uses core names and unique ID keys.
                if (0 === strpos($lower, 'index_wp_mysql_protect_') || (isset($active['index-wp-mysql-for-speed'])
                    && in_array($lower, array('meta_value', 'display_name', 'comment_date_gmt', 'comment_post_parent_approved'), true))) {
                    $protected = true;
                }
                // Ownership is unverified: do not infer inactivity from an invented slug.
                if (0 === strpos($lower, 'wdbi_')) {
                    $protected = true;
                }
                if (!$protected && $known) {
                    $reason = __('Leftover Scalability Pro key; that plugin is not active.', 'seoprostack');
                }
                if (!$protected && !$reason) {
                    foreach ($keys as $other_name => $other) {
                        if ($other_name !== $name && self::covered($key, $other)) {
                            /* translators: %s: covering database index name. */
                            $reason = sprintf(__('Redundant: its columns are a left prefix of %s.', 'seoprostack'), $other_name);
                            break;
                        }
                    }
                }
                $id = hash('sha256', $table . "\0" . $name);
                $restore = !$protected && $reason ? 'CREATE INDEX ' . self::identifier($name) . ' ON ' . self::identifier($table) . ' (' . self::parts($key) . ') USING BTREE;' : '';
                $result[$id] = array(
                    'table' => $table, 'name' => $name, 'columns' => self::parts($key),
                    'reason' => $reason ? $reason : ($protected ? __('Protected: core, constraint, shared table, active plugin or unsupported key.', 'seoprostack') : __('Not covered by another key.', 'seoprostack')),
                    'eligible' => !$protected && (bool) $reason,
                    'restore' => $restore,
                    'fingerprint' => hash('sha256', $restore),
                    'size' => self::size($table, $name),
                );
            }
        }
        return $result;
    }

    /** InnoDB statistics may be unavailable to the WordPress database user. */
    private static function size($table, $name) {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- optional estimate; no cache.
        $pages = $wpdb->get_var($wpdb->prepare("SELECT stat_value FROM mysql.innodb_index_stats WHERE database_name = DATABASE() AND table_name = %s AND index_name = %s AND stat_name = 'size'", $table, $name));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- server's InnoDB page size.
        $page_size = $wpdb->get_var('SELECT @@innodb_page_size');
        $wpdb->suppress_errors($previous);
        return null === $pages || !$page_size ? null : (float) $pages * (float) $page_size;
    }

    /** Serialize our DDL and log writes across requests; never wait for a lock. */
    private static function remove($id, $fingerprint) {
        global $wpdb;
        $lock = 'sps_keys_' . substr(hash('sha256', DB_NAME), 0, 40);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock, not stored state.
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ('1' !== (string) $acquired) {
            return new WP_Error('busy', __('Another cleanup is running, or the database cannot lock this operation. Nothing was removed.', 'seoprostack'));
        }
        try {
            return self::drop_confirmed($id, $fingerprint);
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release our connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Revalidate one confirmed key, write recovery evidence, then execute DDL. */
    private static function drop_confirmed($id, $fingerprint) {
        global $wpdb;
        $keys = self::survey();
        if (is_wp_error($keys)) {
            return $keys;
        }
        if (!isset($keys[$id]) || !$keys[$id]['eligible'] || !hash_equals($keys[$id]['fingerprint'], $fingerprint)) {
            return new WP_Error('changed', __('The key changed or is no longer eligible. Review the list again.', 'seoprostack'));
        }
        $key = $keys[$id];
        $log = get_option(self::LOG, array());
        $log = is_array($log) ? $log : array();
        $entry = array('time' => gmdate('c'), 'user' => get_current_user_id(), 'table' => $key['table'], 'key' => $key['name'], 'restore' => $key['restore'], 'status' => 'pending');
        $log[] = $entry;
        if (!update_option(self::LOG, $log, false)) {
            return new WP_Error('log', __('The restore statement could not be saved. Nothing was removed.', 'seoprostack'));
        }
        $previous = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- confirmed DDL; %i is available since WordPress 6.2.
        $ok = $wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX %i', $key['table'], $key['name']));
        $wpdb->suppress_errors($previous);
        $log[count($log) - 1]['status'] = false === $ok ? 'failed' : 'dropped';
        update_option(self::LOG, $log, false);
        return false === $ok ? new WP_Error('drop', __('The database refused to remove the key. The restore statement is kept in the log.', 'seoprostack')) : true;
    }

    /** POST-only two-step form; each step checks capability and nonce. */
    public static function handle() {
        if (!self::allowed() || !isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $id = isset($_POST['key']) && is_string($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        if (isset($_POST['confirm']) && 'yes' === $_POST['confirm']) {
            $fingerprint = isset($_POST['fingerprint']) && is_string($_POST['fingerprint']) ? sanitize_text_field(wp_unslash($_POST['fingerprint'])) : '';
            $result = self::remove($id, $fingerprint);
            if (is_wp_error($result)) {
                wp_die(esc_html($result->get_error_message()));
            }
            wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE));
            exit;
        }
        $keys = self::survey();
        if (is_wp_error($keys) || !isset($keys[$id]) || !$keys[$id]['eligible']) {
            wp_die(esc_html__('This key cannot be removed. Review the list again.', 'seoprostack'));
        }
        // Render through Tools so core has initialized its admin menu and header.
        $url = add_query_arg('review', $id, admin_url('tools.php?page=' . self::PAGE));
        wp_safe_redirect(add_query_arg('_wpnonce', wp_create_nonce(self::ACTION . '_review'), $url));
        exit;
    }

    private static function confirmation($id, array $key) {
        echo '<div class="wrap"><h1>' . esc_html__('Confirm key removal', 'seoprostack') . '</h1>';
        echo '<p>' . esc_html__('Back up your database first. This changes only the chosen index, not rows, but can slow queries and briefly block database writes. Run during a quiet period.', 'seoprostack') . '</p>';
        echo '<p><strong>' . esc_html($key['table'] . ' / ' . $key['name']) . '</strong>: ' . esc_html($key['columns']) . '</p><p>' . esc_html($key['reason']) . '</p>';
        echo '<p>' . esc_html__('Save this restore statement. Run it through your database tool if you need to put the key back:', 'seoprostack') . '</p><pre>' . esc_html($key['restore']) . '</pre>';
        self::form($id, $key['fingerprint']);
        echo '<p><a href="' . esc_url(admin_url('tools.php?page=' . self::PAGE)) . '">' . esc_html__('Cancel', 'seoprostack') . '</a></p></div>';
    }

    private static function form($id, $fingerprint = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="key" value="' . esc_attr($id) . '">';
        if ($fingerprint) {
            echo '<input type="hidden" name="fingerprint" value="' . esc_attr($fingerprint) . '"><input type="hidden" name="confirm" value="yes">';
        }
        submit_button($fingerprint ? __('Remove this key', 'seoprostack') : __('Review removal', 'seoprostack'), 'secondary', 'submit', false);
        echo '</form>';
    }

    public static function page() {
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'));
        }
        if (isset($_GET['review'])) {
            check_admin_referer(self::ACTION . '_review');
            $id = is_string($_GET['review']) ? sanitize_text_field(wp_unslash($_GET['review'])) : '';
            $keys = self::survey();
            if (is_wp_error($keys) || !isset($keys[$id]) || !$keys[$id]['eligible']) {
                wp_die(esc_html__('This key cannot be removed. Review the list again.', 'seoprostack'));
            }
            self::confirmation($id, $keys[$id]);
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Database keys', 'seoprostack') . '</h1><p>' . esc_html__('Only this site’s core tables are scanned. Shared multisite tables are shown only to super admins. Removing indexes does not remove rows. Back up first; each removal needs confirmation. Estimated sizes may be unavailable or out of date.', 'seoprostack') . '</p>';
        $keys = self::survey();
        if (is_wp_error($keys)) {
            echo '<p>' . esc_html($keys->get_error_message()) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach (array(__('Table / key', 'seoprostack'), __('Columns', 'seoprostack'), __('Why', 'seoprostack'), __('Estimated size', 'seoprostack'), __('Action', 'seoprostack')) as $label) {
                echo '<th scope="col">' . esc_html($label) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($keys as $id => $key) {
                echo '<tr><td>' . esc_html($key['table'] . ' / ' . $key['name']) . '</td><td>' . esc_html($key['columns']) . '</td><td>' . esc_html($key['reason']) . '</td><td>' . esc_html(null === $key['size'] ? __('Unavailable', 'seoprostack') : size_format($key['size'])) . '</td><td>';
                if ($key['eligible']) {
                    self::form($id);
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>' . esc_html__('Removal log and restore statements', 'seoprostack') . '</h2><p>' . esc_html__('Pending means the request stopped before the result was recorded: check whether the key still exists before restoring it. Copy this log before deleting SEO Pro Stack; uninstall removes it, not the database changes.', 'seoprostack') . '</p>';
        foreach ((array) get_option(self::LOG, array()) as $entry) {
            echo '<p>' . esc_html($entry['time'] . ' / ' . $entry['table'] . ' / ' . $entry['key'] . ' / ' . $entry['status']) . '</p><pre>' . esc_html($entry['restore']) . '</pre>';
        }
        echo '</div>';
    }
}
