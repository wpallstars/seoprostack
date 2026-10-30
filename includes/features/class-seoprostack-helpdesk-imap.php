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

    /* ------------------------------------------------------------------
     * Requirements
     * ------------------------------------------------------------------ */

    /**
     * The mailbox password.
     *
     * @return string
     */
    private static function password() {
        $password = defined(self::PASSWORD) ? (string) constant(self::PASSWORD) : '';
        /**
         * Filter the IMAP password of Fluent Support email by IMAP, for sites
         * that keep secrets elsewhere than wp-config.php.
         *
         * @param string $password Password.
         */
        return (string) apply_filters('seoprostack_helpdesk_imap_password', $password);
    }

    /**
     * What is missing before email can be read, if anything.
     *
     * @return WP_Error|null
     */
    public static function missing() {
        if (!function_exists('imap_open')) {
            return new WP_Error('helpdesk_imap_php', __('PHP’s IMAP extension is not installed on this server. Ask your host to turn on the imap extension.', 'seoprostack'));
        }
        if (!self::fluent_ready()) {
            return new WP_Error('helpdesk_imap_fluent', __('Fluent Support and Fluent Support Pro need to be active: tickets are made by Fluent Support Pro’s own email code.', 'seoprostack'));
        }
        if ('' === self::server()['host'] || '' === trim((string) SEOProStack_Settings::get(self::USER_KEY))) {
            return new WP_Error('helpdesk_imap_settings', __('Enter the IMAP server and user name.', 'seoprostack'));
        }
        if ('' === self::password()) {
            return new WP_Error('helpdesk_imap_password', __('Add the mailbox password to wp-config.php: define( \'SEOPROSTACK_HELPDESK_IMAP_PASSWORD\', \'…\' );', 'seoprostack'));
        }
        if (!self::mailbox()) {
            return new WP_Error('helpdesk_imap_mailbox', __('Fluent Support has no business inbox for these emails. Add one under Fluent Support → Settings → Business Inboxes.', 'seoprostack'));
        }
        return null;
    }

    /**
     * Whether Fluent Support Pro's email-to-ticket code and Fluent Support's
     * mailboxes are loaded.
     *
     * @return bool
     */
    private static function fluent_ready() {
        return class_exists('FluentSupportPro\App\Services\Integrations\FluentEmailPiping\ByMailHandler')
            && method_exists('FluentSupportPro\App\Services\Integrations\FluentEmailPiping\ByMailHandler', 'processPayload')
            && class_exists('FluentSupport\App\Models\MailBox');
    }

    /**
     * Server name and port from the setting.
     *
     * @return array{host:string,port:int}
     */
    private static function server() {
        $value = strtolower(trim((string) SEOProStack_Settings::get(self::HOST_KEY)));
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $port  = 993;
        if (preg_match('/^(.+):(\d{1,5})$/', $value, $match)) {
            $value = $match[1];
            $port  = (int) $match[2];
        }
        $host = preg_match('/^[a-z0-9.-]+$/', $value) ? $value : '';
        return array('host' => $host, 'port' => $port);
    }

    /**
     * The Fluent Support business inbox new tickets go to.
     *
     * @return object|null MailBox model.
     */
    private static function mailbox() {
        if (!class_exists('FluentSupport\App\Models\MailBox')) {
            return null;
        }
        $id = (int) SEOProStack_Settings::get(self::MAILBOX_KEY);
        if ($id) {
            return \FluentSupport\App\Models\MailBox::where('id', $id)->first();
        }
        $user = strtolower(trim((string) SEOProStack_Settings::get(self::USER_KEY)));
        foreach (\FluentSupport\App\Models\MailBox::where('box_type', 'email')->get() as $box) {
            if (strtolower(trim((string) $box->email)) === $user) {
                return $box;
            }
        }
        $default = \FluentSupport\App\Models\MailBox::where('is_default', 'yes')->orderBy('id', 'ASC')->first();
        return $default ? $default : \FluentSupport\App\Models\MailBox::orderBy('id', 'ASC')->first();
    }

    /* ------------------------------------------------------------------
     * Running
     * ------------------------------------------------------------------ */

    /**
     * Read new email into Fluent Support. Run by WP-Cron.
     */
    public static function run() {
        if (self::enabled()) {
            self::fetch();
        }
    }

    /**
     * Read new email into Fluent Support.
     *
     * @return array<string,int|string>|WP_Error Counts (tickets, replies, skipped, failed) and error.
     */
    public static function fetch() {
        $missing = self::missing();
        if ($missing) {
            self::note(array('error' => $missing->get_error_message()));
            return $missing;
        }
        if (!self::lock()) {
            return new WP_Error('helpdesk_imap_busy', __('Another run is reading the mailbox.', 'seoprostack'));
        }
        try {
            $result = self::read(microtime(true));
        } finally {
            delete_option(self::LOCK);
        }
        if (is_wp_error($result)) {
            self::note(array('error' => $result->get_error_message()));
            return $result;
        }
        self::note($result);
        return $result;
    }

    /**
     * Take the lock, or a lock left by a run that died.
     *
     * @return bool
     */
    private static function lock() {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }
        $taken = (int) get_option(self::LOCK);
        if ($taken && time() - $taken < 2 * self::INTERVAL) {
            return false;
        }
        update_option(self::LOCK, time(), false);
        return true;
    }

    /**
     * Open the mailbox, read new emails and pass them to Fluent Support.
     *
     * @param float $start microtime(true) when the run began.
     * @return array<string,int|string>|WP_Error
     *
     * imap_open() warns as well as failing; the errors are read from imap_errors().
     * @SuppressWarnings("PHPMD.ErrorControlOperator")
     */
    private static function read($start) {
        $box = self::mailbox();
        if (!$box) {
            return new WP_Error('helpdesk_imap_mailbox', __('Fluent Support has no business inbox for these emails.', 'seoprostack'));
        }
        $server = self::server();
        $flags  = 143 === $server['port'] ? '/imap/tls' : '/imap/ssl';
        $ref    = '{' . $server['host'] . ':' . $server['port'] . $flags . '}INBOX';
        imap_timeout(IMAP_OPENTIMEOUT, 15);
        imap_timeout(IMAP_READTIMEOUT, 15);
        $user = trim((string) SEOProStack_Settings::get(self::USER_KEY));
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the reason is read from imap_errors() below.
        $imap = @imap_open($ref, $user, self::password(), 0, 1, array('DISABLE_AUTHENTICATOR' => 'GSSAPI'));
        if (!$imap) {
            return new WP_Error('helpdesk_imap_connect', self::imap_error(__('Could not log in to the mailbox.', 'seoprostack')));
        }
        $counts = array('tickets' => 0, 'replies' => 0, 'skipped' => 0, 'failed' => 0, 'error' => '');
        try {
            $status = imap_status($imap, $ref, SA_UIDVALIDITY | SA_UIDNEXT);
            if (!$status) {
                return new WP_Error('helpdesk_imap_status', self::imap_error(__('Could not read the mailbox status.', 'seoprostack')));
            }
            $state = self::state();
            $uids  = self::new_uids($imap, $state, (int) $status->uidvalidity, (int) $status->uidnext);
            foreach ($uids as $uid) {
                if (!self::more_time($start, self::BUDGET)) {
                    break;
                }
                try {
                    $outcome = self::process($imap, $uid, (int) $status->uidvalidity, $box);
                } catch (Throwable $e) {
                    // Tried again next run, then passed over.
                    $tries = isset($state['tries'][$uid]) ? (int) $state['tries'][$uid] + 1 : 1;
                    if ($tries < self::TRIES) {
                        $state['tries'] = array($uid => $tries);
                        $counts['error'] = $e->getMessage();
                        break;
                    }
                    $outcome = 'failed';
                }
                $state['tries']    = array();
                $state['last_uid'] = $uid;
                $counts[isset($counts[$outcome]) ? $outcome : 'skipped']++;
            }
            $state['validity'] = (int) $status->uidvalidity;
            if (!isset($state['last_uid'])) {
                $state['last_uid'] = 0;
            }
            if (!$uids && (int) $state['last_uid'] < (int) $status->uidnext - 1) {
                $state['last_uid'] = (int) $status->uidnext - 1;
            }
            self::save_state($state);
        } finally {
            imap_close($imap);
            self::imap_error('');
        }
        return $counts;
    }

    /**
     * UIDs to read, oldest first: those after the last one read, or, on the
     * first run or when the mailbox was rebuilt (new UIDVALIDITY), those of
     * the last day.
     *
     * @param resource        $imap     Connection.
     * @param array           $state    Stored state.
     * @param int             $validity UIDVALIDITY.
     * @param int             $next     UIDNEXT.
     * @return int[]
     */
    private static function new_uids($imap, array &$state, $validity, $next) {
        if (empty($state['validity']) || (int) $state['validity'] !== $validity) {
            $state = array('validity' => $validity, 'last_uid' => 0, 'tries' => array());
            $since = gmdate('j-M-Y', time() - DAY_IN_SECONDS);
            $found = imap_search($imap, 'SINCE "' . $since . '"', SE_UID);
            $uids  = is_array($found) ? array_map('intval', $found) : array();
        } else {
            $last = (int) $state['last_uid'];
            if ($last + 1 >= $next) {
                return array();
            }
            $found = imap_fetch_overview($imap, ($last + 1) . ':*', FT_UID);
            $uids  = array();
            foreach ((array) $found as $item) {
                // "N:*" also answers with the newest email when none is newer.
                if (isset($item->uid) && (int) $item->uid > $last) {
                    $uids[] = (int) $item->uid;
                }
            }
        }
        sort($uids);
        return array_slice(array_unique($uids), 0, self::BATCH);
    }

    /**
     * Pass one email to Fluent Support.
     *
     * @param resource        $imap     Connection.
     * @param int             $uid      UID.
     * @param int             $validity UIDVALIDITY.
     * @param object          $box      MailBox model.
     * @return string tickets, replies, skipped or failed.
     */
    private static function process($imap, $uid, $validity, $box) {
        $raw = (string) imap_fetchheader($imap, $uid, FT_UID);
        if ('' === $raw) {
            return 'skipped';
        }
        $parsed  = imap_rfc822_parse_headers($raw);
        $message = isset($parsed->message_id) ? trim((string) $parsed->message_id) : '';
        $body    = self::body($imap, $uid);

        $content = $body['html'] ? self::clean_html($body['html']) : wpautop(esc_html($body['text']));
        if ($body['files']) {
            $content .= '<p><em>' . esc_html(sprintf(
                /* translators: %s: attachment file names */
                __('Attachments (in the support mailbox): %s', 'seoprostack'),
                implode(', ', $body['files'])
            )) . '</em></p>';
        }

        $data = array(
            'subject'     => self::decode_header(isset($parsed->subject) ? (string) $parsed->subject : ''),
            'body_text'   => substr($content, 0, self::MAX_TEXT),
            'messageId'   => $message,
            'inReplyTo'   => isset($parsed->in_reply_to) ? (string) $parsed->in_reply_to : '',
            'references'  => isset($parsed->references) ? (string) $parsed->references : '',
            'attachments' => array(),
            'isMarkDown'  => false,
            'from'        => array('value' => self::addresses(isset($parsed->from) ? $parsed->from : array())),
            'to'          => array('value' => self::addresses(isset($parsed->to) ? $parsed->to : array())),
            'cc'          => array('value' => self::addresses(isset($parsed->cc) ? $parsed->cc : array())),
            'headers'     => self::header_map($raw),
        );
        $mime = 'imap-' . md5($validity . ':' . $uid . ':' . $message); // NOSONAR: a duplicate key, not security.
        $data['mime'] = $mime;

        // Fluent Support Pro's own filter, applied as its relay's push applies it (EmailBoxController::pipePayload()).
        $data   = apply_filters('fluent_support_pro/email_piping_raw_data', $data, $box); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Support Pro's hook.
        $result = \FluentSupportPro\App\Services\Integrations\FluentEmailPiping\ByMailHandler::processPayload($data, $box, $mime);

        if (is_wp_error($result)) {
            return 'failed';
        }
        $type = is_array($result) && isset($result['type']) ? (string) $result['type'] : '';
        if ('new_ticket' === $type) {
            return 'tickets';
        }
        return '' !== $type && 'confirmed' !== $type ? 'replies' : 'skipped';
    }

    /**
     * The email's text, HTML and attachment names.
     *
     * @param resource        $imap Connection.
     * @param int             $uid  UID.
     * @return array{text:string,html:string,files:string[]}
     */
    private static function body($imap, $uid) {
        $text      = '';
        $html      = '';
        $files     = array();
        $structure = imap_fetchstructure($imap, $uid, FT_UID);
        if ($structure) {
            $parts = self::prop($structure, 'parts');
            if (!is_array($parts) || !$parts) {
                self::part($imap, $uid, $structure, '1', $text, $html, $files);
            } else {
                foreach (array_values($parts) as $index => $part) {
                    self::part($imap, $uid, $part, (string) ($index + 1), $text, $html, $files);
                }
            }
        }
        return array('text' => $text, 'html' => $html, 'files' => $files);
    }

    /**
     * Read one MIME part, and the parts inside it.
     *
     * @param resource $imap    Connection.
     * @param int      $uid     UID.
     * @param object   $part    Part structure.
     * @param string   $section Section number, such as 1.2.
     * @param string   $text    Plain text so far.
     * @param string   $html    HTML so far.
     * @param string[] $files   Attachment names so far.
     */
    private static function part($imap, $uid, $part, $section, &$text, &$html, array &$files) {
        $params = self::params($part);
        $name   = isset($params['filename']) ? $params['filename'] : (isset($params['name']) ? $params['name'] : '');
        $type   = (int) self::prop($part, 'type', TYPETEXT);
        $attach = '' !== $name || 'attachment' === strtolower((string) self::prop($part, 'disposition', ''));
        $parts  = self::prop($part, 'parts');

        if (TYPEMULTIPART === $type && is_array($parts)) {
            foreach (array_values($parts) as $index => $child) {
                self::part($imap, $uid, $child, $section . '.' . ($index + 1), $text, $html, $files);
            }
            return;
        }
        if ($attach || TYPETEXT !== $type) {
            if (TYPEMESSAGE === $type && '' === $name) {
                $name = __('forwarded email', 'seoprostack');
            }
            if ('' !== $name && count($files) < 50) {
                $files[] = sanitize_file_name(self::decode_header($name));
            }
            return;
        }
        $subtype = strtolower((string) self::prop($part, 'subtype', 'plain'));
        if (('html' === $subtype && '' !== $html) || ('plain' === $subtype && '' !== $text) || !in_array($subtype, array('plain', 'html'), true)) {
            return;
        }
        $raw     = (string) imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
        $charset = isset($params['charset']) ? $params['charset'] : '';
        $decoded = self::to_utf8(self::decode_part($raw, (int) self::prop($part, 'encoding', ENC7BIT)), $charset);
        if ('html' === $subtype) {
            $html = $decoded;
        } else {
            $text = $decoded;
        }
    }

    /**
     * A property of a structure imap_fetchstructure() gave, which lists only
     * the properties a part has.
     *
     * @param object $structure Structure.
     * @param string $name      Property.
     * @param mixed  $fallback  Value when missing.
     * @return mixed
     */
    private static function prop($structure, $name, $fallback = null) {
        return isset($structure->$name) ? $structure->$name : $fallback;
    }

    /**
     * A part's parameters (charset, name, filename), lower-case names.
     *
     * @param object $part Part structure.
     * @return array<string,string>
     */
    private static function params($part) {
        $params = array();
        foreach (array('parameters', 'dparameters') as $list) {
            if (empty($part->$list) || !is_array($part->$list)) {
                continue;
            }
            foreach ($part->$list as $param) {
                if (isset($param->attribute, $param->value)) {
                    $params[strtolower(rtrim((string) $param->attribute, '*'))] = (string) $param->value;
                }
            }
        }
        return $params;
    }

    /**
     * Undo a part's transfer encoding.
     *
     * @param string $raw      Raw part.
     * @param int    $encoding ENC* constant.
     * @return string
     */
    private static function decode_part($raw, $encoding) {
        if (ENCBASE64 === $encoding) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MIME transfer encoding of an email part.
            return (string) base64_decode($raw);
        }
        if (ENCQUOTEDPRINTABLE === $encoding) {
            return quoted_printable_decode($raw);
        }
        return $raw;
    }

    /**
     * Text in a character set, as UTF-8.
     *
     * @param string $text    Text.
     * @param string $charset Character set.
     * @return string
     */
    private static function to_utf8($text, $charset) {
        $charset = strtoupper(trim($charset));
        if ('' === $charset || 'UTF-8' === $charset || 'US-ASCII' === $charset || !function_exists('mb_convert_encoding')) {
            return function_exists('wp_check_invalid_utf8') ? wp_check_invalid_utf8($text, true) : $text;
        }
        try {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unknown character set warns on PHP 7.
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        } catch (Throwable $e) {
            // PHP 8 throws ValueError for an unknown character set.
            $converted = false;
        }
        return is_string($converted) ? $converted : wp_check_invalid_utf8($text, true);
    }

    /**
     * A header that may hold encoded words (=?UTF-8?B?…?=), as UTF-8.
     *
     * @param string $value Header value.
     * @return string
     */
    private static function decode_header($value) {
        $out = '';
        foreach ((array) imap_mime_header_decode((string) $value) as $piece) {
            $charset = isset($piece->charset) && 'default' !== $piece->charset ? $piece->charset : '';
            $out    .= self::to_utf8((string) $piece->text, $charset);
        }
        return trim($out);
    }

    /**
     * Addresses in the shape Fluent's relay sends: name and address.
     *
     * @param array $list Address objects from imap_rfc822_parse_headers().
     * @return array<int,array{name:string,address:string}>
     */
    private static function addresses($list) {
        $out = array();
        foreach ((array) $list as $item) {
            if (empty($item->mailbox) || empty($item->host)) {
                continue;
            }
            $address = sanitize_email($item->mailbox . '@' . $item->host);
            if ('' === $address) {
                continue;
            }
            $out[] = array(
                'name'    => isset($item->personal) ? self::decode_header((string) $item->personal) : '',
                'address' => $address,
            );
        }
        return $out;
    }

    /**
     * Header fields by lower-case name (the first of each), for Fluent
     * Support's checks for automatic replies and bounces.
     *
     * @param string $raw Raw header.
     * @return array<string,string>
     */
    private static function header_map($raw) {
        $map   = array();
        $lines = explode("\n", (string) preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw)));
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if (false === $colon || 0 === $colon) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            if (!isset($map[$name]) && preg_match('/^[a-z0-9-]+$/', $name)) {
                $map[$name] = trim(substr($line, $colon + 1));
            }
        }
        return $map;
    }

    /**
     * An email's HTML without its head, styles and scripts, whose text would
     * otherwise stay after Fluent Support removes the tags.
     *
     * @param string $html HTML.
     * @return string
     */
    private static function clean_html($html) {
        $html = (string) preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        if (preg_match('#<body\b[^>]*>(.*)</body\s*>#is', $html, $match)) {
            $html = $match[1];
        }
        return trim($html);
    }

    /**
     * The last IMAP error, or a fallback; clears IMAP's error stack.
     *
     * @param string $fallback Text when IMAP gave none.
     * @return string
     */
    private static function imap_error($fallback) {
        $errors = imap_errors();
        imap_alerts();
        $last = is_array($errors) && $errors ? (string) end($errors) : '';
        return '' !== $fallback && '' !== $last ? $fallback . ' ' . $last : $fallback;
    }

    /* ------------------------------------------------------------------
     * State
     * ------------------------------------------------------------------ */

    /**
     * Where reading got to, and the last runs.
     *
     * @return array
     */
    public static function state() {
        wp_cache_delete(self::STATE, 'options');
        $state = get_option(self::STATE, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Save where reading got to, keeping the log.
     *
     * @param array $state State.
     */
    private static function save_state(array $state) {
        $stored       = self::state();
        $state['log'] = isset($stored['log']) && is_array($stored['log']) ? $stored['log'] : array();
        update_option(self::STATE, $state, false);
    }

    /**
     * Log a run.
     *
     * @param array $result Counts, or an error.
     */
    private static function note(array $result) {
        $state = self::state();
        $log   = isset($state['log']) && is_array($state['log']) ? $state['log'] : array();
        array_unshift($log, array_merge(array('time' => time()), array_intersect_key($result, array_flip(array('tickets', 'replies', 'skipped', 'failed', 'error')))));
        $state['log'] = array_slice($log, 0, self::LOG_MAX);
        update_option(self::STATE, $state, false);
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
        $missing = self::missing();
        $state   = self::state();
        $last    = !empty($state['log'][0]) ? $state['log'][0] : null;
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
    private static function describe(array $run) {
        /* translators: %s: time ago, such as "5 mins" */
        $when = sprintf(__('Last read %s ago.', 'seoprostack'), human_time_diff((int) $run['time']));
        if (!empty($run['error']) && !isset($run['tickets'])) {
            return $when . ' ' . $run['error'];
        }
        $text = $when . ' ' . sprintf(
            /* translators: 1: new tickets, 2: replies, 3: emails passed over, 4: emails Fluent Support refused */
            __('New tickets: %1$d. Replies: %2$d. Passed over: %3$d. Refused: %4$d.', 'seoprostack'),
            (int) $run['tickets'],
            (int) $run['replies'],
            (int) $run['skipped'],
            (int) $run['failed']
        );
        return !empty($run['error']) ? $text . ' ' . $run['error'] : $text;
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
        $action = isset($args[0]) ? (string) $args[0] : 'status';
        if ('fetch' === $action) {
            if (!self::enabled()) {
                WP_CLI::error(__('Fluent Support email by IMAP is off (Plugins tab).', 'seoprostack'));
            }
            $result = self::fetch();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
                return;
            }
            WP_CLI::success(self::describe(array_merge(array('time' => time()), $result)));
            return;
        }
        $missing = self::missing();
        if ($missing) {
            WP_CLI::warning($missing->get_error_message());
        }
        $state = self::state();
        /* translators: 1: UIDVALIDITY, 2: last UID read */
        WP_CLI::log(sprintf(__('Mailbox %1$d, read up to UID %2$d.', 'seoprostack'), isset($state['validity']) ? (int) $state['validity'] : 0, isset($state['last_uid']) ? (int) $state['last_uid'] : 0));
        foreach (isset($state['log']) ? (array) $state['log'] : array() as $run) {
            WP_CLI::log(self::describe($run));
        }
    }
}
