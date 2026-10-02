<?php
/**
 * Brand icons.
 *
 * A Brand icon block and the [simple_icon] shortcode (and #name# menu item
 * titles) with about 3,800 brand icons: the current Simple Icons set, plus
 * Font Awesome Free brand icons for brands Simple Icons does not have.
 *
 * Each icon is one small SVG file in assets/brand-icons/, read and inlined on
 * the server: no icon font, no request to another site and no script or
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
        if (is_readable(self::file($slug))) {
            return $slug;
        }
        $index = self::index();
        $alias = isset($index['aliases'][$slug]) ? (string) $index['aliases'][$slug] : '';
        return '' !== $alias && is_readable(self::file($alias)) ? $alias : '';
    }

    /**
     * Path of an icon's file.
     *
     * @param string $slug Slug.
     * @return string
     */
    private static function file($slug) {
        return SEOPROSTACK_DIR . 'assets/brand-icons/' . $slug . '.svg';
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
        if (!preg_match('/^[a-z0-9_]+$/', $slug) || !is_readable(self::file($slug))) {
            return null;
        }
        $svg = (string) file_get_contents(self::file($slug)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if (!preg_match('/viewBox="([0-9.\s-]+)"/', $svg, $box) || !preg_match_all('/<path d="([^"<>]+)"/', $svg, $paths)) {
            return null;
        }
        $title = $slug;
        $hex   = '';
        foreach (self::index_row($slug) as $i => $value) {
            if (1 === $i) {
                $title = (string) $value;
            } elseif (2 === $i) {
                $hex = (string) $value;
            }
        }
        $cache[$slug] = array(
            'slug'    => $slug,
            'title'   => $title,
            'hex'     => preg_match('/^[0-9A-Fa-f]{6}$/', $hex) ? $hex : '',
            'viewBox' => trim($box[1]),
            'paths'   => $paths[1],
        );
        return $cache[$slug];
    }

    /**
     * An icon's row in the index: [slug, title, hex, source].
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
        $color = '' !== $args['color'] ? $args['color'] : ('' !== $icon['hex'] ? '#' . $icon['hex'] : 'currentColor');
        $size  = '' !== $args['size'] ? $args['size'] : '1.5rem';
        $class = 'sps-brand-icon simple-icon-' . $slug;
        foreach (preg_split('/\s+/', (string) $args['class']) as $extra) {
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
        ), $atts, 'simple_icon');
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
     * @param array $items Menu items.
     * @return array
     */
    public static function menu_items($items) {
        foreach ($items as $item) {
            if (!isset($item->title) || 2 !== substr_count((string) $item->title, '#') || !preg_match('/^\s*#([^#]+)#\s*$/', (string) $item->title, $m)) {
                continue;
            }
            $html = self::shortcode(array(
                'name'      => $m[1],
                // The item's title attribute already names the link.
                'title_tag' => '' === (string) $item->post_excerpt ? 'true' : 'false',
            ));
            if ('' !== $html) {
                $item->title     = $html;
                $item->classes   = (array) $item->classes;
                $item->classes[] = 'simple-icon';
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
}
