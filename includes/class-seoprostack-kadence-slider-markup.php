<?php
/**
 * Kadence sliders and carousels: slides as div, not ul and li (GH#1528), for
 * Fixes for other plugins (SEOProStack_Plugin_Fixes).
 *
 * Splide (which every Kadence Blocks slider, Advanced Gallery carousel and
 * row background slider uses) gives each slide a role when it mounts:
 * tabpanel with dots, group without. Kadence prints the slides as
 * <li class="splide__slide"> in <ul class="splide__list">, and an li may not
 * take either role (axe's aria-allowed-role), so Lighthouse 13's Agentic
 * Browsing audit "Accessibility tree is well-formed" failed on every page
 * with a slider.
 *
 * Kadence's and Splide's styles select by class, and Splide accepts any
 * element, so the list and its direct slides are printed as div, with every
 * attribute kept. Lists inside a slide are left alone. Markup that does not
 * nest as expected is returned unchanged.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.202
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Slider_Markup {

    /**
     * Register hooks.
     */
    public static function boot() {
        if (is_admin()) {
            return;
        }
        foreach (array('kadence/slider', 'kadence/advancedgallery', 'kadence/rowlayout') as $name) {
            add_filter('render_block_' . $name, array(__CLASS__, 'filter'), 40);
        }
    }

    /**
     * Turn a block's Splide list and slides into div.
     *
     * @param mixed $html Block HTML.
     * @return mixed
     */
    public static function filter($html) {
        if (!is_string($html) || false === strpos($html, 'splide__') || !class_exists('SEOProStack_Plugin_Fixes') || !SEOProStack_Plugin_Fixes::enabled()) {
            return $html;
        }
        /**
         * Whether Kadence's slider and carousel slides are printed as div,
         * so the roles Splide gives them are valid. Off: ul and li, as
         * Kadence Blocks has them.
         *
         * @param bool $on Default true.
         */
        if (!apply_filters('seoprostack_kadence_slider_markup', true)) {
            return $html;
        }
        return self::convert($html);
    }

    /**
     * Rewrite the tags, or return the HTML unchanged when ul and li do not
     * nest as a stack.
     *
     * @param string $html HTML.
     * @return string
     */
    public static function convert($html) {
        $count = preg_match_all('~<(/?)(ul|li)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~i', $html, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        if (!$count) {
            return $html;
        }
        $stack = array();
        $edits = array();
        foreach ($found as $tag) {
            $close = '/' === $tag[1][0];
            $name  = strtolower($tag[2][0]);
            if ($close) {
                $top = array_pop($stack);
                if (null === $top || $top[0] !== $name) {
                    return $html;
                }
                if ($top[1]) {
                    $edits[] = array($tag[2][1], strlen($name));
                }
                continue;
            }
            $parent = $stack ? $stack[count($stack) - 1] : null;
            if ('li' === $name && $parent && 'li' === $parent[0]) {
                // An li left open: not the markup Kadence prints.
                return $html;
            }
            $change = false;
            if ('ul' === $name) {
                $change = self::has_class($tag[3][0], 'splide__list');
            } elseif ($parent && 'ul' === $parent[0] && $parent[1]) {
                $change = self::has_class($tag[3][0], 'splide__slide');
            }
            $stack[] = array($name, $change);
            if ($change) {
                $edits[] = array($tag[2][1], strlen($name));
            }
        }
        if ($stack || !$edits) {
            return $html;
        }
        for ($i = count($edits) - 1; $i >= 0; $i--) {
            $html = substr_replace($html, 'div', $edits[$i][0], $edits[$i][1]);
        }
        return $html;
    }

    /**
     * Whether a tag's attributes give it a class.
     *
     * @param string $attrs Attribute text of the tag.
     * @param string $class Class name.
     * @return bool
     */
    private static function has_class($attrs, $class) {
        if (!preg_match('~\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $attrs, $match)) {
            return false;
        }
        $value = '' !== $match[1] ? $match[1] : ($match[2] ?? '');
        $names = preg_split('/\s+/', trim($value));
        return is_array($names) && in_array($class, $names, true);
    }
}
