<?php
/**
 * Like, save and share.
 *
 * A Like, save and share block (and Favorites' shortcodes) with three
 * buttons: Like (a heart with a count), Save (a bookmark) and Share (the
 * device's share sheet, or copy the link). Saving adds to Liked, the
 * default list, and opens "Save to list" for the person's named lists
 * (SEOProStack_Reaction_Lists). The Your lists block and the Saved panel
 * show every list, with bulk Move and Remove; a Saved posts block lists one.
 *
 * Pages stay cacheable: the buttons are printed without state, and a small
 * script (loaded only on pages that have them) asks admin-ajax.php for the
 * counts and, for logged-in people, their lists. Visitors' lists stay in
 * their browser (localStorage) until they log in; the server keeps only like
 * totals. No PHP sessions, no cookies.
 *
 * Replaces Favorites. Its like totals (`simplefavorites_count`) and logged-in
 * users' favourites (user meta `simplefavorites`) are read where SEO Pro
 * Stack has nothing stored yet; Favorites' own data is never changed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/class-seoprostack-reaction-lists.php';

class SEOProStack_Post_Reactions extends SEOProStack_Feature {

    const KEY = 'post_reactions';

    /** Post meta: like total. */
    const LIKES = '_seoprostack_likes';

    /**
     * Post meta: shortlist total, how many people have the post on one of
     * their named lists (not Liked). Browsers report when a post joins their
     * first list or leaves their last (do=listed), as visitors' lists stay
     * in the browser; rate-limited like likes.
     */
    const LISTED = '_seoprostack_listed';

    /** User option (per site): saved post IDs. */
    const SAVED = 'seoprostack_saved';

    /** User option (per site): liked post IDs. */
    const LIKED = 'seoprostack_liked';

    /**
     * User option (per site): liked post IDs joined from a browser at log in
     * without counting them (set_liked() leaves their totals alone when they
     * leave Liked: the server cannot tell the browser counted them).
     */
    const UNCOUNTED = 'seoprostack_liked_uncounted';

    /** User option (per site): current shortlist. */
    const SHORTLIST = 'seoprostack_shortlist';

    /** admin-ajax.php action. */
    const AJAX = 'seoprostack_reactions';

    /** Script and style handle. */
    const HANDLE = 'seoprostack-post-reactions';

    /** Setting: Kadence palette colour for the buttons ('' = text colour). */
    const COLOUR = 'post_reactions_colour';

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
            self::COLOUR => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Button colour', 'seoprostack'),
                'description' => __('A colour from the Kadence theme’s palette, so it follows its dark mode. Also in Customize → Colors & Fonts → Colors. Other themes use the text colour.', 'seoprostack'),
                'options'     => array(__CLASS__, 'colour_options'),
            ),
        );
    }

    /**
     * Button colours: the text colour, or a Kadence global palette colour
     * (main colours 1 to 9, Notices 11 to 15), named as Kadence names them.
     *
     * @return array<string,string>
     */
    public static function colour_options() {
        return array(
            ''          => __('Text colour', 'seoprostack'),
            'palette1'  => __('1 - Accent', 'seoprostack'),
            'palette2'  => __('2 - Accent - alt', 'seoprostack'),
            'palette3'  => __('3 - Strongest text', 'seoprostack'),
            'palette4'  => __('4 - Strong text', 'seoprostack'),
            'palette5'  => __('5 - Medium text', 'seoprostack'),
            'palette6'  => __('6 - Subtle text', 'seoprostack'),
            'palette7'  => __('7 - Subtle background', 'seoprostack'),
            'palette8'  => __('8 - Lighter background', 'seoprostack'),
            'palette9'  => __('9 - White or offwhite', 'seoprostack'),
            'palette11' => __('11 - Notices - Success', 'seoprostack'),
            'palette12' => __('12 - Notices - Info', 'seoprostack'),
            'palette13' => __('13 - Notices - Alert', 'seoprostack'),
            'palette14' => __('14 - Notices - Warning', 'seoprostack'),
            'palette15' => __('15 - Notices - Rating', 'seoprostack'),
        );
    }

    /**
     * CSS for the chosen colour. The variable is set on the blocks, not
     * :root, because Kadence switches palettes on <body>; a theme without
     * the palette falls back to the text colour.
     *
     * @return string
     */
    private static function colour_css() {
        $colour = (string) SEOProStack_Settings::get(self::COLOUR);
        if ('' === $colour || !array_key_exists($colour, self::colour_options())) {
            return '';
        }
        return '.sps-reactions,.sps-saved,.sps-shortlist,.sps-panel-wrap,.sps-lists,.sps-chooser{--sps-reactions-colour:var(--global-' . $colour . ',currentColor)}';
    }

    /**
     * The same Button colour setting in the Customizer, beside Kadence's own
     * colours (Colors & Fonts → Colors), with a live preview.
     *
     * @param WP_Customize_Manager $wp_customize Customizer.
     */
    public static function customize_register($wp_customize) {
        $section = $wp_customize->get_section('kadence_customizer_general_colors');
        if (!$section) {
            return;
        }
        $id = SEOProStack_Settings::OPTION . '[' . self::COLOUR . ']';
        $wp_customize->add_setting($id, array(
            'type'              => 'option',
            'capability'        => 'manage_options',
            'default'           => '',
            'transport'         => 'refresh',
            'sanitize_callback' => array(__CLASS__, 'sanitize_colour'),
        ));
        $wp_customize->add_control($id, array(
            'type'        => 'select',
            'section'     => $section->id,
            'priority'    => 1000,
            'label'       => __('Like, save and share buttons', 'seoprostack'),
            'description' => __('From the palette above, so the buttons follow dark mode.', 'seoprostack'),
            'choices'     => self::colour_options(),
        ));
    }

    /**
     * Keep only a known colour.
     *
     * @param mixed $value Value.
     * @return string
     */
    public static function sanitize_colour($value) {
        $value = is_scalar($value) ? (string) $value : '';
        return array_key_exists($value, self::colour_options()) ? $value : '';
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
        // After the theme adds its sections.
        add_action('customize_register', array(__CLASS__, 'customize_register'), 20);
    }

    /**
     * Register the script, style and blocks.
     */
    public static function register() {
        wp_register_script(self::HANDLE, SEOPROSTACK_URL . 'assets/post-reactions.js', array(), SEOPROSTACK_VERSION, true);
        wp_add_inline_script(self::HANDLE, 'window.seoprostackReactions = ' . wp_json_encode(self::script_config()) . ';', 'before');
        wp_register_style(self::HANDLE, SEOPROSTACK_URL . 'assets/post-reactions.css', array(), SEOPROSTACK_VERSION);
        $css = self::colour_css();
        if ('' !== $css) {
            wp_add_inline_style(self::HANDLE, $css);
        }
        register_block_type(SEOPROSTACK_DIR . 'blocks/post-reactions', array('render_callback' => array(__CLASS__, 'render_block')));
        register_block_type(SEOPROSTACK_DIR . 'blocks/saved-posts', array('render_callback' => array(__CLASS__, 'render_saved_block')));
        register_block_type(SEOPROSTACK_DIR . 'blocks/shortlist-button', array('render_callback' => array(__CLASS__, 'render_shortlist_block')));
        register_block_type(SEOPROSTACK_DIR . 'blocks/saved-panel', array('render_callback' => array(__CLASS__, 'render_panel_block')));
        register_block_type(SEOPROSTACK_DIR . 'blocks/lists', array('render_callback' => array(__CLASS__, 'render_lists_block')));
    }

    /**
     * Words and addresses for the script. No user data: the page may be cached.
     *
     * @return array
     */
    private static function script_config() {
        $account = self::account_urls();
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'site'    => get_current_blog_id(),
            'shortlistMax' => self::list_max(self::SHORTLIST),
            'maxLists' => SEOProStack_Reaction_Lists::MAX_LISTS,
            'nameMax' => SEOProStack_Reaction_Lists::NAME_MAX,
            'loginUrl' => $account['login'],
            'registerUrl' => $account['register'],
            'i18n'    => array(
                'likedList' => __('Liked', 'seoprostack'),
                'savedList' => __('Saved', 'seoprostack'),
                'shortlistList' => __('Shortlist', 'seoprostack'),
                'yourLists' => __('Your lists', 'seoprostack'),
                'saveTo' => __('Save to list', 'seoprostack'),
                /* translators: %s: list name. */
                'savedTo' => __('Saved to %s.', 'seoprostack'),
                'newList' => __('New list', 'seoprostack'),
                'listName' => __('List name', 'seoprostack'),
                'create' => __('Create', 'seoprostack'),
                'rename' => __('Rename', 'seoprostack'),
                'renameList' => __('Rename list', 'seoprostack'),
                'saveName' => __('Save name', 'seoprostack'),
                'deleteList' => __('Delete list', 'seoprostack'),
                /* translators: %s: list name. */
                'confirmDelete' => __('Delete the list “%s”? What is in your other lists stays there.', 'seoprostack'),
                'done' => __('Done', 'seoprostack'),
                'cancel' => __('Cancel', 'seoprostack'),
                'selectAll' => __('Select all', 'seoprostack'),
                'moveTo' => __('Move selected to', 'seoprostack'),
                'move' => __('Move', 'seoprostack'),
                'removeSelected' => __('Remove selected', 'seoprostack'),
                'moreActions' => __('More actions', 'seoprostack'),
                'selectFirst' => __('Select at least one first.', 'seoprostack'),
                /* translators: %d: how many posts. */
                'moved' => __('Moved %d.', 'seoprostack'),
                /* translators: %d: how many posts. */
                'removed' => __('Removed %d.', 'seoprostack'),
                /* translators: %d: most lists. */
                'tooManyLists' => __('You can keep up to %d lists. Delete one to make another.', 'seoprostack'),
                /* translators: 1: "Log in" link, 2: "register" link. */
                'visitorNote' => __('Your lists are kept in this browser. %1$s or %2$s to keep them on any device.', 'seoprostack'),
                /* translators: %s: "Log in" link. */
                'visitorNoteLogin' => __('Your lists are kept in this browser. %s to keep them on any device.', 'seoprostack'),
                'visitorOnly' => __('Your lists are kept in this browser.', 'seoprostack'),
                'nameTaken' => __('You already have a list with that name.', 'seoprostack'),
                /* translators: %s: list name. */
                'removedFrom' => __('Removed from %s.', 'seoprostack'),
                'logIn' => __('Log in', 'seoprostack'),
                'register' => __('register', 'seoprostack'),
                'shortlist' => __('Add to shortlist', 'seoprostack'),
                'shortlisted' => __('On your shortlist', 'seoprostack'),
                'emptyList' => __('Nothing here yet.', 'seoprostack'),
                'like'     => __('Like', 'seoprostack'),
                'liked'    => __('Liked', 'seoprostack'),
                /* translators: %s: number of likes (one). */
                'likesOne' => _n('%s Like', '%s Likes', 1, 'seoprostack'),
                /* translators: %s: number of likes. */
                'likesMany' => _n('%s Like', '%s Likes', 2, 'seoprostack'),
                /* translators: %s: number of people who shortlisted it (one). */
                'listedOne' => _n('%s Shortlist', '%s Shortlists', 1, 'seoprostack'),
                /* translators: %s: number of people who shortlisted it. */
                'listedMany' => _n('%s Shortlist', '%s Shortlists', 2, 'seoprostack'),
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
     * Where visitors log in and register to keep their lists. Registration
     * is WooCommerce's My Account form when it takes registrations, else
     * WordPress's own while the site allows it, else none.
     *
     * Filters: `seoprostack_lists_login_url`, `seoprostack_lists_register_url`
     * (return '' to leave the link out).
     *
     * @return array{login:string,register:string}
     */
    private static function account_urls() {
        $register = '';
        if (function_exists('wc_get_page_permalink') && 'yes' === get_option('woocommerce_enable_myaccount_registration')) {
            $register = (string) wc_get_page_permalink('myaccount');
        } elseif (get_option('users_can_register') || (is_multisite() && in_array(get_site_option('registration'), array('user', 'all'), true))) {
            $register = wp_registration_url();
        }
        return array(
            'login'    => esc_url_raw((string) apply_filters('seoprostack_lists_login_url', wp_login_url())),
            'register' => esc_url_raw((string) apply_filters('seoprostack_lists_register_url', $register)),
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
     * @param array $show    like, save, share => bool; icons => true for
     *                       icon-only buttons (the words stay for screen
     *                       readers; the like total is hidden: tallies()).
     * @param string $extra  Extra wrapper attributes (already escaped HTML), or ''.
     * @return string
     */
    public static function buttons($post_id, array $show = array(), $extra = '') {
        $post = get_post($post_id);
        if (!$post || !self::can_react($post->ID)) {
            return '';
        }
        $show = wp_parse_args($show, array('like' => true, 'save' => true, 'share' => true, 'icons' => false));
        self::enqueue();
        $count = self::likes($post->ID);
        $html  = '';
        if ($show['like']) {
            $html .= '<button type="button" class="sps-reactions__button sps-reactions__like" data-sps-like aria-pressed="false">'
                . self::icon('heart')
                . '<span class="sps-reactions__label">' . esc_html__('Like', 'seoprostack') . '</span>'
                . ($show['icons'] ? '' : '<span class="sps-reactions__count" data-sps-count>' . esc_html(number_format_i18n($count)) . '</span>')
                . '</button>';
        }
        if ($show['save']) {
            $html .= '<button type="button" class="sps-reactions__button sps-reactions__save" data-sps-save data-sps-title="' . esc_attr(wp_strip_all_tags(get_the_title($post))) . '" aria-pressed="false">'
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
            $extra = 'class="sps-reactions' . ($show['icons'] ? ' sps-reactions--icons' : '') . '"';
        }
        return '<div ' . $extra . ' data-sps-reactions="' . (int) $post->ID . '" role="group" aria-label="' . esc_attr__('Like, save and share', 'seoprostack') . '">' . $html . '<span class="sps-reactions__status" role="status" aria-live="polite"></span></div>';
    }

    /**
     * A post's totals as one line: "12 Likes · 3 Shortlists". Printed with
     * the stored totals; the script keeps them current, so cached pages
     * catch up.
     *
     * @param int $post_id Post ID.
     * @return string HTML; '' when the post cannot be liked or saved.
     */
    public static function tallies($post_id) {
        $post_id = (int) $post_id;
        if (!self::can_react($post_id)) {
            return '';
        }
        self::enqueue();
        return '<p class="sps-tallies" data-sps-tallies="' . $post_id . '">'
            . '<span class="sps-tallies__likes" data-sps-tally="likes">' . esc_html(self::tally_text('likes', self::likes($post_id))) . '</span>'
            . '<span class="sps-tallies__sep" aria-hidden="true"> · </span>'
            . '<span class="sps-tallies__listed" data-sps-tally="listed">' . esc_html(self::tally_text('listed', self::listed($post_id))) . '</span>'
            . '</p>';
    }

    /**
     * "1 Like", "12 Likes", "1 Shortlist", "3 Shortlists".
     *
     * @param string $which likes or listed.
     * @param int    $n     Total.
     * @return string
     */
    private static function tally_text($which, $n) {
        $n = max(0, (int) $n);
        /* translators: %s: number of likes. */
        $likes = _n('%s Like', '%s Likes', $n, 'seoprostack');
        /* translators: %s: number of people who shortlisted it. */
        $listed = _n('%s Shortlist', '%s Shortlists', $n, 'seoprostack');
        return sprintf('likes' === $which ? $likes : $listed, number_format_i18n($n));
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
            'list'      => isset($attributes['list']) && in_array($attributes['list'], array('saved', 'liked', 'shortlist'), true) ? $attributes['list'] : 'saved',
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
        $args = wp_parse_args($args, array('list' => 'saved', 'empty' => '', 'excerpts' => false, 'buttons' => true, 'types' => ''));
        if ('' === $extra) {
            $extra = 'class="sps-saved"';
        }
        return '<div ' . $extra . ' data-sps-saved'
            . ' data-list="' . esc_attr($args['list']) . '"'
            . ' data-empty="' . esc_attr($args['empty']) . '"'
            . ' data-excerpts="' . ($args['excerpts'] ? '1' : '0') . '"'
            . ' data-buttons="' . ($args['buttons'] ? '1' : '0') . '"'
            . ' data-types="' . esc_attr($args['types']) . '"'
            . ' aria-live="polite"></div>';
    }

    /**
     * A separate, deliberately placed shortlist button.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    public static function shortlist_button($post_id) {
        if (!self::can_react($post_id)) {
            return '';
        }
        self::enqueue();
        return '<button type="button" class="sps-reactions__button sps-shortlist" data-sps-shortlist="' . (int) $post_id . '" data-sps-title="' . esc_attr(wp_strip_all_tags(get_the_title($post_id))) . '" aria-pressed="false">' . self::icon('bookmark') . '<span class="sps-reactions__label">' . esc_html__('Add to shortlist', 'seoprostack') . '</span></button><span class="sps-reactions__status" role="status"></span>';
    }

    /**
     * Render the shortlist block in a post or Query loop.
     *
     * @param array $attributes Attributes.
     * @param string $content Inner content.
     * @param WP_Block|null $block Block.
     * @return string
     */
    public static function render_shortlist_block($attributes, $content = '', $block = null) {
        $id = $block && isset($block->context['postId']) ? (int) $block->context['postId'] : (int) get_the_ID();
        return '<div ' . get_block_wrapper_attributes() . '>' . self::shortlist_button($id) . '</div>';
    }

    /**
     * A cache-safe header trigger and native modal, with no personal data.
     *
     * @return string
     */
    public static function render_panel_block() {
        self::enqueue();
        $id = wp_unique_id('sps-panel-');
        $html = '<div class="sps-panel-wrap"><button type="button" class="sps-reactions__button" data-sps-panel-open aria-haspopup="dialog" aria-controls="' . esc_attr($id) . '">' . self::icon('bookmark') . esc_html__('Shortlist', 'seoprostack') . '<span data-sps-shortlist-count></span></button>';
        $html .= '<dialog class="sps-panel" data-sps-panel id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id . '-title') . '"><button type="button" class="sps-reactions__button" data-sps-panel-close autofocus>' . esc_html__('Close', 'seoprostack') . '</button>';
        $html .= '<h2 class="sps-panel__title" id="' . esc_attr($id . '-title') . '">' . esc_html__('Your lists', 'seoprostack') . '</h2>';
        return $html . self::lists_box('panel') . '</dialog></div>';
    }

    /**
     * Render the Your lists block: every list, filled in by the script.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_lists_block($attributes = array()) {
        return self::lists_box('block', get_block_wrapper_attributes(array('class' => 'sps-lists')));
    }

    /**
     * Every list, with tabs, selection, bulk Move and Remove, and the
     * actions other plugins add. Printed empty: the script fills it, so the
     * page may be cached.
     *
     * Actions: `seoprostack_lists_actions` (HTML, and `panel` or `block`),
     * after `seoprostack_saved_panel_actions` (HTML, `shortlist`), which
     * runs once here for plugins written for the earlier panel. Return
     * static HTML, never personal data.
     *
     * @param string $context panel or block.
     * @param string $extra   Wrapper attributes (escaped), or ''.
     * @return string
     */
    private static function lists_box($context, $extra = '') {
        self::enqueue();
        $actions = (string) apply_filters('seoprostack_saved_panel_actions', '', 'shortlist');
        $actions = (string) apply_filters('seoprostack_lists_actions', $actions, $context);
        if ('' === $extra) {
            $extra = 'class="sps-lists"';
        }
        return '<div ' . $extra . ' data-sps-lists data-context="' . esc_attr($context) . '">'
            . '<div class="sps-lists__ui" data-sps-lists-ui><p class="sps-saved__empty">' . esc_html__('Loading your lists…', 'seoprostack') . '</p></div>'
            . ('' === trim($actions) ? '' : '<div class="sps-panel__actions sps-lists__actions" data-sps-lists-actions>' . wp_kses_post($actions) . '</div>')
            . '<p class="sps-reactions__status" role="status" aria-live="polite"></p></div>';
    }

    /**
     * Shortlist shortcode.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function sc_shortlist($atts) {
        return self::shortlist_button(self::sc_post((array) $atts));
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
            'seoprostack_shortlist_button' => 'sc_shortlist',
            'seoprostack_saved_panel' => 'render_panel_block',
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
        $atts = shortcode_atts(array('post_id' => '', 'site_id' => '', 'group_id' => ''), (array) $atts, 'favorite_button');
        return self::buttons(self::sc_post($atts), array('share' => false));
    }

    /**
     * [favorite_count post_id=""]: the like total, kept current by the script.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function sc_count($atts) {
        $atts    = shortcode_atts(array('post_id' => '', 'site_id' => ''), (array) $atts, 'favorite_count');
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
        ), (array) $atts, 'user_favorites');
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
        $atts = shortcode_atts(array('site_id' => '', 'text' => ''), (array) $atts, 'clear_favorites_button');
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
        if (false === $list && self::SHORTLIST !== $which) {
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
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), -self::list_max($which));
        update_user_option($user_id, $which, $ids);
    }

    /**
     * Maximum size of a list; shortlist is intentionally smaller.
     *
     * @param string $which User option.
     * @return int
     */
    private static function list_max($which) {
        return self::SHORTLIST === $which ? max(1, min(self::MAX_SAVED, (int) apply_filters('seoprostack_shortlist_max', 50))) : self::MAX_SAVED;
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
     * Shortlist total: how many people have the post on a named list.
     *
     * @param int $post_id Post ID.
     * @return int
     */
    public static function listed($post_id) {
        return max(0, (int) get_post_meta($post_id, self::LISTED, true));
    }

    /**
     * Change a shortlist total.
     *
     * @param int $post_id Post ID.
     * @param int $delta   +1 or -1.
     * @return int New total.
     */
    private static function add_listed($post_id, $delta) {
        $count = max(0, self::listed($post_id) + $delta);
        update_post_meta($post_id, self::LISTED, $count);
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
        return $post && 'publish' === $post->post_status && '' === $post->post_password && is_post_type_viewable($post->post_type);
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
     * Post IDs from a comma-separated request value: unique, at most
     * MAX_SAVED.
     *
     * @param string $value Value.
     * @return int[]
     */
    private static function posts_param($value) {
        return array_slice(array_values(array_unique(array_filter(array_map('absint', explode(',', $value))))), 0, self::MAX_SAVED);
    }

    /**
     * Liked or a named list's ID.
     *
     * @param string $id List ID.
     * @return bool
     */
    private static function valid_list($id) {
        return SEOProStack_Reaction_Lists::DEFAULT_LIST === $id || '' !== SEOProStack_Reaction_Lists::clean_id($id);
    }

    /**
     * Like or unlike posts for a logged-in person; each total changes once.
     *
     * @param int   $user_id User ID.
     * @param int[] $posts   Post IDs.
     * @param bool  $on      Like (true) or unlike (false).
     */
    private static function set_liked($user_id, array $posts, $on) {
        $liked     = self::user_list($user_id, self::LIKED);
        $uncounted = self::uncounted($user_id);
        $marked    = $uncounted;
        foreach ($posts as $id) {
            if (in_array($id, $liked, true) === $on || ($on && !self::can_react($id))) {
                continue;
            }
            $liked = $on ? array_merge($liked, array($id)) : array_values(array_diff($liked, array($id)));
            if (in_array($id, $uncounted, true)) {
                $uncounted = array_values(array_diff($uncounted, array($id)));
                // Joined without counting (join_liked()): its total stays.
                if (!$on) {
                    continue;
                }
            }
            self::add_like($id, $on ? 1 : -1);
        }
        self::set_user_list($user_id, self::LIKED, $liked);
        if ($uncounted !== $marked) {
            update_user_option($user_id, self::UNCOUNTED, $uncounted);
        }
    }

    /**
     * Add posts to Liked without counting them: likes made in a browser
     * before logging in were counted then. They are marked (self::UNCOUNTED),
     * so taking them off Liked later does not lower their totals: the request
     * names them, and the server cannot tell this browser counted them.
     *
     * @param int   $user_id User ID.
     * @param int[] $posts   Post IDs.
     */
    private static function join_liked($user_id, array $posts) {
        $liked  = self::user_list($user_id, self::LIKED);
        $joined = array();
        foreach ($posts as $id) {
            if (!in_array($id, $liked, true) && self::can_react($id)) {
                $liked[]  = $id;
                $joined[] = $id;
            }
        }
        self::set_user_list($user_id, self::LIKED, $liked);
        if ($joined) {
            $kept = self::user_list($user_id, self::LIKED);
            update_user_option($user_id, self::UNCOUNTED, array_values(array_intersect(array_unique(array_merge(self::uncounted($user_id), $joined)), $kept)));
        }
    }

    /**
     * Liked posts joined without counting them (join_liked()).
     *
     * @param int $user_id User ID.
     * @return int[]
     */
    private static function uncounted($user_id) {
        $ids = get_user_option(self::UNCOUNTED, $user_id);
        return is_array($ids) ? array_values(array_unique(array_filter(array_map('absint', $ids)))) : array();
    }

    /**
     * A logged-in person's lists, as the script reads them: Liked, the named
     * lists, and (for code from before named lists) the Saved and Shortlist
     * lists' posts.
     *
     * @param int $user_id User ID.
     * @return array
     */
    private static function lists_payload($user_id) {
        $lists = SEOProStack_Reaction_Lists::get($user_id);
        $data  = array('liked' => self::user_list($user_id, self::LIKED), 'lists' => $lists);
        foreach (array_keys(SEOProStack_Reaction_Lists::legacy()) as $id) {
            $found     = SEOProStack_Reaction_Lists::find($lists, $id);
            $data[$id] = $found ? $found['posts'] : array();
        }
        return $data;
    }

    /**
     * End the request with the person's lists.
     *
     * @param int   $user_id User ID.
     * @param array $extra   More to send.
     */
    private static function send_lists($user_id, array $extra = array()) {
        wp_send_json_success(array_merge(self::lists_payload($user_id), $extra));
    }

    /**
     * admin-ajax.php: state, like, save, create, rename, delete, move,
     * remove, merge, list and clear (and shortlist, which saves to the
     * Shortlist list, from before named lists).
     *
     * Logged-in people's changes need the nonce from the state call (the
     * page may be cached, so it is never printed in the page). Visitors have
     * nothing on the server but like and shortlist totals: their lists are
     * in their browser, and only state, like, listed and list are theirs to
     * use.
     */
    public static function ajax() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked below for logged-in changes; visitors' requests change only rate-limited totals.
        $do   = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $user = get_current_user_id();
        $ids  = isset($_POST['posts']) ? self::posts_param(sanitize_text_field(wp_unslash($_POST['posts']))) : array();
        $post = isset($_POST['post']) ? absint($_POST['post']) : 0;
        $on   = isset($_POST['on']) && '1' === $_POST['on'];
        $list = isset($_POST['list']) ? sanitize_key(wp_unslash($_POST['list'])) : 'saved';
        if ('list' === $do && isset($_POST['which'])) {
            $list = sanitize_key(wp_unslash($_POST['which']));
        }
        $to   = isset($_POST['to']) ? sanitize_key(wp_unslash($_POST['to'])) : '';
        $name = isset($_POST['name']) ? SEOProStack_Reaction_Lists::clean_name(sanitize_text_field(wp_unslash($_POST['name']))) : '';
        $raw  = isset($_POST['lists']) && is_string($_POST['lists']) ? wp_unslash($_POST['lists']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON: decoded below and cleaned by SEOProStack_Reaction_Lists::clean().
        // phpcs:enable

        if ('shortlist' === $do) {
            $do   = 'save';
            $list = 'shortlist';
        }
        if (!self::valid_list($list)) {
            wp_send_json_error(null, 400);
        }
        if ($user && 'state' !== $do && !check_ajax_referer(self::AJAX, 'nonce', false)) {
            wp_send_json_error(array('message' => 'nonce'), 403);
        }
        if (!$user && !in_array($do, array('state', 'like', 'listed', 'list'), true)) {
            wp_send_json_error(null, 401);
        }
        $allowed = static function ($id) {
            return self::can_react($id);
        };

        switch ($do) {
            case 'state':
                $counts = array();
                $listed = array();
                foreach (array_slice($ids, 0, 100) as $id) {
                    if (self::can_react($id)) {
                        $counts[$id] = self::likes($id);
                        $listed[$id] = self::listed($id);
                    }
                }
                $data = array('counts' => (object) $counts, 'listed' => (object) $listed, 'user' => false);
                if ($user) {
                    $data = array_merge($data, self::lists_payload($user), array('user' => true, 'nonce' => wp_create_nonce(self::AJAX)));
                }
                nocache_headers();
                wp_send_json_success($data);
                break;

            case 'like':
                if (!self::can_react($post)) {
                    wp_send_json_error(null, 404);
                }
                if ($user) {
                    if (in_array($post, self::user_list($user, self::LIKED), true) !== $on) {
                        if (!$on && self::rate_limited()) {
                            wp_send_json_error(null, 429);
                        }
                        self::set_liked($user, array($post), $on);
                    }
                    self::send_lists($user, array('count' => self::likes($post)));
                }
                if (self::rate_limited()) {
                    wp_send_json_error(null, 429);
                }
                self::add_like($post, $on ? 1 : -1);
                wp_send_json_success(array('count' => self::likes($post)));
                break;

            case 'listed':
                // A post joined this browser's first named list, or left its
                // last: the public shortlist total only.
                if (!self::can_react($post)) {
                    wp_send_json_error(null, 404);
                }
                if (self::rate_limited()) {
                    wp_send_json_error(null, 429);
                }
                wp_send_json_success(array('listed' => self::add_listed($post, $on ? 1 : -1)));
                break;

            case 'save':
                if (!$post || ($on && !self::can_react($post))) {
                    wp_send_json_error(null, 404);
                }
                if (SEOProStack_Reaction_Lists::DEFAULT_LIST === $list) {
                    if (!$on && self::rate_limited()) {
                        wp_send_json_error(null, 429);
                    }
                    self::set_liked($user, array($post), $on);
                } else {
                    $lists = SEOProStack_Reaction_Lists::change(SEOProStack_Reaction_Lists::get($user), $list, array($post), $on);
                    if (null === $lists) {
                        wp_send_json_error(null, 404);
                    }
                    SEOProStack_Reaction_Lists::set($user, $lists);
                }
                self::send_lists($user, array('count' => self::likes($post)));
                break;

            case 'create':
                if ('' === $name) {
                    wp_send_json_error(null, 400);
                }
                $lists = SEOProStack_Reaction_Lists::get($user);
                $index = SEOProStack_Reaction_Lists::index_by_name($lists, $name);
                if ($index >= 0) {
                    // That name is taken: use the list that has it.
                    $id = $lists[$index]['id'];
                } elseif (count($lists) >= SEOProStack_Reaction_Lists::MAX_LISTS) {
                    wp_send_json_error(array('message' => 'too_many'), 409);
                } else {
                    $id      = SEOProStack_Reaction_Lists::new_id($lists);
                    $lists[] = array('id' => $id, 'name' => $name, 'posts' => array());
                }
                if ($post && self::can_react($post)) {
                    $lists = (array) SEOProStack_Reaction_Lists::change($lists, $id, array($post), true);
                }
                SEOProStack_Reaction_Lists::set($user, $lists);
                self::send_lists($user, array('id' => $id));
                break;

            case 'rename':
                $lists = SEOProStack_Reaction_Lists::get($user);
                $index = SEOProStack_Reaction_Lists::index($lists, $list);
                if ('' === $name || $index < 0) {
                    wp_send_json_error(null, $index < 0 ? 404 : 400);
                }
                $other = SEOProStack_Reaction_Lists::index_by_name($lists, $name);
                if ($other >= 0 && $other !== $index) {
                    wp_send_json_error(array('message' => 'exists'), 409);
                }
                $lists[$index]['name'] = $name;
                SEOProStack_Reaction_Lists::set($user, $lists);
                self::send_lists($user);
                break;

            case 'delete':
                $lists = SEOProStack_Reaction_Lists::get($user);
                $index = SEOProStack_Reaction_Lists::index($lists, $list);
                if ($index < 0) {
                    wp_send_json_error(null, 404);
                }
                array_splice($lists, $index, 1);
                SEOProStack_Reaction_Lists::set($user, $lists);
                self::send_lists($user);
                break;

            case 'move':
                if (!self::valid_list($to) || $to === $list || !$ids) {
                    wp_send_json_error(null, 400);
                }
                $lists = SEOProStack_Reaction_Lists::get($user);
                if (SEOProStack_Reaction_Lists::DEFAULT_LIST === $list) {
                    $source = self::user_list($user, self::LIKED);
                } else {
                    $found  = SEOProStack_Reaction_Lists::find($lists, $list);
                    $source = $found ? $found['posts'] : array();
                }
                $posts = array_values(array_intersect($ids, $source));
                // Moving in or out of Liked changes like totals.
                if (in_array(SEOProStack_Reaction_Lists::DEFAULT_LIST, array($list, $to), true) && self::rate_limited()) {
                    wp_send_json_error(null, 429);
                }
                $add = array_values(array_filter($posts, $allowed));
                if (SEOProStack_Reaction_Lists::DEFAULT_LIST === $to) {
                    self::set_liked($user, $add, true);
                } else {
                    $lists = SEOProStack_Reaction_Lists::change($lists, $to, $add, true);
                    if (null === $lists) {
                        wp_send_json_error(null, 404);
                    }
                }
                if (SEOProStack_Reaction_Lists::DEFAULT_LIST === $list) {
                    self::set_liked($user, $posts, false);
                } else {
                    $lists = (array) SEOProStack_Reaction_Lists::change($lists, $list, $posts, false);
                }
                SEOProStack_Reaction_Lists::set($user, $lists);
                self::send_lists($user, array('changed' => count($posts)));
                break;

            case 'remove':
                if (!$ids) {
                    wp_send_json_error(null, 400);
                }
                if (SEOProStack_Reaction_Lists::DEFAULT_LIST === $list) {
                    if (self::rate_limited()) {
                        wp_send_json_error(null, 429);
                    }
                    self::set_liked($user, $ids, false);
                } else {
                    SEOProStack_Reaction_Lists::set($user, (array) SEOProStack_Reaction_Lists::change(SEOProStack_Reaction_Lists::get($user), $list, $ids, false));
                }
                self::send_lists($user);
                break;

            case 'merge':
                // Lists kept in this browser before logging in.
                $incoming = '' !== $raw && strlen($raw) <= 200000 ? json_decode($raw, true) : null;
                if (is_array($incoming)) {
                    self::join_liked($user, isset($incoming['liked']) && is_array($incoming['liked']) ? self::posts_param(implode(',', array_map('absint', array_filter($incoming['liked'], 'is_scalar')))) : array());
                    $named = isset($incoming['lists']) ? $incoming['lists'] : array();
                } elseif (SEOProStack_Reaction_Lists::DEFAULT_LIST === $list) {
                    // Before named lists: one list's posts at a time.
                    self::join_liked($user, $ids);
                    $named = array();
                } else {
                    $names = SEOProStack_Reaction_Lists::legacy_names();
                    if (!isset($names[$list])) {
                        wp_send_json_error(null, 400);
                    }
                    $named = array(array('id' => $list, 'name' => $names[$list], 'posts' => $ids));
                }
                if ($named) {
                    SEOProStack_Reaction_Lists::set($user, SEOProStack_Reaction_Lists::merge(SEOProStack_Reaction_Lists::get($user), $named, $allowed));
                }
                self::send_lists($user);
                break;

            case 'clear':
                // [clear_favorites_button]: empties Saved.
                $lists = SEOProStack_Reaction_Lists::get($user);
                $index = SEOProStack_Reaction_Lists::index($lists, 'saved');
                if ($index >= 0) {
                    $lists[$index]['posts'] = array();
                    SEOProStack_Reaction_Lists::set($user, $lists);
                }
                self::send_lists($user);
                break;

            case 'list':
                // Titles and links for a list's posts: those sent (the script
                // sends only the ones it has not shown yet), or the list's.
                if (!$user || isset($_POST['posts'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above for logged-in people; read-only.
                    $source = array_slice($ids, -SEOProStack_Reaction_Lists::max_posts($list));
                } elseif (SEOProStack_Reaction_Lists::DEFAULT_LIST === $list) {
                    $source = self::user_list($user, self::LIKED);
                } else {
                    $found  = SEOProStack_Reaction_Lists::find(SEOProStack_Reaction_Lists::get($user), $list);
                    $source = $found ? $found['posts'] : array();
                }
                $types  = isset($_POST['types']) ? array_filter(array_map('sanitize_key', explode(',', sanitize_text_field(wp_unslash($_POST['types']))))) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only.
                $items  = array();
                foreach (array_reverse($source) as $id) {
                    if (!self::can_react($id)) {
                        continue;
                    }
                    $p = get_post($id);
                    if (!$p instanceof WP_Post || ($types && !in_array($p->post_type, $types, true))) {
                        continue;
                    }
                    $item = array(
                        'id'      => $p->ID,
                        'title'   => wp_strip_all_tags(get_the_title($p)),
                        'url'     => get_permalink($p),
                        'excerpt' => wp_strip_all_tags(get_the_excerpt($p)),
                    );
                    $extra = apply_filters('seoprostack_saved_item', $item, $p, $list);
                    if (is_array($extra)) {
                        $item['meta'] = array_values(array_map('sanitize_text_field', array_slice(array_filter(isset($extra['meta']) && is_array($extra['meta']) ? $extra['meta'] : array(), 'is_string'), 0, 3)));
                        $item['image'] = isset($extra['image']) && is_string($extra['image']) ? esc_url_raw($extra['image'], array('http', 'https')) : '';
                    }
                    $items[] = $item;
                }
                nocache_headers();
                wp_send_json_success(array('items' => $items));
                break;
        }
        wp_send_json_error(null, 400);
    }
}
