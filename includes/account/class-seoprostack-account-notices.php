<?php
/**
 * My Account without WooCommerce: notices (wc_add_notice() and
 * wc_print_notices()), kept per user over a redirect.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.218
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Account_Notices {

    /** Transient prefix (+ user ID): notices kept over a redirect. */
    const KEPT = 'seoprostack_account_notices_';

    /**
     * Notices for this request, by type (error, success, notice).
     *
     * @var array<string,array<int,array{0:string,1:array}>>
     */
    private static $notices = array();

    /**
     * Add a notice.
     *
     * @param string $message HTML.
     * @param string $type    error, success or notice.
     * @param array  $data    Data (an id, for errors about a field).
     */
    public static function add($message, $type = 'success', $data = array()) {
        $type = in_array($type, array('error', 'success', 'notice'), true) ? $type : 'success';
        self::load();
        self::$notices[$type][] = array((string) $message, is_array($data) ? $data : array());
    }

    /**
     * Number of notices of a type (all types when '').
     *
     * @param string $type Type.
     * @return int
     */
    public static function get_count($type = '') {
        self::load();
        if ('' === $type) {
            return array_sum(array_map('count', self::$notices));
        }
        return isset(self::$notices[$type]) ? count(self::$notices[$type]) : 0;
    }

    /**
     * Drop every notice.
     */
    public static function clear() {
        self::load();
        self::$notices = array();
    }

    /**
     * Notices kept over the last redirect, once.
     */
    private static function load() {
        static $loaded = false;
        if ($loaded || !did_action('init')) {
            return;
        }
        $loaded  = true;
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }
        $kept = get_transient(self::KEPT . $user_id);
        if (is_array($kept)) {
            delete_transient(self::KEPT . $user_id);
            self::$notices = array_merge_recursive($kept, self::$notices);
        }
    }

    /**
     * Keep this request's notices for the page a redirect goes to.
     *
     * @param mixed $location Redirect address.
     * @return mixed
     */
    public static function keep($location) {
        $user_id = get_current_user_id();
        if ($user_id > 0 && self::$notices && self::get_count() > 0) {
            set_transient(self::KEPT . $user_id, self::$notices, 5 * MINUTE_IN_SECONDS);
        }
        return $location;
    }

    /**
     * Print the notices and drop them, in WooCommerce's markup.
     */
    public static function output() {
        self::load();
        echo '<div class="woocommerce-notices-wrapper">';
        foreach (array('error', 'success', 'notice') as $type) {
            if (!empty(self::$notices[$type])) {
                self::print_type($type, self::$notices[$type]);
            }
        }
        echo '</div>';
        self::$notices = array();
    }

    /**
     * Print the notices of one type: errors in a list, the others one box
     * each.
     *
     * @param string                              $type    Type.
     * @param array<int,array{0:string,1:array}> $notices Notices.
     */
    private static function print_type($type, $notices) {
        if ('error' !== $type) {
            $class = 'success' === $type ? 'woocommerce-message' : 'woocommerce-info';
            $role  = 'success' === $type ? 'alert' : 'status';
            foreach ($notices as $notice) {
                echo '<div class="' . esc_attr($class) . '" role="' . esc_attr($role) . '">' . wp_kses_post($notice[0]) . '</div>';
            }
            return;
        }
        echo '<ul class="woocommerce-error" role="alert">';
        foreach ($notices as $notice) {
            $id = isset($notice[1]['id']) ? ' data-id="' . esc_attr((string) $notice[1]['id']) . '"' : '';
            echo '<li' . $id . '>' . wp_kses_post($notice[0]) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id escaped above.
        }
        echo '</ul>';
    }
}
