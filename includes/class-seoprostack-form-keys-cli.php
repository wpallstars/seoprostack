<?php
/**
 * Fluent Forms by key: wp seoprostack form-keys list, set and unset.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.97
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * List, set or remove Fluent form keys on this site.
 */
class SEOProStack_Form_Keys_CLI {

    /**
     * List this site's forms with their keys.
     *
     * ## OPTIONS
     *
     * [--copy-from=<meta-key>]
     * : Also show each form's value of another form meta key.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack form-keys list
     *     wp seoprostack form-keys list --copy-from=old_form_key
     *
     * @subcommand list
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Associative arguments.
     */
    public function list_($args, $assoc) {
        unset($args);
        $copy   = self::copy_from($assoc);
        $fields = array('id', 'title', 'status', 'form_key');
        if ('' !== $copy) {
            $fields[] = 'other';
        }
        WP_CLI\Utils\format_items('table', SEOProStack_Form_Key_Store::forms($copy), $fields);
    }

    /**
     * Give a form a key, or give every form that has another meta key that
     * value as its key.
     *
     * ## OPTIONS
     *
     * [<form-id>]
     * : Fluent form ID.
     *
     * [<key>]
     * : Key, lower-case words joined by hyphens.
     *
     * [--copy-from=<meta-key>]
     * : With no form ID, give every form that has this meta key the same value
     *   as its key (forms that already have a key are left alone).
     *
     * ## EXAMPLES
     *
     *     wp seoprostack form-keys set 3 contact
     *     wp seoprostack form-keys set --copy-from=old_form_key
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Associative arguments.
     */
    public function set($args, $assoc) {
        $copy = self::copy_from($assoc);
        if ('' !== $copy && !isset($args[0])) {
            self::copy($copy);
            return;
        }
        $form_id = self::existing_form($args);
        $key     = (string) ($args[1] ?? '');
        if (!SEOProStack_Form_Key_Store::valid($key)) {
            WP_CLI::error('A key is lower-case letters and digits, words joined by hyphens.');
        }
        $other = SEOProStack_Form_Key_Store::form_id($key);
        if ($other && $other !== $form_id) {
            WP_CLI::warning(sprintf('Form %d already has the key %s and, as the older form, keeps showing for it.', $other, $key));
        }
        SEOProStack_Form_Key_Store::set($form_id, $key);
        WP_CLI::success(sprintf('Form %d: %s', $form_id, $key));
    }

    /**
     * Remove a form's key.
     *
     * ## OPTIONS
     *
     * <form-id>
     * : Fluent form ID.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack form-keys unset 3
     *
     * @subcommand unset
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Associative arguments.
     */
    public function unset_($args, $assoc) {
        unset($assoc);
        $form_id = self::existing_form($args);
        SEOProStack_Form_Key_Store::set($form_id, '');
        WP_CLI::success(sprintf('Form %d has no key.', $form_id));
    }

    /**
     * The --copy-from meta key, or ''.
     *
     * @param array $assoc Associative arguments.
     * @return string
     */
    private static function copy_from($assoc) {
        return isset($assoc['copy-from']) ? sanitize_key((string) $assoc['copy-from']) : '';
    }

    /**
     * The form ID in the first argument; stops when no such form exists.
     *
     * @param array $args Positional arguments.
     * @return int
     */
    private static function existing_form($args) {
        $form_id = (int) ($args[0] ?? 0);
        if (!SEOProStack_Form_Key_Store::exists($form_id)) {
            WP_CLI::error('Give an existing Fluent form ID (wp seoprostack form-keys list).');
        }
        return $form_id;
    }

    /**
     * Give every unkeyed form with another meta key that value as its key.
     *
     * @param string $other Another form meta key.
     */
    private static function copy($other) {
        $done = 0;
        foreach (SEOProStack_Form_Key_Store::unkeyed_with($other) as $form_id => $key) {
            if (SEOProStack_Form_Key_Store::valid($key) && SEOProStack_Form_Key_Store::set($form_id, $key)) {
                WP_CLI::log(sprintf('Form %d: %s', $form_id, $key));
                ++$done;
            }
        }
        WP_CLI::success(sprintf('%d form keys set.', $done));
    }
}
