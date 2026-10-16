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

    /** Most query loops looked up on one page. */
    const MAX_LOOPS = 10;

    /** Whether a query loop was drawn on this page. @var bool */
    private static $seen = false;

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
        if (SEOProStack_Settings::get(self::SLUGS_KEY)) {
            // After the site's own handlers at 10, which may still want the slug.
            add_action('template_redirect', array(__CLASS__, 'term_slug_redirect'), 20);
        }
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
     * @return array<int,array{taxonomy:string,keys:string[]}>
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
     * A filter's taxonomy and the request arguments that name it (its hash
     * and its slug), when it is a taxonomy filter.
     *
     * @param mixed $facet One entry of a loop's _kad_query_facets meta.
     * @return array{taxonomy:string,keys:string[]}|null
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
        $keys = array_values(array_diff(
            array(is_scalar($hash) ? (string) $hash : '', is_string($slug) ? $slug : ''),
            array('')
        ));
        return $keys ? array('taxonomy' => $taxonomy, 'keys' => $keys) : null;
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
