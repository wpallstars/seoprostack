<?php
/**
 * Short addresses for custom post types.
 *
 * Serves items of chosen post types at /item/ instead of /type/item/, the
 * way pages are served:
 * - links everywhere (menus, sitemaps, the editor) drop the post type's base,
 *   because WordPress's own post_type_link filter builds them;
 * - a short address is only used for an item when WordPress finds nothing
 *   else there, so pages, posts and categories keep their addresses;
 * - old addresses with the base still work and redirect (301) to the short
 *   one, so no rewrite rules change and nothing needs flushing.
 *
 * Replaces "Remove CPT base" and imports its post types.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Remove_Cpt_Base extends SEOProStack_Feature {

    const KEY = 'remove_cpt_base';

    /**
     * Option: names of top-level items whose short address a published page
     * or post already has (types => [], names => []). Autoloaded and small,
     * so links need no extra query; cleared when posts change.
     */
    const TAKEN_OPTION = 'seoprostack_cpt_base_taken';

    /** Query variables that say what WordPress matched at an address. */
    const MATCHED = array('pagename', 'name', 'attachment', 'category_name', 'error', 'page');

    /**
     * Post type of the item this request was resolved to by its short
     * address, if any.
     *
     * @var string
     */
    private static $resolved = '';

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
                'label'       => __('Short addresses for custom post types', 'seoprostack'),
                'description' => __('Serve items of the post types you choose at /item-name/ instead of /type/item-name/, like pages. Old addresses redirect to the new ones. Pages and posts keep their address when an item has the same name.', 'seoprostack'),
                'replaces'    => array('remove-cpt-base' => 'Remove CPT base'),
            ),
            'remove_cpt_base_types' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Post types', 'seoprostack'),
                'description' => __('Post types whose address has a base, such as /product/.', 'seoprostack'),
                'options'     => array(__CLASS__, 'type_options'),
            ),
        );
    }

    /**
     * Post types that can lose their base: public, with pretty addresses
     * and a fixed base (no tags such as %product_cat%).
     *
     * @return array<string,string> name => label (/base/)
     */
    public static function type_options() {
        $options = array();
        foreach (get_post_types(array('public' => true, '_builtin' => false), 'objects') as $type) {
            $prefix = self::prefix($type->name);
            if (null !== $prefix) {
                /* translators: 1: post type name, 2: address base such as /product/ */
                $options[$type->name] = sprintf(__('%1$s (%2$s)', 'seoprostack'), $type->labels->name, '/' . $prefix . '/');
            }
        }
        return $options;
    }

    /**
     * Import Remove CPT base's post types, and switch on while it is active
     * with some chosen.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $selected = get_option('rcptb_selected', null);
        if (!is_array($selected) || !$selected) {
            return $options;
        }
        $types   = array_values(array_filter(array_map('strval', array_keys($selected))));
        $options = self::import_setting($options, 'remove_cpt_base_types', $types);
        if (isset(self::active_plugins()['remove-cpt-base'])) {
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
        if (!self::enabled()) {
            // Not kept while off: posts may change meanwhile.
            if (false !== get_option(self::TAKEN_OPTION)) {
                delete_option(self::TAKEN_OPTION);
            }
            return;
        }
        foreach (array('save_post', 'deleted_post', 'trashed_post', 'untrashed_post') as $hook) {
            add_action($hook, array(__CLASS__, 'forget_taken'));
        }
        add_filter('post_type_link', array(__CLASS__, 'short_link'), 10, 2);
        add_filter('request', array(__CLASS__, 'resolve'));
        // Before redirect_canonical() and the 410 Gone check.
        add_action('template_redirect', array(__CLASS__, 'redirect_old'), 0);
    }

    /**
     * Chosen post types that can lose their base right now.
     *
     * @return string[]
     */
    public static function types() {
        if ('' === (string) get_option('permalink_structure')) {
            return array();
        }
        $types = array();
        foreach ((array) SEOProStack_Settings::get('remove_cpt_base_types') as $type) {
            if (post_type_exists($type) && null !== self::prefix($type)) {
                $types[] = (string) $type;
            }
        }
        return $types;
    }

    /**
     * A post type's address base, from its rewrite structure: "product" for
     * /product/%product%, "blog/product" with the posts' "blog" front.
     *
     * @param string $type Post type.
     * @return string|null Null when it has no fixed base.
     */
    public static function prefix($type) {
        global $wp_rewrite;
        $object = get_post_type_object($type);
        if (!$object || empty($object->rewrite) || !$wp_rewrite instanceof WP_Rewrite) {
            return null;
        }
        $struct = $wp_rewrite->get_extra_permastruct($type);
        $tag    = '%' . $type . '%';
        $at     = is_string($struct) ? strpos($struct, $tag) : false;
        if (false === $at) {
            return null;
        }
        $prefix = trim(substr($struct, 0, $at), '/');
        return '' === $prefix || false !== strpos($prefix, '%') ? null : $prefix;
    }

    /**
     * Drop the base from an item's link.
     *
     * @param string  $link Link.
     * @param WP_Post $post Item.
     * @return string
     */
    public static function short_link($link, $post) {
        if (!$post instanceof WP_Post || !in_array($post->post_type, self::types(), true) || self::is_taken($post)) {
            return $link;
        }
        $base = home_url('/' . self::prefix($post->post_type) . '/');
        return 0 === strpos($link, $base) ? home_url('/' . substr($link, strlen($base))) : $link;
    }

    /**
     * When WordPress finds nothing at an address, look for an item of a
     * chosen post type there.
     *
     * @param array $query_vars Query variables WordPress matched.
     * @return array
     */
    public static function resolve($query_vars) {
        if (!empty($query_vars['post_type']) || !empty($query_vars['p']) || !empty($query_vars['page_id'])) {
            return $query_vars;
        }
        $types = self::types();
        if (!$types) {
            return $query_vars;
        }

        // Pages and posts keep their addresses. Types are passed as arrays:
        // with a string, get_page_by_path() also matches media attachments,
        // which often share a name with an item (product-x.jpg, product-x).
        $page = isset($query_vars['page']) ? (string) $query_vars['page'] : '';
        if (!empty($query_vars['pagename'])) {
            if (get_page_by_path($query_vars['pagename'], OBJECT, array('page'))) {
                return $query_vars;
            }
            $path = $query_vars['pagename'];
        } elseif (!empty($query_vars['name'])) {
            if (get_page_by_path($query_vars['name'], OBJECT, array('post'))) {
                return $query_vars;
            }
            $path = $query_vars['name'];
        } elseif (!empty($query_vars['category_name'])) {
            if (get_category_by_path($query_vars['category_name'])) {
                return $query_vars;
            }
            $path = self::requested_path();
        } elseif (!empty($query_vars['attachment']) || (isset($query_vars['error']) && '404' === (string) $query_vars['error'])) {
            $path = self::requested_path();
        } else {
            return $query_vars;
        }

        $item = '' === $path ? null : get_page_by_path($path, OBJECT, $types);
        if (!$item && preg_match('#^(.+)/([0-9]+)$#', $path, $parts)) {
            // A page of an item split with <!--nextpage-->.
            $item = get_page_by_path($parts[1], OBJECT, $types);
            $path = $parts[1];
            $page = $parts[2];
        }
        if (!$item) {
            return $query_vars;
        }
        $object = get_post_type_object($item->post_type);
        if (!$object || !$object->query_var) {
            return $query_vars;
        }
        self::$resolved = $item->post_type;
        // Keep what else was asked for, such as a feed, embed or comment page.
        $resolved                       = array_diff_key($query_vars, array_flip(self::MATCHED));
        $resolved['post_type']          = $item->post_type;
        $resolved[$object->query_var]   = $path;
        $resolved['name']               = $path;
        if ('' !== $page) {
            $resolved['page'] = $page;
        }
        return $resolved;
    }

    /**
     * Path requested, without the site's folder, slashes at the ends, or a
     * feed, embed, trackback or comment page ending.
     *
     * @return string
     */
    private static function requested_path() {
        global $wp;
        $path = $wp instanceof WP ? trim(rawurldecode((string) $wp->request), '/') : '';
        return (string) preg_replace('#/(?:(?:feed/)?(?:feed|rdf|rss|rss2|atom)|embed|trackback|comment-page-[0-9]+)$#', '', $path);
    }

    /**
     * Whether a published page or post already has an item's short address,
     * so the item keeps its old one.
     *
     * @param WP_Post $post Item.
     * @return bool
     */
    private static function is_taken(WP_Post $post) {
        if ($post->post_parent) {
            return false;
        }
        $taken = self::taken();
        return isset($taken[$post->post_type][$post->post_name]);
    }

    /**
     * Names of top-level published items of the chosen types whose short
     * address a top-level published page or post has.
     *
     * @return array<string,array<string,true>> type => name => true
     */
    public static function taken() {
        $types  = self::types();
        $stored = get_option(self::TAKEN_OPTION);
        if (is_array($stored) && isset($stored['types'], $stored['names']) && $stored['types'] === $types) {
            return $stored['names'];
        }
        global $wpdb;
        $taken = array();
        foreach ($types as $type) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- stored in an option until posts change.
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT a.post_name FROM {$wpdb->posts} a JOIN {$wpdb->posts} b ON b.post_name = a.post_name AND b.post_parent = 0 AND b.post_status = 'publish' AND b.post_type IN ('post', 'page') WHERE a.post_type = %s AND a.post_parent = 0 AND a.post_status = 'publish' ORDER BY a.post_name LIMIT 500",
                $type
            ));
            if ($names) {
                $taken[$type] = array_fill_keys(array_map('strval', $names), true);
            }
        }
        update_option(self::TAKEN_OPTION, array('types' => $types, 'names' => $taken), true);
        return $taken;
    }

    /**
     * Posts changed: work out the taken addresses again when next needed.
     */
    public static function forget_taken() {
        if (false !== get_option(self::TAKEN_OPTION)) {
            delete_option(self::TAKEN_OPTION);
        }
    }

    /**
     * Send old addresses with the base to the short one.
     */
    public static function redirect_old() {
        if ('' !== self::$resolved || !is_singular(self::types()) || is_preview() || is_embed() || is_feed() || is_trackback()) {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if ('GET' !== $method && 'HEAD' !== $method) {
            return;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post || 'publish' !== $post->post_status || self::is_taken($post)) {
            return;
        }
        $target = get_permalink($post);
        $page   = (int) get_query_var('page');
        if ($page > 1) {
            $target = trailingslashit($target) . user_trailingslashit((string) $page, 'single_paged');
        }
        $wanted = wp_parse_url($target, PHP_URL_PATH);
        $asked  = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) : '';
        if (!$target || !$wanted || untrailingslashit((string) $asked) === untrailingslashit((string) $wanted)) {
            return;
        }
        $query = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through as-is, not output.
        parse_str($query, $args);
        unset($args[get_post_type_object($post->post_type)->query_var], $args['post_type'], $args['p'], $args['name'], $args['page']);
        if (wp_safe_redirect($args ? add_query_arg(urlencode_deep($args), $target) : $target, 301, 'SEO Pro Stack')) {
            exit;
        }
    }

    /**
     * Settings panel: items whose short address a page or post already has.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field) {
        if (self::KEY !== $key) {
            return;
        }
        if ('' === (string) get_option('permalink_structure')) {
            printf(
                '<div class="sps-panel-note sps-panel-note--warning"><p>%s</p></div>',
                wp_kses(
                    sprintf(
                        /* translators: %s: Settings → Permalinks address */
                        __('This site uses plain addresses (?p=123), so there is no base to remove. Choose another structure in <a href="%s">Settings → Permalinks</a>.', 'seoprostack'),
                        esc_url(admin_url('options-permalink.php'))
                    ),
                    array('a' => array('href' => array()))
                )
            );
            return;
        }
        if (!self::enabled()) {
            return;
        }
        $taken = array();
        foreach (self::taken() as $names) {
            $taken = array_merge($taken, array_keys($names));
        }
        if (!$taken) {
            return;
        }
        sort($taken);
        $shown = array_slice($taken, 0, 5);
        printf(
            '<div class="sps-panel-note sps-panel-note--warning"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %s: comma-separated item names */
                __('A page or post already has the short address of: %s. Those items keep their old address; rename one of each pair to change that.', 'seoprostack'),
                implode(', ', array_map(function ($name) {
                    return '/' . urldecode($name) . '/';
                }, $shown)) . (count($taken) > 5 ? ', …' : '')
            ))
        );
    }
}
