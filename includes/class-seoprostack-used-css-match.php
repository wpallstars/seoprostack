<?php
/**
 * Used CSS: which of a stylesheet's rules one page can use.
 *
 * A rule is used when the page has every class, ID and element one of its
 * selectors names. Classes and elements the page's scripts may add after it
 * loads count as present (SEOProStack_Used_Css_Scripts), and so do the
 * classes in CLASSES that Kadence's, Kadence Blocks', Fluent Forms' and
 * their libraries' scripts add (menus, drawers, the sticky header, tabs,
 * accordions, dark mode, form errors, the phone field's flags, dropdowns
 * and date pickers), so those parts look right before the full stylesheets
 * arrive. The `seoprostack_used_css_classes` filter adds more ("name" or
 * "prefix*").
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Used_Css_Match {

    /**
     * Classes the supported scripts add after the page loads, so rules for
     * them are kept; a trailing `*` is a prefix. Found in Kadence's
     * navigation.js, Kadence Blocks' tabs, accordion, navigation, header and
     * off-canvas scripts, Kadence Pro's dark mode, Fluent Forms'
     * form-submission.js and its intl-tel-input, Choices and flatpickr.
     */
    const CLASSES = array(
        // States many scripts use.
        'active', 'open', 'opened', 'show', 'triggered', 'initialized', 'is-*',
        // Kadence: menus, drawers, sticky header and sidebar, dark mode.
        'current-menu-item', 'menu-item--toggled-on', 'menu-item--has-toggle', 'toggle-show', 'sub-menu-*',
        'dropdown-nav-special-toggle', 'kadence-menu-mega-enabled', 'show-drawer', 'pop-animated',
        'showing-popup-drawer-*', 'kadence-scrollbar-fixer', 'kadence-drawer-opened', 'kadence-drawer-closed',
        'hide-focus-outline', 'scroll-visible', 'item-hidden-above', 'item-is-fixed', 'item-at-start',
        'item-is-stuck', 'child-is-fixed', 'header-is-fixed', 'has-sticky-sidebar', 'has-sticky-sidebar-widget',
        'color-switch-dark', 'color-switch-light',
        // Kadence Blocks: tabs, accordions, navigation, header, off-canvas, show more, sliders, lightbox.
        'kt-active-tab-*', 'kt-tab-title-active', 'kt-tab-title-inactive', 'kt-tabs-accordion-title*', 'kt-create-accordion',
        'kt-accordion-initialized', 'kt-accordion-panel-active', 'kt-accordion-panel-hidden',
        'kt-panel-is-collapsing', 'kt-panel-is-expanding', 'kb-nav-esc-close', 'kb-modal-open', 'kb-scrollbar-fixer',
        'kb-header-sticky-wrapper', 'kb-header-placeholder-wrapper', 'show-off-canvas', 'kb-smc-open',
        'wp-block-kadence-show-more', 'splide*', 'glightbox*', 'gslide*', 'tippy*',
        // Fluent Forms: errors, messages, submitting, steps, and its phone, select and date libraries.
        'error', 'text-danger', 'error-text', 'error-clear', 'ff-el-is-error', 'ff-message-success', 'ff-hidden',
        'ff_force_hide', 'ff_submitting', 'ff-working', 'ff-form-loading', 'ff-form-loaded', 'ff_item_selected',
        'ff-el-pop-content', 'ff_excluded', 'ff_tokenizing', 'ff_tokenized', 'ff-form-inline', 'ff_numeric',
        'ff_el_with_extended_validation', 'ff_other_error_*', 'iti*', 'choices*', 'flatpickr*',
        // WordPress: the navigation block's menu and the image lightbox.
        'has-modal-open', 'wp-lightbox-overlay', 'zoom', 'show-closing-animation',
    );

    /** The page, read for its tokens when a stylesheet first needs them. */
    private $html;

    /** The page's classes (`.name`), IDs (`#name`) and elements: token => present, or null before reading. */
    private $present = null;

    /** Prefixes of classes counted as present (regex), or ''. */
    private $prefixes = '';

    /**
     * Words in the page's scripts that could be class or element names, and
     * prefixes the scripts build class names from.
     *
     * @var array{names: array<string,bool>, prefixes: array<string,bool>}
     */
    private $scripts = array('names' => array(), 'prefixes' => array());

    /**
     * Rules for one page.
     *
     * @param string $html Page.
     */
    public function __construct($html) {
        $this->html = (string) $html;
    }

    /**
     * The rules this page can use.
     *
     * @param array $rules Rules (SEOProStack_Used_Css_Parser).
     * @return string
     */
    public function used(array $rules) {
        if (null === $this->present) {
            $this->present  = self::tokens($this->html);
            $this->prefixes = self::prefixes();
            $this->scripts  = SEOProStack_Used_Css_Scripts::classes($this->html);
            $this->html     = '';
        }
        $out = '';
        foreach ($rules as $node) {
            $out .= $this->node((array) $node);
        }
        return $out;
    }

    /**
     * One rule or block, if the page can use it.
     *
     * @param array $node Rule or block.
     * @return string
     */
    private function node(array $node) {
        if (isset($node['k'])) {
            return (string) $node['k'];
        }
        if (isset($node['g'])) {
            $inner = $this->used((array) $node['c']);
            // An empty @layer block still sets the order of layers.
            return '' !== $inner || 0 === stripos($node['g'], '@layer') ? $node['g'] . '{' . $inner . '}' : '';
        }
        return isset($node['r']) && $this->matches((array) $node['n']) ? (string) $node['r'] : '';
    }

    /**
     * Whether the page has all that one of a rule's selectors needs.
     *
     * @param array $selectors What each selector needs.
     * @return bool
     */
    private function matches(array $selectors) {
        foreach ($selectors as $needs) {
            foreach ((array) $needs as $token) {
                if (!$this->has((string) $token)) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Whether the page has a class, ID or element.
     *
     * @param string $token `.class`, `#id` or element.
     * @return bool
     */
    private function has($token) {
        if (!isset($this->present[$token])) {
            $this->present[$token] = '' !== $token && $this->added($token);
        }
        return $this->present[$token];
    }

    /**
     * Whether a class or element the page's HTML does not have may be added
     * by its scripts.
     *
     * @param string $token `.class`, `#id` or element.
     * @return bool
     */
    private function added($token) {
        if ('#' === $token[0]) {
            return false;
        }
        if ('.' !== $token[0]) {
            return isset($this->scripts['names'][$token]); // An element a script may create.
        }
        $class = substr($token, 1);
        if (isset($this->scripts['names'][$class]) || ('' !== $this->prefixes && preg_match($this->prefixes, $class))) {
            return true;
        }
        return $this->built($class);
    }

    /**
     * Whether a class starts with a prefix a script builds class names
     * from: each start of the name up to a - or _.
     *
     * @param string $class Class name.
     * @return bool
     */
    private function built($class) {
        $length = strlen($class);
        for ($i = SEOProStack_Used_Css_Scripts::MIN_PREFIX - 1; $i < $length; $i++) {
            if (('-' === $class[$i] || '_' === $class[$i]) && isset($this->scripts['prefixes'][substr($class, 0, $i + 1)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The page's classes, IDs and elements, with the classes scripts add.
     *
     * @param string $html Page.
     * @return array<string,bool>
     */
    private static function tokens($html) {
        $present = SEOProStack_Used_Css_Selector::ALWAYS;
        if (preg_match_all('/<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $tags)) {
            foreach (array_unique($tags[1]) as $tag) {
                $present[strtolower($tag)] = true;
            }
        }
        if (preg_match_all('/\s(class|id)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $found, PREG_SET_ORDER)) {
            foreach ($found as $m) {
                $present = self::attribute($present, $m);
            }
        }
        foreach (self::classes() as $class) {
            if ('*' !== substr($class, -1)) {
                $present['.' . $class] = true;
            }
        }
        return $present;
    }

    /**
     * One class or id attribute's tokens added.
     *
     * @param array<string,bool> $present Tokens so far.
     * @param array              $m       Match: name, then the value as quoted or not.
     * @return array<string,bool>
     */
    private static function attribute(array $present, array $m) {
        $value = (isset($m[2]) ? $m[2] : '') . (isset($m[3]) ? $m[3] : '') . (isset($m[4]) ? $m[4] : '');
        $value = false !== strpos($value, '&') ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
        $mark  = 'id' === strtolower($m[1]) ? '#' : '.';
        $names = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        foreach (is_array($names) ? $names : array() as $name) {
            $present[$mark . $name] = true;
        }
        return $present;
    }

    /**
     * Classes counted as present: "name", or "prefix*".
     *
     * @return string[]
     */
    private static function classes() {
        /**
         * Classes that scripts add after the page loads, so Used CSS keeps
         * their rules: "name", or "prefix*" for every class starting so.
         *
         * @param string[] $classes Classes.
         */
        return array_values(array_filter(array_map('strval', (array) apply_filters('seoprostack_used_css_classes', self::CLASSES))));
    }

    /**
     * A regex matching the class prefixes, or ''.
     *
     * @return string
     */
    private static function prefixes() {
        $prefixes = array();
        foreach (self::classes() as $class) {
            if ('*' === substr($class, -1) && '*' !== $class) {
                $prefixes[] = preg_quote(substr($class, 0, -1), '/');
            }
        }
        return $prefixes ? '/^(?:' . implode('|', $prefixes) . ')/' : '';
    }
}
