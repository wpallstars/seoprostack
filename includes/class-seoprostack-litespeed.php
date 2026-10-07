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
 * - Answers the litespeed_crawler condition and fills in the site's
 *   sitemap, so the preset turns LiteSpeed Cache's crawler on where the
 *   server allows it, to cache pages again after a purge.
 * - Saves preset changes through LiteSpeed Cache's own save code, so its
 *   .htaccess rules, wp-config.php WP_CACHE line, cron and purges follow,
 *   as when settings are saved on its screen.
 * - Notes in Free Plugins that WP-Optimize is not needed on LiteSpeed
 *   servers, and is for its page cache elsewhere.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
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
        add_filter('seoprostack_plugin_presets', array(__CLASS__, 'preset_sitemap'));
        add_action('seoprostack_plugin_preset_changed', array(__CLASS__, 'save_through_plugin'), 10, 2);
        add_filter('seoprostack_free_plugin_note', array(__CLASS__, 'free_plugin_note'), 10, 2);
        add_action('after_plugin_row', array(__CLASS__, 'wp_optimize_row_note'));
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
     * The litespeed_server and litespeed_crawler preset conditions.
     *
     * litespeed_crawler: a LiteSpeed server that lets LiteSpeed Cache's
     * crawler run, and a sitemap for it to crawl.
     *
     * @param bool   $holds     Whether it holds so far.
     * @param string $condition Condition.
     * @return bool
     */
    public static function condition($holds, $condition) {
        if ('litespeed_server' === $condition) {
            return self::is_server();
        }
        if ('litespeed_crawler' === $condition) {
            return self::is_server() && self::crawler_allowed() && '' !== self::crawler_sitemap();
        }
        return $holds;
    }

    /**
     * Whether the server lets LiteSpeed Cache's crawler run, as LiteSpeed
     * Cache checks it (\LiteSpeed\Router::can_crawl()): the server's
     * X-LSCACHE variable, when set, names "crawler". WP-CLI does not see it.
     *
     * @return bool
     */
    private static function crawler_allowed() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
        $flags = isset($_SERVER['X-LSCACHE']) ? (string) wp_unslash($_SERVER['X-LSCACHE']) : null;
        return null === $flags || false !== strpos($flags, 'crawler');
    }

    /**
     * The sitemap for LiteSpeed Cache's crawler: the list already set in
     * LiteSpeed Cache, or else the site's sitemap index (Rank Math's,
     * Yoast's, or WordPress's own).
     *
     * @return string One URL per line, or '' when none is known.
     */
    public static function crawler_sitemap() {
        $stored = self::conf('crawler-sitemap', '');
        if (is_string($stored) && '' !== trim($stored)) {
            return trim($stored);
        }
        $url = '';
        if (class_exists('\RankMath\Helper') && \RankMath\Helper::is_module_active('sitemap')) {
            $url = class_exists('\RankMath\Sitemap\Router')
                ? \RankMath\Sitemap\Router::get_base_url('sitemap_index.xml')
                : home_url('/sitemap_index.xml');
        } elseif (class_exists('WPSEO_Options') && WPSEO_Options::get('enable_xml_sitemap')) {
            $url = class_exists('WPSEO_Sitemaps_Router')
                ? WPSEO_Sitemaps_Router::get_base_url('sitemap_index.xml')
                : home_url('/sitemap_index.xml');
        } elseif (function_exists('wp_sitemaps_get_server') && wp_sitemaps_get_server()->sitemaps_enabled()) {
            $url = get_sitemap_url('index');
        }

        /**
         * Filter the sitemap SEO Pro Stack's LiteSpeed Cache preset gives
         * LiteSpeed Cache's crawler, when none is set there yet.
         *
         * @param string $url Sitemap index URL, or '' when none is known (the preset then leaves the crawler alone).
         */
        $url = apply_filters('seoprostack_crawler_sitemap', is_string($url) ? $url : '');
        return is_string($url) ? trim($url) : '';
    }

    /**
     * Fill in the crawler's sitemap in the LiteSpeed Cache preset, which
     * can only be known on each site.
     *
     * @param array<string,array> $presets Plugin folder => preset.
     * @return array<string,array>
     */
    public static function preset_sitemap($presets) {
        $name = self::PREFIX . 'crawler-sitemap';
        if (is_array($presets) && isset($presets[self::SLUG]['options']) && is_array($presets[self::SLUG]['options'])
            && array_key_exists($name, $presets[self::SLUG]['options'])) {
            $presets[self::SLUG]['options'][$name] = self::crawler_sitemap();
        }
        return $presets;
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
     * Whether LiteSpeed Cache is active for the whole network, where it
     * keeps object cache settings in network options.
     *
     * @return bool
     */
    public static function network_active() {
        if (!is_multisite()) {
            return false;
        }
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active_for_network(self::SLUG . '/litespeed-cache.php');
    }

    /**
     * One of LiteSpeed Cache's settings as it uses it: the network's value
     * when it is active for the network and has one there.
     *
     * @param string $id      Setting, without the litespeed.conf. prefix.
     * @param mixed  $default Value when it is not saved.
     * @return mixed
     */
    public static function conf($id, $default) {
        if (self::network_active()) {
            $value = get_site_option(self::PREFIX . $id, null);
            if (null !== $value) {
                return $value;
            }
        }
        return get_option(self::PREFIX . $id, $default);
    }

    /**
     * Who may change LiteSpeed Cache's object cache settings: network
     * administrators when it is active for the network.
     *
     * @return bool
     */
    public static function can_save_object_cache() {
        return current_user_can(self::network_active() ? 'manage_network_options' : 'manage_options');
    }

    /**
     * Point LiteSpeed Cache's object cache at a server, through its own save
     * code, which rewrites its object-cache.php settings file as its screen
     * does, then empty the cache there. Other object cache settings are
     * left alone.
     *
     * @param string $kind Redis or Memcached.
     * @param string $host Host or Unix socket path.
     * @param int    $port Port (0 for a socket).
     * @return bool Whether LiteSpeed Cache's code was there to save it.
     */
    public static function save_object_cache($kind, $host, $port) {
        if (!class_exists('\LiteSpeed\Conf') || !is_callable(array('\LiteSpeed\Conf', 'cls'))) {
            return false;
        }
        $matrix = array(
            'object-kind' => 'Redis' === $kind,
            'object-host' => (string) $host,
            'object-port' => (int) $port,
        );
        $conf = \LiteSpeed\Conf::cls();
        if (!is_object($conf)) {
            return false;
        }
        if (self::network_active()) {
            if (!is_callable(array($conf, 'network_update')) || !class_exists('\LiteSpeed\Activation')) {
                return false;
            }
            // As LiteSpeed Cache's network settings screen saves them.
            foreach ($matrix as $id => $value) {
                $conf->network_update($id, $value);
            }
            \LiteSpeed\Activation::cls()->update_files();
        } elseif (is_callable(array($conf, 'update_confs'))) {
            $conf->update_confs($matrix);
        } else {
            return false;
        }
        self::flush_object_cache($kind, (string) $host, (int) $port, (int) self::conf('object-db_id', 0));
        return true;
    }

    /**
     * Empty the cache at the new address, as LiteSpeed Cache does when it
     * reconnects: it may hold settings and posts from when the site last
     * used it, and this request saved to the database only. Connects with
     * the PHP extension, because LiteSpeed Cache refuses to connect again in
     * a request where its drop-in failed (LITESPEED_OC_FAILURE). Like its
     * Purge All, Memcached is emptied for every site using that server;
     * Redis only for its database number.
     *
     * @param string $kind Redis or Memcached.
     * @param string $host Host or Unix socket path.
     * @param int    $port Port (0 for a socket).
     * @param int    $db   Redis database number.
     * @return bool Whether it was emptied.
     */
    private static function flush_object_cache($kind, $host, $port, $db) {
        try {
            if ('Redis' === $kind) {
                if (!class_exists('Redis')) {
                    return false;
                }
                $redis = new Redis();
                if (!($port ? $redis->connect($host, $port, 0.5) : $redis->connect($host))) {
                    return false;
                }
                if ($db && !$redis->select($db)) {
                    return false;
                }
                $done = (bool) $redis->flushDb();
                $redis->close();
                return $done;
            }
            if (!class_exists('Memcached')) {
                return false;
            }
            $memcached = new Memcached();
            $memcached->setOption(Memcached::OPT_CONNECT_TIMEOUT, 500);
            $memcached->addServer($host, $port);
            $done = $memcached->flush();
            $memcached->quit();
            return $done;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Free Plugins note for WP-Optimize: needed for its page cache on other
     * servers; on LiteSpeed servers LiteSpeed Cache and SEO Pro Stack do its
     * jobs.
     *
     * @param string $note Note.
     * @param string $slug Plugin slug.
     * @return string
     */
    public static function free_plugin_note($note, $slug) {
        if (!in_array($slug, array('wp-optimize', 'wp-optimize-premium'), true)) {
            return $note;
        }
        if (self::is_server()) {
            return __('Not needed here: this site runs on a LiteSpeed server, so use LiteSpeed Cache instead.', 'seoprostack');
        }
        return __('Use this on servers other than LiteSpeed, for its page cache. Its plugin preset turns the page cache on and leaves the rest to SEO Pro Stack.', 'seoprostack');
    }

    /**
     * Warn before either edition removes a WP_CACHE line LiteSpeed needs.
     * Use a row note rather than writing wp-config.php outside the cache
     * plugin's own settings save, which also reports file permission errors.
     *
     * @param string $file Plugin file.
     */
    public static function wp_optimize_row_note($file) {
        global $wp_list_table;
        if (!in_array($file, array('wp-optimize/wp-optimize.php', 'wp-optimize-premium/wp-optimize.php'), true)
            || !self::is_server() || !is_plugin_active($file) || !is_plugin_active('litespeed-cache/litespeed-cache.php')) {
            return;
        }
        $columns = ($wp_list_table instanceof WP_List_Table) ? $wp_list_table->get_column_count() : 4;
        printf(
            '<tr class="plugin-update-tr active"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
            (int) $columns,
            esc_html__('Deactivating WP-Optimize or WP-Optimize Premium can remove the WP_CACHE line LiteSpeed Cache needs from wp-config.php. After deactivating it, save LiteSpeed Cache’s Cache settings to restore that line, and check for any file permission warning.', 'seoprostack')
        );
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
