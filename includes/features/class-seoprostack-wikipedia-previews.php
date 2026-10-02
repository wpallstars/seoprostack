<?php
/**
 * Wikipedia previews.
 *
 * Links to Wikipedia articles in posts and widgets, and Wikipedia Preview's
 * `<span data-wikipedia-preview data-wp-title data-wp-lang>` markup, show a
 * short preview (title, summary, picture) on hover, focus or tap. The
 * visitor's browser reads it from Wikipedia's page summary API: contacting
 * Wikipedia is the point of the feature, and only happens when a preview
 * opens. The small script loads only on pages with such links.
 *
 * Replaces Wikipedia Preview. Posts where its "detect links" option was
 * turned off (post meta wikipediapreview_detectlinks) keep plain links;
 * their marked words still preview.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Wikipedia_Previews extends SEOProStack_Feature {

    const KEY = 'wikipedia_previews';

    /** Script and style handle. */
    const HANDLE = 'seoprostack-wikipedia-previews';

    /** Wikipedia Preview's per-post switch for links. */
    const DETECT_META = 'wikipediapreview_detectlinks';

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
                'tab'         => 'content',
                'label'       => __('Wikipedia previews', 'seoprostack'),
                'description' => __('Links to Wikipedia articles in your posts show a short preview of the article on hover or tap. The preview comes from Wikipedia when a visitor opens one; nothing loads on pages without such links.', 'seoprostack'),
                'replaces'    => array('wikipedia-preview' => 'Wikipedia Preview'),
            ),
        );
    }

    /**
     * Switch on while Wikipedia Preview is active (it has no site settings).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['wikipedia-preview']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin() || !class_exists('WP_HTML_Tag_Processor')) {
            return;
        }
        add_filter('the_content', array(__CLASS__, 'filter_content'), 99);
        add_filter('widget_block_content', array(__CLASS__, 'filter_widget'), 99);
        add_filter('widget_text', array(__CLASS__, 'filter_widget'), 99);
    }

    /**
     * Post content: mark Wikipedia links unless the post turned them off.
     *
     * @param string $content HTML.
     * @return string
     */
    public static function filter_content($content) {
        // Excerpts run the_content and then strip the tags.
        if (doing_filter('get_the_excerpt')) {
            return $content;
        }
        $post_id = (int) get_the_ID();
        $links   = true;
        if ($post_id && metadata_exists('post', $post_id, self::DETECT_META)) {
            $links = (bool) get_post_meta($post_id, self::DETECT_META, true);
        }
        return self::mark((string) $content, $links);
    }

    /**
     * Widgets: mark Wikipedia links.
     *
     * @param string $content HTML.
     * @return string
     */
    public static function filter_widget($content) {
        return self::mark((string) $content, true);
    }

    /**
     * Give each Wikipedia link (and marked word) the article's language and
     * title, and load the script when there is one.
     *
     * @param string $html  HTML.
     * @param bool   $links Whether plain links to Wikipedia get previews.
     * @return string
     */
    public static function mark($html, $links) {
        if (is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $html;
        }
        $has_links = $links && false !== stripos($html, 'wikipedia.org/wiki/');
        if (!$has_links && false === strpos($html, 'data-wikipedia-preview')) {
            return $html;
        }

        $tags  = new WP_HTML_Tag_Processor($html);
        $found = false;
        while ($tags->next_tag()) {
            if (null !== $tags->get_attribute('data-wikipedia-preview')) {
                $title = (string) $tags->get_attribute('data-wp-title');
                $lang  = self::clean_lang((string) $tags->get_attribute('data-wp-lang'));
                if ('' === trim($title)) {
                    continue;
                }
                $tags->set_attribute('data-wp-lang', '' !== $lang ? $lang : self::site_lang());
                if ('A' !== $tags->get_tag()) {
                    $tags->set_attribute('tabindex', '0');
                }
                $found = true;
                continue;
            }
            if (!$has_links || 'A' !== $tags->get_tag()) {
                continue;
            }
            $article = self::article((string) $tags->get_attribute('href'));
            if ($article) {
                $tags->set_attribute('data-wikipedia-preview', '');
                $tags->set_attribute('data-wp-lang', $article[0]);
                $tags->set_attribute('data-wp-title', $article[1]);
                $found = true;
            }
        }
        if (!$found) {
            return $html;
        }
        self::enqueue();
        return $tags->get_updated_html();
    }

    /**
     * Language and title of a Wikipedia article address.
     *
     * @param string $href Link address.
     * @return array{0:string,1:string}|null
     */
    public static function article($href) {
        $parts = wp_parse_url(html_entity_decode($href, ENT_QUOTES));
        if (empty($parts['host']) || empty($parts['path']) || !preg_match('/^([a-z][a-z0-9-]{1,15})(?:\.m)?\.wikipedia\.org$/i', $parts['host'], $m)) {
            return null;
        }
        if (0 !== strpos($parts['path'], '/wiki/')) {
            return null;
        }
        $title = trim(str_replace('_', ' ', rawurldecode(substr($parts['path'], 6))));
        // Pages outside the articles (Special:, File:, Category: …) have no summary.
        if ('' === $title || preg_match('/^(special|file|image|category|help|wikipedia|template|portal|talk|user|draft|module|mediawiki)(\s+talk)?:/i', $title)) {
            return null;
        }
        return array(strtolower($m[1]), $title);
    }

    /**
     * Valid Wikipedia language code, or ''.
     *
     * @param string $lang Code.
     * @return string
     */
    private static function clean_lang($lang) {
        $lang = strtolower(trim($lang));
        return preg_match('/^[a-z][a-z0-9-]{1,15}$/', $lang) ? $lang : '';
    }

    /**
     * Wikipedia language for marked words without one: the site's.
     *
     * @return string
     */
    private static function site_lang() {
        $lang = self::clean_lang(strtok(str_replace('_', '-', (string) get_locale()), '-'));
        return '' !== $lang ? $lang : 'en';
    }

    /**
     * Load the script and style once, in the footer.
     */
    private static function enqueue() {
        if (wp_script_is(self::HANDLE, 'enqueued')) {
            return;
        }
        $js  = 'assets/wikipedia-previews.js';
        $css = 'assets/wikipedia-previews.css';
        wp_enqueue_style(self::HANDLE, SEOPROSTACK_URL . $css, array(), (string) filemtime(SEOPROSTACK_DIR . $css));
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $js, array(), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        wp_add_inline_script(self::HANDLE, 'window.seoprostackWikipedia = ' . wp_json_encode(array(
            'readMore'    => __('Read more on Wikipedia', 'seoprostack'),
            'close'       => __('Close', 'seoprostack'),
            'loading'     => __('Loading…', 'seoprostack'),
            'unavailable' => __('No preview is available for this article.', 'seoprostack'),
            'source'      => __('From Wikipedia, the free encyclopedia', 'seoprostack'),
        )) . ';', 'before');
    }
}
