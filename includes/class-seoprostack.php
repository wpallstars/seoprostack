<?php
/**
 * SEO Pro Stack bootstrap and feature registry. The features and anything
 * else only this plugin needs come from SEOProStack_Setup.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-e4502af0f07e8a92. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack {

    /**
     * Feature classes whose files are loaded: SEOProStack_Setup::FEATURES,
     * plus each of SEOProStack_Setup::OPTIONAL_FEATURES this build has.
     *
     * @var string[]
     */
    private static $core_features = array();

    /**
     * Resolved feature classes.
     *
     * @var string[]|null
     */
    private static $features = null;

    /**
     * Each site's own tables, without the site's table prefix: the link
     * index's three (SEOProStack_Link_Index) and the media storage's claim,
     * URL and kept-original indexes. uninstall.php drops the same tables.
     *
     * @var string[]
     */
    const SITE_TABLES = array(
        'seoprostack_links',
        'seoprostack_link_health',
        'seoprostack_link_clicks',
        'seoprostack_media_claims',
        'seoprostack_media_urls',
        'seoprostack_media_originals',
    );

    /**
     * Load files and register hooks.
     */
    public static function load() {
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-settings.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-network-settings.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-network-sites.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-network-differences.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-feature.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-setup.php';
        SEOProStack_Setup::load();

        foreach (SEOProStack_Setup::FEATURES as $class) {
            require_once self::feature_file($class);
            self::$core_features[] = $class;
        }
        // Features that some builds leave out (the WordPress.org build has
        // no GitHub updates) load only when their file is present.
        foreach (SEOProStack_Setup::OPTIONAL_FEATURES as $class) {
            if (is_readable(self::feature_file($class))) {
                require_once self::feature_file($class);
                self::$core_features[] = $class;
            }
        }

        // The shared GitHub updater (GitHub builds only): registers this
        // copy; the newest copy on the site loads on plugins_loaded.
        if (is_readable(SEOPROSTACK_DIR . 'includes/github-updater/load.php')) {
            require_once SEOPROSTACK_DIR . 'includes/github-updater/load.php';
        }

        // Shared problem reports: registers this copy; the newest copy on
        // the site loads on after_setup_theme and lists this plugin, so a
        // site's AI assistant can draft reports for an administrator to send.
        if (is_readable(SEOPROSTACK_DIR . 'includes/wpallstars-reports/load.php')) {
            require_once SEOPROSTACK_DIR . 'includes/wpallstars-reports/load.php';
            add_filter('wpallstars_report_products', array(__CLASS__, 'report_product'));
        }

        SEOProStack_Settings::init();
        SEOProStack_Setup::init();
        // Deleting a network site drops its tables: ours too, whichever
        // features are on, as a site keeps a table after its feature is off.
        add_filter('wpmu_drop_tables', array(__CLASS__, 'site_tables'), 10, 2);
        // Priority 0, added after SEOProStack_Settings::maybe_migrate() so features
        // read migrated values, and early enough to hook widgets_init (init:1).
        add_action('init', array(__CLASS__, 'boot_features'), 0);

        if (is_admin()) {
            require_once SEOPROSTACK_DIR . 'admin/settings.php';
        }
    }

    /**
     * File of a feature class.
     *
     * @param string $class Class name.
     * @return string
     */
    private static function feature_file($class) {
        return SEOPROSTACK_DIR . 'includes/features/class-' . str_replace('_', '-', strtolower($class)) . '.php';
    }

    /**
     * The plugin's folder name, which names it in problem reports.
     *
     * @return string
     */
    public static function slug() {
        return dirname(plugin_basename(SEOPROSTACK_FILE));
    }

    /**
     * URL of a file under the plugin's assets/ folder: its .min sibling
     * (made by scripts/build-release.sh) when that exists and SCRIPT_DEBUG is
     * off, else the readable file, so a Git checkout works unchanged.
     *
     * @param string $path Path from the plugin folder, such as assets/x.js.
     * @return non-empty-string Never empty (the plugin's URL comes first), as
     *                          wp_register_script() and wp_register_style() need.
     */
    public static function asset_url($path) {
        $path = ltrim((string) $path, '/');
        if (!(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) && preg_match('/^(.+)\.(js|css)$/', $path, $m) && !preg_match('/\.min$/', $m[1])) {
            $min = $m[1] . '.min.' . $m[2];
            if (is_readable(SEOPROSTACK_DIR . $min)) {
                $path = $min;
            }
        }
        return SEOPROSTACK_URL . $path;
    }

    /**
     * List the plugin for problem reports (wpallstars_report_products):
     * reports go where SEOProStack_Setup::header_links()['support'] points, a
     * GitHub issues address (public) or mailto:address (private, by email).
     *
     * @param mixed $products Products.
     * @return array
     */
    public static function report_product($products) {
        $products = (array) $products;
        $links    = SEOProStack_Setup::header_links();
        if (!empty($links['support'])) {
            $products[self::slug()] = array(
                'name'    => 'SEO Pro Stack',
                'type'    => 'plugin',
                'version' => SEOPROSTACK_VERSION,
                'support' => $links['support'],
            );
        }
        return $products;
    }

    /**
     * Registered feature classes.
     *
     * @return string[]
     */
    public static function features() {
        if (null === self::$features) {
            /**
             * Filter the feature classes. Each must extend SEOProStack_Feature.
             *
             * @param string[] $features Class names.
             */
            $features       = (array) apply_filters('seoprostack_features', self::$core_features);
            self::$features = array_values(array_filter($features, function ($class) {
                return is_string($class) && class_exists($class) && is_subclass_of($class, 'SEOProStack_Feature');
            }));
        }
        return self::$features;
    }

    /**
     * Boot every feature.
     */
    public static function boot_features() {
        foreach (self::features() as $class) {
            $class::boot();
        }
    }

    /**
     * A deleted network site's tables, with ours added (wpmu_drop_tables):
     * WordPress drops each with DROP TABLE IF EXISTS, so a table the site
     * never made is no harm.
     *
     * @param mixed $tables  Table names.
     * @param mixed $site_id Site being deleted.
     * @return array
     */
    public static function site_tables($tables, $site_id = 0) {
        global $wpdb;
        $tables = (array) $tables;
        $prefix = $wpdb->get_blog_prefix((int) $site_id ?: get_current_blog_id());
        foreach (self::SITE_TABLES as $name) {
            if (!in_array($prefix . $name, $tables, true)) {
                $tables[$name] = $prefix . $name;
            }
        }
        return $tables;
    }

    /**
     * Plugin deactivated: let features that changed files or scheduled
     * work outside WordPress's options undo it (a static deactivate() method).
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        foreach (self::features() as $class) {
            if (method_exists($class, 'deactivate')) {
                $class::deactivate((bool) $network_wide);
            }
        }
    }
}
