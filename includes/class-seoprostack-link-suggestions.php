<?php
/**
 * Local linking opportunities, with approved, conflict-checked inserts and undo.
 * No language model, cloud account or automatic content edits.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Link_Suggestions {
    const UNDO = '_seoprostack_link_undo';

    /** @param WP_Post $post Target. @return string[] */
    public static function phrases($post) {
        $keywords = get_post_meta($post->ID, 'rank_math_focus_keyword', true);
        $phrases = is_string($keywords) ? explode(',', $keywords) : array();
        $phrases[] = wp_strip_all_tags($post->post_title);
        $phrases = array_map('trim', $phrases);
        return array_slice(array_values(array_unique(array_filter($phrases, static function ($phrase) {
            return strlen($phrase) >= 4 && strlen($phrase) <= 100 && !preg_match('/[<>\r\n]/', $phrase);
        }))), 0, 6);
    }

    /**
     * Core RichText and classic HTML only; never corrupt builder attributes.
     *
     * @param string $content Stored content.
     * @return bool
     */
    public static function editable($content) {
        if (!has_blocks($content)) {
            return !preg_match('/\[[a-zA-Z][^\]]*\]/', $content);
        }
        $allowed = array('core/paragraph', 'core/list', 'core/list-item', 'core/quote', 'core/group', 'core/columns', 'core/column', 'core/heading', 'core/separator', 'core/spacer');
        $queue = parse_blocks($content);
        while ($queue) {
            $block = array_pop($queue);
            if (!empty($block['blockName']) && !in_array($block['blockName'], $allowed, true)) {
                return false;
            }
            $queue = array_merge($queue, $block['innerBlocks']);
        }
        return true;
    }

    /**
     * Wrap one eligible text occurrence without reserializing HTML or blocks.
     * Quote-aware tag splitting keeps attribute text and block JSON untouched.
     *
     * @param string $content Stored content.
     * @param string $phrase Suggested anchor.
     * @param string $url Target permalink.
     * @return array {content, context}, or empty when no safe occurrence exists.
     */
    public static function wrap($content, $phrase, $url) {
        if (strlen($content) > 2 * MB_IN_BYTES || '' === $phrase) {
            return array();
        }
        $tag_pattern = <<<'HTML'
~(<!--.*?-->|<(?:"[^"]*"|'[^']*'|[^'">])*>)~s
HTML;
        $pieces = preg_split($tag_pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($pieces)) {
            return array();
        }
        $protected = array('a', 'code', 'pre', 'script', 'style', 'textarea', 'button', 'select', 'svg', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6');
        $depth = array();
        $offset = 0;
        foreach ($pieces as $piece) {
            if ('<' === substr($piece, 0, 1)) {
                if (preg_match('~^<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)\b~', $piece, $tag)) {
                    $name = strtolower($tag[2]);
                    if (in_array($name, $protected, true)) {
                        $depth[$name] = max(0, (isset($depth[$name]) ? $depth[$name] : 0) + ('/' === $tag[1] ? -1 : 1));
                    }
                }
            } elseif (!array_sum($depth) && preg_match('~(?<![\p{L}\p{N}_])' . preg_quote($phrase, '~') . '(?![\p{L}\p{N}_])~iu', $piece, $match, PREG_OFFSET_CAPTURE)) {
                $text = $match[0][0];
                $position = $offset + $match[0][1];
                // Entity names and incomplete markup are not editable text.
                $prefix = substr($piece, 0, $match[0][1]);
                if (preg_match('/&[^;\s]*$/', $prefix) || false !== strpos($text, '&')) {
                    $offset += strlen($piece);
                    continue;
                }
                $before = preg_split('/\s+/u', wp_strip_all_tags(substr($content, 0, $position)));
                $after = preg_split('/\s+/u', wp_strip_all_tags(substr($content, $position + strlen($text))));
                $context = implode(' ', array_slice(is_array($before) ? $before : array(), -12)) . ' [' . $text . '] ' . implode(' ', array_slice(is_array($after) ? $after : array(), 0, 12));
                return array('content' => substr($content, 0, $position) . '<a href="' . esc_url($url) . '">' . $text . '</a>' . substr($content, $position + strlen($text)), 'context' => $context);
            }
            $offset += strlen($piece);
        }
        return array();
    }

    /** @param WP_Post $source Source. @param WP_Post $target Target. @return array */
    public static function proposal($source, $target) {
        if ($source->ID === $target->ID || !SEOProStack_Link_Index::eligible($source) || !SEOProStack_Link_Index::eligible($target) || !current_user_can('edit_post', $source->ID)) {
            return array();
        }
        $url = (string) get_permalink($target);
        $address = SEOProStack_Link_Index::address($url, $url);
        $existing = SEOProStack_Link_Index::extract($source);
        if ($existing['truncated'] || isset($existing['links'][hash('sha256', $address)])) {
            return array();
        }
        foreach (self::phrases($target) as $phrase) {
            $wrapped = self::wrap($source->post_content, $phrase, $url);
            if ($wrapped) {
                return array('source' => $source->ID, 'target' => $target->ID, 'phrase' => $phrase, 'context' => $wrapped['context'], 'hash' => hash('sha256', $source->post_content), 'editable' => self::editable($source->post_content));
            }
        }
        return array();
    }

    /**
     * Search bounded local candidates; a specific target can bypass ranking.
     *
     * @param int $post_id Selected page.
     * @param string $direction incoming or outgoing.
     * @param int $target_id Optional explicit target.
     * @param string $target_search Optional owner-requested title or content search.
     * @return array
     */
    public static function find($post_id, $direction, $target_id = 0, $target_search = '') {
        $post = get_post($post_id);
        if (!SEOProStack_Link_Index::eligible($post)) {
            return array();
        }
        $args = array('post_type' => SEOProStack_Link_Index::types(), 'post_status' => 'publish', 'has_password' => false, 'post__not_in' => array($post_id), 'posts_per_page' => 40, 'no_found_rows' => true);
        $candidates = array();
        if ('incoming' === $direction) {
            foreach (self::phrases($post) as $phrase) {
                $query = new WP_Query(array_merge($args, array('s' => $phrase, 'sentence' => true)));
                foreach ($query->posts as $candidate) {
                    $candidates[$candidate->ID] = $candidate;
                }
                if (count($candidates) >= 80) {
                    break;
                }
            }
        } elseif ($target_id) {
            $target = get_post($target_id);
            if ($target instanceof WP_Post) {
                $candidates[$target->ID] = $target;
            }
        } elseif ('' !== $target_search) {
            $query = new WP_Query(array_merge($args, array('s' => sanitize_text_field($target_search), 'posts_per_page' => 80)));
            $candidates = $query->posts;
        } else {
            $taxonomies = get_object_taxonomies($post->post_type);
            $tax_query = array('relation' => 'OR');
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
                if (is_array($terms) && $terms) {
                    $tax_query[] = array('taxonomy' => $taxonomy, 'terms' => array_slice($terms, 0, 12));
                }
            }
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- owner-requested editorial search, capped at 40 candidates and never run for visitors.
            $query = new WP_Query(count($tax_query) > 1 ? array_merge($args, array('tax_query' => $tax_query)) : $args);
            $candidates = $query->posts;
        }
        /** Filter bounded candidate posts for language or editorial rules. */
        $candidates = apply_filters('seoprostack_link_suggestion_candidates', array_slice($candidates, 0, 80), $post, $direction);
        $results = array();
        foreach (is_array($candidates) ? array_slice($candidates, 0, 80) : array() as $candidate) {
            if (!$candidate instanceof WP_Post) {
                continue;
            }
            $proposal = 'incoming' === $direction ? self::proposal($candidate, $post) : self::proposal($post, $candidate);
            if ($proposal) {
                $results[] = $proposal;
            }
            if (count($results) >= 12) {
                break;
            }
        }
        return $results;
    }

    /**
     * The handler authenticates and checks the scoped nonce before calling this.
     *
     * @param int $source_id Source.
     * @param int $target_id Target.
     * @param string $phrase Approved anchor.
     * @param string $hash Content snapshot.
     * @return true|WP_Error
     */
    public static function insert($source_id, $target_id, $phrase, $hash) {
        $source = get_post($source_id);
        $target = get_post($target_id);
        if (!current_user_can('edit_post', $source_id) || !SEOProStack_Link_Index::eligible($source) || !SEOProStack_Link_Index::eligible($target) || $source_id === $target_id) {
            return new WP_Error('not_allowed', __('This content cannot be changed.', 'seoprostack'));
        }
        if (!hash_equals(hash('sha256', $source->post_content), $hash) || (function_exists('wp_check_post_lock') && wp_check_post_lock($source_id))) {
            return new WP_Error('stale', __('The source changed or is being edited. Review a fresh suggestion.', 'seoprostack'));
        }
        if (!self::editable($source->post_content) || !in_array($phrase, self::phrases($target), true)) {
            return new WP_Error('manual', __('Use the source editor for this content.', 'seoprostack'));
        }
        $existing = SEOProStack_Link_Index::extract($source);
        $url = (string) get_permalink($target);
        if ($existing['truncated'] || isset($existing['links'][hash('sha256', SEOProStack_Link_Index::address($url, $url))])) {
            return new WP_Error('already_linked', __('This target is already linked or the source exceeds the scan limit.', 'seoprostack'));
        }
        $wrapped = self::wrap($source->post_content, $phrase, $url);
        if (!$wrapped) {
            return new WP_Error('no_match', __('The suggested text is no longer available.', 'seoprostack'));
        }
        $saved = wp_update_post(wp_slash(array('ID' => $source_id, 'post_content' => $wrapped['content'])), true);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $actual = get_post_field('post_content', $source_id, 'raw');
        update_post_meta($source_id, self::UNDO, array('before' => $source->post_content, 'after' => hash('sha256', $actual), 'at' => time()));
        return true;
    }

    /** @param int $post_id Source. @return true|WP_Error */
    public static function undo($post_id) {
        $undo = get_post_meta($post_id, self::UNDO, true);
        $post = get_post($post_id);
        if (!current_user_can('edit_post', $post_id) || !$post instanceof WP_Post || !is_array($undo) || !isset($undo['before'], $undo['after'])) {
            return new WP_Error('no_undo', __('There is no link change to undo.', 'seoprostack'));
        }
        if (!hash_equals($undo['after'], hash('sha256', $post->post_content)) || (function_exists('wp_check_post_lock') && wp_check_post_lock($post_id))) {
            return new WP_Error('stale', __('The source changed or is being edited. Use WordPress revisions instead.', 'seoprostack'));
        }
        $saved = wp_update_post(wp_slash(array('ID' => $post_id, 'post_content' => $undo['before'])), true);
        if (is_wp_error($saved)) {
            return $saved;
        }
        delete_post_meta($post_id, self::UNDO);
        return true;
    }
}
