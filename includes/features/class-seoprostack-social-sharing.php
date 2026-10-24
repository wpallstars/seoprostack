<?php
/**
 * Social sharing.
 *
 * Share links (Facebook, X, LinkedIn, Pinterest, Reddit, WhatsApp and
 * Email) before or after the content of chosen post types and on
 * WooCommerce products, drawn with the Brand icons set as inline SVG in
 * round buttons that take the brand colours or a Kadence palette colour,
 * so they follow its light and dark modes. A small inline style, and an
 * inline script only for pop-up windows: no files, no tracking and no
 * request to another site until someone shares.
 *
 * Replaces Kadence Simple Share with the options its sites use. Its
 * settings (`kt_share`) are imported once, its per-post choice
 * (`_kt_share_control`: default, show or hide) is read and set in the
 * editor's Dev tab, and its `[kadence_simple_share]` shortcode keeps
 * working once it is deactivated.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.78
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Social_Sharing extends SEOProStack_Feature {

    const KEY = 'social_sharing';

    /** Plugins this replaces: folder => name. */
    const REPLACES = array('kadence-simple-share' => 'Kadence Simple Share');

    /** Post meta for the per-post choice, shared with Kadence Simple Share. */
    const META = '_kt_share_control';

    /** Style and script handle. */
    const HANDLE = 'seoprostack-social-sharing';

    /** Editor script handle. */
    const EDITOR = 'seoprostack-social-sharing-editor';

    /** Kadence Simple Share's shortcode. */
    const SHORTCODE = 'kadence_simple_share';

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
                'label'       => __('Social sharing', 'seoprostack'),
                'description' => __('Share buttons for Facebook, X, LinkedIn, Pinterest, Reddit, WhatsApp and Email before or after the content of the post types below, and on WooCommerce products. Brand icons in round buttons, in the brand colours or a colour from the Kadence palette, so they follow its dark mode. No tracking, no files and nothing from other sites until someone shares.', 'seoprostack'),
                'replaces'    => self::REPLACES,
            ),
            'social_sharing_networks' => array(
                'type'    => 'multi',
                'default' => array('facebook', 'x'),
                'parent'  => self::KEY,
                'label'   => __('Share on', 'seoprostack'),
                'options' => array(__CLASS__, 'network_options'),
            ),
            'social_sharing_types' => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array('post'),
                'parent'  => self::KEY,
                'label'   => __('Show on', 'seoprostack'),
                'description' => __('Products show them in WooCommerce’s sharing place, under Add to cart. Each post can also show or hide them (Share buttons, in the editor’s Dev tab).', 'seoprostack'),
                'options' => array('SEOProStack_Duplicate_Posts', 'post_type_options'),
            ),
            'social_sharing_place' => array(
                'type'    => 'select',
                'default' => 'after',
                'parent'  => self::KEY,
                'label'   => __('Place', 'seoprostack'),
                'options' => array(__CLASS__, 'place_options'),
            ),
            'social_sharing_style' => array(
                'type'    => 'select',
                'default' => 'filled',
                'parent'  => self::KEY,
                'label'   => __('Style', 'seoprostack'),
                'options' => array(__CLASS__, 'style_options'),
            ),
            'social_sharing_size' => array(
                'type'    => 'select',
                'default' => 'normal',
                'parent'  => self::KEY,
                'label'   => __('Size', 'seoprostack'),
                'options' => array(__CLASS__, 'size_options'),
            ),
            'social_sharing_colour' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Colour', 'seoprostack'),
                'description' => __('Brand colours, or one colour from the Kadence theme’s palette so the buttons follow its dark mode. Black and white logos (X) and Email always take the text colour.', 'seoprostack'),
                'options'     => array(__CLASS__, 'colour_options'),
            ),
            'social_sharing_text' => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Text before the buttons', 'seoprostack'),
                'description' => __('Such as “Share via:”. Leave empty for none.', 'seoprostack'),
            ),
            'social_sharing_tooltip' => array(
                'type'    => 'select',
                'default' => 'top',
                'parent'  => self::KEY,
                'label'   => __('Tooltips', 'seoprostack'),
                'options' => array(__CLASS__, 'tooltip_options'),
            ),
            'social_sharing_window' => array(
                'type'    => 'select',
                'default' => 'popup',
                'parent'  => self::KEY,
                'label'   => __('Open sharing in', 'seoprostack'),
                'options' => array(__CLASS__, 'window_options'),
            ),
        );
    }

    /**
     * Networks: key => [name, Brand icons slug, share address pattern].
     * Addresses take {url} and {title}, already encoded; {media} for
     * Pinterest's picture. Email has no brand icon.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    private static function networks() {
        return array(
            'facebook'  => array('Facebook', 'facebook', 'https://www.facebook.com/sharer.php?u={url}'),
            'x'         => array('X', 'x', 'https://twitter.com/intent/tweet?url={url}&text={title}'),
            'linkedin'  => array('LinkedIn', 'linkedin', 'https://www.linkedin.com/shareArticle?mini=true&url={url}'),
            'pinterest' => array('Pinterest', 'pinterest', 'https://pinterest.com/pin/create/bookmarklet/?is_video=false&url={url}&media={media}&description={title}'),
            'reddit'    => array('Reddit', 'reddit', 'https://reddit.com/submit?url={url}&title={title}'),
            'whatsapp'  => array('WhatsApp', 'whatsapp', 'https://wa.me/?text={title}%20{url}'),
            'email'     => array(__('Email', 'seoprostack'), '', 'mailto:?subject={title}&body={url}'),
        );
    }

    /**
     * Network choices, in the order they show.
     *
     * @return array<string,string>
     */
    public static function network_options() {
        $options = array();
        foreach (self::networks() as $key => $network) {
            $options[$key] = $network[0];
        }
        return $options;
    }

    /**
     * Place choices.
     *
     * @return array<string,string>
     */
    public static function place_options() {
        return array(
            'before' => __('Before the content', 'seoprostack'),
            'after'  => __('After the content', 'seoprostack'),
        );
    }

    /**
     * Style choices.
     *
     * @return array<string,string>
     */
    public static function style_options() {
        return array(
            'filled'  => __('Filled circles', 'seoprostack'),
            'outline' => __('Outlined circles, filled on hover', 'seoprostack'),
        );
    }

    /**
     * Size choices.
     *
     * @return array<string,string>
     */
    public static function size_options() {
        return array(
            'normal' => __('Normal', 'seoprostack'),
            'large'  => __('Large', 'seoprostack'),
        );
    }

    /**
     * Colour choices: brand colours, or a Kadence palette colour.
     *
     * @return array<string,string>
     */
    public static function colour_options() {
        $options = SEOProStack_Post_Reactions::colour_options();
        $options[''] = __('Brand colours', 'seoprostack');
        return $options;
    }

    /**
     * Tooltip choices.
     *
     * @return array<string,string>
     */
    public static function tooltip_options() {
        return array(
            'top'    => __('Above the buttons', 'seoprostack'),
            'bottom' => __('Below the buttons', 'seoprostack'),
            ''       => __('None', 'seoprostack'),
        );
    }

    /**
     * Window choices.
     *
     * @return array<string,string>
     */
    public static function window_options() {
        return array(
            'popup' => __('A pop-up window', 'seoprostack'),
            'tab'   => __('A new tab', 'seoprostack'),
        );
    }

    /**
     * Switch on while Kadence Simple Share is active, with its settings.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (!isset(self::active_plugins()['kadence-simple-share'])) {
            return $options;
        }
        $options = self::import_setting($options, self::KEY, true);
        $kt      = get_option('kt_share');
        if (!is_array($kt)) {
            return $options;
        }
        foreach (self::import_values($kt) as $key => $value) {
            $options = self::import_setting($options, $key, $value);
        }
        return $options;
    }

    /**
     * Kadence Simple Share's settings as this feature's.
     *
     * @param array $kt Its `kt_share` option.
     * @return array<string,mixed> Setting key => value (null: not set).
     */
    public static function import_values(array $kt) {
        $yes = static function ($key) use ($kt) {
            return isset($kt[$key]) && 1 === (int) $kt[$key];
        };

        $networks = null;
        if (isset($kt['enabled_sharing']['enabled']) && is_array($kt['enabled_sharing']['enabled'])) {
            $networks = array();
            foreach (array_keys($kt['enabled_sharing']['enabled']) as $network) {
                $network    = 'twitter' === $network ? 'x' : (string) $network;
                $networks[] = $network;
            }
            $networks = array_values(array_intersect($networks, array_keys(self::networks())));
        }

        $types = array();
        foreach (array('post' => 'enable_posts', 'page' => 'enable_pages', 'product' => 'enable_products', 'portfolio' => 'enable_portfolio') as $type => $key) {
            if ($yes($key)) {
                $types[] = $type;
            }
        }

        $style   = isset($kt['sharing_style']) ? (string) $kt['sharing_style'] : '';
        $tooltip = $yes('enable_tooltip') ? (isset($kt['tooltip_align']) && 'top' === $kt['tooltip_align'] ? 'top' : 'bottom') : '';

        return array(
            'social_sharing_networks' => $networks,
            'social_sharing_types'    => $types,
            // Before stays before; after, both and the fixed bars go after.
            'social_sharing_place'    => isset($kt['sharing_location']) && 'before' === $kt['sharing_location'] ? 'before' : 'after',
            // Its styles 3 to 5 are outlines; 1 and 2 are filled.
            'social_sharing_style'    => in_array($style, array('style_03', 'style_04', 'style_05'), true) ? 'outline' : 'filled',
            'social_sharing_size'     => isset($kt['sharing_size']) && 'large' === $kt['sharing_size'] ? 'large' : 'normal',
            'social_sharing_text'     => isset($kt['sharing_text']) ? trim(wp_strip_all_tags((string) $kt['sharing_text'])) : null,
            'social_sharing_tooltip'  => $tooltip,
            'social_sharing_window'   => isset($kt['sharing_link_exit']) && 'popup' !== $kt['sharing_link_exit'] ? 'tab' : 'popup',
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('init', array(__CLASS__, 'register_meta'));
        // After Kadence Simple Share would have added its own.
        add_action('init', array(__CLASS__, 'register_shortcode'), 20);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_filter('the_content', array(__CLASS__, 'content'), 100);
        add_action('woocommerce_share', array(__CLASS__, 'product'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor'));
    }

    /**
     * The per-post choice, for the editor.
     */
    public static function register_meta() {
        register_post_meta('', self::META, array(
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'show_in_rest'      => true,
            'sanitize_callback' => array(__CLASS__, 'sanitize_control'),
            'auth_callback'     => static function ($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', (int) $post_id);
            },
        ));
    }

    /**
     * Keep only a known per-post choice.
     *
     * @param mixed $value Value.
     * @return string
     */
    public static function sanitize_control($value) {
        $value = is_scalar($value) ? (string) $value : '';
        return in_array($value, array('show', 'hide'), true) ? $value : '';
    }

    /**
     * Kadence Simple Share's shortcode, once it is gone.
     */
    public static function register_shortcode() {
        if (!shortcode_exists(self::SHORTCODE)) {
            add_shortcode(self::SHORTCODE, array(__CLASS__, 'shortcode'));
        }
    }

    /**
     * [kadence_simple_share]: the buttons for the current post.
     *
     * @return string
     */
    public static function shortcode() {
        $post = get_post();
        return $post ? self::buttons($post, 'shortcode') : '';
    }

    /**
     * Whether a post shows the buttons by itself: its own choice, else its
     * post type's.
     *
     * @param WP_Post $post Post.
     * @return bool
     */
    public static function shows(WP_Post $post) {
        $choice = (string) get_post_meta($post->ID, self::META, true);
        if ('hide' === $choice) {
            return false;
        }
        if ('show' === $choice) {
            return true;
        }
        return in_array($post->post_type, (array) SEOProStack_Settings::get('social_sharing_types'), true);
    }

    /**
     * The post on its own page, when it shows the buttons.
     *
     * @return WP_Post|null
     */
    private static function current() {
        if (!is_singular() || is_feed() || is_embed()) {
            return null;
        }
        $post = get_queried_object();
        return $post instanceof WP_Post && 'publish' === $post->post_status && '' === $post->post_password && self::shows($post) ? $post : null;
    }

    /**
     * The style (and, for pop-ups, the script) on pages that show the
     * buttons or use the shortcode.
     */
    public static function enqueue() {
        $post = self::current();
        if (!$post) {
            $post = is_singular() ? get_queried_object() : null;
            if (!$post instanceof WP_Post || !has_shortcode($post->post_content, self::SHORTCODE)) {
                return;
            }
        }
        self::assets();
    }

    /**
     * Register and add the inline style and script.
     */
    private static function assets() {
        if (!wp_style_is(self::HANDLE, 'registered')) {
            wp_register_style(self::HANDLE, false, array(), SEOPROSTACK_VERSION);
            wp_add_inline_style(self::HANDLE, self::css());
        }
        wp_enqueue_style(self::HANDLE);
        if ('popup' !== SEOProStack_Settings::get('social_sharing_window')) {
            return;
        }
        if (!wp_script_is(self::HANDLE, 'registered')) {
            wp_register_script(self::HANDLE, false, array(), SEOPROSTACK_VERSION, true);
            // Share addresses open in a small window; Email and modified clicks go as normal.
            wp_add_inline_script(self::HANDLE, 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-sps-popup]");if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button){return;}var w=600,h=520,p=window.open(a.href,"sps-share","width="+w+",height="+h+",left="+Math.max(0,(screen.width-w)/2)+",top="+Math.max(0,(screen.height-h)/2));if(p){p.opener=null;e.preventDefault();}});');
        }
        wp_enqueue_script(self::HANDLE);
    }

    /**
     * The buttons' CSS. Colours come from custom properties on each link:
     * --sps-share-brand (the brand, or the chosen palette colour) and the
     * text colour for black and white logos.
     *
     * @return string
     */
    private static function css() {
        $colour = (string) SEOProStack_Settings::get('social_sharing_colour');
        $css    = '.sps-share{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 var(--global-md-spacing,1.5rem);clear:both}'
            . '.sps-share--after{margin:var(--global-md-spacing,1.5rem) 0 0}'
            . '.sps-share--woocommerce{margin:1em 0 0}'
            . '.sps-share__text{margin-inline-end:4px}'
            . '.sps-share a{--sps-share-size:32px;position:relative;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:var(--sps-share-size);height:var(--sps-share-size);border-radius:50%;text-decoration:none;line-height:0;transition:background-color .2s,color .2s,border-color .2s,opacity .2s}'
            . '.sps-share--large a{--sps-share-size:48px}'
            . '.sps-share a svg{width:50%;height:50%;fill:currentColor}'
            // Filled: the colour behind a white logo; black and white logos and Email invert the text colour.
            . '.sps-share--filled a{background:var(--sps-share-brand);color:#fff;border:0}'
            . '.sps-share--filled a.sps-share--mono{background:currentColor;color:inherit}'
            . '.sps-share--filled a.sps-share--mono svg{fill:var(--global-palette9,#fff)}'
            . '.sps-share--filled a:hover,.sps-share--filled a:focus-visible{opacity:.85}'
            // Outline: the text colour (or the chosen one), filled with the brand colour on hover.
            . '.sps-share--outline a{background:transparent;color:inherit;border:1px solid currentColor}'
            . '.sps-share--outline a:hover,.sps-share--outline a:focus-visible{background:var(--sps-share-brand);border-color:var(--sps-share-brand);color:#fff}'
            . '.sps-share--outline a.sps-share--mono:hover,.sps-share--outline a.sps-share--mono:focus-visible{background:currentColor;border-color:currentColor}'
            . '.sps-share--outline a.sps-share--mono:hover svg,.sps-share--outline a.sps-share--mono:focus-visible svg{fill:var(--global-palette9,#fff)}'
            . '.sps-share a:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
            // Tooltips from the link's name.
            . '.sps-share[data-tip] a::after{content:attr(aria-label);position:absolute;left:50%;transform:translateX(-50%);z-index:10;padding:4px 8px;border-radius:4px;background:var(--global-palette3,#1a202c);color:var(--global-palette9,#fff);font-size:12px;line-height:1.4;white-space:nowrap;pointer-events:none;opacity:0;visibility:hidden;transition:opacity .15s}'
            . '.sps-share[data-tip=top] a::after{bottom:calc(100% + 6px)}'
            . '.sps-share[data-tip=bottom] a::after{top:calc(100% + 6px)}'
            . '.sps-share[data-tip] a:hover::after,.sps-share[data-tip] a:focus-visible::after{opacity:1;visibility:visible}'
            . '@media (prefers-reduced-motion:reduce){.sps-share a,.sps-share a::after{transition:none}}';
        if ('' !== $colour && array_key_exists($colour, self::colour_options())) {
            // One palette colour for every brand; the outline style uses it for the ring and logo too.
            $css .= '.sps-share a{--sps-share-brand:var(--global-' . $colour . ',currentColor)!important}'
                . '.sps-share--filled a.sps-share--mono{background:var(--sps-share-brand)}'
                . '.sps-share--filled a svg{fill:' . self::logo_on($colour) . '}'
                . '.sps-share--outline a{color:var(--sps-share-brand)}'
                . '.sps-share--outline a:hover,.sps-share--outline a:focus-visible,.sps-share--outline a.sps-share--mono:hover,.sps-share--outline a.sps-share--mono:focus-visible{background:var(--sps-share-brand);border-color:var(--sps-share-brand)}'
                . '.sps-share--outline a:hover svg,.sps-share--outline a:focus-visible svg{fill:' . self::logo_on($colour) . '!important}';
        }
        return $css;
    }

    /**
     * The logo colour on a filled button of a palette colour, so it
     * contrasts in Kadence's light and dark modes: text colours (3 to 6)
     * swap with the background, so the logo takes the background; on
     * background colours (7 to 9) it takes the strongest text; accents and
     * notices keep their colour in both modes, so the logo is white.
     *
     * @param string $colour Palette key, such as palette1.
     * @return string CSS colour.
     */
    private static function logo_on($colour) {
        $n = (int) substr($colour, 7);
        if ($n >= 3 && $n <= 6) {
            return 'var(--global-palette9,#fff)';
        }
        if ($n >= 7 && $n <= 9) {
            return 'var(--global-palette3,#1a202c)';
        }
        return '#fff';
    }

    /**
     * The buttons before or after the post's content, on its own page.
     *
     * @param string $content Content.
     * @return string
     */
    public static function content($content) {
        if (!in_the_loop() || !is_main_query() || doing_filter('get_the_excerpt')) {
            return $content;
        }
        $post = self::current();
        // Products show them where WooCommerce puts sharing (product()).
        if (!$post || get_the_ID() !== $post->ID || 'product' === $post->post_type || has_shortcode($post->post_content, self::SHORTCODE)) {
            return $content;
        }
        $place = 'before' === SEOProStack_Settings::get('social_sharing_place') ? 'before' : 'after';
        $html  = self::buttons($post, $place);
        return 'before' === $place ? $html . $content : $content . $html;
    }

    /**
     * The buttons in WooCommerce's sharing place on a product page.
     */
    public static function product() {
        $post = self::current();
        if ($post && 'product' === $post->post_type) {
            echo self::buttons($post, 'woocommerce'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in buttons().
        }
    }

    /**
     * The share buttons for a post.
     *
     * @param WP_Post $post  Post.
     * @param string  $where before, after, woocommerce or shortcode (a class).
     * @return string
     */
    public static function buttons(WP_Post $post, $where = 'after') {
        $chosen = (array) SEOProStack_Settings::get('social_sharing_networks');
        $all    = self::networks();
        $url    = (string) get_permalink($post);
        if (!$chosen || '' === $url) {
            return '';
        }
        self::assets();

        $title = html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES, 'UTF-8');
        $media = has_post_thumbnail($post) ? (string) wp_get_attachment_image_url((int) get_post_thumbnail_id($post), 'full') : '';
        $codes = array('{url}' => rawurlencode($url), '{title}' => rawurlencode($title), '{media}' => rawurlencode($media));
        $style = 'outline' === SEOProStack_Settings::get('social_sharing_style') ? 'outline' : 'filled';
        $size  = 'large' === SEOProStack_Settings::get('social_sharing_size') ? 'large' : 'normal';
        $tip   = (string) SEOProStack_Settings::get('social_sharing_tooltip');
        $popup = 'popup' === SEOProStack_Settings::get('social_sharing_window');
        $text  = trim((string) SEOProStack_Settings::get('social_sharing_text'));

        $links = '';
        foreach ($chosen as $key) {
            if (!isset($all[$key])) {
                continue;
            }
            list($name, $slug, $pattern) = $all[$key];
            $href = strtr($pattern, $codes);
            if ('pinterest' === $key && '' === $media) {
                $href = str_replace('&media=', '', $href);
            }
            $icon  = '' !== $slug ? SEOProStack_Brand_Icons::icon($slug) : null;
            $color = $icon ? SEOProStack_Brand_Icons::brand_color($icon['hex']) : 'currentColor';
            $mono  = 'currentColor' === $color;
            $label = 'email' === $key
                ? __('Share by email', 'seoprostack')
                /* translators: %s: a social network, such as Facebook. */
                : sprintf(__('Share on %s', 'seoprostack'), $name);
            $mail  = 0 === strpos($href, 'mailto:');

            // sps-no-external-icon: no External link icon after the logo.
            $links .= '<a class="sps-share__' . esc_attr($key) . ' sps-no-external-icon' . ($mono ? ' sps-share--mono' : '') . '" href="' . esc_url($href, array('https', 'mailto')) . '"'
                . ($mono ? '' : ' style="--sps-share-brand:' . esc_attr($color) . '"')
                . ' aria-label="' . esc_attr($label) . '"'
                . ($mail ? '' : ' target="_blank" rel="noopener nofollow"' . ($popup ? ' data-sps-popup' : ''))
                . '>' . self::svg($icon) . '</a>';
        }
        if ('' === $links) {
            return '';
        }
        $class = 'sps-share sps-share--' . $style . ' sps-share--' . sanitize_html_class($where) . ('large' === $size ? ' sps-share--large' : '');
        return '<div class="' . esc_attr($class) . '"' . (in_array($tip, array('top', 'bottom'), true) ? ' data-tip="' . esc_attr($tip) . '"' : '') . ' role="group" aria-label="' . esc_attr__('Share', 'seoprostack') . '">'
            . ('' !== $text ? '<span class="sps-share__text">' . esc_html($text) . '</span>' : '')
            . $links . '</div>';
    }

    /**
     * An icon as SVG (an envelope without a brand icon), hidden from screen
     * readers: the link has the name.
     *
     * @param array|null $icon Icon from SEOProStack_Brand_Icons::icon().
     * @return string
     */
    private static function svg($icon) {
        if (!$icon) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 5.5A1.5 1.5 0 0 1 3.5 4h17A1.5 1.5 0 0 1 22 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-17A1.5 1.5 0 0 1 2 18.5zm2.2.5L12 12.1 19.8 6zM20 7.9l-7.4 5.8a1 1 0 0 1-1.2 0L4 7.9V18h16z"/></svg>';
        }
        $svg = '<svg viewBox="' . esc_attr($icon['viewBox']) . '" aria-hidden="true" focusable="false">';
        foreach ($icon['paths'] as $d) {
            $svg .= '<path d="' . esc_attr($d) . '"/>';
        }
        return $svg . '</svg>';
    }

    /**
     * Share buttons: Default, Show or Hide, for each post in the editor.
     */
    public static function editor() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        // The meta is in the REST API only for types with custom fields.
        if (!$screen || empty($screen->post_type) || !is_post_type_viewable($screen->post_type) || !post_type_supports($screen->post_type, 'custom-fields')) {
            return;
        }
        $types = (array) SEOProStack_Settings::get('social_sharing_types');
        wp_enqueue_script(self::EDITOR, SEOPROSTACK_URL . 'admin/js/seoprostack-social-sharing.js', array('wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-editor', 'wp-i18n'), SEOPROSTACK_VERSION, true);
        wp_localize_script(self::EDITOR, 'seoprostackSocialSharing', array(
            'meta'    => self::META,
            'label'   => __('Share buttons', 'seoprostack'),
            'options' => array(
                array('value' => '', 'label' => in_array($screen->post_type, $types, true) ? __('Default (shown)', 'seoprostack') : __('Default (hidden)', 'seoprostack')),
                array('value' => 'show', 'label' => __('Show', 'seoprostack')),
                array('value' => 'hide', 'label' => __('Hide', 'seoprostack')),
            ),
        ));
    }
}
