<?php
/**
 * Load Simple Cloudflare Turnstile when a form comes near.
 *
 * Simple Cloudflare Turnstile prints Cloudflare's api.js on every page with
 * a widget, including a login form in a closed header drawer, and each
 * widget then loads a Cloudflare challenge. With WooCommerce active it also
 * adds its WooCommerce script, which depends on wp-data, so WordPress prints
 * React, ReactDOM and about 15 more of its own scripts on those pages too;
 * that script is only for the checkout (GH#1292).
 *
 * Off the checkout, this prints a small script in place of the api.js tag,
 * after the plugin's own inline scripts (its render queue, whose onload
 * callback renders the queued widgets), that adds the same api.js once a
 * widget comes within DISTANCE pixels of the screen or a field of a form
 * holding one gets focus; and it leaves out the WooCommerce script. Only
 * with versions that have the render queue (cfturnstile_api_bootstrap(),
 * 1.44), when the address is the plugin's own, and when every script that
 * needs api.js is one of the plugin's own that does not call Turnstile as it
 * runs. Otherwise nothing changes.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Turnstile_Later extends SEOProStack_Feature {

    const KEY = 'turnstile_later';

    /** Simple Cloudflare Turnstile's handle for Cloudflare's api.js. */
    const HANDLE = 'cfturnstile';

    /** Its WooCommerce checkout script, which needs wp-data. */
    const WOO_HANDLE = 'cfturnstile-woo-js';

    /** Where that script is, in the plugin's folder. */
    const WOO_FILE = '/simple-cloudflare-turnstile/js/integrations/woocommerce.js';

    /**
     * The plugin's scripts that need api.js printed before them but do not
     * call Turnstile as they run: submit buttons, labels and the checkout
     * (which waits for Turnstile itself, and is left alone).
     */
    const SAFE_DEPENDENTS = array('cfturnstile-js', 'cfturnstile-label-js', self::WOO_HANDLE);

    /** How near the screen a widget is, in pixels, when Turnstile loads. */
    const DISTANCE = 600;

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
                'label'       => __('Load Simple Cloudflare Turnstile when a form comes near', 'seoprostack'),
                'description' => __('Simple Cloudflare Turnstile loads Cloudflare’s script, and a challenge for each form, on every page with a protected form, even one in a closed menu or drawer; with WooCommerce it also adds its checkout script, which brings React and about 15 more of WordPress’s scripts to those pages. This loads Turnstile when a form comes near the screen or one of its fields gets focus, and its checkout script only on the checkout. Forms work as before. Does nothing without Simple Cloudflare Turnstile 1.44 or later.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin() || !function_exists('cfturnstile_api_url') || !function_exists('cfturnstile_api_bootstrap')) {
            return;
        }
        // After the plugin's own filter (10), which adds data-cfasync.
        add_filter('script_loader_tag', array(__CLASS__, 'tag'), 20, 3);
        // Just before WordPress prints the footer scripts (10).
        add_action('wp_print_footer_scripts', array(__CLASS__, 'woo_script'), 9);
    }

    /**
     * Whether this request is a page view where Turnstile can wait: not the
     * login screen, feeds, AJAX, REST, the Customizer or the checkout.
     *
     * @return bool
     */
    private static function page_view() {
        if ((isset($GLOBALS['pagenow']) && 'wp-login.php' === $GLOBALS['pagenow'])
            || is_feed() || is_embed() || is_customize_preview() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || self::checkout()) {
            return false;
        }
        /**
         * Whether Simple Cloudflare Turnstile loads when a form comes near on
         * this page, and its WooCommerce script is left out.
         *
         * @param bool $later True to load it when a form comes near.
         */
        return (bool) apply_filters('seoprostack_turnstile_later', true);
    }

    /**
     * Whether this is a WooCommerce checkout page (pay for order included),
     * or a page with the checkout block or shortcode.
     *
     * @return bool
     */
    private static function checkout() {
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        if (!is_singular()) {
            return false;
        }
        $post    = get_post();
        $content = $post ? (string) $post->post_content : '';
        return false !== strpos($content, '<!-- wp:woocommerce/checkout') || false !== strpos($content, '[woocommerce_checkout');
    }

    /**
     * In place of the api.js tag, a script that adds it later. The inline
     * scripts printed before it (the render queue and token refresh) stay.
     *
     * @param string $tag    Script tags, inline scripts included.
     * @param string $handle Script handle.
     * @param string $src    Script address.
     * @return string
     */
    public static function tag($tag, $handle, $src) {
        if (self::HANDLE !== $handle || !self::own_api($src) || !self::page_view()) {
            return $tag;
        }
        $pattern = '#<script\b[^>]*\bid=(["\'])' . self::HANDLE . '-js\1[^>]*>\s*</script>\s*#i';
        if (1 !== preg_match_all($pattern, $tag, $found, PREG_OFFSET_CAPTURE)) {
            return $tag;
        }
        $js = sprintf(
            '(function(){var src=%1$s,done=false,io=null;'
            . 'function load(){if(done)return;done=true;if(io)io.disconnect();document.removeEventListener("focusin",focus,true);'
            . 'var s=document.createElement("script");s.src=src;s.setAttribute("data-cfasync","false");document.head.appendChild(s);}'
            . 'function focus(e){var f=e.target&&e.target.closest?e.target.closest("form"):null;if(f&&f.querySelector(".cf-turnstile"))load();}'
            . 'function start(){var w=document.querySelectorAll(".cf-turnstile"),i;'
            . 'if(!w.length||!("IntersectionObserver" in window)){load();return;}'
            . 'io=new IntersectionObserver(function(es){for(var j=0;j<es.length;j++){if(es[j].isIntersecting){load();return;}}},{rootMargin:"%2$dpx 0px"});'
            . 'for(i=0;i<w.length;i++)io.observe(w[i]);document.addEventListener("focusin",focus,true);}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",start);else start();})();',
            wp_json_encode($src),
            self::DISTANCE
        );
        $loader = wp_get_inline_script_tag($js, array(
            'id'           => 'seoprostack-cfturnstile-later',
            'data-cfasync' => 'false',
        ));
        // Spliced in by position: the loader is code, not a replacement pattern.
        return substr_replace($tag, $loader, $found[0][0][1], strlen($found[0][0][0]));
    }

    /**
     * Whether the script is the plugin's own api.js with its render queue,
     * with no inline script after it, and every script that needs it is one
     * of the plugin's own that does not call Turnstile as it runs. The
     * address itself is never changed: the loader adds the one WordPress
     * would have printed.
     *
     * @param string $src Script address.
     * @return bool
     */
    private static function own_api($src) {
        if (!function_exists('cfturnstile_api_url') || !function_exists('cfturnstile_api_bootstrap')
            || cfturnstile_api_url() !== $src) {
            return false;
        }
        $scripts = wp_scripts();
        $after   = $scripts->get_data(self::HANDLE, 'after');
        if (!empty($after)) {
            return false;
        }
        $before = (array) $scripts->get_data(self::HANDLE, 'before');
        if (!in_array(cfturnstile_api_bootstrap(), $before, true)) {
            return false;
        }
        $handles = array_unique(array_merge((array) $scripts->queue, (array) $scripts->to_do, (array) $scripts->done));
        foreach ($handles as $other) {
            if (self::HANDLE === $other || in_array($other, self::SAFE_DEPENDENTS, true)) {
                continue;
            }
            $item = $scripts->query($other);
            if ($item instanceof _WP_Dependency && in_array(self::HANDLE, (array) $item->deps, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Leave out the plugin's WooCommerce script, and with it wp-data and
     * React unless another script needs them, outside the checkout.
     */
    public static function woo_script() {
        if (!wp_script_is(self::WOO_HANDLE, 'enqueued') || !self::page_view()) {
            return;
        }
        $script = wp_scripts()->query(self::WOO_HANDLE);
        if (!$script instanceof _WP_Dependency || false === strpos((string) $script->src, self::WOO_FILE)) {
            return;
        }
        wp_dequeue_script(self::WOO_HANDLE);
    }
}
