<?php
/**
 * Fluent Support email by IMAP: one email in the shape Fluent's relay pushes
 * to the site, for ByMailHandler::processPayload().
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

class SEOProStack_Helpdesk_Imap_Message {

    /**
     * The email as Fluent's relay sends it, or null when it has no header.
     * Its mime key, from UIDVALIDITY, UID and Message-ID, is what Fluent
     * Support checks so an email read twice makes one ticket.
     *
     * @param SEOProStack_Helpdesk_Imap_Connection $conn     Connection.
     * @param int                                  $uid      UID.
     * @param int                                  $validity UIDVALIDITY.
     * @param object|null                          $box      MailBox model, whose address is never the customer.
     * @return array<string,mixed>|null
     */
    public static function payload($conn, $uid, $validity, $box = null) {
        $raw = $conn->header($uid);
        if ('' === $raw) {
            return null;
        }
        $fields = get_object_vars(imap_rfc822_parse_headers($raw)) + array(
            'message_id'  => '',
            'subject'     => '',
            'in_reply_to' => '',
            'references'  => '',
            'from'        => array(),
            'reply_to'    => array(),
            'to'          => array(),
            'cc'          => array(),
        );
        $message = trim((string) $fields['message_id']);
        $mime    = new SEOProStack_Helpdesk_Imap_Mime($conn, $uid);
        $headers = self::header_map($raw);
        $own     = array_filter(array(
            strtolower(SEOProStack_Helpdesk_Imap_Config::user()),
            $box && isset($box->email) ? strtolower(trim((string) $box->email)) : '',
        ));

        return array(
            'subject'     => SEOProStack_Helpdesk_Imap_Mime::decode_header((string) $fields['subject']),
            'body_text'   => self::cut(self::content($mime->read())),
            'messageId'   => $message,
            'inReplyTo'   => (string) $fields['in_reply_to'],
            'references'  => (string) $fields['references'],
            'attachments' => array(),
            'isMarkDown'  => false,
            'from'        => array('value' => self::sender($fields, $headers, $own)),
            'to'          => array('value' => self::addresses($fields['to'])),
            'cc'          => array('value' => self::addresses($fields['cc'])),
            'headers'     => $headers,
            'mime'        => 'imap-' . substr(hash('sha256', $validity . ':' . $uid . ':' . $message), 0, 32),
        );
    }

    /**
     * The ticket text: the HTML part, else the plain text, and the names of
     * attachments left in the mailbox.
     *
     * @param SEOProStack_Helpdesk_Imap_Mime $mime Read email.
     * @return string
     */
    private static function content($mime) {
        $content = '' !== $mime->html ? self::clean_html($mime->html) : wpautop(esc_html($mime->text));
        if ($mime->files) {
            $content .= '<p><em>' . esc_html(sprintf(
                /* translators: %s: attachment file names */
                __('Attachments (in the support mailbox): %s', 'seoprostack'),
                implode(', ', $mime->files)
            )) . '</em></p>';
        }
        return $content;
    }

    /**
     * Who the ticket is from: the one Reply-To address, when the email has
     * one that is not this inbox's own, else From. A site's mail plugin
     * usually sends from the site's own address (FluentSMTP's Force From
     * Email, which Amazon SES needs) and keeps the person who wrote, such as
     * a problem report's administrator, as Reply-To; replies then reach them.
     * PHP fills reply_to from From when there is no Reply-To header, so the
     * header itself is checked.
     *
     * @param array                $fields  Parsed header fields.
     * @param array<string,string> $headers Header fields by lower-case name.
     * @param string[]             $own     This inbox's addresses, lower case.
     * @return array<int,array{name:string,address:string}>
     */
    private static function sender(array $fields, array $headers, array $own) {
        $from = self::addresses($fields['from']);
        if (!isset($headers['reply-to'])) {
            return $from;
        }
        $reply = self::addresses($fields['reply_to']);
        if (1 !== count($reply) || in_array(strtolower($reply[0]['address']), $own, true)) {
            return $from;
        }
        return $reply;
    }

    /**
     * Addresses in the shape Fluent's relay sends: name and address.
     *
     * @param mixed $list Address objects from imap_rfc822_parse_headers().
     * @return array<int,array{name:string,address:string}>
     */
    private static function addresses($list) {
        $out = array();
        foreach ((array) $list as $item) {
            $item    = (array) $item + array('mailbox' => '', 'host' => '', 'personal' => '');
            $address = sanitize_email($item['mailbox'] . '@' . $item['host']);
            if ('' !== $address) {
                $out[] = array(
                    'name'    => SEOProStack_Helpdesk_Imap_Mime::decode_header((string) $item['personal']),
                    'address' => $address,
                );
            }
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
        $map = array();
        foreach (explode("\n", (string) preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw))) as $line) {
            if (!preg_match('/^([A-Za-z0-9-]+):(.*)$/', $line, $match)) {
                continue;
            }
            $name = strtolower($match[1]);
            if (!isset($map[$name])) {
                $map[$name] = trim($match[2]);
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
        // On a PCRE limit (a long or unclosed style block) keep the HTML
        // rather than lose the whole ticket text.
        $clean = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html  = null === $clean ? $html : $clean;
        if (preg_match('#<body\b[^>]*>(.*)</body\s*>#is', $html, $match)) {
            $html = $match[1];
        }
        return trim($html);
    }

    /**
     * The ticket text cut to MAX_TEXT bytes without splitting a character.
     *
     * @param string $text UTF-8 text.
     * @return string
     */
    private static function cut($text) {
        if (strlen($text) <= SEOProStack_Helpdesk_Imap::MAX_TEXT) {
            return $text;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, SEOProStack_Helpdesk_Imap::MAX_TEXT, 'UTF-8');
        }
        return wp_check_invalid_utf8(substr($text, 0, SEOProStack_Helpdesk_Imap::MAX_TEXT), true);
    }
}
