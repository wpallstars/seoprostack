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
 * A picture whose block names another attachment than the file it shows,
 * as an imported template's often does, gets what core gives every other
 * picture from the attachment that owns the file: width and height, its
 * smaller copies (srcset and sizes) and the loading attributes (GH#1387).
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

    /** Blocks a short row of text before the hero row may hold (GH#1402). */
    const BAND_BLOCKS = array(
        'kadence/column',
        'kadence/advancedheading',
        'kadence/advancedbtn',
        'kadence/singlebtn',
        'core/heading',
        'core/paragraph',
        'core/list',
        'core/list-item',
        'core/buttons',
        'core/button',
    );

    /** Most characters of text that row may hold (GH#1402). */
    const BAND_TEXT = 300;

    /** In a block's JSON: a media file, a picture attribute with a value, or dynamic content. */
    const BAND_MEDIA = '/\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico|mp4|m4v|webm|ogv|mov)\b|"(?:bgImg|overlayBgImg|img|src)":"[^"]|"kadenceDynamic":/i';

    /** Temporary attribute on pictures in the opening block; content_image() removes it (GH#1412). */
    const OPENING = 'data-seoprostack-opening';

    /** @var array<int,array|null> The block each post's pictures open with (opening_block()). */
    private static $opening = array();

    /** @var array<int,array> The first two blocks of each post (leading()). */
    private static $leading = array();

    /** @var array<int,array<int,bool>> The leading blocks already rendered this request, by post and position. */
    private static $matched = array();

    /** @var bool Whether the main post's blocks were marked this request, so position decides which pictures are eager (GH#1412). */
    private static $marking = false;

    /** @var bool Whether a picture of the opening block, or the first after it, was met (GH#1412). */
    private static $opened = false;

    /** @var bool Whether the opening block's background picture is preloaded: it is the LCP (GH#1412). */
    private static $hero = false;

    /** @var bool Whether core gave the picture it just processed high fetch priority itself (GH#1412). */
    private static $core_high = false;

    /** @var int Pictures encountered in the main loop's output this request. */
    private static $pictures = 0;

    /** @var bool Whether an image already has high fetch priority. */
    private static $high_priority = false;

    /** @var string[] The picture preloaded without a copy of SEO Pro Stack's, and its tag (GH#1326). */
    private static $preloaded = array();

    /** @var int[] The attachment that owns each uploads file looked up this request, or 0 (GH#1387). */
    private static $owners = array();

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
        // Before Media storage (10) maps the picture by its attachment, and
        // before content_image() measures it.
        add_filter('wp_content_img_tag', array(__CLASS__, 'own_sizes'), 5, 3);
        add_filter('wp_content_img_tag', array(__CLASS__, 'content_image'), 20, 2);
        // Pictures in the block the page opens with, so only they load
        // straight away (GH#1412).
        add_filter('render_block', array(__CLASS__, 'mark_opening'), 20, 2);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook.
        add_filter('wp_get_loading_optimization_attributes', array(__CLASS__, 'core_priority'), 20, 4);
        add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'attachment_image'), 20);
        add_filter('wp_iframe_tag_add_loading_attr', array(__CLASS__, 'iframe'), 20, 2);
        // Missing copies of row and slide pictures, made in the background (GH#1406).
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-picture-sizes.php';
        SEOProStack_Picture_Sizes::boot();
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
     * A short row of text alone before the hero row, such as a band with a
     * heading on a colour or gradient, is passed over: the row after it is
     * the one the page opens with (opening_block(), GH#1402).
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
        $block = self::opening_block($post);
        if (null === $block) {
            return;
        }
        if ($slider) {
            $url = self::kadence_first_slide($block);
            if ('' === $url) {
                return;
            }
            // The size each screen needs, when the picture has smaller
            // copies: the script after the slider preloads it, so this one
            // is only for browsers without JavaScript, or goes before the
            // slider if it is not sized after all (GH#1333).
            $tag   = self::preload_html($url);
            $sized = class_exists('SEOProStack_Kadence_Slider') && SEOProStack_Kadence_Slider::opens($block, $tag);
            echo ($sized ? '<noscript>' . $tag . '</noscript>' : $tag) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in preload_tag().
            // With JavaScript, the copy this screen needs at least, from
            // here, when the slider sets its height (GH#1337).
            if ($sized) {
                echo SEOProStack_Kadence_Slider::early($block); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() of a JSON-encoded script.
            }
        } else {
            $url = self::kadence_background($block);
            if ('' === $url) {
                return;
            }
            // The size each screen needs, when the picture has smaller
            // copies: the browser measures the row and preloads it (GH#1221).
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-kadence-hero.php';
            if (!SEOProStack_Kadence_Hero::start($block['attrs'], $post)) {
                self::preload($url);
            }
        }
        // One picture at high priority: core would otherwise give it to the
        // first large picture it meets, often the header's logo.
        self::$high_priority = true;
        // Its picture is the LCP: pictures outside the opening block stay lazy (GH#1412).
        self::$hero = true;
        // The row is the top of the page: its picture is the first of those
        // that load straight away, as a featured image is, so one fewer
        // picture below it competes with it.
        ++self::$pictures;
        if (function_exists('wp_high_priority_element_flag')) {
            wp_high_priority_element_flag(false);
        }
    }

    /**
     * The block a post's pictures open with: its first block, or, when that
     * is a short Kadence Blocks row of text alone (band()) and the next is a
     * Kadence Blocks row, the next (GH#1402). On one Kadence home page a
     * heading band on a gradient came first, so the hero row's picture
     * below it, the page's largest paint, was neither preloaded nor sized.
     * Null when the post has no blocks. Once a request per post; also used
     * by SEOProStack_Kadence_Rows, which leaves this row alone.
     *
     * @param WP_Post $post Post.
     * @return array|null Parsed block.
     */
    public static function opening_block(WP_Post $post) {
        if (array_key_exists($post->ID, self::$opening)) {
            return self::$opening[$post->ID];
        }
        $blocks = self::leading($post);
        $first  = $blocks[0] ?? null;
        if (isset($blocks[1]) && 'kadence/rowlayout' === ($blocks[1]['blockName'] ?? '') && is_array($first) && self::band($first)) {
            $first = $blocks[1];
        }
        self::$opening[$post->ID] = $first;
        return $first;
    }

    /**
     * A post's first two blocks, without the white space between blocks.
     *
     * @param WP_Post $post Post.
     * @return array[] Parsed blocks.
     */
    private static function leading(WP_Post $post) {
        if (isset(self::$leading[$post->ID])) {
            return self::$leading[$post->ID];
        }
        $blocks = array();
        foreach (parse_blocks(ltrim($post->post_content)) as $block) {
            // Leave out the white space between blocks.
            if (!empty($block['blockName']) || '' !== trim((string) ($block['innerHTML'] ?? ''))) {
                $blocks[] = $block;
                if (2 === count($blocks)) {
                    break;
                }
            }
        }
        self::$leading[$post->ID] = $blocks;
        return $blocks;
    }

    /**
     * Mark the pictures of the block the page opens with, and of the block
     * after it when the first has no picture (GH#1412). The eager budget
     * went to the first pictures in content order, which on a Kadence page
     * that opens with a background hero are often in a posts grid, portfolio
     * or gallery hundreds of pixels below the first screen. content_image()
     * reads and removes the mark: pictures without it stay lazy.
     *
     * @param string $content Rendered block.
     * @param array  $block   Parsed block.
     * @return string
     */
    public static function mark_opening($content, $block) {
        global $wp_current_filter;

        if (!is_string($content) || !is_array($block) || !is_singular() || !is_main_query() || !in_the_loop() || is_feed()
            || count(array_keys((array) $wp_current_filter, 'the_content', true)) !== 1) {
            return $content;
        }
        $post = get_post();
        if (!$post instanceof WP_Post || get_queried_object_id() !== (int) $post->ID) {
            return $content;
        }
        self::$marking = true;
        // Each leading block takes its place when it renders, with or without
        // a picture: a hero row whose picture is a CSS background, and a
        // heading row after it, have no <img>, and a later row of the same
        // shape (Kadence rows save no HTML of their own) would otherwise take
        // their place and its pictures load straight away (GH#1420).
        $kind = '';
        foreach (self::leading($post) as $position => $lead) {
            if (!empty(self::$matched[$post->ID][$position]) || !self::same_block($block, $lead)) {
                continue;
            }
            self::$matched[$post->ID][$position] = true;
            $kind = self::opening_block($post) === $lead ? 'opening' : 'near';
            break;
        }
        if ('' === $kind || false === stripos($content, '<img')) {
            return $content;
        }
        $tags = new WP_HTML_Tag_Processor($content);
        while ($tags->next_tag(array('tag_name' => 'IMG'))) {
            $tags->set_attribute(self::OPENING, $kind);
        }
        return $tags->get_updated_html();
    }

    /**
     * Whether a block being rendered is a given leading block: the same name,
     * saved HTML and number of inner blocks, and the same uniqueID when both
     * have one (Kadence Blocks gives each block its own).
     *
     * @param array $block Block being rendered.
     * @param array $lead  Leading block (leading()).
     * @return bool
     */
    private static function same_block(array $block, array $lead) {
        if (($block['blockName'] ?? null) !== ($lead['blockName'] ?? null)
            || ($block['innerContent'] ?? null) !== ($lead['innerContent'] ?? null)
            || count((array) ($block['innerBlocks'] ?? array())) !== count((array) ($lead['innerBlocks'] ?? array()))) {
            return false;
        }
        $id   = $block['attrs']['uniqueID'] ?? null;
        $lead = $lead['attrs']['uniqueID'] ?? null;
        return !is_string($id) || !is_string($lead) || $id === $lead;
    }

    /**
     * Note when core, not the author, gave a content picture high fetch
     * priority, so content_image() can take it off one that is not near the
     * top (GH#1412).
     *
     * Core gives high priority to the first large picture once the header
     * has started, and a logo whose file is large (an SVG that says
     * 1000×781, a PNG uploaded at 680×250) took it, though it shows about
     * 50 px high: the page's main picture then had none, and Lighthouse's
     * LCP request discovery failed on it (GH#1485). The logo keeps loading
     * straight away; core's high priority goes back for the first large
     * picture after it.
     *
     * @param array  $loading_attrs Attributes core chose.
     * @param string $tag_name      Tag name.
     * @param array  $attr          The tag's own attributes.
     * @param string $context       Context.
     * @return array
     */
    public static function core_priority($loading_attrs, $tag_name = '', $attr = array(), $context = '') {
        if ('img' !== $tag_name || !is_array($loading_attrs)) {
            return $loading_attrs;
        }
        $core_high = isset($loading_attrs['fetchpriority']) && 'high' === $loading_attrs['fetchpriority']
            && (!is_array($attr) || empty($attr['fetchpriority']));
        if ('the_content' === $context) {
            self::$core_high = $core_high;
        } elseif ($core_high && is_array($attr) && isset($attr['class']) && is_string($attr['class'])
            && preg_match('/(?:^|\s)custom-logo(?:\s|$)/', $attr['class'])) {
            unset($loading_attrs['fetchpriority']);
            if (function_exists('wp_high_priority_element_flag')) {
                wp_high_priority_element_flag(true);
            }
        }
        return $loading_attrs;
    }

    /**
     * Whether a block is a short Kadence Blocks row of text alone, such as a
     * heading band on a colour or gradient (GH#1402): a normal, gradient or
     * slider background tab, no minimum height, nothing in it naming a
     * picture, video or dynamic content, only columns, headings,
     * paragraphs, lists and buttons, and at most BAND_TEXT characters of
     * text. It is short enough that the row after it starts on the first
     * screen.
     *
     * @param array $block Parsed block.
     * @return bool
     */
    private static function band(array $block) {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        if ('kadence/rowlayout' !== ($block['blockName'] ?? '')
            || !in_array($attrs['backgroundSettingTab'] ?? 'normal', array('', 'normal', 'gradient', 'slider'), true)
            || !empty($attrs['minHeight']) || !empty($attrs['minHeightTablet']) || !empty($attrs['minHeightMobile'])
            || preg_match(self::BAND_MEDIA, (string) wp_json_encode($block, JSON_UNESCAPED_SLASHES))) {
            return false;
        }
        $text = self::band_text(isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : array());
        return $text >= 0 && $text <= self::BAND_TEXT;
    }

    /**
     * Characters of text in blocks a band may hold, or -1 when one is
     * another kind.
     *
     * @param array $blocks Parsed blocks.
     * @return int
     */
    private static function band_text(array $blocks) {
        $text = 0;
        foreach ($blocks as $block) {
            if (!is_array($block) || !in_array($block['blockName'] ?? '', self::BAND_BLOCKS, true)) {
                return -1;
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $inner = self::band_text($block['innerBlocks']);
                if ($inner < 0) {
                    return -1;
                }
                $text += $inner;
            } else {
                $text += strlen(trim(wp_strip_all_tags((string) ($block['innerHTML'] ?? ''))));
            }
        }
        return $text;
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
     * A content picture whose wp-image-N class names an attachment that does
     * not own its file, as a block from an imported template keeps the
     * template's ID (GH#1387). Core found no sizes for it, so it added no
     * width, height, srcset or sizes, and every visitor got the full-size
     * file; nor loading attributes, as core leaves a picture without width
     * and height alone. Core's own steps run again here, in its order, with
     * the attachment that owns the file. Pictures whose attachment owns the
     * file (core has decided), SVGs and pictures outside the uploads folder
     * are left alone.
     *
     * @param string $image   HTML img tag.
     * @param string $context Content filter context.
     * @param int    $id      The attachment core found from the class, or 0.
     * @return string
     */
    public static function own_sizes($image, $context = '', $id = 0) {
        global $wp_current_filter;

        $named = (int) $id;
        if (!is_string($image) || $named <= 0 || false !== strpos($image, ' srcset=')) {
            return $image;
        }
        // As content_image(): nested Post Content blocks are left to the outer pass.
        if ('the_content' === $context && count(array_keys((array) $wp_current_filter, 'the_content', true)) > 1) {
            return $image;
        }
        $tag = new WP_HTML_Tag_Processor($image);
        if (!$tag->next_tag(array('tag_name' => 'IMG'))) {
            return $image;
        }
        $file = self::uploads_file($tag->get_attribute('src'));
        if ('' === $file || self::owns($named, $file)) {
            return $image;
        }
        $owner = self::owner($file);
        if (!$owner || $owner === $named) {
            return $image;
        }
        $unsized = false === strpos($image, ' width=') && false === strpos($image, ' height=');
        if ($unsized) {
            $image = wp_img_tag_add_width_and_height_attr($image, $context, $owner);
        }
        $image = wp_img_tag_add_srcset_and_sizes_attr($image, $context, $owner);
        // Core counts and marks a picture only once it has width and height.
        if ($unsized && false !== strpos($image, ' width=') && false !== strpos($image, ' height=')
            && function_exists('wp_img_tag_add_loading_optimization_attrs')) {
            $image = wp_img_tag_add_loading_optimization_attrs($image, $context);
        }
        if (function_exists('wp_img_tag_add_auto_sizes')) {
            $image = wp_img_tag_add_auto_sizes($image);
        }
        // Media storage serves a picture by the attachment core found.
        if (class_exists('SEOProStack_Media_Storage')
            && false !== has_filter('wp_content_img_tag', array('SEOProStack_Media_Storage', 'content_image'))) {
            $image = SEOProStack_Media_Storage::content_image($image, $context, $owner);
        }
        return $image;
    }

    /**
     * A picture's file relative to this site's uploads folder, as attachments
     * store it, or '' when it is elsewhere or an SVG.
     *
     * @param mixed $src The picture's address.
     * @return string
     */
    private static function uploads_file($src) {
        if (!is_string($src) || '' === $src) {
            return '';
        }
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['baseurl'])) {
            return '';
        }
        // Either scheme, as attachment_url_to_postid() takes it.
        $base = (string) preg_replace('#^https?:#i', '', trailingslashit($uploads['baseurl']));
        $url  = (string) preg_replace('#^https?:#i', '', substr($src, 0, strcspn($src, '?#')));
        if (0 !== strpos($url, $base)) {
            return '';
        }
        $file = rawurldecode(substr($url, strlen($base)));
        return '' !== $file && false === strpos($file, '..') && !preg_match('/\.svgz?$/i', $file) ? $file : '';
    }

    /**
     * Whether an attachment's file, its original or one of its sizes is
     * this uploads file.
     *
     * @param int    $id   Attachment ID.
     * @param string $file File relative to the uploads folder.
     * @return bool
     */
    private static function owns($id, $file) {
        $meta = $id > 0 ? wp_get_attachment_metadata($id) : false;
        if (!is_array($meta) || empty($meta['file']) || !is_string($meta['file']) || dirname($meta['file']) !== dirname($file)) {
            return false;
        }
        $names = array(wp_basename($meta['file']));
        if (!empty($meta['original_image']) && is_string($meta['original_image'])) {
            $names[] = $meta['original_image'];
        }
        foreach (isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array() as $size) {
            if (is_array($size) && !empty($size['file'])) {
                $names[] = (string) $size['file'];
            }
        }
        return in_array(wp_basename($file), $names, true);
    }

    /**
     * The picture attachment that owns an uploads file, or 0: found by its
     * address as core's attachment_url_to_postid() finds it, also without a
     * -WxH size suffix, and with -scaled added (the file WordPress keeps for
     * a large upload). Once per file a request.
     *
     * @param string $file File relative to the uploads folder.
     * @return int
     */
    private static function owner($file) {
        if (isset(self::$owners[$file])) {
            return self::$owners[$file];
        }
        $uploads  = wp_get_upload_dir();
        $original = (string) preg_replace('/-[0-9]+x[0-9]+(\.[A-Za-z0-9]+)$/', '$1', $file);
        $scaled   = (string) preg_replace('/(?<!-scaled)(\.[A-Za-z0-9]+)$/', '-scaled$1', $original);
        $owner    = 0;
        foreach (array_unique(array($file, $original, $scaled)) as $candidate) {
            $found = (int) attachment_url_to_postid(trailingslashit($uploads['baseurl']) . $candidate);
            if ($found > 0 && wp_attachment_is_image($found) && self::owns($found, $file)) {
                $owner = $found;
                break;
            }
        }
        self::$owners[$file] = $owner;
        return $owner;
    }

    /**
     * Process pictures in content order, after secondary-query blocks render.
     * Core's main-query heuristics do not make those block pictures eager.
     *
     * A picture that waits gets loading="lazy" here when core left it
     * without: core's first pictures, whatever their position (GH#1412), and
     * every picture when a cache plugin's own lazy load turns WordPress's off
     * (LiteSpeed Cache's does). Its sizes then gets "auto", as core gives the
     * pictures it lazy-loads itself, so the browser fetches the copy for the
     * width the picture is shown at instead of the 100vw guess (GH#1485).
     * LiteSpeed Cache's lazy loader copies sizes and keeps loading="lazy",
     * so "auto" works through it.
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
        $mark = $tag->get_attribute(self::OPENING);
        if (null !== $mark) {
            $tag->remove_attribute(self::OPENING);
            $image = $tag->get_updated_html();
        }
        $core_high       = self::$core_high;
        self::$core_high = false;
        $eager           = false;
        $deep            = false;
        $counted         = false;
        // As core does, a picture with fetchpriority="auto" (block visibility:
        // it may be hidden at this screen size) takes no place in the budget.
        $counts = 'auto' !== $tag->get_attribute('fetchpriority');
        if ('the_content' === $context && is_main_query() && in_the_loop() && !is_feed()) {
            $counted = true;
            if (!self::$marking) {
                $eager = self::in_budget($counts);
            } elseif ('opening' === $mark) {
                // The block the page opens with: the budget is for these (GH#1412).
                self::$opened = true;
                $eager        = self::in_budget($counts);
            } elseif ('near' === $mark && !self::$opened) {
                // Nothing in the opening block: without a preloaded hero, the
                // first picture in the next block may be the LCP.
                self::$opened = true;
                if (self::$hero) {
                    $deep = true;
                } else {
                    $eager = self::in_budget($counts);
                }
            } else {
                $deep = true;
            }
        }
        $excluded = self::excluded($image);
        // Core, not the author, gave a picture below the opening block high priority.
        if ($deep && !$excluded && $core_high) {
            $tag->remove_attribute('fetchpriority');
        }
        $priority = $tag->get_attribute('fetchpriority');
        if ('high' === $priority) {
            self::$high_priority = true;
        }
        $lazied = false;
        if (($deep || ($counted && !$eager)) && !$excluded && !in_array($priority, array('high', 'low'), true)
            && null === $tag->get_attribute('loading')) {
            // Core leaves its first pictures without loading="lazy" whatever
            // their position, and every picture when a cache plugin turned
            // WordPress's lazy loading off. Never fetchpriority="low", as in
            // core: a later carousel slide or a menu overlay's picture, which
            // the browser would not start loading before it is shown.
            $tag->set_attribute('loading', 'lazy');
            $lazied = true;
        }
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
        $image = $tag->get_updated_html();
        // As core does for the pictures it lazy-loads itself (GH#1485).
        if ($lazied && function_exists('wp_img_tag_add_auto_sizes')) {
            $image = wp_img_tag_add_auto_sizes($image);
        }
        return $image;
    }

    /**
     * Whether the next content picture is within the eager budget; it takes
     * a place in the budget when it counts.
     *
     * @param bool $counts False for pictures core leaves out of its count.
     * @return bool
     */
    private static function in_budget($counts) {
        $eager = self::$pictures < self::threshold();
        if ($counts) {
            ++self::$pictures;
        }
        return $eager;
    }

    /**
     * Pictures shown with wp_get_attachment_image(), such as featured
     * images and logos.
     *
     * Featured images in an archive or search loop, past the pictures that
     * load straight away, get loading="lazy" here when a cache plugin's own
     * lazy load turned WordPress's off, as content pictures do in
     * content_image() (GH#1504). LiteSpeed Cache's does, and its lazy loader
     * then runs only on pages it caches, so on search results and on every
     * page for people who are logged in, all of them loaded straight away.
     * When WordPress's lazy loading is on, core decides, as before.
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
        // As in content_image(), one with fetchpriority="auto" takes no place.
        $counted = false;
        if (is_main_query() && in_the_loop() && !doing_filter('the_content') && !doing_filter('get_the_excerpt') && !is_feed()) {
            if (!isset($attr['fetchpriority']) || 'auto' !== $attr['fetchpriority']) {
                ++self::$pictures;
            }
            $counted = true;
        }
        $text = '';
        foreach ($attr as $name => $value) {
            $text .= ' ' . $name . '="' . (is_scalar($value) ? (string) $value : '') . '"';
        }
        $excluded = self::excluded($text);
        if ($excluded && isset($attr['loading']) && 'lazy' === $attr['loading']) {
            unset($attr['loading']);
        }
        if ($counted && !$excluded && self::$pictures > self::threshold()
            && !isset($attr['loading']) && !isset($attr['fetchpriority']) && !self::core_lazy()) {
            $attr['loading'] = 'lazy';
            // As core does for the pictures it lazy-loads itself (GH#1485).
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, read as core reads it.
            $auto = (bool) apply_filters('wp_img_tag_add_auto_sizes', true);
            if ($auto && isset($attr['sizes']) && is_string($attr['sizes']) && function_exists('wp_sizes_attribute_includes_valid_auto')
                && !wp_sizes_attribute_includes_valid_auto($attr['sizes'])) {
                $attr['sizes'] = 'auto, ' . $attr['sizes'];
            }
        }
        // Logos and pictures that load first: keep page-rewriting lazy
        // loaders (LiteSpeed Cache, WP Rocket) off them too.
        if ($excluded || $high) {
            $attr[self::NO_LAZY] = '1';
        }
        return $attr;
    }

    /**
     * Whether WordPress's own lazy loading is on for the picture
     * wp_get_attachment_image() is making, in the context core gives it
     * ('the_post_thumbnail' for featured images).
     *
     * @return bool
     */
    private static function core_lazy() {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, read as core reads it.
        $context = apply_filters('wp_get_attachment_image_context', 'wp_get_attachment_image');
        return (bool) wp_lazy_loading_enabled('img', is_string($context) ? $context : 'wp_get_attachment_image');
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
