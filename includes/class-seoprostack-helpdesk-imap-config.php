<?php
/**
 * Fluent Support email by IMAP: the mailbox's settings and password, and
 * what is missing before email can be read.
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

class SEOProStack_Helpdesk_Imap_Config {

    /** Fluent Support Pro's email-to-ticket class. */
    const HANDLER = 'FluentSupportPro\App\Services\Integrations\FluentEmailPiping\ByMailHandler';

    /** Fluent Support's business inbox model. */
    const MAILBOX = 'FluentSupport\App\Models\MailBox';

    /**
     * The mailbox password.
     *
     * @return string
     */
    public static function password() {
        $name     = SEOProStack_Helpdesk_Imap::PASSWORD;
        $password = defined($name) ? (string) constant($name) : '';
        /**
         * Filter the IMAP password of Fluent Support email by IMAP, for sites
         * that keep secrets elsewhere than wp-config.php.
         *
         * @param string $password Password.
         */
        return (string) apply_filters('seoprostack_helpdesk_imap_password', $password);
    }

    /**
     * The IMAP user name.
     *
     * @return string
     */
    public static function user() {
        return trim((string) SEOProStack_Settings::get(SEOProStack_Helpdesk_Imap::USER_KEY));
    }

    /**
     * Server name and port from the setting.
     *
     * @return array{host:string,port:int}
     */
    public static function server() {
        $value = strtolower(trim((string) SEOProStack_Settings::get(SEOProStack_Helpdesk_Imap::HOST_KEY)));
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $port  = 993;
        if (preg_match('/^(.+):(\d{1,5})$/', $value, $match)) {
            $value = $match[1];
            $port  = (int) $match[2];
        }
        return array('host' => preg_match('/^[a-z0-9.-]+$/', $value) ? $value : '', 'port' => $port);
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
        if ('' === self::server()['host'] || '' === self::user()) {
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
        return class_exists(self::HANDLER) && method_exists(self::HANDLER, 'processPayload') && class_exists(self::MAILBOX);
    }

    /**
     * The Fluent Support business inbox new tickets go to.
     *
     * @return object|null MailBox model.
     */
    public static function mailbox() {
        if (!class_exists(self::MAILBOX)) {
            return null;
        }
        $id = (int) SEOProStack_Settings::get(SEOProStack_Helpdesk_Imap::MAILBOX_KEY);
        if ($id) {
            return \FluentSupport\App\Models\MailBox::where('id', $id)->first();
        }
        $user = strtolower(self::user());
        foreach (\FluentSupport\App\Models\MailBox::where('box_type', 'email')->get() as $box) {
            if (strtolower(trim((string) $box->email)) === $user) {
                return $box;
            }
        }
        $default = \FluentSupport\App\Models\MailBox::where('is_default', 'yes')->orderBy('id', 'ASC')->first();
        return $default ? $default : \FluentSupport\App\Models\MailBox::orderBy('id', 'ASC')->first();
    }
}
