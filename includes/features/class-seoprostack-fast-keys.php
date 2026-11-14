<?php
/**
 * Faster database keys: the keys Index WP MySQL For Speed (1.6, Barracuda)
 * gives WordPress's and WooCommerce's meta, post, comment, option and user
 * tables, added in the background and kept after updates.
 *
 * Only keys named in the running WordPress (or WooCommerce) schema, or in
 * the target set, are changed; other plugins' keys stay. Each table changes
 * in one online ALTER TABLE (SEOProStack_Fast_Keys_Table). Tables that are
 * not InnoDB with a DYNAMIC or COMPRESSED row format, have NULL in a new
 * primary key column, or are large (left to WP-CLI) are skipped. Tables
 * already keyed this way (by Index WP MySQL For Speed) are adopted as they
 * are. The key sets are in SEOProStack_Fast_Keys_Schema, the comparison in
 * SEOProStack_Fast_Keys_Plan, the panel and WP-CLI in
 * SEOProStack_Fast_Keys_Admin.
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

// Also loaded by the must-use file and uninstall.php, without the plugin.
require_once __DIR__ . '/class-seoprostack-fast-keys-schema.php';
require_once __DIR__ . '/class-seoprostack-fast-keys-plan.php';
require_once __DIR__ . '/class-seoprostack-fast-keys-table.php';

/**
 * WordPress calls each hook's callback by name, so they are public.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class SEOProStack_Fast_Keys extends SEOProStack_Feature {

    const KEY = 'fast_keys';

    /** State: tables kept, last result per table, log. Not autoloaded. */
    const STATE = 'seoprostack_fast_keys';

    /** Daily background check. */
    const HOOK = 'seoprostack_fast_keys';

    /** One check soon: after updates, setting changes and Check now. */
    const SOON = 'seoprostack_fast_keys_soon';

    /** Must-use file that adds the dbDelta filter to upgrade.php. */
    const FILE = 'seoprostack-fast-keys.php';
    const MARKER = 'SEO Pro Stack faster database keys';

    /** Seconds a background run may start new tables for. */
    const BUDGET = 20;

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
        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            require_once __DIR__ . '/class-seoprostack-fast-keys-admin.php';
            SEOProStack_Fast_Keys_Admin::boot();
        }
    }

    /**
     * Hooks that must also run where plugins do not load (the must-use
     * file calls this in wp-admin/upgrade.php).
     */
    public static function early() {
        add_filter('dbdelta_queries', array(__CLASS__, 'dbdelta'), 20);
        add_action('wp_upgrade', array(__CLASS__, 'soon'), 10, 0);
    }

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
        $lock = 'sps_fast_keys_' . substr(hash('sha256', DB_NAME . $wpdb->prefix), 0, 30);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return array();
        }
        $results = array();
        $state   = self::state();
        try {
            $results = self::run_tables($state, $undo, $cli);
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
     * Each table in turn, within the time budget (none from WP-CLI).
     *
     * @param array $state Stored state, updated.
     * @param bool  $undo  Restoring WordPress's keys.
     * @param bool  $cli   From WP-CLI.
     * @return array<string,array> Results per table.
     */
    private static function run_tables(array &$state, $undo, $cli) {
        $results = array();
        $start   = microtime(true);
        foreach (SEOProStack_Fast_Keys_Schema::targets() as $suffix => $target) {
            $base = SEOProStack_Fast_Keys_Schema::standard_for($suffix);
            if ($undo && (!isset($state['owned'][$suffix]) || !$base['keys'])) {
                continue;
            }
            if (!$cli && !self::more_time($start, self::BUDGET)) {
                self::soon();
                break;
            }
            $fast             = SEOProStack_Fast_Keys_Plan::combined($target, $base);
            $last             = isset($state['tables'][$suffix]) ? $state['tables'][$suffix] : array();
            $results[$suffix] = SEOProStack_Fast_Keys_Table::run($suffix, $undo ? $base : $fast, $undo ? $fast : $base, $undo, $cli, $last);
            self::record($state, $suffix, $results[$suffix], $undo);
        }
        if ($undo) {
            $state['tables'] = array_intersect_key($state['tables'], $state['owned']);
        }
        return $results;
    }

    /**
     * Store one table's result: whether it is kept, and a log line when the
     * table was changed.
     *
     * @param array  $state  Stored state, updated.
     * @param string $suffix Table without prefix.
     * @param array  $result Its result.
     * @param bool   $undo   Restoring WordPress's keys.
     */
    private static function record(array &$state, $suffix, array $result, $undo) {
        $stored = $result;
        unset($stored['sql']);
        $state['tables'][$suffix] = $stored;
        if ('missing' === $result['status'] || ($undo && 'done' === $result['status'])) {
            unset($state['owned'][$suffix]);
        } elseif ('done' === $result['status'] && !isset($state['owned'][$suffix])) {
            $state['owned'][$suffix] = time();
        }
        if (!empty($result['sql'])) {
            $state['log'][] = array(
                'time'   => time(),
                'table'  => SEOProStack_Fast_Keys_Schema::table($suffix),
                'action' => $undo ? 'undo' : 'apply',
                'result' => 'done' === $result['status'] ? 'done' : 'failed: ' . $result['note'],
            );
        }
    }

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
        $tables = self::owned_tables();
        if (!$tables) {
            return $queries;
        }
        $targets = SEOProStack_Fast_Keys_Schema::targets();
        foreach ($queries as $i => $query) {
            if (!is_string($query) || !preg_match('/^\s*CREATE TABLE\s+`?([^\s`(]+)`?\s*\((.*)\)([^)]*)$/is', $query, $match)) {
                continue;
            }
            $suffix = isset($tables[strtolower($match[1])]) ? $tables[strtolower($match[1])] : '';
            if ('' !== $suffix) {
                $queries[$i] = SEOProStack_Fast_Keys_Plan::rewrite($query, $match, $targets[$suffix], SEOProStack_Fast_Keys_Schema::standard_for($suffix));
            }
        }
        return $queries;
    }

    /**
     * Tables this keeps: lowercase full name => suffix.
     *
     * @return array<string,string>
     */
    private static function owned_tables() {
        $targets = SEOProStack_Fast_Keys_Schema::targets();
        $tables  = array();
        foreach (array_keys(self::state()['owned']) as $suffix) {
            if (isset($targets[$suffix])) {
                $tables[strtolower(SEOProStack_Fast_Keys_Schema::table($suffix))] = $suffix;
            }
        }
        return $tables;
    }

    /**
     * Install, update or remove the must-use file. Only our own marked file
     * is ever written or removed.
     *
     * @param bool $on Whether it should be there.
     */
    private static function sync_file($on) {
        // Uninstall removes it without loading the main plugin file.
        if (!defined('WPMU_PLUGIN_DIR') || ($on && !defined('SEOPROSTACK_FILE'))) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $fs   = new WP_Filesystem_Direct(null);
        $path = WPMU_PLUGIN_DIR . '/' . self::FILE;
        $old  = is_file($path) ? (string) $fs->get_contents($path) : '';
        if ('' !== $old && false === strpos($old, self::MARKER)) {
            return; // Not ours.
        }
        if (!$on) {
            if ('' !== $old) {
                $fs->delete($path);
            }
            return;
        }
        $code = self::file_code();
        if ($old !== $code && wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            $fs->put_contents($path, $code, 0644);
        }
    }

    /**
     * The must-use file's code.
     *
     * @return string
     */
    private static function file_code() {
        $base = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(SEOPROSTACK_FILE)) . '/includes/';
        // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates PHP string literals.
        return "<?php\n// " . self::MARKER . ": keeps its keys when wp-admin/upgrade.php updates the database (plugins do not load there).\n"
            . 'if (defined(\'ABSPATH\') && !is_multisite() && is_file(' . var_export($base . 'features/class-seoprostack-fast-keys.php', true) . ")) {\n"
            . '    require_once ' . var_export($base . 'class-seoprostack-feature.php', true) . ";\n"
            . '    require_once ' . var_export($base . 'features/class-seoprostack-fast-keys.php', true) . ";\n"
            . "    SEOProStack_Fast_Keys::early();\n}\n";
        // phpcs:enable
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
        foreach (SEOProStack_Fast_Keys_Schema::targets() as $suffix => $target) {
            if (isset($owned[$suffix]) && SEOProStack_Fast_Keys_Schema::table($suffix) === $table) {
                return in_array(strtolower((string) $name), SEOProStack_Fast_Keys_Plan::names($target['keys']), true);
            }
        }
        return false;
    }
}
