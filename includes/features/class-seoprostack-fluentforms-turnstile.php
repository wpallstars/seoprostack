<?php
/**
 * Load Fluent Forms' Cloudflare Turnstile when a form comes near.
 *
 * Fluent Forms adds Turnstile's api.js to every page with a form that has
 * Turnstile, and each widget then fetches a Cloudflare challenge of about
 * 213 KB, competing with the top of the page even when the form is far down
 * it or in a closed modal (GH#1210). This prints a small script in place of
 * Fluent Forms' tag that adds the same api.js once a widget comes within
 * DISTANCE pixels of the screen, or a field of its form gets focus. Fluent
 * Forms 6.2.17 and later wait for Turnstile themselves (form-submission.js
 * checks for turnstile.render after each script loads, and every second at
 * most), so they render the widgets when it arrives. Older versions are left
 * alone.
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

class SEOProStack_Fluentforms_Turnstile extends SEOProStack_Feature {

    const KEY = 'fluentforms_turnstile_later';

    /** Fluent Forms' script handle for Turnstile in its standard forms. */
    const HANDLE = 'turnstile';

    /** The first Fluent Forms version that waits for a late Turnstile. */
    const MIN_VERSION = '6.2.17';

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
                'label'       => __('Load Fluent Forms’ Turnstile when a form comes near', 'seoprostack'),
                'description' => __('Fluent Forms loads Cloudflare Turnstile, a challenge of about 213 KB for each form plus a 28 KB script, with every page that has a form using it, even when the form is far down the page or in a closed modal. This loads it when a form comes near the screen or one of its fields gets focus, so the top of the page loads first. Forms work as before. Needs Fluent Forms 6.2.17 or later; nothing changes with older versions or without Fluent Forms.', 'seoprostack'),
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
        add_filter('script_loader_tag', array(__CLASS__, 'tag'), 10, 3);
    }

    /**
     * In place of Fluent Forms' Turnstile tag, a script that adds it later.
     *
     * @param string $tag    Script tag.
     * @param string $handle Script handle.
     * @param string $src    Script address.
     * @return string
     */
    public static function tag($tag, $handle, $src) {
        if (self::HANDLE !== $handle || !self::fluent_forms_turnstile($src)) {
            return $tag;
        }
        $js = sprintf(
            '(function(){var src=%1$s,done=false,io=null;'
            . 'function load(){if(done)return;done=true;if(io)io.disconnect();document.removeEventListener("focusin",focus,true);'
            . 'var s=document.createElement("script");s.src=src;s.setAttribute("data-cfasync","false");document.head.appendChild(s);}'
            . 'function focus(e){var f=e.target&&e.target.closest?e.target.closest("form"):null;if(f&&f.querySelector(".ff-el-turnstile"))load();}'
            . 'function start(){var w=document.querySelectorAll(".ff-el-turnstile"),i;'
            . 'if(!w.length||!("IntersectionObserver" in window)){load();return;}'
            . 'io=new IntersectionObserver(function(es){for(var j=0;j<es.length;j++){if(es[j].isIntersecting){load();return;}}},{rootMargin:"%2$dpx 0px"});'
            . 'for(i=0;i<w.length;i++)io.observe(w[i]);document.addEventListener("focusin",focus,true);}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",start);else start();})();',
            wp_json_encode($src),
            self::DISTANCE
        );
        return wp_get_inline_script_tag($js, array(
            'id'           => 'seoprostack-turnstile-later',
            'data-cfasync' => 'false',
        ));
    }

    /**
     * Whether the script is Fluent Forms' own Turnstile, from a version that
     * waits for it: its explicit-render api.js, registered with Fluent Forms'
     * version, so another plugin's script under the same handle is left alone.
     *
     * @param string $src Script address.
     * @return bool
     */
    private static function fluent_forms_turnstile($src) {
        if (!defined('FLUENTFORM_VERSION') || version_compare(FLUENTFORM_VERSION, self::MIN_VERSION, '<')) {
            return false;
        }
        $parts = wp_parse_url($src);
        if (!is_array($parts) || !isset($parts['host'], $parts['path'], $parts['query'])
            || 'challenges.cloudflare.com' !== $parts['host'] || '/turnstile/v0/api.js' !== $parts['path']) {
            return false;
        }
        parse_str($parts['query'], $query);
        if (!isset($query['render']) || 'explicit' !== $query['render']) {
            return false;
        }
        $script = wp_scripts()->query(self::HANDLE);
        return $script instanceof _WP_Dependency && FLUENTFORM_VERSION === $script->ver;
    }
}
