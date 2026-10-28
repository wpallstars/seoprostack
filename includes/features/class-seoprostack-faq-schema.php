<?php
/**
 * FAQ schema from Details blocks.
 *
 * Core's Details block (core/details) is the block editor's own way to write
 * a question and its answer: the summary is the question and the blocks
 * inside are the answer. Rank Math reads only its own FAQ block, so a page
 * of Details blocks had no FAQ schema. With this on, a single post or page
 * with two or more of them gets one FAQPage in Rank Math's JSON-LD, its
 * questions in page order, including Details blocks nested in other blocks
 * and in synced patterns. A lone Details block is more often a "show more"
 * than a question, so it is left alone.
 *
 * Nothing is added when the page's schema already has a FAQPage (Rank Math's
 * FAQ block, another plugin or the site's own code), or while the post needs
 * a password. Nothing is stored. Nothing changes without Rank Math.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Faq_Schema extends SEOProStack_Feature {

    const KEY = 'faq_schema';

    /** Key of the FAQPage among Rank Math's JSON-LD entities. */
    const ENTITY = 'seoprostackFaq';

    /** Fewest questions that make a page an FAQ. */
    const MIN_QUESTIONS = 2;

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
                'label'       => __('FAQ schema from Details blocks', 'seoprostack'),
                'description' => __('A post or page with two or more Details blocks (the question as the summary, the answer inside) gets them as FAQ schema in Rank Math’s structured data. Nothing is added where the page already has FAQ schema, such as Rank Math’s FAQ block, or needs a password. Only works while Rank Math is active.', 'seoprostack'),
            ),
        );
    }

    /** Register only on the front end while Rank Math is loaded. */
    public static function boot() {
        // Rank Math defines its version even when its runtime requirements fail.
        if (!self::enabled() || is_admin() || !did_action('rank_math/loaded')) {
            return;
        }
        // After Rank Math joins a FAQPage to its WebPage (connect_schema_entities,
        // priority 99), so one added before, joined or not, is seen.
        add_filter('rank_math/json_ld', array(__CLASS__, 'add'), 100);
    }

    /**
     * Add the questions to Rank Math's entities.
     *
     * As Rank Math does with its own FAQ block, Rank Math's WebPage becomes
     * a FAQPage too and holds the questions. Where there is no WebPage (the
     * post's Schema Type is None), or it already has a main entity, the
     * questions are a FAQPage of their own, part of the WebPage if any.
     *
     * @param mixed $data JSON-LD entities, keyed.
     * @return mixed
     */
    public static function add($data) {
        if (!is_array($data) || !is_singular() || self::has_faq($data)) {
            return $data;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post || post_password_required($post)) {
            return $data;
        }
        $questions = self::questions((string) $post->post_content);
        if (count($questions) < self::MIN_QUESTIONS) {
            return $data;
        }

        $entities = array();
        foreach ($questions as $entry) {
            $entities[] = array(
                '@type'          => 'Question',
                'name'           => $entry[0],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $entry[1],
                ),
            );
        }

        $page = isset($data['WebPage']) && is_array($data['WebPage']) ? $data['WebPage'] : array();
        if ($page && !isset($page['mainEntity'])) {
            $types = isset($page['@type']) ? (array) $page['@type'] : array('WebPage');
            $data['WebPage']['@type']      = array_values(array_unique(array_merge($types, array('FAQPage'))));
            $data['WebPage']['mainEntity'] = $entities;
            return $data;
        }
        $data[self::ENTITY] = self::faq_page($entities, $page, $post);
        return $data;
    }

    /**
     * The questions as a FAQPage of their own, part of the WebPage if any.
     *
     * @param array   $entities Question entities.
     * @param array   $page     Rank Math's WebPage entity, or empty.
     * @param WP_Post $post     The post.
     * @return array
     */
    private static function faq_page(array $entities, array $page, WP_Post $post) {
        // Rank Math's WebPage follows the canonical address.
        $url = !empty($page['url']) && is_string($page['url']) ? $page['url'] : (string) get_permalink($post);
        $faq = array(
            '@type' => 'FAQPage',
            '@id'   => $url . '#faq',
        );
        if (!empty($page['@id']) && is_string($page['@id'])) {
            $faq['isPartOf'] = array('@id' => $page['@id']);
        }
        $faq['mainEntity'] = $entities;
        return $faq;
    }

    /**
     * Whether an entity is already a FAQPage.
     *
     * @param array $data JSON-LD entities.
     * @return bool
     */
    private static function has_faq(array $data) {
        foreach ($data as $entity) {
            if (is_array($entity) && isset($entity['@type']) && in_array('FAQPage', (array) $entity['@type'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The questions and answers of a post's Details blocks, in page order.
     *
     * @param string $content Post content.
     * @return array<int,array{0:string,1:string}>
     */
    public static function questions($content) {
        if (false === strpos($content, '<!-- wp:details') && false === strpos($content, '<!-- wp:block ')) {
            return array();
        }
        $seen = array();
        return self::from_blocks(parse_blocks($content), $seen);
    }

    /**
     * Questions in parsed blocks, nested ones too.
     *
     * @param array            $blocks Parsed blocks.
     * @param array<int,bool>  $seen   Synced patterns already read.
     * @return array<int,array{0:string,1:string}>
     */
    private static function from_blocks(array $blocks, array &$seen) {
        $found = array();
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
            if ('core/details' === $name) {
                // A Details block inside it is part of its answer, not a second question.
                $entry = self::entry($block);
                if ($entry) {
                    $found[] = $entry;
                }
                continue;
            }
            if ('core/block' === $name) {
                $found = array_merge($found, self::from_pattern($block, $seen));
                continue;
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $found = array_merge($found, self::from_blocks($block['innerBlocks'], $seen));
            }
        }
        return $found;
    }

    /**
     * Questions in a published synced pattern, each pattern read once, so
     * one used twice (or inside itself) adds its questions once. As core's
     * Pattern block shows nothing for one with a password, neither does this.
     *
     * @param array           $block core/block block.
     * @param array<int,bool> $seen  Synced patterns already read.
     * @return array<int,array{0:string,1:string}>
     */
    private static function from_pattern(array $block, array &$seen) {
        $ref = isset($block['attrs']['ref']) ? absint($block['attrs']['ref']) : 0;
        if (!$ref || isset($seen[$ref])) {
            return array();
        }
        $seen[$ref] = true;
        $pattern = get_post($ref);
        if (!$pattern instanceof WP_Post || 'wp_block' !== $pattern->post_type || 'publish' !== $pattern->post_status
            || '' !== (string) $pattern->post_password
            || (false === strpos($pattern->post_content, '<!-- wp:details') && false === strpos($pattern->post_content, '<!-- wp:block '))) {
            return array();
        }
        return self::from_blocks(parse_blocks($pattern->post_content), $seen);
    }

    /**
     * One Details block as a question (its summary) and an answer (its
     * blocks, rendered), as text; empty when either is empty.
     *
     * @param array $block core/details block.
     * @return array{0:string,1:string}|array{}
     */
    private static function entry(array $block) {
        $html     = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
        $question = preg_match('#<summary[^>]*>(.*?)</summary>#s', $html, $match) ? self::text(strip_shortcodes($match[1])) : '';
        if ('' === $question) {
            return array();
        }
        $answer = '';
        foreach ((array) ($block['innerBlocks'] ?? array()) as $inner) {
            if (is_array($inner)) {
                $answer .= ' ' . render_block($inner);
            }
        }
        $answer = self::text(strip_shortcodes($answer));
        return '' !== $answer ? array($question, $answer) : array();
    }

    /**
     * Plain text from HTML: tags removed, entities decoded (non-breaking
     * spaces collapse with the others, "&#8217;" is "’"), spaces collapsed.
     * "<" and ">" stay escaped, so no output of these strings can end a
     * script tag: Rank Math prints its JSON with unescaped slashes (after
     * wp_kses_post_deep(), which also escapes a bare "&" again).
     *
     * @param string $html HTML.
     * @return string
     */
    private static function text($html) {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(array('<', '>'), array('&lt;', '&gt;'), $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
