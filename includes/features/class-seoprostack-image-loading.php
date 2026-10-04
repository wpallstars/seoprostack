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
 *   a slider that lazy loading breaks: the skip-lazy and no-lazy classes,
 *   a data-skip-lazy or data-no-lazy attribute, loading="eager", and any
 *   text from a list (`wp_content_img_tag`, `wp_get_attachment_image_attributes`,
 *   `wp_iframe_tag_add_loading_attr`).
 *
 * Replaces Flying Images' lazy loading, in part: its exclusions are imported
 * once. Its CDN, compression, WebP and responsive images are not replaced
 * (WebP and AVIF images and core's srcset cover the last two), nor its
 * JavaScript lazy loading of background pictures, which rewrites every page.
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
                'label'       => __('Image loading', 'seoprostack'),
                'description' => __('Choose which pictures load straight away and which wait until the visitor scrolls near them. Uses the browser’s own lazy loading that WordPress adds, so pages are not rewritten.', 'seoprostack'),
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
                'description' => __('One per line: text in the picture’s address, class or other attributes, such as logo.png or a slider’s class. Pictures with the skip-lazy or no-lazy class, or a data-skip-lazy attribute, always load straight away.', 'seoprostack'),
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
        add_filter('wp_content_img_tag', array(__CLASS__, 'content_image'), 20);
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
            $keywords = array('skip-lazy', 'no-lazy', 'data-skip-lazy', 'data-no-lazy');
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
     * Content pictures: drop loading="lazy" from excluded ones, whether core
     * or the saved block added it.
     *
     * @param string $image HTML img tag.
     * @return string
     */
    public static function content_image($image) {
        if (!is_string($image) || false === strpos($image, 'loading=') || !self::excluded($image)) {
            return $image;
        }
        return (string) preg_replace('/\sloading=(["\'])lazy\1/i', '', $image);
    }

    /**
     * Pictures shown with wp_get_attachment_image(), such as featured
     * images and logos.
     *
     * @param array $attr Attributes.
     * @return array
     */
    public static function attachment_image($attr) {
        if (!is_array($attr) || !isset($attr['loading']) || 'lazy' !== $attr['loading']) {
            return $attr;
        }
        $text = '';
        foreach ($attr as $name => $value) {
            $text .= ' ' . $name . '="' . (is_scalar($value) ? (string) $value : '') . '"';
        }
        if (self::excluded($text)) {
            unset($attr['loading']);
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
