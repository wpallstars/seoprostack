<?php
/**
 * iFrame block.
 *
 * A dynamic block (allstars/iframe) for embedding any page, with the controls
 * core's Embed block lacks: size or aspect ratio, lazy loading, sandbox
 * tokens, permissions policy, referrer policy, border and passing the page's
 * query string (e.g. UTM tags) through to the embedded page.
 *
 * Output is built on the server from validated attributes, so stored markup
 * cannot inject HTML. Site owners can limit which domains may be embedded and
 * who may use the block.
 *
 * @package Allstars
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Allstars_Iframe_Block extends Allstars_Feature {

    const KEY = 'iframe_block';

    /** Block name. */
    const BLOCK = 'allstars/iframe';

    /** Sandbox tokens offered in the editor. */
    const SANDBOX_TOKENS = array(
        'allow-scripts',
        'allow-same-origin',
        'allow-forms',
        'allow-popups',
        'allow-popups-to-escape-sandbox',
        'allow-presentation',
        'allow-modals',
        'allow-downloads',
        'allow-top-navigation-by-user-activation',
    );

    /** Permissions-policy features offered in the editor. */
    const ALLOW_FEATURES = array(
        'autoplay',
        'camera',
        'microphone',
        'geolocation',
        'clipboard-write',
        'encrypted-media',
        'picture-in-picture',
        'payment',
        'web-share',
        'fullscreen',
    );

    /** Referrer policies. */
    const REFERRER_POLICIES = array(
        'no-referrer',
        'no-referrer-when-downgrade',
        'origin',
        'origin-when-cross-origin',
        'same-origin',
        'strict-origin',
        'strict-origin-when-cross-origin',
        'unsafe-url',
    );

    /** Aspect ratios offered in the editor ('' = fixed width/height). */
    const RATIOS = array('', '16/9', '4/3', '3/2', '1/1', '9/16', '21/9');

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
                'tab'         => 'workflow',
                'label'       => __('iFrame block', 'allstars'),
                'description' => __('Adds an iFrame block to the editor with size, loading, sandbox, permission and referrer controls. Turning it off hides existing iFrame blocks.', 'allstars'),
            ),
            'iframe_block_domains' => array(
                'type'        => 'domains',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Allowed domains', 'allstars'),
                'description' => __('One domain per line; subdomains are included. Leave empty to allow any site.', 'allstars'),
                'placeholder' => "youtube.com\ncalendly.com",
            ),
            'iframe_block_capability' => array(
                'type'        => 'select',
                'default'     => 'edit_posts',
                'parent'      => self::KEY,
                'label'       => __('Who can add iFrames', 'allstars'),
                'description' => __('Checked in the editor and again when the page is shown, using the post author’s role.', 'allstars'),
                'options'     => array(
                    'edit_posts'      => __('Contributors and above', 'allstars'),
                    'publish_posts'   => __('Authors and above', 'allstars'),
                    'edit_others_posts' => __('Editors and above', 'allstars'),
                    'unfiltered_html' => __('Only people who can add any HTML', 'allstars'),
                ),
            ),
        );
    }

    /**
     * Register hooks when enabled.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // boot() runs on init, which is where blocks are registered.
        register_block_type(ALLSTARS_DIR . 'blocks/iframe');
        add_filter('allowed_block_types_all', array(__CLASS__, 'restrict_inserter'), 10, 2);
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_settings'));
    }

    /**
     * Hide the block from people below the chosen capability.
     *
     * @param bool|string[]           $allowed Allowed block names, or true for all.
     * @param WP_Block_Editor_Context $context Editor context.
     * @return bool|string[]
     */
    public static function restrict_inserter($allowed, $context) {
        unset($context);
        if (current_user_can(self::capability())) {
            return $allowed;
        }
        if (true === $allowed) {
            $allowed = array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered());
        }
        return is_array($allowed) ? array_values(array_diff($allowed, array(self::BLOCK))) : $allowed;
    }

    /**
     * Pass the domain allow-list to the editor so it can warn early.
     */
    public static function editor_settings() {
        $handle = generate_block_asset_handle(self::BLOCK, 'editorScript');
        wp_add_inline_script($handle, 'window.allstarsIframe = ' . wp_json_encode(array(
            'domains' => self::allowed_domains(),
        )) . ';', 'before');
    }

    /**
     * Capability required to use the block.
     *
     * @return string
     */
    public static function capability() {
        $cap = (string) Allstars_Settings::get('iframe_block_capability');
        return '' !== $cap ? $cap : 'edit_posts';
    }

    /**
     * Allowed domains ([] = any).
     *
     * @return string[]
     */
    public static function allowed_domains() {
        return Allstars_Settings::parse_domains(Allstars_Settings::get('iframe_block_domains'));
    }

    /**
     * Validate an embed URL.
     *
     * @param string $url URL.
     * @return string Clean URL, or '' when not allowed.
     */
    public static function clean_url($url) {
        $url = esc_url_raw(trim((string) $url), array('https', 'http'));
        if ('' === $url) {
            return '';
        }
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }
        $domains = self::allowed_domains();
        if ($domains && !Allstars_Settings::host_matches($host, $domains)) {
            return '';
        }
        return $url;
    }

    /**
     * Whether the author of the current post may use the block.
     *
     * @param WP_Block|null $block Block instance.
     * @return bool
     */
    public static function author_allowed($block = null) {
        $post_id = ($block instanceof WP_Block && !empty($block->context['postId'])) ? (int) $block->context['postId'] : (int) get_the_ID();
        $post    = $post_id ? get_post($post_id) : null;
        if (!$post || !in_array($post->post_type, get_post_types(array('public' => true)), true)) {
            return true; // Templates, widgets and patterns are edited by administrators.
        }
        return user_can((int) $post->post_author, self::capability());
    }

    /**
     * Build the iframe's HTML attributes from block attributes.
     *
     * @param array $attributes Block attributes.
     * @return array<string,string>|null Null when there is nothing to show.
     */
    public static function iframe_attributes(array $attributes) {
        $url = self::clean_url(isset($attributes['url']) ? $attributes['url'] : '');
        if ('' === $url) {
            return null;
        }

        $ratio = isset($attributes['aspectRatio']) && in_array($attributes['aspectRatio'], self::RATIOS, true) ? $attributes['aspectRatio'] : '';
        $attrs = array(
            'src'            => $url,
            'title'          => isset($attributes['title']) && '' !== trim($attributes['title']) ? sanitize_text_field($attributes['title']) : __('Embedded content', 'allstars'),
            'loading'        => !isset($attributes['lazy']) || $attributes['lazy'] ? 'lazy' : 'eager',
            'referrerpolicy' => isset($attributes['referrerPolicy']) && in_array($attributes['referrerPolicy'], self::REFERRER_POLICIES, true) ? $attributes['referrerPolicy'] : 'strict-origin-when-cross-origin',
        );

        if ('' === $ratio) {
            $width           = self::clean_width(isset($attributes['width']) ? $attributes['width'] : '100%');
            $height          = max(50, min(5000, isset($attributes['height']) ? (int) $attributes['height'] : 500));
            $attrs['height'] = (string) $height;
            $attrs['style']  = sprintf('width:%s;height:%dpx;', false === strpos($width, '%') ? $width . 'px' : $width, $height);
            if (false === strpos($width, '%')) {
                $attrs['width'] = $width;
            }
        } else {
            $attrs['style'] = 'width:100%;height:auto;aspect-ratio:' . $ratio . ';';
        }

        $allow = isset($attributes['allow']) ? array_values(array_intersect(self::ALLOW_FEATURES, (array) $attributes['allow'])) : array();
        if (!isset($attributes['allowFullscreen']) || $attributes['allowFullscreen']) {
            $attrs['allowfullscreen'] = '';
            $allow[]                  = 'fullscreen';
        }
        if ($allow) {
            $attrs['allow'] = implode('; ', array_unique($allow));
        }

        if (!isset($attributes['sandbox']) || $attributes['sandbox']) {
            $tokens           = isset($attributes['sandboxAllow']) ? (array) $attributes['sandboxAllow'] : array('allow-scripts', 'allow-same-origin', 'allow-forms', 'allow-popups', 'allow-presentation');
            $attrs['sandbox'] = implode(' ', array_intersect(self::SANDBOX_TOKENS, $tokens));
        }

        if (!empty($attributes['passParams'])) {
            $attrs['data-allstars-pass-params'] = '';
        }

        return $attrs;
    }

    /**
     * Normalise a width to "NNN" (pixels) or "NN%".
     *
     * @param mixed $width Raw width.
     * @return string
     */
    public static function clean_width($width) {
        $width = trim((string) $width);
        if (preg_match('/^(\d{1,4})(px)?$/', $width, $m)) {
            return (string) max(50, (int) $m[1]);
        }
        if (preg_match('/^(\d{1,3})%$/', $width, $m)) {
            return min(100, max(1, (int) $m[1])) . '%';
        }
        return '100%';
    }
}
