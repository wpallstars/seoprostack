<?php
/**
 * Publishing queue.
 *
 * When someone publishes a post from the editor without choosing a date, it
 * is scheduled for the next free time slot instead of going live straight
 * away. Slots are times of day on chosen weekdays; a slot is free when no
 * other scheduled post of a queued type uses it.
 *
 * Uses core scheduling only: the post becomes a normal "Scheduled" (future)
 * post and WordPress publishes it on time. Dates you set yourself, updates to
 * published posts, and imports/WP-CLI/cron saves are never changed.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Post_Scheduler extends SEOProStack_Feature {

    const KEY = 'post_scheduler';

    /** A publish date within this many seconds of now means "Immediately". */
    const NOW_TOLERANCE = 300;

    /** How far ahead to look for a free slot. */
    const HORIZON_DAYS = 366;

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
                'label'       => __('Publishing queue', 'seoprostack'),
                'description' => __('Publishing a post without choosing a date schedules it for the next free time slot. Dates you set yourself are kept.', 'seoprostack'),
            ),
            'post_scheduler_times' => array(
                'type'        => 'times',
                'default'     => '09:00, 15:00',
                'parent'      => self::KEY,
                'label'       => __('Time slots', 'seoprostack'),
                /* translators: %s: site timezone */
                'description' => sprintf(__('Times of day in your site timezone (%s), separated by commas. Each slot takes one post.', 'seoprostack'), wp_timezone_string()),
                'placeholder' => '09:00, 15:00',
            ),
            'post_scheduler_days' => array(
                'type'        => 'multi',
                'default'     => array('1', '2', '3', '4', '5'),
                'parent'      => self::KEY,
                'label'       => __('Days', 'seoprostack'),
                'description' => __('Days of the week that have slots.', 'seoprostack'),
                'options'     => array(__CLASS__, 'day_options'),
            ),
            'post_scheduler_post_types' => array(
                'type'        => 'multi',
                'default'     => array('post'),
                'parent'      => self::KEY,
                'label'       => __('Content types', 'seoprostack'),
                'description' => __('Types that go through the queue.', 'seoprostack'),
                'options'     => array(__CLASS__, 'post_type_options'),
            ),
        );
    }

    /**
     * Weekday options, ISO-8601 numbering (1 = Monday), in the site's week order.
     *
     * @return array<string,string>
     */
    public static function day_options() {
        global $wp_locale;
        $start   = (int) get_option('start_of_week', 1);
        $options = array();
        for ($i = 0; $i < 7; $i++) {
            $w                                    = ($start + $i) % 7; // 0 = Sunday.
            $options[(string) (0 === $w ? 7 : $w)] = $wp_locale ? $wp_locale->get_weekday($w) : gmdate('l', strtotime("Sunday +{$w} days"));
        }
        return $options;
    }

    /**
     * Public post types that support scheduling.
     *
     * @return array<string,string>
     */
    public static function post_type_options() {
        $options = array();
        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if ('attachment' === $type->name) {
                continue;
            }
            $options[$type->name] = $type->labels->name;
        }
        return $options;
    }

    /**
     * Register hooks when enabled.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('wp_insert_post_data', array(__CLASS__, 'queue'), 20, 2);
    }

    /**
     * Turn an immediate publish into a scheduled one.
     *
     * @param array $data    Slashed, sanitized post data.
     * @param array $postarr Raw post data.
     * @return array
     */
    public static function queue($data, $postarr) {
        if (!self::applies($data, $postarr)) {
            return $data;
        }

        $post_id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        $slot    = self::next_slot($data['post_type'], $post_id);
        if (!$slot) {
            return $data;
        }

        $data['post_status']   = 'future';
        $data['post_date']     = $slot->format('Y-m-d H:i:s');
        $data['post_date_gmt'] = get_gmt_from_date($data['post_date']);

        /**
         * Fires when a post is placed in the publishing queue.
         *
         * @param int               $post_id Post ID (0 for a new post).
         * @param DateTimeImmutable $slot    Scheduled time (site timezone).
         */
        do_action('seoprostack_post_queued', $post_id, $slot);

        return $data;
    }

    /**
     * Whether this save is an interactive "publish now".
     *
     * @param array $data    Post data.
     * @param array $postarr Raw post data.
     * @return bool
     */
    private static function applies($data, $postarr) {
        if ('publish' !== $data['post_status']) {
            return false;
        }
        if (!in_array($data['post_type'], (array) SEOProStack_Settings::get('post_scheduler_post_types'), true)) {
            return false;
        }

        // Only saves made by a person in the editor: the block editor uses
        // cookie-authenticated REST (which always sends X-WP-Nonce); the classic
        // editor posts to wp-admin. Imports, WP-CLI, cron, XML-RPC and
        // application-password API clients are left alone.
        $rest        = defined('REST_REQUEST') && REST_REQUEST && !empty($_SERVER['HTTP_X_WP_NONCE']);
        $interactive = $rest || (is_admin() && !wp_doing_ajax());
        if (!$interactive || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
            return false;
        }

        // Updates to posts that are already published or scheduled are left alone.
        $post_id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        if ($post_id) {
            $old_status = get_post_status($post_id);
            if (in_array($old_status, array('publish', 'future', 'private'), true)) {
                return false;
            }
        }

        // "Immediately": no date, or a date within a few minutes of now. A date
        // chosen in the past (back-dating) publishes as normal.
        $gmt = isset($data['post_date_gmt']) ? $data['post_date_gmt'] : '';
        if ($gmt && '0000-00-00 00:00:00' !== $gmt) {
            $timestamp = strtotime($gmt . ' UTC');
            if ($timestamp && abs(time() - $timestamp) > self::NOW_TOLERANCE) {
                return false;
            }
        }

        /**
         * Filter whether a publish goes through the queue.
         *
         * @param bool  $queue   Whether to queue.
         * @param array $data    Post data.
         * @param array $postarr Raw post data.
         */
        return (bool) apply_filters('seoprostack_post_scheduler_applies', true, $data, $postarr);
    }

    /**
     * Next free slot after now.
     *
     * @param string $post_type Post type being published.
     * @param int    $post_id   Post being published (ignored when checking slots).
     * @return DateTimeImmutable|null
     */
    public static function next_slot($post_type, $post_id = 0) {
        $times = SEOProStack_Settings::parse_times(SEOProStack_Settings::get('post_scheduler_times'));
        $days  = array_map('intval', (array) SEOProStack_Settings::get('post_scheduler_days'));
        if (!$times || !$days) {
            return null;
        }

        $tz    = wp_timezone();
        $now   = new DateTimeImmutable('now', $tz);
        $taken = self::taken_slots($post_id);
        $day   = $now->setTime(0, 0);

        for ($i = 0; $i <= self::HORIZON_DAYS; $i++, $day = $day->modify('+1 day')) {
            if (!in_array((int) $day->format('N'), $days, true)) {
                continue;
            }
            foreach ($times as $time) {
                list($hour, $minute) = array_map('intval', explode(':', $time));
                $slot                = $day->setTime($hour, $minute);
                if ($slot <= $now->modify('+1 minute')) {
                    continue;
                }
                if (!isset($taken[$slot->format('Y-m-d H:i')])) {
                    return $slot;
                }
            }
        }

        return null;
    }

    /**
     * Minutes already used by scheduled posts of queued types.
     *
     * @param int $exclude Post ID to ignore.
     * @return array<string,true> 'Y-m-d H:i' => true
     */
    private static function taken_slots($exclude) {
        $posts = get_posts(array(
            'post_type'              => (array) SEOProStack_Settings::get('post_scheduler_post_types'),
            'post_status'            => 'future',
            'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- scheduled posts only, without meta or terms.
            'orderby'                => 'date',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        $taken = array();
        foreach ($posts as $post) {
            if ((int) $post->ID !== (int) $exclude) {
                $taken[substr((string) $post->post_date, 0, 16)] = true;
            }
        }
        return $taken;
    }
}
