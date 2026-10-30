<?php
/**
 * Link cards.
 *
 * A Link card block (seoprostack/link-card): paste an address and the editor
 * fills in the page's title, description and picture from core's
 * wp-block-editor/v1/url-details route. The picture is copied to the Media
 * Library, so visitors' browsers never contact the other site. The card is
 * built on the server from the stored attributes. Pages without a picture
 * get the site's icon when it is at least MIN_ICON pixels square, and
 * pictures keep their shape (or a chosen one) beside the text.
 *
 * Replaces Bookmark Card: its blocks (mamaduka/bookmark-card, saved as HTML)
 * keep showing with a matching style while Bookmark Card is inactive, and the
 * editor converts them to Link cards in one click.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Link_Card_Block extends SEOProStack_Feature {

    const KEY = 'link_cards';

    /** Block name. */
    const BLOCK = 'seoprostack/link-card';

    /** Bookmark Card's block. */
    const LEGACY_BLOCK = 'mamaduka/bookmark-card';

    /** Where pictures came from; shared with Auto upload images, kept on uninstall. */
    const SOURCE_META = '_seoprostack_source_url';

    /** Picture positions. */
    const POSITIONS = array('right', 'left', 'top', 'none');

    /**
     * Picture shapes: auto (the picture's own, between square and 2:1),
     * wide (social previews' 1.91:1), square, fill (the card's height).
     */
    const SHAPES = array('auto', 'wide', 'square', 'fill');

    /** Social preview pictures' shape (1200 by 630 pixels). */
    const WIDE = 1.91;

    /** Smallest site icon used, in pixels on each side. */
    const MIN_ICON = 128;

    /** Site icon addresses tried at most. */
    const MAX_ICON_TRIES = 4;

    /** Largest picture copied, in bytes. */
    const MAX_BYTES = 8388608;

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
                'label'       => __('Link cards', 'seoprostack'),
                'description' => __('Adds a Link card block: paste an address to show the page’s title, description and picture. The picture is copied to your Media Library, so visitors never contact the other site. Bookmark Card blocks keep showing and can be converted.', 'seoprostack'),
                'replaces'    => array('bookmark-card' => 'Bookmark Card'),
            ),
        );
    }

    /**
     * Switch on while Bookmark Card is active (it has no settings).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['bookmark-card']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // boot() runs on init, which is where blocks are registered.
        register_block_type(SEOPROSTACK_DIR . 'blocks/link-card');
        self::register_legacy_block();
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_settings'));
    }

    /**
     * Keep Bookmark Card blocks showing. They are saved as HTML, so this
     * only adds their style; the editor script registers their editor side.
     */
    private static function register_legacy_block() {
        if (WP_Block_Type_Registry::get_instance()->is_registered(self::LEGACY_BLOCK)) {
            return;
        }
        $file = 'blocks/link-card/bookmark-card.css';
        wp_register_style('seoprostack-bookmark-card', SEOPROSTACK_URL . $file, array(), (string) filemtime(SEOPROSTACK_DIR . $file));
        register_block_type(self::LEGACY_BLOCK, array(
            'title'        => 'Bookmark Card',
            'category'     => 'embed',
            'style_handles' => array('seoprostack-bookmark-card'),
            'supports'     => array('inserter' => false, 'html' => false),
        ));
    }

    /**
     * Tell the editor whether pictures can be copied.
     */
    public static function editor_settings() {
        $handle = generate_block_asset_handle(self::BLOCK, 'editorScript');
        wp_add_inline_script($handle, 'window.seoprostackLinkCards = ' . wp_json_encode(array(
            'canUpload' => current_user_can('upload_files'),
        )) . ';', 'before');
    }

    /*
     * ------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------
     */

    /**
     * Build the card.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_block(array $attributes) {
        $url = esc_url_raw(trim(isset($attributes['url']) ? (string) $attributes['url'] : ''), array('https', 'http'));
        if ('' === $url) {
            return '';
        }
        $title       = isset($attributes['title']) ? trim(wp_strip_all_tags((string) $attributes['title'])) : '';
        $description = isset($attributes['description']) ? trim(wp_strip_all_tags((string) $attributes['description'])) : '';
        $site        = isset($attributes['siteName']) ? trim(wp_strip_all_tags((string) $attributes['siteName'])) : '';
        $host        = (string) wp_parse_url($url, PHP_URL_HOST);
        $host        = preg_replace('/^www\./i', '', $host) ?? $host;
        $site        = '' !== $site ? $site : $host;
        $title       = '' !== $title ? $title : $url;
        $position    = isset($attributes['mediaPosition']) && in_array($attributes['mediaPosition'], self::POSITIONS, true) ? $attributes['mediaPosition'] : 'right';
        $shape       = isset($attributes['imageShape']) && in_array($attributes['imageShape'], self::SHAPES, true) ? $attributes['imageShape'] : 'auto';
        $icon        = isset($attributes['imageKind']) && 'icon' === $attributes['imageKind'];
        $image_id    = isset($attributes['imageId']) ? absint($attributes['imageId']) : 0;

        $image = '';
        if ('none' !== $position && $image_id && wp_attachment_is_image($image_id)) {
            $image = wp_get_attachment_image($image_id, 'top' === $position && !$icon ? 'large' : 'medium', false, array(
                'class'   => 'wp-block-seoprostack-link-card__img',
                'alt'     => '',
                'loading' => 'lazy',
            ));
        }
        $ratio = '' !== $image ? self::ratio($shape, $icon, $image_id) : 0.0;

        $rel = array();
        if (!empty($attributes['newTab'])) {
            $rel[] = 'noopener';
        }
        if (!empty($attributes['nofollow'])) {
            $rel[] = 'nofollow';
        }

        $classes = array('is-media-' . ('' !== $image ? $position : 'none'));
        if ('' !== $image) {
            $classes[] = 'is-shape-' . $shape;
            if ($icon) {
                $classes[] = 'is-image-icon';
            }
        }
        $wrapper = get_block_wrapper_attributes(array(
            'class' => implode(' ', $classes),
        ));

        $html  = '<div ' . $wrapper . '>';
        $html .= '<a class="wp-block-seoprostack-link-card__link" href="' . esc_url($url) . '"';
        $html .= !empty($attributes['newTab']) ? ' target="_blank"' : '';
        $html .= $rel ? ' rel="' . esc_attr(implode(' ', $rel)) . '"' : '';
        $html .= '>';
        if ('' !== $image) {
            $style = $ratio > 0 ? ' style="--sps-link-card-ratio:' . esc_attr(self::ratio_css($ratio)) . '"' : '';
            $html .= '<span class="wp-block-seoprostack-link-card__media"' . $style . '>' . $image . '</span>';
        }
        $html .= '<span class="wp-block-seoprostack-link-card__body">';
        $html .= '<span class="wp-block-seoprostack-link-card__title">' . esc_html($title) . '</span>';
        if ('' !== $description) {
            $html .= '<span class="wp-block-seoprostack-link-card__description">' . esc_html($description) . '</span>';
        }
        // sps-external-link-after: External link icons puts its icon after the site name, picture or not.
        $html .= '<span class="wp-block-seoprostack-link-card__site sps-external-link-after">' . esc_html($site) . '</span>';
        $html .= '</span></a></div>';
        return $html;
    }

    /**
     * Width over height of the picture's frame; 0 fills the card's height.
     * Auto keeps the picture's own shape, between square and 2:1, so social
     * preview pictures and logos show whole; icons are square.
     *
     * @param string $shape    One of self::SHAPES.
     * @param bool   $icon     Whether the picture is the site's icon.
     * @param int    $image_id Attachment ID.
     * @return float
     */
    private static function ratio($shape, $icon, $image_id) {
        if ('fill' === $shape) {
            return 0.0;
        }
        if ('square' === $shape || ('auto' === $shape && $icon)) {
            return 1.0;
        }
        if ('wide' === $shape) {
            return self::WIDE;
        }
        $full = wp_get_attachment_image_src($image_id, 'full');
        if (!$full || empty($full[1]) || empty($full[2])) {
            return self::WIDE;
        }
        return max(1.0, min(2.0, (int) $full[1] / (int) $full[2]));
    }

    /**
     * A ratio for CSS, with a dot whatever the locale.
     *
     * @param float $ratio Width over height.
     * @return string
     */
    private static function ratio_css($ratio) {
        return rtrim(rtrim(number_format($ratio, 3, '.', ''), '0'), '.');
    }

    /*
     * ------------------------------------------------------------------
     * Editor
     * ------------------------------------------------------------------
     */

    /**
     * REST route that copies a card's picture to the Media Library.
     */
    public static function register_routes() {
        register_rest_route('seoprostack/v1', '/link-card-image', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_image'),
            'permission_callback' => function () {
                return current_user_can('upload_files');
            },
            'args'                => array(
                'image' => array('type' => 'string', 'required' => true),
                'page'  => array('type' => 'string', 'default' => ''),
                'post'  => array('type' => 'integer', 'default' => 0),
            ),
        ));
        register_rest_route('seoprostack/v1', '/link-card-icon', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_icon'),
            'permission_callback' => function () {
                return current_user_can('upload_files');
            },
            'args'                => array(
                'page' => array('type' => 'string', 'required' => true),
                'post' => array('type' => 'integer', 'default' => 0),
            ),
        ));
    }

    /**
     * REST: copy a picture (or reuse an earlier copy) and describe it.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function rest_image($request) {
        $image = esc_url_raw(trim((string) $request['image']), array('https', 'http'));
        if ('' === $image) {
            return new WP_Error('seoprostack_link_card_image', __('The picture’s address is not valid.', 'seoprostack'), array('status' => 400));
        }
        $post_id = (int) $request['post'];
        $post_id = $post_id && current_user_can('edit_post', $post_id) ? $post_id : 0;

        $id = self::find_existing($image);
        if (!$id) {
            $id = self::copy_image($image, (string) $request['page'], $post_id);
            if (is_wp_error($id)) {
                $id->add_data(array('status' => 502));
                return $id;
            }
        }
        return self::describe($id);
    }

    /**
     * REST: copy the site's largest icon, when it is at least MIN_ICON
     * pixels on each side (or reuse an earlier copy), and describe it.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function rest_icon($request) {
        $page = esc_url_raw(trim((string) $request['page']), array('https', 'http'));
        if ('' === $page) {
            return new WP_Error('seoprostack_link_card_icon', __('The page’s address is not valid.', 'seoprostack'), array('status' => 400));
        }
        $post_id = (int) $request['post'];
        $post_id = $post_id && current_user_can('edit_post', $post_id) ? $post_id : 0;

        foreach (self::icon_candidates($page) as $icon) {
            $id = self::find_existing($icon);
            if ($id && !self::big_enough($id)) {
                continue;
            }
            if (!$id) {
                $id = self::copy_image($icon, $page, $post_id, self::MIN_ICON);
                if (is_wp_error($id)) {
                    continue;
                }
            }
            return self::describe($id);
        }
        return new WP_Error('seoprostack_link_card_icon', __('The site has no icon big enough for a card.', 'seoprostack'), array('status' => 404));
    }

    /**
     * The REST answer for a copied picture.
     *
     * @param int $id Attachment ID.
     * @return array
     */
    private static function describe($id) {
        return array(
            'id'  => $id,
            'src' => (string) (class_exists('SEOProStack_Media_Storage') ? SEOProStack_Media_Storage::local_url($id, 'medium') : wp_get_attachment_image_url($id, 'medium')),
        );
    }

    /**
     * Whether an attachment is at least MIN_ICON pixels on each side.
     *
     * @param int $id Attachment ID.
     * @return bool
     */
    private static function big_enough($id) {
        $full = wp_get_attachment_image_src($id, 'full');
        return $full && min((int) $full[1], (int) $full[2]) >= self::MIN_ICON;
    }

    /**
     * The site's icons that may be large enough, largest first: the page's
     * icon and Apple touch icon links, Windows' tile picture (WordPress's
     * site icon at 270 pixels), its web app manifest, then the usual
     * /apple-touch-icon.png. Icons said to be smaller, SVG and ICO files
     * are left out; ones of unknown size are tried last.
     *
     * @param string $page Page address.
     * @return string[]
     */
    private static function icon_candidates($page) {
        $parts = wp_parse_url($page);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return array();
        }
        $found    = array();
        $manifest = '';
        $html     = self::fetch($page, 512 * KB_IN_BYTES);
        if ('' !== $html && class_exists('WP_HTML_Tag_Processor')) {
            $tags = new WP_HTML_Tag_Processor($html);
            while ($tags->next_tag()) {
                $tag = $tags->get_tag();
                if ('BODY' === $tag) {
                    break;
                }
                if ('META' === $tag && 'msapplication-tileimage' === strtolower(trim((string) $tags->get_attribute('name')))) {
                    self::add_icon($found, WP_Http::make_absolute_url(trim((string) $tags->get_attribute('content')), $page), '', '', 270);
                    continue;
                }
                if ('LINK' !== $tag) {
                    continue;
                }
                $rels = preg_split('/\s+/', strtolower(trim((string) $tags->get_attribute('rel'))));
                $href = trim((string) $tags->get_attribute('href'));
                if ('' === $href || !$rels) {
                    continue;
                }
                $href = WP_Http::make_absolute_url($href, $page);
                if (in_array('manifest', $rels, true)) {
                    $manifest = $href;
                } elseif (array_intersect($rels, array('icon', 'apple-touch-icon', 'apple-touch-icon-precomposed'))) {
                    // Apple touch icons without sizes are 180 pixels.
                    self::add_icon($found, $href, (string) $tags->get_attribute('sizes'), (string) $tags->get_attribute('type'), in_array('icon', $rels, true) ? 0 : 180);
                }
            }
        }
        if ('' !== $manifest) {
            $data = json_decode(self::fetch($manifest, 64 * KB_IN_BYTES), true);
            if (is_array($data) && isset($data['icons']) && is_array($data['icons'])) {
                foreach ($data['icons'] as $item) {
                    if (is_array($item) && !empty($item['src']) && is_string($item['src'])) {
                        self::add_icon($found, WP_Http::make_absolute_url($item['src'], $manifest), isset($item['sizes']) ? (string) $item['sizes'] : '', isset($item['type']) ? (string) $item['type'] : '', 0);
                    }
                }
            }
        }
        // Tried after icons said to be large, before ones of unknown size.
        $fallback = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/apple-touch-icon.png';
        if (!isset($found[$fallback])) {
            $found[$fallback] = 1;
        }
        arsort($found);
        return array_slice(array_keys($found), 0, self::MAX_ICON_TRIES);
    }

    /**
     * Note an icon, keeping its largest stated size.
     *
     * @param array  $found   Icon address => size (0 when unknown).
     * @param string $url     Icon address.
     * @param string $sizes   Its sizes attribute (such as "180x180").
     * @param string $type    Its type attribute.
     * @param int    $default Its size when sizes says nothing.
     */
    private static function add_icon(array &$found, $url, $sizes, $type, $default) {
        $url = esc_url_raw($url, array('https', 'http'));
        if ('' === $url || false !== stripos($type, 'svg') || false !== stripos($type, 'icon') || preg_match('/\.(svg|ico)$/i', (string) wp_parse_url($url, PHP_URL_PATH))) {
            return;
        }
        $size = $default;
        if (preg_match_all('/(\d+)x(\d+)/i', $sizes, $pairs, PREG_SET_ORDER)) {
            $size = 0;
            foreach ($pairs as $pair) {
                $size = max($size, min((int) $pair[1], (int) $pair[2]));
            }
        }
        if ($size > 0 && $size < self::MIN_ICON) {
            return;
        }
        $found[$url] = max(isset($found[$url]) ? $found[$url] : 0, $size);
    }

    /**
     * Read a page or file from another site, as core's link preview route
     * does (its request filter applies), refusing local addresses.
     *
     * @param string $url   Address.
     * @param int    $limit Most bytes read.
     * @return string Empty on failure.
     */
    private static function fetch($url, $limit) {
        $args = apply_filters('rest_url_details_http_request_args', array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, so sites' settings for link previews apply here too.
            'limit_response_size' => $limit,
            'timeout'             => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- only in the editor, when someone adds a card; another site's page may be slow.
            'user-agent'          => 'WP-URLDetails/' . get_bloginfo('version') . ' (+' . get_bloginfo('url') . ')',
        ), $url);
        $response = wp_safe_remote_get($url, $args);
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return '';
        }
        return (string) wp_remote_retrieve_body($response);
    }

    /**
     * An attachment copied earlier from the same address.
     *
     * @param string $url Picture address.
     * @return int
     */
    private static function find_existing($url) {
        $ids = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
        ));
        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * Download a picture into the Media Library.
     *
     * @param string $url      Picture address.
     * @param string $page     Address of the page it illustrates.
     * @param int    $post_id  Post to attach it to.
     * @param int    $min_side Fewest pixels on each side, or 0.
     * @return int|WP_Error Attachment ID.
     */
    private static function copy_image($url, $page, $post_id, $min_side = 0) {
        // download_url() uses wp_safe_remote_get(), which refuses local and
        // private addresses.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            /* translators: %s: error message */
            return new WP_Error('seoprostack_link_card_image', sprintf(__('The picture could not be copied: %s', 'seoprostack'), $tmp->get_error_message()));
        }
        // Some sites answer missing files with an empty page: too short for
        // exif_imagetype(), which would log a notice.
        $bytes = (int) filesize($tmp);
        $mime  = $bytes >= 12 && $bytes <= self::MAX_BYTES ? wp_get_image_mime($tmp) : false;
        $types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif');
        if (!$mime || !isset($types[$mime])) {
            wp_delete_file($tmp);
            return new WP_Error('seoprostack_link_card_image', __('The page’s picture is not a picture WordPress can use.', 'seoprostack'));
        }
        if ($min_side > 0) {
            $dimensions = wp_getimagesize($tmp);
            if (!$dimensions || min((int) $dimensions[0], (int) $dimensions[1]) < $min_side) {
                wp_delete_file($tmp);
                return new WP_Error('seoprostack_link_card_image', __('The icon is too small for a card.', 'seoprostack'));
            }
        }

        $host = preg_replace('/^www\./i', '', (string) wp_parse_url('' !== $page ? $page : $url, PHP_URL_HOST));
        $name = substr(sanitize_title('link-card-' . $host . '-' . pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)), 0, 80);
        $id   = media_handle_sideload(
            array('name' => $name . '.' . $types[$mime], 'tmp_name' => $tmp),
            $post_id,
            null,
            /* translators: %s: domain name */
            array('post_title' => sprintf(__('Picture from %s', 'seoprostack'), $host))
        );
        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            return $id;
        }
        update_post_meta($id, self::SOURCE_META, $url);
        return (int) $id;
    }
}
