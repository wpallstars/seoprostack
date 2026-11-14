<?php
/**
 * Faster database keys: the keys Index WP MySQL For Speed (1.6, Barracuda)
 * gives WordPress's and WooCommerce's meta, post, comment, option and user
 * tables, added in the background and kept after updates.
 *
 * Only keys named in the running WordPress (or WooCommerce) schema, or in
 * the target set, are changed; other plugins' keys stay. Each table changes
 * in one online ALTER TABLE. Tables that are not InnoDB with a DYNAMIC or
 * COMPRESSED row format, have NULL in a new primary key column, or are
 * large (left to WP-CLI) are skipped. Tables already keyed this way (by
 * Index WP MySQL For Speed) are adopted as they are.
 *
 * Parts: the key sets (SEOProStack_Fast_Keys_Schema), the comparison
 * (_Plan), one table (_Table, _Alter), a run over the tables (_Run),
 * dbDelta() (_Dbdelta), the must-use file (_Mu_File), the panel (_Admin)
 * and WP-CLI (_Cli).
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
require_once __DIR__ . '/class-seoprostack-fast-keys-alter.php';
require_once __DIR__ . '/class-seoprostack-fast-keys-run.php';
require_once __DIR__ . '/class-seoprostack-fast-keys-dbdelta.php';
require_once __DIR__ . '/class-seoprostack-fast-keys-mu-file.php';

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
            require_once __DIR__ . '/class-seoprostack-fast-keys-admin.php';
            SEOProStack_Fast_Keys_Admin::boot();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            require_once __DIR__ . '/class-seoprostack-fast-keys-cli.php';
            WP_CLI::add_command('seoprostack fast-keys', array('SEOProStack_Fast_Keys_Cli', 'run'));
        }
    }

    /**
     * Hooks that must also run where plugins do not load (the must-use
     * file calls this in wp-admin/upgrade.php).
     */
    public static function early() {
        add_filter('dbdelta_queries', array('SEOProStack_Fast_Keys_Dbdelta', 'filter'), 20);
        add_action('wp_upgrade', array(__CLASS__, 'soon'), 10, 0);
    }

    /**
     * Stored state.
     *
     * @return array{owned:array<string,int>,tables:array<string,array>,log:array}
     */
    public static function state() {
        $stored = get_option(self::STATE, array());
        $state  = array();
        foreach (array('owned', 'tables', 'log') as $part) {
            $state[$part] = is_array($stored) && isset($stored[$part]) && is_array($stored[$part]) ? $stored[$part] : array();
        }
        return $state;
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
            SEOProStack_Fast_Keys_Mu_File::sync(true);
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
        SEOProStack_Fast_Keys_Mu_File::sync(false);
    }

    /**
     * Background run: daily, or soon after a change.
     */
    public static function run_scheduled() {
        SEOProStack_Fast_Keys_Run::run(false);
    }

    /**
     * Put WordPress's keys back and remove the file (uninstall).
     */
    public static function remove_all() {
        SEOProStack_Fast_Keys_Run::run(true, 'undo');
        SEOProStack_Fast_Keys_Mu_File::sync(false);
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
