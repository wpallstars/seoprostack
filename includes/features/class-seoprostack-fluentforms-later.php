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
 * data-seoprostack-src, with its inline scripts before and after it; its
 * data (fluentFormVars and the rest) prints as it was. A small script at the end of the page
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
 * the visitor interacts, and a form sent before they have run is held (it
 * would post to the page and lose what was typed) so it can be sent again. Fields that Fluent Forms and Fluent Forms Pro start
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
 * than MIN_VERSION; on conversational form pages; on pages that open with a
 * form (SEOProStack_Image_Loading::opening_block()); when one of the scripts
 * prints before the footer; when a script of another plugin needs one of
 * these; when Delay scripts would hold one of them, or a script they need,
 * with its keywords; or where the content security policy PHP sends blocks
 * inline scripts. A script another filter gave a type (a consent manager's
 * text/plain) keeps it. Not in the admin, feeds, embeds, the Customizer,
 * AJAX or REST. Changing the setting empties the page cache.
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

    /** @var int Output buffer level of the wp_head capture; -1 when none is open. */
    private static $head_level = -1;

    /** @var string The <meta> tags in wp_head that mention a content security policy. */
    private static $head_metas = '';

    /** @var bool Whether the head could not be read (another buffer was open in it). */
    private static $head_unread = false;

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
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        if (!self::enabled() || is_admin() || !defined('FLUENTFORM_VERSION') || version_compare(FLUENTFORM_VERSION, self::MIN_VERSION, '<')) {
            return;
        }
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-fluentforms-scripts.php';
        add_filter('script_loader_tag', array(__CLASS__, 'tag'), 10, 2);
        // Read the head's <meta> policies, which headers_list() does not show.
        // get_header runs before header.php, so <meta> tags printed before wp_head() are read too.
        add_action('get_header', array(__CLASS__, 'head_start'), 0);
        add_action('wp_head', array(__CLASS__, 'head_start'), 0);
        add_action('wp_head', array(__CLASS__, 'head_end'), PHP_INT_MAX);
        // After WordPress prints the footer scripts (20).
        add_action('wp_footer', array(__CLASS__, 'loader'), 99);
    }

    /**
     * Start collecting what wp_head prints, to find a <meta> content
     * security policy in it.
     */
    public static function head_start() {
        if (self::$head_level < 0 && ob_start()) {
            self::$head_level = ob_get_level();
        }
    }

    /**
     * Print what wp_head printed, keeping its <meta> tags for page_view().
     */
    public static function head_end() {
        if (self::$head_level < 1) {
            return;
        }
        if (ob_get_level() !== self::$head_level) {
            // Another callback's buffer is open above ours (it may transform
            // the body): leave every buffer as it is, and hold nothing, as
            // the head's policy cannot be read.
            self::$head_unread = true;
            self::$head_level  = -1;
            return;
        }
        $head             = (string) ob_get_clean();
        self::$head_metas = self::policy_metas($head);
        self::$head_level = -1;
        echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- what the head printed, unchanged.
    }

    /**
     * The content security policy <meta> tags among real elements of the
     * HTML (not comments or script text), as plain tags.
     *
     * @param string $html Head HTML.
     * @return string
     */
    private static function policy_metas($html) {
        if (false === stripos($html, 'content-security-policy')) {
            return '';
        }
        $out  = '';
        $tags = new WP_HTML_Tag_Processor($html);
        while ($tags->next_tag(array('tag_name' => 'META'))) {
            $equiv = $tags->get_attribute('http-equiv');
            if (is_string($equiv) && 'content-security-policy' === strtolower(trim($equiv))) {
                $content = $tags->get_attribute('content');
                $out    .= '<meta http-equiv="Content-Security-Policy" content="' . esc_attr(is_string($content) ? $content : '') . '">';
            }
        }
        return $out;
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
     * A held script's tags: its file, and inline scripts before and after
     * it, wait.
     *
     * @param string $tag    Script tags, inline scripts included.
     * @param string $handle Script handle.
     * @return string
     */
    public static function tag($tag, $handle) {
        // The address it was registered with, as plan() reads it: a filter
        // may have moved the printed one (a CDN), and the scripts wait together.
        $item = wp_scripts()->query($handle);
        if (!is_string($tag) || !$item instanceof _WP_Dependency || !is_string($item->src) || !SEOProStack_Fluentforms_Scripts::own($item->src)) {
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
     * The tags with the script's file, and inline scripts before and after
     * it, waiting, so they run in their order; its data prints as it was.
     *
     * @param string $tag    Script tags.
     * @param string $handle Script handle.
     * @param bool   $late   Whether it runs after the others.
     * @return string|null Null when its file was not found, or another
     *                     filter gave it a type (a consent manager's text/plain).
     */
    private static function rewrite($tag, $handle, $late) {
        $tags = new WP_HTML_Tag_Processor($tag);
        $file = null;
        while ($tags->next_tag(array('tag_name' => 'SCRIPT'))) {
            $id = $tags->get_attribute('id');
            if ($handle . '-js' === $id) {
                $file = $tags->get_attribute('src');
                if (!is_string($file) || '' === $file || !self::classic($tags->get_attribute('type'))) {
                    return null;
                }
                $tags->remove_attribute('src');
                $tags->set_attribute('data-seoprostack-src', $file);
            } elseif ($handle . '-js-before' !== $id && $handle . '-js-after' !== $id) {
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
     * Whether a script tag's type attribute runs it as a classic script.
     *
     * @param string|true|null $type The type attribute.
     * @return bool
     */
    private static function classic($type) {
        return !is_string($type) || in_array(strtolower(trim($type)), array('', 'text/javascript', 'application/javascript'), true);
    }

    /**
     * The handles held on this page (SEOProStack_Fluentforms_Scripts::plan()),
     * decided once, at the first of Fluent Forms' scripts printed. None when
     * that is before the footer: the page's forms may still add scripts that
     * need it, which would run before it.
     *
     * @return array<string,string>
     */
    private static function held() {
        if (null === self::$held) {
            self::$held = did_action('wp_footer') && self::page_view() ? SEOProStack_Fluentforms_Scripts::plan() : array();
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
        if (is_feed() || is_embed() || is_customize_preview() || (defined('REST_REQUEST') && REST_REQUEST) || is_login() || self::$head_unread || self::opening_form()) {
            return false;
        }
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-policy.php';
        // A policy (header or head <meta>) that blocks the loader would leave the forms without their scripts.
        if (!SEOProStack_Used_Css_Policy::allows(self::$head_metas . '</head>', true)) {
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
     * Whether the main post opens with a form, in the block
     * SEOProStack_Image_Loading::opening_block() finds: it is on the first
     * screen, so its scripts load with the page, as before.
     *
     * @return bool
     */
    private static function opening_form() {
        $post = get_queried_object();
        if (!is_singular() || !$post instanceof WP_Post) {
            return false;
        }
        $block = SEOProStack_Image_Loading::opening_block($post);
        if (!is_array($block)) {
            return false;
        }
        // The [fluentform] shortcode, a form's markup, or the Fluent Forms block (fluentfom/guten-block).
        $text = serialize_block($block);
        return false !== stripos($text, 'fluentform') || false !== stripos($text, 'fluentfom/');
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
            // jQuery, which form-submission.js needs, may be held by another plugin until the visitor interacts: wait for it, every 50 ms for 20 s, then every 500 ms.
            . 'function wait(){clearTimeout(timer);if(!window.jQuery){setTimeout(wait,++tries<400?50:500);return;}next();}'
            // Fields Fluent Forms starts from an inline script at the end of the page (phone, date) skipped their libraries then; they start again on reInitExtras.
            . 'function next(){var old=list.shift();if(!old){document.removeEventListener("submit",hold,true);if(window.jQuery)window.jQuery("form.frm-fluent-form").trigger("reInitExtras");return;}var s=document.createElement("script");'
            . '[].forEach.call(old.attributes,function(a){if(a.name!=="type"&&a.name!=="data-seoprostack-src"&&a.name!=="data-seoprostack-late")s.setAttribute(a.name,a.value);});'
            . 'var src=old.getAttribute("data-seoprostack-src");'
            . 'if(src){s.src=src;s.async=false;s.onload=s.onerror=next;old.parentNode.replaceChild(s,old);}'
            . 'else{s.text=old.text;old.parentNode.replaceChild(s,old);next();}}}'
            // Until Fluent Forms' scripts have run, a form sent would post to this page and lose what was typed: hold it, so the visitor can send it again.
            . 'function hold(e){var f=e.target;if(f&&f.matches&&f.matches("form.frm-fluent-form")){e.preventDefault();load();}}'
            . 'function focus(e){var f=e.target&&e.target.closest?e.target.closest(F):null;if(f)load();}'
            . 'function tap(e){var a=e.target&&e.target.closest?e.target.closest("a[href*=\'#\'],[aria-controls],[data-toggle-target]"):null;if(!a)return;'
            . 'var id=a.getAttribute("aria-controls")||a.getAttribute("data-toggle-target")||a.getAttribute("href")||"";id=id.slice(id.indexOf("#")+1);'
            . 'var t=id?document.getElementById(id):null;if(t&&q(F,t).length)load();}'
            . 'function start(){var w=q(F),i;document.addEventListener("submit",hold,true);if(!w.length||!("IntersectionObserver" in window)){load();return;}'
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
