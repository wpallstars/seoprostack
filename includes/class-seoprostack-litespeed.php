<?php
/**
 * LiteSpeed servers and LiteSpeed Cache.
 *
 * On a LiteSpeed server, LiteSpeed Cache uses the server's own page cache,
 * and with SEO Pro Stack's speed features it does what WP-Optimize does
 * there: LiteSpeed Cache keeps pages, browser caching and CSS and JS;
 * SEO Pro Stack does images, Heartbeat, WordPress extras and script delay.
 *
 * - Knows whether the site runs on a LiteSpeed server, from the server's
 *   own variables, and remembers it for WP-CLI, which does not see them.
 * - Answers the litespeed_server condition, which
 *   presets/litespeed-cache.json uses to turn the page cache and browser
 *   cache on only on a LiteSpeed server. Its feature:… conditions turn
 *   LiteSpeed's copy of a job off where SEO Pro Stack's feature for it is
 *   on, so the two never both do it.
 * - Saves preset changes through LiteSpeed Cache's own save code, so its
 *   .htaccess rules, wp-config.php WP_CACHE line, cron and purges follow,
 *   as when settings are saved on its screen.
 * - Leaves WP-Optimize out of the recommended plugins on LiteSpeed servers.
 *
 * @package SEOProStack
 * @since 0.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Litespeed {

    /** Remembered server: openlitespeed, enterprise, adc or none. Not autoloaded. */
    const OPTION = 'seoprostack_litespeed_server';

    /** LiteSpeed Cache's plugin folder. */
    const SLUG = 'litespeed-cache';

    /** Prefix of LiteSpeed Cache's options. */
    const PREFIX = 'litespeed.conf.';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'remember'));
        add_filter('seoprostack_preset_condition', array(__CLASS__, 'condition'), 10, 2);
        add_action('seoprostack_plugin_preset_changed', array(__CLASS__, 'save_through_plugin'), 10, 2);
        add_filter('seoprostack_free_plugins', array(__CLASS__, 'free_plugins'));
    }

    /**
     * The LiteSpeed server this request came through, from the variables
     * LiteSpeed sets (as LiteSpeed Cache reads them).
     *
     * @return string openlitespeed, enterprise, adc, none, or '' when this
     *                request cannot tell (WP-CLI).
     */
    private static function detect() {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
        if (!empty($_SERVER['HTTP_X_LSCACHE'])) {
            return 'adc';
        }
        $edition  = isset($_SERVER['LSWS_EDITION']) ? (string) wp_unslash($_SERVER['LSWS_EDITION']) : '';
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) wp_unslash($_SERVER['SERVER_SOFTWARE']) : '';
        // phpcs:enable
        if (0 === stripos($edition, 'openlitespeed')) {
            return 'openlitespeed';
        }
        if (0 === stripos($software, 'litespeed')) {
            return 'enterprise';
        }
        if ('' === $software || (defined('WP_CLI') && WP_CLI)) {
            return '';
        }
        return 'none';
    }

    /**
     * The site's LiteSpeed server, or '' when it is not one (or not known yet).
     *
     * @return string openlitespeed, enterprise, adc or ''.
     */
    public static function server() {
        $server = self::detect();
        if ('' === $server) {
            $server = (string) get_option(self::OPTION, '');
        }
        return 'none' === $server ? '' : $server;
    }

    /**
     * Whether the site runs on a LiteSpeed server.
     *
     * @return bool
     */
    public static function is_server() {
        return '' !== self::server();
    }

    /**
     * Remember the server for WP-CLI, only when it changes.
     */
    public static function remember() {
        $server = self::detect();
        if ('' !== $server && get_option(self::OPTION, '') !== $server) {
            update_option(self::OPTION, $server, false);
        }
    }

    /**
     * Name of the server, for Hosting needs.
     *
     * @return string
     */
    public static function server_name() {
        $names = array(
            'openlitespeed' => __('OpenLiteSpeed', 'seoprostack'),
            'enterprise'    => __('LiteSpeed Enterprise', 'seoprostack'),
            'adc'           => __('LiteSpeed Web ADC', 'seoprostack'),
        );
        $server = self::server();
        if (isset($names[$server])) {
            return $names[$server];
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shown escaped by the caller.
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
        return '' !== $software ? $software : __('Not known', 'seoprostack');
    }

    /**
     * The litespeed_server preset condition.
     *
     * @param bool   $holds     Whether it holds so far.
     * @param string $condition Condition.
     * @return bool
     */
    public static function condition($holds, $condition) {
        return 'litespeed_server' === $condition ? self::is_server() : $holds;
    }

    /**
     * After a preset is applied, reset or undone, save LiteSpeed Cache's
     * changed settings again through its own code, which compares them with
     * the values it loaded for this request and does what its settings
     * screen does on save: .htaccess, WP_CACHE, cron, purges.
     *
     * @param string   $slug  Plugin folder.
     * @param string[] $names Option names written.
     */
    public static function save_through_plugin($slug, $names) {
        if (self::SLUG !== $slug || !class_exists('\LiteSpeed\Conf') || !is_callable(array('\LiteSpeed\Conf', 'cls'))) {
            return;
        }
        $matrix = array();
        foreach ((array) $names as $name) {
            $name = (string) $name;
            if (0 !== strpos($name, self::PREFIX)) {
                continue;
            }
            $value = get_option($name, null);
            if (null !== $value && is_scalar($value)) {
                $matrix[substr($name, strlen(self::PREFIX))] = $value;
            }
        }
        if ($matrix) {
            \LiteSpeed\Conf::cls()->update_confs($matrix);
        }
    }

    /**
     * Leave WP-Optimize out of the recommended plugins on LiteSpeed servers,
     * where LiteSpeed Cache and SEO Pro Stack do its jobs.
     *
     * @param array $plugins Category => slugs.
     * @return array
     */
    public static function free_plugins($plugins) {
        if (!is_array($plugins) || !self::is_server()) {
            return $plugins;
        }
        foreach ($plugins as $category => $slugs) {
            if (is_array($slugs)) {
                $plugins[$category] = array_values(array_diff($slugs, array('wp-optimize')));
            }
        }
        return $plugins;
    }

    /**
     * WP-Optimize's page cache and minify, when they are on.
     *
     * @return string[] What is on: cache, minify.
     */
    public static function wp_optimize_overlap() {
        $on     = array();
        $cache  = is_multisite() ? get_site_option('wpo_cache_config', array()) : get_option('wpo_cache_config', array());
        $minify = is_multisite() ? get_site_option('wpo_minify_config', array()) : get_option('wpo_minify_config', array());
        if (is_array($cache) && !empty($cache['enable_page_caching'])) {
            $on[] = 'cache';
        }
        if (is_array($minify) && !empty($minify['enabled'])) {
            $on[] = 'minify';
        }
        return $on;
    }
}
