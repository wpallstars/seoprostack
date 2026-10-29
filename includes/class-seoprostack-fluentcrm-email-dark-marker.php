<?php
/**
 * Marks the elements of a FluentCRM email that use its global colours, for
 * SEOProStack_FluentCRM_Email_Dark's dark colours.
 *
 * FluentCRM writes the global colours into each element's style attribute
 * (its CSS inliner), and an element with a colour chosen for one block
 * looks the same there. So the elements whose colour is one of the global
 * ones get a class (sps-dm-b, -c, -h, -t, -a), and only those change in
 * dark mode: blocks with colours of their own keep them, as in light mode,
 * and so does the text inside a block with a background of its own
 * (sps-dm-k), which stays light.
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

final class SEOProStack_FluentCRM_Email_Dark_Marker {

    /**
     * A colour as six lower-case hex digits, or '' for anything else.
     *
     * @param mixed $value A CSS colour.
     * @return string
     */
    public static function hex($value) {
        $value = strtolower(trim((string) $value));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $value)) {
            return $value;
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*1(?:\.0+)?\s*)?\)$/', $value, $m)) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }
        return '';
    }

    /**
     * Give each element in the <body> whose colour is a global one that
     * colour's class, and each with a background of its own sps-dm-k.
     *
     * @param string               $html  The email.
     * @param array<string,string> $light Class letter => global colour (hex, or '').
     * @return string
     */
    public static function mark($html, $light) {
        $at = stripos($html, '<body');
        if (false === $at) {
            return $html;
        }
        $body = preg_replace_callback(
            '~<([a-z][a-z0-9]*)((?:\s[^<>]*?)?\sstyle=(["\'])(.*?)\3[^<>]*)>~is',
            static function ($match) use ($light) {
                $classes = self::classes(strtolower($match[1]), $match[2], $match[4], $light);
                if (!$classes) {
                    return $match[0];
                }
                return '<' . $match[1] . self::add_classes($match[2], $classes) . '>';
            },
            substr($html, $at)
        );
        return null === $body ? $html : substr($html, 0, $at) . $body;
    }

    /**
     * The classes for one element, from its style attribute.
     *
     * @param string               $tag   The element's name.
     * @param string               $attrs Its attributes.
     * @param string               $style Its style attribute's value.
     * @param array<string,string> $light Class letter => the global colour (hex, or '').
     * @return array<int,string>
     */
    private static function classes($tag, $attrs, $style, $light) {
        $colours = self::style_colours($style);
        $out     = array();
        if ($colours['ground']) {
            $out[] = self::ground_class($tag, $attrs, $colours['ground'], $light);
        }
        $text = $colours['colour'] ? self::colour_class($tag, $colours['colour'], $light) : '';
        if ($text) {
            $out[] = $text;
        }
        return $out;
    }

    /**
     * The text colour and background colour in a style attribute.
     *
     * @param string $style The style attribute's value.
     * @return array{colour:string,ground:string} Each six-digit hex, or ''.
     */
    private static function style_colours($style) {
        $found = array();
        foreach (explode(';', html_entity_decode($style, ENT_QUOTES)) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 === count($parts)) {
                $value = preg_replace('/\s*!important\s*$/i', '', $parts[1]);
                $name  = strtolower(trim($parts[0]));

                $found[$name] = trim(null === $value ? $parts[1] : $value);
            }
        }
        $ground = isset($found['background-color']) ? self::hex($found['background-color']) : '';
        if ('' === $ground && isset($found['background'])) {
            $ground = self::hex(strtok($found['background'], ' '));
        }
        return array(
            'colour' => isset($found['color']) ? self::hex($found['color']) : '',
            'ground' => $ground,
        );
    }

    /**
     * The class for an element's background: the page, the content area,
     * or a background of its own, such as a box or a button, which stays.
     *
     * @param string               $tag    The element's name.
     * @param string               $attrs  Its attributes.
     * @param string               $ground Its background colour (hex).
     * @param array<string,string> $light  Class letter => the global colour (hex, or '').
     * @return string
     */
    private static function ground_class($tag, $attrs, $ground, $light) {
        if ($ground === $light['c']) {
            $content = (bool) preg_match('/\bclass=["\'][^"\']*\b(?:fc_email_body|fcTextContentBody)\b/i', $attrs);
            $page    = 'body' === $tag || (bool) preg_match('/\bclass=["\'][^"\']*\bfc_block_template\b/i', $attrs);
            if ($content || !$page) {
                return 'sps-dm-c';
            }
        }
        return $ground === $light['b'] ? 'sps-dm-b' : 'sps-dm-k';
    }

    /**
     * The class for an element's text colour, when it is a global one: for
     * links the link colour first, for headings the headings colour.
     *
     * @param string               $tag    The element's name.
     * @param string               $colour Its text colour (hex).
     * @param array<string,string> $light  Class letter => the global colour (hex, or '').
     * @return string The class, or ''.
     */
    private static function colour_class($tag, $colour, $light) {
        $order = array('t', 'h', 'a');
        if ('a' === $tag) {
            $order = array('a', 't', 'h');
        } elseif (preg_match('/^h[1-6]$/', $tag)) {
            $order = array('h', 't', 'a');
        }
        foreach ($order as $letter) {
            if ($colour === $light[$letter]) {
                return 'sps-dm-' . $letter;
            }
        }
        return '';
    }

    /**
     * Add classes to an element's attributes.
     *
     * @param string            $attrs   The attributes, with the space before them.
     * @param array<int,string> $classes The classes.
     * @return string
     */
    private static function add_classes($attrs, $classes) {
        $list  = implode(' ', $classes);
        $added = preg_replace('/(\sclass=)(["\'])(.*?)\2/is', '$1$2$3 ' . $list . '$2', $attrs, 1, $count);
        if ($count && null !== $added) {
            return $added;
        }
        return ' class="' . $list . '"' . $attrs;
    }
}
