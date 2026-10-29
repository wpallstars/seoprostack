<?php
/**
 * Links skip redirects: a link to an address the site permanently redirects
 * to another of its own pages points straight at that page.
 *
 * When a page is renamed or moved, Rank Math → Redirections keeps its old
 * address working with a 301, but links in posts, block widgets and menus
 * still point at the old address, so every visit and crawl takes an extra
 * step through the redirect. With this on, such links are written with the
 * address the redirect ends at when the page is shown (following one
 * redirect to the next, up to five), so visitors and search engines go
 * straight there. The content saved in the editor is not changed.
 *
 * Only active, exact-match, permanent (301 and 308) redirections from one
 * address of this site to another are used. A temporary redirect keeps the
 * old address as the one to link to, and a redirect to another site (such as
 * a /go/ affiliate link) is left as it is, so its clicks still pass through
 * it. Links with a query string are left alone.
 *
 * The redirections are read once and kept for ten minutes (a transient), and
 * read again when Rank Math adds one for a changed slug or deletes one.
 * Nothing is read on a request that has no links to rewrite.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.106
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Redirect_Links extends SEOProStack_Feature {

    const KEY = 'redirect_links';

    /** Transient: the redirections, read from Rank Math. */
    const CACHE = 'seoprostack_redirect_links';

    /** Most redirections read. */
    const LIMIT = 500;

    /** Most redirects followed from one link. */
    const HOPS = 5;

    /**
     * Redirections for this request: exact (old path => where it goes) and
     * nocase (the same, for patterns that ignore case, by lower-case path).
     *
     * @var array{exact:array<string,string>,nocase:array<string,string>}|null
     */
    private static $map = null;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'links',
                'label'       => __('Links skip redirects', 'seoprostack'),
                'description' => __('Links in posts, pages, block widgets and menus to an old address that Rank Math permanently redirects to another page of this site point straight at that page, so visitors and search engines skip the redirect. What you saved is not changed, and redirects to other sites are left as they are.', 'seoprostack'),
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
        // Rank Math adds a redirection when a post's or term's slug changes, and deletes them from its screen.
        add_action('rank_math/redirection/post_updated', array(__CLASS__, 'forget'));
        add_action('rank_math/redirection/term_updated', array(__CLASS__, 'forget'));
        add_action('rank_math/redirection/deleted', array(__CLASS__, 'forget'));
        if (is_admin()) {
            return;
        }
        add_filter('the_content', array(__CLASS__, 'html'), 20);
        add_filter('widget_block_content', array(__CLASS__, 'html'), 20);
        add_filter('wp_nav_menu_objects', array(__CLASS__, 'menu'), 20);
    }

    /**
     * Read the redirections again on the next request that needs them.
     */
    public static function forget() {
        self::$map = null;
        delete_transient(self::CACHE);
    }

    /**
     * The redirections links skip.
     *
     * @return array{exact:array<string,string>,nocase:array<string,string>}
     */
    private static function map() {
        if (null !== self::$map) {
            return self::$map;
        }
        $map = get_transient(self::CACHE);
        if (!is_array($map) || !isset($map['exact'], $map['nocase'])) {
            $map = self::read();
            if (null === $map) {
                // Rank Math is not loaded on this request: skip nothing, and keep no empty list.
                $map = array('exact' => array(), 'nocase' => array());
            } else {
                set_transient(self::CACHE, $map, 10 * MINUTE_IN_SECONDS);
            }
        }
        /**
         * Filter the redirects links skip: old path (without slashes,
         * relative to the site's home) => the address it goes to on this
         * site. Add redirects another plugin keeps here.
         *
         * @param array<string,string> $exact Redirects, from Rank Math.
         */
        $exact = apply_filters('seoprostack_redirect_links', (array) $map['exact']);
        self::$map = array(
            'exact'  => is_array($exact) ? array_map('strval', $exact) : array(),
            'nocase' => (array) $map['nocase'],
        );
        return self::$map;
    }

    /**
     * Rank Math's active, exact, permanent redirections from this site to
     * this site; null while Rank Math or its Redirections module is not
     * loaded.
     *
     * @return array{exact:array<string,string>,nocase:array<string,string>}|null
     */
    private static function read() {
        if (!did_action('rank_math/loaded') || !class_exists('\RankMath\Helper') || !\RankMath\Helper::is_module_active('redirections')) {
            return null;
        }
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Rank Math's own table, read at most every ten minutes and kept in a transient.
            $wpdb->prepare(
                'SELECT sources, url_to FROM %i WHERE status = %s AND header_code IN (301, 308) ORDER BY id LIMIT %d',
                $wpdb->prefix . 'rank_math_redirections',
                'active',
                self::LIMIT
            ),
            ARRAY_A
        );
        $map = array('exact' => array(), 'nocase' => array());
        foreach ((array) $rows as $row) {
            $target = (string) $row['url_to'];
            if (null === self::relative($target)) {
                continue;
            }
            $sources = maybe_unserialize($row['sources']);
            foreach (is_array($sources) ? $sources : array() as $source) {
                if (!is_array($source) || 'exact' !== ($source['comparison'] ?? '')) {
                    continue;
                }
                $path = trim((string) ($source['pattern'] ?? ''), '/');
                if ('' === $path || false !== strpos($path, '?')) {
                    continue;
                }
                if ('case' === ($source['ignore'] ?? '')) {
                    $map['nocase'][strtolower($path)] = $target;
                } else {
                    $map['exact'][$path] = $target;
                }
            }
        }
        return $map;
    }

    /**
     * An address's path relative to the site's home, without slashes, when
     * it is a page of this site with no query string; null otherwise.
     *
     * @param string $url Address, absolute or root-relative.
     * @return string|null
     */
    private static function relative($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || isset($parts['query']) || !isset($parts['path'])) {
            return null;
        }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return null;
        }
        $home = wp_parse_url(home_url('/'));
        if (isset($parts['host'])) {
            if (strtolower($parts['host']) !== strtolower((string) ($home['host'] ?? ''))) {
                return null;
            }
        } elseif (0 !== strpos($parts['path'], '/')) {
            return null;
        }
        $base = trailingslashit((string) ($home['path'] ?? '/'));
        $path = trailingslashit($parts['path']);
        if (0 !== strpos($path, $base)) {
            return null;
        }
        return trim(substr($path, strlen($base)), '/');
    }

    /**
     * Where a redirect from a path goes, or null.
     *
     * @param string $path Path relative to the home, without slashes.
     * @return string|null
     */
    private static function target($path) {
        if ('' === $path) {
            return null;
        }
        $map = self::map();
        if (isset($map['exact'][$path])) {
            return $map['exact'][$path];
        }
        $lower = strtolower($path);
        return isset($map['nocase'][$lower]) ? $map['nocase'][$lower] : null;
    }

    /**
     * Where a link ends after the site's own permanent redirects; the link
     * unchanged when it does not redirect.
     *
     * @param string $url Address.
     * @return string
     */
    public static function final_url($url) {
        $url   = (string) $url;
        $split = explode('#', $url, 2);
        $path  = self::relative($split[0]);
        if (null === $path) {
            return $url;
        }
        $final = null;
        $seen  = array($path => true);
        for ($hop = 0; $hop < self::HOPS; $hop++) {
            $next = self::target($path);
            if (null === $next) {
                break;
            }
            $final = $next;
            $path  = self::relative($next);
            if (null === $path || isset($seen[$path])) {
                break;
            }
            $seen[$path] = true;
        }
        if (null === $final) {
            return $url;
        }
        return isset($split[1]) ? explode('#', $final, 2)[0] . '#' . $split[1] : $final;
    }

    /**
     * Whether some old address appears in a piece of HTML, before parsing it.
     *
     * @param string $html HTML.
     * @return bool
     */
    private static function mentions_old_address($html) {
        $map = self::map();
        foreach (array_keys($map['exact']) as $path) {
            if (false !== strpos($html, '/' . $path)) {
                return true;
            }
        }
        foreach (array_keys($map['nocase']) as $path) {
            if (false !== stripos($html, '/' . $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Point links in content and block widgets past redirects.
     *
     * @param string $html Rendered HTML.
     * @return string
     */
    public static function html($html) {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, '<a') || !class_exists('WP_HTML_Tag_Processor') || !self::mentions_old_address($html)) {
            return $html;
        }
        $tags = new WP_HTML_Tag_Processor($html);
        while ($tags->next_tag(array('tag_name' => 'a'))) {
            $href = $tags->get_attribute('href');
            if (!is_string($href) || '' === $href) {
                continue;
            }
            $final = self::final_url($href);
            if ($final !== $href) {
                $tags->set_attribute('href', $final);
            }
        }
        return $tags->get_updated_html();
    }

    /**
     * Point menu links past redirects.
     *
     * @param WP_Post[] $items Menu items.
     * @return WP_Post[]
     */
    public static function menu($items) {
        foreach ((array) $items as $item) {
            if (isset($item->url) && is_string($item->url) && '' !== $item->url && false === strpos($item->url, '?')) {
                $item->url = self::final_url($item->url);
            }
        }
        return $items;
    }
}
