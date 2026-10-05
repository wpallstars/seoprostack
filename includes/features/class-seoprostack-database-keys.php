<?php
/**
 * On-demand review of secondary indexes on this site's core tables.
 *
 * No scheduled scans or automatic DDL. A fresh survey is required for both
 * confirmation and removal. Restore statements are saved before DDL because
 * MySQL's ALTER TABLE cannot be rolled back with an option write.
 *
 * Every key gets an owner (WordPress, the plugin that added it, or unknown)
 * and, when another key covers its columns, the name of that key, even when
 * it cannot be removed yet. So a duplicate a still-active plugin re-creates
 * is named with what to do first. A Site Health test, always on, counts the
 * duplicates.
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

class SEOProStack_Database_Keys extends SEOProStack_Feature {

    const KEY = 'database_keys';
    const LOG = 'seoprostack_database_keys_log';
    const PAGE = 'seoprostack-database-keys';
    const ACTION = 'seoprostack_database_keys';

    /** Site Health test. */
    const TEST = 'seoprostack-database-keys';

    /** Transient: which installed plugin uses each searched key prefix (daily). */
    const OWNERS = 'seoprostack_database_key_owners';

    /** Seconds the search for a prefix's plugin may take. */
    const SEARCH_BUDGET = 5;

    const SCALABILITY_PRO = 'scalability-pro';
    const IWMFS = 'index-wp-mysql-for-speed';

    /** Key name prefixes of plugins known to add keys: prefix => plugin folder. */
    const PREFIXES = array(
        'wpi_'                    => self::SCALABILITY_PRO,
        'spro_'                   => self::SCALABILITY_PRO,
        'index_wp_mysql_protect_' => self::IWMFS,
    );

    /** Key prefixes no known plugin owns; installed plugins' code is searched for them. */
    const SEARCHED = array('wdbi_');

    /** Keys Index WP MySQL For Speed adds under plain names. */
    const IWMFS_KEYS = array('meta_value', 'display_name', 'comment_date_gmt', 'comment_post_parent_approved');

    public static function settings() {
        return array(self::KEY => array(
            'type' => 'bool',
            'default' => false,
            'tab' => 'server',
            'label' => __('Database key cleanup', 'seoprostack'),
            'description' => __('Review leftover and duplicate database indexes under Tools → Database keys, with the plugin that added each and what to do first. Nothing is removed until you choose a key and confirm. Back up the database first. Site Health counts duplicate keys even while this is off.', 'seoprostack'),
        ));
    }

    public static function boot() {
        // The test is always on: it only gives advice.
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        if (!is_admin()) {
            return;
        }
        add_action('wp_ajax_health-check-' . self::TEST, array(__CLASS__, 'ajax_test'));
        if (!self::enabled()) {
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

    /**
     * Whether $other starts with $key's columns in the same order and
     * directions. A whole column, or a longer prefix of it, covers a shorter
     * prefix: meta_value(32) serves every lookup meta_value(15) does. Add
     * database keys uses it to skip a key an existing one covers.
     */
    public static function covered(array $key, array $other) {
        if (count($key) > count($other) || !self::ordinary($other, true)) {
            return false;
        }
        foreach ($key as $i => $row) {
            if ($row['COLUMN_NAME'] !== $other[$i]['COLUMN_NAME'] || $row['COLLATION'] !== $other[$i]['COLLATION']) {
                return false;
            }
            if (null !== $other[$i]['SUB_PART'] && (null === $row['SUB_PART'] || (int) $other[$i]['SUB_PART'] < (int) $row['SUB_PART'])) {
                return false;
            }
        }
        return true;
    }

    /** Same columns, prefix lengths and directions. */
    private static function identical(array $key, array $other) {
        if (count($key) !== count($other)) {
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

    /**
     * The key that covers this one, or ''. Of two identical keys only one is
     * named: the one that is not a core key, else the later name.
     *
     * @param string $name       Key name.
     * @param array  $key        Its rows.
     * @param array  $keys       All the table's keys: name => rows.
     * @param array  $core_lower Lowercase core key names of the table.
     * @return string
     */
    private static function covering($name, array $key, array $keys, array $core_lower) {
        foreach ($keys as $other_name => $other) {
            if ($other_name === $name || !self::covered($key, $other)) {
                continue;
            }
            if (self::identical($key, $other)) {
                $core       = in_array(strtolower($name), $core_lower, true);
                $other_core = in_array(strtolower((string) $other_name), $core_lower, true);
                if ($core !== $other_core ? $core : strcmp($name, (string) $other_name) < 0) {
                    continue;
                }
            }
            return (string) $other_name;
        }
        return '';
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
     * Name of an installed plugin, by folder.
     *
     * @param string $slug Folder.
     * @return string
     */
    private static function plugin_name($slug) {
        $names = array(
            self::SCALABILITY_PRO => 'Scalability Pro',
            self::IWMFS           => 'Index WP MySQL For Speed',
        );
        if (isset($names[$slug])) {
            return $names[$slug];
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            if (dirname($file) === $slug && !empty($data['Name'])) {
                return (string) $data['Name'];
            }
        }
        return $slug;
    }

    /**
     * The installed plugin whose PHP code uses a key prefix, searched for at
     * most SEARCH_BUDGET seconds and remembered for a day.
     *
     * @param string $prefix Key prefix.
     * @return string|false|null Plugin folder; false when no installed plugin
     *                           uses it; null when the search did not finish.
     */
    private static function prefix_plugin($prefix) {
        $known = get_transient(self::OWNERS);
        $known = is_array($known) ? $known : array();
        if (array_key_exists($prefix, $known)) {
            return $known[$prefix];
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $own   = dirname(plugin_basename(SEOPROSTACK_FILE));
        $start = microtime(true);
        $found = false;
        foreach (array_keys(get_plugins()) as $file) {
            $slug = dirname($file);
            if ('.' === $slug || $own === $slug || !is_dir(WP_PLUGIN_DIR . '/' . $slug)) {
                continue;
            }
            try {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(WP_PLUGIN_DIR . '/' . $slug, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($files as $item) {
                    if (!self::more_time($start, self::SEARCH_BUDGET)) {
                        return null;
                    }
                    if (!$item->isFile() || 'php' !== strtolower($item->getExtension()) || $item->getSize() > 2097152) {
                        continue;
                    }
                    $code = file_get_contents($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.
                    if (is_string($code) && false !== stripos($code, $prefix)) {
                        $found = $slug;
                        break 2;
                    }
                }
            } catch (UnexpectedValueException $e) {
                continue; // An unreadable folder.
            }
        }
        $known[$prefix] = $found;
        set_transient(self::OWNERS, $known, DAY_IN_SECONDS);
        return $found;
    }

    /**
     * Who added a key.
     *
     * @param string $lower      Lowercase key name.
     * @param array  $core_lower Lowercase core key names of the table.
     * @param array  $active     Active plugins: folder => file.
     * @return array{name:string,slug:string,active:bool,core:bool,searched:bool,unknown:bool}
     */
    private static function owner($lower, array $core_lower, array $active) {
        $owner = array('name' => '', 'slug' => '', 'active' => false, 'core' => false, 'searched' => false, 'unknown' => false);
        if (in_array($lower, $core_lower, true)) {
            $owner['core'] = true;
            $owner['name'] = isset($active[self::IWMFS])
                ? __('WordPress (Index WP MySQL For Speed rebuilds core keys)', 'seoprostack')
                : __('WordPress', 'seoprostack');
            return $owner;
        }
        if (0 === strpos($lower, SEOProStack_Added_Keys::PREFIX)) {
            $owner['name'] = __('SEO Pro Stack (Add database keys)', 'seoprostack');
            return $owner;
        }
        if (isset($active[self::IWMFS]) && in_array($lower, self::IWMFS_KEYS, true)) {
            $owner['slug'] = self::IWMFS;
        } else {
            foreach (self::PREFIXES as $prefix => $slug) {
                if (0 === strpos($lower, $prefix)) {
                    $owner['slug'] = $slug;
                    break;
                }
            }
            foreach (self::SEARCHED as $prefix) {
                if ('' === $owner['slug'] && 0 === strpos($lower, $prefix)) {
                    $owner['searched'] = true;
                    $slug              = self::prefix_plugin($prefix);
                    if (is_string($slug)) {
                        $owner['slug'] = $slug;
                    } elseif (false === $slug) {
                        $owner['name'] = __('Unknown: no installed plugin uses this prefix', 'seoprostack');
                    } else {
                        $owner['name']    = __('Unknown: the search of installed plugins did not finish', 'seoprostack');
                        $owner['unknown'] = true;
                    }
                }
            }
        }
        if ('' !== $owner['slug']) {
            $owner['active'] = isset($active[$owner['slug']]);
            $owner['name']   = self::plugin_name($owner['slug']);
        }
        return $owner;
    }

    /**
     * Survey this site's tables plus global tables for super admins only.
     * Index sizes are optional InnoDB estimates, not table totals divided by keys.
     *
     * Kinds: redundant (another key covers it; removable), duplicate (covered
     * but kept, with the reason in blocked), leftover (added by a plugin
     * that is not active, not covered; removable only when $leftovers),
     * protected and kept.
     *
     * @param bool $leftovers Whether leftover keys nothing covers can be removed.
     * @return array|WP_Error
     */
    public static function survey($leftovers = false) {
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
            $core_lower = array_map('strtolower', $core[$table]);
            $table_size = null;
            foreach ($keys as $name => $key) {
                $name  = (string) $name;
                $lower = strtolower($name);
                $owner = self::owner($lower, $core_lower, $active);
                $ordinary = self::ordinary($key);
                $duplicate_of = $ordinary ? self::covering($name, $key, $keys, $core_lower) : '';
                $blocked = '';
                if (is_multisite() && in_array($table, $global, true)) {
                    // Shared indexes may be owned by a plugin active on another site.
                    $blocked = __('it is on a table the whole network shares, and a plugin on another site may use it.', 'seoprostack');
                } elseif ($owner['core']) {
                    $blocked = __('it is a WordPress core key.', 'seoprostack');
                } elseif (!$ordinary) {
                    $blocked = __('it is a unique, full-text, spatial or other special key.', 'seoprostack');
                } elseif (array_intersect(array_column($key, 'COLUMN_NAME'), $foreign)) {
                    $blocked = __('it holds a column of a foreign key.', 'seoprostack');
                } elseif (0 === strpos($lower, SEOProStack_Added_Keys::PREFIX)) {
                    $blocked = __('SEO Pro Stack added it. Remove it under Tools → Add database keys (turn on Add database keys on the Server tab first).', 'seoprostack');
                } elseif ($owner['active']) {
                    /* translators: %s: plugin name */
                    $blocked = sprintf(__('%s is active and re-creates this key.', 'seoprostack'), $owner['name']);
                } elseif (0 === strpos($lower, 'index_wp_mysql_protect_')) {
                    $blocked = __('Index WP MySQL For Speed keeps it to protect its changes.', 'seoprostack');
                } elseif ($owner['unknown']) {
                    $blocked = __('SEO Pro Stack could not finish checking which plugin added it. Open this page again to finish.', 'seoprostack');
                }
                $leftover = '' !== $owner['slug'] || $owner['searched'];
                if ('' !== $duplicate_of && '' === $blocked) {
                    $kind = 'redundant';
                    /* translators: %s: covering database index name. */
                    $reason = sprintf(__('Redundant: %s covers its columns.', 'seoprostack'), $duplicate_of);
                    if ('' !== $owner['slug']) {
                        /* translators: %s: plugin name */
                        $reason .= ' ' . sprintf(__('Added by %s, which is not active.', 'seoprostack'), $owner['name']);
                    }
                } elseif ('' !== $duplicate_of) {
                    $kind = 'duplicate';
                    /* translators: 1: covering database index name, 2: why the key is kept */
                    $reason = sprintf(__('Duplicate: %1$s covers its columns, but it is kept because %2$s', 'seoprostack'), $duplicate_of, $blocked);
                    if ($owner['active']) {
                        /* translators: %s: plugin name */
                        $reason .= ' ' . sprintf(__('Check what else %s does for this site, deactivate it, then remove this key here.', 'seoprostack'), $owner['name']);
                    }
                } elseif ('' === $blocked && $leftover) {
                    $kind = 'leftover';
                    /* translators: %s: plugin name or "Unknown: …" */
                    $reason = sprintf(__('Leftover (%s), not covered by another key: no plugin maintains it. Keep it unless you know it is unused.', 'seoprostack'), $owner['name']);
                } elseif ('' !== $blocked) {
                    $kind = 'protected';
                    /* translators: %s: why the key is kept */
                    $reason = sprintf(__('Protected: %s', 'seoprostack'), $blocked);
                } else {
                    $kind = 'kept';
                    $reason = __('Not covered by another key.', 'seoprostack');
                }
                $eligible = 'redundant' === $kind || ($leftovers && 'leftover' === $kind);
                $size = self::size($table, $name);
                if (null === $size && null === $table_size) {
                    $table_size = self::table_size($table);
                }
                $id = hash('sha256', $table . "\0" . $name);
                $restore = $eligible ? 'CREATE INDEX ' . self::identifier($name) . ' ON ' . self::identifier($table) . ' (' . self::parts($key) . ') USING BTREE;' : '';
                $result[$id] = array(
                    'table' => $table, 'name' => $name, 'columns' => self::parts($key),
                    'owner' => $owner['name'], 'owner_slug' => $owner['slug'], 'owner_active' => $owner['active'], 'core' => $owner['core'],
                    'kind' => $kind, 'duplicate_of' => $duplicate_of, 'blocked' => $blocked,
                    'reason' => $reason,
                    'eligible' => $eligible,
                    'restore' => $restore,
                    'fingerprint' => hash('sha256', $restore),
                    'size' => $size,
                    'table_size' => null === $size ? $table_size : null,
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

    /** All the table's keys but the primary key, from the table status, when per-key sizes are not readable. */
    private static function table_size($table) {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- optional estimate; no cache.
        $bytes = $wpdb->get_var($wpdb->prepare('SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
        $wpdb->suppress_errors($previous);
        return null === $bytes ? null : (float) $bytes;
    }

    /** Serialize our DDL and log writes across requests; never wait for a lock. */
    private static function remove($id, $fingerprint, $leftovers) {
        global $wpdb;
        $lock = 'sps_keys_' . substr(hash('sha256', DB_NAME), 0, 40);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock, not stored state.
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ('1' !== (string) $acquired) {
            return new WP_Error('busy', __('Another cleanup is running, or the database cannot lock this operation. Nothing was removed.', 'seoprostack'));
        }
        try {
            return self::drop_confirmed($id, $fingerprint, $leftovers);
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release our connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Revalidate one confirmed key, write recovery evidence, then execute DDL. */
    private static function drop_confirmed($id, $fingerprint, $leftovers) {
        global $wpdb;
        $keys = self::survey($leftovers);
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

    /** Page address, with the leftover choice. */
    private static function page_url($leftovers) {
        $url = admin_url('tools.php?page=' . self::PAGE);
        return $leftovers ? add_query_arg('leftovers', '1', $url) : $url;
    }

    /** POST-only two-step form; each step checks capability and nonce. */
    public static function handle() {
        if (!self::allowed() || !isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $id = isset($_POST['key']) && is_string($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $leftovers = isset($_POST['leftovers']) && '1' === $_POST['leftovers'];
        if (isset($_POST['confirm']) && 'yes' === $_POST['confirm']) {
            $fingerprint = isset($_POST['fingerprint']) && is_string($_POST['fingerprint']) ? sanitize_text_field(wp_unslash($_POST['fingerprint'])) : '';
            $result = self::remove($id, $fingerprint, $leftovers);
            if (is_wp_error($result)) {
                wp_die(esc_html($result->get_error_message()));
            }
            wp_safe_redirect(self::page_url($leftovers));
            exit;
        }
        $keys = self::survey($leftovers);
        if (is_wp_error($keys) || !isset($keys[$id]) || !$keys[$id]['eligible']) {
            wp_die(esc_html__('This key cannot be removed. Review the list again.', 'seoprostack'));
        }
        // Render through Tools so core has initialized its admin menu and header.
        $url = add_query_arg('review', $id, self::page_url($leftovers));
        wp_safe_redirect(add_query_arg('_wpnonce', wp_create_nonce(self::ACTION . '_review'), $url));
        exit;
    }

    private static function confirmation($id, array $key, $leftovers) {
        echo '<div class="wrap"><h1>' . esc_html__('Confirm key removal', 'seoprostack') . '</h1>';
        echo '<p>' . esc_html__('Back up your database first. This changes only the chosen index, not rows, but can slow queries and briefly block database writes. Run during a quiet period.', 'seoprostack') . '</p>';
        echo '<p><strong>' . esc_html($key['table'] . ' / ' . $key['name']) . '</strong>: ' . esc_html($key['columns']) . '</p><p>' . esc_html($key['reason']) . '</p>';
        if ('leftover' === $key['kind']) {
            echo '<p><strong>' . esc_html__('No other key covers this one, so queries that use it may get slower.', 'seoprostack') . '</strong></p>';
        }
        echo '<p>' . esc_html__('Save this restore statement. Run it through your database tool if you need to put the key back:', 'seoprostack') . '</p><pre>' . esc_html($key['restore']) . '</pre>';
        self::form($id, $leftovers, $key['fingerprint']);
        echo '<p><a href="' . esc_url(self::page_url($leftovers)) . '">' . esc_html__('Cancel', 'seoprostack') . '</a></p></div>';
    }

    private static function form($id, $leftovers, $fingerprint = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="key" value="' . esc_attr($id) . '">';
        if ($leftovers) {
            echo '<input type="hidden" name="leftovers" value="1">';
        }
        if ($fingerprint) {
            echo '<input type="hidden" name="fingerprint" value="' . esc_attr($fingerprint) . '"><input type="hidden" name="confirm" value="yes">';
        }
        submit_button($fingerprint ? __('Remove this key', 'seoprostack') : __('Review removal', 'seoprostack'), 'secondary', 'submit', false);
        echo '</form>';
    }

    /**
     * Advice above the list when Scalability Pro's duplicates are kept while
     * Index WP MySQL For Speed is active too.
     *
     * @param array $keys Survey.
     */
    private static function overlap(array $keys) {
        $active = self::active_plugins();
        if (!isset($active[self::SCALABILITY_PRO], $active[self::IWMFS])) {
            return;
        }
        $count = 0;
        foreach ($keys as $key) {
            if ('duplicate' === $key['kind'] && self::SCALABILITY_PRO === $key['owner_slug']) {
                $count++;
            }
        }
        if (!$count) {
            return;
        }
        echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(
            /* translators: %d: number of keys */
            _n(
                'Index WP MySQL For Speed and Scalability Pro overlap on indexes: %d Scalability Pro key repeats one that is already there. Index WP MySQL For Speed covers the core tables. Check which other Scalability Pro features this site uses (many are WooCommerce and admin count caches), consider deactivating it, then remove its duplicate keys here.',
                'Index WP MySQL For Speed and Scalability Pro overlap on indexes: %d Scalability Pro keys repeat ones that are already there. Index WP MySQL For Speed covers the core tables. Check which other Scalability Pro features this site uses (many are WooCommerce and admin count caches), consider deactivating it, then remove its duplicate keys here.',
                $count,
                'seoprostack'
            ),
            $count
        )) . ' <a href="' . esc_url(admin_url('plugins.php?plugin_status=active&s=' . rawurlencode('Scalability Pro'))) . '">' . esc_html__('Scalability Pro on the Plugins screen', 'seoprostack') . '</a></p></div>';
    }

    public static function page() {
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'));
        }
        $leftovers = isset($_GET['leftovers']) && '1' === $_GET['leftovers']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display choice; removal checks its own nonce.
        if (isset($_GET['review'])) {
            check_admin_referer(self::ACTION . '_review');
            $id = is_string($_GET['review']) ? sanitize_text_field(wp_unslash($_GET['review'])) : '';
            $keys = self::survey($leftovers);
            if (is_wp_error($keys) || !isset($keys[$id]) || !$keys[$id]['eligible']) {
                wp_die(esc_html__('This key cannot be removed. Review the list again.', 'seoprostack'));
            }
            self::confirmation($id, $keys[$id], $leftovers);
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Database keys', 'seoprostack') . '</h1><p>' . esc_html__('Only this site’s core tables are scanned. Shared multisite tables are shown only to super admins. Removing indexes does not remove rows. Back up first; each removal needs confirmation. Estimated sizes may be unavailable or out of date.', 'seoprostack') . '</p>';
        $keys = self::survey($leftovers);
        if (is_wp_error($keys)) {
            echo '<p>' . esc_html($keys->get_error_message()) . '</p>';
        } else {
            self::overlap($keys);
            $has_leftovers = in_array('leftover', array_column($keys, 'kind'), true);
            if ($has_leftovers) {
                echo '<p>' . ($leftovers
                    ? esc_html__('Leftover keys nothing covers can be removed too.', 'seoprostack') . ' <a href="' . esc_url(self::page_url(false)) . '">' . esc_html__('Offer only redundant keys', 'seoprostack') . '</a>'
                    : esc_html__('Leftover keys that no plugin maintains and no other key covers are listed but not offered: queries may still use them.', 'seoprostack') . ' <a href="' . esc_url(self::page_url(true)) . '">' . esc_html__('Offer leftover keys too', 'seoprostack') . '</a>') . '</p>';
            }
            echo '<table class="widefat striped"><thead><tr>';
            foreach (array(__('Table / key', 'seoprostack'), __('Columns', 'seoprostack'), __('Added by', 'seoprostack'), __('Why', 'seoprostack'), __('Estimated size', 'seoprostack'), __('Action', 'seoprostack')) as $label) {
                echo '<th scope="col">' . esc_html($label) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($keys as $id => $key) {
                if (null !== $key['size']) {
                    $size = size_format($key['size']);
                } elseif (null !== $key['table_size']) {
                    /* translators: %s: size of all the table's keys */
                    $size = sprintf(__('Unavailable (all this table’s keys but the primary key: %s)', 'seoprostack'), size_format($key['table_size']));
                } else {
                    $size = __('Unavailable', 'seoprostack');
                }
                $owner = $key['owner'];
                if ('' !== $key['owner_slug']) {
                    $owner .= ' (' . ($key['owner_active'] ? __('active', 'seoprostack') : __('not active', 'seoprostack')) . ')';
                }
                echo '<tr><td>' . esc_html($key['table'] . ' / ' . $key['name']) . '</td><td>' . esc_html($key['columns']) . '</td><td>' . esc_html($owner) . '</td><td>' . esc_html($key['reason']) . '</td><td>' . esc_html((string) $size) . '</td><td>';
                if ($key['eligible']) {
                    self::form($id, $leftovers);
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

    /* ------------------------------------------------------------------
     * Site Health
     * ------------------------------------------------------------------ */

    /**
     * Add the test.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        $tests['async'][self::TEST] = array(
            'label'             => __('Duplicate database keys', 'seoprostack'),
            'test'              => self::TEST,
            'async_direct_test' => array(__CLASS__, 'test'),
        );
        return $tests;
    }

    /**
     * Run the test for the Site Health screen.
     */
    public static function ajax_test() {
        check_ajax_referer('health-check-site-status');
        if (!current_user_can('view_site_health_checks')) {
            wp_send_json_error();
        }
        wp_send_json_success(self::test());
    }

    /**
     * Duplicate keys on core tables that are not core keys themselves.
     *
     * @return array
     */
    public static function test() {
        $result = array(
            'label'       => __('No duplicate database keys on the core tables', 'seoprostack'),
            'status'      => 'good',
            'badge'       => array(
                'label' => __('Performance', 'seoprostack'),
                'color' => 'blue',
            ),
            'description' => '<p>' . esc_html__('A database key (index) that repeats the start of another key speeds up nothing, but every write to its table updates it. Plugins that add keys, such as Scalability Pro and Index WP MySQL For Speed, can repeat each other’s or WordPress’s.', 'seoprostack') . '</p>',
            'actions'     => '',
            'test'        => 'seoprostack_database_keys',
        );
        $keys = self::survey();
        if (is_wp_error($keys)) {
            $result['label'] = __('Database keys could not be checked', 'seoprostack');
            $result['description'] .= '<p>' . esc_html($keys->get_error_message()) . '</p>';
            return $result;
        }
        $found = array();
        foreach ($keys as $key) {
            if ('redundant' === $key['kind'] || ('duplicate' === $key['kind'] && !$key['core'])) {
                $found[] = $key;
            }
        }
        if (!$found) {
            return $result;
        }
        $result['status'] = 'recommended';
        $result['label']  = sprintf(
            /* translators: %d: number of keys */
            _n('%d duplicate database key', '%d duplicate database keys', count($found), 'seoprostack'),
            count($found)
        );
        $items = '';
        foreach ($found as $key) {
            $items .= '<li><code>' . esc_html($key['table'] . ' / ' . $key['name']) . '</code>: ' . esc_html($key['reason']) . '</li>';
        }
        $result['description'] .= '<ul>' . $items . '</ul>';
        $result['actions'] = '<p>' . (self::enabled()
            ? '<a href="' . esc_url(admin_url('tools.php?page=' . self::PAGE)) . '">' . esc_html__('Review them in Tools → Database keys', 'seoprostack') . '</a>'
            : sprintf(
                /* translators: %s: link to the setting */
                esc_html__('Turn on %s in SEO Pro Stack to review and remove them one at a time, with a restore statement for each.', 'seoprostack'),
                '<a href="' . esc_url(admin_url('options-general.php?page=seoprostack&tab=server')) . '">' . esc_html__('Database key cleanup', 'seoprostack') . '</a>'
            )) . '</p>';
        return $result;
    }
}
