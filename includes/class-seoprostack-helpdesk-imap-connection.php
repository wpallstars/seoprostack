<?php
/**
 * Fluent Support email by IMAP: the connection to the support mailbox. It
 * reads with FT_PEEK, so no email is marked read; the only write is append(),
 * which adds copies of sent emails to the Sent folder.
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

class SEOProStack_Helpdesk_Imap_Connection {

    /** Seconds to wait for the server. */
    const TIMEOUT = 15;

    /**
     * The open connection.
     *
     * @var resource
     */
    private $imap;

    /**
     * The mailbox reference, such as {mail.example.com:993/imap/ssl}INBOX.
     *
     * @var string
     */
    private $ref;

    /**
     * Wrap an open connection.
     *
     * @param resource $imap Connection.
     * @param string   $ref  Mailbox reference.
     */
    private function __construct($imap, $ref) {
        $this->imap = $imap;
        $this->ref  = $ref;
    }

    /**
     * Log in to the inbox. SSL on any port but 143, STARTTLS on 143; the
     * certificate is always checked.
     *
     * @param array{host:string,port:int} $server   Server name and port.
     * @param string                      $user     User name.
     * @param string                      $password Password.
     * @return SEOProStack_Helpdesk_Imap_Connection|WP_Error
     *
     * imap_open() warns as well as failing; the errors are read from imap_errors().
     * @SuppressWarnings("PHPMD.ErrorControlOperator")
     */
    public static function open(array $server, $user, $password) {
        $flags = 143 === $server['port'] ? '/imap/tls' : '/imap/ssl';
        $ref   = '{' . $server['host'] . ':' . $server['port'] . $flags . '}INBOX';
        imap_timeout(IMAP_OPENTIMEOUT, self::TIMEOUT);
        imap_timeout(IMAP_READTIMEOUT, self::TIMEOUT);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the reason is read from imap_errors() below.
        $imap = @imap_open($ref, $user, $password, 0, 1, array('DISABLE_AUTHENTICATOR' => 'GSSAPI'));
        if (!$imap) {
            return new WP_Error('helpdesk_imap_connect', self::error(__('Could not log in to the mailbox.', 'seoprostack')));
        }
        return new self($imap, $ref);
    }

    /**
     * Close the connection.
     */
    public function close() {
        imap_close($this->imap);
        self::error('');
    }

    /**
     * The inbox's UIDVALIDITY and next UID.
     *
     * @return array{validity:int,next:int}|WP_Error
     */
    public function status() {
        $status = imap_status($this->imap, $this->ref, SA_UIDVALIDITY | SA_UIDNEXT);
        if (!$status) {
            return new WP_Error('helpdesk_imap_status', self::error(__('Could not read the mailbox status.', 'seoprostack')));
        }
        return array('validity' => (int) $status->uidvalidity, 'next' => (int) $status->uidnext);
    }

    /**
     * UIDs of emails that arrived on or after a day.
     *
     * @param string $day Day, as 30-Oct-2026.
     * @return int[]
     */
    public function since($day) {
        $found = imap_search($this->imap, 'SINCE "' . $day . '"', SE_UID);
        return is_array($found) ? array_map('intval', $found) : array();
    }

    /**
     * UIDs after one.
     *
     * @param int $last UID.
     * @return int[]|WP_Error An error when the server did not answer, so the
     *                        run does not move past emails it never saw.
     */
    public function after($last) {
        $found = imap_fetch_overview($this->imap, ($last + 1) . ':*', FT_UID);
        if (!is_array($found)) {
            return new WP_Error('helpdesk_imap_list', self::error(__('Could not list new emails.', 'seoprostack')));
        }
        $uids = array();
        foreach ($found as $item) {
            // "N:*" also answers with the newest email when none is newer.
            if (isset($item->uid) && (int) $item->uid > $last) {
                $uids[] = (int) $item->uid;
            }
        }
        return $uids;
    }

    /**
     * An email's raw header.
     *
     * @param int $uid UID.
     * @return string
     */
    public function header($uid) {
        return (string) imap_fetchheader($this->imap, $uid, FT_UID);
    }

    /**
     * An email's MIME structure.
     *
     * @param int $uid UID.
     * @return object|null
     */
    public function structure($uid) {
        $structure = imap_fetchstructure($this->imap, $uid, FT_UID);
        return $structure ? $structure : null;
    }

    /**
     * One MIME part, still in its transfer encoding.
     *
     * @param int    $uid     UID.
     * @param string $section Section number, such as 1.2.
     * @return string
     */
    public function section($uid, $section) {
        return (string) imap_fetchbody($this->imap, $uid, $section, FT_UID | FT_PEEK);
    }

    /**
     * The server's folder names.
     *
     * @return string[]
     */
    public function folders() {
        $server = $this->server();
        $list   = imap_list($this->imap, $server, '*');
        $names  = array();
        foreach (is_array($list) ? $list : array() as $folder) {
            $names[] = self::folder_name(substr((string) $folder, strlen($server)), false);
        }
        return $names;
    }

    /**
     * Save a message to a folder, marked read, with the time it was sent.
     *
     * @param string $folder  Folder name.
     * @param string $message Raw message.
     * @param int    $time    When it was sent.
     * @return true|WP_Error
     */
    public function append($folder, $message, $time) {
        $message = (string) preg_replace('/\r?\n/', "\r\n", $message);
        $date    = gmdate('d-M-Y H:i:s', $time ? $time : time()) . ' +0000';
        if (!imap_append($this->imap, $this->server() . self::folder_name($folder, true), $message, '\\Seen', $date)) {
            return new WP_Error('helpdesk_imap_append', self::error(__('Could not save the email to the Sent folder.', 'seoprostack')));
        }
        return true;
    }

    /**
     * The server part of the reference, such as {mail.example.com:993/imap/ssl}.
     *
     * @return string
     */
    private function server() {
        return substr($this->ref, 0, (int) strpos($this->ref, '}') + 1);
    }

    /**
     * A folder name to or from IMAP's modified UTF-7.
     *
     * @param string $name   Name.
     * @param bool   $encode True to encode, false to decode.
     * @return string
     */
    private static function folder_name($name, $encode) {
        if ($encode) {
            return function_exists('imap_utf8_to_mutf7') ? (string) imap_utf8_to_mutf7($name) : (string) imap_utf7_encode($name);
        }
        return function_exists('imap_mutf7_to_utf8') ? (string) imap_mutf7_to_utf8($name) : (string) imap_utf7_decode($name);
    }

    /**
     * The last IMAP error after a fallback text; clears IMAP's error stack.
     *
     * @param string $fallback Text to start with.
     * @return string
     */
    public static function error($fallback) {
        $errors = imap_errors();
        imap_alerts();
        $last = is_array($errors) && $errors ? (string) end($errors) : '';
        return trim($fallback . ' ' . $last);
    }
}
