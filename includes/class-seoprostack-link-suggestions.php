<?php
/**
 * Local linking opportunities, with approved, conflict-checked inserts and undo.
 * No language model, cloud account or automatic content edits.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
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
        if (preg_match('/\[[a-zA-Z][^\]]*\]/', $content)) {
            return false;
        }
        if (!has_blocks($content)) {
            return true;
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
        $identity = SEOProStack_Link_Index::identity($url, $url);
        $existing = SEOProStack_Link_Index::extract($source);
        if (!$identity || $existing['truncated'] || isset($existing['links'][$identity['hash']])) {
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
        // One more than the 40 wanted: the page itself can be among them and
        // is dropped below, which is cheaper than post__not_in.
        $args = array('post_type' => SEOProStack_Link_Index::types(), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 41, 'no_found_rows' => true);
        $candidates = array();
        if ('incoming' === $direction) {
            foreach (self::phrases($post) as $phrase) {
                $query = new WP_Query(array_merge($args, array('s' => $phrase, 'sentence' => true)));
                foreach ($query->posts as $candidate) {
                    if ($candidate instanceof WP_Post) {
                        $candidates[$candidate->ID] = $candidate;
                    }
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
            $query = new WP_Query(array_merge($args, array('s' => sanitize_text_field($target_search), 'posts_per_page' => 81)));
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
        // A page never links to itself.
        $candidates = array_values(array_filter($candidates, function ($candidate) use ($post_id) {
            return !$candidate instanceof WP_Post || (int) $candidate->ID !== (int) $post_id;
        }));
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
        return self::mutate($source_id, static function ($source) use ($source_id, $target_id, $phrase, $hash) {
            return self::insert_locked($source, $source_id, $target_id, $phrase, $hash);
        });
    }

    /** @param WP_Post $source Locked source. @param int $source_id Source. @param int $target_id Target. @param string $phrase Anchor. @param string $hash Snapshot. @return true|WP_Error */
    private static function insert_locked($source, $source_id, $target_id, $phrase, $hash) {
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
        $identity = SEOProStack_Link_Index::identity($url, $url);
        if (!$identity || $existing['truncated'] || isset($existing['links'][$identity['hash']])) {
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
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read the exact saved value while our transaction holds the post row lock.
        $actual = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $source_id));
        if ($actual !== $wrapped['content'] || !update_post_meta($source_id, self::UNDO, wp_slash(array('before' => $source->post_content, 'after' => hash('sha256', $actual), 'at' => time())))) {
            return new WP_Error('not_saved', __('The link or its undo record could not be saved. Use the source editor instead.', 'seoprostack'));
        }
        $undo = get_post_meta($source_id, self::UNDO, true);
        if (!is_array($undo) || !isset($undo['before']) || $undo['before'] !== $source->post_content) {
            return new WP_Error('not_saved', __('The exact undo snapshot could not be saved. No change was committed.', 'seoprostack'));
        }
        return true;
    }

    /** @param int $post_id Source. @return true|WP_Error */
    public static function undo($post_id) {
        return self::mutate($post_id, static function ($post) use ($post_id) {
            return self::undo_locked($post, $post_id);
        });
    }

    /** @param WP_Post $post Locked source. @param int $post_id Source. @return true|WP_Error */
    private static function undo_locked($post, $post_id) {
        $undo = get_post_meta($post_id, self::UNDO, true);
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
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- refuse sanitization or save-hook changes before committing an exact restoration.
        $actual = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id));
        if ($actual !== $undo['before']) {
            return new WP_Error('not_saved', __('The exact content could not be restored. No change was committed.', 'seoprostack'));
        }
        if (!delete_post_meta($post_id, self::UNDO)) {
            return new WP_Error('undo_storage', __('The undo record could not be removed. No change was committed.', 'seoprostack'));
        }
        return true;
    }

    /**
     * Lock the actual post row, not just other toolkit requests. Normal core
     * saves also acquire this database row lock. Read the snapshot after locking
     * and commit content plus undo metadata together, keeping core save hooks.
     *
     * @param int $post_id Source.
     * @param callable $callback Mutation accepting the freshly locked WP_Post.
     * @return true|WP_Error
     */
    private static function mutate($post_id, $callback) {
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('not_allowed', __('This content cannot be changed.', 'seoprostack'));
        }
        global $wpdb;
        foreach (array($wpdb->posts, $wpdb->postmeta) as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fail closed where content and undo metadata cannot be committed atomically.
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table));
            if (!$status || !isset($status->Engine) || 'innodb' !== strtolower($status->Engine)) {
                return new WP_Error('manual', __('Automatic edits need transactional WordPress tables. Use the source editor instead.', 'seoprostack'));
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- owner-approved mutation, held only through this core save and undo update.
        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('storage', __('Could not safely start this change.', 'seoprostack'));
        }
        $committed = false;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- locking the current row prevents an intervening save after snapshot validation.
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $post_id));
            clean_post_cache($post_id);
            $result = $row ? call_user_func($callback, new WP_Post($row)) : new WP_Error('missing', __('The source page no longer exists.', 'seoprostack'));
            if (is_wp_error($result)) {
                return $result;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- both the post change and its matching undo record succeed together.
            $committed = false !== $wpdb->query('COMMIT');
            return $committed ? true : new WP_Error('storage', __('Could not commit this change.', 'seoprostack'));
        } finally {
            if (!$committed) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- roll back on any failed save, metadata update or exception.
                $wpdb->query('ROLLBACK');
            }
            // Core hooks may have primed a cache before commit or rollback.
            clean_post_cache($post_id);
        }
    }
}
