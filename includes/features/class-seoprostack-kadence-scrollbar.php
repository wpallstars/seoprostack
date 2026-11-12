<?php
/**
 * Measure the Kadence theme's scrollbar width without holding up the page.
 *
 * The Kadence theme (checked with 1.6.0) prints, at the end of every page
 * (Kadence\Base_Support\Component::action_add_scrollbar_offset_script() on
 * wp_footer at priority 2), a script that sets --scrollbar-offset on the
 * html element from window.innerWidth minus the html element's clientWidth.
 * Reading clientWidth while the page is still being read makes the browser
 * style and lay out everything so far at once: PageSpeed Insights lists it
 * under Forced reflow (GitHub issue #1552). Kadence reads the property for
 * transparent headers (.kadence-scrollbar-fixer, with a fallback of 0) and
 * in --global-vw, which full-width blocks read as var(--global-vw, 100vw):
 * unset, both give what a width of 0 gives.
 *
 * This prints a script that measures from a ResizeObserver instead, whose
 * callback runs after the browser's own layout and before it paints, so
 * nothing is laid out early and the first paint has the value. It sets the
 * property only when the width is not 0 (or changes back), so on phones,
 * whose scrollbars take no room, styles are never worked out again. Being
 * an observer, it also keeps the value right when the window or page
 * changes size, which Kadence's one-off read did not. Without
 * ResizeObserver it measures at once, as Kadence did.
 *
 * Lighthouse mobile, 5 runs each on a Kadence home page: forced reflow
 * 272 ms to 152 ms (Kadence's own share 123 ms to 0), Style & Layout 787 ms
 * to 760 ms, LCP unchanged. Setting the property even when it is 0 kept the
 * forced reflow away but cost a second style pass before the first paint
 * (Style & Layout 803 ms, LCP +38 ms), so 0 is left unset.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.204
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Scrollbar extends SEOProStack_Feature {

    const KEY = 'kadence_scrollbar';

    /** The Kadence theme's part that prints the scrollbar script. */
    const KADENCE_PART = 'Kadence\\Base_Support\\Component';

    /** Its method, and the wp_footer priority it is added at. */
    const KADENCE_METHOD = 'action_add_scrollbar_offset_script';
    const PRIORITY       = 2;

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
                'tab'         => 'speed',
                'label'       => __('Measure Kadence’s scrollbar without holding up the page', 'seoprostack'),
                'description' => __('The Kadence theme measures the scrollbar’s width at the end of every page, which makes the browser lay out the whole page early, while it is still loading (PageSpeed Insights: Forced reflow). This measures it after the browser’s own layout instead, and again when the window or page changes size, so full-width blocks and transparent headers fit as before. On phones, whose scrollbars take no room, nothing is set. Does nothing with other themes.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        if (!self::enabled() || is_admin()) {
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'swap'));
    }

    /**
     * Settings changed: when this feature's did, cached pages were made the
     * other way, so empty the page cache.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        if (!empty($old[self::KEY]) !== !empty($new[self::KEY])) {
            SEOProStack_Page_Cache::purge_all();
        }
    }

    /**
     * Take Kadence's scrollbar script off wp_footer and put this one in its
     * place, at the same priority, when this page wants it.
     */
    public static function swap() {
        global $wp_filter;
        if (!isset($wp_filter['wp_footer']->callbacks[self::PRIORITY]) || !self::wanted()) {
            return;
        }
        foreach ($wp_filter['wp_footer']->callbacks[self::PRIORITY] as $callback) {
            $function = $callback['function'];
            if (is_array($function) && is_object($function[0]) && is_a($function[0], self::KADENCE_PART)
                && self::KADENCE_METHOD === $function[1]) {
                remove_action('wp_footer', $function, self::PRIORITY);
                add_action('wp_footer', array(__CLASS__, 'script'), self::PRIORITY);
                return;
            }
        }
    }

    /**
     * Print the script: set --scrollbar-offset on the html element from a
     * ResizeObserver, only when the width is not 0 or changes.
     */
    public static function script() {
        wp_print_inline_script_tag(
            '(function(w,e){var l=0;function s(){var x=w.innerWidth-e.clientWidth;if(x!==l){l=x;e.style.setProperty("--scrollbar-offset",x+"px")}}if(w.ResizeObserver){new w.ResizeObserver(s).observe(e)}else{s()}})(window,document.documentElement);'
        );
    }

    /**
     * Whether this page gets the lighter script: the Kadence theme (or a
     * child theme of it), not a feed, embed or AMP page (where Kadence
     * prints nothing), and the filter agrees.
     *
     * @return bool
     */
    private static function wanted() {
        if ('kadence' !== get_template() || is_feed() || is_embed()) {
            return false;
        }
        if ((function_exists('amp_is_request') && amp_is_request())
            || (function_exists('Kadence\kadence') && \Kadence\kadence()->is_amp())) {
            return false;
        }
        /**
         * Whether the Kadence theme's scrollbar width is measured after
         * layout on this page, instead of while the page is still loading.
         *
         * @param bool $on True to measure it after layout.
         */
        return (bool) apply_filters('seoprostack_kadence_scrollbar', true);
    }
}
