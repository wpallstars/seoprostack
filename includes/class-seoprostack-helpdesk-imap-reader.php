<?php
/**
 * Fluent Support email by IMAP: a run. Reads emails newer than the last one
 * read and passes each to Fluent Support Pro's own email-to-ticket code,
 * keeping where reading got to and a short log of runs.
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

class SEOProStack_Helpdesk_Imap_Reader {

    /**
     * Read new email into Fluent Support.
     *
     * @return array<string,int|string>|WP_Error Counts (tickets, replies, skipped, failed) and error.
     */
    public static function fetch() {
        $missing = SEOProStack_Helpdesk_Imap_Config::missing();
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
            delete_option(SEOProStack_Helpdesk_Imap::LOCK);
        }
        self::note(is_wp_error($result) ? array('error' => $result->get_error_message()) : $result);
        return $result;
    }

    /**
     * Take the lock, or a lock left by a run that died.
     *
     * @return bool
     */
    private static function lock() {
        if (add_option(SEOProStack_Helpdesk_Imap::LOCK, time(), '', false)) {
            return true;
        }
        $taken = (int) get_option(SEOProStack_Helpdesk_Imap::LOCK);
        if ($taken && time() - $taken < 2 * SEOProStack_Helpdesk_Imap::INTERVAL) {
            return false;
        }
        update_option(SEOProStack_Helpdesk_Imap::LOCK, time(), false);
        return true;
    }

    /**
     * Open the mailbox, read new emails and pass them to Fluent Support.
     *
     * @param float $start microtime(true) when the run began.
     * @return array<string,int|string>|WP_Error
     */
    private static function read($start) {
        $box = SEOProStack_Helpdesk_Imap_Config::mailbox();
        if (!$box) {
            return new WP_Error('helpdesk_imap_mailbox', __('Fluent Support has no business inbox for these emails.', 'seoprostack'));
        }
        $conn = SEOProStack_Helpdesk_Imap_Connection::open(
            SEOProStack_Helpdesk_Imap_Config::server(),
            SEOProStack_Helpdesk_Imap_Config::user(),
            SEOProStack_Helpdesk_Imap_Config::password()
        );
        if (is_wp_error($conn)) {
            return $conn;
        }
        try {
            $status = $conn->status();
            if (is_wp_error($status)) {
                return $status;
            }
            $state  = self::state();
            $uids   = self::new_uids($conn, $state, $status['validity'], $status['next']);
            $counts = self::read_uids($conn, $uids, $box, $state, $status['validity'], $start);
            self::advance($state, $uids, $status);
            self::save_state($state);
        } finally {
            $conn->close();
        }
        return $counts;
    }

    /**
     * UIDs to read, oldest first: those after the last one read, or, on the
     * first run or when the mailbox was rebuilt (new UIDVALIDITY), those of
     * the last day.
     *
     * @param SEOProStack_Helpdesk_Imap_Connection $conn     Connection.
     * @param array                                $state    Stored state.
     * @param int                                  $validity UIDVALIDITY.
     * @param int                                  $next     UIDNEXT.
     * @return int[]
     */
    private static function new_uids($conn, array &$state, $validity, $next) {
        $state += array('validity' => 0, 'last_uid' => 0);
        if ((int) $state['validity'] !== $validity) {
            $state = array('validity' => $validity, 'last_uid' => 0, 'tries' => array());
            $uids  = $conn->since(gmdate('j-M-Y', time() - DAY_IN_SECONDS));
        } else {
            $last = (int) $state['last_uid'];
            $uids = $last + 1 < $next ? $conn->after($last) : array();
        }
        sort($uids);
        return array_slice(array_unique($uids), 0, SEOProStack_Helpdesk_Imap::BATCH);
    }

    /**
     * Pass the emails to Fluent Support, oldest first, while time allows. An
     * email that throws stops the run and is tried in the next runs, then
     * passed over.
     *
     * @param SEOProStack_Helpdesk_Imap_Connection $conn     Connection.
     * @param int[]                                $uids     UIDs.
     * @param object                               $box      MailBox model.
     * @param array                                $state    Stored state.
     * @param int                                  $validity UIDVALIDITY.
     * @param float                                $start    microtime(true) when the run began.
     * @return array<string,int|string>
     */
    private static function read_uids($conn, array $uids, $box, array &$state, $validity, $start) {
        $counts = array('tickets' => 0, 'replies' => 0, 'skipped' => 0, 'failed' => 0, 'error' => '');
        $tries  = isset($state['tries']) ? (array) $state['tries'] : array();
        foreach ($uids as $uid) {
            if (!SEOProStack_Helpdesk_Imap::more_time($start, SEOProStack_Helpdesk_Imap::BUDGET)) {
                break;
            }
            try {
                $outcome = self::process($conn, $uid, $validity, $box);
            } catch (Throwable $e) {
                $tried = 1 + (int) (isset($tries[$uid]) ? $tries[$uid] : 0);
                if ($tried < SEOProStack_Helpdesk_Imap::TRIES) {
                    $state['tries']  = array($uid => $tried);
                    $counts['error'] = $e->getMessage();
                    break;
                }
                $outcome = 'failed';
            }
            $tries             = array();
            $state['tries']    = array();
            $state['last_uid'] = $uid;
            $counts[$outcome]++;
        }
        return $counts;
    }

    /**
     * Record the mailbox read. With nothing new, reading starts after the
     * newest email, so emails deleted from the mailbox are not waited for.
     *
     * @param array                       $state  Stored state.
     * @param int[]                       $uids   UIDs read this run.
     * @param array{validity:int,next:int} $status Mailbox status.
     */
    private static function advance(array &$state, array $uids, array $status) {
        $state['validity'] = $status['validity'];
        if (!$uids && (int) $state['last_uid'] < $status['next'] - 1) {
            $state['last_uid'] = $status['next'] - 1;
        }
    }

    /**
     * Pass one email to Fluent Support.
     *
     * @param SEOProStack_Helpdesk_Imap_Connection $conn     Connection.
     * @param int                                  $uid      UID.
     * @param int                                  $validity UIDVALIDITY.
     * @param object                               $box      MailBox model.
     * @return string tickets, replies, skipped or failed.
     */
    private static function process($conn, $uid, $validity, $box) {
        $data = SEOProStack_Helpdesk_Imap_Message::payload($conn, $uid, $validity);
        if (null === $data) {
            return 'skipped';
        }
        $mime = (string) $data['mime'];
        // Fluent Support Pro's own filter, applied as its relay's push applies it (EmailBoxController::pipePayload()).
        $data = apply_filters('fluent_support_pro/email_piping_raw_data', $data, $box); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Support Pro's hook.
        return self::outcome(\FluentSupportPro\App\Services\Integrations\FluentEmailPiping\ByMailHandler::processPayload($data, $box, $mime));
    }

    /**
     * What Fluent Support did with an email.
     *
     * @param mixed $result processPayload()'s result.
     * @return string tickets, replies, skipped or failed.
     */
    private static function outcome($result) {
        if (is_wp_error($result)) {
            return 'failed';
        }
        $type = is_array($result) && isset($result['type']) ? (string) $result['type'] : '';
        if ('new_ticket' === $type) {
            return 'tickets';
        }
        return in_array($type, array('', 'confirmed'), true) ? 'skipped' : 'replies';
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
        wp_cache_delete(SEOProStack_Helpdesk_Imap::STATE, 'options');
        $state = get_option(SEOProStack_Helpdesk_Imap::STATE, array());
        return is_array($state) ? $state : array();
    }

    /**
     * The last run logged, if any.
     *
     * @return array|null
     */
    public static function last_run() {
        $state = self::state() + array('log' => array());
        $log   = (array) $state['log'];
        return $log ? (array) reset($log) : null;
    }

    /**
     * Save where reading got to, keeping the log.
     *
     * @param array $state State.
     */
    private static function save_state(array $state) {
        $stored       = self::state() + array('log' => array());
        $state['log'] = (array) $stored['log'];
        update_option(SEOProStack_Helpdesk_Imap::STATE, $state, false);
    }

    /**
     * Log a run.
     *
     * @param array $result Counts, or an error.
     */
    private static function note(array $result) {
        $state = self::state() + array('log' => array());
        $log   = (array) $state['log'];
        array_unshift($log, array_merge(array('time' => time()), array_intersect_key($result, array_flip(array('tickets', 'replies', 'skipped', 'failed', 'error')))));
        $state['log'] = array_slice($log, 0, SEOProStack_Helpdesk_Imap::LOG_MAX);
        update_option(SEOProStack_Helpdesk_Imap::STATE, $state, false);
    }
}
