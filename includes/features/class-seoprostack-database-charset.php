<?php
/**
 * Database character set: find this site's tables that cannot store emoji
 * and other 4-byte characters (the older utf8, or utf8mb3, character set)
 * and convert them to utf8mb4 when someone asks.
 *
 * WordPress converts its tables only in the 4.2 upgrade, so a site moved
 * later from an older database keeps utf8mb3 tables for good. Saving an
 * option, post field or comment with an emoji to one of them fails without
 * a message (GitHub issue #610). A Site Health test, always on, lists them.
 *
 * Converting keeps every row. Tables are converted to the collation
 * WordPress uses for new tables, so joins never meet "Illegal mix of
 * collations" (GitHub issue #550). Tables in other character sets (such as
 * latin1) are listed, never converted: they may hold text stored in the
 * wrong character set, which converting would garble. One conversion at a
 * time (an advisory lock, not waited for); a conversion never waits long
 * for a busy table; each is logged in LOG (not autoloaded).
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

class SEOProStack_Database_Charset extends SEOProStack_Feature {

    const KEY    = 'database_charset';
    const LOG    = 'seoprostack_database_charset_log';
    const PAGE   = 'seoprostack-database-charset';
    const ACTION = 'seoprostack_database_charset';

    /** Site Health test. */
    const TEST = 'seoprostack-database-charset';

    /** Notice after a redirect. */
    const DONE = 'sps_charset_done';

    /** Character sets converted to utf8mb4 without changing any text. */
    const CONVERTIBLE = array('utf8', 'utf8mb3', 'utf8mb4');

    /** Bytes above which a table is left to WP-CLI: a web request may time out. */
    const LARGE = 536870912;

    /** Seconds a conversion waits for a busy table before giving up. */
    const LOCK_WAIT = 15;

    /** Log entries kept. */
    const LOG_MAX = 100;

    /** Tables listed in Site Health before "and N more". */
    const HEALTH_MAX = 20;

    /**
     * This request's survey.
     *
     * @var array{target:string,tables:array<string,array{name:string,collation:string,rows:int,size:int,columns:array<string,string>,convert:bool}>}|WP_Error|null
     */
    private static $survey = null;

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
                'label'       => __('Database character set', 'seoprostack'),
                'description' => __('Converts database tables that cannot store emoji (the older utf8 character set) to utf8mb4, under Tools → Database character set, when you click. Saving settings, custom fields or comments with an emoji to those tables fails without a message. Back up the database first. Site Health lists those tables even while this is off.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // The test is always on: it only gives advice.
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack charset', array(__CLASS__, 'cli'));
        }
        if (!is_admin()) {
            return;
        }
        add_action('wp_ajax_health-check-' . self::TEST, array(__CLASS__, 'ajax_test'));
        if (!self::enabled()) {
            return;
        }
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Add the Tools page.
     */
    public static function menu() {
        if (self::allowed()) {
            add_management_page(__('Database character set', 'seoprostack'), __('Database character set', 'seoprostack'), 'manage_options', self::PAGE, array(__CLASS__, 'page'));
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
     * The notice argument goes from the address after it is shown.
     *
     * @param array $args Arguments.
     * @return array
     */
    public static function removable_query_args($args) {
        $args[] = self::DONE;
        return $args;
    }

    /**
     * Character set of a collation (utf8mb4_unicode_ci → utf8mb4).
     *
     * @param string $collation Collation.
     * @return string
     */
    private static function charset($collation) {
        return strtolower((string) strtok((string) $collation, '_'));
    }

    /**
     * Whether a collation name is a utf8mb4 one that is safe to write into SQL.
     *
     * @param string $collation Collation.
     * @return bool
     */
    private static function valid_target($collation) {
        return 1 === preg_match('/^utf8mb4_[a-z0-9_]+$/', (string) $collation);
    }

    /**
     * Collation to convert to: the one WordPress uses for new tables, else
     * the most common utf8mb4 one among the site's tables, so converted
     * tables compare text the same way as the rest.
     *
     * @param array<string,string> $collations Table => collation.
     * @return string
     */
    private static function target(array $collations) {
        global $wpdb;
        if (self::valid_target($wpdb->collate)) {
            return (string) $wpdb->collate;
        }
        $counts = array_count_values(array_filter($collations, array(__CLASS__, 'valid_target')));
        if ($counts) {
            arsort($counts);
            return (string) key($counts);
        }
        return $wpdb->has_cap('utf8mb4_520') ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
    }

    /**
     * Text columns of a table that are not utf8mb4: column => collation.
     *
     * @param string $table Table.
     * @return array<string,string>|false False when the columns cannot be read.
     */
    private static function old_columns($table) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- on-demand metadata; nothing cached.
        $columns = $wpdb->get_results($wpdb->prepare('SHOW FULL COLUMNS FROM %i', $table), ARRAY_A);
        if (!is_array($columns) || $wpdb->last_error) {
            return false;
        }
        $old = array();
        foreach ($columns as $column) {
            if (!empty($column['Collation']) && 'utf8mb4' !== self::charset($column['Collation'])) {
                $old[(string) $column['Field']] = (string) $column['Collation'];
            }
        }
        return $old;
    }

    /**
     * One table from SHOW TABLE STATUS, if it cannot store 4-byte characters.
     *
     * A table whose default is utf8mb4 can still have older columns: changing
     * only the default (ALTER TABLE … DEFAULT CHARSET) leaves them as they were.
     *
     * @param array<string,mixed> $row SHOW TABLE STATUS row.
     * @return array{name:string,collation:string,rows:int,size:int,columns:array<string,string>,convert:bool}|null
     */
    private static function inspect(array $row) {
        $collation = (string) $row['Collation'];
        $utf8mb4   = 'utf8mb4' === self::charset($collation);
        $columns   = self::old_columns((string) $row['Name']);
        if (false === $columns || ($utf8mb4 && !$columns)) {
            return null;
        }
        $charsets    = array_map(array(__CLASS__, 'charset'), array_merge(array($collation), array_values($columns)));
        $convertible = !array_diff($charsets, self::CONVERTIBLE);
        if (!$convertible && !$columns) {
            return null; // No text at all: nothing to garble or lose.
        }
        return array(
            'name'      => (string) $row['Name'],
            'collation' => $collation,
            'rows'      => (int) $row['Rows'],
            'size'      => (int) $row['Data_length'] + (int) $row['Index_length'],
            'columns'   => $columns,
            'convert'   => $convertible,
        );
    }

    /**
     * This site's tables that cannot store 4-byte characters.
     *
     * Read with SHOW TABLE STATUS and SHOW FULL COLUMNS: on a busy shared
     * server a query of information_schema.COLUMNS for every table timed
     * out. Reading one table's columns is quick, and this runs only for
     * Site Health (after its page loads), the Tools page and WP-CLI.
     *
     * @param bool $fresh Read again, not this request's copy.
     * @return array{target:string,tables:array<string,array{name:string,collation:string,rows:int,size:int,columns:array<string,string>,convert:bool}>}|WP_Error
     */
    public static function survey($fresh = false) {
        global $wpdb;
        if (null !== self::$survey && !$fresh) {
            return self::$survey;
        }
        if (!$wpdb->has_cap('utf8mb4')) {
            self::$survey = new WP_Error('no_utf8mb4', __('This database server cannot store emoji in any table. Ask your host for a newer MySQL or MariaDB.', 'seoprostack'));
            return self::$survey;
        }
        $previous = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- on-demand metadata; nothing cached.
        $status = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'), ARRAY_A);
        if (!is_array($status) || $wpdb->last_error) {
            $wpdb->suppress_errors($previous);
            self::$survey = new WP_Error('status', __('The database did not allow reading its tables.', 'seoprostack'));
            return self::$survey;
        }
        $collations = array();
        $tables     = array();
        foreach ($status as $row) {
            if (empty($row['Engine']) || empty($row['Collation'])) {
                continue; // A view, or a table the database could not open.
            }
            $name              = (string) $row['Name'];
            $collations[$name] = (string) $row['Collation'];
            $table             = self::inspect($row);
            if ($table) {
                $tables[$name] = $table;
            }
        }
        $wpdb->suppress_errors($previous);
        uasort($tables, function ($a, $b) {
            return $a['size'] <=> $b['size'];
        });
        self::$survey = array('target' => self::target($collations), 'tables' => $tables);
        return self::$survey;
    }

    /**
     * Why a table cannot be converted now, if it cannot.
     *
     * @param array  $survey Fresh survey.
     * @param string $table  Table.
     * @param int    $max    Largest size in bytes, or 0 for any.
     * @return WP_Error|null
     */
    private static function refusal(array $survey, $table, $max) {
        if (!isset($survey['tables'][$table]) || !$survey['tables'][$table]['convert']) {
            return new WP_Error('state', __('This table cannot be converted here, or is converted already. Look at the list again.', 'seoprostack'));
        }
        if ($max && $survey['tables'][$table]['size'] > $max) {
            /* translators: %s: WP-CLI command */
            return new WP_Error('large', sprintf(__('Large table: convert it with WP-CLI, as a web request may time out: %s', 'seoprostack'), 'wp seoprostack charset convert ' . $table));
        }
        return null;
    }

    /**
     * Convert one table to utf8mb4, after checking it again.
     *
     * @param string $table Table.
     * @param int    $max   Largest size in bytes to convert, or 0 for any (WP-CLI).
     * @return true|WP_Error
     */
    public static function convert($table, $max = 0) {
        global $wpdb;
        $lock = 'sps_charset_' . substr(hash('sha256', DB_NAME), 0, 30);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock, not stored state.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return new WP_Error('busy', __('Another table is being converted. Nothing was changed.', 'seoprostack'));
        }
        try {
            $survey = self::survey(true);
            if (is_wp_error($survey)) {
                return $survey;
            }
            $refusal = self::refusal($survey, $table, (int) $max);
            if ($refusal) {
                return $refusal;
            }
            $target = $survey['target'];
            $from   = $survey['tables'][$table]['collation'];
            // A large table takes a while; finish it even if the browser stops waiting.
            if (function_exists('set_time_limit')) {
                set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- converting a large table.
            }
            ignore_user_abort(true);
            $entry    = self::log($table, $from, $target, 'pending', 0);
            $previous = $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this connection's own setting.
            $wait = $wpdb->get_var('SELECT @@SESSION.lock_wait_timeout');
            // Never queue behind a long query on the table: every later query would queue too.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this connection's own setting.
            $wpdb->query($wpdb->prepare('SET SESSION lock_wait_timeout = %d', self::LOCK_WAIT));
            $start = microtime(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- confirmed DDL; table through %i (WordPress 6.2); $target matches valid_target().
            $ok      = $wpdb->query($wpdb->prepare('ALTER TABLE %i CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . $target, $table));
            $error   = $wpdb->last_error;
            $seconds = (int) round(microtime(true) - $start);
            if (null !== $wait) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this connection's own setting.
                $wpdb->query($wpdb->prepare('SET SESSION lock_wait_timeout = %d', (int) $wait));
            }
            $wpdb->suppress_errors($previous);
            self::log($table, $from, $target, false === $ok ? 'failed: ' . substr($error, 0, 200) : 'done', $seconds, $entry);
            if (false === $ok) {
                /* translators: %s: database error */
                return new WP_Error('ddl', sprintf(__('The database refused the change, so nothing changed: %s', 'seoprostack'), $error));
            }
            self::$survey = null;
            return true;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release our connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Tables "Convert all" takes in a web request: the convertible ones up
     * to LARGE, smallest first.
     *
     * @param array $survey Survey.
     * @return string[]
     */
    private static function batch(array $survey) {
        $names = array();
        foreach ($survey['tables'] as $name => $table) {
            if ($table['convert'] && $table['size'] <= self::LARGE) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Add or update a log entry.
     *
     * @param string   $table   Table.
     * @param string   $from    Collation before.
     * @param string   $to      Collation after.
     * @param string   $result  pending, done or failed: ….
     * @param int      $seconds Time taken.
     * @param int|null $entry   Entry to update.
     * @return int Entry ID.
     */
    private static function log($table, $from, $to, $result, $seconds, $entry = null) {
        $log = get_option(self::LOG, array());
        $log = is_array($log) ? $log : array();
        $id  = null === $entry ? (int) (microtime(true) * 1000) : $entry;
        $log[$id] = array('time' => time(), 'user' => get_current_user_id(), 'table' => $table, 'from' => $from, 'to' => $to, 'result' => $result, 'seconds' => $seconds);
        update_option(self::LOG, array_slice($log, -self::LOG_MAX, null, true), false);
        return $id;
    }

    /**
     * Convert one table, or every one up to LARGE.
     */
    public static function handle() {
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $table = isset($_POST['table']) && is_string($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';
        if ('' === $table) {
            $survey = self::survey(true);
            $names  = is_wp_error($survey) ? array() : self::batch($survey);
        } else {
            $names = array($table);
        }
        $done = 0;
        foreach ($names as $name) {
            $result = self::convert($name, self::LARGE);
            if (is_wp_error($result)) {
                $message = $name . ': ' . $result->get_error_message();
                if ($done) {
                    /* translators: 1: error; 2: number of tables converted before it */
                    $message = sprintf(_n('%1$s The %2$d table before it was converted.', '%1$s The %2$d tables before it were converted.', $done, 'seoprostack'), $message, $done);
                }
                wp_die(esc_html($message), '', array('response' => 409, 'back_link' => true));
            }
            $done++;
        }
        wp_safe_redirect(add_query_arg(self::DONE, $done, admin_url('tools.php?page=' . self::PAGE)));
        exit;
    }

    /**
     * A Convert button.
     *
     * @param string $table Table, or '' for every one up to LARGE.
     * @param string $label Button text.
     */
    private static function button($table, $label) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="table" value="' . esc_attr($table) . '">';
        submit_button($label, '' === $table ? 'primary' : 'secondary small', 'submit', false);
        echo '</form>';
    }

    /**
     * The Tools page.
     */
    public static function page() {
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'));
        }
        echo '<div class="wrap"><h1>' . esc_html__('Database character set', 'seoprostack') . '</h1>';
        if (isset($_GET[self::DONE])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect.
            $count = absint($_GET[self::DONE]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect.
            /* translators: %d: number of tables */
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d table converted.', '%d tables converted.', $count, 'seoprostack'), $count)) . '</p></div>';
        }
        echo '<p>' . esc_html__('Tables in the older utf8 (utf8mb3) character set cannot store emoji and some other characters. WordPress and plugins then fail to save settings, custom fields or comments that contain them, without a message. Converting a table to utf8mb4 keeps every row and lets it store them; WordPress’s own upgrade does the same. Back up the database first. While a table converts, the database holds back changes to it (reading goes on), for about a minute per 250 MB on a busy server, so choose a quiet time.', 'seoprostack') . '</p>';
        $survey = self::survey();
        if (is_wp_error($survey)) {
            echo '<p>' . esc_html($survey->get_error_message()) . '</p></div>';
            return;
        }
        $convert = array_filter($survey['tables'], function ($table) {
            return $table['convert'];
        });
        $other = array_diff_key($survey['tables'], $convert);
        if (!$survey['tables']) {
            echo '<p><strong>' . esc_html__('Every table of this site can store emoji. Nothing to convert.', 'seoprostack') . '</strong></p>';
        }
        if ($convert) {
            /* translators: %s: collation */
            echo '<p>' . esc_html(sprintf(__('Tables are converted to %s, the collation WordPress uses for new tables here, so tables compared with each other keep working.', 'seoprostack'), $survey['target'])) . '</p>';
            self::list_tables($convert, true);
            $batch = self::batch($survey);
            if ($batch) {
                $size = array_sum(array_map(function ($name) use ($survey) {
                    return $survey['tables'][$name]['size'];
                }, $batch));
                /* translators: 1: number of tables; 2: their size */
                self::button('', sprintf(_n('Convert %1$d table (%2$s)', 'Convert all %1$d tables (%2$s)', count($batch), 'seoprostack'), count($batch), size_format($size)));
            }
        }
        if ($other) {
            echo '<h2>' . esc_html__('Other character sets', 'seoprostack') . '</h2><p>' . esc_html__('These are not converted here: their text may have been stored in the wrong character set, and converting would garble it. Ask your host or a developer to check them.', 'seoprostack') . '</p>';
            self::list_tables($other, false);
        }
        echo '<h2>' . esc_html__('Changes', 'seoprostack') . '</h2>';
        $log = array_reverse((array) get_option(self::LOG, array()));
        if (!$log) {
            echo '<p>' . esc_html__('None yet.', 'seoprostack') . '</p>';
        }
        foreach ($log as $entry) {
            /* translators: %d: seconds */
            $took = $entry['seconds'] ? ' / ' . sprintf(_n('%d second', '%d seconds', (int) $entry['seconds'], 'seoprostack'), (int) $entry['seconds']) : '';
            echo '<p>' . esc_html(wp_date('Y-m-d H:i', (int) $entry['time']) . ' / ' . $entry['table'] . ' / ' . $entry['from'] . ' → ' . $entry['to'] . ' / ' . $entry['result'] . $took) . '</p>';
        }
        echo '<p>' . esc_html__('Pending means the request stopped before the result was recorded: look at the list above to see whether the table was converted.', 'seoprostack') . '</p>';
        echo '<p>' . esc_html__('WP-CLI: wp seoprostack charset list, wp seoprostack charset convert <table>, wp seoprostack charset convert --all.', 'seoprostack') . '</p></div>';
    }

    /**
     * Table of tables.
     *
     * @param array $tables  Tables from survey().
     * @param bool  $buttons Show Convert buttons.
     */
    private static function list_tables(array $tables, $buttons) {
        echo '<table class="widefat striped"><thead><tr>';
        $labels = array(__('Table', 'seoprostack'), __('Character set', 'seoprostack'), __('Size', 'seoprostack'), __('Text columns not in utf8mb4', 'seoprostack'));
        if ($buttons) {
            $labels[] = __('Action', 'seoprostack');
        }
        foreach ($labels as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($tables as $name => $table) {
            /* translators: 1: number of rows; 2: table size */
            $size = sprintf(__('%1$s rows, %2$s', 'seoprostack'), number_format_i18n($table['rows']), size_format($table['size']));
            echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($table['collation']) . '</td><td>' . esc_html($size) . '</td><td>' . esc_html($table['columns'] ? implode(', ', array_keys($table['columns'])) : '–') . '</td>';
            if ($buttons) {
                echo '<td>';
                if ($table['size'] > self::LARGE) {
                    /* translators: %s: WP-CLI command */
                    echo esc_html(sprintf(__('Large table: convert it with WP-CLI, as a web request may time out: %s', 'seoprostack'), 'wp seoprostack charset convert ' . $name));
                } else {
                    self::button($name, __('Convert', 'seoprostack'));
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /* ------------------------------------------------------------------
     * Site Health
     * ------------------------------------------------------------------ */

    /**
     * Add the test (run after the page loads: it reads every table's status).
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        $tests['async'][self::TEST] = array(
            'test'              => self::TEST,
            'async_direct_test' => array(__CLASS__, 'test'),
            'label'             => __('Database tables that cannot store emoji', 'seoprostack'),
        );
        return $tests;
    }

    /**
     * Answer the Site Health screen.
     */
    public static function ajax_test() {
        check_ajax_referer('health-check-site-status');
        if (current_user_can('view_site_health_checks')) {
            wp_send_json_success(self::test());
        }
        wp_send_json_error();
    }

    /**
     * A Site Health result.
     *
     * @param string $status  good or recommended.
     * @param string $label   Heading.
     * @param string $more    HTML after the explanation.
     * @param string $actions HTML actions.
     * @return array
     */
    private static function result($status, $label, $more = '', $actions = '') {
        $about = esc_html__('Tables in the older utf8 (utf8mb3) character set cannot store emoji and some other characters, so saving settings, custom fields or comments that contain them fails without a message. WordPress converts its tables only when upgrading from before version 4.2, so a site moved later from an older database can keep them.', 'seoprostack');
        return array(
            'test'        => 'seoprostack_database_charset',
            'status'      => $status,
            'label'       => $label,
            'description' => '<p>' . $about . '</p>' . $more,
            'actions'     => $actions,
            'badge'       => array('label' => __('Performance', 'seoprostack'), 'color' => 'blue'),
        );
    }

    /**
     * Tables that cannot store 4-byte characters.
     *
     * @return array
     */
    public static function test() {
        $survey = self::survey();
        if (is_wp_error($survey)) {
            return self::result('recommended', __('Database character sets could not be checked', 'seoprostack'), '<p>' . esc_html($survey->get_error_message()) . '</p>');
        }
        $count = count($survey['tables']);
        if (!$count) {
            return self::result('good', __('Every database table can store emoji', 'seoprostack'));
        }
        $items = '';
        foreach (array_slice($survey['tables'], 0, self::HEALTH_MAX, true) as $name => $table) {
            $items .= '<li><code>' . esc_html($name) . '</code>: ' . esc_html($table['collation'] . ', ' . size_format($table['size'])) . '</li>';
        }
        if ($count > self::HEALTH_MAX) {
            /* translators: %d: number of tables */
            $items .= '<li>' . esc_html(sprintf(__('and %d more', 'seoprostack'), $count - self::HEALTH_MAX)) . '</li>';
        }
        $action = self::enabled()
            ? '<a href="' . esc_url(admin_url('tools.php?page=' . self::PAGE)) . '">' . esc_html__('Convert them in Tools → Database character set', 'seoprostack') . '</a>'
            : sprintf(
                /* translators: %s: link to the setting */
                esc_html__('Turn on %s in SEO Pro Stack to convert them when you click, after backing up the database.', 'seoprostack'),
                '<a href="' . esc_url(admin_url('options-general.php?page=seoprostack&tab=server')) . '">' . esc_html__('Database character set', 'seoprostack') . '</a>'
            );
        /* translators: %d: number of tables */
        $label = sprintf(_n('%d database table cannot store emoji', '%d database tables cannot store emoji', $count, 'seoprostack'), $count);
        return self::result('recommended', $label, '<ul>' . $items . '</ul>', '<p>' . $action . '</p>');
    }

    /* ------------------------------------------------------------------
     * WP-CLI
     * ------------------------------------------------------------------ */

    /**
     * Lists the tables that cannot store emoji, or converts them to utf8mb4.
     *
     * ## OPTIONS
     *
     * <command>
     * : list or convert.
     *
     * [<table>...]
     * : Tables to convert.
     *
     * [--all]
     * : Convert every table that can be converted, smallest first.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack charset list
     *     wp seoprostack charset convert wp_options wp_postmeta
     *     wp seoprostack charset convert --all
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Flags.
     */
    public static function cli($args, $assoc_args = array()) {
        $command = $args[0] ?? 'list';
        if (!in_array($command, array('list', 'convert'), true)) {
            WP_CLI::error('Usage: wp seoprostack charset list|convert <table>...|convert --all');
            return;
        }
        $survey = self::survey(true);
        if (is_wp_error($survey)) {
            WP_CLI::error($survey->get_error_message());
            return;
        }
        if ('list' === $command) {
            self::cli_list($survey);
            return;
        }
        if (!self::enabled()) {
            WP_CLI::error('Turn on Database character set (Server tab) first.');
            return;
        }
        $names = array_slice($args, 1);
        if (!empty($assoc_args['all'])) {
            $names = array_keys(array_filter($survey['tables'], function ($table) {
                return $table['convert'];
            }));
        }
        self::cli_convert($names, $survey['target']);
    }

    /**
     * WP-CLI: list the tables.
     *
     * @param array $survey Survey.
     */
    private static function cli_list(array $survey) {
        if (!$survey['tables']) {
            WP_CLI::success('Every table can store emoji.');
            return;
        }
        $items = array();
        foreach ($survey['tables'] as $name => $table) {
            $items[] = array('table' => $name, 'collation' => $table['collation'], 'rows' => $table['rows'], 'size' => size_format($table['size']), 'columns' => implode(',', array_keys($table['columns'])), 'convert' => $table['convert'] ? 'yes' : 'no: other character set');
        }
        WP_CLI\Utils\format_items('table', $items, array('table', 'collation', 'rows', 'size', 'columns', 'convert'));
        WP_CLI::log('Converts to ' . $survey['target'] . '.');
    }

    /**
     * WP-CLI: convert tables in turn, stopping at the first that fails.
     *
     * @param string[] $names  Tables.
     * @param string   $target Collation.
     */
    private static function cli_convert(array $names, $target) {
        if (!$names) {
            WP_CLI::error('Name the tables to convert, or use --all.');
            return;
        }
        $done = 0;
        foreach ($names as $name) {
            $start  = microtime(true);
            $result = self::convert($name);
            if (is_wp_error($result)) {
                WP_CLI::error($name . ': ' . $result->get_error_message() . ($done ? sprintf(' (%d converted before it.)', $done) : ''));
                return;
            }
            $done++;
            WP_CLI::log(sprintf('%s: converted to %s in %ds.', $name, $target, (int) round(microtime(true) - $start)));
        }
        WP_CLI::success($done . ' converted.');
    }
}
