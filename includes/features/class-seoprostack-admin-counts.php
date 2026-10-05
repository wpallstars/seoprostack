<?php
/**
 * Remember admin counts: keep the site's comment, post and people counts
 * between requests on sites without a persistent object cache, where
 * WordPress (and WooCommerce) count them again on every admin screen.
 *
 * Counts are what WordPress itself would cache in its `counts` group (post
 * counts unfiltered, as core caches them; the site's comment count as the
 * `wp_count_comments` filters finish it, so WooCommerce's adjustment is
 * kept). A group is cleared on the events WordPress clears its own on, and a
 * little wider, once per request at shutdown; a count stored by a request
 * that started before the last change is ignored, a group changed in this
 * request is counted afresh, and each count is kept at most MAX_AGE, for
 * changes made straight in the database. Stored in OPTION, not autoloaded.
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

class SEOProStack_Admin_Counts extends SEOProStack_Feature {

    const KEY    = 'admin_counts';
    const OPTION = 'seoprostack_admin_counts';

    /** Seconds a count is kept at most. */
    const MAX_AGE = 3600;

    /**
     * Other plugins' counts kept in the object cache that are asked for on
     * every admin screen: group => [cache key, cache group], cleared with
     * comments. WooCommerce's pending product reviews (Products menu).
     */
    const KEPT = array(
        'wc-reviews-pending' => array('woocommerce_product_reviews_pending_count', 'wc_comment_counts'),
    );

    /** @var array{counts:array<string,array{time:float,value:mixed}>,changed:array<string,float>}|null Stored counts, read on first use. */
    private static $stored = null;

    /** @var array<string,float> Groups changed in this request => when first changed. */
    private static $changed = array();

    /** @var array<string,mixed> Groups counted in this request => count, stored at shutdown. */
    private static $counted = array();

    /** @var bool Whether count_users() is running for us. */
    private static $counting_users = false;

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
                'tab'         => 'server',
                'label'       => __('Remember admin counts', 'seoprostack'),
                'description' => __('Keeps the number of comments, posts and people between admin screens, so WordPress and WooCommerce need not count them again on each one (the Comments menu counts every comment on every screen). Counted again whenever they change; changes made straight in the database show within an hour. Does nothing with a persistent object cache (Redis, Memcached), which keeps them already.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('update_option_seoprostack_options', array(__CLASS__, 'options_updated'), 10, 2);
        if (!self::enabled() || wp_using_ext_object_cache()) {
            return;
        }
        add_filter('wp_count_comments', array(__CLASS__, 'comments_stored'), 1, 2);
        add_filter('wp_count_comments', array(__CLASS__, 'comments_counted'), PHP_INT_MAX, 2);
        add_filter('wp_count_posts', array(__CLASS__, 'posts_counted'), PHP_INT_MAX, 3);
        add_filter('pre_count_users', array(__CLASS__, 'users'), PHP_INT_MAX, 3);
        add_action('init', array(__CLASS__, 'prime'), 1);

        add_action('transition_post_status', array(__CLASS__, 'post_status_changed'), 10, 3);
        add_action('clean_post_cache', array(__CLASS__, 'post_changed'), 10, 2);
        add_action('deleted_post', array(__CLASS__, 'post_changed'), 10, 2);

        foreach (array('wp_insert_comment', 'transition_comment_status', 'clean_comment_cache', 'wp_update_comment_count') as $hook) {
            add_action($hook, array(__CLASS__, 'comments_changed'), 10, 0);
        }
        foreach (array('user_register', 'deleted_user', 'set_user_role', 'add_user_role', 'remove_user_role', 'add_user_to_blog', 'remove_user_from_blog') as $hook) {
            add_action($hook, array(__CLASS__, 'users_changed'), 10, 0);
        }
        add_action('shutdown', array(__CLASS__, 'save'));
    }

    /**
     * Forget the counts when the feature is turned off (settings screen,
     * WP-CLI or the settings API).
     *
     * @param mixed $old Previous settings.
     * @param mixed $new Saved settings.
     */
    public static function options_updated($old, $new) {
        if (is_array($old) && !empty($old[self::KEY]) && (!is_array($new) || empty($new[self::KEY]))) {
            delete_option(self::OPTION);
            self::$stored = null;
        }
    }

    /**
     * Put stored post counts, and the other plugins' counts in KEPT, into
     * the object cache before admin screens and menus ask for them. Not for
     * AJAX: those rarely count.
     */
    public static function prime() {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }
        foreach (array_keys(self::stored()['counts']) as $group) {
            $counts = 0 === strpos($group, 'posts-') || isset(self::KEPT[$group]) ? self::get($group) : null;
            if (null === $counts) {
                continue;
            }
            if (isset(self::KEPT[$group])) {
                wp_cache_add(self::KEPT[$group][0], $counts, self::KEPT[$group][1]);
            } else {
                wp_cache_add($group, $counts, 'counts');
            }
        }
    }

    /**
     * Keep a post count WordPress just made (or got from us), unfiltered as
     * WordPress caches it. Per-person counts are left alone.
     *
     * @param stdClass $counts Filtered counts.
     * @param string   $type   Post type.
     * @param string   $perm   '' or 'readable'.
     * @return stdClass
     */
    public static function posts_counted($counts, $type, $perm) {
        $group = _count_posts_cache_key($type, $perm);
        if (false !== strpos($group, '_readable_') || array_key_exists($group, self::$counted) || null !== self::get($group)) {
            return $counts;
        }
        $raw = wp_cache_get($group, 'counts');
        if (is_object($raw)) {
            self::$counted[$group] = clone $raw;
        }
        return $counts;
    }

    /**
     * Answer the site's comment count from the stored one, before other
     * filters (WooCommerce's) count it.
     *
     * @param array|stdClass $stats   Empty array, or counts from an earlier filter.
     * @param int            $post_id Post ID, 0 for the whole site.
     * @return array|stdClass
     */
    public static function comments_stored($stats, $post_id) {
        if (0 !== (int) $post_id || !empty($stats)) {
            return $stats;
        }
        $stored = self::get('comments');
        return null === $stored ? $stats : $stored;
    }

    /**
     * Keep the site's comment count as the filters finished it, counting it
     * as WordPress does when no filter did.
     *
     * @param array|stdClass $stats   Counts, or an empty array.
     * @param int            $post_id Post ID, 0 for the whole site.
     * @return array|stdClass
     */
    public static function comments_counted($stats, $post_id) {
        if (0 !== (int) $post_id || array_key_exists('comments', self::$counted) || null !== self::get('comments')) {
            return $stats;
        }
        if (empty($stats)) {
            // As wp_count_comments() does after its filter.
            $stats = wp_cache_get('comments-0', 'counts');
            if (false === $stats) {
                $count              = get_comment_count(0);
                $count['moderated'] = $count['awaiting_moderation'];
                unset($count['awaiting_moderation']);
                $stats = (object) $count;
                wp_cache_set('comments-0', $stats, 'counts');
            }
        }
        self::$counted['comments'] = is_object($stats) ? clone $stats : $stats;
        return $stats;
    }

    /**
     * Answer count_users() for this site from the stored count, or count
     * once and keep it. An earlier answer from another plugin wins.
     *
     * @param array|null $pre      Earlier answer.
     * @param string     $strategy 'time' or 'memory'.
     * @param int        $site_id  Site.
     * @return array|null
     */
    public static function users($pre, $strategy, $site_id) {
        if (null !== $pre || self::$counting_users || (int) $site_id !== get_current_blog_id()) {
            return $pre;
        }
        $stored = isset(self::$counted['users']) ? self::$counted['users'] : self::get('users');
        if (is_array($stored)) {
            return $stored;
        }
        self::$counting_users = true;
        $result               = count_users('memory' === $strategy ? 'memory' : 'time', $site_id);
        self::$counting_users = false;
        if (!isset(self::$changed['users'])) {
            self::$counted['users'] = $result;
        }
        return $result;
    }

    /**
     * @param string  $new_status New status.
     * @param string  $old_status Old status.
     * @param WP_Post $post       Post.
     */
    public static function post_status_changed($new_status, $old_status, $post) {
        if ($new_status !== $old_status && $post instanceof WP_Post) {
            self::changed('posts-' . $post->post_type);
        }
    }

    /**
     * @param int          $post_id Post ID.
     * @param WP_Post|null $post    Post.
     */
    public static function post_changed($post_id, $post = null) {
        $post = $post instanceof WP_Post ? $post : get_post($post_id);
        if ($post instanceof WP_Post) {
            self::changed('posts-' . $post->post_type);
        }
    }

    /** A comment was added, changed or deleted. */
    public static function comments_changed() {
        self::changed('comments');
        foreach (array_keys(self::KEPT) as $group) {
            self::changed($group);
        }
    }

    /** Someone joined, left or changed role. */
    public static function users_changed() {
        self::changed('users');
    }

    /**
     * Note a change: this request counts the group afresh, and the stored
     * count goes at shutdown.
     *
     * @param string $group Group.
     */
    private static function changed($group) {
        if (!isset(self::$changed[$group])) {
            self::$changed[$group] = microtime(true);
        }
        unset(self::$counted[$group]);
    }

    /**
     * A stored count still good for this request, or null.
     *
     * @param string $group Group.
     * @return mixed
     */
    private static function get($group) {
        if (isset(self::$changed[$group])) {
            return null;
        }
        $stored = self::stored();
        if (!isset($stored['counts'][$group])) {
            return null;
        }
        $entry = $stored['counts'][$group];
        if (!is_array($entry) || !isset($entry['time']) || !array_key_exists('value', $entry)) {
            return null;
        }
        $changed = isset($stored['changed'][$group]) ? (float) $stored['changed'][$group] : 0.0;
        if ((float) $entry['time'] <= $changed || microtime(true) - (float) $entry['time'] > self::MAX_AGE) {
            return null;
        }
        return is_object($entry['value']) ? clone $entry['value'] : $entry['value'];
    }

    /**
     * Stored counts, read once a request.
     *
     * @param bool $fresh Read the option again, for saving.
     * @return array{counts:array<string,array{time:float,value:mixed}>,changed:array<string,float>}
     */
    private static function stored($fresh = false) {
        if (null === self::$stored || $fresh) {
            if ($fresh) {
                wp_cache_delete(self::OPTION, 'options');
            }
            $stored       = get_option(self::OPTION, array());
            $stored       = is_array($stored) ? $stored : array();
            self::$stored = array(
                'counts'  => isset($stored['counts']) && is_array($stored['counts']) ? $stored['counts'] : array(),
                'changed' => isset($stored['changed']) && is_array($stored['changed']) ? $stored['changed'] : array(),
            );
        }
        return self::$stored;
    }

    /**
     * Save this request's changes and new counts, once, reading the option
     * again first so other requests' counts are kept.
     */
    public static function save() {
        // Other plugins' counts this request made (in admin, where they are primed).
        if (is_admin() && !wp_doing_ajax()) {
            foreach (self::KEPT as $group => $where) {
                if (isset(self::$changed[$group]) || null !== self::get($group)) {
                    continue;
                }
                $value = wp_cache_get($where[0], $where[1]);
                if (false !== $value) {
                    self::$counted[$group] = $value;
                }
            }
        }
        if (!self::$changed && !self::$counted) {
            return;
        }
        $stored = self::stored(true);
        $now    = microtime(true);
        $start  = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $now;
        foreach (self::$changed as $group => $time) {
            unset($stored['counts'][$group]);
            $stored['changed'][$group] = max($time, isset($stored['changed'][$group]) ? (float) $stored['changed'][$group] : 0.0);
        }
        foreach (self::$counted as $group => $value) {
            if (!isset($stored['changed'][$group]) || $start > $stored['changed'][$group]) {
                $stored['counts'][$group] = array('time' => $start, 'value' => $value);
            }
        }
        // Older than MAX_AGE, a count is ignored anyway, and so is a change before it.
        foreach ($stored['counts'] as $group => $entry) {
            if (!is_array($entry) || !isset($entry['time']) || $now - $entry['time'] > self::MAX_AGE) {
                unset($stored['counts'][$group]);
            }
        }
        foreach ($stored['changed'] as $group => $time) {
            if ($now - $time > self::MAX_AGE) {
                unset($stored['changed'][$group]);
            }
        }
        self::$changed = array();
        self::$counted = array();
        self::$stored  = $stored;
        update_option(self::OPTION, $stored, false);
    }
}
