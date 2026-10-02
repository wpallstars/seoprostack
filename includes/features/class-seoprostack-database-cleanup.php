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

    /** The plugin this replaces on LiteSpeed servers with LiteSpeed Cache. */
    const REPLACES = array('wp-optimize' => 'WP-Optimize');

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
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        $settings = array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'speed',
                'label'       => __('Clean the database weekly', 'seoprostack'),
                'description' => __('Once a week, in the background, remove what WordPress and plugins leave behind: expired temporary data, old spam and bin contents, old automatic drafts and details of deleted posts. Revisions are left to Limit post revisions.', 'seoprostack'),
                // Only where LiteSpeed Cache on a LiteSpeed server does WP-Optimize's other jobs (see below).
                'replaces'    => self::REPLACES,
            ),
            self::ITEMS_KEY => array(
                'type'    => 'multi',
                'default' => array_keys(self::item_options()),
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
                    $total += (int) $wpdb->get_var(self::orphan_query($table, 'COUNT(*)')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from $wpdb table names.
                }
                return $total;
            default:
                $total = 0;
                foreach (self::passes($item) as $pass) {
                    $total += (int) $wpdb->get_var('SELECT COUNT(*)' . self::from_query($pass)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in from_query().
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

        if ('orphaned_meta' === $item) {
            foreach (self::meta_tables() as $table) {
                do {
                    $ids = array_map('intval', (array) $wpdb->get_col(self::orphan_query($table, 'm.meta_id') . ' LIMIT ' . self::META_BATCH)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from $wpdb table names.
                    if ($ids) {
                        $removed += (int) $wpdb->query("DELETE FROM {$table['meta']} WHERE meta_id IN (" . implode(',', $ids) . ')'); // phpcs:ignore WordPress.DB.PreparedSQL -- table name and integer IDs.
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
            $query    = self::from_query($pass);
            do {
                $ids    = array_map('intval', (array) $wpdb->get_col('SELECT ' . ($comments ? 'c.comment_ID' : 'p.ID') . $query . ' LIMIT ' . self::BATCH)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in from_query().
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
     * The FROM and WHERE part of a pass's query, prepared; posts are "p",
     * comments "c".
     *
     * @param string $pass Pass from passes().
     * @return string
     */
    private static function from_query($pass) {
        global $wpdb;
        $bin = time() - self::bin_days() * DAY_IN_SECONDS;
        switch ($pass) {
            case 'trash':
                return $wpdb->prepare(
                    " FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time' WHERE p.post_status = 'trash' AND CAST(m.meta_value AS UNSIGNED) < %d",
                    $bin
                );
            case 'trash_comments':
                return $wpdb->prepare(
                    " FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = '_wp_trash_meta_time' WHERE c.comment_approved = 'trash' AND CAST(m.meta_value AS UNSIGNED) < %d",
                    $bin
                );
            case 'spam':
                return $wpdb->prepare(
                    " FROM {$wpdb->comments} c WHERE c.comment_approved = 'spam' AND c.comment_date_gmt < %s",
                    gmdate('Y-m-d H:i:s', $bin)
                );
            default:
                // auto_drafts, as wp_delete_auto_drafts(): post_date, in site time.
                return $wpdb->prepare(
                    " FROM {$wpdb->posts} p WHERE p.post_status = 'auto-draft' AND p.post_date < %s",
                    wp_date('Y-m-d H:i:s', time() - self::AUTO_DRAFT_DAYS * DAY_IN_SECONDS)
                );
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
     * @param array  $table  From meta_tables().
     * @param string $select Columns.
     * @return string
     */
    private static function orphan_query(array $table, $select) {
        return "SELECT {$select} FROM {$table['meta']} m LEFT JOIN {$table['parent']} o ON o.{$table['id']} = m.{$table['column']} WHERE m.{$table['column']} > 0 AND o.{$table['id']} IS NULL";
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
        $wpdb->query('OPTIMIZE TABLE `' . str_replace('`', '', $table) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a table name cannot be a placeholder.
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery

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
        if (!is_array($waiting) || !isset($waiting['counts'], $waiting['tables'])) {
            $run     = self::run(true);
            $waiting = array('counts' => $run['counts'], 'tables' => $run['tables']);
            set_transient(self::WAITING, $waiting, HOUR_IN_SECONDS);
        }
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
     * Clean now, from the panel.
     */
    public static function clean_now() {
        if (!SEOProStack_Settings::can_change()) {
            wp_die(esc_html__('You cannot change these settings.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        // What is left after the time limit is removed in the background.
        self::run(false, self::BUDGET);
        wp_safe_redirect(admin_url('options-general.php?page=seoprostack&tab=speed'));
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
     * drafts and orphaned meta, as chosen in Clean the database weekly, and
     * optimise tables if that is on. Works while the switch is off too.
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
        $dry = !empty($assoc['dry-run']);
        $run = self::run($dry, 0);
        foreach ($run['counts'] as $item => $count) {
            WP_CLI::log(sprintf('%-14s %d', $item, $count));
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
