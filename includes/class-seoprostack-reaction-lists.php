<?php
/**
 * Named lists for Like, save and share, for logged-in people.
 *
 * Liked is the default list: it stays in the `seoprostack_liked` user option
 * and keeps the public like totals (SEOProStack_Post_Reactions). The lists a
 * person names are the per-site user option `seoprostack_lists`:
 * [{id, name, posts}]. Until that is stored, the Shortlist and Saved lists
 * from before named lists (`seoprostack_shortlist`, `seoprostack_saved`)
 * become lists of those names when they hold posts, and both options are kept
 * in step afterwards for code that still reads them.
 *
 * Visitors keep the same lists in their browser (assets/post-reactions.js);
 * they join the account when the person logs in (merge()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.62
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Reaction_Lists {

    /** User option (per site): named lists. */
    const OPTION = 'seoprostack_lists';

    /** The default list's ID. */
    const DEFAULT_LIST = 'liked';

    /** Most named lists one person keeps, besides Liked. */
    const MAX_LISTS = 20;

    /** Longest list name, in characters. */
    const NAME_MAX = 60;

    /** Most posts in one list. */
    const MAX_POSTS = 500;

    /**
     * Lists from before named lists: list ID => user option.
     *
     * @return array<string,string>
     */
    public static function legacy() {
        return array('shortlist' => 'seoprostack_shortlist', 'saved' => 'seoprostack_saved');
    }

    /**
     * Names of the lists from before named lists.
     *
     * @return array<string,string>
     */
    public static function legacy_names() {
        return array('shortlist' => __('Shortlist', 'seoprostack'), 'saved' => __('Saved', 'seoprostack'));
    }

    /**
     * A person's named lists on this site (Liked is not among them).
     *
     * @param int $user_id User ID.
     * @return array<int,array{id:string,name:string,posts:int[]}>
     */
    public static function get($user_id) {
        $stored = get_user_option(self::OPTION, $user_id);
        if (is_array($stored)) {
            return self::clean($stored);
        }
        $lists = array();
        $names = self::legacy_names();
        foreach (self::legacy() as $id => $option) {
            $posts = SEOProStack_Post_Reactions::user_list($user_id, $option);
            if ($posts) {
                $lists[] = array('id' => $id, 'name' => $names[$id], 'posts' => $posts);
            }
        }
        return self::clean($lists);
    }

    /**
     * Store a person's named lists, and the old Shortlist and Saved options
     * with them.
     *
     * @param int   $user_id User ID.
     * @param array $lists   Lists.
     * @return array The lists as stored.
     */
    public static function set($user_id, array $lists) {
        $lists = self::clean($lists);
        update_user_option($user_id, self::OPTION, $lists);
        foreach (self::legacy() as $id => $option) {
            $list = self::find($lists, $id);
            update_user_option($user_id, $option, $list ? $list['posts'] : array());
        }
        return $lists;
    }

    /**
     * Keep only well-formed lists: known IDs, names, unique post IDs, and
     * the limits.
     *
     * @param mixed $lists Lists from storage or a browser.
     * @return array<int,array{id:string,name:string,posts:int[]}>
     */
    public static function clean($lists) {
        $out  = array();
        $seen = array();
        foreach (is_array($lists) ? $lists : array() as $list) {
            if (!is_array($list) || count($out) >= self::MAX_LISTS) {
                continue;
            }
            $id   = self::clean_id(isset($list['id']) ? $list['id'] : '');
            $name = self::clean_name(isset($list['name']) ? $list['name'] : '');
            if ('' === $id || '' === $name || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $posts     = isset($list['posts']) && is_array($list['posts']) ? $list['posts'] : array();
            $out[]     = array('id' => $id, 'name' => $name, 'posts' => self::clean_posts($posts, $id));
        }
        return $out;
    }

    /**
     * Unique positive post IDs, the newest kept when there are too many.
     *
     * @param array  $posts Post IDs.
     * @param string $id    List ID.
     * @return int[]
     */
    public static function clean_posts(array $posts, $id) {
        $posts = array_values(array_unique(array_filter(array_map('absint', $posts))));
        return array_slice($posts, -self::max_posts($id));
    }

    /**
     * A list ID: lower-case letters, digits and hyphens, up to 40; never Liked's.
     *
     * @param mixed $id ID.
     * @return string '' when not valid.
     */
    public static function clean_id($id) {
        $id = is_scalar($id) ? strtolower((string) $id) : '';
        return (1 === preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) && self::DEFAULT_LIST !== $id) ? $id : '';
    }

    /**
     * A list name: plain text, up to NAME_MAX characters.
     *
     * @param mixed $name Name.
     * @return string
     */
    public static function clean_name($name) {
        $name = is_scalar($name) ? trim(sanitize_text_field((string) $name)) : '';
        return trim(function_exists('mb_substr') ? mb_substr($name, 0, self::NAME_MAX) : substr($name, 0, self::NAME_MAX));
    }

    /**
     * Most posts in a list: the shortlist keeps its own, smaller limit.
     *
     * @param string $id List ID.
     * @return int
     */
    public static function max_posts($id) {
        if ('shortlist' === $id) {
            return max(1, min(self::MAX_POSTS, (int) apply_filters('seoprostack_shortlist_max', 50)));
        }
        return self::MAX_POSTS;
    }

    /**
     * The list with this ID.
     *
     * @param array  $lists Lists.
     * @param string $id    List ID.
     * @return array|null
     */
    public static function find(array $lists, $id) {
        $index = self::index($lists, $id);
        return $index < 0 ? null : $lists[$index];
    }

    /**
     * Position of the list with this ID, or -1.
     *
     * @param array  $lists Lists.
     * @param string $id    List ID.
     * @return int
     */
    public static function index(array $lists, $id) {
        foreach (array_values($lists) as $i => $list) {
            if ($list['id'] === $id) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * Position of the list with this name (case-insensitive), or -1.
     *
     * @param array  $lists Lists.
     * @param string $name  Name.
     * @return int
     */
    public static function index_by_name(array $lists, $name) {
        $name = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
        foreach (array_values($lists) as $i => $list) {
            $other = function_exists('mb_strtolower') ? mb_strtolower($list['name']) : strtolower($list['name']);
            if ($other === $name) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * A new, unused list ID.
     *
     * @param array $lists Lists.
     * @return string
     */
    public static function new_id(array $lists) {
        do {
            $id = 'l-' . strtolower(wp_generate_password(8, false, false));
        } while (self::index($lists, $id) >= 0);
        return $id;
    }

    /**
     * Add posts to a list, or take them off. The old Shortlist and Saved
     * lists are made when something is added to them.
     *
     * @param array  $lists Lists.
     * @param string $id    List ID (not Liked).
     * @param int[]  $posts Post IDs.
     * @param bool   $on    Add (true) or take off (false).
     * @return array|null The lists, or null when there is no such list.
     */
    public static function change(array $lists, $id, array $posts, $on) {
        $lists = array_values($lists);
        $index = self::index($lists, $id);
        if ($index < 0) {
            $names = self::legacy_names();
            if (!$on) {
                return $lists;
            }
            if (!isset($names[$id])) {
                return null;
            }
            $lists[] = array('id' => $id, 'name' => $names[$id], 'posts' => array());
            $index   = count($lists) - 1;
        }
        $current = array_diff($lists[$index]['posts'], $posts);
        $lists[$index]['posts'] = self::clean_posts($on ? array_merge($current, $posts) : $current, $id);
        return $lists;
    }

    /**
     * Join a browser's lists to an account's: a list with the same ID, or
     * else the same name, takes the browser's posts; others are added while
     * there is room.
     *
     * @param array    $lists    The account's lists.
     * @param mixed    $incoming The browser's lists.
     * @param callable $allowed  Whether a post can be kept (public, published).
     * @return array
     */
    public static function merge(array $lists, $incoming, callable $allowed) {
        $lists = array_values($lists);
        foreach (self::clean($incoming) as $list) {
            $posts = array_values(array_filter($list['posts'], $allowed));
            $index = self::index($lists, $list['id']);
            if ($index < 0) {
                $index = self::index_by_name($lists, $list['name']);
            }
            if ($index >= 0) {
                $lists[$index]['posts'] = self::clean_posts(array_merge($lists[$index]['posts'], array_diff($posts, $lists[$index]['posts'])), $lists[$index]['id']);
                continue;
            }
            if (count($lists) < self::MAX_LISTS) {
                $lists[] = array('id' => $list['id'], 'name' => $list['name'], 'posts' => $posts);
            }
        }
        return self::clean($lists);
    }
}
