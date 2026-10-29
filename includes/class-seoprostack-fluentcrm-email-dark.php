<?php
/**
 * FluentCRM emails in mail apps' dark mode: a setting per email, saved with
 * its design settings (`settings.template_config`, so it goes with
 * templates into campaigns, sequences and automations), chosen in the
 * email designer (admin/js/seoprostack-mail-dark.js):
 *
 * - Let the mail app choose (`auto`, the default): the email is sent as
 *   FluentCRM makes it, and each app darkens it as it likes.
 * - Keep light (`light`): `color-scheme: light only`, which Apple Mail and
 *   the other apps that read it follow by leaving the email light.
 * - Use my dark colours (`custom`): `color-scheme: light dark`, and the dark
 *   versions of the email's five global colours in a
 *   `prefers-color-scheme: dark` rule (and Outlook.com's `[data-ogsc]` and
 *   `[data-ogsb]` forms), for the apps that read them.
 *
 * Gmail and Outlook for Windows read neither and recolour emails themselves.
 *
 * The elements that use the global colours get classes
 * (SEOProStack_FluentCRM_Email_Dark_Marker), and only those change.
 * Runs after FluentCRM's inliner (10), which would copy the rules into the
 * elements, and only for emails with a setting other than the default.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.103
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_FluentCRM_Email_Dark {

    /** The setting's key in the email's design settings, and its values. */
    const KEY   = 'sps_dark_mode';
    const MODES = array('auto', 'light', 'custom');

    /** FluentCRM's designs that use its block template and its global colours. */
    const DESIGNS = array('block_editor', 'simple', 'plain', 'classic', 'web_preview');

    /** The stylesheet's id: the designer's Email Preview turns its rules on and off. */
    const STYLE_ID = 'seoprostack-email-dark';

    /**
     * Each dark colour: its key in the design settings, the global colour
     * it stands for, its class and its default.
     */
    const COLOURS = array(
        'b' => array('sps_dark_body_bg', 'body_bg_color', '#121212'),
        'c' => array('sps_dark_content_bg', 'content_bg_color', '#1e1e1e'),
        'h' => array('sps_dark_headings_color', 'headings_color', '#f2f2f2'),
        't' => array('sps_dark_text_color', 'text_color', '#e2e2e2'),
        'a' => array('sps_dark_link_color', 'link_color', '#6cb6ff'),
    );

    /** Register the filters. */
    public static function init() {
        foreach (self::DESIGNS as $design) {
            add_filter('fluent_crm/email-design-template-' . $design, array(__CLASS__, 'filter'), 11, 2);
        }
    }

    /**
     * The email's setting, from its design settings.
     *
     * @param mixed $config The email's design settings.
     * @return string One of MODES.
     */
    public static function mode($config) {
        $mode = is_array($config) && isset($config[self::KEY]) ? (string) $config[self::KEY] : '';
        return in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /**
     * The dark colours chosen, each a hex colour (the default when unset).
     *
     * @param mixed $config The email's design settings.
     * @return array<string,string> Class letter => colour.
     */
    public static function dark_colours($config) {
        $out = array();
        foreach (self::COLOURS as $letter => $colour) {
            $value        = is_array($config) && isset($config[$colour[0]]) ? sanitize_hex_color((string) $config[$colour[0]]) : '';
            $out[$letter] = $value ? $value : $colour[2];
        }
        return $out;
    }

    /**
     * Add the email's dark mode setting to its HTML.
     *
     * @param mixed $html          The email's HTML, as FluentCRM made it.
     * @param mixed $template_data FluentCRM's template data (`config`: design settings).
     * @return mixed
     */
    public static function filter($html, $template_data = array()) {
        $config = is_array($template_data) && isset($template_data['config']) ? $template_data['config'] : array();
        $mode   = self::mode($config);
        if ('auto' === $mode || !is_string($html) || false === stripos($html, '</head>') || false !== strpos($html, 'id="' . self::STYLE_ID . '"')) {
            return $html;
        }
        if ('light' === $mode) {
            $head = '<meta name="color-scheme" content="light only">' . "\n"
                . '<meta name="supported-color-schemes" content="light only">' . "\n"
                . '<style type="text/css" id="' . self::STYLE_ID . '-light">:root{color-scheme:light only;supported-color-schemes:light only}</style>' . "\n";
            return self::head($html, $head);
        }
        $light = self::light_colours($config);
        $html  = SEOProStack_FluentCRM_Email_Dark_Marker::mark($html, $light);
        $head  = '<meta name="color-scheme" content="light dark">' . "\n"
            . '<meta name="supported-color-schemes" content="light dark">' . "\n"
            . '<style type="text/css" id="' . self::STYLE_ID . '">' . self::css(self::dark_colours($config), $light) . '</style>' . "\n";
        return self::head($html, $head);
    }

    /**
     * The email's global colours.
     *
     * @param mixed $config The email's design settings.
     * @return array<string,string> Class letter => colour (six-digit hex, or '').
     */
    private static function light_colours($config) {
        $out = array();
        foreach (self::COLOURS as $letter => $colour) {
            $out[$letter] = is_array($config) && isset($config[$colour[1]]) ? SEOProStack_FluentCRM_Email_Dark_Marker::hex($config[$colour[1]]) : '';
        }
        return $out;
    }

    /**
     * Put markup at the end of the email's <head>.
     *
     * @param string $html The email.
     * @param string $add  The markup.
     * @return string
     */
    private static function head($html, $add) {
        $at = stripos($html, '</head>');
        if (false === $at) {
            return $html;
        }
        return substr($html, 0, $at) . $add . substr($html, $at);
    }

    /**
     * The dark rules: for apps that read `prefers-color-scheme`, and for
     * Outlook.com, which marks what it recolours with data-ogsc (text) and
     * data-ogsb (backgrounds). Inside a block with a background of its own
     * (sps-dm-k), which stays as it is, text keeps its light mode colours.
     *
     * @param array<string,string> $dark  Class letter => dark colour (sanitized hex).
     * @param array<string,string> $light Class letter => global colour (hex, or '').
     * @return string
     */
    private static function css($dark, $light) {
        $media   = '';
        $outlook = '';
        foreach (array('b', 'c', 'h', 't', 'a') as $letter) {
            $property = in_array($letter, array('b', 'c'), true) ? 'background-color' : 'color';
            $marker   = 'color' === $property ? '[data-ogsc] ' : '[data-ogsb] ';
            $media   .= self::rule(array('.sps-dm-' . $letter), $property, $dark[$letter]);
            $outlook .= self::rule(array($marker . '.sps-dm-' . $letter), $property, $dark[$letter]);
        }
        foreach (array('h', 't', 'a') as $letter) {
            if ($light[$letter]) {
                $keep     = array('.sps-dm-k .sps-dm-' . $letter, '.sps-dm-k.sps-dm-' . $letter);
                $media   .= self::rule($keep, 'color', $light[$letter]);
                $outlook .= self::rule(array('[data-ogsc] ' . $keep[0], '[data-ogsc] ' . $keep[1]), 'color', $light[$letter]);
            }
        }
        return ':root{color-scheme:light dark;supported-color-schemes:light dark}'
            . '@media (prefers-color-scheme: dark){' . $media . '}'
            . $outlook;
    }

    /**
     * One CSS rule that outweighs the email's inline colours.
     *
     * @param array<int,string> $selectors The selectors.
     * @param string            $property  The property.
     * @param string            $value     The colour (hex).
     * @return string
     */
    private static function rule($selectors, $property, $value) {
        return implode(',', $selectors) . '{' . $property . ':' . $value . '!important}';
    }
}
