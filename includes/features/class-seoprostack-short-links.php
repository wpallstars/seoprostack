<?php
/**
 * Short links.
 *
 * Short addresses on this site, such as /go/offer/, that send visitors to
 * another address with a 301, 302 or 307 redirect:
 * - links are a post type (Short links in the admin menu) with categories,
 *   so listing, searching, bulk actions and the bin come from WordPress;
 * - live links are kept in one small autoloaded option, so a request that
 *   is not a short link costs an array lookup and no query;
 * - clicks and unique visitors (a cookie per link) are counted after the
 *   redirect has been sent; known bots are not counted;
 * - three review links (/googlereview/, /facebookreview/, /trustpilotreview/)
 *   are added once, pointing at placeholders until the site owner sets them.
 *
 * Replaces "Pretty Links": imports its links with their click counts and
 * categories (button, WP-CLI, or when Pretty Links is deactivated) and its
 * defaults for new links.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Short_Links extends SEOProStack_Feature {

    const KEY = 'short_links';

    /** Post type and taxonomy. */
    const TYPE = 'sps_short_link';
    const TAX  = 'sps_short_link_cat';

    /** Option: live links by lower-case address, autoloaded. */
    const MAP_OPTION = 'seoprostack_short_links';

    /** Post meta key prefix. */
    const META = '_seoprostack_link_';

    /** admin-post.php action that imports Pretty Links. */
    const IMPORT = 'seoprostack_import_pretty_links';

    /** Pretty Links' main file. */
    const PRLI_FILE = 'pretty-link/pretty-link.php';

    /** Redirect status codes offered. */
    const STATUSES = array('301', '302', '307');

    /** Seconds an import in the admin may run; the rest follows next time. */
    const IMPORT_BUDGET = 20;

    /** Option: version of the starter review links this site has had. */
    const PRESETS_OPTION = 'seoprostack_short_links_presets';

    /**
     * Starter review links version: 1 added the links, 2 puts them in the
     * Review Requests category.
     */
    const PRESETS_VERSION = 2;

    /**
     * Whether the map is rebuilt at the end of this request.
     *
     * @var bool
     */
    private static $refresh = false;

    /**
     * Notice code for the redirect after saving a link.
     *
     * @var string
     */
    private static $notice = '';

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
                'tab'         => 'links',
                'label'       => __('Short links', 'seoprostack'),
                'description' => __('Make short addresses on this site, such as /go/offer/, that send visitors to another address, and count the clicks. Manage them under Short links in the admin menu.', 'seoprostack'),
                'replaces'    => array('pretty-link' => 'Pretty Links'),
            ),
            'short_links_redirect' => array(
                'type'        => 'select',
                'default'     => '302',
                'parent'      => self::KEY,
                'label'       => __('Redirect for new links', 'seoprostack'),
                'description' => __('Temporary redirects let you change where a link goes later. Browsers remember permanent ones.', 'seoprostack'),
                'options'     => self::status_options(),
            ),
            'short_links_nofollow' => array(
                'type'    => 'bool',
                'default' => true,
                'parent'  => self::KEY,
                'label'   => __('New links: ask search engines not to follow them (nofollow)', 'seoprostack'),
            ),
            'short_links_sponsored' => array(
                'type'    => 'bool',
                'default' => false,
                'parent'  => self::KEY,
                'label'   => __('New links: mark as paid or affiliate (sponsored)', 'seoprostack'),
            ),
            'short_links_track' => array(
                'type'    => 'bool',
                'default' => true,
                'parent'  => self::KEY,
                'label'   => __('New links: count clicks', 'seoprostack'),
            ),
            'short_links_prefix' => array(
                'type'        => 'text',
                'default'     => '',
                'placeholder' => 'go/',
                'parent'      => self::KEY,
                'label'       => __('Start new addresses with', 'seoprostack'),
                'description' => __('Optional, such as go/ for /go/offer/. Each link’s address can still be changed.', 'seoprostack'),
            ),
        );
    }

    /**
     * Redirect choices.
     *
     * @return array<string,string>
     */
    public static function status_options() {
        return array(
            '301' => __('Permanent (301)', 'seoprostack'),
            '302' => __('Temporary (302)', 'seoprostack'),
            '307' => __('Temporary (307)', 'seoprostack'),
        );
    }

    /**
     * Import Pretty Links' defaults for new links, and switch on while it is
     * active with links, so they are imported when it is deactivated.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $prli = get_option('prli_options', null);
        // Pretty Links 3 stored an object.
        $prli = is_object($prli) ? (array) $prli : $prli;
        if (is_array($prli)) {
            if (isset($prli['link_redirect_type']) && in_array((string) $prli['link_redirect_type'], self::STATUSES, true)) {
                $options = self::import_setting($options, 'short_links_redirect', (string) $prli['link_redirect_type']);
            }
            $map = array(
                'link_nofollow'  => 'short_links_nofollow',
                'link_sponsored' => 'short_links_sponsored',
                'link_track_me'  => 'short_links_track',
            );
            foreach ($map as $from => $to) {
                if (isset($prli[$from]) && is_scalar($prli[$from])) {
                    $options = self::import_setting($options, $to, (bool) $prli[$from]);
                }
            }
        }
        $pro = get_option('prlipro_options', null);
        $pro = is_object($pro) ? (array) $pro : $pro;
        if (is_array($pro) && !empty($pro['base_slug_prefix']) && is_string($pro['base_slug_prefix'])) {
            $prefix = self::clean_slug($pro['base_slug_prefix']);
            if ('' !== $prefix) {
                $options = self::import_setting($options, 'short_links_prefix', $prefix . '/');
            }
        }
        if (isset(self::active_plugins()['pretty-link']) && self::pretty_links_rows()) {
            $options = self::import_setting($options, self::KEY, true);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        }
        // Switched off: drop the autoloaded list; it is rebuilt when needed.
        add_action('update_option_' . SEOProStack_Settings::OPTION, array(__CLASS__, 'settings_saved'), 10, 2);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack short-links import', array(__CLASS__, 'cli_import'));
        }
        if (!self::switched_on()) {
            return;
        }

        // Also while Pretty Links is active, so links can be imported and
        // checked before it is deactivated.
        self::register();
        add_action('deactivated_plugin', array(__CLASS__, 'plugin_deactivated'));
        add_action('transition_post_status', array(__CLASS__, 'status_changed'), 10, 3);
        add_action('delete_post', array(__CLASS__, 'deleted'));
        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'maybe_add_presets'));
            add_action('admin_post_' . self::IMPORT, array(__CLASS__, 'handle_import'));
            add_action('add_meta_boxes_' . self::TYPE, array(__CLASS__, 'add_box'));
            add_action('save_post_' . self::TYPE, array(__CLASS__, 'save'), 10, 2);
            add_filter('redirect_post_location', array(__CLASS__, 'redirect_location'), 10, 2);
            add_filter('enter_title_here', array(__CLASS__, 'title_placeholder'), 10, 2);
            add_filter('post_updated_messages', array(__CLASS__, 'messages'));
            add_filter('bulk_post_updated_messages', array(__CLASS__, 'bulk_messages'), 10, 2);
            add_filter('manage_' . self::TYPE . '_posts_columns', array(__CLASS__, 'columns'));
            add_action('manage_' . self::TYPE . '_posts_custom_column', array(__CLASS__, 'column'), 10, 2);
            add_filter('manage_edit-' . self::TYPE . '_sortable_columns', array(__CLASS__, 'sortable'));
            add_action('pre_get_posts', array(__CLASS__, 'list_query'));
            add_action('admin_notices', array(__CLASS__, 'notices'));
        }

        if (!self::enabled()) {
            return;
        }
        // Before WordPress matches the address against its rewrite rules.
        add_filter('do_parse_request', array(__CLASS__, 'maybe_redirect'), 1);
    }

    /**
     * Post type and categories.
     */
    public static function register() {
        register_post_type(self::TYPE, array(
            'labels'              => array(
                'name'                     => __('Short links', 'seoprostack'),
                'singular_name'            => __('Short link', 'seoprostack'),
                'menu_name'                => __('Short links', 'seoprostack'),
                'all_items'                => __('All short links', 'seoprostack'),
                'add_new'                  => __('Add short link', 'seoprostack'),
                'add_new_item'             => __('Add short link', 'seoprostack'),
                'edit_item'                => __('Edit short link', 'seoprostack'),
                'new_item'                 => __('New short link', 'seoprostack'),
                'search_items'             => __('Search short links', 'seoprostack'),
                'not_found'                => __('No short links found.', 'seoprostack'),
                'not_found_in_trash'       => __('No short links found in the bin.', 'seoprostack'),
                'item_published'           => __('Short link saved.', 'seoprostack'),
                'item_updated'             => __('Short link updated.', 'seoprostack'),
                'item_reverted_to_draft'   => __('Short link turned off.', 'seoprostack'),
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'menu_position'       => 26,
            'menu_icon'           => 'dashicons-admin-links',
            'capability_type'     => 'page',
            'map_meta_cap'        => true,
            'supports'            => array('title'),
            'rewrite'             => false,
            'query_var'           => false,
        ));
        register_taxonomy(self::TAX, self::TYPE, array(
            'labels'            => array(
                'name'          => __('Categories', 'seoprostack'),
                'singular_name' => __('Category', 'seoprostack'),
                'menu_name'     => __('Categories', 'seoprostack'),
                'search_items'  => __('Search categories', 'seoprostack'),
                'all_items'     => __('All categories', 'seoprostack'),
                'edit_item'     => __('Edit category', 'seoprostack'),
                'add_new_item'  => __('Add category', 'seoprostack'),
                'not_found'     => __('No categories found.', 'seoprostack'),
            ),
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => false,
            'hierarchical'      => true,
            'rewrite'           => false,
            'query_var'         => false,
            'capabilities'      => array(
                'manage_terms' => 'edit_pages',
                'edit_terms'   => 'edit_pages',
                'delete_terms' => 'edit_pages',
                'assign_terms' => 'edit_pages',
            ),
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Addresses                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Tidy an address typed by a person or imported: no slashes at the
     * ends or doubled, only letters, digits and - _ . ~ / .
     *
     * @param string $slug Address.
     * @return string
     */
    public static function clean_slug($slug) {
        $slug = trim(rawurldecode((string) $slug));
        $home = trailingslashit(home_url());
        if (0 === stripos($slug, $home)) {
            $slug = substr($slug, strlen($home));
        }
        $slug  = strtok($slug, '?#');
        $slug  = preg_replace('#[^\p{L}\p{N}/_.~-]+#u', '-', (string) $slug);
        $parts = array();
        foreach (explode('/', (string) $slug) as $part) {
            $part = trim($part, '-');
            if ('' !== $part && '.' !== $part && '..' !== $part) {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    /**
     * Lower-case key for matching.
     *
     * @param string $slug Address.
     * @return string
     */
    public static function key($slug) {
        return function_exists('mb_strtolower') ? mb_strtolower($slug, 'UTF-8') : strtolower($slug);
    }

    /**
     * Whether an address belongs to WordPress itself.
     *
     * @param string $slug Clean address.
     * @return bool
     */
    public static function reserved($slug) {
        $first = self::key((string) strtok($slug, '/'));
        return 0 === strpos($first, 'wp-') || in_array($first, array('index.php', 'xmlrpc.php', 'feed', 'comments', 'embed', 'trackback', 'favicon.ico', 'robots.txt', 'sitemap.xml'), true);
    }

    /**
     * Full short address.
     *
     * @param string $slug Clean address.
     * @return string
     */
    public static function url($slug) {
        return home_url('/' . $slug . (false === strpos(wp_basename($slug), '.') ? '/' : ''));
    }

    /**
     * Address of this request, relative to the home page.
     *
     * @return string
     */
    private static function request_slug() {
        if (empty($_SERVER['REQUEST_URI'])) {
            return '';
        }
        // Compared with stored addresses only.
        $path = trim(rawurldecode((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)), '/'); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $home = trim(rawurldecode((string) wp_parse_url(home_url(), PHP_URL_PATH)), '/');
        if ('' !== $home) {
            if (0 !== strpos($path . '/', $home . '/')) {
                return '';
            }
            $path = ltrim(substr($path, strlen($home)), '/');
        }
        if (0 === strpos($path, 'index.php/')) {
            $path = substr($path, strlen('index.php/'));
        }
        return $path;
    }

    /* --------------------------------------------------------------------- */
    /* Redirects                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Live links by lower-case address. Built from the posts when missing.
     *
     * @return array<string,array{id:int,url:string,status:int,nofollow:bool,sponsored:bool,track:bool}>
     */
    public static function map() {
        $map = get_option(self::MAP_OPTION);
        return is_array($map) ? $map : self::build_map();
    }

    /**
     * Rebuild and store the live links.
     *
     * @return array
     */
    public static function build_map() {
        // get_posts() suppresses query filters by default.
        $ids = get_posts(array(
            'post_type'   => self::TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'orderby'     => 'ID',
            'order'       => 'ASC',
        ));
        update_meta_cache('post', $ids);
        $map = array();
        foreach ($ids as $id) {
            $link = self::link($id);
            if ('' !== $link['slug'] && '' !== $link['url'] && !isset($map[self::key($link['slug'])])) {
                $map[self::key($link['slug'])] = array(
                    'id'        => (int) $id,
                    'url'       => $link['url'],
                    'status'    => (int) $link['status'],
                    'nofollow'  => $link['nofollow'],
                    'sponsored' => $link['sponsored'],
                    'track'     => $link['track'],
                );
            }
        }
        update_option(self::MAP_OPTION, $map, true);
        return $map;
    }

    /**
     * A link's fields.
     *
     * @param int $id Post ID.
     * @return array{slug:string,url:string,status:string,nofollow:bool,sponsored:bool,track:bool,note:string,clicks:int,uniques:int}
     */
    public static function link($id) {
        $get    = function ($key) use ($id) {
            return get_post_meta($id, self::META . $key, true);
        };
        $status = (string) $get('status');
        return array(
            'slug'      => (string) $get('slug'),
            'url'       => (string) $get('url'),
            'status'    => in_array($status, self::STATUSES, true) ? $status : '302',
            'nofollow'  => (bool) $get('nofollow'),
            'sponsored' => (bool) $get('sponsored'),
            'track'     => (bool) $get('track'),
            'note'      => (string) $get('note'),
            'clicks'    => (int) $get('clicks'),
            'uniques'   => (int) $get('uniques'),
        );
    }

    /**
     * Send short link visitors on, before WordPress parses the request.
     *
     * @param bool $parse Whether WordPress should parse the request.
     * @return bool
     */
    public static function maybe_redirect($parse) {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if (!$parse || is_admin() || !in_array($method, array('GET', 'HEAD'), true)) {
            return $parse;
        }
        $slug = self::request_slug();
        if ('' === $slug) {
            return $parse;
        }
        $map = self::map();
        $key = self::key($slug);
        if (!isset($map[$key])) {
            return $parse;
        }
        $link = $map[$key];

        /**
         * Filter where a short link sends visitors. Return '' to let
         * WordPress handle the address instead.
         *
         * @param string $url Target address.
         * @param int    $id  Short link post ID.
         */
        $url = (string) apply_filters('seoprostack_short_link_target', $link['url'], $link['id']);
        if ('' === $url) {
            return $parse;
        }

        $count = false;
        $first = false;
        if ($link['track'] && 'GET' === $method && !self::is_bot()) {
            /**
             * Filter whether this click is counted.
             *
             * @param bool $count Count it.
             * @param int  $id    Short link post ID.
             */
            $count = (bool) apply_filters('seoprostack_short_link_count_click', true, $link['id']);
        }
        if ($count) {
            $cookie = 'sps_link_' . $link['id'];
            $first  = empty($_COOKIE[$cookie]);
            if ($first && !headers_sent()) {
                setcookie($cookie, '1', time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
            }
        }

        nocache_headers();
        $robots = array();
        if ($link['nofollow']) {
            $robots[] = 'noindex';
            $robots[] = 'nofollow';
        }
        if ($link['sponsored']) {
            $robots[] = 'sponsored';
        }
        if ($robots && !headers_sent()) {
            header('X-Robots-Tag: ' . implode(', ', $robots), true);
        }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- short links go to addresses their editors chose, usually on other sites.
        if (!wp_redirect($url, in_array((string) $link['status'], self::STATUSES, true) ? (int) $link['status'] : 302, 'SEO Pro Stack')) {
            return $parse;
        }
        if ($count) {
            // Count after the visitor has the redirect.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            self::count_click($link['id'], $first);
        }
        exit;
    }

    /**
     * Whether the visitor looks like a bot, link preview or script.
     *
     * @return bool
     */
    private static function is_bot() {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        return '' === $agent || (bool) preg_match('/bot\b|bot\/|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|headless|lighthouse|pingdom|uptime|monitor|scanner|validator/i', $agent);
    }

    /**
     * Add a click, and a unique visitor on their first click.
     *
     * @param int  $id    Short link post ID.
     * @param bool $first First click from this browser.
     */
    public static function count_click($id, $first) {
        global $wpdb;
        $keys = $first ? array('clicks', 'uniques') : array('clicks');
        foreach ($keys as $what) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one atomic increment, so simultaneous clicks are all counted.
            $done = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
                $id,
                self::META . $what
            ));
            if (!$done) {
                add_post_meta($id, self::META . $what, 1, true);
            }
        }
        wp_cache_delete($id, 'post_meta');
    }

    /* --------------------------------------------------------------------- */
    /* Keeping the map current                                                */
    /* --------------------------------------------------------------------- */

    /**
     * A link was published, turned off, binned or restored.
     *
     * @param string  $new  New status.
     * @param string  $old  Old status.
     * @param WP_Post $post Post.
     */
    public static function status_changed($new, $old, $post) {
        if ($post instanceof WP_Post && self::TYPE === $post->post_type && ('publish' === $new || 'publish' === $old)) {
            self::refresh_map();
        }
    }

    /**
     * A link was deleted.
     *
     * @param int $post_id Post ID.
     */
    public static function deleted($post_id) {
        if (self::TYPE === get_post_type($post_id)) {
            self::refresh_map();
        }
    }

    /**
     * Rebuild the map once, at the end of the request, however many links
     * changed (bulk actions, imports).
     */
    public static function refresh_map() {
        if (!self::$refresh) {
            self::$refresh = true;
            add_action('shutdown', array(__CLASS__, 'build_map'), 0);
        }
    }

    /**
     * Settings saved: when switched off, drop the autoloaded map.
     *
     * @param mixed $old Old settings.
     * @param mixed $new New settings.
     */
    public static function settings_saved($old, $new) {
        if (is_array($new) && empty($new[self::KEY])) {
            delete_option(self::MAP_OPTION);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Starter review links                                                   */
    /* --------------------------------------------------------------------- */

    /**
     * Review links every site gets once: address, placeholder target, name
     * and where to find the real target.
     *
     * @return array<string,array{slug:string,url:string,title:string,advice:string}>
     */
    public static function presets() {
        return array(
            'google'     => array(
                'slug'   => 'googlereview',
                'url'    => 'https://google.com',
                'title'  => __('Google review', 'seoprostack'),
                'advice' => __('Replace this with the link to your Google Business Profile’s review form. In your profile, choose Ask for reviews and copy the link.', 'seoprostack'),
            ),
            'facebook'   => array(
                'slug'   => 'facebookreview',
                'url'    => 'https://facebook.com',
                'title'  => __('Facebook review', 'seoprostack'),
                'advice' => __('Replace this with the link to your Facebook page’s reviews, such as https://www.facebook.com/yourpage/reviews.', 'seoprostack'),
            ),
            'trustpilot' => array(
                'slug'   => 'trustpilotreview',
                'url'    => 'https://trustpilot.com',
                'title'  => __('Trustpilot review', 'seoprostack'),
                'advice' => __('Replace this with the link to your Trustpilot review form, such as https://www.trustpilot.com/evaluate/example.com.', 'seoprostack'),
            ),
        );
    }

    /**
     * Add the starter review links, in the Review Requests category, the
     * first time someone who can add links opens the admin with the feature
     * on. Deleted ones do not come back. Sites that got the links before the
     * category have them filed there once, unless they were given a category.
     */
    public static function maybe_add_presets() {
        $done = (int) get_option(self::PRESETS_OPTION);
        if ($done >= self::PRESETS_VERSION || wp_doing_ajax()) {
            return;
        }
        $type = get_post_type_object(self::TYPE);
        if (!$type || !current_user_can($type->cap->create_posts)) {
            return;
        }
        // Set first, so two admin requests at once do not both add them.
        update_option(self::PRESETS_OPTION, self::PRESETS_VERSION, false);
        if ($done < 1) {
            self::add_presets();
        }
        self::categorise_presets();
    }

    /**
     * Create the starter review links whose addresses are free.
     */
    private static function add_presets() {
        // Addresses Pretty Links has and that are still to be imported.
        $prli = array();
        foreach (self::pretty_links_rows() as $row) {
            $prli[self::key(self::clean_slug((string) $row['slug']))] = true;
        }
        $added = 0;
        foreach (self::presets() as $preset => $link) {
            $slug = $link['slug'];
            if (isset($prli[self::key($slug)]) || self::slug_owner($slug) || self::page_at($slug)) {
                continue;
            }
            $post_id = wp_insert_post(wp_slash(array(
                'post_type'   => self::TYPE,
                'post_status' => 'publish',
                'post_title'  => $link['title'],
                'post_author' => get_current_user_id(),
                'meta_input'  => array(
                    self::META . 'slug'      => $slug,
                    self::META . 'url'       => $link['url'],
                    self::META . 'status'    => '302',
                    self::META . 'nofollow'  => SEOProStack_Settings::get('short_links_nofollow') ? 1 : 0,
                    self::META . 'sponsored' => 0,
                    self::META . 'track'     => SEOProStack_Settings::get('short_links_track') ? 1 : 0,
                    self::META . 'clicks'    => 0,
                    self::META . 'uniques'   => 0,
                    self::META . 'preset'    => $preset,
                ),
            )), true);
            if (!is_wp_error($post_id) && $post_id) {
                $added++;
            }
        }
        if ($added) {
            self::build_map();
        }
    }

    /**
     * Put starter review links that have no category in Review Requests,
     * using the category of that name if the site already has one.
     */
    private static function categorise_presets() {
        $ids = get_posts(array(
            'post_type'   => self::TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => self::META . 'preset', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- once per site.
        ));
        $term_id = 0;
        foreach ($ids as $id) {
            $terms = wp_get_object_terms($id, self::TAX, array('fields' => 'ids'));
            if (is_wp_error($terms) || $terms) {
                continue;
            }
            if (!$term_id) {
                $name = __('Review Requests', 'seoprostack');
                $term = term_exists($name, self::TAX);
                if (!$term) {
                    $term = wp_insert_term($name, self::TAX);
                }
                if (is_wp_error($term) || !is_array($term)) {
                    return;
                }
                $term_id = (int) $term['term_id'];
            }
            wp_set_object_terms($id, array($term_id), self::TAX);
        }
    }

    /**
     * Help under "Goes to" for a starter review link.
     *
     * @param int    $post_id Post ID.
     * @param string $url     Current target.
     * @return string Empty for other links.
     */
    private static function preset_advice($post_id, $url) {
        $presets = self::presets();
        $preset  = (string) get_post_meta($post_id, self::META . 'preset', true);
        if (!isset($presets[$preset])) {
            return '';
        }
        $share = __('Use this short link in your email signature and when you ask happy customers for a review.', 'seoprostack');
        $same  = untrailingslashit(strtolower(trim($url))) === $presets[$preset]['url'];
        return $same ? $presets[$preset]['advice'] . ' ' . $share : $share;
    }

    /* --------------------------------------------------------------------- */
    /* Edit screen                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Meta box.
     */
    public static function add_box() {
        add_meta_box('seoprostack-short-link', __('Link', 'seoprostack'), array(__CLASS__, 'render_box'), self::TYPE, 'normal', 'high');
    }

    /**
     * An unused address for a new link: the prefix and four characters.
     *
     * @return string
     */
    private static function new_slug() {
        $prefix = self::clean_slug((string) SEOProStack_Settings::get('short_links_prefix'));
        $prefix = '' === $prefix ? '' : $prefix . '/';
        for ($i = 0; $i < 20; $i++) {
            $slug = $prefix . strtolower(wp_generate_password($i < 10 ? 4 : 6, false));
            if (!self::slug_owner($slug)) {
                return $slug;
            }
        }
        return $prefix . strtolower(wp_generate_password(10, false));
    }

    /**
     * Another short link that has the address, in any status but the bin.
     *
     * @param string $slug    Clean address.
     * @param int    $exclude Post ID to ignore.
     * @return int Post ID, or 0.
     */
    private static function slug_owner($slug, $exclude = 0) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- only when a link is saved.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') AND p.ID <> %d AND LOWER(m.meta_value) = %s LIMIT 1",
            self::META . 'slug',
            self::TYPE,
            (int) $exclude,
            self::key($slug)
        ));
    }

    /**
     * Link fields.
     *
     * @param WP_Post $post Post.
     */
    public static function render_box($post) {
        $link = self::link($post->ID);
        $new  = '' === $link['slug'] && 'auto-draft' === $post->post_status;
        if ($new) {
            $link['slug']      = self::new_slug();
            $link['status']    = (string) SEOProStack_Settings::get('short_links_redirect');
            $link['nofollow']  = (bool) SEOProStack_Settings::get('short_links_nofollow');
            $link['sponsored'] = (bool) SEOProStack_Settings::get('short_links_sponsored');
            $link['track']     = (bool) SEOProStack_Settings::get('short_links_track');
        }
        $advice = $new ? '' : self::preset_advice($post->ID, $link['url']);
        wp_nonce_field('seoprostack_short_link_' . $post->ID, '_seoprostack_short_link');
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="sps-link-slug"><?php esc_html_e('Short address', 'seoprostack'); ?></label></th>
                <td>
                    <code><?php echo esc_html(trailingslashit(home_url())); ?></code><input type="text" id="sps-link-slug" name="sps_link[slug]" class="regular-text code" value="<?php echo esc_attr($link['slug']); ?>" required spellcheck="false" autocomplete="off" />
                    <?php if (!$new && 'publish' === $post->post_status && '' !== $link['slug']) : ?>
                        <p><a href="<?php echo esc_url(self::url($link['slug'])); ?>" target="_blank" rel="noopener"><?php echo esc_html(self::url($link['slug'])); ?></a></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sps-link-url"><?php esc_html_e('Goes to', 'seoprostack'); ?></label></th>
                <td>
                    <input type="url" id="sps-link-url" name="sps_link[url]" class="large-text code" value="<?php echo esc_attr($link['url']); ?>" placeholder="https://" required<?php echo '' !== $advice ? ' aria-describedby="sps-link-url-help"' : ''; ?> />
                    <?php if ('' !== $advice) : ?>
                        <p class="description" id="sps-link-url-help"><?php echo esc_html($advice); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sps-link-status"><?php esc_html_e('Redirect', 'seoprostack'); ?></label></th>
                <td>
                    <select id="sps-link-status" name="sps_link[status]">
                        <?php foreach (self::status_options() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($link['status'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Options', 'seoprostack'); ?></th>
                <td>
                    <fieldset>
                        <label><input type="checkbox" name="sps_link[nofollow]" value="1" <?php checked($link['nofollow']); ?> /> <?php esc_html_e('Ask search engines not to follow it (nofollow)', 'seoprostack'); ?></label><br />
                        <label><input type="checkbox" name="sps_link[sponsored]" value="1" <?php checked($link['sponsored']); ?> /> <?php esc_html_e('Paid or affiliate link (sponsored)', 'seoprostack'); ?></label><br />
                        <label><input type="checkbox" name="sps_link[track]" value="1" <?php checked($link['track']); ?> /> <?php esc_html_e('Count clicks', 'seoprostack'); ?></label>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sps-link-note"><?php esc_html_e('Note', 'seoprostack'); ?></label></th>
                <td><textarea id="sps-link-note" name="sps_link[note]" class="large-text" rows="2"><?php echo esc_textarea($link['note']); ?></textarea></td>
            </tr>
            <?php if (!$new) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Clicks', 'seoprostack'); ?></th>
                    <td><?php echo esc_html(self::clicks_text($link)); ?></td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * "12 (10 unique visitors)".
     *
     * @param array $link Link fields.
     * @return string
     */
    private static function clicks_text(array $link) {
        return sprintf(
            /* translators: 1: clicks, 2: unique visitors */
            _n('%1$s (%2$s unique visitor)', '%1$s (%2$s unique visitors)', $link['uniques'], 'seoprostack'),
            number_format_i18n($link['clicks']),
            number_format_i18n($link['uniques'])
        );
    }

    /**
     * Save the link fields.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function save($post_id, $post) {
        if (!isset($_POST['_seoprostack_short_link'], $_POST['sps_link']) || wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        check_admin_referer('seoprostack_short_link_' . $post_id, '_seoprostack_short_link');
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $data   = (array) wp_unslash($_POST['sps_link']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised below.
        $old    = self::link($post_id);
        $notice = '';

        $slug = self::clean_slug(isset($data['slug']) ? (string) $data['slug'] : '');
        if ('' === $slug) {
            $slug   = '' !== $old['slug'] ? $old['slug'] : self::new_slug();
            $notice = 'empty';
        } elseif (self::reserved($slug)) {
            $notice = 'reserved';
            $slug   = '' !== $old['slug'] ? $old['slug'] : self::new_slug();
        } elseif (self::slug_owner($slug, $post_id)) {
            $notice = 'taken';
            $base   = $slug;
            for ($i = 2; self::slug_owner($slug, $post_id); $i++) {
                $slug = $base . '-' . $i;
            }
        }
        $url = isset($data['url']) ? esc_url_raw(trim((string) $data['url'])) : '';
        if ('' === $url && '' === $notice) {
            $notice = 'nourl';
        }
        $status = isset($data['status']) && in_array((string) $data['status'], self::STATUSES, true) ? (string) $data['status'] : '302';

        update_post_meta($post_id, self::META . 'slug', $slug);
        update_post_meta($post_id, self::META . 'url', $url);
        update_post_meta($post_id, self::META . 'status', $status);
        foreach (array('nofollow', 'sponsored', 'track') as $flag) {
            update_post_meta($post_id, self::META . $flag, empty($data[$flag]) ? 0 : 1);
        }
        $note = isset($data['note']) ? sanitize_textarea_field((string) $data['note']) : '';
        if ('' === $note) {
            delete_post_meta($post_id, self::META . 'note');
        } else {
            update_post_meta($post_id, self::META . 'note', $note);
        }
        // Sorting by clicks needs the key on every link.
        add_post_meta($post_id, self::META . 'clicks', 0, true);
        add_post_meta($post_id, self::META . 'uniques', 0, true);

        if ('' === trim($post->post_title)) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- wp_update_post() here would save the link again.
            $wpdb->update($wpdb->posts, array('post_title' => $slug), array('ID' => $post_id));
            clean_post_cache($post_id);
        }
        if ('' === $notice && 'publish' === $post->post_status && self::page_at($slug)) {
            $notice = 'page';
        }
        if ('' !== $notice) {
            self::$notice = $notice;
        }
        self::refresh_map();
    }

    /**
     * Whether WordPress has content at an address.
     *
     * @param string $slug Clean address.
     * @return bool
     */
    private static function page_at($slug) {
        return (bool) url_to_postid(self::url($slug));
    }

    /**
     * Carry the notice to the edit screen.
     *
     * @param string $location Redirect address.
     * @param int    $post_id  Post ID.
     * @return string
     */
    public static function redirect_location($location, $post_id) {
        if ('' !== self::$notice && self::TYPE === get_post_type($post_id)) {
            $location = add_query_arg('sps_link_notice', self::$notice, $location);
        }
        return $location;
    }

    /**
     * Title field placeholder.
     *
     * @param string  $text Placeholder.
     * @param WP_Post $post Post.
     * @return string
     */
    public static function title_placeholder($text, $post) {
        return self::TYPE === $post->post_type ? __('Name (only shown in the admin)', 'seoprostack') : $text;
    }

    /**
     * Messages after saving.
     *
     * @param array $messages Messages by post type.
     * @return array
     */
    public static function messages($messages) {
        $saved                = __('Short link saved.', 'seoprostack');
        $messages[self::TYPE] = array(
            0  => '',
            1  => __('Short link updated.', 'seoprostack'),
            4  => __('Short link updated.', 'seoprostack'),
            6  => $saved,
            7  => $saved,
            8  => $saved,
            10 => __('Short link saved as a draft; it does not work until it is published.', 'seoprostack'),
        );
        return $messages;
    }

    /**
     * Messages after bulk actions.
     *
     * @param array $messages Messages by post type.
     * @param array $counts   Counts.
     * @return array
     */
    public static function bulk_messages($messages, $counts) {
        $messages[self::TYPE] = array(
            /* translators: %s: number of links */
            'updated'   => _n('%s short link updated.', '%s short links updated.', $counts['updated'], 'seoprostack'),
            /* translators: %s: number of links */
            'locked'    => _n('%s short link not updated, somebody is editing it.', '%s short links not updated, somebody is editing them.', $counts['locked'], 'seoprostack'),
            /* translators: %s: number of links */
            'deleted'   => _n('%s short link permanently deleted.', '%s short links permanently deleted.', $counts['deleted'], 'seoprostack'),
            /* translators: %s: number of links */
            'trashed'   => _n('%s short link moved to the bin.', '%s short links moved to the bin.', $counts['trashed'], 'seoprostack'),
            /* translators: %s: number of links */
            'untrashed' => _n('%s short link restored from the bin.', '%s short links restored from the bin.', $counts['untrashed'], 'seoprostack'),
        );
        return $messages;
    }

    /* --------------------------------------------------------------------- */
    /* List screen                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Columns.
     *
     * @param array $columns Columns.
     * @return array
     */
    public static function columns($columns) {
        $out = array();
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ('title' === $key) {
                $out['sps_link']   = __('Short address', 'seoprostack');
                $out['sps_target'] = __('Goes to', 'seoprostack');
                $out['sps_clicks'] = __('Clicks', 'seoprostack');
            }
        }
        return $out;
    }

    /**
     * Column content.
     *
     * @param string $column  Column.
     * @param int    $post_id Post ID.
     */
    public static function column($column, $post_id) {
        $link = self::link($post_id);
        switch ($column) {
            case 'sps_link':
                if ('' !== $link['slug']) {
                    printf('<a href="%1$s" target="_blank" rel="noopener"><code>/%2$s</code></a>', esc_url(self::url($link['slug'])), esc_html($link['slug']));
                }
                break;
            case 'sps_target':
                if ('' !== $link['url']) {
                    printf('<code>%1$s</code> <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>', esc_html($link['status']), esc_url($link['url']), esc_html(wp_html_excerpt($link['url'], 60, '…')));
                }
                break;
            case 'sps_clicks':
                echo esc_html($link['track'] || $link['clicks'] ? self::clicks_text($link) : __('Not counted', 'seoprostack'));
                break;
        }
    }

    /**
     * Sortable columns.
     *
     * @param array $columns Columns.
     * @return array
     */
    public static function sortable($columns) {
        $columns['sps_clicks'] = array('sps_clicks', true);
        return $columns;
    }

    /**
     * Sort by clicks, and let search find addresses.
     *
     * @param WP_Query $query Query.
     */
    public static function list_query($query) {
        if (!$query->is_main_query() || self::TYPE !== $query->get('post_type')) {
            return;
        }
        if ('sps_clicks' === $query->get('orderby')) {
            $query->set('meta_key', self::META . 'clicks'); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- sorting the admin list.
            $query->set('orderby', 'meta_value_num');
        }
        $search = (string) $query->get('s');
        if ('' !== $search) {
            add_filter('posts_search', array(__CLASS__, 'search_addresses'), 10, 2);
        }
    }

    /**
     * Search the address and target too, not only the name.
     *
     * @param string   $where Search SQL.
     * @param WP_Query $query Query.
     * @return string
     */
    public static function search_addresses($where, $query) {
        remove_filter('posts_search', array(__CLASS__, 'search_addresses'), 10);
        if ('' === $where || !$query->is_main_query()) {
            return $where;
        }
        global $wpdb;
        $like = '%' . $wpdb->esc_like((string) $query->get('s')) . '%';
        $or   = $wpdb->prepare(
            "{$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value LIKE %s)",
            self::META . 'slug',
            self::META . 'url',
            $like
        );
        // " AND ((...title/content...))" becomes " AND ((...) OR ID IN (...))".
        return (string) preg_replace_callback('/^\s*AND\s*\((.*)\)\s*$/s', function ($m) use ($or) {
            return ' AND ((' . $m[1] . ') OR ' . $or . ')';
        }, $where);
    }

    /**
     * Notices on the link screens.
     */
    public static function notices() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || self::TYPE !== $screen->post_type) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
        if (isset($_GET['sps_link_notice'])) {
            $messages = array(
                'empty'    => __('A short link needs an address, so the old or a new one was kept.', 'seoprostack'),
                'reserved' => __('That address belongs to WordPress, so the old or a new one was kept.', 'seoprostack'),
                'taken'    => __('Another short link has that address, so a number was added to this one.', 'seoprostack'),
                'nourl'    => __('Add the address this link goes to; until then it does nothing.', 'seoprostack'),
                'page'     => __('A page or post has the same address. Visitors to it now go where this link goes.', 'seoprostack'),
            );
            $code = sanitize_key(wp_unslash($_GET['sps_link_notice']));
            if (isset($messages[$code])) {
                printf('<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html($messages[$code]));
            }
        }
        if (isset($_GET['sps_imported'])) {
            $added   = absint(wp_unslash($_GET['sps_imported']));
            $skipped = isset($_GET['sps_skipped']) ? absint(wp_unslash($_GET['sps_skipped'])) : 0;
            $left    = isset($_GET['sps_left']) ? absint(wp_unslash($_GET['sps_left'])) : 0;
            $text    = sprintf(
                /* translators: %s: number of links */
                _n('%s link imported from Pretty Links.', '%s links imported from Pretty Links.', $added, 'seoprostack'),
                number_format_i18n($added)
            );
            if ($skipped) {
                $text .= ' ' . sprintf(
                    /* translators: %s: number of links */
                    _n('%s was left out because a short link already has its address.', '%s were left out because short links already have their addresses.', $skipped, 'seoprostack'),
                    number_format_i18n($skipped)
                );
            }
            if ($left) {
                $text .= ' ' . sprintf(
                    /* translators: %s: number of links */
                    _n('%s is left; import again to continue.', '%s are left; import again to continue.', $left, 'seoprostack'),
                    number_format_i18n($left)
                );
            }
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
        }
        // phpcs:enable
    }

    /* --------------------------------------------------------------------- */
    /* Pretty Links import                                                    */
    /* --------------------------------------------------------------------- */

    /**
     * Pretty Links' links that are not deleted, as id => row, or empty when
     * its table is missing.
     *
     * @param bool $full All columns, not only id and slug.
     * @return array<int,array>
     */
    private static function pretty_links_rows($full = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, read only.
        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, read only.
        $columns = $wpdb->get_col($wpdb->prepare('DESCRIBE %i', $table), 0);
        // Pretty Links 3 had no deleted_at.
        $deleted = in_array('deleted_at', $columns, true);
        if ($full) {
            $sql = $deleted
                ? $wpdb->prepare('SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY id', $table)
                : $wpdb->prepare('SELECT * FROM %i ORDER BY id', $table);
        } else {
            $sql = $deleted
                ? $wpdb->prepare('SELECT id, slug FROM %i WHERE deleted_at IS NULL ORDER BY id', $table)
                : $wpdb->prepare('SELECT id, slug FROM %i ORDER BY id', $table);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out  = array();
        foreach ((array) $rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * Pretty Links IDs already imported (in any status, also the bin, so
     * deleted links stay deleted) and the addresses in use.
     *
     * @return array{0:array<int,true>,1:array<string,true>}
     */
    private static function imported() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- importing only.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND p.post_status <> 'auto-draft' AND m.meta_key IN (%s, %s)",
            self::TYPE,
            self::META . 'prli',
            self::META . 'slug'
        ));
        $ids   = array();
        $slugs = array();
        foreach ((array) $rows as $row) {
            if (self::META . 'prli' === $row->meta_key) {
                $ids[(int) $row->meta_value] = true;
            } else {
                $slugs[self::key((string) $row->meta_value)] = true;
            }
        }
        return array($ids, $slugs);
    }

    /**
     * Pretty Links' links still to import.
     *
     * @return int
     */
    public static function pending() {
        $rows = self::pretty_links_rows();
        if (!$rows) {
            return 0;
        }
        list($ids, $slugs) = self::imported();
        $count = 0;
        foreach ($rows as $id => $row) {
            $slug = self::clean_slug((string) $row['slug']);
            if (!isset($ids[$id]) && '' !== $slug && !isset($slugs[self::key($slug)]) && !self::reserved($slug)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Copy Pretty Links' links: address, target, redirect, nofollow,
     * sponsored, click counting, name, description, date, on or off, click
     * and unique visitor counts, and categories. Links already imported, or
     * whose address a short link has, are left out. Pretty Links' data is
     * only read.
     *
     * @param int $budget Seconds to run; 0 for no limit.
     * @return array{added:int,skipped:int,left:int}
     */
    public static function import_pretty_links($budget = 0) {
        global $wpdb;
        $counts = array('added' => 0, 'skipped' => 0, 'left' => 0);
        $rows   = self::pretty_links_rows(true);
        if (!$rows) {
            return $counts;
        }
        list($ids, $slugs) = self::imported();

        // Categories and dates are on Pretty Links' own post type, whose IDs
        // the links keep in link_cpt_id.
        $cpt = array();
        foreach ($rows as $row) {
            if (!empty($row['link_cpt_id'])) {
                $cpt[] = (int) $row['link_cpt_id'];
            }
        }
        $terms = array();
        $dates = array();
        foreach (array_chunk($cpt, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list; Pretty Links' taxonomy may not be registered.
            foreach ((array) $wpdb->get_results("SELECT tr.object_id, t.name FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'pretty-link-category' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN ({$in})") as $term) {
                $terms[(int) $term->object_id][] = $term->name;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
            foreach ((array) $wpdb->get_results("SELECT ID, post_date, post_date_gmt FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'pretty-link'") as $post) {
                $dates[(int) $post->ID] = $post;
            }
        }

        $start = microtime(true);
        wp_defer_term_counting(true);
        foreach ($rows as $id => $row) {
            if (isset($ids[$id])) {
                continue;
            }
            $slug = self::clean_slug((string) $row['slug']);
            if ('' === $slug || isset($slugs[self::key($slug)]) || self::reserved($slug)) {
                $counts['skipped']++;
                continue;
            }
            if ($budget && microtime(true) - $start > $budget) {
                $counts['left']++;
                continue;
            }
            $url    = esc_url_raw(trim((string) $row['url']));
            $status = isset($row['redirect_type']) && in_array((string) $row['redirect_type'], self::STATUSES, true) ? (string) $row['redirect_type'] : '302';
            $on     = '' !== $url && (!isset($row['link_status']) || 'disabled' !== $row['link_status']);
            $post   = array(
                'post_type'   => self::TYPE,
                'post_status' => $on ? 'publish' : 'draft',
                'post_title'  => '' !== trim((string) $row['name']) ? sanitize_text_field((string) $row['name']) : $slug,
                'post_author' => get_current_user_id(),
                'meta_input'  => array(
                    self::META . 'slug'      => $slug,
                    self::META . 'url'       => $url,
                    self::META . 'status'    => $status,
                    self::META . 'nofollow'  => empty($row['nofollow']) ? 0 : 1,
                    self::META . 'sponsored' => empty($row['sponsored']) ? 0 : 1,
                    self::META . 'track'     => isset($row['track_me']) && empty($row['track_me']) ? 0 : 1,
                    self::META . 'clicks'    => isset($row['clicks']) ? max(0, (int) $row['clicks']) : 0,
                    self::META . 'uniques'   => isset($row['uniques']) ? max(0, (int) $row['uniques']) : 0,
                    self::META . 'prli'      => $id,
                ),
            );
            $note = isset($row['description']) ? sanitize_textarea_field((string) $row['description']) : '';
            if ('' !== $note) {
                $post['meta_input'][self::META . 'note'] = $note;
            }
            $cpt_id = isset($row['link_cpt_id']) ? (int) $row['link_cpt_id'] : 0;
            if ($cpt_id && isset($dates[$cpt_id]) && '0000-00-00 00:00:00' !== $dates[$cpt_id]->post_date_gmt) {
                $post['post_date']     = $dates[$cpt_id]->post_date;
                $post['post_date_gmt'] = $dates[$cpt_id]->post_date_gmt;
            } elseif (!empty($row['created_at']) && '0000-00-00 00:00:00' !== $row['created_at']) {
                $post['post_date_gmt'] = (string) $row['created_at'];
                $post['post_date']     = get_date_from_gmt((string) $row['created_at']);
            }
            $post_id = wp_insert_post(wp_slash($post), true);
            if (is_wp_error($post_id) || !$post_id) {
                $counts['skipped']++;
                continue;
            }
            if ($cpt_id && !empty($terms[$cpt_id])) {
                wp_set_object_terms($post_id, array_values(array_unique($terms[$cpt_id])), self::TAX);
            }
            $slugs[self::key($slug)] = true;
            $counts['added']++;
        }
        wp_defer_term_counting(false);
        if ($counts['added']) {
            self::build_map();
        }
        return $counts;
    }

    /**
     * admin-post.php?action=seoprostack_import_pretty_links
     */
    public static function handle_import() {
        check_admin_referer(self::IMPORT);
        $type = get_post_type_object(self::TYPE);
        if (!$type || !current_user_can($type->cap->create_posts) || !current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to import links.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
        $counts = self::import_pretty_links(self::IMPORT_BUDGET);
        wp_safe_redirect(add_query_arg(
            array(
                'sps_imported' => $counts['added'],
                'sps_skipped'  => $counts['skipped'],
                'sps_left'     => $counts['left'],
            ),
            admin_url('edit.php?post_type=' . self::TYPE)
        ));
        exit;
    }

    /**
     * Import when Pretty Links is deactivated, so its links keep working.
     *
     * @param string $plugin Plugin file.
     */
    public static function plugin_deactivated($plugin) {
        // Whoever could deactivate it (or WP-CLI) may import its links.
        if (self::PRLI_FILE === $plugin) {
            self::import_pretty_links();
        }
    }

    /**
     * Settings panel: links, and Pretty Links' links still to import.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field) {
        if (self::KEY !== $key) {
            return;
        }
        $pending = self::pending();
        if (!self::switched_on()) {
            if ($pending) {
                printf(
                    '<div class="sps-panel-note"><p>%s</p></div>',
                    esc_html(sprintf(
                        /* translators: %s: number of links */
                        _n('Pretty Links has %s link. Switch this on to import it.', 'Pretty Links has %s links. Switch this on to import them.', $pending, 'seoprostack'),
                        number_format_i18n($pending)
                    ))
                );
            }
            return;
        }
        $count = wp_count_posts(self::TYPE);
        $live  = isset($count->publish) ? (int) $count->publish : 0;
        echo '<div class="sps-panel-note">';
        printf(
            '<p>%1$s <a href="%2$s">%3$s</a></p>',
            esc_html(sprintf(
                /* translators: %s: number of links */
                _n('%s short link is live.', '%s short links are live.', $live, 'seoprostack'),
                number_format_i18n($live)
            )),
            esc_url(admin_url('edit.php?post_type=' . self::TYPE)),
            esc_html__('Manage short links', 'seoprostack')
        );
        if ($pending && current_user_can('manage_options')) {
            $active = isset(self::active_plugins()['pretty-link']);
            printf(
                '<form method="post" action="%1$s"><p>%2$s %3$s</p><input type="hidden" name="action" value="%4$s" />',
                esc_url(admin_url('admin-post.php')),
                esc_html(sprintf(
                    /* translators: %s: number of links */
                    _n('Pretty Links has %s link that is not here yet.', 'Pretty Links has %s links that are not here yet.', $pending, 'seoprostack'),
                    number_format_i18n($pending)
                )),
                $active ? esc_html__('They are also imported when Pretty Links is deactivated, and work here from then on.', 'seoprostack') : '',
                esc_attr(self::IMPORT)
            );
            wp_nonce_field(self::IMPORT);
            printf('<p><button type="submit" class="button">%s</button></p></form>', esc_html__('Import links', 'seoprostack'));
        }
        echo '</div>';
    }

    /**
     * Import Pretty Links' links.
     *
     * Links keep their address, target, redirect, nofollow, sponsored,
     * click counting, click and unique visitor counts, name, description,
     * date and categories. Links already imported, or whose address a short
     * link has, are left out, so running it again is safe. Pretty Links'
     * data is only read.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack short-links import
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Options.
     */
    public static function cli_import($args, $assoc) {
        if (!self::switched_on()) {
            WP_CLI::error('Short links is off. Switch it on under Settings → SEO Pro Stack → Links first.');
        }
        $counts = self::import_pretty_links();
        WP_CLI::success(sprintf('%d imported, %d left out because their address is in use.', $counts['added'], $counts['skipped']));
    }
}
