<?php
/**
 * WP-Optimize: its page cache for sites on servers other than LiteSpeed.
 *
 * presets/wp-optimize.json turns its page cache on where the site is not on
 * a LiteSpeed server (there LiteSpeed Cache keeps pages). Storing the
 * setting is not enough: WP-Optimize's own save code writes
 * advanced-cache.php, the WP_CACHE line in wp-config.php and its config
 * file, and purges. After the preset is applied, reset or undone, this runs
 * that code, as its Cache screen does on save.
 *
 * @package SEOProStack
 * @since 0.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_WP_Optimize {

    /** WP-Optimize's plugin folder. */
    const SLUG = 'wp-optimize';

    /** Premium replaces the free edition, rather than loading as an add-on. */
    const PREMIUM_SLUG = 'wp-optimize-premium';

    /** WP-Optimize's page cache settings. */
    const CACHE = 'wpo_cache_config';

    /**
     * Register hooks.
     */
    public static function init() {
        add_filter('seoprostack_plugin_presets', array(__CLASS__, 'premium_preset'));
        add_action('seoprostack_plugin_preset_changed', array(__CLASS__, 'save_through_plugin'), 10, 2);
    }

    /**
     * Offer the shared options for Premium without maintaining a second JSON.
     * Keep an explicitly supplied Premium preset in preference to the alias.
     *
     * @param array $presets Plugin folder => preset.
     * @return array
     */
    public static function premium_preset($presets) {
        if (isset($presets[self::SLUG]) && !isset($presets[self::PREMIUM_SLUG])) {
            $presets[self::PREMIUM_SLUG] = $presets[self::SLUG];
            $presets[self::PREMIUM_SLUG]['name'] = 'WP-Optimize Premium';
        }
        return $presets;
    }

    /**
     * After a preset changed WP-Optimize's page cache settings, save them
     * again through WP_Optimize_Cache_Commands::save_cache_settings(), which
     * turns the cache on or off only when its stored switch differs from
     * what it is asked for. So the switch is first set to the opposite
     * wherever the files say the cache is not (fully) in that state: to
     * turn it on unless WP_CACHE and its advanced-cache.php are both there,
     * and off while its advanced-cache.php is, which also tidies a
     * half-finished setup.
     *
     * @param string   $slug  Plugin folder.
     * @param string[] $names Option names written.
     */
    public static function save_through_plugin($slug, $names) {
        if (!in_array($slug, array(self::SLUG, self::PREMIUM_SLUG), true) || !in_array(self::CACHE, (array) $names, true)) {
            return;
        }
        if (!class_exists('WPO_Cache_Config') || !class_exists('WP_Optimize_Cache_Commands')) {
            return;
        }
        // With WP-Optimize's defaults, in case undo removed the option.
        $wanted = WPO_Cache_Config::instance()->get();
        $on     = !empty($wanted['enable_page_caching']);
        $flag   = defined('WP_CACHE') && WP_CACHE;
        $file   = self::own_dropin();
        $stored = $wanted;
        // Off only while its own advanced-cache.php is there: WP_CACHE alone
        // may be another cache plugin's, and does nothing without the file.
        $stored['enable_page_caching'] = $on ? ($flag && $file) : $file;
        self::store($stored);

        $commands = new WP_Optimize_Cache_Commands();
        $commands->save_cache_settings(array('cache-settings' => $wanted));
        self::forget_config();
    }

    /**
     * Drop wp-config.php from OPcache after WP-Optimize changed its WP_CACHE
     * line. WP-Optimize does not, so for opcache.revalidate_freq seconds
     * (2 by default, often 60 on hosts) the next requests still run the old
     * file, and an undo straight after a reset sees WP_CACHE still on and
     * skips writing it back. Paths as wp-load.php finds the file.
     */
    private static function forget_config() {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        // Where the host limits the OPcache API to some scripts, calling it
        // from others gives a warning.
        $allowed = (string) ini_get('opcache.restrict_api');
        $script  = isset($_SERVER['SCRIPT_FILENAME']) ? (string) wp_unslash($_SERVER['SCRIPT_FILENAME']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a path, compared only.
        if ('' !== $allowed && 0 !== strpos($script, $allowed)) {
            return;
        }
        foreach (array(ABSPATH . 'wp-config.php', dirname(ABSPATH) . '/wp-config.php') as $path) {
            if (is_file($path)) {
                opcache_invalidate($path, true);
            }
        }
    }

    /**
     * Whether wp-content/advanced-cache.php is WP-Optimize's. Read from the
     * file, because WP-CLI never loads advanced-cache.php, so
     * WPO_ADVANCED_CACHE is not defined there.
     *
     * @return bool
     */
    private static function own_dropin() {
        $file = WP_CONTENT_DIR . '/advanced-cache.php';
        if (!is_readable($file)) {
            return false;
        }
        $code = file_get_contents($file, false, null, 0, 8192); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        return is_string($code) && false !== strpos($code, 'WPO_ADVANCED_CACHE');
    }

    /**
     * Store WP-Optimize's page cache settings, where it keeps them.
     *
     * @param array $config Settings.
     */
    private static function store(array $config) {
        if (is_multisite()) {
            update_site_option(self::CACHE, $config);
        } else {
            update_option(self::CACHE, $config);
        }
    }
}
