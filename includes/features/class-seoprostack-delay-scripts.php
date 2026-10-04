<?php
/**
 * Delay chosen scripts until the visitor interacts.
 *
 * Scripts whose tag or code contains one of the listed keywords (for example
 * a chat widget or cookie banner) are held back until the visitor moves the
 * mouse, scrolls, taps or presses a key, or until a time limit passes. The
 * page becomes usable sooner and speed scores improve. Held scripts then run
 * in their original order. Replaces "Flying Scripts"; its settings are
 * imported once.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Delay_Scripts extends SEOProStack_Feature {

    const KEY = 'delay_scripts';

    /** Type given to held scripts so the browser does not run them. */
    const TYPE = 'seoprostack/delayed';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'speed',
                'label'       => __('Delay scripts until interaction', 'seoprostack'),
                'description' => __('Hold back scripts that are not needed straight away, such as chat widgets, until the visitor moves, scrolls, taps or types. Test your pages after adding a script.', 'seoprostack'),
                'replaces'    => array('flying-scripts' => 'Flying Scripts'),
            ),
            'delay_scripts_keywords' => array(
                'type'        => 'lines',
                'default'     => '',
                'rows'        => 6,
                'placeholder' => "chat-widget.js\nfbevents.js",
                'parent'      => self::KEY,
                'label'       => __('Scripts to delay', 'seoprostack'),
                'description' => __('One per line: part of the script’s file name or code, such as chat-widget.js or gtag(. Every matching script is delayed.', 'seoprostack'),
            ),
            'delay_scripts_timeout' => array(
                'type'        => 'int',
                'default'     => 5,
                'min'         => 0,
                'max'         => 60,
                'unit'        => __('seconds', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Run anyway after', 'seoprostack'),
                'description' => __('0 waits for interaction however long it takes.', 'seoprostack'),
            ),
            'delay_scripts_exclude' => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Do not delay on pages containing', 'seoprostack'),
                'description' => __('One per line: part of the page address, such as /checkout.', 'seoprostack'),
            ),
        );
    }

    /**
     * Import Flying Scripts settings.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (false === get_option('FLYING_SCRIPTS_VERSION')) {
            return $options;
        }
        $keywords = get_option('flying_scripts_include_list');
        $keywords = is_array($keywords) ? array_filter(array_map('trim', array_filter($keywords, 'is_string')), function ($keyword) {
            return '' !== $keyword;
        }) : array();
        $pages    = get_option('flying_scripts_disabled_pages');
        $timeout  = (int) get_option('flying_scripts_timeout', 5);
        if ($timeout > 60) {
            $timeout = (int) round($timeout / 1000); // Some installs stored milliseconds.
        }

        // Flying Scripts does nothing with an empty list, so neither do we.
        $options = self::import_setting($options, self::KEY, (bool) $keywords);
        $options = self::import_setting($options, 'delay_scripts_keywords', implode("\n", $keywords));
        $options = self::import_setting($options, 'delay_scripts_timeout', $timeout);
        return self::import_setting($options, 'delay_scripts_exclude', is_array($pages) ? implode("\n", $pages) : null);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin() || !self::keywords()) {
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'start'), PHP_INT_MAX);
    }

    /**
     * Keywords to match.
     *
     * @return string[]
     */
    public static function keywords() {
        $keywords = preg_split('/\n/', (string) SEOProStack_Settings::get('delay_scripts_keywords'), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($keywords) ? $keywords : array();
    }

    /**
     * Buffer the page for visitors on normal front-end views.
     */
    public static function start() {
        if (is_user_logged_in() || is_feed() || is_embed() || is_customize_preview() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_preview()) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
        foreach (preg_split('/\n/', (string) SEOProStack_Settings::get('delay_scripts_exclude'), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $page) {
            if ('' !== trim($page) && false !== stripos($uri, trim($page))) {
                return;
            }
        }
        ob_start(array(__CLASS__, 'rewrite'));
    }

    /**
     * Hold back matching scripts and add the loader.
     *
     * @param string $html Page.
     * @return string
     */
    public static function rewrite($html) {
        if (false === stripos($html, '</body>') || false === stripos(ltrim(substr($html, 0, 500)), '<')) {
            return $html; // Not an HTML page.
        }
        $keywords = self::keywords();
        $count    = 0;
        $original = $html;
        $html     = preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', function ($m) use ($keywords, &$count) {
            $attrs = $m[1];
            if (preg_match('#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $attrs, $type) && !preg_match('#^(text/javascript|application/javascript|module)$#i', $type[1])) {
                return $m[0]; // JSON, templates, speculation rules...
            }
            $matched = false;
            foreach ($keywords as $keyword) {
                if ('' !== $keyword && (false !== stripos($attrs, $keyword) || false !== stripos($m[2], $keyword))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched || false !== stripos($attrs, 'data-seoprostack-nodelay')) {
                return $m[0];
            }
            $count++;
            $original = isset($type[1]) ? $type[1] : '';
            $attrs    = preg_replace('#\btype\s*=\s*["\']?[^"\'\s>]+["\']?#i', '', $attrs);
            $attrs    = preg_replace('#\bsrc\s*=#i', 'data-seoprostack-src=', $attrs);
            return sprintf('<script type="%1$s" data-seoprostack-type="%2$s"%3$s>%4$s</script>', self::TYPE, esc_attr($original), $attrs, $m[2]);
        }, $html);

        if (null === $html || !$count) {
            return $original; // Unchanged, or the regex hit a PCRE limit.
        }
        $loader = '<script id="seoprostack-delay">' . self::loader_js() . '</script>';
        $pos    = strripos($html, '</body>');
        if (false === $pos) {
            return $original;
        }
        return substr($html, 0, $pos) . $loader . substr($html, $pos);
    }

    /**
     * Loader: run held scripts in order after interaction or the time limit.
     *
     * @return string
     */
    private static function loader_js() {
        $timeout = (int) SEOProStack_Settings::get('delay_scripts_timeout');
        return sprintf(
            '(function(){var done=false,events=["mousemove","mousedown","keydown","touchstart","wheel","scroll"];'
            . 'function run(){if(done)return;done=true;events.forEach(function(e){removeEventListener(e,run,{passive:true});});'
            . 'var list=[].slice.call(document.querySelectorAll(\'script[type="%1$s"]\'));'
            . '(function next(){var old=list.shift();if(!old)return;var s=document.createElement("script");'
            . '[].forEach.call(old.attributes,function(a){if(a.name!=="type"&&a.name!=="data-seoprostack-src"&&a.name!=="data-seoprostack-type")s.setAttribute(a.name,a.value);});'
            . 'var t=old.getAttribute("data-seoprostack-type");if(t)s.type=t;var src=old.getAttribute("data-seoprostack-src");'
            . 'if(src){s.src=src;s.async=false;s.onload=s.onerror=next;old.parentNode.replaceChild(s,old);}'
            . 'else{s.text=old.text;old.parentNode.replaceChild(s,old);next();}})();}'
            . 'events.forEach(function(e){addEventListener(e,run,{passive:true});});%2$s})();',
            self::TYPE,
            $timeout > 0 ? 'setTimeout(run,' . ($timeout * 1000) . ');' : ''
        );
    }
}
