<?php
/**
 * External link icons without visitor-side scripts or asset requests.
 *
 * Mark links in rendered content, never in stored posts. A CSS mask follows
 * currentColor, including hover and Kadence's live palette changes.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_External_Links extends SEOProStack_Feature {

    const KEY = 'external_link_icons';

    /**
     * Settings. This is only Link Whisper's icon feature, not its link tools.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'links',
                'label'       => __('External link icons', 'seoprostack'),
                'description' => __('Show a small icon after text links to other websites in posts and text widgets. It follows the link colour, including Kadence dark mode, without scripts or downloads. Only the icon feature of Link Whisper is covered; keep it if you use its other tools.', 'seoprostack'),
            ),
            'external_link_icons_exclude_domains' => array(
                'type'        => 'domains',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Do not mark these domains', 'seoprostack'),
                'description' => __('One domain per line, including its subdomains. Use this for related websites you want to treat as internal.', 'seoprostack'),
            ),
        );
    }

    /** Register only front-end hooks while enabled. */
    public static function boot() {
        if (!self::enabled() || is_admin() || !class_exists('WP_HTML_Tag_Processor')) {
            return;
        }
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'));
        add_filter('the_content', array(__CLASS__, 'mark'), 100);
        add_filter('widget_block_content', array(__CLASS__, 'mark'), 100);
        add_filter('widget_text_content', array(__CLASS__, 'mark'), 100);
    }

    /**
     * Mark absolute HTTP(S) links to other hosts in one HTML-tokenizer pass.
     *
     * Excerpts, feeds and REST responses stay untouched. WordPress's tokenizer
     * preserves markup and skips apparent links inside scripts and comments.
     * Repeated filtering only adds the class once.
     *
     * @param string $html Rendered content.
     * @return string
     */
    public static function mark($html) {
        if (is_feed() || (defined('REST_REQUEST') && REST_REQUEST) || doing_filter('get_the_excerpt') || false === stripos($html, '<a')) {
            return $html;
        }
        $home = self::host((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ('' === $home) {
            return $html;
        }
        $excluded = preg_split('/\s+/', (string) SEOProStack_Settings::get('external_link_icons_exclude_domains'), -1, PREG_SPLIT_NO_EMPTY);
        $tags = new WP_HTML_Tag_Processor($html);
        while ($tags->next_tag(array('tag_name' => 'A'))) {
            $href = trim((string) $tags->get_attribute('href'));
            if (!preg_match('~^(?:https?:)?//~i', $href) || null !== $tags->get_attribute('download') || 'button' === $tags->get_attribute('role')) {
                continue;
            }
            $parts = wp_parse_url($href);
            if (empty($parts['host'])) {
                continue;
            }
            $host = self::host($parts['host']);
            if ($home === $host || self::excluded($host, $excluded ?: array())) {
                continue;
            }
            $skip = false;
            foreach (array('sps-no-external-icon', 'wp-block-button__link', 'wp-element-button', 'kb-button', 'button') as $class) {
                if ($tags->has_class($class)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $tags->add_class('sps-external-link');
            }
        }
        return $tags->get_updated_html();
    }

    /**
     * Treat www and a trailing DNS dot as the same host, case-insensitively.
     *
     * @param string $host Hostname.
     * @return string
     */
    private static function host($host) {
        return (string) preg_replace('/^www\./', '', strtolower(rtrim($host, '.')));
    }

    /**
     * Match domain boundaries, not substrings (example.com.evil is external).
     *
     * @param string   $host    Normalised hostname.
     * @param string[] $domains Bypass domains.
     * @return bool
     */
    private static function excluded($host, array $domains) {
        foreach ($domains as $domain) {
            $domain = self::host($domain);
            if ('' !== $domain && ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Print tiny styles in the head, before content paints: no extra request.
     * Empty generated content is decorative, not added to accessible names.
     * :has() safely omits media links and icons already supplied by content.
     * Browsers without :has() leave links unchanged rather than mislabel them.
     */
    public static function styles() {
        $mask = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\'%3E%3Cpath fill=\'none\' stroke=\'black\' stroke-width=\'1.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M9 2h5v5M14 2L7 9M6 3H3a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-3\'/%3E%3C/svg%3E")';
        wp_register_style('seoprostack-external-links', false, array(), SEOPROSTACK_VERSION);
        wp_enqueue_style('seoprostack-external-links');
        wp_add_inline_style('seoprostack-external-links',
            'a.sps-external-link:not(:empty):not(:has(img,svg,picture,video,canvas,[class*="wpil"],.sps-external-link-icon)):not(.sps-no-external-icon):not(.sps-no-external-icon a)::after{content:"";display:inline-block;inline-size:.75em;block-size:.75em;margin-inline-start:.2em;vertical-align:baseline;background-color:currentColor;--sps-external-mask:' . $mask . ';-webkit-mask:var(--sps-external-mask) center/contain no-repeat;mask:var(--sps-external-mask) center/contain no-repeat}'
            . '@media(forced-colors:active){a.sps-external-link::after{background-color:LinkText;forced-color-adjust:none}}'
        );
    }
}
