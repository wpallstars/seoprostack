<?php
/**
 * Stored-content link index and bounded, opt-in link health checks.
 *
 * Rank Math owns its counts when its public reporting API is available.
 * Our fallback index is only populated otherwise. Health results are shared
 * by address; visitor requests never build indexes or check remote sites.
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

final class SEOProStack_Link_Index {
    const STATE = 'seoprostack_link_index';
    const VERSION = 'seoprostack_link_tables';
    const SCHEMA = '2';
    const CRON = 'seoprostack_link_batch';
    const META = '_seoprostack_link_scan';
    const MAP = '_seoprostack_link_map';

    /** Create only our tables, after the owner enables the toolkit. */
    public static function install() {
        if (self::SCHEMA === get_option(self::VERSION)) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}seoprostack_links (
            post_id bigint(20) unsigned NOT NULL,
            url_hash char(64) NOT NULL,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(8) NOT NULL,
            occurrences int unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (post_id,url_hash),
            KEY target_id (target_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}seoprostack_link_health (
            url_hash char(64) NOT NULL,
            url text NOT NULL,
            status smallint unsigned NOT NULL DEFAULT 0,
            redirects tinyint unsigned NOT NULL DEFAULT 0,
            checked_at bigint(20) unsigned NOT NULL DEFAULT 0,
            error varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (url_hash),
            KEY checked_at (checked_at)
        ) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}seoprostack_link_clicks (
            post_id bigint(20) unsigned NOT NULL,
            url_hash char(64) NOT NULL,
            url text NOT NULL,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            day date NOT NULL,
            clicks int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (post_id,url_hash,day),
            KEY day (day)
        ) $charset;");
        if (self::has_table('seoprostack_links') && self::has_table('seoprostack_link_health') && self::has_table('seoprostack_link_clicks')) {
            update_option(self::VERSION, self::SCHEMA, false);
            delete_option(self::STATE);
        }
    }

    /** @param string $suffix Table suffix, never supplied by a request. @return bool */
    public static function has_table($suffix) {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema detection, not content data.
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    /** Reuse Rank Math 1.0.266+'s reporting API, never its private storage methods. @return string */
    public static function provider() {
        $helper = 'RankMath\\Helper';
        if (!class_exists($helper) || !is_callable(array($helper, 'is_module_active')) || !call_user_func(array($helper, 'is_module_active'), 'link-counter') || !self::rank_math_api()) {
            return 'native';
        }
        return self::has_table('rank_math_internal_meta') && self::has_table('rank_math_internal_links') ? 'rank_math' : 'native';
    }

    /** Validate the optional public API without bundling or depending on Rank Math. @return object|null */
    private static function rank_math_api() {
        static $api = null;
        if (null !== $api) {
            return $api;
        }
        $class = 'RankMath\\Links\\Api\\Controller';
        if (!class_exists($class)) {
            return null;
        }
        // Resolve the actual exported class after autoloading, rather than
        // pretending an optional third-party class is a bundled dependency.
        $classes = get_declared_classes();
        $index = array_search($class, $classes, true);
        if (false === $index) {
            return null;
        }
        $reflection = new ReflectionClass($classes[$index]);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || ($constructor && $constructor->getNumberOfRequiredParameters()) || !$reflection->hasMethod('get_posts_data') || !$reflection->getMethod('get_posts_data')->isPublic()) {
            return null;
        }
        $api = $reflection->newInstance();
        return $api;
    }

    /** Do not duplicate Link Genius's existing crawler. @return string */
    public static function health_provider() {
        $provider = 'rank_math' === self::provider() && class_exists('RankMathPro\\Link_Genius\\Link_Genius') && self::has_table('rank_math_link_genius_audit') ? 'rank_math' : 'native';
        /** Override only when the detected Pro report is not usable on this site. */
        return 'rank_math' === apply_filters('seoprostack_link_health_provider', $provider) ? 'rank_math' : 'native';
    }

    /** @return string[] */
    public static function types() {
        return array_values(array_diff(get_post_types(array('public' => true)), array('attachment')));
    }

    /** @param mixed $post Post or ID. @return bool */
    public static function eligible($post) {
        $post = get_post($post);
        return $post instanceof WP_Post && 'publish' === $post->post_status && '' === $post->post_password && in_array($post->post_type, self::types(), true);
    }

    /**
     * Group by destination path, not query strings, fragments or credentials.
     *
     * @param string $href Original address.
     * @param string $base Source permalink.
     * @return string
     */
    public static function address($href, $base) {
        $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
        if ('' === $href || '#' === substr($href, 0, 1) || strlen($href) > 2048) {
            return '';
        }
        $url = WP_Http::make_absolute_url($href, $base);
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        return esc_url_raw(strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . (isset($parts['path']) ? $parts['path'] : '/'), array('http', 'https'));
    }

    /** @param string $url Absolute address. @return bool */
    public static function internal($url) {
        return strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    /**
     * Preserve plain-permalink page identity without storing query strings.
     *
     * @param string $href Original address.
     * @param string $base Source permalink.
     * @return array {url, hash, target}; empty for unsupported addresses.
     */
    public static function identity($href, $base) {
        $url = self::address($href, $base);
        if ('' === $url) {
            return array();
        }
        $target = 0;
        if (self::internal($url)) {
            $raw = WP_Http::make_absolute_url(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $base);
            $query = wp_parse_url($raw, PHP_URL_QUERY);
            $params = array();
            if (is_string($query)) {
                parse_str($query, $params);
            }
            foreach (array('p', 'page_id') as $key) {
                if (isset($params[$key]) && is_string($params[$key]) && ctype_digit($params[$key])) {
                    $target = absint($params[$key]);
                    break;
                }
            }
        }
        return array('url' => $url, 'hash' => hash('sha256', $url . ($target ? '|post:' . $target : '')), 'target' => $target);
    }

    /**
     * Extract at most 200 distinct destinations, without executing blocks or shortcodes.
     *
     * @param WP_Post $post Source.
     * @return array
     */
    public static function extract($post) {
        $links = array();
        if (strlen($post->post_content) > 2 * MB_IN_BYTES) {
            return array('links' => array(), 'truncated' => true);
        }
        $processor = new WP_HTML_Tag_Processor($post->post_content);
        $base = (string) get_permalink($post);
        $truncated = false;
        while ($processor->next_tag(array('tag_name' => 'A'))) {
            $href = $processor->get_attribute('href');
            if (!is_string($href)) {
                continue;
            }
            $identity = self::identity($href, $base);
            if (!$identity) {
                continue;
            }
            $url = $identity['url'];
            $hash = $identity['hash'];
            if (isset($links[$hash])) {
                ++$links[$hash]['occurrences'];
                continue;
            }
            if (count($links) >= 200) {
                $truncated = true;
                break;
            }
            // Never automatically request query-bearing URLs or admin/API endpoints.
            $raw = WP_Http::make_absolute_url(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $base);
            $path = (string) wp_parse_url($raw, PHP_URL_PATH);
            $checkable = null === wp_parse_url($raw, PHP_URL_QUERY) && !preg_match('~(?:/(?:wp-admin|wp-json)(?:/|$))|(?:/wp-login\.php$)~i', $path);
            $links[$hash] = array('url' => $url, 'target' => $identity['target'], 'occurrences' => 1, 'checkable' => $checkable);
        }
        return array('links' => $links, 'truncated' => $truncated);
    }

    /** Queue a full scan; old results stay visible but are explicitly incomplete. */
    public static function start() {
        self::install();
        $provider = self::provider();
        $done = 'rank_math' === $provider && !SEOProStack_Settings::get('linking_clicks') && (!SEOProStack_Settings::get('linking_health') || 'rank_math' === self::health_provider());
        update_option(self::STATE, array('cursor' => 0, 'done' => $done, 'provider' => $provider, 'started' => time(), 'finished' => $done ? time() : 0), false);
        if (!$done) {
            self::schedule();
        }
    }

    /** @param int $delay Seconds until the next bounded batch. */
    public static function schedule($delay = 60) {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_single_event(time() + $delay, self::CRON);
        }
    }

    /** @param int $post_id Changed post. */
    public static function saved($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        // The scanner is bounded and runs on saves, not visitor requests.
        self::scan($post_id);
    }

    /** @param int $post_id Source. */
    public static function scan($post_id) {
        global $wpdb;
        if (self::SCHEMA !== get_option(self::VERSION)) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our derived fallback index must match the saved post.
        $wpdb->delete($wpdb->prefix . 'seoprostack_links', array('post_id' => $post_id), array('%d'));
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !self::eligible($post)) {
            delete_post_meta($post_id, self::META);
            delete_post_meta($post_id, self::MAP);
            return;
        }
        $native = 'native' === self::provider();
        $health = SEOProStack_Settings::get('linking_health') && 'native' === self::health_provider();
        $track = (bool) SEOProStack_Settings::get('linking_clicks');
        if (!$native && !$health && !$track) {
            delete_post_meta($post_id, self::MAP);
            return;
        }
        $result = self::extract($post);
        $map = array();
        foreach ($result['links'] as $hash => $link) {
            $internal = self::internal($link['url']);
            if ($native) {
                $target = $internal ? ($link['target'] ? $link['target'] : url_to_postid($link['url'])) : 0;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this index is the cache, and is absent when Rank Math supplies counts.
                $wpdb->insert($wpdb->prefix . 'seoprostack_links', array('post_id' => $post_id, 'url_hash' => $hash, 'target_id' => $target, 'kind' => $internal ? 'internal' : 'external', 'occurrences' => $link['occurrences']), array('%d', '%s', '%d', '%s', '%d'));
            }
            if ($health && $link['checkable']) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a shared health cache, never a remote request during a save.
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}seoprostack_link_health (url_hash,url) VALUES (%s,%s)", $hash, $link['url']));
            }
            if ($track) {
                $map[$hash] = array('url' => $link['url'], 'target' => $link['target']);
            }
        }
        if ($map) {
            update_post_meta($post_id, self::MAP, $map);
        } else {
            delete_post_meta($post_id, self::MAP);
        }
        update_post_meta($post_id, self::META, array('hash' => hash('sha256', $post->post_content), 'at' => time(), 'truncated' => $result['truncated']));
        if ($health) {
            self::schedule();
        }
    }

    /** Resume at most 20 posts and two health checks under a shared time budget. */
    public static function batch() {
        if (!SEOProStack_Linking::switched_on()) {
            return;
        }
        self::install();
        $start = microtime(true);
        $state = get_option(self::STATE, array());
        if (!is_array($state) || !isset($state['provider']) || $state['provider'] !== self::provider()) {
            self::start();
            $state = get_option(self::STATE, array());
        }
        if (empty($state['done'])) {
            global $wpdb;
            $types = self::types();
            if (!$types) {
                return;
            }
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            $args = array_merge(array((int) $state['cursor']), $types);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- interpolation contains only generated %s placeholders; every type is prepared.
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_password = '' AND post_type IN ($placeholders) ORDER BY ID LIMIT 20", $args));
            foreach ($ids as $id) {
                if (!SEOProStack_Feature::more_time($start, 12)) {
                    break;
                }
                self::scan((int) $id);
                $state['cursor'] = (int) $id;
            }
            if (!$ids) {
                $state['done'] = true;
                $state['finished'] = time();
            }
            update_option(self::STATE, $state, false);
        }
        if (SEOProStack_Settings::get('linking_health') && 'native' === self::health_provider() && SEOProStack_Feature::more_time($start, 12)) {
            self::health_batch();
        }
        SEOProStack_Link_Clicks::prune();
        if (empty($state['done']) || (SEOProStack_Settings::get('linking_health') && 'native' === self::health_provider())) {
            self::schedule(empty($state['done']) ? 60 : HOUR_IN_SECONDS);
        }
    }

    /** Perform at most two cached URL checks, with safe redirect validation. */
    public static function health_batch() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a bounded queue over the indexed cache timestamp.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT url_hash,url FROM {$wpdb->prefix}seoprostack_link_health WHERE checked_at < %d ORDER BY checked_at LIMIT 2", time() - 7 * DAY_IN_SECONDS), ARRAY_A);
        foreach ($rows as $row) {
            $result = self::check($row['url']);
            $result['checked_at'] = time();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- updating the cache that the report reads.
            $wpdb->update($wpdb->prefix . 'seoprostack_link_health', $result, array('url_hash' => $row['url_hash']), array('%d', '%d', '%s', '%d'), array('%s'));
        }
    }

    /** @param string $url Untrusted content URL. @return array */
    public static function check($url) {
        $deadline = microtime(true) + 8;
        $result = array('status' => 0, 'redirects' => 0, 'error' => '');
        for ($hop = 0; $hop <= 3; ++$hop) {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if (!wp_http_validate_url($url) || null !== wp_parse_url($url, PHP_URL_QUERY) || preg_match('~(?:/(?:wp-admin|wp-json)(?:/|$))|(?:/wp-login\.php$)~i', $path)) {
                $result['error'] = 'unsafe_url';
                break;
            }
            // Core permits a private address matching home_url(). This tool
            // deliberately does not: content must not probe local services.
            $ip = gethostbyname((string) wp_parse_url($url, PHP_URL_HOST));
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $result['error'] = 'unsafe_url';
                break;
            }
            if (microtime(true) >= $deadline) {
                $result['error'] = 'time_budget';
                break;
            }
            $args = array('timeout' => 2, 'redirection' => 0, 'limit_response_size' => 1024, 'cookies' => array(), 'headers' => array());
            $response = wp_safe_remote_head($url, $args);
            if (!is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), array(403, 404, 405, 501), true) && microtime(true) < $deadline) {
                $response = wp_safe_remote_get($url, $args);
            }
            if (is_wp_error($response)) {
                $result['error'] = sanitize_key((string) $response->get_error_code());
                break;
            }
            $result['status'] = wp_remote_retrieve_response_code($response);
            if (!in_array($result['status'], array(301, 302, 303, 307, 308), true)) {
                break;
            }
            $location = wp_remote_retrieve_header($response, 'location');
            if (!is_string($location) || '' === $location || 3 === $hop) {
                $result['error'] = 'redirect_limit';
                break;
            }
            $url = WP_Http::make_absolute_url($location, $url);
            ++$result['redirects'];
        }
        return $result;
    }

    /** @param int $page Page. @return array */
    public static function report($page) {
        $page = max(1, (int) $page);
        if ('rank_math' === self::provider()) {
            $callback = array(self::rank_math_api(), 'get_posts_data');
            $data = is_callable($callback) ? call_user_func($callback, array('page' => $page, 'per_page' => 30, 'post_type' => self::types())) : null;
            if (is_array($data) && isset($data['posts'], $data['pages'])) {
                return array('posts' => $data['posts'], 'pages' => (int) $data['pages'], 'provider' => 'rank_math');
            }
        }
        $query = new WP_Query(array('post_type' => self::types(), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 30, 'paged' => $page, 'orderby' => 'title', 'order' => 'ASC'));
        $rows = array();
        global $wpdb;
        foreach ($query->posts ?? array() as $post) {
            if (!$post instanceof WP_Post) {
                continue; // A pre_get_posts filter asked for IDs only.
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only the fallback index supplies these counts.
            $out = $wpdb->get_results($wpdb->prepare("SELECT kind,SUM(occurrences) AS amount FROM {$wpdb->prefix}seoprostack_links WHERE post_id = %d GROUP BY kind", $post->ID), OBJECT_K);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed incoming destinations; self-links do not rescue orphan candidates.
            $incoming = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(occurrences),0) FROM {$wpdb->prefix}seoprostack_links WHERE target_id = %d AND post_id <> %d", $post->ID, $post->ID));
            $rows[] = (object) array('post_id' => $post->ID, 'post_title' => $post->post_title, 'internal_link_count' => isset($out['internal']) ? (int) $out['internal']->amount : 0, 'external_link_count' => isset($out['external']) ? (int) $out['external']->amount : 0, 'incoming_link_count' => $incoming);
        }
        return array('posts' => $rows, 'pages' => $query->max_num_pages, 'provider' => 'native');
    }
}
