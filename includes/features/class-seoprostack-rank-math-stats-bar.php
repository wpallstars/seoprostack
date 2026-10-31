<?php
/**
 * Leave out Rank Math's stats bar.
 *
 * Rank Math's Frontend Stats Bar (Rank Math → General Settings → Analytics,
 * on by default once Search Console is connected) loads on every post and
 * page for people who may see Analytics: a React script with wp-element,
 * wp-components, lodash and wp-api-fetch (rank-math-analytics-stats, plus
 * rank-math-analytics-pro-stats with Rank Math Pro), which then asks the
 * REST API for the page's stats. Folding the bar away with its own button
 * only saves rank_math_hide_frontend_stats user meta: everything still
 * loads. Checked with Rank Math 1.0.280 and Rank Math Pro 3.0.124.
 *
 * Turned off through Rank Math's own rank_math/analytics/frontend_stats
 * filter. Rank Math sets up its modules on after_setup_theme, before
 * features boot on init, so the bar's scripts and styles are also dequeued.
 * Rank Math's settings are not changed, and switching this off brings the
 * bar back. Rank Math → Analytics keeps the stats.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.122
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Rank_Math_Stats_Bar extends SEOProStack_Feature {

    const KEY = 'rank_math_no_stats_bar';

    /** Script and style handles of the bar: Rank Math's, then Rank Math Pro's. */
    const HANDLES = array('rank-math-analytics-stats', 'rank-math-analytics-pro-stats');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: the bar slows every
                // post and page for logged-in people, even when folded away.
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Leave out Rank Math’s stats bar', 'seoprostack'),
                'description' => __('Rank Math’s stats bar below the admin bar loads its own scripts and asks the server for the page’s Analytics on every post and page you open while logged in, even when you have folded it away. This leaves it out; Rank Math → Analytics still has the stats. Rank Math’s settings are not changed, and nothing changes without Rank Math.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks. The bar shows only on the site, never in the admin.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        // For checks Rank Math makes after init.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own filter.
        add_filter('rank_math/analytics/frontend_stats', '__return_false');
        // After Rank Math's own enqueue (priority 10), set up before init.
        add_action('wp_enqueue_scripts', array(__CLASS__, 'dequeue'), 100);
    }

    /**
     * Dequeue the bar's scripts and styles, where Rank Math added them.
     */
    public static function dequeue() {
        foreach (self::HANDLES as $handle) {
            wp_dequeue_script($handle);
            wp_dequeue_style($handle);
        }
    }
}
