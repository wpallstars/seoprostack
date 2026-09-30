<?php
/**
 * Load the next page before the visitor clicks.
 *
 * Uses the browser's Speculation Rules: when a visitor hovers or starts to
 * tap a link to another page on this site, the browser fetches (or fully
 * prepares) that page so it opens almost instantly. On WordPress 6.8+ this
 * tunes core's built-in speculative loading; on older versions it adds the
 * rules itself. Browsers without support simply ignore them. Replaces
 * "Flying Pages"; its ignore list is imported once.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Preload_Pages extends SEOProStack_Feature {

    const KEY = 'preload_pages';

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
                'label'       => __('Load pages before the click', 'seoprostack'),
                'description' => __('When a visitor points at or starts to tap a link on your site, the browser starts loading that page so it opens almost instantly.', 'seoprostack'),
                'replaces'    => array('flying-pages' => 'Flying Pages'),
            ),
            'preload_pages_mode' => array(
                'type'    => 'select',
                'default' => 'prefetch',
                'parent'  => self::KEY,
                'label'   => __('How much to load', 'seoprostack'),
                'options' => array(
                    'prefetch'  => __('Download the page (safe for every site)', 'seoprostack'),
                    'prerender' => __('Fully prepare the page (fastest; may count a visit in analytics before the click)', 'seoprostack'),
                ),
            ),
            'preload_pages_eagerness' => array(
                'type'    => 'select',
                'default' => 'moderate',
                'parent'  => self::KEY,
                'label'   => __('When to start', 'seoprostack'),
                'options' => array(
                    'conservative' => __('When the link is pressed', 'seoprostack'),
                    'moderate'     => __('When the pointer rests on the link', 'seoprostack'),
                    'eager'        => __('As soon as the pointer moves onto the link', 'seoprostack'),
                ),
            ),
            'preload_pages_exclude' => array(
                'type'        => 'lines',
                'default'     => "/cart\n/checkout\n/my-account\nadd-to-cart\nlogout",
                'parent'      => self::KEY,
                'label'       => __('Never preload links containing', 'seoprostack'),
                'description' => __('One per line: a path such as /cart or any text in the address such as logout. Admin, login, file and query-string links are always skipped.', 'seoprostack'),
            ),
        );
    }

    /**
     * Import Flying Pages settings.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (false === get_option('FLYING_PAGES_VERSION')) {
            return $options;
        }
        $keywords = get_option('flying_pages_config_ignore_keywords');
        $keep     = array();
        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                $keyword = trim((string) $keyword);
                // Covered by the built-in exclusions (admin, login, query strings, fragments, files).
                if ('' === $keyword || in_array($keyword, array('/wp-admin', '/wp-login.php', '#', '?'), true) || preg_match('/^\.[a-z0-9]{2,5}$/i', $keyword)) {
                    continue;
                }
                $keep[] = $keyword;
            }
        }

        $options = self::import_setting($options, self::KEY, true);
        return self::import_setting($options, 'preload_pages_exclude', $keywords ? implode("\n", $keep) : null);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        if (function_exists('wp_get_speculation_rules_configuration')) {
            add_filter('wp_speculation_rules_configuration', array(__CLASS__, 'configuration'), 20);
            add_filter('wp_speculation_rules_href_exclude_paths', array(__CLASS__, 'core_exclusions'), 20);
        } else {
            add_action('wp_footer', array(__CLASS__, 'print_rules'), 20);
        }
    }

    /**
     * Visitors only: logged-in pages differ (admin bar, previews).
     *
     * @return bool
     */
    private static function active() {
        return !is_user_logged_in() && !is_customize_preview() && get_option('permalink_structure');
    }

    /**
     * Core configuration (WordPress 6.8+).
     *
     * @param array|null $config Core configuration.
     * @return array|null
     */
    public static function configuration($config) {
        if (!self::active()) {
            return $config;
        }
        return array(
            'mode'      => (string) SEOProStack_Settings::get('preload_pages_mode'),
            'eagerness' => (string) SEOProStack_Settings::get('preload_pages_eagerness'),
        );
    }

    /**
     * Exclusions as URL patterns relative to the site root.
     *
     * @return string[]
     */
    public static function patterns() {
        $patterns = array();
        foreach (preg_split('/\n/', (string) SEOProStack_Settings::get('preload_pages_exclude'), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            // URL pattern syntax: escape its special characters, then match the text anywhere or as a path prefix.
            $escaped    = preg_replace('/([\\\\:*?+(){}])/', '\\\\$1', $line);
            $patterns[] = '/' === $line[0] ? $escaped . '*' : '/*' . $escaped . '*';
        }
        return array_values(array_unique($patterns));
    }

    /**
     * Add exclusions to core's list (WordPress 6.8+).
     *
     * @param string[] $paths Core exclusions.
     * @return string[]
     */
    public static function core_exclusions($paths) {
        return array_merge((array) $paths, self::patterns());
    }

    /**
     * Print rules on WordPress before 6.8.
     */
    public static function print_rules() {
        if (!self::active() || is_404()) {
            return;
        }
        $home    = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        $exclude = array_merge(
            array('/wp-*.php', '/wp-admin/*', '/wp-content/*', '/wp-includes/*', '/*\\?(.+)'),
            self::patterns()
        );
        $exclude = array_map(function ($pattern) use ($home) {
            return $home . $pattern;
        }, $exclude);

        $rules = array(
            (string) SEOProStack_Settings::get('preload_pages_mode') => array(
                array(
                    'source'    => 'document',
                    'where'     => array(
                        'and' => array(
                            array('href_matches' => $home . '/*'),
                            array('not' => array('href_matches' => $exclude)),
                            array('not' => array('selector_matches' => 'a[rel~="nofollow"]')),
                            array('not' => array('selector_matches' => '.no-prefetch, .no-prefetch a')),
                        ),
                    ),
                    'eagerness' => (string) SEOProStack_Settings::get('preload_pages_eagerness'),
                ),
            ),
        );
        printf('<script type="speculationrules">%s</script>' . "\n", wp_json_encode($rules, JSON_UNESCAPED_SLASHES));
    }
}
