<?php
/**
 * Parts of a screen on wp-login.php, printed with the ids and classes core's
 * login styles use. Each screen prints one form and one #nav paragraph, so
 * the ids stay unique.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.230
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Login_Screen {

    /**
     * The submit button of the screen's form.
     *
     * @param string $label Button text.
     */
    public static function submit_button($label) {
        printf(
            '<p class="submit"><input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="%s" /></p>',
            esc_attr($label)
        );
    }

    /**
     * Links under the form, in core's #nav paragraph, separated as core
     * separates them.
     *
     * @param array<string,string> $links Link text => address.
     */
    public static function nav_links(array $links) {
        $html = array();
        foreach ($links as $text => $url) {
            $html[] = '<a href="' . esc_url($url) . '">' . esc_html((string) $text) . '</a>';
        }
        echo wp_kses('<p id="nav">' . implode(' <span aria-hidden="true">|</span> ', $html) . '</p>', array(
            'p'    => array('id' => true),
            'a'    => array('href' => true),
            'span' => array('aria-hidden' => true),
        ));
    }
}
