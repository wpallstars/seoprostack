<?php
/**
 * Brand icons.
 *
 * A Brand icon block and the [simple_icon] shortcode (and #name# menu item
 * titles) with about 3,800 brand icons: the current Simple Icons set, plus
 * Font Awesome Free brand icons for brands Simple Icons does not have.
 *
 * Icon shapes live in a few dozen shapes-{n}.json files in assets/brand-icons/
 * (index.json says which), read and inlined as SVG on the server: no icon font, no request to another site and no script or
 * stylesheet on the front end. The editor searches through a REST route and
 * never loads the whole set. scripts/update-brand-icons.sh refreshes it.
 *
 * Replaces Popular Brand Icons – Simple Icons (its set dates from 2022). Its
 * shortcode and menu titles keep working once it is deactivated.
 *
 * @package SEOProStack
 * @since 0.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Brand_Icons extends SEOProStack_Feature {

    const KEY = 'brand_icons';

    /** Block name. */
    const BLOCK = 'seoprostack/brand-icon';

    /** Icons returned by one search. */
    const PER_PAGE = 60;

    /** Icon names in Kadence Blocks: sps_{slug}. */
    const KADENCE_PREFIX = 'sps_';

    /** Shapes of the icons used in Kadence blocks, kept for the front end. */
    const KADENCE_OPTION = 'seoprostack_kadence_brand_icons';

    /** Most icons kept in that option. */
    const KADENCE_MAX = 300;

    /**
     * Kept shapes: slug => [viewBox, path, ...] (null until read).
     *
     * @var array|null
     */
    private static $kadence_icons = null;

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
                'label'       => __('Brand icons', 'seoprostack'),
                'description' => __('A Brand icon block with about 3,800 brand logos (Simple Icons and Font Awesome), in the brand’s colour or your text colour. Icons are part of the page, so nothing extra loads. The [simple_icon] shortcode and #name# menu titles keep working.', 'seoprostack'),
                'replaces'    => array('simple-icons' => 'Popular Brand Icons – Simple Icons'),
            ),
        );
    }

    /**
     * Switch on while Simple Icons is active (it has no settings).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['simple-icons']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // boot() runs on init, which is where blocks are registered.
        register_block_type(SEOPROSTACK_DIR . 'blocks/brand-icon', array('render_callback' => array(__CLASS__, 'render_block')));
        // After Simple Icons would have added its own, so ours are used only when it is gone.
        add_action('init', array(__CLASS__, 'register_shortcode'), 20);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        if (defined('KADENCE_BLOCKS_VERSION')) {
            add_filter('kadence_svg_icons', array(__CLASS__, 'kadence_icons'));
            add_filter('render_block_data', array(__CLASS__, 'kadence_block_data'));
            add_action('wp_after_insert_post', array(__CLASS__, 'kadence_saved'), 10, 2);
            add_action('enqueue_block_editor_assets', array(__CLASS__, 'kadence_editor'));
        }
    }

    /**
     * [simple_icon] and #name# menu titles, while Simple Icons is not active.
     */
    public static function register_shortcode() {
        if (shortcode_exists('simple_icon')) {
            return;
        }
        add_shortcode('simple_icon', array(__CLASS__, 'shortcode'));
        add_filter('wp_nav_menu_objects', array(__CLASS__, 'menu_items'));
    }

    /*
     * ------------------------------------------------------------------
     * Icons
     * ------------------------------------------------------------------
     */

    /**
     * A name as a slug, as Simple Icons makes them: "Node.js" => nodedotjs,
     * "C++" => cplusplus. Popular Brand Icons' names ("node-dot-js") match too.
     *
     * @param string $name Name or slug.
     * @return string
     */
    public static function slug($name) {
        $name = strtolower(trim(str_replace('#', '', (string) $name)));
        if (preg_match('/^[a-z0-9_]+$/', $name)) {
            return $name;
        }
        $name = str_replace(array('+', '.', '&'), array('plus', 'dot', 'and'), $name);
        return preg_replace('/[^a-z0-9]/', '', remove_accents($name));
    }

    /**
     * The icon list, aliases and source versions (read only when needed).
     *
     * @return array
     */
    private static function index() {
        static $index = null;
        if (null === $index) {
            $json  = file_get_contents(SEOPROSTACK_DIR . 'assets/brand-icons/index.json'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            $index = $json ? json_decode($json, true) : null;
            if (!is_array($index) || !isset($index['icons'])) {
                $index = array('icons' => array(), 'aliases' => array(), 'sources' => array());
            }
        }
        return $index;
    }

    /**
     * Find an icon by name, slug or old name.
     *
     * @param string $name Name.
     * @return string Slug, or '' if there is no such icon.
     */
    public static function find($name) {
        $slug = self::slug($name);
        if ('' === $slug) {
            return '';
        }
        if (self::index_row($slug)) {
            return $slug;
        }
        $index = self::index();
        $alias = isset($index['aliases'][$slug]) ? (string) $index['aliases'][$slug] : '';
        return '' !== $alias && self::index_row($alias) ? $alias : '';
    }

    /**
     * The shapes in one shapes-{n}.json file: slug => [viewBox, path, ...].
     *
     * @param int $shard Shard number from the icon's index row.
     * @return array
     */
    private static function shapes($shard) {
        static $files = array();
        $shard = (int) $shard;
        if (!isset($files[$shard])) {
            $file = SEOPROSTACK_DIR . 'assets/brand-icons/shapes-' . $shard . '.json';
            $json = is_readable($file) ? file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            $data = $json ? json_decode($json, true) : null;
            $files[$shard] = is_array($data) ? $data : array();
        }
        return $files[$shard];
    }

    /**
     * An icon's shape: viewBox, path data, title and brand colour.
     *
     * @param string $slug Slug (from find()).
     * @return array|null
     */
    public static function icon($slug) {
        static $cache = array();
        if (isset($cache[$slug])) {
            return $cache[$slug];
        }
        $row = preg_match('/^[a-z0-9_]+$/', (string) $slug) ? self::index_row($slug) : array();
        if (count($row) < 5) {
            return null;
        }
        $shapes = self::shapes($row[4]);
        $shape  = isset($shapes[$slug]) && is_array($shapes[$slug]) ? array_values($shapes[$slug]) : array();
        $box    = isset($shape[0]) ? (string) $shape[0] : '';
        $paths  = array();
        foreach (array_slice($shape, 1) as $d) {
            // Path data only: numbers, commands, spaces and separators.
            if (is_string($d) && '' !== $d && preg_match('/^[0-9A-Za-z.,\s+-]+$/', $d)) {
                $paths[] = $d;
            }
        }
        if (!preg_match('/^[0-9.\s-]+$/', $box) || !$paths) {
            return null;
        }
        $hex          = (string) $row[2];
        $cache[$slug] = array(
            'slug'    => $slug,
            'title'   => (string) $row[1],
            'hex'     => preg_match('/^[0-9A-Fa-f]{6}$/', $hex) ? $hex : '',
            'viewBox' => trim($box),
            'paths'   => $paths,
        );
        return $cache[$slug];
    }

    /**
     * An icon's row in the index: [slug, title, hex, source, shard].
     *
     * @param string $slug Slug.
     * @return array
     */
    private static function index_row($slug) {
        static $rows = null;
        if (null === $rows) {
            $rows = array();
            foreach (self::index()['icons'] as $row) {
                $rows[$row[0]] = $row;
            }
        }
        return isset($rows[$slug]) ? $rows[$slug] : array();
    }

    /**
     * The colour to draw a brand in. Near-black and near-white brand colours
     * (GitHub, X, Apple) are one-colour logos, so they take the text colour
     * and stay visible in dark mode.
     *
     * @param string $hex Brand colour, six hex digits or ''.
     * @return string
     */
    public static function brand_color($hex) {
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', (string) $hex)) {
            return 'currentColor';
        }
        $light = 0.0;
        foreach (array(0 => 0.2126, 2 => 0.7152, 4 => 0.0722) as $at => $weight) {
            $c      = hexdec(substr($hex, $at, 2)) / 255;
            $light += $weight * ($c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4));
        }
        return $light < 0.02 || $light > 0.85 ? 'currentColor' : '#' . $hex;
    }

    /**
     * A CSS colour from a shortcode or block, or ''. Hex digits without
     * "#" are accepted, as Simple Icons' shortcode did.
     *
     * @param string $color Colour.
     * @return string
     */
    public static function css_color($color) {
        $color = trim((string) $color);
        if (preg_match('/^(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color)) {
            return '#' . $color;
        }
        if (preg_match('/^(?:#[0-9a-f]{3,8}|[a-z]+|var\(--[a-z0-9_-]+\)|(?:rgb|hsl)a?\([0-9.,%\s\/deg]+\))$/i', $color)) {
            return $color;
        }
        return '';
    }

    /**
     * A CSS length from a shortcode or block ("24", "24px", "1.5rem"), or ''.
     *
     * @param string|int $size Size.
     * @return string
     */
    public static function css_size($size) {
        $size = trim((string) $size);
        if (preg_match('/^\d+(?:\.\d+)?$/', $size)) {
            return $size . 'px';
        }
        return preg_match('/^\d+(?:\.\d+)?(?:px|em|rem|%|vw|vh|ch|ex)$/', $size) ? $size : '';
    }

    /**
     * An icon's HTML.
     *
     * @param string $slug Slug (from find()).
     * @param array  $args color (CSS, '' for the brand colour), size (CSS),
     *                     class, label (accessible name; '' to hide it from
     *                     screen readers), title (bool: <title> tooltip).
     * @return string
     */
    public static function html($slug, array $args = array()) {
        $icon = self::icon($slug);
        if (!$icon) {
            return '';
        }
        $args  = wp_parse_args($args, array('color' => '', 'size' => '', 'class' => '', 'label' => $icon['title'], 'title' => true));
        $color = '' !== $args['color'] ? $args['color'] : self::brand_color($icon['hex']);
        $size  = '' !== $args['size'] ? $args['size'] : '1.5rem';
        $class = 'sps-brand-icon simple-icon-' . $slug;
        foreach (preg_split('/\s+/', (string) $args['class']) ?: array() as $extra) {
            $extra = sanitize_html_class($extra);
            $class .= '' !== $extra ? ' ' . $extra : '';
        }
        $label = trim((string) $args['label']);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . esc_attr($icon['viewBox']) . '" width="100%" height="100%" style="display:block"';
        $svg .= '' !== $label ? ' role="img" aria-label="' . esc_attr($label) . '"' : ' aria-hidden="true" focusable="false"';
        $svg .= '>';
        if ('' !== $label && $args['title']) {
            $svg .= '<title>' . esc_html($label) . '</title>';
        }
        foreach ($icon['paths'] as $d) {
            $svg .= '<path d="' . esc_attr($d) . '"/>';
        }
        $svg .= '</svg>';

        $style = 'display:inline-block;vertical-align:middle;line-height:0;width:' . $size . ';height:' . $size . ';fill:' . $color;
        return '<span class="' . esc_attr($class) . '" style="' . esc_attr($style) . '">' . $svg . '</span>';
    }

    /*
     * ------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------
     */

    /**
     * Render the Brand icon block.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_block($attributes) {
        $slug = self::find(isset($attributes['icon']) ? (string) $attributes['icon'] : '');
        if ('' === $slug) {
            return '';
        }
        $icon = self::icon($slug);
        $url  = isset($attributes['url']) ? esc_url_raw(trim((string) $attributes['url'])) : '';
        $name = isset($attributes['label']) && '' !== trim((string) $attributes['label']) ? trim(wp_strip_all_tags((string) $attributes['label'])) : $icon['title'];
        $size = self::css_size(isset($attributes['size']) ? $attributes['size'] : 32);

        $html = self::html($slug, array(
            // Without the brand colour, the icon takes the text colour (block or theme).
            'color' => isset($attributes['brandColor']) && !$attributes['brandColor'] ? 'currentColor' : '',
            'size'  => '' !== $size ? $size : '32px',
            // A link's name is the icon's; a tooltip only without a link.
            'label' => $name,
            'title' => '' === $url,
        ));
        if ('' !== $url) {
            $new  = !empty($attributes['newTab']);
            $html = '<a href="' . esc_url($url) . '"' . ($new ? ' target="_blank" rel="noopener"' : '') . ' style="color:inherit;line-height:0;display:inline-block">' . $html . '</a>';
        }
        return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
    }

    /**
     * [simple_icon name="" color="" size="" class="" title_tag=""].
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts(array(
            'name'      => '',
            'color'     => '',
            'size'      => '',
            'class'     => '',
            'cache'     => 'true',
            'title_tag' => 'true',
        ), (array) $atts, 'simple_icon');
        $slug = self::find($atts['name']);
        if ('' === $slug) {
            return '';
        }
        $title = !in_array(strtolower((string) $atts['title_tag']), array('false', '0', 'no', ''), true);
        return self::html($slug, array(
            'color' => self::css_color($atts['color']),
            'size'  => self::css_size($atts['size']),
            'class' => (string) $atts['class'],
            'title' => $title,
            // Without the tooltip (menus with a title attribute), still named.
            'label' => self::icon($slug)['title'],
        ));
    }

    /**
     * Menu items titled "#name#" show that icon, as with Simple Icons.
     *
     * @param array<int, WP_Post|stdClass> $items Menu items.
     * @return array<int, WP_Post|stdClass>
     */
    public static function menu_items($items) {
        foreach ($items as $item) {
            if (!isset($item->title) || 2 !== substr_count((string) $item->title, '#') || !preg_match('/^\s*#([^#]+)#\s*$/', (string) $item->title, $m)) {
                continue;
            }
            $html = self::shortcode(array(
                'name'      => $m[1],
                // The item's title attribute already names the link.
                'title_tag' => !isset($item->post_excerpt) || '' === (string) $item->post_excerpt ? 'true' : 'false',
            ));
            if ('' !== $html) {
                $classes         = isset($item->classes) ? (array) $item->classes : array();
                $classes[]       = 'simple-icon';
                $item->title     = $html;
                $item->classes   = $classes;
            }
        }
        return $items;
    }

    /*
     * ------------------------------------------------------------------
     * Editor
     * ------------------------------------------------------------------
     */

    /**
     * REST route the block searches icons through.
     */
    public static function register_routes() {
        register_rest_route('seoprostack/v1', '/brand-icons', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'rest_search'),
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
            'args'                => array(
                'search' => array('type' => 'string', 'default' => ''),
                'slug'   => array('type' => 'string', 'default' => ''),
            ),
        ));
    }

    /**
     * REST: icons matching a search (best matches first), or one by slug.
     *
     * @param WP_REST_Request $request Request.
     * @return array
     */
    public static function rest_search($request) {
        $index = self::index();
        $found = array();
        if ('' !== (string) $request['slug']) {
            $slug  = self::find((string) $request['slug']);
            $found = '' !== $slug ? array($slug) : array();
        } else {
            $query  = self::slug((string) $request['search']);
            $text   = strtolower(trim((string) $request['search']));
            // Exact, starts with, contains; alphabetical within each.
            $ranked = array(array(), array(), array(), array());
            if ('' !== $query && isset($index['aliases'][$query])) {
                $ranked[0][] = (string) $index['aliases'][$query];
            }
            foreach ($index['icons'] as $row) {
                $slug  = (string) $row[0];
                $title = strtolower((string) $row[1]);
                if ('' === $query) {
                    $rank = 3;
                } elseif ($slug === $query || $title === $text) {
                    $rank = 0;
                } elseif (0 === strpos($slug, $query) || 0 === strpos($title, $text)) {
                    $rank = 1;
                } elseif (false !== strpos($slug, $query) || ('' !== $text && false !== strpos($title, $text))) {
                    $rank = 2;
                } else {
                    continue;
                }
                $ranked[$rank][] = $slug;
            }
            $found = array_slice(array_values(array_unique(array_merge($ranked[0], $ranked[1], $ranked[2], $ranked[3]))), 0, self::PER_PAGE);
        }
        $icons = array();
        foreach ($found as $slug) {
            $icon = self::icon($slug);
            if ($icon) {
                $icons[] = $icon;
            }
        }
        return array('icons' => $icons, 'total' => count($index['icons']));
    }

    /*
     * ------------------------------------------------------------------
     * Kadence Blocks
     *
     * Kadence's icon pickers get a Brand icons category (icon names
     * sps_{slug}). Kadence draws icons on the server from one list it
     * builds the first time a request needs an icon, so the shapes of the
     * icons in use are kept in an option (not autoloaded): pages read that,
     * never the icon files. Icons are added when a post is saved and when a
     * page shows one that is not kept yet.
     * ------------------------------------------------------------------
     */

    /**
     * Brand icons named in some block markup or attributes.
     *
     * @param string $text Text.
     * @return string[] Slugs.
     */
    private static function kadence_names($text) {
        if (false === strpos($text, self::KADENCE_PREFIX) || !preg_match_all('/(?<![a-z0-9_])' . self::KADENCE_PREFIX . '([a-z0-9_]{1,40})/', $text, $m)) {
            return array();
        }
        $found = array();
        foreach (array_unique($m[1]) as $slug) {
            if (self::index_row($slug)) {
                $found[] = $slug;
            }
        }
        return $found;
    }

    /**
     * Kept shapes, refreshed from the icon files after an update.
     *
     * @return array slug => [viewBox, path, ...]
     */
    private static function kadence_kept() {
        if (null !== self::$kadence_icons) {
            return self::$kadence_icons;
        }
        $stored = get_option(self::KADENCE_OPTION, array());
        $icons  = is_array($stored) && isset($stored['icons']) && is_array($stored['icons']) ? $stored['icons'] : array();
        if ($icons && (!isset($stored['version']) || SEOPROSTACK_VERSION !== $stored['version'])) {
            self::$kadence_icons = array();
            self::kadence_keep(array_keys($icons));
            return self::$kadence_icons;
        }
        self::$kadence_icons = $icons;
        return $icons;
    }

    /**
     * Keep the shapes of these icons.
     *
     * @param string[] $slugs Slugs.
     */
    private static function kadence_keep(array $slugs) {
        $icons   = self::kadence_kept();
        $changed = false;
        foreach ($slugs as $slug) {
            $slug = (string) $slug;
            if (isset($icons[$slug]) || count($icons) >= self::KADENCE_MAX) {
                continue;
            }
            $icon = self::icon($slug);
            if ($icon) {
                $icons[$slug] = array_merge(array($icon['viewBox']), $icon['paths']);
                $changed      = true;
            }
        }
        self::$kadence_icons = $icons;
        if ($changed) {
            update_option(self::KADENCE_OPTION, array('version' => SEOPROSTACK_VERSION, 'icons' => $icons), false);
        }
    }

    /**
     * Add the kept icons to Kadence Blocks' icon list.
     *
     * @param array $icons Kadence's icons: name => {vB, cD}.
     * @return array
     */
    public static function kadence_icons($icons) {
        if (!is_array($icons)) {
            return $icons;
        }
        foreach (self::kadence_kept() as $slug => $shape) {
            $shape = array_values((array) $shape);
            $paths = array();
            foreach (array_slice($shape, 1) as $d) {
                $paths[] = array('nE' => 'path', 'aBs' => array('d' => (string) $d));
            }
            if (isset($shape[0]) && $paths) {
                $icons[self::KADENCE_PREFIX . $slug] = array('vB' => (string) $shape[0], 'cD' => $paths);
            }
        }
        return $icons;
    }

    /**
     * Keep a brand icon that a Kadence block on this page uses, before the
     * block is drawn.
     *
     * @param array $block Parsed block.
     * @return array Unchanged.
     */
    public static function kadence_block_data($block) {
        if (empty($block['blockName']) || 0 !== strpos((string) $block['blockName'], 'kadence/')) {
            return $block;
        }
        $text = (isset($block['innerHTML']) ? (string) $block['innerHTML'] : '') . (string) wp_json_encode(isset($block['attrs']) ? $block['attrs'] : array());
        $new  = array_diff(self::kadence_names($text), array_keys(self::kadence_kept()));
        if ($new) {
            self::kadence_keep($new);
        }
        return $block;
    }

    /**
     * Keep the brand icons in a saved post (posts, template parts, patterns
     * and Kadence elements are all posts).
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function kadence_saved($post_id, $post) {
        if (!$post instanceof WP_Post || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }
        $new = array_diff(self::kadence_names((string) $post->post_content), array_keys(self::kadence_kept()));
        if ($new) {
            self::kadence_keep($new);
        }
    }

    /**
     * Editor: the Brand icons category in Kadence's icon pickers. Icons in
     * use come with the page, so blocks show them at once; the rest of the
     * set loads from the icon files (cached by the browser) in the background.
     */
    public static function kadence_editor() {
        $file = 'admin/js/seoprostack-kadence-brand-icons.js';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_script('seoprostack-kadence-brand-icons', SEOPROSTACK_URL . $file, array('wp-hooks'), $ver, false);
        wp_add_inline_script(
            'seoprostack-kadence-brand-icons',
            'window.seoprostackKadenceBrandIcons=' . wp_json_encode(array(
                'base'   => SEOPROSTACK_URL . 'assets/brand-icons/',
                'ver'    => SEOPROSTACK_VERSION,
                'prefix' => self::KADENCE_PREFIX,
                'label'  => __('Brand icons', 'seoprostack'),
                'icons'  => self::kadence_icons(array()),
            )) . ';',
            'before'
        );
    }
}
