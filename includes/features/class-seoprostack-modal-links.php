<?php
/**
 * Modal links: links to #id of a modal or drawer open it.
 *
 * Kadence opens its drawers (#login-drawer, #search-drawer, #mobile-drawer,
 * #cart-drawer) only from its own toggles, and its Modal block only from
 * links that had the modal-trigger class when the page loaded. A link in
 * text, a button or an image to #login-drawer only jumped to a hidden
 * element. With this on, such a link opens the modal or drawer, through the
 * opener that already owns it where there is one, so editors can open the
 * login box, search, a Kadence Modal block (by its anchor) or a <dialog>
 * from anywhere in the block editor. A page loaded with such a hash opens it
 * too.
 *
 * The work is in assets/modal-links.js, loaded in the footer on the front
 * end. Nothing is stored and pages are not rewritten.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.81
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Modal_Links extends SEOProStack_Feature {

    const KEY = 'modal_links';

    const HANDLE = 'seoprostack-modal-links';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'links',
                'label'       => __('Modal links', 'seoprostack'),
                'description' => __('A link to the ID of a modal or drawer on the page opens it, so any text, button or image link can open the login box (#login-drawer), search (#search-drawer), the mobile menu (#mobile-drawer), a Kadence Modal block (by its anchor) or a dialog. Other links to IDs on the page still scroll there.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    /**
     * Load the script in the footer.
     */
    public static function enqueue() {
        $js = 'assets/modal-links.js';
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $js, array(), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        /**
         * Filter CSS selectors of other kinds of modal or drawer that a link
         * to their ID opens. Dialogs, elements with role="dialog" or
         * aria-modal="true", Kadence drawers and Kadence Modal blocks are
         * always covered.
         *
         * @param string[] $selectors CSS selectors.
         */
        $selectors = array_values(array_filter(array_map('strval', (array) apply_filters('seoprostack_modal_links_selectors', array())), 'strlen'));
        if ($selectors) {
            wp_add_inline_script(self::HANDLE, 'window.seoprostackModalLinks = ' . wp_json_encode(array('selectors' => $selectors)) . ';', 'before');
        }
    }
}
