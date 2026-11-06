<?php
/**
 * Load AI Engine's chatbot on first use.
 *
 * With a site-wide chatbot, AI Engine (checked with AI Engine Pro 3.9.0)
 * enqueues its chatbot script (app/chatbot.js, about 350 KB, in the head)
 * with React, ReactDOM and wp-element, and the chatbot's theme stylesheet,
 * on every page: on two Kadence sites they were the first render-blocking
 * requests, an estimated 3 to 5 s on a phone (GitHub issue #1261). The
 * chatbot needs none of it until someone uses it: its launcher is drawn by
 * that script into a container AI Engine prints in the footer, whose data
 * attributes hold all its settings.
 *
 * So the chatbot's script and stylesheets are taken out of the queue (at
 * wp_enqueue_scripts, and again before the footer prints for a chatbot
 * added by a shortcode or block), and after the footer's scripts their tags,
 * as WordPress prints them with the dependencies no other script needed,
 * go into the page as data. A small loader adds them on the visitor's first
 * pointer, touch, key, wheel or scroll, or when an inline chatbot comes
 * within DISTANCE pixels of the screen, then calls AI Engine's own
 * window.mwaiInitialize(), which mounts each container once. Each held
 * stylesheet loads before the scripts, so the chatbot never mounts
 * unstyled. An AI Engine whose chatbot.js has no mwaiInitialize() (older
 * versions mount only on DOMContentLoaded) is left alone (GitHub issue
 * #1268).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.161
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Ai_Engine_Later extends SEOProStack_Feature {

    const KEY = 'ai_engine_later';

    /** AI Engine's chatbot scripts. */
    const SCRIPTS = array('mwai_chatbot', 'mwai_highlight');

    /** The start of AI Engine's chatbot theme stylesheet handles. */
    const STYLE_PREFIX = 'mwai_chatbot_theme_';

    /** How near the screen an inline chatbot is, in pixels, when it loads. */
    const DISTANCE = 600;

    /** Whether this AI Engine's chatbot.js can mount late, per MWAI_VERSION. */
    const SUPPORT_OPTION = 'seoprostack_ai_engine_later_support';

    /** What chatbot.js defines to mount chatbots after the page has loaded. */
    const INITIALIZER = 'mwaiInitialize';

    /** Longest wait for a held stylesheet before the scripts load anyway, in ms. */
    const STYLE_WAIT = 3000;

    /**
     * Handles taken out of the queue on this request.
     *
     * @var array{styles: array<string,bool>, scripts: array<string,bool>}
     */
    private static $held = array('styles' => array(), 'scripts' => array());

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
                'label'       => __('Load AI Engine’s chatbot on first use', 'seoprostack'),
                'description' => __('AI Engine loads its chatbot, with React, in the head of every page, which holds up the first paint by seconds on phones. This loads it when the visitor first moves, taps, scrolls or types, or when a chatbot placed in the page comes near the screen. The chatbot works as before. Does nothing without AI Engine.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin() || !defined('MWAI_VERSION') || !self::supported()) {
            return;
        }
        add_action('wp_enqueue_scripts', array(__CLASS__, 'hold'), PHP_INT_MAX);
        // Before wp_print_footer_scripts (20) prints a chatbot added by a shortcode or block.
        add_action('wp_footer', array(__CLASS__, 'hold'), 19);
        add_action('wp_footer', array(__CLASS__, 'print_loader'), 21);
    }

    /**
     * Whether this AI Engine's chatbot.js defines mwaiInitialize(): without
     * it, a chatbot loaded after DOMContentLoaded never mounts. Read once per
     * AI Engine version, then kept in an option.
     *
     * @return bool
     */
    private static function supported() {
        $version = defined('MWAI_VERSION') ? (string) MWAI_VERSION : '';
        if ('' === $version) {
            return false;
        }
        $known = get_option(self::SUPPORT_OPTION);
        if (is_array($known) && isset($known['version'], $known['ok']) && $version === $known['version']) {
            return (bool) $known['ok'];
        }
        $ok = self::has_initializer();
        if (null === $ok) {
            // The file could not be read: leave AI Engine alone now, and try again on a later request.
            return false;
        }
        // Autoloaded: read on every page view while AI Engine is active.
        update_option(self::SUPPORT_OPTION, array('version' => $version, 'ok' => $ok), true);
        return $ok;
    }

    /**
     * Whether AI Engine's app/chatbot.js contains INITIALIZER; null when the
     * file could not be read, so that is not kept as an answer.
     *
     * @return bool|null
     */
    private static function has_initializer() {
        $file = defined('MWAI_PATH') ? trailingslashit((string) MWAI_PATH) . 'app/chatbot.js' : '';
        if ('' === $file || !is_readable($file)) {
            return null;
        }
        $code = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.
        if (!is_string($code)) {
            return null;
        }
        return false !== strpos($code, self::INITIALIZER);
    }

    /**
     * Whether this request is a page view the chatbot could be on.
     *
     * @return bool
     */
    private static function page_view() {
        if (is_feed() || is_embed() || is_customize_preview() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('amp_is_request') && amp_is_request())) {
            return false;
        }
        /**
         * Whether AI Engine's chatbot loads on first use on this page.
         *
         * @param bool $later True to load it on first use.
         */
        return (bool) apply_filters('seoprostack_ai_engine_later', true);
    }

    /**
     * Take the chatbot's queued scripts and styles out of the queue.
     */
    public static function hold() {
        if (!self::page_view()) {
            return;
        }
        $scripts = wp_scripts();
        foreach (self::SCRIPTS as $handle) {
            if (in_array($handle, $scripts->queue, true) && !in_array($handle, $scripts->done, true)) {
                wp_dequeue_script($handle);
                self::$held['scripts'][$handle] = true;
            }
        }
        $styles = wp_styles();
        foreach ($styles->queue as $handle) {
            if (0 === strpos((string) $handle, self::STYLE_PREFIX) && !in_array($handle, $styles->done, true)) {
                wp_dequeue_style($handle);
                self::$held['styles'][$handle] = true;
            }
        }
    }

    /**
     * After the footer's scripts: the held tags as data, and the loader.
     */
    public static function print_loader() {
        $scripts = wp_scripts();
        // Another script needed the chatbot's, so it loaded as before.
        $handles = array_values(array_diff(array_keys(self::$held['scripts']), $scripts->done));
        if (!$handles) {
            // The chatbot loaded anyway: its stylesheets go in as usual.
            if (self::$held['styles']) {
                wp_styles()->do_items(array_keys(self::$held['styles']));
            }
            self::$held = array('styles' => array(), 'scripts' => array());
            return;
        }
        ob_start();
        wp_styles()->do_items(array_keys(self::$held['styles']));
        $scripts->do_items($handles);
        $tags = (string) ob_get_clean();
        if ('' === trim($tags)) {
            return;
        }
        // Escaped as JSON, so no tag in it is read as the page's (Used CSS, Delay scripts, LiteSpeed).
        printf(
            '<script type="application/json" id="seoprostack-ai-engine-later-tags">%s</script>' . "\n",
            wp_json_encode($tags, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        );
        echo wp_get_inline_script_tag(self::loader_js(), array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds the tag.
            'id'               => 'seoprostack-ai-engine-later',
            'data-cfasync'     => 'false',
            'data-no-defer'    => '1',
            'data-no-optimize' => '1',
        ));
        self::$held = array('styles' => array(), 'scripts' => array());
    }

    /**
     * The loader: adds the held stylesheets, each before the next tag (or
     * after STYLE_WAIT), then the scripts in order (each file after the one
     * before has loaded), then mounts the chatbots.
     *
     * @return string
     */
    private static function loader_js() {
        return sprintf(
            '(function(d,w){var data=d.getElementById("seoprostack-ai-engine-later-tags"),done=false,io=null,'
            . 'ev=["pointerdown","mousemove","touchstart","keydown","wheel","scroll"];if(!data)return;'
            . 'function load(){if(done)return;done=true;if(io)io.disconnect();ev.forEach(function(e){w.removeEventListener(e,load,{passive:true});});'
            . 'var t=d.createElement("template");try{t.innerHTML=JSON.parse(data.textContent);}catch(e){return;}'
            . 'var list=[].slice.call(t.content.children);'
            . '(function next(){var o=list.shift();if(!o){if(w.mwaiInitialize)w.mwaiInitialize();return;}'
            . 'if(o.tagName!=="SCRIPT"){if(o.tagName==="LINK"&&/\bstylesheet\b/i.test(o.rel)){var f=false,go=function(){if(!f){f=true;next();}};'
            . 'o.onload=o.onerror=go;setTimeout(go,%2$d);d.head.appendChild(o);}else{d.head.appendChild(o);next();}return;}'
            . 'var s=d.createElement("script");[].forEach.call(o.attributes,function(a){s.setAttribute(a.name,a.value);});'
            . 'if(o.hasAttribute("src")){s.async=false;s.onload=s.onerror=next;d.body.appendChild(s);}else{s.text=o.text;d.body.appendChild(s);next();}})();}'
            . 'ev.forEach(function(e){w.addEventListener(e,load,{passive:true});});'
            . 'var c=d.querySelectorAll(".mwai-chatbot-container:not(.mwai-popup-container),.mwai-discussions-container"),i;'
            . 'if(c.length){if(!("IntersectionObserver" in w)){load();return;}'
            . 'io=new IntersectionObserver(function(es){for(var j=0;j<es.length;j++){if(es[j].isIntersecting){load();return;}}},{rootMargin:"%1$dpx 0px"});'
            . 'for(i=0;i<c.length;i++)io.observe(c[i]);}})(document,window);',
            self::DISTANCE,
            self::STYLE_WAIT
        );
    }
}
