<?php
/**
 * Like, save and share.
 *
 * A Like, save and share block (and Favorites' shortcodes) with three
 * buttons: Like (a heart with a count), Save (a bookmark) and Share (the
 * device's share sheet, or copy the link). A Saved posts block lists what
 * someone saved.
 *
 * Pages stay cacheable: the buttons are printed without state, and a small
 * script (loaded only on pages that have them) asks admin-ajax.php for the
 * counts and, for logged-in people, what they liked and saved. Visitors'
 * likes and saved posts stay in their browser (localStorage); the server
 * keeps only like totals. No PHP sessions, no cookies.
 *
 * Replaces Favorites. Its like totals (`simplefavorites_count`) and logged-in
 * users' favourites (user meta `simplefavorites`) are read where SEO Pro
 * Stack has nothing stored yet; Favorites' own data is never changed.
 *
 * @package SEOProStack
 * @since 0.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Post_Reactions extends SEOProStack_Feature {

    const KEY = 'post_reactions';

    /** Post meta: like total. */
    const LIKES = '_seoprostack_likes';

    /** User option (per site): saved post IDs. */
    const SAVED = 'seoprostack_saved';

    /** User option (per site): liked post IDs. */
    const LIKED = 'seoprostack_liked';

    /** admin-ajax.php action. */
    const AJAX = 'seoprostack_reactions';

    /** Script and style handle. */
    const HANDLE = 'seoprostack-post-reactions';

    /** Most posts kept in one saved list. */
    const MAX_SAVED = 500;

    /** Like changes allowed from one address per hour. */
    const RATE = 120;

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
                'label'       => __('Like, save and share', 'seoprostack'),
                'description' => __('Like, Save and Share buttons, as on social media, from a block or after the content of the post types below. Likes are counted, saved posts are listed by the Saved posts block, and Share opens the device’s share sheet or copies the link. Works with page caching and sets no cookies: visitors’ likes and saved posts stay in their browser.', 'seoprostack'),
                'replaces'    => array('favorites' => 'Favorites'),
            ),
            'post_reactions_types' => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array(),
                'parent'  => self::KEY,
                'label'   => __('Add the buttons after the content of', 'seoprostack'),
                'options' => array('SEOProStack_Duplicate_Posts', 'post_type_options'),
            ),
        );
    }

    /**
     * Switch on while Favorites is active, and take the post types it
     * shows its button on (before or after the content).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (!isset(self::active_plugins()['favorites'])) {
            return $options;
        }
        $options = self::import_setting($options, self::KEY, true);
        $display = get_option('simplefavorites_display');
        $types   = array();
        if (is_array($display) && !empty($display['posttypes']) && is_array($display['posttypes'])) {
            // Favorites stores true on activation and "true" from its screen,
            // and compares loosely (== 'true'), so both count.
            $yes = static function ($where, $key) {
                return isset($where[$key]) && (true === $where[$key] || 'true' === $where[$key]);
            };
            foreach ($display['posttypes'] as $type => $where) {
                $where = (array) $where;
                if ($yes($where, 'display') && ($yes($where, 'after_content') || $yes($where, 'before_content'))) {
                    $types[] = (string) $type;
                }
            }
        }
        return self::import_setting($options, 'post_reactions_types', $types);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('init', array(__CLASS__, 'register'));
        // After Favorites would have added its own, so ours win only when it is gone.
        add_action('init', array(__CLASS__, 'register_shortcodes'), 20);
        add_filter('the_content', array(__CLASS__, 'after_content'), 20);
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
        add_action('wp_ajax_nopriv_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    /**
     * Register the script, style and blocks.
     */
    public static function register() {
        wp_register_script(self::HANDLE, SEOPROSTACK_URL . 'assets/post-reactions.js', array(), SEOPROSTACK_VERSION, true);
        wp_add_inline_script(self::HANDLE, 'window.seoprostackReactions = ' . wp_json_encode(self::script_config()) . ';', 'before');
        wp_register_style(self::HANDLE, SEOPROSTACK_URL . 'assets/post-reactions.css', array(), SEOPROSTACK_VERSION);
        register_block_type(SEOPROSTACK_DIR . 'blocks/post-reactions', array('render_callback' => array(__CLASS__, 'render_block')));
        register_block_type(SEOPROSTACK_DIR . 'blocks/saved-posts', array('render_callback' => array(__CLASS__, 'render_saved_block')));
    }

    /**
     * Words and addresses for the script. No user data: the page may be cached.
     *
     * @return array
     */
    private static function script_config() {
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'site'    => get_current_blog_id(),
            'i18n'    => array(
                'like'     => __('Like', 'seoprostack'),
                'liked'    => __('Liked', 'seoprostack'),
                'save'     => __('Save', 'seoprostack'),
                'saved'    => __('Saved', 'seoprostack'),
                'copied'   => __('Link copied', 'seoprostack'),
                'none'     => __('Nothing saved yet.', 'seoprostack'),
                'remove'   => __('Remove', 'seoprostack'),
                'failed'   => __('That did not work. Please try again.', 'seoprostack'),
            ),
        );
    }

    /**
     * Load the script and style on this page.
     */
    private static function enqueue() {
        wp_enqueue_script(self::HANDLE);
        wp_enqueue_style(self::HANDLE);
    }

    /*
     * ------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------
     */

    /**
     * Inline SVG icons, drawn with currentColor.
     *
     * @param string $name heart|bookmark|share.
     * @return string
     */
    private static function icon($name) {
        $paths = array(
            'heart'    => 'M12 20.5s-7.5-4.6-7.5-10.1A4.4 4.4 0 0 1 12 7.6a4.4 4.4 0 0 1 7.5 2.8c0 5.5-7.5 10.1-7.5 10.1z',
            'bookmark' => 'M6.5 3.5h11v17L12 16.6l-5.5 3.9z',
            'share'    => 'M12 3.5v11M7.5 8 12 3.5 16.5 8M5.5 12.5v7h13v-7',
        );
        return '<svg class="sps-reactions__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="' . esc_attr($paths[$name]) . '"/></svg>';
    }

    /**
     * The buttons for one post.
     *
     * @param int   $post_id Post ID.
     * @param array $show    like, save, share => bool.
     * @param string $extra  Extra wrapper attributes (already escaped HTML), or ''.
     * @return string
     */
    public static function buttons($post_id, array $show = array(), $extra = '') {
        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status || post_password_required($post)) {
            return '';
        }
        $show = wp_parse_args($show, array('like' => true, 'save' => true, 'share' => true));
        self::enqueue();
        $count = self::likes($post->ID);
        $html  = '';
        if ($show['like']) {
            $html .= '<button type="button" class="sps-reactions__button sps-reactions__like" data-sps-like aria-pressed="false">'
                . self::icon('heart')
                . '<span class="sps-reactions__label">' . esc_html__('Like', 'seoprostack') . '</span>'
                . '<span class="sps-reactions__count" data-sps-count>' . esc_html(number_format_i18n($count)) . '</span>'
                . '</button>';
        }
        if ($show['save']) {
            $html .= '<button type="button" class="sps-reactions__button sps-reactions__save" data-sps-save aria-pressed="false">'
                . self::icon('bookmark')
                . '<span class="sps-reactions__label">' . esc_html__('Save', 'seoprostack') . '</span>'
                . '</button>';
        }
        if ($show['share']) {
            $html .= '<button type="button" class="sps-reactions__button sps-reactions__share" data-sps-share data-title="' . esc_attr(wp_strip_all_tags(get_the_title($post))) . '" data-url="' . esc_url(get_permalink($post)) . '">'
                . self::icon('share')
                . '<span class="sps-reactions__label">' . esc_html__('Share', 'seoprostack') . '</span>'
                . '</button>';
        }
        if ('' === $html) {
            return '';
        }
        if ('' === $extra) {
            $extra = 'class="sps-reactions"';
        }
        return '<div ' . $extra . ' data-sps-reactions="' . (int) $post->ID . '" role="group" aria-label="' . esc_attr__('Like, save and share', 'seoprostack') . '">' . $html . '<span class="sps-reactions__status" role="status" aria-live="polite"></span></div>';
    }

    /**
     * Render the Like, save and share block.
     *
     * @param array    $attributes Block attributes.
     * @param string   $content    Inner content.
     * @param WP_Block $block      Block.
     * @return string
     */
    public static function render_block($attributes, $content = '', $block = null) {
        $post_id = $block && isset($block->context['postId']) ? (int) $block->context['postId'] : (int) get_the_ID();
        $extra   = get_block_wrapper_attributes(array('class' => 'sps-reactions'));
        return self::buttons($post_id, array(
            'like'  => !isset($attributes['showLike']) || !empty($attributes['showLike']),
            'save'  => !isset($attributes['showSave']) || !empty($attributes['showSave']),
            'share' => !isset($attributes['showShare']) || !empty($attributes['showShare']),
        ), $extra);
    }

    /**
     * Render the Saved posts block: filled in by the script.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_saved_block($attributes) {
        return self::saved_list(array(
            'empty'     => isset($attributes['emptyText']) ? (string) $attributes['emptyText'] : '',
            'excerpts'  => !empty($attributes['showExcerpt']),
            'buttons'   => !empty($attributes['showRemove']),
        ), get_block_wrapper_attributes(array('class' => 'sps-saved')));
    }

    /**
     * A saved posts list; the script fills it.
     *
     * @param array  $args  empty, excerpts, buttons, types.
     * @param string $extra Wrapper attributes (escaped), or ''.
     * @return string
     */
    private static function saved_list(array $args, $extra = '') {
        self::enqueue();
        $args = wp_parse_args($args, array('empty' => '', 'excerpts' => false, 'buttons' => true, 'types' => ''));
        if ('' === $extra) {
            $extra = 'class="sps-saved"';
        }
        return '<div ' . $extra . ' data-sps-saved'
            . ' data-empty="' . esc_attr($args['empty']) . '"'
            . ' data-excerpts="' . ($args['excerpts'] ? '1' : '0') . '"'
            . ' data-buttons="' . ($args['buttons'] ? '1' : '0') . '"'
            . ' data-types="' . esc_attr($args['types']) . '"'
            . ' aria-live="polite"></div>';
    }

    /**
     * Buttons after the content of the chosen post types, on their own page.
     *
     * @param string $content Content.
     * @return string
     */
    public static function after_content($content) {
        if (!is_singular() || !in_the_loop() || !is_main_query() || doing_filter('get_the_excerpt') || is_feed()) {
            return $content;
        }
        $post = get_post();
        if (!$post || !in_array($post->post_type, (array) SEOProStack_Settings::get('post_reactions_types'), true)) {
            return $content;
        }
        // Not twice: the block or a shortcode is already there, or Favorites
        // is still running and adds its own button (until it is deactivated).
        if (has_block('seoprostack/post-reactions', $post) || has_shortcode($post->post_content, 'favorite_button') || self::favorites_running()) {
            return $content;
        }
        return $content . self::buttons($post->ID);
    }

    /*
     * ------------------------------------------------------------------
     * Favorites' shortcodes
     * ------------------------------------------------------------------
     */

    /**
     * Favorites' shortcodes, when it is not active (it registers them itself).
     */
    public static function register_shortcodes() {
        $map = array(
            'favorite_button'        => 'sc_button',
            'favorite_count'         => 'sc_count',
            'user_favorites'         => 'sc_list',
            'user_favorite_count'    => 'sc_user_count',
            'clear_favorites_button' => 'sc_clear',
        );
        foreach ($map as $tag => $method) {
            if (!shortcode_exists($tag)) {
                add_shortcode($tag, array(__CLASS__, $method));
            }
        }
    }

    /**
     * Whether Favorites is loaded on this page (its shortcodes are its own).
     *
     * @return bool
     */
    private static function favorites_running() {
        global $shortcode_tags;
        return isset($shortcode_tags['favorite_button']) && array(__CLASS__, 'sc_button') !== $shortcode_tags['favorite_button'];
    }

    /**
     * Post ID from a shortcode's post_id, or the current post.
     *
     * @param array $atts Attributes.
     * @return int
     */
    private static function sc_post($atts) {
        return !empty($atts['post_id']) ? absint($atts['post_id']) : (int) get_the_ID();
    }

    /**
     * [favorite_button post_id=""]: the Like and Save buttons.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function sc_button($atts) {
        $atts = shortcode_atts(array('post_id' => '', 'site_id' => '', 'group_id' => ''), $atts, 'favorite_button');
        return self::buttons(self::sc_post($atts), array('share' => false));
    }

    /**
     * [favorite_count post_id=""]: the like total, kept current by the script.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function sc_count($atts) {
        $atts    = shortcode_atts(array('post_id' => '', 'site_id' => ''), $atts, 'favorite_count');
        $post_id = self::sc_post($atts);
        if (!$post_id) {
            return '';
        }
        self::enqueue();
        return '<span class="sps-reactions__total" data-sps-total="' . (int) $post_id . '">' . esc_html(number_format_i18n(self::likes($post_id))) . '</span>';
    }

    /**
     * [user_favorites]: the saved posts list. Favorites' user_id, site_id and
     * thumbnail options are ignored: each person sees their own list.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function sc_list($atts) {
        $atts = shortcode_atts(array(
            'user_id'            => '',
            'site_id'            => '',
            'include_links'      => 'true',
            'post_types'         => '',
            'include_buttons'    => 'false',
            'include_thumbnails' => 'false',
            'thumbnail_size'     => 'thumbnail',
            'include_excerpts'   => 'false',
            'no_favorites'       => '',
        ), $atts, 'user_favorites');
        return self::saved_list(array(
            'empty'    => (string) $atts['no_favorites'],
            'excerpts' => 'true' === $atts['include_excerpts'],
            'buttons'  => 'true' === $atts['include_buttons'],
            'types'    => implode(',', array_map('sanitize_key', array_filter(array_map('trim', explode(',', (string) $atts['post_types']))))),
        ));
    }

    /**
     * [user_favorite_count]: how many posts this person saved.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function sc_user_count($atts) {
        self::enqueue();
        return '<span class="sps-reactions__saved-count" data-sps-saved-count>0</span>';
    }

    /**
     * [clear_favorites_button text=""]: empties this person's saved list.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function sc_clear($atts) {
        $atts = shortcode_atts(array('site_id' => '', 'text' => ''), $atts, 'clear_favorites_button');
        self::enqueue();
        $text = '' !== (string) $atts['text'] ? (string) $atts['text'] : __('Clear saved posts', 'seoprostack');
        return '<button type="button" class="sps-reactions__button sps-reactions__clear" data-sps-clear>' . esc_html($text) . '</button>';
    }

    /*
     * ------------------------------------------------------------------
     * Data
     * ------------------------------------------------------------------
     */

    /**
     * Like total, falling back to Favorites' count.
     *
     * @param int $post_id Post ID.
     * @return int
     */
    public static function likes($post_id) {
        $count = get_post_meta($post_id, self::LIKES, true);
        if ('' === $count) {
            $count = get_post_meta($post_id, 'simplefavorites_count', true);
        }
        return max(0, (int) $count);
    }

    /**
     * A user's list on this site (saved or liked). Until one is stored, both
     * fall back to Favorites' favourites for this site: each favourite was
     * saved and counted in the total, so unliking takes it off once.
     *
     * @param int    $user_id User ID.
     * @param string $which   self::SAVED or self::LIKED.
     * @return int[]
     */
    public static function user_list($user_id, $which) {
        $list = get_user_option($which, $user_id);
        if (false === $list) {
            $list = self::favorites_of($user_id);
        }
        return array_values(array_unique(array_filter(array_map('absint', is_array($list) ? $list : array()))));
    }

    /**
     * Favorites' saved posts for this site, in any of its formats.
     *
     * @param int $user_id User ID.
     * @return int[]
     */
    private static function favorites_of($user_id) {
        $meta = get_user_meta($user_id, 'simplefavorites', true);
        if (!is_array($meta) || !$meta) {
            return array();
        }
        $site = get_current_blog_id();
        // Oldest format: a flat list of post IDs, for site 1.
        if (!is_array(reset($meta))) {
            return 1 === $site ? $meta : array();
        }
        foreach ($meta as $entry) {
            if (!is_array($entry) || (isset($entry['site_id']) ? (int) $entry['site_id'] : 1) !== $site) {
                continue;
            }
            if (isset($entry['posts']) && is_array($entry['posts'])) {
                return $entry['posts'];
            }
            if (isset($entry['site_favorites']) && is_array($entry['site_favorites'])) {
                return $entry['site_favorites'];
            }
        }
        return array();
    }

    /**
     * Store a user's list on this site.
     *
     * @param int    $user_id User ID.
     * @param string $which   self::SAVED or self::LIKED.
     * @param int[]  $ids     Post IDs.
     */
    private static function set_user_list($user_id, $which, array $ids) {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), -self::MAX_SAVED);
        update_user_option($user_id, $which, $ids);
    }

    /**
     * Change a like total.
     *
     * @param int $post_id Post ID.
     * @param int $delta   +1 or -1.
     * @return int New total.
     */
    private static function add_like($post_id, $delta) {
        $count = max(0, self::likes($post_id) + $delta);
        update_post_meta($post_id, self::LIKES, $count);
        return $count;
    }

    /**
     * Whether a post can be liked or saved by the current visitor.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private static function can_react($post_id) {
        $post = get_post($post_id);
        return $post && 'publish' === $post->post_status && !post_password_required($post) && is_post_type_viewable($post->post_type);
    }

    /**
     * Too many like changes from this address in the last hour? Only a
     * salted hash of the address is kept, for an hour.
     *
     * @return bool
     */
    private static function rate_limited() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $key = 'seoprostack_rx_' . substr(wp_hash($ip, 'nonce'), 0, 20);
        $n   = (int) get_transient($key);
        if ($n >= self::RATE) {
            return true;
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        return false;
    }

    /*
     * ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------
     */

    /**
     * admin-ajax.php: state, like, save, list and clear.
     *
     * Logged-in people's changes need the nonce from the state call (the
     * page may be cached, so it is never printed in the page). Visitors have
     * nothing on the server but like totals.
     */
    public static function ajax() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked below for logged-in changes; visitors' requests change only rate-limited totals.
        $do   = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $user = get_current_user_id();
        $ids  = isset($_POST['posts']) ? array_slice(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_POST['posts']))))), 0, self::MAX_SAVED) : array();
        $post = isset($_POST['post']) ? absint($_POST['post']) : 0;
        $on   = isset($_POST['on']) && '1' === $_POST['on'];
        // phpcs:enable

        if ($user && 'state' !== $do && !check_ajax_referer(self::AJAX, 'nonce', false)) {
            wp_send_json_error(array('message' => 'nonce'), 403);
        }

        switch ($do) {
            case 'state':
                $counts = array();
                foreach (array_slice($ids, 0, 100) as $id) {
                    if (self::can_react($id)) {
                        $counts[$id] = self::likes($id);
                    }
                }
                $data = array('counts' => (object) $counts, 'user' => false);
                if ($user) {
                    $data['user']  = true;
                    $data['nonce'] = wp_create_nonce(self::AJAX);
                    $data['liked'] = self::user_list($user, self::LIKED);
                    $data['saved'] = self::user_list($user, self::SAVED);
                }
                nocache_headers();
                wp_send_json_success($data);
                break;

            case 'like':
                if (!self::can_react($post)) {
                    wp_send_json_error(null, 404);
                }
                if ($user) {
                    $liked = self::user_list($user, self::LIKED);
                    $has   = in_array($post, $liked, true);
                    if ($has !== $on) {
                        self::set_user_list($user, self::LIKED, $on ? array_merge($liked, array($post)) : array_diff($liked, array($post)));
                        self::add_like($post, $on ? 1 : -1);
                    }
                } else {
                    if (self::rate_limited()) {
                        wp_send_json_error(null, 429);
                    }
                    self::add_like($post, $on ? 1 : -1);
                }
                wp_send_json_success(array('count' => self::likes($post)));
                break;

            case 'save':
                if (!$user) {
                    wp_send_json_error(null, 401);
                }
                if ($on && !self::can_react($post)) {
                    wp_send_json_error(null, 404);
                }
                $saved = self::user_list($user, self::SAVED);
                $saved = $on ? array_merge(array_diff($saved, array($post)), array($post)) : array_diff($saved, array($post));
                self::set_user_list($user, self::SAVED, $saved);
                wp_send_json_success(array('saved' => self::user_list($user, self::SAVED)));
                break;

            case 'merge':
                // Posts saved in this browser before logging in.
                if (!$user) {
                    wp_send_json_error(null, 401);
                }
                $saved = self::user_list($user, self::SAVED);
                foreach ($ids as $id) {
                    if (!in_array($id, $saved, true) && self::can_react($id)) {
                        $saved[] = $id;
                    }
                }
                self::set_user_list($user, self::SAVED, $saved);
                wp_send_json_success(array('saved' => self::user_list($user, self::SAVED)));
                break;

            case 'clear':
                if (!$user) {
                    wp_send_json_error(null, 401);
                }
                self::set_user_list($user, self::SAVED, array());
                wp_send_json_success(array('saved' => array()));
                break;

            case 'list':
                // Titles and links for saved posts (visitors send their own IDs).
                $source = $user ? self::user_list($user, self::SAVED) : $ids;
                $types  = isset($_POST['types']) ? array_filter(array_map('sanitize_key', explode(',', sanitize_text_field(wp_unslash($_POST['types']))))) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only.
                $items  = array();
                foreach (array_reverse($source) as $id) {
                    if (!self::can_react($id)) {
                        continue;
                    }
                    $p = get_post($id);
                    if ($types && !in_array($p->post_type, $types, true)) {
                        continue;
                    }
                    $items[] = array(
                        'id'      => $p->ID,
                        'title'   => wp_strip_all_tags(get_the_title($p)),
                        'url'     => get_permalink($p),
                        'excerpt' => wp_strip_all_tags(get_the_excerpt($p)),
                    );
                }
                nocache_headers();
                wp_send_json_success(array('items' => $items));
                break;
        }
        wp_send_json_error(null, 400);
    }
}
