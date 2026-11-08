<?php
/**
 * Load Fluent Forms' scripts when a form comes near.
 *
 * Fluent Forms prints its form scripts (form-submission.js,
 * fluentform-advanced.js and, for some fields, Fluent Forms Pro's phone,
 * upload, cropper and lightbox libraries) on every page with a form, usually
 * below the first screen. On a phone they share the connection with the
 * first screen's pictures and fonts, and Lighthouse's simulated LCP counts
 * every request that starts before the observed LCP: on one Kadence home page
 * with a form below the first screen, leaving them out took LCP from 3.3–3.6 s
 * to 3.0–3.2 s (GH#1428).
 *
 * Each of Fluent Forms' and Fluent Forms Pro's own scripts (a file in either
 * plugin's folder) is printed as type="seoprostack/near", its address in
 * data-seoprostack-src; its data (fluentFormVars and the rest) and any inline
 * script before it print as they were. A small script at the end of the page
 * runs them, in order, once a form comes within DISTANCE pixels of the
 * screen (IntersectionObserver, which also fires when a modal, drawer or tab
 * holding one is shown), a field of a form gets focus, or the visitor
 * presses a link or button that opens an element holding a form; at once
 * when the page has no form or no IntersectionObserver, so a form in the
 * first screen loads them straight away.
 *
 * The files are fetched together first (rel=preload) and then run one after
 * another, form-submission.js last: when it runs after the page has loaded
 * it starts the forms 100 ms later (Fluent Forms 6.2.17 checks
 * document.readyState), and fluentform-advanced.js and the Pro libraries
 * must be in by then, as they listen for its fluentform_init event. Scripts
 * that need jQuery wait for it, in case another plugin holds jQuery until
 * the visitor interacts. Fields that Fluent Forms and Fluent Forms Pro start
 * from an inline script at the end of the page, on jQuery's ready (phone,
 * date), find no library then and return; once the scripts have run, each
 * form gets Fluent Forms' reInitExtras event, which starts them. A page with
 * a color picker or range slider, whose inline scripts do not check for
 * their library, is left as it is. Which scripts wait is decided in
 * SEOProStack_Fluentforms_Scripts.
 *
 * The loader does not wait for Used CSS's full stylesheets
 * (window.seoprostackCss(), which Delay scripts waits for): Used CSS reads
 * these scripts' words as it does any script that runs as the page loads
 * (SEOProStack_Used_Css_Scripts::NEAR), so the classes they add, such as
 * ff-el-is-error, keep their rules in the page, and a form near the first
 * screen starts straight away.
 *
 * Nothing changes, and the scripts print as before, with Fluent Forms older
 * than MIN_VERSION; on conversational form pages; when a script of another
 * plugin needs one of these; when Delay scripts would hold one of them, or a
 * script they need, with its keywords; or where the content security policy
 * PHP sends blocks inline scripts. Not in the admin, feeds, embeds, the
 * Customizer, AJAX or REST.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.189
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Fluentforms_Later extends SEOProStack_Feature {

    const KEY = 'fluentforms_later';

    /** The type the scripts wait under (SEOProStack_Used_Css_Scripts::NEAR). */
    const TYPE = 'seoprostack/near';

    /** First Fluent Forms version whose form-submission.js starts forms when it loads late. */
    const MIN_VERSION = '6.2.17';

    /** How near the screen a form is, in pixels, when its scripts load. */
    const DISTANCE = 600;

    /** @var array<string,string>|null Handles held this request => 'late' or '' (decided at the first one). */
    private static $held = null;

    /** @var bool Whether a script was held. */
    private static $printed = false;

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
                'label'       => __('Load Fluent Forms’ scripts when a form comes near', 'seoprostack'),
                'description' => __('Fluent Forms loads its form scripts, and Fluent Forms Pro’s phone, upload and other field libraries, with every page that has a form, even when the form is far down the page or in a closed modal, so they compete with the top of the page. This loads them when a form comes near the screen, one of its fields gets focus or a link that opens one is pressed. Forms work as before; a form in the first screen loads them straight away. Needs Fluent Forms 6.2.17 or later; nothing changes with older versions or without Fluent Forms.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin() || !defined('FLUENTFORM_VERSION') || version_compare(FLUENTFORM_VERSION, self::MIN_VERSION, '<')) {
            return;
        }
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-fluentforms-scripts.php';
        add_filter('script_loader_tag', array(__CLASS__, 'tag'), 10, 3);
        // After WordPress prints the footer scripts (20).
        add_action('wp_footer', array(__CLASS__, 'loader'), 99);
    }

    /**
     * A held script's tags: its file, and an inline script after it, wait.
     *
     * @param string $tag    Script tags, inline scripts included.
     * @param string $handle Script handle.
     * @param string $src    Script address.
     * @return string
     */
    public static function tag($tag, $handle, $src) {
        if (!is_string($tag) || '' === (string) $src || !SEOProStack_Fluentforms_Scripts::own((string) $src)) {
            return $tag;
        }
        $held = self::held();
        if (!isset($held[$handle])) {
            return $tag;
        }
        $waiting = self::rewrite($tag, $handle, 'late' === $held[$handle]);
        if (null === $waiting) {
            return $tag;
        }
        self::$printed = true;
        return $waiting;
    }

    /**
     * The tags with the script's file, and an inline script after it,
     * waiting; data and inline scripts before it run as they were.
     *
     * @param string $tag    Script tags.
     * @param string $handle Script handle.
     * @param bool   $late   Whether it runs after the others.
     * @return string|null Null when its file was not found.
     */
    private static function rewrite($tag, $handle, $late) {
        $tags = new WP_HTML_Tag_Processor($tag);
        $file = null;
        while ($tags->next_tag(array('tag_name' => 'SCRIPT'))) {
            $id = $tags->get_attribute('id');
            if ($handle . '-js' === $id) {
                $file = $tags->get_attribute('src');
                if (!is_string($file) || '' === $file) {
                    return null;
                }
                $tags->remove_attribute('src');
                $tags->set_attribute('data-seoprostack-src', $file);
            } elseif ($handle . '-js-after' !== $id) {
                continue;
            }
            $tags->set_attribute('type', self::TYPE);
            if ($late) {
                $tags->set_attribute('data-seoprostack-late', '1');
            }
        }
        return null === $file ? null : $tags->get_updated_html();
    }

    /**
     * The handles held on this page (SEOProStack_Fluentforms_Scripts::plan()),
     * decided once, at the first of Fluent Forms' scripts printed.
     *
     * @return array<string,string>
     */
    private static function held() {
        if (null === self::$held) {
            self::$held = self::page_view() ? SEOProStack_Fluentforms_Scripts::plan() : array();
        }
        return self::$held;
    }

    /**
     * Whether this request is a page view where the scripts can wait. boot()
     * has already left out the admin, and with it AJAX.
     *
     * @return bool
     */
    private static function page_view() {
        if (is_feed() || is_embed() || is_customize_preview() || (defined('REST_REQUEST') && REST_REQUEST) || is_login()) {
            return false;
        }
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-policy.php';
        // A policy that blocks the loader would leave the forms without their scripts.
        if (!SEOProStack_Used_Css_Policy::allows('', true)) {
            return false;
        }
        /**
         * Whether Fluent Forms' scripts load when a form comes near on this
         * page.
         *
         * @param bool $later True to load them when a form comes near.
         */
        return (bool) apply_filters('seoprostack_fluentforms_later', true);
    }

    /**
     * The script that runs the held scripts when a form comes near.
     */
    public static function loader() {
        if (!self::$printed) {
            return;
        }
        $js = sprintf(
            '(function(){var T=%1$s,F=".fluentform",done=false,io=null;'
            . 'function q(s,r){return(r||document).querySelectorAll(s);}'
            . 'function load(){if(done)return;done=true;if(io)io.disconnect();document.removeEventListener("focusin",focus,true);document.removeEventListener("pointerdown",tap,true);'
            . 'var all=[].slice.call(q(\'script[type="\'+T+\'"]\')),first=[],late=[],n=0,tries=0,timer=null,i;'
            . 'all.forEach(function(s){(s.hasAttribute("data-seoprostack-late")?late:first).push(s);});'
            . 'var list=first.concat(late),files=list.filter(function(s){return s.getAttribute("data-seoprostack-src");});'
            . 'function fetched(){if(++n===files.length)wait();}'
            . 'if(!files.length){wait();return;}'
            . 'for(i=0;i<files.length;i++){var l=document.createElement("link");l.rel="preload";l.as="script";l.href=files[i].getAttribute("data-seoprostack-src");'
            . 'var c=files[i].getAttribute("crossorigin");if(c!==null)l.crossOrigin=c;l.onload=l.onerror=fetched;document.head.appendChild(l);}'
            . 'timer=setTimeout(function(){n=files.length-1;fetched();},5000);'
            // jQuery, which form-submission.js needs, may be held by another plugin until the visitor interacts; 20 s at most.
            . 'function wait(){clearTimeout(timer);if(!window.jQuery&&++tries<400){setTimeout(wait,50);return;}next();}'
            // Fields Fluent Forms starts from an inline script at the end of the page (phone, date) skipped their libraries then; they start again on reInitExtras.
            . 'function next(){var old=list.shift();if(!old){if(window.jQuery)window.jQuery("form.frm-fluent-form").trigger("reInitExtras");return;}var s=document.createElement("script");'
            . '[].forEach.call(old.attributes,function(a){if(a.name!=="type"&&a.name!=="data-seoprostack-src"&&a.name!=="data-seoprostack-late")s.setAttribute(a.name,a.value);});'
            . 'var src=old.getAttribute("data-seoprostack-src");'
            . 'if(src){s.src=src;s.async=false;s.onload=s.onerror=next;old.parentNode.replaceChild(s,old);}'
            . 'else{s.text=old.text;old.parentNode.replaceChild(s,old);next();}}}'
            . 'function focus(e){var f=e.target&&e.target.closest?e.target.closest(F):null;if(f)load();}'
            . 'function tap(e){var a=e.target&&e.target.closest?e.target.closest("a[href*=\'#\'],[aria-controls],[data-toggle-target]"):null;if(!a)return;'
            . 'var id=a.getAttribute("aria-controls")||a.getAttribute("data-toggle-target")||a.getAttribute("href")||"";id=id.slice(id.indexOf("#")+1);'
            . 'var t=id?document.getElementById(id):null;if(t&&q(F,t).length)load();}'
            . 'function start(){var w=q(F),i;if(!w.length||!("IntersectionObserver" in window)){load();return;}'
            . 'io=new IntersectionObserver(function(es){for(var j=0;j<es.length;j++){if(es[j].isIntersecting){load();return;}}},{rootMargin:"%2$dpx 0px"});'
            . 'for(i=0;i<w.length;i++)io.observe(w[i]);document.addEventListener("focusin",focus,true);document.addEventListener("pointerdown",tap,true);}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",start);else start();})();',
            wp_json_encode(self::TYPE),
            self::DISTANCE
        );
        wp_print_inline_script_tag($js, array(
            'id'                       => 'seoprostack-fluentforms-later',
            'data-cfasync'             => 'false',
            'data-no-optimize'         => '1',
            'data-seoprostack-nodelay' => '1',
        ));
    }
}
