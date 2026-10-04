<?php
/**
 * 410 Gone for removed addresses.
 *
 * Search engines drop a URL faster when it answers "410 Gone" instead of
 * "404 Not Found". List removed addresses (one per line; end with * to match
 * everything that starts with it) and, optionally, add posts automatically
 * when they are deleted. Only addresses that would otherwise be "not found"
 * are affected, so a live page can never be taken down by mistake. Visitors
 * still see the theme's normal not-found page. Replaces "Ultimate 410".
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Gone_Urls extends SEOProStack_Feature {

    const KEY = 'gone_urls';

    /** Most entries kept when adding deleted posts automatically. */
    const MAX_AUTO = 2000;

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
                'tab'         => 'links',
                'label'       => __('410 Gone for removed pages', 'seoprostack'),
                'description' => __('Tell search engines that removed addresses are gone for good, so they drop them sooner. Only addresses that would otherwise show “not found” are affected.', 'seoprostack'),
                'replaces'    => array('ultimate-410' => 'Ultimate 410'),
            ),
            'gone_urls_list' => array(
                'type'        => 'lines',
                'default'     => '',
                'rows'        => 8,
                'placeholder' => "/old-page/\n/old-shop/*",
                'parent'      => self::KEY,
                'label'       => __('Removed addresses', 'seoprostack'),
                'description' => __('One per line, as a path such as /old-page/ or a full address on this site. End with * to include everything below it, such as /old-shop/*.', 'seoprostack'),
            ),
            'gone_urls_deleted' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Add posts when they are permanently deleted', 'seoprostack'),
                'description' => __('Published posts, pages and other public content deleted from the bin are added to the list.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // Before redirect_canonical() guesses a similar post for the 404.
        add_action('template_redirect', array(__CLASS__, 'maybe_gone'), 1);
        if (SEOProStack_Settings::get('gone_urls_deleted')) {
            add_action('before_delete_post', array(__CLASS__, 'remember_deleted'), 10, 2);
        }
    }

    /**
     * Normalise an address to its decoded path from the domain root, without
     * trailing slash or query string.
     *
     * @param string $url Path or full URL.
     * @return string|null Null for other sites.
     */
    public static function normalise($url) {
        $url  = trim((string) $url);
        $home = wp_parse_url(home_url('/'));
        if (preg_match('#^(https?:)?//#i', $url)) {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || !is_array($home) || empty($parts['host']) || empty($home['host']) || strtolower($parts['host']) !== strtolower($home['host'])) {
                return null;
            }
            $url = isset($parts['path']) ? $parts['path'] : '/';
        } else {
            $url = strtok($url, '?#');
        }
        $url = '/' . ltrim(rawurldecode((string) $url), '/');
        return '/' === $url ? $url : untrailingslashit($url);
    }

    /**
     * The saved list as [path => is_prefix].
     *
     * @return array<string,bool>
     */
    public static function rules() {
        $rules = array();
        foreach (preg_split('/\n/', (string) SEOProStack_Settings::get('gone_urls_list'), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
            $prefix = '*' === substr($line, -1);
            $path   = self::normalise($prefix ? substr($line, 0, -1) : $line);
            if (null !== $path && '' !== $path) {
                // "/old/*" matches /old and anything below; "/*" is ignored so the whole site cannot be marked gone.
                if ($prefix && '/' === $path) {
                    continue;
                }
                $rules[$path] = $prefix || !empty($rules[$path]);
            }
        }
        return $rules;
    }

    /**
     * Whether a request path is listed.
     *
     * @param string $path Normalised request path.
     * @return bool
     */
    public static function is_gone($path) {
        foreach (self::rules() as $rule => $prefix) {
            if (0 === strcasecmp($rule, $path)) {
                return true;
            }
            if ($prefix && 0 === stripos($path, $rule . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Send 410 instead of 404 for listed addresses.
     */
    public static function maybe_gone() {
        if (!is_404() || empty($_SERVER['REQUEST_URI'])) {
            return;
        }
        $path = self::normalise(wp_unslash($_SERVER['REQUEST_URI'])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
        if (null === $path || !self::is_gone($path)) {
            return;
        }
        status_header(410);
        nocache_headers();
        header('X-Robots-Tag: noindex', true);
        // Keep the theme's not-found page; stop core guessing a redirect.
        remove_action('template_redirect', 'redirect_canonical');
    }

    /**
     * Add a published post's address when it is deleted for good.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function remember_deleted($post_id, $post = null) {
        $post = $post instanceof WP_Post ? $post : get_post($post_id);
        if (!$post || !is_post_type_viewable($post->post_type) || 'attachment' === $post->post_type) {
            return;
        }
        // Trashed posts had "__trashed" added to their slug; use the slug they had.
        $status = 'trash' === $post->post_status ? get_post_meta($post->ID, '_wp_trash_meta_status', true) : $post->post_status;
        if ('publish' !== $status) {
            return;
        }
        $slug = get_post_meta($post->ID, '_wp_desired_post_slug', true);
        $live = clone $post;
        $live->post_status = 'publish';
        if ($slug) {
            $live->post_name = $slug;
        }
        $path = self::normalise(get_permalink($live));
        if (!$path || '/' === $path || self::is_gone($path)) {
            return;
        }

        $lines   = preg_split('/\n/', (string) SEOProStack_Settings::get('gone_urls_list'), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $lines[] = trailingslashit($path);
        SEOProStack_Settings::set('gone_urls_list', implode("\n", array_slice($lines, -self::MAX_AUTO)));
    }
}
