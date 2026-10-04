<?php
/**
 * Clean the database weekly.
 *
 * Once a week, in the background at a quiet hour, removes what WordPress
 * and plugins leave behind and never use again: expired transients, posts
 * and comments that have been in the bin longer than WordPress keeps them,
 * old spam comments, old automatic drafts, and details (meta) of posts,
 * comments and terms that no longer exist. Optionally it also optimises
 * tables with a lot of free space. Revisions are left to Limit post
 * revisions.
 *
 * Work is done in small batches within a time limit; anything left is
 * picked up a minute later. Posts and comments are removed with WordPress's
 * own functions, so their files, meta and counts follow.
 *
 * Replaces WP-Optimize's scheduled cleanup where LiteSpeed Cache runs on a
 * LiteSpeed server, which then does WP-Optimize's other jobs (page cache,
 * minify). Elsewhere WP-Optimize's page cache is still needed, so this runs
 * alongside it. WP-Optimize's scheduled cleanup choices are imported once.
 *
 * @package SEOProStack
 * @since 0.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Database_Cleanup extends SEOProStack_Feature {

    const KEY = 'database_cleanup';

    /** What to clean. */
    const ITEMS_KEY = 'database_cleanup_items';

    /** Whether to optimise tables. */
    const OPTIMIZE_KEY = 'database_cleanup_optimize';

    /** Weekly cleanup. */
    const HOOK = 'seoprostack_database_cleanup';

    /** One more batch, when a run stopped at its time limit. */
    const MORE = 'seoprostack_database_cleanup_more';

    /** Last run: time, counts, whether it finished. Not autoloaded. */
    const LAST = 'seoprostack_database_cleanup_last';

    /** What is waiting, cached for the settings panel. */
    const WAITING = 'seoprostack_database_cleanup_waiting';

    /** Clean now (admin-post action and nonce). */
    const ACTION = 'seoprostack_database_cleanup_now';

    /** The editions this replaces on LiteSpeed servers with LiteSpeed Cache. */
    const REPLACES = array(
        'wp-optimize'         => 'WP-Optimize',
        'wp-optimize-premium' => 'WP-Optimize Premium',
    );

    /** Seconds a background or Clean now run may take. */
    const BUDGET = 20;

    /** Rows per batch: posts and comments (deleted one by one), and meta. */
    const BATCH = 100;
    const META_BATCH = 1000;

    /** Days before automatic drafts go, as WordPress's own daily cleanup. */
    const AUTO_DRAFT_DAYS = 7;

    /** Free space a table needs before it is optimised: 1 MB and a tenth of its size. */
    const FREE_MIN = 1048576;

    /**
     * Scheduled tasks seen without code on cron requests: when checked, and
     * per hook when first and last seen so; hooks put back. Not autoloaded.
     */
    const CRON_SEEN = 'seoprostack_database_cleanup_cron_seen';

    /** Scheduled tasks the last cleanup removed, as stored, for Put back. */
    const CRON_REMOVED = 'seoprostack_database_cleanup_cron_removed';

    /** Put back (admin-post action and nonce). */
    const PUT_BACK = 'seoprostack_database_cleanup_put_back';

    /** Days a task must have had no code, between two checks. */
    const CRON_DAYS = 7;

    /** Seconds after which a check no longer counts (the code may be back): 2 days. */
    const CRON_FRESH = 172800;

    /** Value of wp-cron.php's lock that loads WordPress for a check and runs no tasks. */
    const CRON_CHECK = 'seoprostack-check';

    /** Transient: a check was asked for from the admin within the hour. */
    const CRON_ASKED = 'seoprostack_database_cleanup_cron_asked';

    /** WordPress's own scheduled tasks, some with code only in the admin. */
    const CORE_CRON = array(
        'delete_expired_transients',
        'recovery_mode_clean_expired_keys',
        'upgrader_scheduled_cleanup',
        'importer_scheduled_cleanup',
        'publish_future_post',
        'do_pings',
    );

    /**
     * Hosts' agents that add tasks from outside plugins, on web requests
     * only: Hostinger's Monarx agent (mnx_*, the monarxprotect PHP
     * extension). Plugin fixes registers their schedules on cron and WP-CLI
     * requests, where the agent does not.
     */
    const HOST_CRON = array('mnx_');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        $settings = array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'server',
                'label'       => __('Clean the database weekly', 'seoprostack'),
                'description' => __('Once a week, in the background, remove what WordPress and plugins leave behind: expired temporary data, old spam and bin contents, old automatic drafts and details of deleted posts. Revisions are left to Limit post revisions.', 'seoprostack'),
                // Only where LiteSpeed Cache on a LiteSpeed server does WP-Optimize's other jobs (see below).
                'replaces'    => self::REPLACES,
            ),
            self::ITEMS_KEY => array(
                'type'    => 'multi',
                // Scheduled tasks change how other code behaves: chosen only by hand.
                'default' => array_values(array_diff(array_keys(self::item_options()), array('orphaned_cron'))),
                'parent'  => self::KEY,
                'label'   => __('Remove', 'seoprostack'),
                'options' => array(__CLASS__, 'item_options'),
            ),
            self::OPTIMIZE_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Optimise tables with free space', 'seoprostack'),
                'description' => __('Rebuild tables that have a lot of unused space, to give it back. A large table can be busy for a moment while this runs.', 'seoprostack'),
            ),
        );
        if (!self::replaces_wp_optimize()) {
            unset($settings[self::KEY]['replaces']);
        }
        return $settings;
    }

    /**
     * What it removes.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        $days = self::bin_days();
        return array(
            'transients'    => __('Expired temporary data (transients)', 'seoprostack'),
            /* translators: %s: number of days */
            'trash'         => sprintf(_n('Posts and comments in the bin for over %s day', 'Posts and comments in the bin for over %s days', $days, 'seoprostack'), number_format_i18n($days)),
            /* translators: %s: number of days */
            'spam'          => sprintf(_n('Spam comments older than %s day', 'Spam comments older than %s days', $days, 'seoprostack'), number_format_i18n($days)),
            /* translators: %s: number of days */
            'auto_drafts'   => sprintf(__('Automatic drafts older than %s days', 'seoprostack'), number_format_i18n(self::AUTO_DRAFT_DAYS)),
            'orphaned_meta' => __('Details of posts, comments and terms that no longer exist', 'seoprostack'),
            /* translators: %s: number of days */
            'orphaned_cron' => sprintf(__('Scheduled tasks from plugins that are no longer active (no code to run them for %s days)', 'seoprostack'), number_format_i18n(self::CRON_DAYS)),
        );
    }

    /**
     * Days WordPress keeps the bin (EMPTY_TRASH_DAYS), also used for spam.
     *
     * @return int
     */
    private static function bin_days() {
        $days = defined('EMPTY_TRASH_DAYS') ? (int) EMPTY_TRASH_DAYS : 30;
        return max(1, $days);
    }

    /**
     * Whether this takes over from WP-Optimize: on a LiteSpeed server with
     * LiteSpeed Cache active, which keeps pages and handles CSS and JS there.
     *
     * @return bool
     */
    public static function replaces_wp_optimize() {
        return isset(self::active_plugins()['litespeed-cache']) && self::litespeed_server();
    }

    /**
     * Whether the site runs on a LiteSpeed server. SEOProStack_Litespeed
     * also remembers the answer for WP-CLI; without it, the server's
     * variables are read as LiteSpeed Cache reads them.
     *
     * @return bool
     */
    private static function litespeed_server() {
        if (class_exists('SEOProStack_Litespeed') && is_callable(array('SEOProStack_Litespeed', 'is_server'))) {
            return (bool) SEOProStack_Litespeed::is_server();
        }
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) wp_unslash($_SERVER['SERVER_SOFTWARE']) : '';
        $edition  = isset($_SERVER['LSWS_EDITION']) ? (string) wp_unslash($_SERVER['LSWS_EDITION']) : '';
        // phpcs:enable
        return !empty($_SERVER['HTTP_X_LSCACHE']) || 0 === stripos($software, 'litespeed') || 0 === stripos($edition, 'openlitespeed');
    }

    /**
     * Import WP-Optimize's scheduled cleanup choices. The switch comes on only
     * where this replaces WP-Optimize; elsewhere WP-Optimize keeps its schedule.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if ('true' !== get_option('wp-optimize-schedule', null)) {
            return $options;
        }
        $auto = get_option('wp-optimize-auto', null);
        if (!is_array($auto)) {
            return $options;
        }
        $on  = function ($name) use ($auto) {
            return isset($auto[$name]) && 'true' === (string) $auto[$name];
        };
        $map = array(
            'transients'    => $on('transient'),
            'trash'         => $on('trash'),
            'spam'          => $on('spams'),
            'auto_drafts'   => $on('drafts'),
            'orphaned_meta' => $on('postmeta') || $on('commentmeta'),
        );
        $items = array_keys(array_filter($map));
        if (!$items && !$on('optimize')) {
            return $options;
        }
        if (self::replaces_wp_optimize()) {
            $options = self::import_setting($options, self::KEY, true);
        }
        $options = self::import_setting($options, self::ITEMS_KEY, $items);
        $options = self::import_setting($options, self::OPTIMIZE_KEY, $on('optimize'));
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 1);
        if (is_admin()) {
            // Also when switched off or waiting, to take the schedule away.
            add_action('admin_init', array(__CLASS__, 'sync_schedule'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'clean_now'));
            add_action('admin_post_' . self::PUT_BACK, array(__CLASS__, 'put_back'));
        }
        if (self::switched_on()) {
            // Which scheduled tasks have code, seen as cron runs them: on
            // web cron requests, which load every plugin (WP-CLI may not run
            // what hosts add to web requests). Sites whose cron runs from
            // WP-CLI are checked by a request the admin asks for.
            if (wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
                add_action('shutdown', array(__CLASS__, 'cron_observe'));
            } elseif (is_admin() && !wp_doing_ajax()) {
                add_action('admin_init', array(__CLASS__, 'cron_ask'));
            }
        }
        // Can be used before switching on, to see what would go.
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack clean-database', array(__CLASS__, 'cli'));
        }
        // The rest of a cleanup that stopped at its time limit, including a
        // Clean now made while the switch is off. Scheduled only then.
        add_action(self::MORE, array(__CLASS__, 'run_scheduled'));
        if (!self::enabled()) {
            return;
        }
        add_action(self::HOOK, array(__CLASS__, 'run_scheduled'));
    }

    /* --------------------------------------------------------------------- */
    /* Schedule                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Keep the weekly event in step with the switch (and with WP-Optimize
     * being active, which makes it wait).
     */
    public static function sync_schedule() {
        $scheduled = wp_next_scheduled(self::HOOK);
        if (self::enabled()) {
            if (!$scheduled) {
                wp_schedule_event(self::quiet_time(), 'weekly', self::HOOK);
            }
            return;
        }
        if ($scheduled || wp_next_scheduled(self::MORE)) {
            wp_clear_scheduled_hook(self::HOOK);
            wp_clear_scheduled_hook(self::MORE);
        }
    }

    /**
     * A setting changed: update the schedule, and the panel's counts.
     *
     * @param string $key Setting key.
     */
    public static function setting_saved($key) {
        if (0 !== strpos((string) $key, self::KEY)) {
            return;
        }
        delete_transient(self::WAITING);
        self::sync_schedule();
    }

    /**
     * 3am tomorrow, site time: few visitors, and away from midnight jobs.
     *
     * @return int
     */
    private static function quiet_time() {
        try {
            $time = new DateTimeImmutable('tomorrow 03:00', wp_timezone());
            return $time->getTimestamp();
        } catch (Exception $e) {
            return time() + DAY_IN_SECONDS;
        }
    }

    /**
     * Weekly event, or the next batch.
     */
    public static function run_scheduled() {
        self::run(false, self::BUDGET);
    }

    /* --------------------------------------------------------------------- */
    /* Cleanup                                                                */
    /* --------------------------------------------------------------------- */

    // phpcs:disable WordPress.DB.DirectDatabaseQuery -- finds and removes leftover rows; the panel's counts are cached in a transient.

    /**
     * Clean (or count) everything chosen.
     *
     * @param bool $dry    Only count what would go.
     * @param int  $budget Seconds to stop after (0 for no limit).
     * @return array{counts: array<string,int>, tables: string[], done: bool}
     */
    public static function run($dry, $budget = 0) {
        $start  = microtime(true);
        $items  = (array) SEOProStack_Settings::get(self::ITEMS_KEY);
        $counts = array_fill_keys(array_keys(self::item_options()), 0);
        $done   = true;

        foreach (array_keys($counts) as $item) {
            if (!in_array($item, $items, true)) {
                continue;
            }
            if ($dry) {
                $counts[$item] = self::count($item);
                continue;
            }
            $left = $budget > 0 ? $budget - (microtime(true) - $start) : 0;
            if ($budget > 0 && $left <= 0) {
                $done = false;
                break;
            }
            $result        = self::clean($item, $budget > 0 ? $left : 0);
            $counts[$item] = $result['removed'];
            if (!$result['done']) {
                $done = false;
                break;
            }
        }

        $tables = array();
        if ($done && SEOProStack_Settings::get(self::OPTIMIZE_KEY)) {
            $tables = self::tables_to_optimize();
            if (!$dry) {
                foreach ($tables as $i => $table) {
                    if ($budget > 0 && microtime(true) - $start > $budget) {
                        $tables = array_slice($tables, 0, $i);
                        $done   = false;
                        break;
                    }
                    self::optimize($table);
                }
            }
        }

        if (!$dry) {
            self::record($counts, $tables, $done);
            delete_transient(self::WAITING);
            if ($done) {
                wp_clear_scheduled_hook(self::MORE);
            } elseif (!wp_next_scheduled(self::MORE)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::MORE);
            }
        }

        return array('counts' => $counts, 'tables' => $tables, 'done' => $done);
    }

    /**
     * Remember the last run, adding to it while a cleanup goes on in batches.
     *
     * @param array<string,int> $counts Removed per item.
     * @param string[]          $tables Tables optimised.
     * @param bool              $done   Whether it finished.
     */
    private static function record(array $counts, array $tables, $done) {
        $last = get_option(self::LAST, array());
        if (is_array($last) && empty($last['done']) && isset($last['counts']) && is_array($last['counts'])) {
            foreach ($last['counts'] as $item => $count) {
                if (isset($counts[$item])) {
                    $counts[$item] += (int) $count;
                }
            }
            $tables = array_values(array_unique(array_merge(isset($last['tables']) ? (array) $last['tables'] : array(), $tables)));
        }
        update_option(self::LAST, array(
            'time'   => time(),
            'counts' => $counts,
            'tables' => $tables,
            'done'   => (bool) $done,
        ), false);
    }

    /**
     * How many of an item are waiting.
     *
     * @param string $item Item key.
     * @return int
     */
    public static function count($item) {
        global $wpdb;
        switch ($item) {
            case 'transients':
                // Also with an object cache: rows stored before it came are still here.
                $total = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
                    $wpdb->esc_like('_transient_timeout_') . '%',
                    $wpdb->esc_like('_site_transient_timeout_') . '%',
                    time()
                ));
                if (is_multisite() && is_main_site() && is_main_network()) {
                    $total += (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s AND meta_value < %d",
                        $wpdb->esc_like('_site_transient_timeout_') . '%',
                        time()
                    ));
                }
                return $total;
            case 'orphaned_meta':
                $total = 0;
                foreach (self::meta_tables() as $table) {
                    $total += (int) self::orphan_query($table, true);
                }
                return $total;
            case 'orphaned_cron':
                return count(self::cron_candidates());
            default:
                $total = 0;
                foreach (self::passes($item) as $pass) {
                    $total += (int) self::from_query($pass, true);
                }
                return $total;
        }
    }

    /**
     * Queries an item needs: the bin holds posts and comments.
     *
     * @param string $item Item key.
     * @return string[]
     */
    private static function passes($item) {
        if ('trash' === $item) {
            return array('trash', 'trash_comments');
        }
        return in_array($item, array('spam', 'auto_drafts'), true) ? array($item) : array();
    }

    /**
     * Whether a pass removes comments rather than posts.
     *
     * @param string $pass Pass from passes().
     * @return bool
     */
    private static function is_comment_pass($pass) {
        return in_array($pass, array('spam', 'trash_comments'), true);
    }

    /**
     * Remove one item's leftovers.
     *
     * @param string $item   Item key.
     * @param float  $budget Seconds left (0 for no limit).
     * @return array{removed: int, done: bool}
     */
    private static function clean($item, $budget) {
        global $wpdb;
        $start   = microtime(true);
        $removed = 0;
        $out     = function () use ($start, $budget) {
            return $budget > 0 && microtime(true) - $start > $budget;
        };

        if ('transients' === $item) {
            $before = self::count('transients');
            delete_expired_transients(true);
            return array('removed' => max(0, $before - self::count('transients')), 'done' => true);
        }

        if ('orphaned_cron' === $item) {
            return array('removed' => self::cron_remove(), 'done' => true);
        }

        if ('orphaned_meta' === $item) {
            foreach (self::meta_tables() as $table) {
                do {
                    $ids = array_map('intval', (array) self::orphan_query($table, false));
                    if ($ids) {
                        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only generated %d placeholders; every ID and the table name are prepared.
                        $removed += (int) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE meta_id IN ($placeholders)", array_merge(array($table['meta']), $ids)));
                    }
                    if ($out()) {
                        return array('removed' => $removed, 'done' => false);
                    }
                } while (count($ids) === self::META_BATCH);
            }
            return array('removed' => $removed, 'done' => true);
        }

        foreach (self::passes($item) as $pass) {
            $comments = self::is_comment_pass($pass);
            do {
                $ids    = array_map('intval', (array) self::from_query($pass, false));
                $before = $removed;
                foreach ($ids as $id) {
                    $gone = $comments ? wp_delete_comment($id, true) : wp_delete_post($id, true);
                    if ($gone) {
                        $removed++;
                    }
                    if ($out()) {
                        return array('removed' => $removed, 'done' => false);
                    }
                }
                // Stop if nothing in a full batch could be removed (another plugin refused).
            } while (count($ids) === self::BATCH && $removed > $before);
        }
        return array('removed' => $removed, 'done' => true);
    }

    /**
     * Count a pass or select its next batch with a complete prepared query.
     *
     * @param string $pass  Pass from passes().
     * @param bool   $count Count rows rather than select a batch of IDs.
     * @return int|string[]
     */
    private static function from_query($pass, $count) {
        global $wpdb;
        $bin = time() - self::bin_days() * DAY_IN_SECONDS;
        switch ($pass) {
            case 'trash':
                if ($count) {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time' WHERE p.post_status = 'trash' AND CAST(m.meta_value AS UNSIGNED) < %d",
                        $wpdb->posts, $wpdb->postmeta, $bin
                    ));
                }
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT p.ID FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time' WHERE p.post_status = 'trash' AND CAST(m.meta_value AS UNSIGNED) < %d LIMIT %d",
                    $wpdb->posts, $wpdb->postmeta, $bin, self::BATCH
                ));
            case 'trash_comments':
                if ($count) {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM %i c INNER JOIN %i m ON m.comment_id = c.comment_ID AND m.meta_key = '_wp_trash_meta_time' WHERE c.comment_approved = 'trash' AND CAST(m.meta_value AS UNSIGNED) < %d",
                        $wpdb->comments, $wpdb->commentmeta, $bin
                    ));
                }
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT c.comment_ID FROM %i c INNER JOIN %i m ON m.comment_id = c.comment_ID AND m.meta_key = '_wp_trash_meta_time' WHERE c.comment_approved = 'trash' AND CAST(m.meta_value AS UNSIGNED) < %d LIMIT %d",
                    $wpdb->comments, $wpdb->commentmeta, $bin, self::BATCH
                ));
            case 'spam':
                if ($count) {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM %i c WHERE c.comment_approved = 'spam' AND c.comment_date_gmt < %s",
                        $wpdb->comments, gmdate('Y-m-d H:i:s', $bin)
                    ));
                }
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT c.comment_ID FROM %i c WHERE c.comment_approved = 'spam' AND c.comment_date_gmt < %s LIMIT %d",
                    $wpdb->comments, gmdate('Y-m-d H:i:s', $bin), self::BATCH
                ));
            default:
                // auto_drafts, as wp_delete_auto_drafts(): post_date, in site time.
                $before = wp_date('Y-m-d H:i:s', time() - self::AUTO_DRAFT_DAYS * DAY_IN_SECONDS);
                if ($count) {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM %i p WHERE p.post_status = 'auto-draft' AND p.post_date < %s",
                        $wpdb->posts, $before
                    ));
                }
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT p.ID FROM %i p WHERE p.post_status = 'auto-draft' AND p.post_date < %s LIMIT %d",
                    $wpdb->posts, $before, self::BATCH
                ));
        }
    }

    /**
     * Meta tables and the tables their rows belong to.
     *
     * @return array<int,array{meta: string, column: string, parent: string, id: string}>
     */
    private static function meta_tables() {
        global $wpdb;
        return array(
            array('meta' => $wpdb->postmeta, 'column' => 'post_id', 'parent' => $wpdb->posts, 'id' => 'ID'),
            array('meta' => $wpdb->commentmeta, 'column' => 'comment_id', 'parent' => $wpdb->comments, 'id' => 'comment_ID'),
            array('meta' => $wpdb->termmeta, 'column' => 'term_id', 'parent' => $wpdb->terms, 'id' => 'term_id'),
        );
    }

    /**
     * Meta rows whose post, comment or term is gone. Rows for ID 0 were never
     * attached to anything, and are left alone.
     *
     * @param array $table From meta_tables().
     * @param bool  $count Count rows rather than select a batch of IDs.
     * @return int|string[]
     */
    private static function orphan_query(array $table, $count) {
        global $wpdb;
        if ($count) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i m LEFT JOIN %i o ON o.%i = m.%i WHERE m.%i > 0 AND o.%i IS NULL',
                $table['meta'], $table['parent'], $table['id'], $table['column'], $table['column'], $table['id']
            ));
        }
        return $wpdb->get_col($wpdb->prepare(
            'SELECT m.meta_id FROM %i m LEFT JOIN %i o ON o.%i = m.%i WHERE m.%i > 0 AND o.%i IS NULL LIMIT %d',
            $table['meta'], $table['parent'], $table['id'], $table['column'], $table['column'], $table['id'], self::META_BATCH
        ));
    }

    /**
     * This site's tables with enough free space to be worth optimising.
     *
     * @return string[]
     */
    public static function tables_to_optimize() {
        global $wpdb;
        $rows   = (array) $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'), ARRAY_A);
        $tables = array();
        foreach ($rows as $row) {
            $engine = isset($row['Engine']) ? strtolower((string) $row['Engine']) : '';
            $free   = isset($row['Data_free']) ? (int) $row['Data_free'] : 0;
            $size   = (isset($row['Data_length']) ? (int) $row['Data_length'] : 0) + (isset($row['Index_length']) ? (int) $row['Index_length'] : 0);
            if (in_array($engine, array('innodb', 'myisam', 'aria'), true) && $free >= self::FREE_MIN && $free * 10 >= $size) {
                $tables[] = (string) $row['Name'];
            }
        }
        return $tables;
    }

    /**
     * Optimise one table.
     *
     * @param string $table Table name, from SHOW TABLE STATUS.
     */
    private static function optimize($table) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('OPTIMIZE TABLE %i', $table));
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery

    /* --------------------------------------------------------------------- */
    /* Scheduled tasks of plugins that are gone                               */
    /* --------------------------------------------------------------------- */

    /**
     * Hooks of this site's scheduled tasks.
     *
     * @return string[]
     */
    private static function cron_hooks() {
        $hooks = array();
        foreach ((array) _get_cron_array() as $events) {
            if (is_array($events)) {
                foreach (array_keys($events) as $hook) {
                    $hooks[(string) $hook] = true;
                }
            }
        }
        return array_keys($hooks);
    }

    /**
     * What the checks found.
     *
     * @return array{checked: int, hooks: array<string,int[]>, keep: string[]}
     */
    private static function cron_seen() {
        $seen = get_option(self::CRON_SEEN, array());
        $seen = is_array($seen) ? $seen : array();
        return array(
            'checked' => isset($seen['checked']) ? (int) $seen['checked'] : 0,
            'hooks'   => isset($seen['hooks']) && is_array($seen['hooks']) ? $seen['hooks'] : array(),
            'keep'    => isset($seen['keep']) && is_array($seen['keep']) ? array_values(array_map('strval', $seen['keep'])) : array(),
        );
    }

    /**
     * At the end of a web cron request (every plugin loaded), at most hourly:
     * note each scheduled task with no code to run it. A task seen with code
     * starts again from nothing.
     */
    public static function cron_observe() {
        $seen = self::cron_seen();
        $now  = time();
        if ($now - $seen['checked'] < HOUR_IN_SECONDS) {
            return;
        }
        $hooks = array();
        foreach (self::cron_hooks() as $hook) {
            if (has_action($hook) || self::cron_live($hook)) {
                continue;
            }
            $first        = isset($seen['hooks'][$hook][0]) ? (int) $seen['hooks'][$hook][0] : $now;
            $hooks[$hook] = array($first, $now);
        }
        update_option(self::CRON_SEEN, array('checked' => $now, 'hooks' => $hooks, 'keep' => $seen['keep']), false);
    }

    /**
     * From an admin screen, when no check ran for 12 hours (cron runs from
     * WP-CLI, or seldom): ask wp-cron.php for one in the background, as
     * WordPress starts cron. Its lock value matches no lock, so wp-cron.php
     * loads WordPress and runs no tasks; cron_observe() checks at its end.
     */
    public static function cron_ask() {
        $seen = self::cron_seen();
        if (time() - $seen['checked'] < 12 * HOUR_IN_SECONDS || get_transient(self::CRON_ASKED)) {
            return;
        }
        set_transient(self::CRON_ASKED, 1, HOUR_IN_SECONDS);
        wp_remote_get(add_query_arg('doing_wp_cron', self::CRON_CHECK, site_url('wp-cron.php')), array(
            'timeout'   => 0.01,
            'blocking'  => false,
            // Core's filter for requests to the site itself.
            'sslverify' => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, as spawn_cron() uses.
        ));
    }

    /**
     * Whether a hook is never removed: WordPress's own, hosts' agents', SEO
     * Pro Stack's, one put back, one whose name starts with the folder, main
     * file or text domain of an installed plugin, active or not (it may add
     * its code again, and reactivating it must still work), or one whose
     * name's first part other code in this request uses (cron_live()).
     *
     * @param string   $hook Hook.
     * @param string[] $keep Hooks put back.
     * @return bool
     */
    private static function cron_protected($hook, array $keep) {
        if (0 === strpos($hook, 'wp_') || 0 === strpos($hook, 'seoprostack_') || in_array($hook, self::CORE_CRON, true) || in_array($hook, $keep, true)) {
            return true;
        }
        foreach (self::HOST_CRON as $prefix) {
            if (0 === strpos($hook, $prefix)) {
                return true;
            }
        }
        $name = self::cron_name($hook);
        foreach (self::installed_prefixes() as $prefix) {
            if (0 === strpos($name, $prefix)) {
                return true;
            }
        }
        return self::cron_live($hook);
    }

    /**
     * A name compared with plugin folders and other hooks: lower case, with
     * - and / as _.
     *
     * @param string $name Hook or folder.
     * @return string
     */
    private static function cron_name($name) {
        return str_replace(array('-', '/'), '_', strtolower($name));
    }

    /**
     * The first part of a hook's name, with its separator (rank_math/... and
     * burst_... give rank_ and burst_), or '' when shorter than 3 characters.
     *
     * @param string $name Hook.
     * @return string
     */
    private static function cron_namespace($name) {
        return preg_match('/^([a-z0-9]{3,})_/', self::cron_name($name), $match) ? $match[1] . '_' : '';
    }

    /**
     * Whether another hook with code in this request starts the same way:
     * the plugin that owns the name is still running, so a task of its that
     * nothing runs (a module turned off, say) is left alone. Folders do not
     * always match hooks: Rank Math's folder is seo-by-rank-math, its tasks
     * rank_math/....
     *
     * @param string $hook Hook.
     * @return bool
     */
    private static function cron_live($hook) {
        static $live = null;
        if (null === $live) {
            global $wp_filter;
            $live = array();
            foreach (array_keys((array) $wp_filter) as $name) {
                $space = self::cron_namespace((string) $name);
                if ('' !== $space && !isset($live[$space]) && has_filter($name)) {
                    $live[$space] = true;
                }
            }
        }
        $space = self::cron_namespace($hook);
        return '' !== $space && isset($live[$space]);
    }

    /**
     * Folders, main files and text domains of installed plugins, and their
     * first parts, as compared with hooks: LiteSpeed Cache's tasks are
     * litespeed_task_..., not litespeed_cache_.... Three letters or more, so
     * short names do not match everything.
     *
     * @return string[]
     */
    private static function installed_prefixes() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $prefixes = array();
        foreach ((array) get_plugins() as $file => $data) {
            $folder = dirname($file);
            $names  = array('.' === $folder ? '' : $folder, basename($file, '.php'), isset($data['TextDomain']) ? (string) $data['TextDomain'] : '');
            foreach ($names as $name) {
                if (strlen($name) >= 3) {
                    $prefixes[] = self::cron_name($name);
                    $prefixes[] = self::cron_namespace($name);
                }
            }
        }
        return array_values(array_filter(array_unique($prefixes)));
    }

    /**
     * Tasks to remove: no code on checks at least CRON_DAYS apart, the last
     * one recent, none in this request either, and not protected.
     *
     * @return string[]
     */
    public static function cron_candidates() {
        $seen = self::cron_seen();
        if (!$seen['hooks'] || time() - $seen['checked'] > self::CRON_FRESH) {
            return array();
        }
        $current    = array_flip(self::cron_hooks());
        $candidates = array();
        foreach ($seen['hooks'] as $hook => $times) {
            $hook = (string) $hook;
            if (!isset($current[$hook]) || !is_array($times) || count($times) < 2) {
                continue;
            }
            if ((int) $times[1] - (int) $times[0] < self::CRON_DAYS * DAY_IN_SECONDS) {
                continue;
            }
            if (has_action($hook) || self::cron_protected($hook, $seen['keep'])) {
                continue;
            }
            $candidates[] = $hook;
        }
        sort($candidates);
        return $candidates;
    }

    /**
     * The recommended plugin a hook most likely came from, by its folder on
     * WordPress.org (installed plugins' hooks are never removed).
     *
     * @param string $hook Hook.
     * @return string Folder, or '' when unknown.
     */
    public static function cron_plugin($hook) {
        if (!function_exists('seoprostack_get_free_plugins')) {
            return '';
        }
        $name = self::cron_name($hook);
        foreach (seoprostack_get_free_plugins() as $slugs) {
            foreach ((array) $slugs as $slug) {
                if (strlen((string) $slug) >= 3 && 0 === strpos($name, self::cron_name((string) $slug))) {
                    return (string) $slug;
                }
            }
        }
        return '';
    }

    /**
     * Remove the candidates' tasks, keeping them as stored for Put back.
     *
     * @return int Hooks removed.
     */
    private static function cron_remove() {
        $hooks = self::cron_candidates();
        if (!$hooks) {
            return 0;
        }
        $wanted = array_flip($hooks);
        $events = array();
        foreach ((array) _get_cron_array() as $time => $by_hook) {
            foreach ((array) $by_hook as $hook => $keyed) {
                if (isset($wanted[$hook])) {
                    foreach ((array) $keyed as $key => $event) {
                        $events[] = array('time' => (int) $time, 'hook' => (string) $hook, 'key' => (string) $key, 'event' => $event);
                    }
                }
            }
        }
        foreach ($hooks as $hook) {
            wp_unschedule_hook($hook);
        }
        update_option(self::CRON_REMOVED, array('time' => time(), 'events' => $events), false);
        $seen = self::cron_seen();
        update_option(self::CRON_SEEN, array('checked' => $seen['checked'], 'hooks' => array_diff_key($seen['hooks'], $wanted), 'keep' => $seen['keep']), false);
        return count($hooks);
    }

    /**
     * Put back the tasks the last cleanup removed, exactly as they were
     * stored (their plugin's own schedule may no longer exist), and never
     * remove them again.
     *
     * @return int Tasks put back.
     */
    public static function cron_put_back() {
        $removed = get_option(self::CRON_REMOVED, array());
        if (!is_array($removed) || empty($removed['events']) || !is_array($removed['events'])) {
            return 0;
        }
        $crons = (array) _get_cron_array();
        $count = 0;
        $hooks = array();
        foreach ($removed['events'] as $item) {
            if (!is_array($item) || empty($item['hook']) || !isset($item['key'], $item['event']) || !is_array($item['event'])) {
                continue;
            }
            // A task whose time passed runs at the next cron run, as WordPress does.
            $time = isset($item['time']) ? (int) $item['time'] : time();
            $hook = (string) $item['hook'];
            $key  = (string) $item['key'];
            if (!isset($crons[$time][$hook][$key])) {
                // The event as core stores it: schedule, args and, if it repeats, interval.
                $event = array(
                    'schedule' => isset($item['event']['schedule']) && is_string($item['event']['schedule']) ? $item['event']['schedule'] : false,
                    'args'     => isset($item['event']['args']) && is_array($item['event']['args']) ? $item['event']['args'] : array(),
                );
                if (isset($item['event']['interval'])) {
                    $event['interval'] = max(0, (int) $item['event']['interval']);
                }
                $crons[$time][$hook][$key] = $event;
                $count++;
            }
            $hooks[] = (string) $item['hook'];
        }
        ksort($crons);
        _set_cron_array($crons);
        delete_option(self::CRON_REMOVED);
        $seen = self::cron_seen();
        update_option(self::CRON_SEEN, array('checked' => $seen['checked'], 'hooks' => $seen['hooks'], 'keep' => array_values(array_unique(array_merge($seen['keep'], $hooks)))), false);
        delete_transient(self::WAITING);
        return $count;
    }

    /* --------------------------------------------------------------------- */
    /* Admin                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * What is waiting, cached for an hour so the settings screen stays quick.
     *
     * @return array{counts: array<string,int>, tables: string[]}
     */
    private static function waiting() {
        $waiting = get_transient(self::WAITING);
        // Use the cached counts only when every value is plain; recount otherwise.
        if (is_array($waiting) && isset($waiting['counts'], $waiting['tables']) && is_array($waiting['counts']) && is_array($waiting['tables'])
            && count(array_filter($waiting['counts'], 'is_scalar')) === count($waiting['counts'])
            && count(array_filter($waiting['tables'], 'is_scalar')) === count($waiting['tables'])) {
            $counts = array();
            foreach ($waiting['counts'] as $kind => $count) {
                $counts[(string) $kind] = (int) $count;
            }
            return array('counts' => $counts, 'tables' => array_map('strval', array_values($waiting['tables'])));
        }
        $run     = self::run(true);
        $waiting = array('counts' => $run['counts'], 'tables' => $run['tables']);
        set_transient(self::WAITING, $waiting, HOUR_IN_SECONDS);
        return $waiting;
    }

    /**
     * What is waiting and the last cleanup, with Clean now, in the panel.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel($key, $field = array()) {
        if (self::KEY !== $key || !SEOProStack_Settings::can_change()) {
            return;
        }
        $labels  = self::item_options();
        $waiting = self::waiting();
        $parts   = array();
        foreach ($waiting['counts'] as $item => $count) {
            if ($count > 0 && isset($labels[$item])) {
                $parts[] = sprintf('%1$s: %2$s', $labels[$item], number_format_i18n($count));
            }
        }
        if ($waiting['tables']) {
            /* translators: %s: number of tables */
            $parts[] = sprintf(_n('%s table to optimise', '%s tables to optimise', count($waiting['tables']), 'seoprostack'), number_format_i18n(count($waiting['tables'])));
        }

        echo '<div class="sps-panel-note">';
        if ($parts) {
            echo '<p>' . esc_html__('Waiting to be removed:', 'seoprostack') . '</p><ul class="ul-disc">';
            foreach ($parts as $part) {
                echo '<li>' . esc_html($part) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>' . esc_html__('Nothing is waiting to be removed.', 'seoprostack') . '</p>';
        }
        self::cron_panel();

        $last = get_option(self::LAST, array());
        if (is_array($last) && !empty($last['time'])) {
            $removed = isset($last['counts']) ? array_sum(array_map('intval', (array) $last['counts'])) : 0;
            $text    = sprintf(
                /* translators: 1: date and time, 2: number of items */
                _n('Last cleanup %1$s: %2$s item removed.', 'Last cleanup %1$s: %2$s items removed.', $removed, 'seoprostack'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['time']),
                number_format_i18n($removed)
            );
            if (!empty($last['tables'])) {
                /* translators: %s: number of tables */
                $text .= ' ' . sprintf(_n('%s table optimised.', '%s tables optimised.', count((array) $last['tables']), 'seoprostack'), number_format_i18n(count((array) $last['tables'])));
            }
            if (empty($last['done'])) {
                $text .= ' ' . __('The rest is removed in the background.', 'seoprostack');
            }
            echo '<p>' . esc_html($text) . '</p>';
        }

        $next = wp_next_scheduled(self::HOOK);
        if ($next && self::enabled()) {
            /* translators: %s: date and time */
            echo '<p>' . esc_html(sprintf(__('Next cleanup: %s.', 'seoprostack'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next))) . '</p>';
        }

        if ($parts) {
            printf(
                '<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s" />',
                esc_url(admin_url('admin-post.php')),
                esc_attr(self::ACTION)
            );
            wp_nonce_field(self::ACTION);
            printf('<p><button type="submit" class="button">%s</button></p></form>', esc_html__('Clean now', 'seoprostack'));
        }
        echo '</div>';
    }

    /**
     * Scheduled tasks in the panel, when chosen: the ones to be removed,
     * when the last check ran, and the last removal with Put back.
     */
    private static function cron_panel() {
        if (!in_array('orphaned_cron', (array) SEOProStack_Settings::get(self::ITEMS_KEY), true)) {
            return;
        }
        $candidates = self::cron_candidates();
        if ($candidates) {
            echo '<p>' . esc_html__('Scheduled tasks to be removed (nothing runs them):', 'seoprostack') . '</p><ul class="ul-disc">';
            foreach ($candidates as $hook) {
                $plugin = self::cron_plugin($hook);
                echo '<li><code>' . esc_html($hook) . '</code>';
                if ('' !== $plugin) {
                    /* translators: %s: plugin folder on WordPress.org */
                    echo ' ' . esc_html(sprintf(__('(probably %s)', 'seoprostack'), $plugin));
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        $seen = self::cron_seen();
        if ($seen['checked']) {
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: date and time, 2: number of days */
                __('Scheduled tasks last checked %1$s, as cron runs them. A task is removed once nothing has run it for %2$s days.', 'seoprostack'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $seen['checked']),
                number_format_i18n(self::CRON_DAYS)
            )) . '</p>';
        } else {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: number of days */
                __('Scheduled tasks are checked as cron runs them, hourly at most. A task is removed once nothing has run it for %s days.', 'seoprostack'),
                number_format_i18n(self::CRON_DAYS)
            )) . '</p>';
        }
        $removed = get_option(self::CRON_REMOVED, array());
        if (is_array($removed) && !empty($removed['events']) && is_array($removed['events'])) {
            $hooks = array_values(array_unique(array_filter(array_map(function ($item) {
                return is_array($item) && isset($item['hook']) ? (string) $item['hook'] : '';
            }, $removed['events']))));
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: date and time, 2: list of hooks */
                __('Removed %1$s: %2$s.', 'seoprostack'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), isset($removed['time']) ? (int) $removed['time'] : time()),
                implode(', ', $hooks)
            )) . '</p>';
            printf(
                '<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s" />',
                esc_url(admin_url('admin-post.php')),
                esc_attr(self::PUT_BACK)
            );
            wp_nonce_field(self::PUT_BACK);
            printf('<p><button type="submit" class="button">%1$s</button> %2$s</p></form>', esc_html__('Put back', 'seoprostack'), esc_html__('They are kept from then on.', 'seoprostack'));
        }
    }

    /**
     * Put back, from the panel.
     */
    public static function put_back() {
        if (!SEOProStack_Settings::can_change()) {
            wp_die(esc_html__('You cannot change these settings.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::PUT_BACK);
        self::cron_put_back();
        wp_safe_redirect(admin_url('options-general.php?page=seoprostack&tab=server'));
        exit;
    }

    /**
     * Clean now, from the panel.
     */
    public static function clean_now() {
        if (!SEOProStack_Settings::can_change()) {
            wp_die(esc_html__('You cannot change these settings.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        // What is left after the time limit is removed in the background.
        self::run(false, self::BUDGET);
        wp_safe_redirect(admin_url('options-general.php?page=seoprostack&tab=server'));
        exit;
    }

    /**
     * Stop the schedule when SEO Pro Stack is deactivated.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::MORE);
    }

    /* --------------------------------------------------------------------- */
    /* WP-CLI                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Remove expired transients, old bin contents and spam, old automatic
     * drafts, orphaned meta and scheduled tasks nothing runs, as chosen in
     * Clean the database weekly, and optimise tables if that is on. Works
     * while the switch is off too (scheduled tasks are only checked while it
     * is on). --dry-run lists the scheduled tasks it would remove.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Only count what would be removed.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack clean-database --dry-run
     *     wp seoprostack clean-database
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Options.
     */
    public static function cli($args, $assoc) {
        $dry   = !empty($assoc['dry-run']);
        $hooks = $dry && in_array('orphaned_cron', (array) SEOProStack_Settings::get(self::ITEMS_KEY), true) ? self::cron_candidates() : array();
        $run   = self::run($dry, 0);
        foreach ($run['counts'] as $item => $count) {
            WP_CLI::log(sprintf('%-14s %d', $item, $count));
        }
        foreach ($hooks as $hook) {
            $plugin = self::cron_plugin($hook);
            WP_CLI::log(sprintf('%-14s %s', 'task', $hook . ('' !== $plugin ? ' (probably ' . $plugin . ')' : '')));
        }
        foreach ($run['tables'] as $table) {
            WP_CLI::log(sprintf('%-14s %s', 'optimise', $table));
        }
        $total = array_sum($run['counts']);
        if ($dry) {
            WP_CLI::success(sprintf('%d items would be removed; %d tables would be optimised.', $total, count($run['tables'])));
            return;
        }
        WP_CLI::success(sprintf('%d items removed; %d tables optimised.', $total, count($run['tables'])));
    }
}
