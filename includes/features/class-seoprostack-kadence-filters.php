<?php
/**
 * Kadence query filters scroll to results.
 *
 * When a visitor changes a filter (checkbox, radio button or drop-down) in
 * a Kadence Blocks Pro query loop, the results reload in place. On a long
 * list the visitor is left partway down the page, below where the new
 * results start. This scrolls back to the top of the loop, less an offset
 * for sticky headers, and only when the loop's top is above the window.
 *
 * The script is under 1 KB, printed inline in the footer only on
 * pages where a kadence/query block was drawn: in the post, a Kadence
 * Element, a block template or a widget alike, since every one goes through
 * render_block. No jQuery, no file to download. With reduced motion set the
 * jump is instant.
 *
 * Kadence query filters accept term slugs (a setting of its own): Kadence's
 * taxonomy filters send and match term IDs (?make=123), so a readable link
 * naming a term by its slug (?make=bentley) shows no results. On a page
 * whose content has a query loop, such a link is sent on (302) to the same
 * address with the term's ID.
 *
 * Its child, Kadence query filters use term slugs in addresses, replaces the
 * redirect: around Kadence's query loop REST route, which draws the results
 * and filters on page load and on every change, slugs in the arguments
 * become IDs for Kadence, and taxonomy filter options get slugs as values,
 * so Kadence's own script puts slugs in the address.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.12.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Filters extends SEOProStack_Feature {

    const KEY = 'kadence_filter_scroll';

    /** Setting: links may name a taxonomy filter's term by its slug. */
    const SLUGS_KEY = 'kadence_filter_term_slugs';

    /** Setting (child of SLUGS_KEY): taxonomy filters put term slugs in the address. */
    const ADDRESS_KEY = 'kadence_filter_slug_addresses';

    /** Kadence Blocks Pro's query loop route, for page loads and filter changes alike. */
    const QUERY_ROUTE = '/wp/v2/kadence_query/query';

    /** Most query loops looked up on one page. */
    const MAX_LOOPS = 10;

    /** Whether a query loop was drawn on this page. @var bool */
    private static $seen = false;

    /**
     * Request arguments as they were before slugs became IDs, by REST request.
     *
     * @var array<int,array{get:array<string,mixed>,request:array<string,mixed>}>
     */
    private static $originals = array();

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
                'label'       => __('Kadence query filters scroll to results', 'seoprostack'),
                'description' => __('When a visitor changes a filter in a Kadence Blocks Pro query loop, scroll back up to the results instead of leaving them partway down the page. Only on pages with a query loop. Nothing changes if Kadence Blocks Pro is not active.', 'seoprostack'),
            ),
            'kadence_filter_scroll_offset' => array(
                'type'        => 'int',
                'default'     => 0,
                'min'         => 0,
                'max'         => 500,
                'unit'        => __('px', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Space above the results', 'seoprostack'),
                'description' => __('Room to leave above the loop, such as the height of a sticky header. The admin bar is allowed for already.', 'seoprostack'),
            ),
            self::SLUGS_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'content',
                'label'       => __('Kadence query filters accept term slugs', 'seoprostack'),
                'description' => __('Links that name a category, tag or other term in a Kadence Blocks Pro query loop filter by its slug, such as ?make=bentley, show its results: the visitor goes on to the same address with the term ID that Kadence uses. Only on pages whose content has a query loop. Nothing changes if Kadence Blocks Pro is not active.', 'seoprostack'),
            ),
            self::ADDRESS_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::SLUGS_KEY,
                'label'       => __('Kadence query filters use term slugs in addresses', 'seoprostack'),
                'description' => __('Taxonomy filters show the term slug in the address instead of its ID, such as ?make=bentley, when a visitor picks a term, and a link with the slug is shown without going on to the ID address. Links with term IDs still work. Loops in Kadence Elements and block templates included.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (is_admin()) {
            return;
        }
        if (self::enabled()) {
            add_filter('render_block_kadence/query', array(__CLASS__, 'seen'));
            // After blocks that other footer hooks draw, such as Kadence Elements.
            add_action('wp_footer', array(__CLASS__, 'print_script'), 100);
        }
        if (!SEOProStack_Settings::get(self::SLUGS_KEY)) {
            return;
        }
        if (SEOProStack_Settings::get(self::ADDRESS_KEY)) {
            // Kadence draws a loop's results and filters through its REST
            // route, inside the page on load and by fetch on a change.
            add_filter('rest_request_before_callbacks', array(__CLASS__, 'rest_term_ids'), 10, 3);
            add_filter('rest_request_after_callbacks', array(__CLASS__, 'rest_term_slugs'), 10, 3);
            return;
        }
        // After the site's own handlers at 10, which may still want the slug.
        add_action('template_redirect', array(__CLASS__, 'term_slug_redirect'), 20);
    }

    /**
     * Before Kadence's query loop route runs, change term slugs in its
     * taxonomy filter arguments to the term IDs that Kadence matches.
     *
     * Kadence reads filter values from $_GET, both on the REST request a
     * filter change sends and inside the page, where the loop's blocks run
     * the route with the page's own arguments. The address keeps the slug.
     *
     * @since 1.3.53
     * @param mixed           $response Result so far: null, or a response or error.
     * @param mixed           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed Unchanged.
     */
    public static function rest_term_ids($response, $handler, $request) {
        if (null !== $response || !self::is_query_request($request)) {
            return $response;
        }
        $loop = (int) $request->get_param('ql_id');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public filter arguments, read only; values are matched against term slugs.
        $changes = $loop > 0 && !empty($_GET) ? self::term_ids_for_slugs(array($loop), wp_unslash($_GET)) : array();
        if (!$changes) {
            return $response;
        }
        $saved = array('get' => array(), 'request' => array());
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- raw values kept unchanged, only to put back after the route runs; every key is in $_GET (term_ids_for_slugs read it).
        foreach ($changes as $key => $value) {
            $saved['get'][$key] = $_GET[$key];
            $_GET[$key]         = wp_slash($value);
            if (isset($_REQUEST[$key])) {
                $saved['request'][$key] = $_REQUEST[$key];
                $_REQUEST[$key]         = wp_slash($value);
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
        $request->set_query_params(array_merge($request->get_query_params(), $changes));
        self::$originals[spl_object_id($request)] = $saved;
        return $response;
    }

    /**
     * After Kadence's query loop route runs, put the request arguments back
     * and give taxonomy filter options term slugs as values, so Kadence's
     * script puts slugs in the address and selects the option a slug names.
     *
     * @since 1.3.53
     * @param mixed           $response Route result.
     * @param mixed           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public static function rest_term_slugs($response, $handler, $request) {
        if (!self::is_query_request($request)) {
            return $response;
        }
        $id = spl_object_id($request);
        if (isset(self::$originals[$id])) {
            foreach (self::$originals[$id]['get'] as $key => $value) {
                $_GET[$key] = $value;
            }
            foreach (self::$originals[$id]['request'] as $key => $value) {
                $_REQUEST[$key] = $value;
            }
            unset(self::$originals[$id]);
        }
        $loop = (int) $request->get_param('ql_id');
        if ($loop <= 0 || !$response instanceof WP_REST_Response) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data) || empty($data['filters']) || !is_array($data['filters'])) {
            return $response;
        }
        $changed = false;
        foreach (self::taxonomy_filters(array($loop)) as $filter) {
            $uid = $filter['uid'];
            if ('' === $uid || !isset($data['filters'][$uid]) || !is_string($data['filters'][$uid])) {
                continue;
            }
            $html = self::slug_values($data['filters'][$uid], $filter['taxonomy']);
            if ($html !== $data['filters'][$uid]) {
                $data['filters'][$uid] = $html;
                $changed               = true;
            }
        }
        if ($changed) {
            $response->set_data($data);
        }
        return $response;
    }

    /**
     * Whether a REST request is for Kadence's query loop route.
     *
     * @param mixed $request Request.
     * @return bool
     */
    private static function is_query_request($request) {
        return defined('KBP_VERSION') && $request instanceof WP_REST_Request
            && self::QUERY_ROUTE === untrailingslashit($request->get_route());
    }

    /**
     * Filter HTML with each option's term ID value (value="12" and
     * data-value="12") changed to the term's slug. A slug of digits alone
     * would read as an ID, so that term keeps its ID.
     *
     * @param string $html     A filter's options HTML from Kadence.
     * @param string $taxonomy The filter's taxonomy.
     * @return string
     */
    private static function slug_values($html, $taxonomy) {
        $pattern = '/\b((?:data-)?value)="(\d+)"/';
        if (!preg_match_all($pattern, $html, $matches)) {
            return $html;
        }
        $slugs = get_terms(array(
            'taxonomy'               => $taxonomy,
            'include'                => array_values(array_unique(array_map('intval', $matches[2]))),
            'hide_empty'             => false,
            'fields'                 => 'id=>slug',
            'update_term_meta_cache' => false,
        ));
        if (!is_array($slugs) || !$slugs) {
            return $html;
        }
        $result = preg_replace_callback(
            $pattern,
            static function ($match) use ($slugs) {
                $slug = isset($slugs[(int) $match[2]]) ? (string) $slugs[(int) $match[2]] : '';
                return '' === $slug || ctype_digit($slug) ? $match[0] : $match[1] . '="' . esc_attr($slug) . '"';
            },
            $html
        );
        return is_string($result) ? $result : $html;
    }

    /**
     * Send a link naming a taxonomy filter's term by its slug on to the same
     * address with the term's ID.
     *
     * @since 1.3.49
     */
    public static function term_slug_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public filter links, read only.
        if (empty($_GET) || !defined('KBP_VERSION') || !is_singular()) {
            return;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post || !has_block('kadence/query', $post)) {
            return;
        }
        $loops = self::loop_ids(parse_blocks($post->post_content));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public filter links, read only; values are matched against term slugs.
        $changes = $loops ? self::term_ids_for_slugs($loops, wp_unslash($_GET)) : array();
        if (!$changes) {
            return;
        }
        wp_safe_redirect(add_query_arg(array_map('rawurlencode', $changes)), 302, 'SEO Pro Stack');
        exit;
    }

    /**
     * IDs of the query loops (kadence_query posts) in parsed blocks.
     *
     * @param array $blocks Parsed blocks.
     * @param array $ids    IDs found so far.
     * @return int[]
     */
    private static function loop_ids(array $blocks, array $ids = array()) {
        foreach ($blocks as $block) {
            if (count($ids) >= self::MAX_LOOPS) {
                break;
            }
            // A loop repeated on the page counts once towards the limit.
            $id = 'kadence/query' === ($block['blockName'] ?? '') ? (int) ($block['attrs']['id'] ?? 0) : 0;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $ids = self::loop_ids($block['innerBlocks'], $ids);
            }
        }
        return $ids;
    }

    /**
     * Query arguments whose values name taxonomy filter terms by slug, with
     * those slugs replaced by term IDs.
     *
     * Kadence matches a request argument to a filter by the filter's slug
     * (set in the block) or its hash, and a taxonomy filter's values to the
     * index's term IDs, split by its split character.
     *
     * @param int[] $loops Query loop post IDs.
     * @param array $args  Query arguments, unslashed.
     * @return array<string,string> Argument => new value; empty if none change.
     */
    private static function term_ids_for_slugs(array $loops, array $args) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Kadence Blocks Pro's own filter, read so values split as Kadence splits them.
        $split = (string) apply_filters('kadence_blocks_pro_query_block_split_character', ',');
        if ('' === $split) {
            return array();
        }
        $changes = array();
        foreach (self::taxonomy_filters($loops) as $filter) {
            foreach ($filter['keys'] as $key) {
                if (isset($changes[$key]) || !isset($args[$key]) || !is_string($args[$key])) {
                    continue;
                }
                $value = self::term_ids_value($args[$key], $split, $filter['taxonomy']);
                if (null !== $value) {
                    $changes[$key] = $value;
                }
            }
        }
        return $changes;
    }

    /**
     * The taxonomy filters of query loops.
     *
     * @param int[] $loops Query loop post IDs.
     * @return array<int,array{taxonomy:string,keys:string[],uid:string}>
     */
    private static function taxonomy_filters(array $loops) {
        $filters = array();
        foreach ($loops as $loop) {
            $facets = get_post_meta($loop, '_kad_query_facets', true);
            foreach (is_array($facets) ? $facets : array() as $facet) {
                $filter = self::taxonomy_filter($facet);
                if (null !== $filter) {
                    $filters[] = $filter;
                }
            }
        }
        return $filters;
    }

    /**
     * A filter's taxonomy, the request arguments that name it (its hash
     * and its slug) and its block's unique ID, when it is a taxonomy filter.
     *
     * @param mixed $facet One entry of a loop's _kad_query_facets meta.
     * @return array{taxonomy:string,keys:string[],uid:string}|null
     */
    private static function taxonomy_filter($facet) {
        $attrs = is_array($facet) && isset($facet['attributes']) && is_string($facet['attributes'])
            ? json_decode($facet['attributes'], true) : null;
        if (!is_array($attrs) || 'taxonomy' !== ($attrs['source'] ?? '')) {
            return null;
        }
        $taxonomy = $attrs['taxonomy'] ?? '';
        if (!is_string($taxonomy) || '' === $taxonomy || !taxonomy_exists($taxonomy)) {
            return null;
        }
        $hash = $facet['hash'] ?? '';
        $slug = $attrs['slug'] ?? '';
        $uid  = $attrs['uniqueID'] ?? '';
        $keys = array_values(array_diff(
            array(is_scalar($hash) ? (string) $hash : '', is_string($slug) ? $slug : ''),
            array('')
        ));
        return $keys ? array(
            'taxonomy' => $taxonomy,
            'keys'     => $keys,
            'uid'      => is_scalar($uid) ? (string) $uid : '',
        ) : null;
    }

    /**
     * A filter value with each term slug replaced by its term ID.
     *
     * @param string $value    Value from the link.
     * @param non-empty-string $split Split character.
     * @param string $taxonomy Taxonomy.
     * @return string|null New value, or null when nothing changes.
     */
    private static function term_ids_value($value, $split, $taxonomy) {
        $parts   = explode($split, $value);
        $changed = false;
        foreach ($parts as $i => $part) {
            $part = trim($part);
            if ('' === $part || ctype_digit($part)) {
                continue;
            }
            $term = get_term_by('slug', sanitize_title($part), $taxonomy);
            if ($term instanceof WP_Term) {
                $parts[$i] = (string) $term->term_id;
                $changed   = true;
            }
        }
        return $changed ? implode($split, $parts) : null;
    }

    /**
     * Note that a query loop was drawn.
     *
     * @param string $content Block HTML.
     * @return string Unchanged.
     */
    public static function seen($content) {
        self::$seen = true;
        return $content;
    }

    /**
     * Print the script on pages with a query loop.
     */
    public static function print_script() {
        if (!self::$seen || !defined('KBP_VERSION') || !function_exists('wp_print_inline_script_tag')) {
            return;
        }
        $offset = max(0, (int) SEOProStack_Settings::get('kadence_filter_scroll_offset'));
        wp_print_inline_script_tag(self::script($offset), array('id' => 'seoprostack-kadence-filters'));
    }

    /**
     * The script. Kadence draws each filter in a fieldset.kadence-filter-wrap
     * inside the loop (.wp-block-kadence-query, .kb-query). A filter outside a
     * loop scrolls to the page's only loop, or else to itself.
     *
     * @param int $offset Pixels to leave above the loop.
     * @return string
     */
    private static function script($offset) {
        return '(function(){var o=' . (int) $offset . ',q=".wp-block-kadence-query,.kb-query";'
            . 'function go(t){if(!t||!t.closest)return;var w=t.closest(".kadence-filter-wrap");if(!w)return;'
            . 'var l=w.closest(q);if(!l){var a=document.querySelectorAll(q);l=1===a.length?a[0]:w;}'
            . 'var r=document.documentElement,b=document.getElementById("wpadminbar"),'
            . 'y=l.getBoundingClientRect().top-o-(parseFloat(getComputedStyle(r).scrollPaddingTop)||0)'
            . '-(b&&"fixed"===getComputedStyle(b).position?b.offsetHeight:0);if(y>=0)return;y+=window.pageYOffset;'
            . 'if(window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches){'
            . 'var s=r.style.scrollBehavior;r.style.scrollBehavior="auto";window.scrollTo(0,y);r.style.scrollBehavior=s;}'
            . 'else{window.scrollTo({top:y,behavior:"smooth"});}}'
            . 'document.addEventListener("change",function(e){var t=e.target;'
            . 'if(t&&t.matches&&t.matches("input[type=checkbox],input[type=radio],select"))go(t);});'
            . '})();';
    }
}
