<?php
/**
 * Website screenshots.
 *
 * A Screenshot block (seoprostack/screenshot) and Browser Shots' [browser-shot]
 * shortcode and block, served from the Media Library. Each page is captured
 * once by a screenshot service (Thum.io and Microlink need no key; ApiFlash
 * and Screenshot Machine use the site owner's key), saved as an attachment,
 * and shown from the site, so visitors never load the service and free
 * allowances are only used once per screenshot.
 *
 * Screenshots are taken by the editor (REST), in the background when a post
 * is saved or a page shows one that is missing (WP-Cron), and again after a
 * set number of days. Until one exists, a box with a link to the page shows.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Screenshots extends SEOProStack_Feature {

    const KEY = 'screenshots';

    /** Block name. */
    const BLOCK = 'seoprostack/screenshot';

    /** Browser Shots' block, still rendered for existing content. */
    const LEGACY_BLOCK = 'browser-shots/browser-shots';

    /** Browser Shots' shortcode. */
    const SHORTCODE = 'browser-shot';

    /** Cron hook that takes one screenshot (page URL, width, height, post ID). */
    const HOOK = 'seoprostack_screenshot';

    /** Attachment meta: the screenshot's key (page URL and browser size). */
    const META = '_seoprostack_screenshot';

    /** Attachment meta: the captured page's URL. */
    const META_URL = '_seoprostack_screenshot_url';

    /** Attachment meta: when the screenshot was taken (Unix time). */
    const META_TAKEN = '_seoprostack_screenshot_taken';

    /** REST namespace. */
    const REST_NAMESPACE = 'seoprostack/v1';

    /** Transient holding the last failure, shown in the settings panel. */
    const LAST_ERROR = 'seoprostack_screenshots_error';

    /** Largest file accepted from a service. */
    const MAX_BYTES = 20971520;

    /** Browser width and height limits. */
    const MIN_SIZE = 320;
    const MAX_WIDTH = 3840;
    const MAX_HEIGHT = 4000;

    /**
     * True while a screenshot is being added to the Media Library.
     *
     * @var bool
     */
    private static $capturing = false;

    /**
     * Attachment IDs found this request, by screenshot key.
     *
     * @var array<string,int>
     */
    private static $found = array();

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
                'label'       => __('Website screenshots', 'seoprostack'),
                'description' => __('Adds a Screenshot block and the [browser-shot] shortcode. Each page is captured once by a screenshot service, saved to the Media Library and shown from your site.', 'seoprostack'),
                'replaces'    => array('browser-shots' => 'Browser Shots'),
            ),
            'screenshots_service' => array(
                'type'        => 'select',
                'default'     => 'thumio',
                'parent'      => self::KEY,
                'label'       => __('Screenshot service', 'seoprostack'),
                'description' => __('Used once for each screenshot, and again when it is renewed.', 'seoprostack'),
                'options'     => array(
                    'thumio'            => __('Thum.io (free, no key)', 'seoprostack'),
                    'microlink'         => __('Microlink (free daily allowance, key optional)', 'seoprostack'),
                    'apiflash'          => __('ApiFlash (key needed)', 'seoprostack'),
                    'screenshotmachine' => __('Screenshot Machine (key needed)', 'seoprostack'),
                ),
            ),
            'screenshots_key' => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Service key', 'seoprostack'),
                'description' => __('Your ApiFlash access key, Screenshot Machine key or Microlink API key. Thum.io needs none. You can set SEOPROSTACK_SCREENSHOTS_KEY in wp-config.php instead.', 'seoprostack'),
            ),
            'screenshots_width' => array(
                'type'        => 'int',
                'default'     => 1920,
                'min'         => self::MIN_SIZE,
                'max'         => self::MAX_WIDTH,
                'unit'        => 'px',
                'parent'      => self::KEY,
                'label'       => __('Browser width', 'seoprostack'),
                'description' => __('Pages are shown in a browser window this wide. 1920 × 1080 is a maximised window on a full HD screen.', 'seoprostack'),
            ),
            'screenshots_height' => array(
                'type'        => 'int',
                'default'     => 1080,
                'min'         => self::MIN_SIZE,
                'max'         => self::MAX_HEIGHT,
                'unit'        => 'px',
                'parent'      => self::KEY,
                'label'       => __('Browser height', 'seoprostack'),
                'description' => __('Used when a screenshot has no shape of its own. Changing the size takes new screenshots; earlier ones stay in the Media Library.', 'seoprostack'),
            ),
            'screenshots_format' => array(
                'type'        => 'select',
                'default'     => 'jpeg',
                'parent'      => self::KEY,
                'label'       => __('File type', 'seoprostack'),
                'description' => __('JPEG files are much smaller; PNG keeps text sharper.', 'seoprostack'),
                'options'     => array(
                    'jpeg' => 'JPEG',
                    'png'  => 'PNG',
                ),
            ),
            'screenshots_refresh' => array(
                'type'        => 'int',
                'default'     => 30,
                'min'         => 0,
                'max'         => 365,
                'unit'        => __('days', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Take new screenshots after', 'seoprostack'),
                'description' => __('Older screenshots are replaced in the background the next time they are shown. 0 keeps them.', 'seoprostack'),
            ),
        );
    }

    /**
     * Switch on while Browser Shots is active, so it takes over when that
     * plugin is deactivated. Browser Shots has no settings to import.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (isset(self::active_plugins()['browser-shots'])) {
            $options = self::import_setting($options, self::KEY, true);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Screenshots are never watermarked, even when marked in bulk later.
        add_filter('seoprostack_watermark_attachment', array(__CLASS__, 'skip_watermark'), 10, 2);
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        }
        if (!self::enabled()) {
            return;
        }

        // boot() runs on init, which is where blocks are registered.
        register_block_type(SEOPROSTACK_DIR . 'blocks/screenshot');
        self::register_legacy_block();
        if (!shortcode_exists(self::SHORTCODE)) {
            add_shortcode(self::SHORTCODE, array(__CLASS__, 'shortcode'));
        }

        add_action(self::HOOK, array(__CLASS__, 'run_capture'), 10, 4);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('save_post', array(__CLASS__, 'post_saved'), 20, 2);
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_settings'));
    }

    /**
     * Plugin deactivated: drop screenshots waiting to be taken.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        unset($network_wide);
        wp_unschedule_hook(self::HOOK);
    }

    /*
     * ------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------
     */

    /**
     * Register Browser Shots' block so existing blocks keep showing. The
     * editor offers to convert them to Screenshot blocks.
     */
    private static function register_legacy_block() {
        if (WP_Block_Type_Registry::get_instance()->is_registered(self::LEGACY_BLOCK)) {
            return;
        }
        $number = array('type' => array('integer', 'string'));
        register_block_type(self::LEGACY_BLOCK, array(
            'title'           => 'Browser Shots',
            'category'        => 'embed',
            'attributes'      => array(
                'html'         => array('type' => 'string', 'default' => ''),
                'url'          => array('type' => 'string', 'default' => ''),
                'width'        => $number + array('default' => 600),
                'height'       => $number + array('default' => 450),
                'alt'          => array('type' => 'string', 'default' => ''),
                'link'         => array('type' => 'string', 'default' => ''),
                'target'       => array('type' => 'string', 'default' => ''),
                'classname'    => array('type' => 'string', 'default' => ''),
                'rel'          => array('type' => 'string', 'default' => ''),
                'display_link' => array('type' => 'boolean', 'default' => true),
                'image_size'   => array('type' => 'string', 'default' => 'medium'),
                'content'      => array('type' => 'string', 'default' => ''),
                'post_links'   => array('type' => 'boolean', 'default' => false),
                'align'        => array('type' => 'string', 'default' => ''),
            ),
            'supports'        => array(
                'inserter' => false,
                'html'     => false,
                'align'    => array('left', 'center', 'right'),
            ),
            'render_callback' => array(__CLASS__, 'render_legacy_block'),
        ));
    }

    /**
     * Pass the browser size and permissions to the editor.
     */
    public static function editor_settings() {
        $handle = generate_block_asset_handle(self::BLOCK, 'editorScript');
        wp_add_inline_script($handle, 'window.seoprostackScreenshots = ' . wp_json_encode(array(
            'width'      => self::browser_width(),
            'height'     => self::browser_height(),
            'canCapture' => current_user_can('upload_files'),
        )) . ';', 'before');
    }

    /**
     * [browser-shot url="…" width="600" height="450"]Caption[/browser-shot]
     *
     * Takes Browser Shots' attributes: url, width, height, alt, link (or
     * href, which its classic editor button wrote), target, class,
     * image_class, rel, display_link and post_links. link="PERMALINK" links
     * to the current post. The browser window keeps the image's shape.
     *
     * @param array|string $atts    Attributes.
     * @param string|null  $content Caption.
     * @return string
     */
    public static function shortcode($atts, $content = '') {
        $a = shortcode_atts(array(
            'url'          => '',
            'width'        => 600,
            'height'       => 450,
            'alt'          => '',
            'link'         => '',
            'href'         => '',
            'target'       => '',
            'class'        => '',
            'image_class'  => 'alignnone',
            'rel'          => '',
            'display_link' => true,
            'post_links'   => false,
        ), (array) $atts, self::SHORTCODE);

        $url    = self::clean_url($a['url']);
        $width  = min(self::MAX_WIDTH, absint($a['width']));
        $height = min(self::MAX_HEIGHT, absint($a['height']));
        $width  = $width >= 20 ? $width : 600;
        $height = $height >= 20 ? $height : 450;

        $link = '' !== trim((string) $a['link']) ? trim((string) $a['link']) : trim((string) $a['href']);
        if (filter_var($a['post_links'], FILTER_VALIDATE_BOOLEAN) || in_array($link, array('PERMALINK', 'http://PERMALINK'), true)) {
            $link = (string) get_permalink();
        }
        if (!filter_var($a['display_link'], FILTER_VALIDATE_BOOLEAN)) {
            $link = '';
        } elseif ('' === $link) {
            $link = $url;
        }

        $classes = array('browser-shot');
        foreach (array($a['image_class'], $a['class']) as $class) {
            foreach (preg_split('/\s+/', (string) $class, -1, PREG_SPLIT_NO_EMPTY) ?: array() as $name) {
                $classes[] = sanitize_html_class($name);
            }
        }

        return self::markup(array(
            'url'     => $url,
            'ratio'   => array($width, $height),
            'width'   => $width,
            'alt'     => $a['alt'],
            'link'    => $link,
            'target'  => $a['target'],
            'rel'     => $a['rel'],
            'caption' => (string) $content,
            'classes' => $classes,
            'post_id' => (int) get_the_ID(),
        ));
    }

    /**
     * Browser Shots block: render through the shortcode, as it did.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_legacy_block($attributes) {
        $a = (array) $attributes;
        return self::shortcode(array(
            'url'          => isset($a['url']) ? $a['url'] : '',
            'width'        => isset($a['width']) ? $a['width'] : 600,
            'height'       => isset($a['height']) ? $a['height'] : 450,
            'alt'          => isset($a['alt']) ? $a['alt'] : '',
            'link'         => isset($a['link']) ? $a['link'] : '',
            'target'       => isset($a['target']) ? $a['target'] : '',
            'class'        => isset($a['classname']) ? $a['classname'] : '',
            'image_class'  => !empty($a['align']) ? 'align' . $a['align'] : 'alignnone',
            'rel'          => isset($a['rel']) ? $a['rel'] : '',
            'display_link' => !isset($a['display_link']) || $a['display_link'] ? 'true' : 'false',
            'post_links'   => !empty($a['post_links']) ? 'true' : 'false',
        ), isset($a['content']) ? (string) $a['content'] : '');
    }

    /**
     * Screenshot block.
     *
     * @param array         $attributes Block attributes.
     * @param WP_Block|null $block      Block instance.
     * @return string
     */
    public static function render_block(array $attributes, $block = null) {
        $url     = self::clean_url(isset($attributes['url']) ? $attributes['url'] : '');
        $post_id = ($block instanceof WP_Block && !empty($block->context['postId'])) ? (int) $block->context['postId'] : (int) get_the_ID();
        $width   = isset($attributes['width']) ? max(0, min(self::MAX_WIDTH, (int) $attributes['width'])) : 0;
        $link_to = isset($attributes['linkTo']) ? (string) $attributes['linkTo'] : 'page';

        switch ($link_to) {
            case 'none':
                $link = '';
                break;
            case 'post':
                $link = $post_id ? (string) get_permalink($post_id) : '';
                break;
            case 'custom':
                $link = isset($attributes['href']) ? trim((string) $attributes['href']) : '';
                break;
            default:
                $link = $url;
        }

        $rel = array();
        foreach (array('nofollow', 'sponsored', 'ugc') as $value) {
            if (!empty($attributes[$value])) {
                $rel[] = $value;
            }
        }
        $wrapper_args = array('class' => 'seoprostack-screenshot' . ($width ? '' : ' is-fill'));
        if ($width) {
            $wrapper_args['style'] = sprintf('width:%dpx;', $width);
        }

        return self::markup(array(
            'url'     => $url,
            'ratio'   => self::parse_ratio(isset($attributes['aspectRatio']) ? $attributes['aspectRatio'] : ''),
            'width'   => $width,
            'alt'     => isset($attributes['alt']) ? $attributes['alt'] : '',
            'link'    => $link,
            'target'  => !empty($attributes['newTab']) ? '_blank' : '',
            'rel'     => implode(' ', $rel),
            'caption' => isset($attributes['caption']) ? (string) $attributes['caption'] : '',
            'wrapper' => get_block_wrapper_attributes($wrapper_args),
            'style'   => self::image_style($attributes),
            'post_id' => $post_id,
        ));
    }

    /**
     * Border and shadow chosen in the editor, for the picture itself so a
     * caption stays outside them.
     *
     * @param array $attributes Block attributes.
     * @return string CSS declarations.
     */
    private static function image_style(array $attributes) {
        $style  = isset($attributes['style']) && is_array($attributes['style']) ? $attributes['style'] : array();
        $border = isset($style['border']) && is_array($style['border']) ? self::clean_css_values($style['border']) : array();

        $css = '';
        if ($border && function_exists('wp_style_engine_get_styles')) {
            $styles = wp_style_engine_get_styles(array('border' => $border));
            $css   .= isset($styles['css']) ? rtrim($styles['css'], ';') . ';' : '';
        }
        // A palette colour is saved apart from the other border settings, and
        // the style engine would return it as a class name, not CSS.
        if (!empty($attributes['borderColor']) && is_string($attributes['borderColor'])) {
            $css .= 'border-color:' . self::css_value('var:preset|color|' . $attributes['borderColor']) . ';';
        }
        $css = '' !== $css ? safecss_filter_attr($css) : '';

        // safecss_filter_attr() drops rgb() and other colour functions, so
        // shadows are checked here instead: lengths, colours and var() only.
        $shadow = !empty($style['shadow']) && is_string($style['shadow']) ? self::css_value($style['shadow']) : '';
        if ('' !== $shadow && preg_match('/^[a-z0-9\s#.,()%-]+$/i', $shadow) && !preg_match('/url|expression/i', $shadow)) {
            $css .= ('' !== $css ? ';' : '') . 'box-shadow:' . $shadow;
        }
        return $css;
    }

    /**
     * Drop values that could end a CSS declaration and start another.
     *
     * @param array $values Nested style values.
     * @return array
     */
    private static function clean_css_values(array $values) {
        $clean = array();
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = self::clean_css_values($value);
            } elseif (is_scalar($value) && !preg_match('/[;{}"\'\\\\<>]/', (string) $value)) {
                $clean[$key] = (string) $value;
            }
        }
        return $clean;
    }

    /**
     * Turn a preset reference ("var:preset|shadow|natural") into a CSS
     * variable; other values pass through. Slugs are kebab-cased as core
     * does, so Kadence's "theme-palette3" becomes "theme-palette-3", the
     * variable its dark mode switches.
     *
     * @param string $value Value.
     * @return string
     */
    private static function css_value($value) {
        if (0 !== strpos($value, 'var:')) {
            return $value;
        }
        $parts = array_map(function ($part) {
            return preg_replace('/[^a-z0-9-]/i', '', _wp_to_kebab_case($part));
        }, explode('|', substr($value, 4)));
        return 'var(--wp--' . implode('--', $parts) . ')';
    }

    /**
     * Build a screenshot's HTML. Takes it in the background when it is
     * missing or old.
     *
     * @param array $args {
     *     @type string     $url     Page URL (already cleaned).
     *     @type int[]|null $ratio   Shape as [width, height], or null for the browser window.
     *     @type int        $width   Display width in pixels, 0 to fill.
     *     @type string     $alt     Alt text; empty for "Screenshot of host".
     *     @type string     $link    Link URL, or '' for none.
     *     @type string     $target  Link target.
     *     @type string     $rel     Link rel.
     *     @type string     $caption Caption HTML.
     *     @type string     $wrapper Block wrapper attributes (blocks only).
     *     @type string     $style   Picture CSS: border and shadow (blocks only).
     *     @type string[]   $classes Wrapper classes (shortcodes only).
     *     @type int        $post_id Post showing the screenshot.
     * }
     * @return string
     */
    private static function markup(array $args) {
        $url = $args['url'];
        if ('' === $url) {
            return '';
        }

        list($browser_w, $browser_h) = self::browser_size($args['ratio']);
        $shot = self::find($url, $browser_w, $browser_h);
        if (!$shot || self::is_stale($shot)) {
            self::schedule($url, $browser_w, $browser_h, $args['post_id']);
        }

        $host    = (string) wp_parse_url($url, PHP_URL_HOST);
        $alt     = '' !== trim((string) $args['alt']) ? trim((string) $args['alt']) : sprintf(/* translators: %s: domain name */ __('Screenshot of %s', 'seoprostack'), $host);
        $width   = (int) $args['width'];
        $display = $width ? $width : $browser_w;
        $height  = (int) round($display * $browser_h / $browser_w);
        $style   = isset($args['style']) ? (string) $args['style'] : '';

        if ($shot) {
            // Services stop at the bottom of short pages, so use the file's own shape.
            $meta = wp_get_attachment_metadata($shot);
            if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) {
                $height = (int) round($display * $meta['height'] / $meta['width']);
            }
            $sizes  = $width ? sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $width) : '100vw';
            $srcset = wp_get_attachment_image_srcset($shot, 'full');
            $image  = sprintf(
                '<img src="%1$s"%2$s sizes="%3$s" width="%4$d" height="%5$d" alt="%6$s" loading="lazy" decoding="async" class="seoprostack-screenshot__image"%7$s />',
                esc_url((string) wp_get_attachment_image_url($shot, 'full')),
                $srcset ? ' srcset="' . esc_attr($srcset) . '"' : '',
                esc_attr($sizes),
                $display,
                $height,
                esc_attr($alt),
                '' !== $style ? ' style="' . esc_attr($style) . '"' : ''
            );
        } else {
            // Shown until the screenshot is taken, usually one page view.
            $image = sprintf(
                '<span class="seoprostack-screenshot__pending" style="aspect-ratio:%1$d/%2$d;%5$s" role="img" aria-label="%3$s">%4$s</span>',
                $display,
                $height,
                esc_attr($alt),
                esc_html($host),
                esc_attr($style)
            );
        }

        $link = '' !== $args['link'] ? esc_url($args['link']) : '';
        if ('' !== $link) {
            $target = in_array($args['target'], array('_blank', '_self', '_parent', '_top'), true) ? $args['target'] : '';
            $rel    = preg_split('/\s+/', strtolower((string) preg_replace('/[^A-Za-z\s-]/', '', (string) $args['rel'])), -1, PREG_SPLIT_NO_EMPTY) ?: array();
            if ('_blank' === $target) {
                $rel[] = 'noopener';
            }
            $image = sprintf(
                '<a href="%1$s"%2$s%3$s>%4$s</a>',
                $link,
                $target ? ' target="' . esc_attr($target) . '"' : '',
                $rel ? ' rel="' . esc_attr(implode(' ', array_unique($rel))) . '"' : '',
                $image
            );
        }

        $caption = trim((string) $args['caption']);
        if (isset($args['wrapper'])) {
            $wrapper = $args['wrapper'];
            $caption = '' !== $caption ? '<figcaption class="wp-element-caption">' . wp_kses_post($caption) . '</figcaption>' : '';
        } else {
            // Blocks get their stylesheet from block.json; shortcodes ask for it.
            wp_enqueue_style(generate_block_asset_handle(self::BLOCK, 'style'));
            $classes = array_merge(array('seoprostack-screenshot'), (array) $args['classes']);
            if ('' !== $caption) {
                $classes[] = 'wp-caption';
                $caption   = '<figcaption class="wp-caption-text">' . wp_kses_post($caption) . '</figcaption>';
            }
            $wrapper = sprintf('class="%1$s" style="width:%2$dpx;"', esc_attr(implode(' ', array_unique(array_filter($classes)))), $display);
        }

        return '<figure ' . $wrapper . '>' . $image . $caption . '</figure>';
    }

    /*
     * ------------------------------------------------------------------
     * Finding and scheduling
     * ------------------------------------------------------------------
     */

    /**
     * Clean a page URL: http or https with a host, else ''.
     *
     * @param mixed $url Raw URL.
     * @return string
     */
    public static function clean_url($url) {
        $url = esc_url_raw(trim((string) $url), array('http', 'https'));
        return '' !== $url && wp_parse_url($url, PHP_URL_HOST) ? $url : '';
    }

    /**
     * Parse a shape such as "4/3" or "16:9".
     *
     * @param mixed $ratio Raw shape.
     * @return int[]|null [width, height], or null for the browser window.
     */
    public static function parse_ratio($ratio) {
        if (is_array($ratio) && 2 === count($ratio)) {
            $ratio = implode('/', array_map('intval', array_values($ratio)));
        }
        if (!is_string($ratio) || !preg_match('#^\s*(\d{1,5})\s*[/:]\s*(\d{1,5})\s*$#', $ratio, $m) || !(int) $m[1] || !(int) $m[2]) {
            return null;
        }
        return array((int) $m[1], (int) $m[2]);
    }

    /**
     * Browser window width.
     *
     * @return int
     */
    public static function browser_width() {
        return max(self::MIN_SIZE, min(self::MAX_WIDTH, (int) SEOProStack_Settings::get('screenshots_width')));
    }

    /**
     * Browser window height.
     *
     * @return int
     */
    public static function browser_height() {
        return max(self::MIN_SIZE, min(self::MAX_HEIGHT, (int) SEOProStack_Settings::get('screenshots_height')));
    }

    /**
     * Browser window for a shape: the set width, and a height that gives
     * the shape (or the set height).
     *
     * @param int[]|null $ratio [width, height] or null.
     * @return int[] [width, height]
     */
    public static function browser_size($ratio) {
        $width = self::browser_width();
        if (!is_array($ratio)) {
            return array($width, self::browser_height());
        }
        $height = (int) round($width * $ratio[1] / $ratio[0]);
        return array($width, max(self::MIN_SIZE, min(self::MAX_HEIGHT, $height)));
    }

    /**
     * Key for a page at a browser size.
     *
     * @param string $url    Page URL.
     * @param int    $width  Browser width.
     * @param int    $height Browser height.
     * @return string
     */
    private static function key($url, $width, $height) {
        return md5($url . '|' . (int) $width . 'x' . (int) $height);
    }

    /**
     * The Media Library item holding a screenshot.
     *
     * @param string $url    Page URL.
     * @param int    $width  Browser width.
     * @param int    $height Browser height.
     * @return int Attachment ID, or 0.
     */
    public static function find($url, $width, $height) {
        $key = self::key($url, $width, $height);
        if (!array_key_exists($key, self::$found)) {
            // Only screenshots have this meta key, so its index keeps this cheap.
            $ids = get_posts(array(
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'meta_key'               => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_value'             => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
            ));
            self::$found[$key] = $ids ? (int) $ids[0] : 0;
        }
        return self::$found[$key];
    }

    /**
     * Whether a screenshot is due to be taken again.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private static function is_stale($attachment_id) {
        $days = (int) SEOProStack_Settings::get('screenshots_refresh');
        if ($days <= 0) {
            return false;
        }
        $taken = (int) get_post_meta($attachment_id, self::META_TAKEN, true);
        return $taken && $taken < time() - $days * DAY_IN_SECONDS;
    }

    /**
     * Take a screenshot in the background, unless it is being taken or
     * failed within the hour.
     *
     * @param string $url     Page URL.
     * @param int    $width   Browser width.
     * @param int    $height  Browser height.
     * @param int    $post_id Post that shows it.
     */
    private static function schedule($url, $width, $height, $post_id) {
        $key = self::key($url, $width, $height);
        if (get_transient('seoprostack_shot_fail_' . $key) || get_transient('seoprostack_shot_lock_' . $key)) {
            return;
        }
        $args = array($url, (int) $width, (int) $height, (int) $post_id);
        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time(), self::HOOK, $args);
        }
    }

    /**
     * Saved posts: take their missing screenshots in the background, so they
     * are ready before anyone views the page.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function post_saved($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !in_array($post->post_status, array('publish', 'future', 'private'), true)) {
            return;
        }
        $content = (string) $post->post_content;
        if (false === strpos($content, '[' . self::SHORTCODE) && false === strpos($content, 'wp:' . self::BLOCK) && false === strpos($content, 'wp:' . self::LEGACY_BLOCK)) {
            return;
        }
        foreach (array_slice(self::shots_in($content), 0, 20) as $shot) {
            list($width, $height) = self::browser_size($shot['ratio']);
            if (!self::find($shot['url'], $width, $height)) {
                self::schedule($shot['url'], $width, $height, (int) $post_id);
            }
        }
    }

    /**
     * Screenshots used in post content.
     *
     * @param string $content Post content.
     * @return array[] Each with url and ratio.
     */
    private static function shots_in($content) {
        $shots = array();
        $walk  = function ($blocks) use (&$walk, &$shots) {
            foreach ($blocks as $block) {
                $a = isset($block['attrs']) ? (array) $block['attrs'] : array();
                if (self::BLOCK === $block['blockName']) {
                    $shots[] = array('url' => isset($a['url']) ? $a['url'] : '', 'ratio' => self::parse_ratio(isset($a['aspectRatio']) ? $a['aspectRatio'] : ''));
                } elseif (self::LEGACY_BLOCK === $block['blockName']) {
                    $shots[] = array('url' => isset($a['url']) ? $a['url'] : '', 'ratio' => self::legacy_ratio($a));
                }
                if (!empty($block['innerBlocks'])) {
                    $walk($block['innerBlocks']);
                }
            }
        };
        if (function_exists('parse_blocks')) {
            $walk(parse_blocks($content));
        }

        if (false !== strpos($content, '[' . self::SHORTCODE) && preg_match_all('/' . get_shortcode_regex(array(self::SHORTCODE)) . '/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ('[' === $match[1] && ']' === $match[6]) {
                    continue; // Escaped: [[browser-shot]].
                }
                $a       = (array) shortcode_parse_atts($match[3]);
                $shots[] = array('url' => isset($a['url']) ? $a['url'] : '', 'ratio' => self::legacy_ratio($a));
            }
        }

        $clean = array();
        foreach ($shots as $shot) {
            $shot['url'] = self::clean_url($shot['url']);
            if ('' !== $shot['url']) {
                $clean[] = $shot;
            }
        }
        return $clean;
    }

    /**
     * Shape of a Browser Shots block or shortcode.
     *
     * @param array $a Attributes.
     * @return int[]
     */
    private static function legacy_ratio(array $a) {
        $width  = isset($a['width']) ? absint($a['width']) : 0;
        $height = isset($a['height']) ? absint($a['height']) : 0;
        return array($width >= 20 ? $width : 600, $height >= 20 ? $height : 450);
    }

    /*
     * ------------------------------------------------------------------
     * Taking screenshots
     * ------------------------------------------------------------------
     */

    /**
     * Cron: take a screenshot that is missing or old.
     *
     * @param string $url     Page URL.
     * @param int    $width   Browser width.
     * @param int    $height  Browser height.
     * @param int    $post_id Post that shows it.
     */
    public static function run_capture($url, $width, $height, $post_id = 0) {
        $url = self::clean_url($url);
        if ('' === $url || !self::enabled()) {
            return;
        }
        $existing = self::find($url, (int) $width, (int) $height);
        if ($existing && !self::is_stale($existing)) {
            return;
        }
        self::capture($url, (int) $width, (int) $height, (int) $post_id, $existing);
    }

    /**
     * Take a screenshot and save it to the Media Library, replacing an
     * older one.
     *
     * @param string $url     Page URL.
     * @param int    $width   Browser width.
     * @param int    $height  Browser height.
     * @param int    $post_id Post to attach it to (0 for none).
     * @param int    $replace Older screenshot to delete once this one is saved.
     * @param bool   $force   Try even if the last try failed within the hour.
     * @return int|WP_Error Attachment ID.
     */
    public static function capture($url, $width, $height, $post_id = 0, $replace = 0, $force = false) {
        $key  = self::key($url, $width, $height);
        $fail = 'seoprostack_shot_fail_' . $key;
        $lock = 'seoprostack_shot_lock_' . $key;
        if (!$force && get_transient($fail)) {
            return new WP_Error('seoprostack_screenshot_waiting', __('The last try failed, so this screenshot waits an hour before trying again.', 'seoprostack'));
        }
        if (get_transient($lock)) {
            return new WP_Error('seoprostack_screenshot_busy', __('This screenshot is being taken already. Try again in a minute.', 'seoprostack'));
        }
        set_transient($lock, 1, 3 * MINUTE_IN_SECONDS);
        if (function_exists('set_time_limit')) {
            @set_time_limit(180); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions -- slow services; may be disabled by the host.
        }

        if ($replace && !$post_id) {
            $post_id = (int) wp_get_post_parent_id($replace);
        }
        $result = self::take($url, $width, $height, $key, $post_id);
        delete_transient($lock);

        if (is_wp_error($result)) {
            set_transient($fail, $result->get_error_message(), HOUR_IN_SECONDS);
            set_transient(self::LAST_ERROR, array(
                'message' => $result->get_error_message(),
                'url'     => $url,
                'time'    => time(),
            ), WEEK_IN_SECONDS);
            self::log($url, $result->get_error_message());

            /**
             * Fires when a screenshot cannot be taken.
             *
             * @param string   $url   Page URL.
             * @param WP_Error $error What went wrong.
             */
            do_action('seoprostack_screenshot_failed', $url, $result);
            return $result;
        }

        delete_transient($fail);
        delete_transient(self::LAST_ERROR);
        self::$found[$key] = $result;
        if ($replace && $replace !== $result && get_post_meta($replace, self::META, true)) {
            wp_delete_attachment($replace, true);
        }
        if ($post_id) {
            // Lets page caches that listen for post changes show the picture.
            clean_post_cache($post_id);
        }

        /**
         * Fires when a screenshot is saved to the Media Library.
         *
         * @param int    $attachment_id Attachment ID.
         * @param string $url           Page URL.
         * @param int    $post_id       Post it is attached to, or 0.
         */
        do_action('seoprostack_screenshot_saved', $result, $url, $post_id);
        return $result;
    }

    /**
     * Ask the service for a screenshot and add it to the Media Library.
     *
     * @param string $url     Page URL.
     * @param int    $width   Browser width.
     * @param int    $height  Browser height.
     * @param string $key     Screenshot key.
     * @param int    $post_id Post to attach it to.
     * @return int|WP_Error Attachment ID.
     */
    private static function take($url, $width, $height, $key, $post_id) {
        // Services photograph their own error page for sites they cannot
        // reach, so check first. This also refuses local and private addresses.
        if (!wp_http_validate_url($url)) {
            return new WP_Error('seoprostack_screenshot_unreachable', __('The site could not be found, or is not on the public internet.', 'seoprostack'));
        }
        $check = wp_safe_remote_head($url, array('timeout' => 15, 'redirection' => 5)); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- cron only (run_capture()); another site's page may be slow.
        if (is_wp_error($check)) {
            /* translators: %s: error message */
            return new WP_Error('seoprostack_screenshot_unreachable', sprintf(__('The page could not be reached: %s', 'seoprostack'), $check->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($check);
        if (in_array($status, array(404, 410), true)) {
            /* translators: %d: HTTP status code */
            return new WP_Error('seoprostack_screenshot_missing', sprintf(__('The page was not found (HTTP %d).', 'seoprostack'), $status));
        }

        // wp_tempnam() and media_handle_sideload() are admin functions, and
        // screenshots are also taken from REST requests and WP-Cron.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = self::fetch($url, $width, $height);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $mime = wp_get_image_mime($tmp);
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true)) {
            wp_delete_file($tmp);
            return new WP_Error('seoprostack_screenshot_not_image', __('The screenshot service did not send a picture.', 'seoprostack'));
        }

        list($tmp, $mime) = self::normalise($tmp, $mime, $width, $height);

        $host  = (string) wp_parse_url($url, PHP_URL_HOST);
        $path  = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        $page  = preg_replace('/^www\./i', '', $host) . ('' !== $path ? '/' . $path : '');
        $name  = substr(sanitize_title('screenshot-' . $page), 0, 80);
        $ext   = 'image/png' === $mime ? 'png' : ('image/webp' === $mime ? 'webp' : 'jpg');
        $title = sprintf(/* translators: %s: page address without https:// */ __('Screenshot of %s', 'seoprostack'), $page);

        self::$capturing = true;
        $attachment_id   = media_handle_sideload(
            array('name' => $name . '.' . $ext, 'tmp_name' => $tmp),
            (int) $post_id,
            null,
            array('post_title' => $title)
        );
        self::$capturing = false;

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            return $attachment_id;
        }

        update_post_meta($attachment_id, self::META, $key);
        update_post_meta($attachment_id, self::META_URL, esc_url_raw($url));
        update_post_meta($attachment_id, self::META_TAKEN, time());
        /* translators: %s: domain name */
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sprintf(__('Screenshot of %s', 'seoprostack'), $host));

        return (int) $attachment_id;
    }

    /**
     * The request for the chosen service.
     *
     * @param string $url    Page URL.
     * @param int    $width  Browser width.
     * @param int    $height Browser height.
     * @return array|WP_Error Request URL, headers and whether it answers with JSON.
     */
    private static function service_request($url, $width, $height) {
        $service = (string) SEOProStack_Settings::get('screenshots_service');
        $key     = self::service_key();
        $png     = 'png' === SEOProStack_Settings::get('screenshots_format');
        $request = array('url' => '', 'headers' => array(), 'json' => false);

        switch ($service) {
            case 'apiflash':
                if ('' === $key) {
                    return new WP_Error('seoprostack_screenshot_no_key', __('Enter your ApiFlash access key in the screenshot settings.', 'seoprostack'));
                }
                $request['url'] = add_query_arg(array_map('rawurlencode', array(
                    'access_key'    => $key,
                    'url'           => $url,
                    'width'         => (string) $width,
                    'height'        => (string) $height,
                    'format'        => $png ? 'png' : 'jpeg',
                    'fresh'         => 'true',
                    'response_type' => 'image',
                )), 'https://api.apiflash.com/v1/urltoimage');
                break;

            case 'screenshotmachine':
                if ('' === $key) {
                    return new WP_Error('seoprostack_screenshot_no_key', __('Enter your Screenshot Machine key in the screenshot settings.', 'seoprostack'));
                }
                $request['url'] = add_query_arg(array_map('rawurlencode', array(
                    'key'        => $key,
                    'url'        => $url,
                    'dimension'  => $width . 'x' . $height,
                    'device'     => 'desktop',
                    'format'     => $png ? 'png' : 'jpg',
                    'cacheLimit' => '0',
                )), 'https://api.screenshotmachine.com/');
                break;

            case 'microlink':
                $request['url'] = add_query_arg(array_map('rawurlencode', array(
                    'url'                          => $url,
                    'screenshot'                   => 'true',
                    'meta'                         => 'false',
                    'type'                         => $png ? 'png' : 'jpeg',
                    'viewport.width'               => (string) $width,
                    'viewport.height'              => (string) $height,
                    'viewport.deviceScaleFactor'   => '1',
                )), '' !== $key ? 'https://pro.microlink.io/' : 'https://api.microlink.io/');
                if ('' !== $key) {
                    $request['headers']['x-api-key'] = $key;
                }
                $request['json'] = true;
                break;

            case 'thumio':
            default:
                // Thum.io takes the page address as the end of its own path.
                $request['url'] = sprintf('https://image.thum.io/get/width/%1$d/crop/%2$d/viewportWidth/%1$d/viewportHeight/%2$d/noanimate/%3$s', $width, $height, $url);
                break;
        }

        /**
         * Filter the request sent to the screenshot service, for example to
         * use another service. The response must be a JPEG, PNG or WebP
         * picture, or JSON (with 'json' true) holding data.screenshot.url.
         *
         * @param array  $request { url, headers, json }.
         * @param string $url     Page URL.
         * @param int    $width   Browser width.
         * @param int    $height  Browser height.
         * @param string $service Chosen service.
         */
        return apply_filters('seoprostack_screenshot_request', $request, $url, $width, $height, $service);
    }

    /**
     * Download a screenshot from the service to a temporary file.
     *
     * @param string $url    Page URL.
     * @param int    $width  Browser width.
     * @param int    $height Browser height.
     * @return string|WP_Error Temporary file path.
     */
    private static function fetch($url, $width, $height) {
        $request = self::service_request($url, $width, $height);
        if (is_wp_error($request)) {
            return $request;
        }
        if (!is_array($request) || empty($request['url'])) {
            return new WP_Error('seoprostack_screenshot_no_service', __('No screenshot service is set.', 'seoprostack'));
        }
        $headers = isset($request['headers']) ? (array) $request['headers'] : array();
        $image   = (string) $request['url'];

        if (!empty($request['json'])) {
            $response = wp_safe_remote_get($image, array('timeout' => 90, 'headers' => $headers)); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- cron only; the service loads the page in a browser first.
            if (is_wp_error($response)) {
                /* translators: %s: error message */
                return new WP_Error('seoprostack_screenshot_service', sprintf(__('The screenshot service did not answer: %s', 'seoprostack'), $response->get_error_message()));
            }
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($body) || empty($body['data']['screenshot']['url']) || !is_string($body['data']['screenshot']['url'])) {
                $message = is_array($body) && !empty($body['message']) && is_string($body['message']) ? $body['message'] : (string) wp_remote_retrieve_response_message($response);
                /* translators: 1: message from the service, 2: HTTP status code */
                return new WP_Error('seoprostack_screenshot_service', sprintf(__('The screenshot service said: %1$s (HTTP %2$d)', 'seoprostack'), wp_strip_all_tags($message), (int) wp_remote_retrieve_response_code($response)));
            }
            $image   = $body['data']['screenshot']['url'];
            $headers = array();
        }

        $tmp = wp_tempnam('seoprostack-screenshot');
        if (!$tmp) {
            return new WP_Error('seoprostack_screenshot_tmp', __('A temporary file could not be made.', 'seoprostack'));
        }
        $response = wp_safe_remote_get($image, array(
            'timeout'             => 90, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- cron only; the service loads the page in a browser first.
            'headers'             => $headers,
            'stream'              => true,
            'filename'            => $tmp,
            'limit_response_size' => self::MAX_BYTES,
        ));
        if (is_wp_error($response)) {
            wp_delete_file($tmp);
            /* translators: %s: error message */
            return new WP_Error('seoprostack_screenshot_service', sprintf(__('The screenshot service did not answer: %s', 'seoprostack'), $response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        // Screenshot Machine answers errors with a picture and this header.
        $problem = wp_remote_retrieve_header($response, 'x-screenshotmachine-response');
        $problem = is_array($problem) ? implode(', ', $problem) : (string) $problem;
        if (200 !== $status || '' !== $problem) {
            $message = '' !== $problem ? $problem : trim(wp_strip_all_tags((string) file_get_contents($tmp, false, null, 0, 300))); // phpcs:ignore WordPress.WP.AlternativeFunctions -- our temporary file.
            wp_delete_file($tmp);
            /* translators: 1: message from the service, 2: HTTP status code */
            return new WP_Error('seoprostack_screenshot_service', sprintf(__('The screenshot service said: %1$s (HTTP %2$d)', 'seoprostack'), '' !== $message ? $message : (string) wp_remote_retrieve_response_message($response), $status));
        }
        return $tmp;
    }

    /**
     * Scale a screenshot to the browser width, crop it to the browser height
     * and save it in the chosen file type.
     *
     * @param string $tmp    File path.
     * @param string $mime   File type.
     * @param int    $width  Browser width.
     * @param int    $height Browser height.
     * @return array [path, mime]
     */
    private static function normalise($tmp, $mime, $width, $height) {
        $target = 'png' === SEOProStack_Settings::get('screenshots_format') ? 'image/png' : 'image/jpeg';
        // The temporary file has no image extension; the type lets WordPress
        // pick an editor that can read it (some Imagick builds cannot read PNG).
        $editor = wp_get_image_editor($tmp, array('mime_type' => $mime));
        if (is_wp_error($editor)) {
            return array($tmp, $mime);
        }

        $changed = false;
        $size    = $editor->get_size();
        if ($size['width'] > $width && !is_wp_error($editor->resize($width, null, false))) {
            $changed = true;
            $size    = $editor->get_size();
        }
        if ($size['height'] > $height + 1 && !is_wp_error($editor->crop(0, 0, $size['width'], $height))) {
            $changed = true;
        }
        if (!$changed && $mime === $target) {
            return array($tmp, $mime);
        }

        $saved = $editor->save($tmp . ('image/png' === $target ? '.png' : '.jpg'), $target);
        if (is_wp_error($saved) || empty($saved['path'])) {
            return array($tmp, $mime);
        }
        wp_delete_file($tmp);
        return array($saved['path'], isset($saved['mime-type']) ? $saved['mime-type'] : $target);
    }

    /**
     * The service key: the wp-config.php constant, or the setting.
     *
     * @return string
     */
    private static function service_key() {
        if (defined('SEOPROSTACK_SCREENSHOTS_KEY') && is_string(SEOPROSTACK_SCREENSHOTS_KEY) && '' !== SEOPROSTACK_SCREENSHOTS_KEY) {
            return trim(SEOPROSTACK_SCREENSHOTS_KEY);
        }
        return trim((string) SEOProStack_Settings::get('screenshots_key'));
    }

    /**
     * Never watermark screenshots.
     *
     * @param bool $mark          Whether to mark.
     * @param int  $attachment_id Attachment ID.
     * @return bool
     */
    public static function skip_watermark($mark, $attachment_id) {
        if (self::$capturing || get_post_meta((int) $attachment_id, self::META, true)) {
            return false;
        }
        return $mark;
    }

    /**
     * Log a failure when debugging.
     *
     * @param string $url   Page URL.
     * @param string $error Message.
     */
    private static function log($url, $error) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('[SEO Pro Stack] Screenshot of %s failed: %s', esc_url_raw($url), $error));
        }
    }

    /*
     * ------------------------------------------------------------------
     * Editor
     * ------------------------------------------------------------------
     */

    /**
     * REST route the editor uses to show and take screenshots.
     */
    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/screenshot', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_screenshot'),
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
            'args'                => array(
                'url'     => array('type' => 'string', 'required' => true),
                'ratio'   => array('type' => 'string', 'default' => ''),
                'refresh' => array('type' => 'boolean', 'default' => false),
                'post'    => array('type' => 'integer', 'default' => 0),
            ),
        ));
    }

    /**
     * REST: return a screenshot, taking it first when it is missing (or
     * when asked to renew it). Only people who can upload files take
     * screenshots; others see them once taken.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function rest_screenshot($request) {
        $url = self::clean_url($request['url']);
        if ('' === $url) {
            return new WP_Error('seoprostack_screenshot_url', __('Enter a full web address starting with https://', 'seoprostack'), array('status' => 400));
        }
        list($width, $height) = self::browser_size(self::parse_ratio($request['ratio']));
        $existing = self::find($url, $width, $height);

        if (!$existing || $request['refresh']) {
            if (!current_user_can('upload_files')) {
                return array(
                    'id'      => $existing,
                    'message' => __('The screenshot will be taken when the page is published or viewed.', 'seoprostack'),
                ) + ($existing ? self::describe($existing) : array());
            }
            $post_id = (int) $request['post'];
            $post_id = $post_id && current_user_can('edit_post', $post_id) ? $post_id : 0;
            $shot    = self::capture($url, $width, $height, $post_id, $existing, true);
            if (is_wp_error($shot)) {
                $shot->add_data(array('status' => 502));
                return $shot;
            }
            $existing = $shot;
        }

        return array('id' => $existing) + self::describe($existing);
    }

    /**
     * Details of a screenshot for the editor.
     *
     * @param int $attachment_id Attachment ID.
     * @return array
     */
    private static function describe($attachment_id) {
        $meta  = wp_get_attachment_metadata($attachment_id);
        $taken = (int) get_post_meta($attachment_id, self::META_TAKEN, true);
        return array(
            'src'    => (string) wp_get_attachment_image_url($attachment_id, 'large'),
            'width'  => is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0,
            'height' => is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0,
            'taken'  => $taken ? wp_date(get_option('date_format'), $taken) : '',
        );
    }

    /**
     * Settings panel: how many screenshots are saved, and the last failure.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field) {
        unset($field);
        if (self::KEY !== $key || !self::switched_on()) {
            return;
        }
        $query = new WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
        ));
        printf(
            '<div class="sps-panel-note"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %s: number of screenshots */
                _n('%s screenshot is saved in the Media Library.', '%s screenshots are saved in the Media Library.', (int) $query->found_posts, 'seoprostack'),
                number_format_i18n((int) $query->found_posts)
            ))
        );

        $service = (string) SEOProStack_Settings::get('screenshots_service');
        if (in_array($service, array('apiflash', 'screenshotmachine'), true) && '' === self::service_key()) {
            printf('<div class="sps-panel-note sps-panel-note--warning"><p>%s</p></div>', esc_html__('This service needs a key. Until one is entered, no screenshots are taken.', 'seoprostack'));
        }

        $error = get_transient(self::LAST_ERROR);
        if (is_array($error) && !empty($error['message'])) {
            printf(
                '<div class="sps-panel-note sps-panel-note--warning"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: 1: page address, 2: time ago, 3: error message */
                    __('The last screenshot, of %1$s %2$s ago, failed: %3$s', 'seoprostack'),
                    isset($error['url']) ? $error['url'] : '',
                    human_time_diff(isset($error['time']) ? (int) $error['time'] : time()),
                    $error['message']
                ))
            );
        }
    }
}
