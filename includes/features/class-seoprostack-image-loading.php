<?php
/**
 * Image loading: which pictures load straight away and which wait.
 *
 * WordPress already lazy-loads pictures and iframes with the browser's own
 * loading="lazy", and loads the first few pictures straight away. This
 * tunes that, through core's filters only, so pages are never rewritten:
 * - how many pictures at the top of the page load straight away
 *   (`wp_omit_loading_attr_threshold`);
 * - pictures and iframes that always load straight away, such as a logo or
 *   a slider that lazy loading breaks: site logos (the custom-logo class),
 *   the skip-lazy and no-lazy classes, a data-skip-lazy or data-no-lazy
 *   attribute, loading="eager", and any text from a list
 *   (`wp_content_img_tag`, `wp_get_attachment_image_attributes`,
 *   `wp_iframe_tag_add_loading_attr`).
 *
 * Pictures that load straight away (logos, high fetch priority, the first
 * pictures, the list) also get data-no-lazy="1". Cache plugins that lazy-load
 * by rewriting the page with JavaScript, such as LiteSpeed Cache and
 * WP Rocket, skip pictures with it. Without it they swap even the logo for a
 * placeholder on every page, so it flashes as visitors move between pages
 * (GH#925).
 *
 * Replaces Flying Images' lazy loading, in part: its exclusions are imported
 * once. Its CDN, compression, WebP and responsive images are not replaced
 * (WebP and AVIF images and core's srcset cover the last two), nor its
 * JavaScript lazy loading of background pictures, which rewrites every page.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.12.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Image_Loading extends SEOProStack_Feature {

    const KEY = 'image_loading';

    /** Flying Images' example exclusion, left out of the import. */
    const FLYING_EXAMPLE = 'your-logo.png';

    /** Attribute that page-rewriting lazy loaders skip. */
    const NO_LAZY = 'data-no-lazy';

    /** @var int Pictures encountered in the main loop's output this request. */
    private static $pictures = 0;

    /** @var bool Whether an image already has high fetch priority. */
    private static $high_priority = false;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Image loading', 'seoprostack'),
                'description' => __('Choose which pictures load straight away and which wait until the visitor scrolls near them. Uses the browser’s own lazy loading that WordPress adds, so pages are not rewritten. Pictures that load straight away, including the logo, are also kept from cache plugins’ lazy loading, such as LiteSpeed Cache’s, so they do not flash as visitors move between pages.', 'seoprostack'),
                'replaces'    => array('nazy-load' => 'Flying Images'),
            ),
            'image_loading_eager' => array(
                'type'        => 'int',
                'default'     => 3,
                'min'         => 0,
                'max'         => 20,
                'unit'        => __('pictures', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Load straight away', 'seoprostack'),
                'description' => __('The first pictures on a post or page load without waiting, so what shows first is not delayed. A featured image counts as the first. WordPress’s default is 3; 0 lazy-loads them all.', 'seoprostack'),
            ),
            'image_loading_exclude' => array(
                'type'        => 'lines',
                'default'     => '',
                'rows'        => 4,
                'parent'      => self::KEY,
                'label'       => __('Never lazy-load pictures containing', 'seoprostack'),
                'description' => __('One per line: text in the picture’s address, class or other attributes, such as logo.png or a slider’s class. The site logo, and pictures with the skip-lazy or no-lazy class, or a data-skip-lazy attribute, always load straight away.', 'seoprostack'),
            ),
        );
    }

    /**
     * Import Flying Images' exclusions; switch on where it lazy-loads.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (false === get_option('FLYING_IMAGES_VERSION')) {
            return $options;
        }
        $keywords = get_option('flying_images_exclude_keywords');
        $keep     = array();
        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                $keyword = trim((string) $keyword);
                if ('' !== $keyword && self::FLYING_EXAMPLE !== $keyword) {
                    $keep[] = $keyword;
                }
            }
        }
        if (get_option('flying_images_enable_lazyloading')) {
            $options = self::import_setting($options, self::KEY, true);
        }
        return self::import_setting($options, 'image_loading_exclude', $keep ? implode("\n", array_unique($keep)) : null);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || (is_admin() && !wp_doing_ajax())) {
            return;
        }
        add_filter('wp_omit_loading_attr_threshold', array(__CLASS__, 'threshold'), 20);
        add_filter('wp_content_img_tag', array(__CLASS__, 'content_image'), 20, 2);
        add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'attachment_image'), 20);
        add_filter('wp_iframe_tag_add_loading_attr', array(__CLASS__, 'iframe'), 20, 2);
    }

    /**
     * How many content pictures load straight away.
     *
     * @return int
     */
    public static function threshold() {
        return max(0, (int) SEOProStack_Settings::get('image_loading_eager'));
    }

    /**
     * Text that keeps a picture or iframe from lazy loading.
     *
     * @return string[]
     */
    public static function keywords() {
        static $keywords = null;
        if (null === $keywords) {
            // custom-logo: core's logo class, kept by Kadence and most themes.
            $keywords = array('skip-lazy', 'no-lazy', 'data-skip-lazy', 'data-no-lazy', 'custom-logo');
            foreach (preg_split('/\r?\n/', (string) SEOProStack_Settings::get('image_loading_exclude'), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
                $line = trim($line);
                if ('' !== $line) {
                    $keywords[] = $line;
                }
            }
            /**
             * Filter the text that keeps a picture or iframe from lazy
             * loading: any of it in the tag (its address, class or other
             * attributes) loads it straight away.
             *
             * @param string[] $keywords Text to look for.
             */
            $keywords = array_values(array_filter(array_map('strval', (array) apply_filters('seoprostack_image_loading_exclude', $keywords)), function ($keyword) {
                return '' !== $keyword;
            }));
        }
        return $keywords;
    }

    /**
     * Whether a tag contains text from the list.
     *
     * @param string $tag HTML tag, or its attributes joined.
     * @return bool
     */
    public static function excluded($tag) {
        foreach (self::keywords() as $keyword) {
            if (false !== strpos($tag, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Process pictures in content order, after secondary-query blocks render.
     * Core's main-query heuristics do not make those block pictures eager.
     *
     * @param string $image   HTML img tag.
     * @param string $context Content filter context.
     * @return string
     */
    public static function content_image($image, $context = '') {
        global $wp_current_filter;

        if (!is_string($image)) {
            return $image;
        }
        // Query Loop Post Content blocks filter their images before the outer
        // content does. Leave those for the outer pass, in final output order.
        if ('the_content' === $context && count(array_keys((array) $wp_current_filter, 'the_content', true)) > 1) {
            return $image;
        }
        $tag = new WP_HTML_Tag_Processor($image);
        if (!$tag->next_tag(array('tag_name' => 'IMG'))) {
            return $image;
        }
        $priority = $tag->get_attribute('fetchpriority');
        if ('high' === $priority) {
            self::$high_priority = true;
        }
        $eager = false;
        if ('the_content' === $context && is_main_query() && in_the_loop() && !is_feed()) {
            ++self::$pictures;
            $eager = self::$pictures <= self::threshold();
        }
        $excluded = self::excluded($image);
        if (($eager || $excluded) && 'lazy' === $tag->get_attribute('loading')) {
            $tag->remove_attribute('loading');
        }
        // Match core's minimum area so an icon cannot take the LCP slot.
        $area = (int) $tag->get_attribute('width') * (int) $tag->get_attribute('height');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reuse core's existing image-area threshold, not a plugin-owned hook.
        $minimum = (int) apply_filters('wp_min_priority_img_pixels', 50000);
        // Respect explicit priorities and core's choice of an earlier LCP image.
        if ($eager && $area >= $minimum && null === $priority && !self::$high_priority
            && (!function_exists('wp_high_priority_element_flag') || wp_high_priority_element_flag())) {
            $tag->set_attribute('fetchpriority', 'high');
            $priority            = 'high';
            self::$high_priority = true;
            if (function_exists('wp_high_priority_element_flag')) {
                wp_high_priority_element_flag(false);
            }
        }
        if ($eager || $excluded || 'high' === $priority) {
            $tag->set_attribute(self::NO_LAZY, '1');
        }
        return $tag->get_updated_html();
    }

    /**
     * Pictures shown with wp_get_attachment_image(), such as featured
     * images and logos.
     *
     * @param array $attr Attributes.
     * @return array
     */
    public static function attachment_image($attr) {
        if (!is_array($attr)) {
            return $attr;
        }
        $high = isset($attr['fetchpriority']) && 'high' === $attr['fetchpriority'];
        if ($high) {
            self::$high_priority = true;
        }
        // A featured image before the content shares its eager-image budget.
        // Block attachments are counted later, in their final content order.
        if (is_main_query() && in_the_loop() && !doing_filter('the_content') && !doing_filter('get_the_excerpt') && !is_feed()) {
            ++self::$pictures;
        }
        $text = '';
        foreach ($attr as $name => $value) {
            $text .= ' ' . $name . '="' . (is_scalar($value) ? (string) $value : '') . '"';
        }
        $excluded = self::excluded($text);
        if ($excluded && isset($attr['loading']) && 'lazy' === $attr['loading']) {
            unset($attr['loading']);
        }
        // Logos and pictures that load first: keep page-rewriting lazy
        // loaders (LiteSpeed Cache, WP Rocket) off them too.
        if ($excluded || $high) {
            $attr[self::NO_LAZY] = '1';
        }
        return $attr;
    }

    /**
     * Content iframes.
     *
     * @param string|bool $value  The loading value.
     * @param string      $iframe HTML iframe tag.
     * @return string|bool
     */
    public static function iframe($value, $iframe) {
        return is_string($iframe) && self::excluded($iframe) ? false : $value;
    }
}
