<?php
/**
 * Fluent Support email by IMAP.
 *
 * Fluent Support Pro turns support email into tickets through its maker's
 * hosted relay: the relay gives each mailbox a forwarding address and pushes
 * each email to the site, and it only does so for a site with an active
 * licence. The part that makes the ticket runs on the site, in Fluent Support
 * Pro's own GPL code (ByMailHandler::processPayload(), the code the relay's
 * push calls). This feature reads the site's own mailbox over IMAP instead
 * and hands each new email to that code, so tickets need no relay, no
 * forwarding address and no licence call.
 *
 * Every five minutes (WP-Cron, or `wp seoprostack helpdesk-mail fetch`) it
 * reads emails that arrived since the last one it read, by IMAP UID, up to 25
 * a run. The mailbox is only read: emails stay where they are, read or
 * unread. On the first run, emails from the last day are read. Fluent
 * Support's own checks still apply: replies join their ticket, out-of-office
 * replies and bounces open none, an email read twice makes one ticket.
 * Attachments stay in the mailbox; the ticket names them.
 *
 * The password is read from SEOPROSTACK_HELPDESK_IMAP_PASSWORD in
 * wp-config.php (or the seoprostack_helpdesk_imap_password filter), never
 * from the database. The connection always checks the server's certificate.
 *
 * Stored: where reading got to and the last runs' results in
 * seoprostack_helpdesk_imap_state (not autoloaded, no addresses or message
 * text), and a lock while a run is under way.
 *
 * This class holds the switch, schedule, settings card and WP-CLI command;
 * the reading is in includes/class-seoprostack-helpdesk-imap-*.php, loaded
 * only when needed (load()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.118
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Helpdesk_Imap extends SEOProStack_Feature {

    const KEY         = 'helpdesk_imap';
    const HOST_KEY    = 'helpdesk_imap_host';
    const USER_KEY    = 'helpdesk_imap_user';
    const MAILBOX_KEY = 'helpdesk_imap_mailbox';

    /** Constant holding the mailbox password. */
    const PASSWORD = 'SEOPROSTACK_HELPDESK_IMAP_PASSWORD';

    /** WP-Cron event and its interval's name. */
    const HOOK     = 'seoprostack_helpdesk_imap';
    const SCHEDULE = 'seoprostack_helpdesk_imap';

    /** Seconds between runs. */
    const INTERVAL = 300;

    /** Where reading got to, and the last runs (not autoloaded). */
    const STATE = 'seoprostack_helpdesk_imap_state';

    /** Taken while a run is under way. */
    const LOCK = 'seoprostack_helpdesk_imap_lock';

    /** Emails read per run. */
    const BATCH = 25;

    /** Seconds a run may take. */
    const BUDGET = 20;

    /** Times an email that stops a run is tried before it is passed over. */
    const TRIES = 3;

    /** Runs kept in the log. */
    const LOG_MAX = 20;

    /** Largest message text handed to Fluent Support, in bytes. */
    const MAX_TEXT = 1048576;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY         => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'plugins',
                'label'       => __('Fluent Support email by IMAP', 'seoprostack'),
                'description' => __('Turns email in your support mailbox into Fluent Support tickets and replies, without Fluent’s hosted email relay or its licence check. Every five minutes the mailbox is read over IMAP and each new email goes to Fluent Support Pro’s own email-to-ticket code, so replies, bounces and duplicates are handled as Fluent Support handles them. Emails stay in the mailbox. Set the password as SEOPROSTACK_HELPDESK_IMAP_PASSWORD in wp-config.php.', 'seoprostack'),
            ),
            self::HOST_KEY    => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('IMAP server', 'seoprostack'),
                'description' => __('Server name, with :993 (SSL, the default) or :143 (STARTTLS), such as mail.example.com:993. The certificate is always checked.', 'seoprostack'),
            ),
            self::USER_KEY    => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('User name', 'seoprostack'),
                'description' => __('Usually the mailbox’s email address.', 'seoprostack'),
            ),
            self::MAILBOX_KEY => array(
                'type'        => 'int',
                'default'     => 0,
                'min'         => 0,
                'parent'      => self::KEY,
                'label'       => __('Fluent Support mailbox ID', 'seoprostack'),
                'description' => __('The business inbox new tickets go to. 0: the email inbox with the user name as its address, else the default inbox.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack helpdesk-mail', array(__CLASS__, 'cli'));
        }
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 1);
        if (is_admin()) {
            // Also when switched off, to take the schedule away.
            add_action('admin_init', array(__CLASS__, 'sync_schedule'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'));
        }
        if (!self::enabled()) {
            return;
        }
        // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- every five minutes (INTERVAL), only while this is on.
        add_filter('cron_schedules', array(__CLASS__, 'schedules'));
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    /**
     * Load the classes that read the mailbox.
     */
    public static function load() {
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-helpdesk-imap-config.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-helpdesk-imap-connection.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-helpdesk-imap-mime.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-helpdesk-imap-message.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-helpdesk-imap-reader.php';
    }

    /* ------------------------------------------------------------------
     * Schedule
     * ------------------------------------------------------------------ */

    /**
     * The five-minute interval.
     *
     * @param array $schedules Intervals.
     * @return array
     */
    public static function schedules($schedules) {
        if (!is_array($schedules)) {
            $schedules = array();
        }
        $schedules[self::SCHEDULE] = array(
            'interval' => self::INTERVAL,
            'display'  => __('Every five minutes (Fluent Support email by IMAP)', 'seoprostack'),
        );
        return $schedules;
    }

    /**
     * Keep the event in step with the switch.
     */
    public static function sync_schedule() {
        if (self::enabled()) {
            if (!wp_next_scheduled(self::HOOK)) {
                // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- every five minutes (INTERVAL), only while this is on.
                add_filter('cron_schedules', array(__CLASS__, 'schedules'));
                wp_schedule_event(time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK);
            }
            return;
        }
        if (wp_next_scheduled(self::HOOK)) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * A setting changed: update the schedule.
     *
     * @param string $key Setting key.
     */
    public static function setting_saved($key) {
        if (0 === strpos((string) $key, 'helpdesk_imap')) {
            self::sync_schedule();
        }
    }

    /**
     * Read new email into Fluent Support. Run by WP-Cron.
     */
    public static function run() {
        if (self::enabled()) {
            self::load();
            SEOProStack_Helpdesk_Imap_Reader::fetch();
        }
    }

    /* ------------------------------------------------------------------
     * Settings panel and WP-CLI
     * ------------------------------------------------------------------ */

    /**
     * Status at the top of the card's options.
     *
     * @param string $key Setting key.
     */
    public static function panel($key) {
        if (self::KEY !== $key || !current_user_can('manage_options')) {
            return;
        }
        self::load();
        $missing = SEOProStack_Helpdesk_Imap_Config::missing();
        $last    = SEOProStack_Helpdesk_Imap_Reader::last_run();
        echo '<div class="sps-panel-note">';
        if ($missing) {
            echo '<p>' . esc_html($missing->get_error_message()) . '</p>';
        }
        if ($last) {
            echo '<p>' . esc_html(self::describe($last)) . '</p>';
        } elseif (!$missing) {
            echo '<p>' . esc_html__('Ready. The mailbox is read within five minutes of switching this on.', 'seoprostack') . '</p>';
        }
        echo '</div>';
    }

    /**
     * A run, in words.
     *
     * @param array $run Logged run.
     * @return string
     */
    public static function describe(array $run) {
        $run += array('time' => time(), 'error' => '');
        /* translators: %s: time ago, such as "5 mins" */
        $when = sprintf(__('Last read %s ago.', 'seoprostack'), human_time_diff((int) $run['time']));
        if (!isset($run['tickets'])) {
            return trim($when . ' ' . $run['error']);
        }
        $run += array('replies' => 0, 'skipped' => 0, 'failed' => 0);
        $text = $when . ' ' . sprintf(
            /* translators: 1: new tickets, 2: replies, 3: emails passed over, 4: emails Fluent Support refused */
            __('New tickets: %1$d. Replies: %2$d. Passed over: %3$d. Refused: %4$d.', 'seoprostack'),
            (int) $run['tickets'],
            (int) $run['replies'],
            (int) $run['skipped'],
            (int) $run['failed']
        );
        return trim($text . ' ' . $run['error']);
    }

    /**
     * Read Fluent Support email from the support mailbox.
     *
     * ## OPTIONS
     *
     * <action>
     * : fetch reads new email into Fluent Support now; status shows the last runs.
     * ---
     * options:
     *   - fetch
     *   - status
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostack helpdesk-mail fetch
     *     wp seoprostack helpdesk-mail status
     *
     * @param array $args Arguments.
     */
    public static function cli($args) {
        self::load();
        if (isset($args[0]) && 'fetch' === $args[0]) {
            self::cli_fetch();
            return;
        }
        self::cli_status();
    }

    /**
     * WP-CLI: read new email now.
     */
    private static function cli_fetch() {
        if (!self::enabled()) {
            WP_CLI::error(__('Fluent Support email by IMAP is off (Plugins tab).', 'seoprostack'));
            return;
        }
        $result = SEOProStack_Helpdesk_Imap_Reader::fetch();
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
            return;
        }
        WP_CLI::success(self::describe($result));
    }

    /**
     * WP-CLI: where reading got to, and the last runs.
     */
    private static function cli_status() {
        $missing = SEOProStack_Helpdesk_Imap_Config::missing();
        if ($missing) {
            WP_CLI::warning($missing->get_error_message());
        }
        $state = SEOProStack_Helpdesk_Imap_Reader::state() + array('validity' => 0, 'last_uid' => 0, 'log' => array());
        /* translators: 1: UIDVALIDITY, 2: last UID read */
        WP_CLI::log(sprintf(__('Mailbox %1$d, read up to UID %2$d.', 'seoprostack'), (int) $state['validity'], (int) $state['last_uid']));
        foreach ((array) $state['log'] as $run) {
            WP_CLI::log(self::describe((array) $run));
        }
    }
}
