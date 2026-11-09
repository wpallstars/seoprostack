<?php
/**
 * Load Fluent Forms' scripts when a form comes near: which of Fluent Forms'
 * and Fluent Forms Pro's scripts can wait on this page
 * (SEOProStack_Fluentforms_Later).
 *
 * Each of their own scripts not yet printed (a file in either plugin's
 * folder on this site), with form-submission.js and the scripts that need it
 * marked to run last. None, so the scripts print as before, on conversational
 * form pages; with a color picker or range slider field, whose inline scripts
 * do not check for their library (UNGUARDED); when a script of another
 * plugin needs one of them, as it would run first; or when Delay scripts'
 * keywords would hold one of them or a script they need (jQuery), in its id,
 * address or inline code.
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

final class SEOProStack_Fluentforms_Scripts {

    /** Fluent Forms' form script, run last. */
    const SUBMISSION = 'fluent-form-submission';

    /** Plugin folders whose scripts wait. */
    const FOLDERS = array('fluentform', 'fluentformpro');

    /**
     * Libraries whose fields Fluent Forms Pro starts from an inline script
     * at the end of the page without checking that the library is there
     * (color picker, range slider): they would throw before it arrives.
     */
    const UNGUARDED = array('pickr', 'rangeslider');

    /**
     * The handles that can wait on this page => 'late' for form-submission.js
     * and those that need it, '' for the rest. Empty when none can.
     *
     * @return array<string,string>
     */
    public static function plan() {
        $scripts = wp_scripts();
        $handles = array_values(array_unique(array_merge((array) $scripts->queue, (array) $scripts->to_do, (array) $scripts->done)));
        $own     = self::own_scripts($scripts, $handles);
        $hold    = array_keys(array_diff_key($own, array_flip((array) $scripts->done)));
        if (!$hold || isset($own['']) || array_intersect(self::UNGUARDED, $hold)
            || self::needed_elsewhere($scripts, $handles, $own, $hold) || self::delayed_any($hold)) {
            return array();
        }
        $plan = array();
        foreach ($hold as $handle) {
            $plan[$handle] = in_array(self::SUBMISSION, self::needs($handle), true) ? 'late' : '';
        }
        return $plan;
    }

    /**
     * Whether an address is a file in Fluent Forms' or Fluent Forms Pro's
     * folder on this site.
     *
     * @param string $src Script address, absolute or from the site's root.
     * @return bool
     */
    public static function own($src) {
        static $bases = null;
        if (null === $bases) {
            $root  = untrailingslashit((string) wp_parse_url(plugins_url(), PHP_URL_PATH));
            $bases = array_map(function ($folder) use ($root) {
                return $root . '/' . $folder . '/';
            }, self::FOLDERS);
        }
        $host = wp_parse_url($src, PHP_URL_HOST);
        if (is_string($host) && strtolower($host) !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            return false;
        }
        $path = (string) wp_parse_url($src, PHP_URL_PATH);
        foreach ($bases as $base) {
            if (0 === strpos($path, $base)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fluent Forms' own scripts on this page, by handle; with an '' key on a
     * conversational form page.
     *
     * @param WP_Scripts $scripts Scripts.
     * @param string[]   $handles Every handle on the page.
     * @return array<string,true>
     */
    private static function own_scripts(WP_Scripts $scripts, array $handles) {
        $own = array();
        foreach ($handles as $handle) {
            $item = $scripts->query($handle);
            if ($item instanceof _WP_Dependency && is_string($item->src) && self::own($item->src)) {
                $own[$handle] = true;
                if (false !== stripos($handle . ' ' . $item->src, 'conversational')) {
                    $own[''] = true;
                }
            }
        }
        return $own;
    }

    /**
     * Whether another plugin's script needs one of the held scripts, itself
     * or through a script it needs.
     *
     * @param WP_Scripts          $scripts Scripts.
     * @param string[]            $handles Every handle on the page.
     * @param array<string,true>  $own     Fluent Forms' own scripts.
     * @param string[]            $hold    Handles to hold.
     * @return bool
     */
    private static function needed_elsewhere(WP_Scripts $scripts, array $handles, array $own, array $hold) {
        foreach (array_diff($handles, array_keys($own)) as $handle) {
            if ($scripts->query($handle) instanceof _WP_Dependency && array_intersect(self::needs($handle), $hold)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether Delay scripts would hold one of these scripts, or one they need.
     *
     * @param string[] $hold Handles to hold.
     * @return bool
     */
    private static function delayed_any(array $hold) {
        if (!SEOProStack_Delay_Scripts::enabled()) {
            return false;
        }
        foreach ($hold as $handle) {
            if (array_filter(self::needs($handle), array(__CLASS__, 'delayed'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A script and everything it needs, through other plugins' scripts too.
     *
     * @param string $handle Handle.
     * @return string[]
     */
    private static function needs($handle) {
        $scripts = wp_scripts();
        $found   = array();
        $next    = array($handle);
        while ($next) {
            $current = (string) array_shift($next);
            if (isset($found[$current])) {
                continue;
            }
            $found[$current] = true;
            $item            = $scripts->query($current);
            if ($item instanceof _WP_Dependency) {
                $next = array_merge($next, (array) $item->deps);
            }
        }
        return array_keys($found);
    }

    /**
     * Whether Delay scripts' keywords are in a script's id, address or
     * inline code.
     *
     * @param string $handle Handle.
     * @return bool
     */
    private static function delayed($handle) {
        $text = self::text($handle);
        foreach (SEOProStack_Delay_Scripts::keywords() as $keyword) {
            $keyword = trim($keyword);
            if ('' !== $keyword && false !== stripos($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A script's id, address and inline code, as Delay scripts reads them.
     *
     * @param string $handle Handle.
     * @return string
     */
    private static function text($handle) {
        $item = wp_scripts()->query($handle);
        $text = $handle . '-js';
        if (!$item instanceof _WP_Dependency) {
            return $text;
        }
        $text .= ' ' . (is_string($item->src) ? $item->src : '');
        foreach (array('data', 'before', 'after') as $key) {
            $value = $item->extra[$key] ?? '';
            $text .= ' ' . implode("\n", array_filter((array) $value, 'is_string'));
        }
        return $text;
    }
}
