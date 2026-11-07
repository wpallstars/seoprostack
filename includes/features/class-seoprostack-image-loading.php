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
 * When a post or page opens with a Kadence Blocks row with a background
 * picture, the picture is preloaded at high priority from the head
 * (GH#1204), as browsers otherwise find backgrounds only after the CSS;
 * at the size the screen needs when it has smaller copies (GH#1221). So is
 * the first slide's picture of a Kadence Blocks Pro Advanced Slider it
 * opens with, and each slider's other slides fetch theirs only as they come
 * up (SEOProStack_Kadence_Slider, GH#1312), each at the size the screen
 * needs when it has smaller copies (GH#1333). Where LiteSpeed Cache's WebP
 * replacement names its copy in the block's CSS, the preload names it too
 * (GH#1326). Rows further down fetch their background pictures as the
 * visitor scrolls near them (SEOProStack_Kadence_Rows, GH#1329), and a row
 * background slider's first slide shows at its full width before the
 * slider's script runs (GH#1339).
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

    /** @var string[] The picture preloaded without a copy of SEO Pro Stack's, and its tag (GH#1326). */
    private static $preloaded = array();

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
                'description' => __('Choose which pictures load straight away and which wait until the visitor scrolls near them. Uses the browser’s own lazy loading that WordPress adds, so pages are not rewritten. Pictures that load straight away, including the logo, are also kept from cache plugins’ lazy loading, such as LiteSpeed Cache’s, so they do not flash as visitors move between pages. When a page opens with a Kadence Blocks row with a background picture or slider, that picture is fetched first, at the size the screen needs.', 'seoprostack'),
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
                'description' => __('The first pictures on a post or page load without waiting, so what shows first is not delayed. A featured image, or the background picture of a Kadence Blocks row the page opens with, counts as the first. WordPress’s default is 3; 0 lazy-loads them all.', 'seoprostack'),
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
        if (defined('KADENCE_BLOCKS_VERSION')) {
            // First in the head, before other plugins' inline styles and
            // scripts (on a test page about 40 KB of them at 1 and earlier).
            add_action('wp_head', array(__CLASS__, 'kadence_preload'), -10);
            if (!is_admin()) {
                // Rows further down: their background pictures wait until
                // the visitor scrolls near them (GH#1329).
                require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-kadence-rows.php';
                SEOProStack_Kadence_Rows::boot();
            }
        }
        if (defined('KBP_VERSION') && !is_admin()) {
            // Advanced Slider slides: only the slides shown, and the next,
            // fetch their pictures (GH#1312).
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-kadence-slider.php';
            SEOProStack_Kadence_Slider::boot();
        }
    }

    /**
     * When a post or page opens with a Kadence Blocks row that has a
     * background picture (or a background slider, whose first slide shows
     * first), fetch the picture with the page at high priority (GH#1204).
     * Browsers otherwise find a background only once the CSS is in, so on
     * a hero row it was often the page's slowest paint (LCP). The AVIF or
     * WebP copy when pages name copies, as the row's CSS then does. When the
     * picture has smaller copies of the same proportions, the browser
     * measures the row and fetches the smallest that is enough instead,
     * never less sharp (SEOProStack_Kadence_Hero, GH#1221).
     *
     * A Kadence Blocks Pro Advanced Slider the page opens with: its first
     * slide's picture, which shows first (GH#1312); when it has smaller
     * copies, the script after the slider preloads the one the screen needs
     * instead (SEOProStack_Kadence_Slider::opens(), GH#1333).
     *
     * When a full-width row or slider sets its height for the screen size,
     * a script here, before the stylesheets, fetches the copy it needs at
     * least, so the picture does not wait for the parser to reach the block
     * (SEOProStack_Kadence_Early, GH#1337).
     *
     * Left alone, so no device fetches a picture it does not show: rows
     * hidden on a device, with their own tablet or phone background, a
     * parallax or dynamic background, or Kadence's old row markup; rows
     * and sliders hidden by core's Block visibility or shown on a Kadence
     * condition. Also left alone when the theme shows the featured image,
     * which comes before the content: core keeps its high priority for
     * that picture.
     *
     * The head comes before the rest of the page, so a header picture with
     * fetchpriority="high" in its own markup cannot be seen from here, and
     * keeps it. Core no longer adds high priority to one, such as a large
     * logo.
     */
    public static function kadence_preload() {
        if (!is_singular() || is_feed() || is_embed()) {
            return;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post || post_password_required($post) || self::feature_shown($post)) {
            return;
        }
        // Cheap check first: the content must open with a row or a slider.
        $content = ltrim($post->post_content);
        $slider  = 0 === strpos($content, '<!-- wp:kadence/slider ');
        if (!$slider && 0 !== strpos($content, '<!-- wp:kadence/rowlayout ')) {
            return;
        }
        $blocks = parse_blocks($content);
        if ($slider) {
            $url = isset($blocks[0]) ? self::kadence_first_slide($blocks[0]) : '';
            if ('' === $url) {
                return;
            }
            // The size each screen needs, when the picture has smaller
            // copies: the script after the slider preloads it, so this one
            // is only for browsers without JavaScript, or goes before the
            // slider if it is not sized after all (GH#1333).
            $tag   = self::preload_html($url);
            $sized = class_exists('SEOProStack_Kadence_Slider') && SEOProStack_Kadence_Slider::opens($blocks[0], $tag);
            echo ($sized ? '<noscript>' . $tag . '</noscript>' : $tag) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in preload_tag().
            // With JavaScript, the copy this screen needs at least, from
            // here, when the slider sets its height (GH#1337).
            if ($sized) {
                echo SEOProStack_Kadence_Slider::early($blocks[0]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() of a JSON-encoded script.
            }
        } else {
            $url = isset($blocks[0]) ? self::kadence_background($blocks[0]) : '';
            if ('' === $url) {
                return;
            }
            // The size each screen needs, when the picture has smaller
            // copies: the browser measures the row and preloads it (GH#1221).
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-kadence-hero.php';
            if (!SEOProStack_Kadence_Hero::start($blocks[0]['attrs'], $post)) {
                self::preload($url);
            }
        }
        // One picture at high priority: core would otherwise give it to the
        // first large picture it meets, often the header's logo.
        self::$high_priority = true;
        // The row is the top of the page: its picture is the first of those
        // that load straight away, as a featured image is, so one fewer
        // picture below it competes with it.
        ++self::$pictures;
        if (function_exists('wp_high_priority_element_flag')) {
            wp_high_priority_element_flag(false);
        }
    }

    /**
     * Preload a background picture at high priority: its AVIF or WebP copy
     * when pages name copies, as the block's CSS then does. Otherwise,
     * LiteSpeed Cache's WebP replacement may name its own copy in the
     * block's CSS at the end of the page, so the preload follows it there
     * (litespeed_next_gen()).
     *
     * @param string $url Picture address.
     */
    private static function preload($url) {
        echo self::preload_html($url) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in preload_tag().
    }

    /**
     * The tag preload() prints, for a caller that prints it itself; every
     * copy of it in the page follows LiteSpeed Cache's WebP copy too.
     *
     * @param string $url Picture address.
     * @return string
     */
    private static function preload_html($url) {
        $type = '';
        if (class_exists('SEOProStack_Nextgen_Images')) {
            list($url, $type) = SEOProStack_Nextgen_Images::first_copy($url);
        }
        $tag = self::preload_tag($url, $type);
        if ('' === $type && defined('LSCWP_V')) {
            self::$preloaded = array($url, $tag);
            add_filter('litespeed_buffer_after', array(__CLASS__, 'litespeed_next_gen'), 5);
        }
        return $tag;
    }

    /**
     * A preload tag for a picture at high priority.
     *
     * @param string $url  Picture address.
     * @param string $type Its type, or '' for none.
     * @return string
     */
    private static function preload_tag($url, $type) {
        return sprintf(
            '<link rel="preload" as="image" href="%s"%s fetchpriority="high">',
            esc_url($url),
            '' !== $type ? ' type="' . esc_attr($type) . '"' : ''
        );
    }

    /**
     * LiteSpeed Cache's WebP replacement rewrites every CSS url() whose
     * picture has a .webp or .avif file beside it, for browsers that get
     * its WebP copy of the page, but not a preload's address: the browser
     * then fetched the picture twice, and the copy the block's CSS names,
     * the one it shows, only after the CSS (GH#1326). When the page names
     * the preloaded picture's copy, as LiteSpeed rewrote it on this request,
     * the preload names it too: in the head, in its <noscript> and before
     * an Advanced Slider that was not sized after all (GH#1333). Pages it
     * left alone (logged in, not cached, a browser without WebP) keep the
     * picture's own address.
     *
     * @param mixed $buffer The page, after LiteSpeed Cache's changes.
     * @return mixed
     */
    public static function litespeed_next_gen($buffer) {
        if (!is_string($buffer) || 2 !== count(self::$preloaded)) {
            return $buffer;
        }
        list($url, $tag) = self::$preloaded;
        self::$preloaded = array();
        $end = strcspn($url, '?#');
        foreach (array('avif', 'webp') as $format) {
            // As LiteSpeed Cache's Media::replace_webp() names the copy.
            $copy = substr($url, 0, $end) . '.' . $format . substr($url, $end);
            if (false !== strpos($buffer, $copy)) {
                return str_replace($tag, self::preload_tag($copy, ''), $buffer);
            }
        }
        return $buffer;
    }

    /**
     * The picture of a Kadence Blocks Pro Advanced Slider's first slide,
     * which shows first on every device, or ''. Kadence Blocks Pro writes
     * it into the slide's CSS, on its normal background only; a gradient
     * there replaces it.
     *
     * @param array $block Parsed block.
     * @return string
     */
    private static function kadence_first_slide(array $block) {
        if (!defined('KBP_VERSION') || empty($block['blockName']) || 'kadence/slider' !== $block['blockName']
            || !self::shown_always(isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array())) {
            return '';
        }
        $slide = isset($block['innerBlocks'][0]) && is_array($block['innerBlocks'][0]) ? $block['innerBlocks'][0] : array();
        $attrs = isset($slide['attrs']) && is_array($slide['attrs']) ? $slide['attrs'] : array();
        if (empty($slide['blockName']) || 'kadence/slide' !== $slide['blockName'] || !self::shown_always($attrs)
            || !in_array($attrs['backgroundType'] ?? 'normal', array('', 'normal'), true) || !empty($attrs['backgroundGradient'])) {
            return '';
        }
        $url = $attrs['backgroundImg'][0]['img'] ?? '';
        return is_string($url) && preg_match('#^(?:https?:)?//#i', $url) ? $url : '';
    }

    /**
     * Whether a block shows for everyone: not hidden by core's Block
     * visibility (WordPress 6.9: false, or a list of screen sizes) and
     * not shown on a Kadence condition.
     *
     * @param array $attrs Block attributes.
     * @return bool
     */
    private static function shown_always(array $attrs) {
        if (isset($attrs['metadata']['blockVisibility']) && true !== $attrs['metadata']['blockVisibility']) {
            return false;
        }
        foreach ((array) ($attrs['kadenceConditional'] ?? array()) as $condition) {
            if (is_array($condition) && !empty($condition['enable'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the theme shows the post's featured image (above, behind or
     * below the title, all before the content). With the Kadence theme its
     * setting for the post decides (pages default to hiding it, posts to
     * showing it); other themes are taken to show it.
     *
     * @param WP_Post $post Post.
     * @return bool
     */
    private static function feature_shown(WP_Post $post) {
        if (!has_post_thumbnail($post)) {
            return false;
        }
        if ('kadence' === get_template() && function_exists('Kadence\kadence')) {
            return (bool) \Kadence\kadence()->show_feature();
        }
        return true;
    }

    /**
     * The background picture a Kadence Blocks row shows on every device
     * from the start, or ''.
     *
     * @param array $block Parsed block.
     * @return string
     */
    private static function kadence_background(array $block) {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        if (!self::kadence_row_everywhere($block, $attrs)) {
            return '';
        }
        $url = self::kadence_row_picture($attrs);
        return is_string($url) && preg_match('#^(?:https?:)?//#i', $url) ? $url : '';
    }

    /**
     * Whether a block is a Kadence Blocks row (kbVersion 2 or later) shown,
     * with the same background, on every device and to everyone: not
     * hidden on one, by Block visibility or on a condition, with no tablet
     * or phone background of its own and no dynamic background.
     *
     * @param array $block Parsed block.
     * @param array $attrs Its attributes.
     * @return bool
     */
    private static function kadence_row_everywhere(array $block, array $attrs) {
        if (empty($block['blockName']) || 'kadence/rowlayout' !== $block['blockName'] || empty($attrs['kbVersion']) || (int) $attrs['kbVersion'] < 2) {
            return false;
        }
        return self::shown_always($attrs) && empty($attrs['vsdesk']) && empty($attrs['vstablet']) && empty($attrs['vsmobile'])
            && empty($attrs['tabletBackground'][0]['enable']) && empty($attrs['mobileBackground'][0]['enable'])
            && empty($attrs['kadenceDynamic']['bgImg']['enable']);
    }

    /**
     * A Kadence Blocks row's first background picture: its first slide's,
     * or its normal tab's unless that is parallax. '' when none.
     *
     * @param array $attrs Row attributes.
     * @return mixed
     */
    private static function kadence_row_picture(array $attrs) {
        $tab = isset($attrs['backgroundSettingTab']) ? $attrs['backgroundSettingTab'] : 'normal';
        if ('slider' === $tab) {
            // Kadence's default is one slide.
            $slides = isset($attrs['backgroundSliderCount']) ? (int) $attrs['backgroundSliderCount'] : 1;
            return $slides >= 1 && isset($attrs['backgroundSlider'][0]['bgImg']) ? $attrs['backgroundSlider'][0]['bgImg'] : '';
        }
        if ('normal' === $tab && (!isset($attrs['bgImgAttachment']) || 'parallax' !== $attrs['bgImgAttachment'])) {
            return isset($attrs['bgImg']) ? $attrs['bgImg'] : '';
        }
        return '';
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
