<?php
/**
 * Fluent Support email by IMAP: copies of sent ticket emails in the
 * mailbox's Sent folder.
 *
 * The site's mail plugin sends Fluent Support's email (FluentSMTP through
 * Amazon SES, say), not the mailbox's own server, so no copy reaches the
 * mailbox's Sent folder as it would from an email app. This saves one: each
 * email Fluent Support sends to a customer from the inbox this feature reads
 * (ticket created, agent reply, ticket closed, ticket made by an agent) is
 * saved, exactly as sent and marked read, to the Sent folder over IMAP.
 * Notifications to agents, FluentCRM and other site email are not.
 *
 * Fluent Support passes each of those emails' headers through
 * fluent_support/mail_to_customer_header, with the ticket, just before
 * wp_mail(); the next wp_mail() is the one marked. After it succeeds, the
 * message PHPMailer built (getSentMIMEMessage(), what FluentSMTP hands to its
 * provider and what core's wp_mail() sends) is kept, and saved when the
 * request ends. Only when its From is the inbox's address.
 *
 * A copy that cannot be saved waits in seoprostack_helpdesk_imap_sent (not
 * autoloaded; it holds the waiting emails, so addresses and text, until they
 * are saved) and the five-minute run tries again. At most QUEUE_MAX emails and
 * QUEUE_BYTES wait; others are counted as not kept.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.119
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Helpdesk_Imap_Sent {

    /** Copies waiting, and counts (not autoloaded). */
    const QUEUE = 'seoprostack_helpdesk_imap_sent';

    /** Most emails that wait. */
    const QUEUE_MAX = 20;

    /** Most bytes that wait, all emails together (one database row). */
    const QUEUE_BYTES = 8388608;

    /** Folder names looked for when none is set, in order. */
    const NAMES = array('Sent', 'Sent Items', 'Sent Messages', 'Sent Mail', 'INBOX.Sent', 'INBOX/Sent', '[Gmail]/Sent Mail');

    /**
     * The inbox address the next wp_mail() is to be sent from, once marked.
     *
     * @var string
     */
    private static $pending = '';

    /**
     * The inbox address the wp_mail() under way is to be sent from.
     *
     * @var string
     */
    private static $armed = '';

    /**
     * Emails sent on this request, to save when it ends.
     *
     * @var array<int,array{time:int,mime:string}>
     */
    private static $copies = array();

    /**
     * Register hooks. Called while the feature and this setting are on.
     */
    public static function boot() {
        add_filter('fluent_support/mail_to_customer_header', array(__CLASS__, 'mark'), PHP_INT_MAX, 2);
        add_filter('pre_wp_mail', array(__CLASS__, 'arm'), PHP_INT_MAX, 1);
        add_action('wp_mail_succeeded', array(__CLASS__, 'sent'));
        add_action('wp_mail_failed', array(__CLASS__, 'disarm'));
    }

    /**
     * Fluent Support is about to email a customer: mark the email when its
     * ticket is in this feature's inbox.
     *
     * @param array $headers Headers, returned as they are.
     * @param array $info    Fluent Support's context, with the ticket.
     * @return array
     */
    public static function mark($headers, $info = array()) {
        self::$pending = '';
        $box = SEOProStack_Helpdesk_Imap_Config::mailbox();
        if (!$box || !isset($box->id, $box->email)) {
            return $headers;
        }
        $ticket = is_array($info) && isset($info['ticket']) && is_object($info['ticket']) ? $info['ticket'] : null;
        if ($ticket && isset($ticket->mailbox_id) && (int) $ticket->mailbox_id !== (int) $box->id) {
            return $headers;
        }
        self::$pending = strtolower(trim((string) $box->email));
        return $headers;
    }

    /**
     * wp_mail() starts: the marked email, if any, is this one.
     *
     * @param mixed $result Short-circuit result, returned as it is.
     * @return mixed
     */
    public static function arm($result) {
        self::$armed   = self::$pending;
        self::$pending = '';
        return $result;
    }

    /**
     * The email was not sent: nothing to save.
     */
    public static function disarm() {
        self::$armed = '';
    }

    /**
     * The email was sent: keep the message PHPMailer built, to save when the
     * request ends.
     */
    public static function sent() {
        global $phpmailer;
        $from        = self::$armed;
        self::$armed = '';
        if ('' === $from || !($phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer)) {
            return;
        }
        if (strtolower(trim((string) $phpmailer->From)) !== $from) {
            return;
        }
        $mime = (string) $phpmailer->getSentMIMEMessage();
        if ('' === trim($mime)) {
            return;
        }
        if (!self::$copies) {
            add_action('shutdown', array(__CLASS__, 'flush'), PHP_INT_MAX);
        }
        self::$copies[] = array('time' => time(), 'mime' => $mime);
    }

    /**
     * The request ends: save this request's copies.
     */
    public static function flush() {
        $copies       = self::$copies;
        self::$copies = array();
        if ($copies) {
            self::record(self::save($copies));
        }
    }

    /**
     * Try the waiting copies again. Run by the five-minute run. They leave
     * the queue first, so a copy saved here is not saved twice.
     */
    public static function retry() {
        $state = self::state();
        if (!$state['waiting']) {
            return;
        }
        $waiting          = $state['waiting'];
        $state['waiting'] = array();
        update_option(self::QUEUE, $state, false);
        self::record(self::save($waiting));
    }

    /**
     * Save copies to the Sent folder, oldest first, stopping at the first
     * that fails.
     *
     * @param array $copies Copies.
     * @return array{saved:int,left:array,error:string}
     */
    private static function save(array $copies) {
        $done    = array('saved' => 0, 'left' => array_values($copies), 'error' => '');
        $missing = SEOProStack_Helpdesk_Imap_Config::missing();
        if ($missing) {
            $done['error'] = $missing->get_error_message();
            return $done;
        }
        SEOProStack_Helpdesk_Imap::load();
        $conn = SEOProStack_Helpdesk_Imap_Connection::open(
            SEOProStack_Helpdesk_Imap_Config::server(),
            SEOProStack_Helpdesk_Imap_Config::user(),
            SEOProStack_Helpdesk_Imap_Config::password()
        );
        if (is_wp_error($conn)) {
            $done['error'] = $conn->get_error_message();
            return $done;
        }
        try {
            $folder = self::folder($conn);
            if (is_wp_error($folder)) {
                $done['error'] = $folder->get_error_message();
                return $done;
            }
            while ($done['left']) {
                $copy  = $done['left'][0];
                $saved = $conn->append($folder, (string) $copy['mime'], (int) $copy['time']);
                if (is_wp_error($saved)) {
                    $done['error'] = $saved->get_error_message();
                    break;
                }
                array_shift($done['left']);
                $done['saved']++;
            }
        } finally {
            $conn->close();
        }
        return $done;
    }

    /**
     * The Sent folder: the setting, else the first of NAMES on the server.
     * A folder is never created.
     *
     * @param SEOProStack_Helpdesk_Imap_Connection $conn Connection.
     * @return string|WP_Error
     */
    private static function folder($conn) {
        $set = trim((string) SEOProStack_Settings::get(SEOProStack_Helpdesk_Imap::SENT_FOLDER_KEY));
        if ('' !== $set) {
            return $set;
        }
        $found = array();
        foreach ($conn->folders() as $name) {
            $found[strtolower($name)] = $name;
        }
        foreach (self::NAMES as $name) {
            if (isset($found[strtolower($name)])) {
                return $found[strtolower($name)];
            }
        }
        return new WP_Error('helpdesk_imap_sent_folder', __('The mailbox has no folder named Sent. Enter the Sent folder’s name in the settings.', 'seoprostack'));
    }

    /**
     * Keep a save's result: counts, the last error, and copies not saved,
     * within the queue's limits.
     *
     * @param array{saved:int,left:array,error:string} $done Result.
     */
    private static function record(array $done) {
        $state           = self::state();
        $state['saved'] += (int) $done['saved'];
        if ($done['saved']) {
            $state['time'] = time();
        }
        if ('' !== $done['error']) {
            $state['error']      = $done['error'];
            $state['error_time'] = time();
        } elseif ($done['saved']) {
            $state['error'] = '';
        }
        $bytes = 0;
        foreach ($state['waiting'] as $copy) {
            $bytes += strlen((string) $copy['mime']);
        }
        foreach ($done['left'] as $copy) {
            $size = strlen((string) $copy['mime']);
            if (count($state['waiting']) >= self::QUEUE_MAX || $bytes + $size > self::QUEUE_BYTES) {
                $state['dropped']++;
                continue;
            }
            $state['waiting'][] = $copy;
            $bytes             += $size;
        }
        update_option(self::QUEUE, $state, false);
    }

    /**
     * Copies waiting, and counts.
     *
     * @return array{waiting:array,saved:int,dropped:int,time:int,error:string,error_time:int}
     */
    public static function state() {
        wp_cache_delete(self::QUEUE, 'options');
        $state = get_option(self::QUEUE, array());
        $state = (is_array($state) ? $state : array()) + array('waiting' => array(), 'saved' => 0, 'dropped' => 0, 'time' => 0, 'error' => '', 'error_time' => 0);
        $state['waiting'] = array_values(array_filter((array) $state['waiting'], array(__CLASS__, 'is_copy')));
        $state['saved']   = (int) $state['saved'];
        $state['dropped'] = (int) $state['dropped'];
        return $state;
    }

    /**
     * Whether a stored item is a copy.
     *
     * @param mixed $copy Item.
     * @return bool
     */
    private static function is_copy($copy) {
        return is_array($copy) && isset($copy['mime'], $copy['time']) && is_string($copy['mime']);
    }

    /**
     * The copies, in words.
     *
     * @return string
     */
    public static function describe() {
        $state = self::state();
        $text  = sprintf(
            /* translators: 1: emails saved, 2: emails waiting, 3: emails not kept */
            __('Sent folder copies: %1$d saved, %2$d waiting, %3$d not kept.', 'seoprostack'),
            $state['saved'],
            count($state['waiting']),
            $state['dropped']
        );
        if ($state['time']) {
            /* translators: %s: time ago, such as "5 mins" */
            $text .= ' ' . sprintf(__('Last saved %s ago.', 'seoprostack'), human_time_diff((int) $state['time']));
        }
        if ('' !== $state['error']) {
            /* translators: 1: time ago, 2: error */
            $text .= ' ' . sprintf(__('Last problem, %1$s ago: %2$s', 'seoprostack'), human_time_diff((int) $state['error_time']), $state['error']);
        }
        return $text;
    }
}
