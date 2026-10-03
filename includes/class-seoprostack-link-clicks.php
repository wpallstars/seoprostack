<?php
/**
 * Optional daily link-event totals, without visitor identifiers or cookies.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Link_Clicks {
    const PRUNE = 'seoprostack_link_click_prune';
    const PRUNE_MORE = 'seoprostack_link_click_prune_more';

    /** Register only when the owner explicitly enables counting. */
    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_filter('the_content', array(__CLASS__, 'decorate'), 99);
        if (!wp_next_scheduled(self::PRUNE)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::PRUNE);
        }
    }

    /** @param int $post_id Public source. @param string $hash Registered path hash. @return string */
    public static function token($post_id, $hash) {
        return hash_hmac('sha256', get_current_blog_id() . '|' . $post_id . '|' . $hash, wp_salt('auth'));
    }

    /** A public, signed event route; it cannot accept arbitrary addresses or personal data. */
    public static function routes() {
        register_rest_route('seoprostack/v1', '/link-click', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'record'),
            'permission_callback' => '__return_true',
            'args' => array(
                'post' => array('required' => true, 'type' => 'integer', 'minimum' => 1),
                'link' => array('required' => true, 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
                'token' => array('required' => true, 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
            ),
        ));
    }

    /**
     * Decorate only saved, indexed links in the public singular main content.
     * Cached pages carry a signature, not an expiring user/session nonce.
     *
     * @param string $content Rendered content.
     * @return string
     */
    public static function decorate($content) {
        if (is_admin() || is_feed() || !is_singular() || is_user_logged_in() || !in_the_loop() || !is_main_query() || get_the_ID() !== get_queried_object_id()) {
            return $content;
        }
        $post_id = get_the_ID();
        if (!SEOProStack_Link_Index::eligible($post_id)) {
            return $content;
        }
        $map = get_post_meta($post_id, SEOProStack_Link_Index::MAP, true);
        if (!is_array($map) || !$map) {
            return $content;
        }
        $processor = new WP_HTML_Tag_Processor($content);
        $changed = false;
        $base = (string) get_permalink($post_id);
        while ($processor->next_tag(array('tag_name' => 'A'))) {
            $href = $processor->get_attribute('href');
            if (!is_string($href)) {
                continue;
            }
            $identity = SEOProStack_Link_Index::identity($href, $base);
            if ($identity && isset($map[$identity['hash']])) {
                $hash = $identity['hash'];
                $processor->set_attribute('data-sps-link-post', (string) $post_id);
                $processor->set_attribute('data-sps-link-hash', $hash);
                $processor->set_attribute('data-sps-link-token', self::token($post_id, $hash));
                $changed = true;
            }
        }
        if ($changed) {
            wp_enqueue_script('seoprostack-link-clicks', SEOPROSTACK_URL . 'public/js/seoprostack-link-clicks.js', array(), SEOPROSTACK_VERSION, true);
            wp_add_inline_script('seoprostack-link-clicks', 'window.seoprostackLinkClicks = ' . wp_json_encode(array('endpoint' => rest_url('seoprostack/v1/link-click'))) . ';', 'before');
        }
        return $changed ? $processor->get_updated_html() : $content;
    }

    /** @param WP_REST_Request $request Signed event. @return WP_REST_Response|WP_Error */
    public static function record($request) {
        if (!SEOProStack_Linking::switched_on() || !SEOProStack_Settings::get('linking_clicks') || SEOProStack_Link_Index::SCHEMA !== get_option(SEOProStack_Link_Index::VERSION)) {
            return new WP_Error('not_enabled', __('Link counting is off.', 'seoprostack'), array('status' => 403));
        }
        // Honour privacy signals on the server as well as in the browser.
        if ('1' === $request->get_header('dnt') || '1' === $request->get_header('sec-gpc') || is_user_logged_in()) {
            return new WP_REST_Response(null, 204);
        }
        $origin = $request->get_header('origin');
        $home = wp_parse_url(home_url());
        $parts = $origin ? wp_parse_url($origin) : false;
        if ($origin && (!is_array($parts) || !isset($parts['host'], $parts['scheme']) || strtolower($parts['host']) !== strtolower($home['host']) || $parts['scheme'] !== $home['scheme'] || (isset($parts['port']) ? $parts['port'] : 0) !== (isset($home['port']) ? $home['port'] : 0))) {
            return new WP_Error('origin', __('This event is not from this site.', 'seoprostack'), array('status' => 403));
        }
        $post_id = absint($request['post']);
        $hash = (string) $request['link'];
        $token = (string) $request['token'];
        if (!SEOProStack_Link_Index::eligible($post_id) || !hash_equals(self::token($post_id, $hash), $token)) {
            return new WP_Error('signature', __('This link event is not valid.', 'seoprostack'), array('status' => 403));
        }
        $map = get_post_meta($post_id, SEOProStack_Link_Index::MAP, true);
        if (!is_array($map) || !isset($map[$hash]['url'], $map[$hash]['target'])) {
            return new WP_Error('link', __('This link is not registered.', 'seoprostack'), array('status' => 403));
        }
        global $wpdb;
        // Atomic totals, bounded to 5,000 events per registered link per UTC day.
        // Public signatures are not a fraud-prevention or unique-visitor mechanism.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the aggregate table is the cache; no visitor identity is collected.
        $saved = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}seoprostack_link_clicks (post_id,url_hash,url,target_id,day,clicks) VALUES (%d,%s,%s,%d,%s,1) ON DUPLICATE KEY UPDATE clicks = LEAST(clicks + 1,5000)", $post_id, $hash, $map[$hash]['url'], $map[$hash]['target'], gmdate('Y-m-d')));
        return false === $saved ? new WP_Error('storage', __('Could not count this event.', 'seoprostack'), array('status' => 503)) : new WP_REST_Response(null, 204);
    }

    /** Keep at most 90 days, including after counting has been switched off. */
    public static function prune() {
        if (SEOProStack_Link_Index::SCHEMA !== get_option(SEOProStack_Link_Index::VERSION)) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded retention of our aggregate-only records.
        $removed = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}seoprostack_link_clicks WHERE day < %s LIMIT 1000", gmdate('Y-m-d', time() - 89 * DAY_IN_SECONDS)));
        if (1000 === $removed && !wp_next_scheduled(self::PRUNE_MORE)) {
            wp_schedule_single_event(time() + 60, self::PRUNE_MORE);
        }
    }
}
