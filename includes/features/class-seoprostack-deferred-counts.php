<?php
/**
 * Batch term and comment recounts instead of repeating them during imports.
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

class SEOProStack_Deferred_Counts extends SEOProStack_Feature {

    const KEY = 'deferred_counts';
    const QUEUE = 'seoprostack_deferred_counts_queue';
    const HOOK = 'seoprostack_deferred_counts';
    const ACTION = 'seoprostack_deferred_counts_now';
    const BATCH = 500;

    /** @var array Original taxonomy callbacks, including core's empty default. */
    private static $original = array();

    /** @var bool Whether counts must run immediately in this request. */
    private static $running = false;

    /** @return array Settings schema. */
    public static function settings() {
        return array(self::KEY => array(
            'type'        => 'bool',
            'default'     => false,
            'tab'         => 'server',
            'label'       => __('Count terms and comments in the background', 'seoprostack'),
            'description' => __('Batch recounts after saves, imports and deletions. Counts in widgets, term lists and hide-empty lists, including the first approved comment, can be a few minutes behind. On quiet sites they wait until WordPress runs its scheduled tasks.', 'seoprostack'),
        ));
    }

    /** Register the runner even when switched off, to finish pending work. */
    public static function boot() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'));
        add_action('update_option_seoprostack_options', array(__CLASS__, 'options_updated'), 10, 2);
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'recount_now'));
        }
        if (!self::enabled()) {
            if (!get_option(self::QUEUE, array())) {
                return;
            }
            // Recover pending work even if an interrupted disable lost its event.
            self::schedule();
        }
        add_action('init', array(__CLASS__, 'wrap_taxonomies'), PHP_INT_MAX);
        add_action('registered_taxonomy', array(__CLASS__, 'wrap_taxonomy'));
        add_filter('pre_wp_update_comment_count_now', array(__CLASS__, 'queue_comment'), 99, 3);
    }

    /** Wrap taxonomies registered before and during init. */
    public static function wrap_taxonomies() {
        foreach (get_taxonomies() as $name) {
            self::wrap_taxonomy($name);
        }
    }

    /** @param string $name Taxonomy name. */
    public static function wrap_taxonomy($name) {
        $taxonomy = get_taxonomy($name);
        if (!$taxonomy || array(__CLASS__, 'queue_terms') === $taxonomy->update_count_callback) {
            return;
        }
        self::$original[$name] = $taxonomy->update_count_callback;
        $taxonomy->update_count_callback = array(__CLASS__, 'queue_terms');
    }

    /**
     * @param int[]       $terms    Term taxonomy IDs, not term IDs.
     * @param WP_Taxonomy $taxonomy Taxonomy object.
     */
    public static function queue_terms($terms, $taxonomy) {
        if (self::$running) {
            self::count_terms($terms, $taxonomy);
            return;
        }
        $queued = self::queue(array($taxonomy->name => $terms), false, array(), !self::enabled());
        if (false === $queued) {
            self::immediate(function () use ($terms, $taxonomy) {
                self::count_terms($terms, $taxonomy);
            });
            return;
        }
        if (!self::enabled()) {
            self::count_terms($terms, $taxonomy);
        }
        self::schedule();
    }

    /**
     * Respect an earlier plugin's override; otherwise keep the stored count.
     *
     * @param int|null $value   Earlier override.
     * @param int      $old     Stored count.
     * @param int      $post_id Post ID.
     * @return int|null
     */
    public static function queue_comment($value, $old, $post_id) {
        if (null !== $value || self::$running) {
            return $value;
        }
        if (false === self::queue(array('' => array($post_id)), false, array(), !self::enabled())) {
            self::immediate(function () use ($post_id) {
                wp_update_comment_count_now($post_id);
            });
            $post = get_post($post_id);
            return $post ? (int) $post->comment_count : $old;
        }
        self::schedule();
        return self::enabled() ? $old : $value;
    }

    /** Schedule once; incoming work does not postpone an existing event. */
    private static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::HOOK);
        }
    }

    /**
     * Compare-and-swap prevents simultaneous saves or a runner from losing IDs.
     * The empty taxonomy key holds comment post IDs. Read directly to avoid a
     * stale persistent option cache; all writes invalidate that cache too.
     *
     * Each ID carries a generation token. A runner acknowledges only the token
     * it counted, so a simultaneous new change to that same ID stays queued.
     * Work remains durable while running, including on timeout or process death.
     *
     * @param array<string,int[]> $add  IDs to merge.
     * @param bool                $take Read up to BATCH IDs instead of adding.
     * @param array               $done Successfully counted generations.
     * @param bool                $pending_only Only refresh IDs still being drained after disable.
     * @return array|false Selected generations, or false on contention/error.
     */
    private static function queue($add = array(), $take = false, $done = array(), $pending_only = false) {
        global $wpdb;
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic plugin queue cannot use cached read/modify/write options.
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::QUEUE));
            $queue = null === $raw ? array() : maybe_unserialize($raw);
            if (!is_array($queue) || ($pending_only && !$queue)) {
                return false;
            }
            $taken = array();
            $left = self::BATCH;
            foreach ($queue as $name => $ids) {
                foreach ($done[$name] ?? array() as $id => $token) {
                    if (isset($ids[$id]) && $ids[$id] === $token) {
                        unset($queue[$name][$id]);
                    }
                }
                if (!$queue[$name]) {
                    unset($queue[$name]);
                }
                if (!$take || $left <= 0 || ('' !== $name && !taxonomy_exists($name))) {
                    continue;
                }
                $taken[$name] = array_slice($ids, 0, $left, true);
                $left -= count($taken[$name]);
                foreach ($taken[$name] as $id => $token) {
                    $token = wp_generate_uuid4();
                    $taken[$name][$id] = $token;
                    $queue[$name][$id] = $token;
                }
            }
            foreach ($add as $name => $ids) {
                foreach ($ids as $id) {
                    if ($pending_only && !isset($queue[$name][(int) $id])) {
                        continue;
                    }
                    // Repeated writes before a run need no repeated option writes.
                    $queue[$name][(int) $id] = 'pending';
                }
            }
            $next = maybe_serialize($queue);
            if ($next === $raw || (null === $raw && !$queue)) {
                return $taken;
            }
            if (null === $raw) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- unique option name makes initial creation atomic; never autoload this queue.
                $changed = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::QUEUE, $next));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare the exact serialized snapshot before replacing it.
                $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $next, self::QUEUE, $raw));
            }
            if (false === $changed) {
                return false;
            }
            if ($changed) {
                wp_cache_delete(self::QUEUE, 'options');
                wp_cache_delete('notoptions', 'options');
                return $taken;
            }
        }
        return false;
    }

    /**
     * Temporarily restore the original callback, including core's empty default.
     *
     * @param int[]       $terms    Term taxonomy IDs.
     * @param WP_Taxonomy $taxonomy Taxonomy object.
     */
    private static function count_terms($terms, $taxonomy) {
        $callback = $taxonomy->update_count_callback;
        $taxonomy->update_count_callback = self::$original[$taxonomy->name] ?? $callback;
        try {
            wp_update_term_count_now($terms, $taxonomy->name);
        } finally {
            $taxonomy->update_count_callback = $callback;
        }
    }

    /** Run a bounded batch. Cron and admin-post load all taxonomy plugins. */
    public static function run() {
        if (self::$running || !self::lock(0)) {
            self::schedule();
            return;
        }
        try {
            self::run_batch();
        } finally {
            self::lock(null);
        }
    }

    /**
     * @param int|null $wait Seconds to wait, or null to release.
     * @return bool Whether the lock operation succeeded.
     */
    private static function lock($wait) {
        global $wpdb;
        $name = substr('sps_counts_' . hash('sha256', $wpdb->dbname . $wpdb->options), 0, 64);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned lock is released on connection loss and needs no extra option.
        $result = null === $wait ? $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)) : $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait));
        return '1' === (string) $result;
    }

    /**
     * Prefer a short lock, but never abort a save when a recount cannot be queued.
     *
     * @param callable $count Immediate recount.
     */
    private static function immediate($count) {
        $locked = self::lock(2);
        self::$running = true;
        try {
            $count();
        } finally {
            self::$running = false;
            if ($locked) {
                self::lock(null);
            }
        }
    }

    /** Take and process a batch while holding the runner lock. */
    private static function run_batch() {
        $batch = self::queue(array(), true);
        if (false === $batch) {
            self::schedule();
            return;
        }
        if ($batch && self::HOOK === current_filter()) {
            // Cron consumed this event already: keep a retry before doing work.
            self::schedule();
        }
        self::$running = true;
        try {
            foreach ($batch as $name => $generations) {
                $ids = array_map('intval', array_keys($generations));
                if ('' === $name) {
                    foreach ($ids as $id) {
                        wp_update_comment_count_now($id);
                    }
                } else {
                    $taxonomy = get_taxonomy($name);
                    if ($taxonomy) {
                        self::count_terms($ids, $taxonomy);
                    }
                }
                // Failed acknowledgements leave durable work for another run.
                self::queue(array(), false, array($name => $generations));
            }
        } finally {
            self::$running = false;
            wp_cache_delete(self::QUEUE, 'options');
            if (get_option(self::QUEUE, array())) {
                self::schedule();
            }
            // Do not clear here: a concurrent writer may already have queued
            // and scheduled new work. A harmless empty event is safer.
        }
    }

    /** @param string $key Saved setting. */
    public static function setting_saved($key) {
        if (self::KEY === $key && !self::enabled()) {
            // Flush one batch now; any remainder finishes without deferring new writes.
            wp_clear_scheduled_hook(self::HOOK);
            self::run();
        }
    }

    /**
     * Also flush changes made through WP-CLI or the settings API.
     *
     * @param array $old Previous settings.
     * @param array $new Saved settings.
     */
    public static function options_updated($old, $new) {
        if (!empty($old[self::KEY]) && empty($new[self::KEY])) {
            self::setting_saved(self::KEY);
        }
    }

    /**
     * @param string $key   Setting key.
     * @param array  $field Schema field.
     */
    public static function panel($key, $field = array()) {
        if (self::KEY !== $key || !current_user_can('manage_options')) {
            return;
        }
        $url = wp_nonce_url(add_query_arg('action', self::ACTION, admin_url('admin-post.php')), self::ACTION);
        echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Recount now', 'seoprostack') . '</a></p>';
        echo '<p class="description">' . esc_html__('Recount up to 500 queued items now. Any remaining items follow in the background.', 'seoprostack') . '</p>';
    }

    /** Capability and nonce protected manual recount. */
    public static function recount_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot recount this site.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        self::run();
        wp_safe_redirect(admin_url('options-general.php?page=seoprostack&tab=server'));
        exit;
    }
}
