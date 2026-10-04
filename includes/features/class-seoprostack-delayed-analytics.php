<?php
/**
 * Google Analytics that does not slow the page.
 *
 * Adds Google Analytics 4 with the standard gtag.js, loaded after the
 * visitor first interacts with the page (or after a few seconds), so it no
 * longer competes with the page itself. Logged-in users are not tracked.
 * Replaces "Flying Analytics"; its measurement ID is imported once.
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

class SEOProStack_Delayed_Analytics extends SEOProStack_Feature {

    const KEY = 'delayed_analytics';

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
                'label'       => __('Delayed Google Analytics', 'seoprostack'),
                'description' => __('Add Google Analytics 4 without slowing the page: it loads after the visitor first moves, scrolls or taps, or after a few seconds. Turn off any other plugin that adds the same measurement ID.', 'seoprostack'),
                'replaces'    => array('flying-analytics' => 'Flying Analytics'),
            ),
            'delayed_analytics_id' => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Measurement ID', 'seoprostack'),
                'description' => __('Starts with G-, from Google Analytics › Admin › Data streams.', 'seoprostack'),
                'placeholder' => 'G-XXXXXXXXXX',
            ),
            'delayed_analytics_timeout' => array(
                'type'    => 'int',
                'default' => 5,
                'min'     => 1,
                'max'     => 30,
                'unit'    => __('seconds', 'seoprostack'),
                'parent'  => self::KEY,
                'label'   => __('Load anyway after', 'seoprostack'),
            ),
        );
    }

    /**
     * Import Flying Analytics settings (only when it had an ID).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $id = self::clean_id(get_option('flying_analytics_id', ''));
        if ('' === $id) {
            return $options;
        }
        $options = self::import_setting($options, self::KEY, true);
        return self::import_setting($options, 'delayed_analytics_id', $id);
    }

    /**
     * Normalise a GA4 measurement ID; '' when invalid.
     *
     * @param mixed $id Raw ID.
     * @return string
     */
    public static function clean_id($id) {
        $id = strtoupper(trim((string) $id));
        return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : '';
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        add_action('wp_footer', array(__CLASS__, 'print_script'), 99);
    }

    /**
     * Print the loader for visitors.
     */
    public static function print_script() {
        $id = self::clean_id(SEOProStack_Settings::get('delayed_analytics_id'));
        if ('' === $id || is_user_logged_in() || is_customize_preview() || is_preview()) {
            return;
        }
        $timeout = max(1, (int) SEOProStack_Settings::get('delayed_analytics_timeout')) * 1000;
        printf(
            // Already delayed: keep "Delay scripts" from holding it back again.
            '<script id="seoprostack-analytics" data-seoprostack-nodelay>(function(){window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.gtag=window.gtag||gtag;gtag("js",new Date());gtag("config",%1$s);'
            . 'var done=false,events=["mousemove","mousedown","keydown","touchstart","wheel","scroll"];'
            . 'function load(){if(done)return;done=true;events.forEach(function(e){removeEventListener(e,load,{passive:true});});'
            . 'var s=document.createElement("script");s.async=true;s.src="https://www.googletagmanager.com/gtag/js?id="+encodeURIComponent(%1$s);document.head.appendChild(s);}'
            . 'events.forEach(function(e){addEventListener(e,load,{passive:true});});setTimeout(load,%2$d);})();</script>' . "\n",
            wp_json_encode($id),
            (int) $timeout
        );
    }
}
