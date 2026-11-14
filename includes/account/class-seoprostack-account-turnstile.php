<?php
/**
 * My Account without WooCommerce: Simple Cloudflare Turnstile's checks.
 *
 * Simple Cloudflare Turnstile loads its WooCommerce checks (log in, sign up,
 * lost password, account details) only while WooCommerce is active. They
 * hook only WooCommerce's form hooks, which My Account fires, so this loads
 * the same file: its own switches (WooCommerce Login, Register, Reset
 * Password, My Account) and its own markup and checks.
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

final class SEOProStack_Account_Turnstile {

    /**
     * Load the plugin's WooCommerce checks, when it is active with tested
     * keys and has not loaded them itself.
     */
    public static function boot() {
        if (!function_exists('cfturnstile_field_show') || function_exists('cfturnstile_field_woo_login')) {
            return;
        }
        $tested = get_option('cfturnstile_tested');
        if (!empty($tested) && 'yes' !== $tested) {
            return;
        }
        $file = self::file();
        if ('' !== $file && is_readable($file)) {
            include_once $file;
        }
    }

    /**
     * The plugin's integrations/ecommerce/woocommerce.php, or ''.
     *
     * @return string
     */
    private static function file() {
        try {
            $main = (string) (new ReflectionFunction('cfturnstile_field_show'))->getFileName();
        } catch (ReflectionException $e) {
            return '';
        }
        return '' !== $main ? dirname($main) . '/integrations/ecommerce/woocommerce.php' : '';
    }
}
