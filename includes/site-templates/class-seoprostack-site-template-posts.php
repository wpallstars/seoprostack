<?php
/**
 * Site templates: pages and Kadence Elements, with their title, slug and meta.
 *
 * A post a template created carries _seoprostack_template_key. Title, slug
 * and each meta value are owned by hash like parts, so site edits stay.
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

class SEOProStack_Site_Template_Posts {

    /** @param SEOProStack_Site_Template_Run $run Run. @param string $key Page key. @param array $item Post manifest. @return true|WP_Error */
    public static function apply_page($run, $key, $item) {
        return self::apply($run, 'pages/' . $key, 'page', $item);
    }

    /** @param SEOProStack_Site_Template_Run $run Run. @param string $key Element key. @param array $item Post manifest. @return true|WP_Error */
    public static function apply_element($run, $key, $item) {
        if (!post_type_exists('kadence_element')) {
            $run->report('elements/' . $key, 'skipped: Kadence Elements inactive');
            return true;
        }
        return self::apply($run, 'elements/' . $key, 'kadence_element', $item);
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Stable resource key.
     * @param string                        $type Post type.
     * @param array                         $item Manifest post.
     * @return true|WP_Error
     */
    private static function apply($run, $path, $type, $item) {
        $old = $run->record['posts'][$path] ?? array();
        $post = !empty($old['id']) ? get_post($old['id']) : null;
        if (!self::claimable($run, $path, $type, $item, $old, $post)) {
            return true;
        }
        $blocks = $post ? parse_blocks($post->post_content) : array();
        $hashes = SEOProStack_Site_Template_Parts::merge($run, $path, $item, $blocks, $old['parts'] ?? array());
        $write = array('post_type' => $type, 'post_status' => $post ? $post->post_status : 'publish', 'post_content' => serialize_blocks($blocks));
        $fields = array('post_title' => $item['title'], 'post_name' => $item['slug']);
        $owned = array(
            'fields' => self::own_fields($run, $path, $post, $fields, $old['fields'] ?? array(), $write),
            'meta' => self::own_meta($run, $path, $post, self::meta_values($item, $path), $old['meta'] ?? array(), $write),
        );
        if ($run->dry) {
            return true;
        }
        $id = self::write($run, $post, $write);
        if (is_wp_error($id)) {
            return $id;
        }
        $run->record['posts'][$path] = array('id' => $id, 'parts' => $hashes, 'fields' => $owned['fields'], 'meta' => $owned['meta']);
        self::own_saved($run, $path, $id, $write, $fields);
        SEOProStack_Site_Template_Parts::own_saved($run, $path, (string) get_post_field('post_content', $id), $hashes, $old['parts'] ?? array());
        $run->save();
        return true;
    }

    /**
     * Whether the template may write this post; reports why not.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Resource key.
     * @param string                        $type Post type.
     * @param array                         $item Manifest post.
     * @param array                         $old Ownership record.
     * @param WP_Post|null                  $post Owned post.
     * @return bool
     */
    private static function claimable($run, $path, $type, $item, $old, $post) {
        if ($old && !self::still_ours($post, $type, $path)) {
            $run->report($path, 'removed-on-site');
            return false;
        }
        if (!$old && get_page_by_path($item['slug'], OBJECT, $type)) {
            $run->report($path, 'edited-on-site');
            return false;
        }
        return true;
    }

    /** @param WP_Post|null $post Post. @param string $type Type. @param string $path Key. @return bool */
    private static function still_ours($post, $type, $path) {
        return $post && 'trash' !== $post->post_status && $post->post_type === $type && get_post_meta($post->ID, SEOProStack_Site_Templates::META, true) === $path;
    }

    /**
     * @param WP_Post|null $post Owned post.
     * @param mixed        $current Stored value.
     * @param array        $hashes Owned hashes.
     * @param string       $name Field or meta name.
     * @return bool Whether the template still owns the value.
     */
    private static function unchanged($post, $current, $hashes, $name) {
        return !$post || (isset($hashes[$name]) && SEOProStack_Site_Template_Run::fingerprint($current) === $hashes[$name]);
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Resource key.
     * @param WP_Post|null                  $post Owned post.
     * @param array                         $fields Field => template value.
     * @param array                         $hashes Owned field hashes.
     * @param array                         $write Post data, updated in place.
     * @return array Owned field hashes.
     */
    private static function own_fields($run, $path, $post, $fields, $hashes, &$write) {
        foreach ($fields as $field => $value) {
            if (self::unchanged($post, $post ? $post->$field : null, $hashes, $field)) {
                $write[$field] = $value;
                $hashes[$field] = SEOProStack_Site_Template_Run::fingerprint($value);
            } else {
                $run->report($path . '/' . $field, 'edited-on-site');
            }
        }
        return $hashes;
    }

    /** @param array $item Manifest post. @param string $path Resource key. @return array Meta name => value. */
    private static function meta_values($item, $path) {
        $meta = $item['meta'] ?? array();
        if (isset($item['template'])) {
            $meta['_wp_page_template'] = $item['template'];
        }
        $meta[SEOProStack_Site_Templates::META] = $path;
        return $meta;
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Resource key.
     * @param WP_Post|null                  $post Owned post.
     * @param array                         $meta Meta name => template value.
     * @param array                         $hashes Owned meta hashes.
     * @param array                         $write Post data, updated in place.
     * @return array Owned meta hashes.
     */
    private static function own_meta($run, $path, $post, $meta, $hashes, &$write) {
        $write['meta_input'] = array();
        foreach ($meta as $name => $value) {
            if (is_scalar($value) || null === $value) {
                $value = (string) $value;
            }
            if (self::unchanged($post, $post ? get_post_meta($post->ID, $name, true) : '', $hashes, $name)) {
                $write['meta_input'][$name] = $value;
                $hashes[$name] = SEOProStack_Site_Template_Run::fingerprint($value);
            } elseif (SEOProStack_Site_Templates::META !== $name) {
                $run->report($path . '/meta/' . $name, 'edited-on-site');
            }
        }
        return $hashes;
    }

    /**
     * Journal, then insert or update the post.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param WP_Post|null                  $post Owned post.
     * @param array                         $write Post data.
     * @return int|WP_Error Post ID.
     */
    private static function write($run, $post, $write) {
        if ($post) {
            $write['ID'] = $post->ID;
        }
        $meta_keys = array_keys($write['meta_input']);
        $before = $post ? SEOProStack_Site_Template_Journal::post($post->ID, $meta_keys) : null;
        $after = $before ? array_replace($before, $write) : $write;
        unset($after['ID']);
        if ($before === $after) {
            return $post->ID;
        }
        $run->journal('post', $post ? $post->ID : 0, $before, $after);
        $id = $post ? wp_update_post(wp_slash($write), true) : wp_insert_post(wp_slash($write), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $run->amend('id', $id);
        $run->amend('after', SEOProStack_Site_Template_Journal::post($id, $meta_keys));
        SEOProStack_Page_Cache::purge_posts(array($id));
        return $id;
    }

    /**
     * Core may normalise metadata or filter block markup. Own exactly what
     * reached storage, never the pre-filter input or preserved site edits.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Resource key.
     * @param int                           $id Post.
     * @param array                         $write Data this run wrote.
     * @param array                         $fields Field => template value.
     */
    private static function own_saved($run, $path, $id, $write, $fields) {
        $saved = get_post($id);
        if (!$saved) {
            return;
        }
        $record = $run->record['posts'][$path];
        $record['sha256'] = SEOProStack_Site_Template_Run::fingerprint($saved->post_content);
        foreach (array_keys($write['meta_input']) as $name) {
            // PHP stores a numeric meta name as an integer array key.
            $record['meta'][$name] = SEOProStack_Site_Template_Run::fingerprint(get_post_meta($id, (string) $name, true));
        }
        foreach (array_keys($fields) as $field) {
            if (isset($write[$field])) {
                $record['fields'][$field] = SEOProStack_Site_Template_Run::fingerprint($saved->$field);
            }
        }
        $run->record['posts'][$path] = $record;
    }
}
