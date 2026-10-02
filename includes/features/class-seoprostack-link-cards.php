<?php
/**
 * Link cards.
 *
 * A Link card block (seoprostack/link-card): paste an address and the editor
 * fills in the page's title, description and picture from core's
 * wp-block-editor/v1/url-details route. The picture is copied to the Media
 * Library, so visitors' browsers never contact the other site. The card is
 * built on the server from the stored attributes.
 *
 * Replaces Bookmark Card: its blocks (mamaduka/bookmark-card, saved as HTML)
 * keep showing with a matching style while Bookmark Card is inactive, and the
 * editor converts them to Link cards in one click.
 *
 * @package SEOProStack
 * @since 0.8.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Link_Cards extends SEOProStack_Feature {

    const KEY = 'link_cards';

    /** Block name. */
    const BLOCK = 'seoprostack/link-card';

    /** Bookmark Card's block. */
    const LEGACY_BLOCK = 'mamaduka/bookmark-card';

    /** Where pictures came from; shared with Auto upload images, kept on uninstall. */
    const SOURCE_META = '_seoprostack_source_url';

    /** Picture positions. */
    const POSITIONS = array('right', 'left', 'top', 'none');

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
        $host        = preg_replace('/^www\./i', '', (string) wp_parse_url($url, PHP_URL_HOST));
        $site        = '' !== $site ? $site : $host;
        $title       = '' !== $title ? $title : $url;
        $position    = isset($attributes['mediaPosition']) && in_array($attributes['mediaPosition'], self::POSITIONS, true) ? $attributes['mediaPosition'] : 'right';
        $image_id    = isset($attributes['imageId']) ? absint($attributes['imageId']) : 0;

        $image = '';
        if ('none' !== $position && $image_id && wp_attachment_is_image($image_id)) {
            $image = wp_get_attachment_image($image_id, 'top' === $position ? 'large' : 'medium', false, array(
                'class'   => 'wp-block-seoprostack-link-card__img',
                'alt'     => '',
                'loading' => 'lazy',
            ));
        }

        $rel = array();
        if (!empty($attributes['newTab'])) {
            $rel[] = 'noopener';
        }
        if (!empty($attributes['nofollow'])) {
            $rel[] = 'nofollow';
        }

        $wrapper = get_block_wrapper_attributes(array(
            'class' => 'is-media-' . ('' !== $image ? $position : 'none'),
        ));

        $html  = '<div ' . $wrapper . '>';
        $html .= '<a class="wp-block-seoprostack-link-card__link" href="' . esc_url($url) . '"';
        $html .= !empty($attributes['newTab']) ? ' target="_blank"' : '';
        $html .= $rel ? ' rel="' . esc_attr(implode(' ', $rel)) . '"' : '';
        $html .= '>';
        if ('' !== $image) {
            $html .= '<span class="wp-block-seoprostack-link-card__media">' . $image . '</span>';
        }
        $html .= '<span class="wp-block-seoprostack-link-card__body">';
        $html .= '<span class="wp-block-seoprostack-link-card__title">' . esc_html($title) . '</span>';
        if ('' !== $description) {
            $html .= '<span class="wp-block-seoprostack-link-card__description">' . esc_html($description) . '</span>';
        }
        $html .= '<span class="wp-block-seoprostack-link-card__site">' . esc_html($site) . '</span>';
        $html .= '</span></a></div>';
        return $html;
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
        return array(
            'id'  => $id,
            'src' => (string) wp_get_attachment_image_url($id, 'medium'),
        );
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
     * @param string $url     Picture address.
     * @param string $page    Address of the page it illustrates.
     * @param int    $post_id Post to attach it to.
     * @return int|WP_Error Attachment ID.
     */
    private static function copy_image($url, $page, $post_id) {
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
        $mime  = wp_get_image_mime($tmp);
        $types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif');
        if (!isset($types[$mime]) || filesize($tmp) > self::MAX_BYTES) {
            wp_delete_file($tmp);
            return new WP_Error('seoprostack_link_card_image', __('The page’s picture is not a picture WordPress can use.', 'seoprostack'));
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
