<?php
/**
 * Restrict content.
 *
 * Who sees what, chosen where the content is edited:
 * - posts, pages and public custom post types: a "Who sees this" box in the
 *   editor; people who may not see one get its hand-written excerpt, if it
 *   has one, and a message (with a login link for logged-out visitors) in
 *   place of the content, on the page, in lists, feeds and the REST API.
 *   Its comments are hidden and closed, and the page asks search engines
 *   not to index it;
 * - categories, tags and other public taxonomies: the same choice on each
 *   term, for every post in it that has no choice of its own;
 * - any block: a Visibility panel in the block settings; hidden blocks are
 *   left out of the page.
 * People who can edit a post always see it; people who can edit others'
 * posts always see blocks kept for members or roles.
 *
 * Membership, payments and sign-up stay with the plugins that do them well
 * (Fluent Forms, FluentCRM, FluentCart, WooCommerce): they give people a
 * role, and the role decides what they see.
 *
 * Replaces Content Control. Its block rules (`contentControls`, including
 * hiding on phones, tablets or desktops) and its [content_control]
 * shortcode keep working. Its restrictions for chosen posts and terms, or
 * posts with chosen terms, become rules here once it is deactivated; others
 * are listed on the settings card to set again. Its settings and
 * restrictions are never changed.
 *
 * @package SEOProStack
 * @since 0.10.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Restrict_Content extends SEOProStack_Feature {

    const KEY = 'restrict_content';

    /** Post and term meta: array( 'show' => everyone|in|out|roles|not_roles, 'roles' => string[] ). */
    const META = '_seoprostack_restrict';

    /** Option: IDs of terms with a rule, autoloaded, so posts in no such term cost no lookups. */
    const TERMS_OPTION = 'seoprostack_restricted_terms';

    /** Option: result of converting Content Control's restrictions. */
    const IMPORT_OPTION = 'seoprostack_restrict_imported';

    /** Nonce action. */
    const NONCE = 'seoprostack_restrict';

    /** Content Control's folder. */
    const CC = 'content-control';

    /** The Members only block. */
    const BLOCK = 'seoprostack/members-only';

    /** Choices that limit who sees something. */
    const SHOWS = array('in', 'out', 'roles', 'not_roles');

    /**
     * Whether the current visitor may see each post, by ID.
     *
     * @var array<int,bool>
     */
    private static $seen = array();

    /**
     * IDs of posts with a rule the current visitor does not meet; null until needed.
     *
     * @var int[]|null
     */
    private static $hidden = null;

    /**
     * Whether the device styles were added.
     *
     * @var bool
     */
    private static $device_styles = false;

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
                'label'       => __('Restrict content', 'seoprostack'),
                'description' => __('Show posts, pages, products, categories and blocks only to logged-in people, logged-out visitors or chosen roles. Choose “Who sees this” in the editor, on a category or tag, or in a block’s Visibility panel, or add a Members only block.', 'seoprostack'),
                'replaces'    => array(self::CC => 'Content Control'),
            ),
            'restrict_content_message' => array(
                'type'        => 'text',
                'default'     => '',
                'placeholder' => self::default_message(),
                'parent'      => self::KEY,
                'label'       => __('Message instead of the content', 'seoprostack'),
                'description' => __('Logged-out visitors also get a link to log in. Above it shows what is above a post’s More block, or else its hand-written excerpt.', 'seoprostack'),
            ),
        );
    }

    /**
     * Message when no other is set.
     *
     * @return string
     */
    public static function default_message() {
        return __('This content is for members.', 'seoprostack');
    }

    /**
     * Switch on while Content Control is active, so its restrictions are
     * converted when it is deactivated, and take its message.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (!isset(self::active_plugins()[self::CC])) {
            return $options;
        }
        $options = self::import_setting($options, self::KEY, true);
        $cc      = get_option('content_control_settings', null);
        if (is_array($cc) && !empty($cc['defaultDenialMessage']) && is_string($cc['defaultDenialMessage'])) {
            $message = trim(wp_strip_all_tags($cc['defaultDenialMessage']));
            if ('' !== $message) {
                $options = self::import_setting($options, 'restrict_content_message', $message);
            }
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
            return;
        }

        // Before core and plugins register their blocks (init:10), so every
        // block accepts the attributes, also in the editor's server renders.
        add_filter('register_block_type_args', array(__CLASS__, 'block_args'), 10, 2);
        register_block_type(SEOPROSTACK_DIR . 'blocks/members-only', array('render_callback' => array(__CLASS__, 'render_members_only')));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_assets'));
        if (!shortcode_exists('content_control')) {
            add_shortcode('content_control', array(__CLASS__, 'shortcode'));
        }
        add_action('delete_term', array(__CLASS__, 'term_deleted'));

        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'admin_init'));
            add_action('add_meta_boxes', array(__CLASS__, 'add_box'));
            add_action('save_post', array(__CLASS__, 'save'), 10, 2);
            add_action('created_term', array(__CLASS__, 'save_term'), 10, 3);
            add_action('edited_term', array(__CLASS__, 'save_term'), 10, 3);
            if (!wp_doing_ajax()) {
                return;
            }
        }

        // Early, so restricted content (and its shortcodes) is never built.
        add_filter('the_content', array(__CLASS__, 'filter_content'), 0);
        add_filter('get_the_excerpt', array(__CLASS__, 'filter_excerpt'), 20, 2);
        add_filter('wp_robots', array(__CLASS__, 'robots'));
        add_filter('comments_open', array(__CLASS__, 'comments_open'), 20, 2);
        add_filter('pings_open', array(__CLASS__, 'comments_open'), 20, 2);
        add_filter('comments_array', array(__CLASS__, 'comments_array'), 20, 2);
        add_filter('get_comments_number', array(__CLASS__, 'comments_number'), 20, 2);
        // Comment lists, the Latest Comments block, the REST API and comment
        // feeds query comments without comments_array.
        add_filter('comments_clauses', array(__CLASS__, 'comments_clauses'), 20, 2);
        add_filter('comment_feed_where', array(__CLASS__, 'comment_feed_where'), 20);
        add_filter('rest_prepare_comment', array(__CLASS__, 'rest_comment'), 20, 2);
        add_filter('pre_render_block', array(__CLASS__, 'pre_render_block'), 10, 2);
        add_filter('render_block', array(__CLASS__, 'render_block'), 10, 2);

        // Shops: products the visitor may not see keep their pictures and
        // short description, without prices, and cannot be bought.
        if (class_exists('WooCommerce')) {
            foreach (array('product', 'product_variation') as $type) {
                foreach (array('price', 'regular_price', 'sale_price') as $field) {
                    add_filter("woocommerce_{$type}_get_{$field}", array(__CLASS__, 'woo_price'), 20, 2);
                }
            }
            // The Store API (and blocks that preload it) read these, not the_content.
            add_filter('woocommerce_product_get_description', array(__CLASS__, 'woo_description'), 20, 2);
            add_filter('woocommerce_product_variation_get_description', array(__CLASS__, 'woo_description'), 20, 2);
            add_filter('woocommerce_product_get_attributes', array(__CLASS__, 'woo_attributes'), 20, 2);
            add_filter('woocommerce_variation_prices', array(__CLASS__, 'woo_variation_prices'), 20, 2);
            add_filter('woocommerce_is_purchasable', array(__CLASS__, 'woo_purchasable'), 20, 2);
            add_filter('woocommerce_variation_is_purchasable', array(__CLASS__, 'woo_purchasable'), 20, 2);
            add_filter('woocommerce_get_price_html', array(__CLASS__, 'woo_price_html'), 20, 2);
            add_filter('woocommerce_product_tabs', array(__CLASS__, 'woo_tabs'), 20);
        }
        if (defined('FLUENTCART_VERSION')) {
            add_filter('fluent_cart/cart/can_purchase', array(__CLASS__, 'fluent_cart_can_purchase'), 20, 2);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Rules                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * A stored rule, checked; null when there is none.
     *
     * @param mixed $rule Stored rule.
     * @return array{show:string,roles:string[]}|null
     */
    public static function clean_rule($rule) {
        if (!is_array($rule) || !isset($rule['show']) || !is_string($rule['show'])) {
            return null;
        }
        $show  = $rule['show'];
        $roles = isset($rule['roles']) && is_array($rule['roles']) ? $rule['roles'] : array();
        $roles = array_values(array_unique(array_filter(array_map('sanitize_key', array_filter($roles, 'is_string')))));
        if ('everyone' === $show) {
            return array('show' => 'everyone', 'roles' => array());
        }
        if (!in_array($show, self::SHOWS, true)) {
            return null;
        }
        if (in_array($show, array('roles', 'not_roles'), true) && !$roles) {
            // Only these roles, none ticked: keep it for logged-in people
            // rather than show it to everyone.
            $show = 'in';
        }
        if (!in_array($show, array('roles', 'not_roles'), true)) {
            $roles = array();
        }
        return array('show' => $show, 'roles' => $roles);
    }

    /**
     * Whether the visitor counts as logged in on this site.
     *
     * @return bool
     */
    private static function logged_in() {
        if (!is_user_logged_in()) {
            return false;
        }
        return !is_multisite() || is_user_member_of_blog() || is_super_admin();
    }

    /**
     * Whether the logged-in visitor has one of the roles.
     *
     * @param string[] $roles Roles.
     * @return bool
     */
    private static function has_role(array $roles) {
        if (!self::logged_in()) {
            return false;
        }
        foreach ($roles as $role) {
            if ('' !== $role && current_user_can($role)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current visitor meets a rule.
     *
     * @param array $rule   Clean rule.
     * @param bool  $editor Whether the visitor edits this content: they pass
     *                      every rule but "Logged-out visitors".
     * @return bool
     */
    public static function meets(array $rule, $editor = false) {
        switch ($rule['show']) {
            case 'in':
                return $editor || self::logged_in();
            case 'out':
                return !self::logged_in();
            case 'roles':
                return $editor || self::has_role($rule['roles']);
            case 'not_roles':
                return $editor || (self::logged_in() && !self::has_role($rule['roles']));
            default:
                return true;
        }
    }

    /**
     * IDs of terms that have a rule.
     *
     * @return array<int,bool>
     */
    private static function restricted_terms() {
        $ids = get_option(self::TERMS_OPTION, array());
        return is_array($ids) ? array_fill_keys(array_map('intval', $ids), true) : array();
    }

    /**
     * Add or remove a term from the list of terms with a rule.
     *
     * @param int  $term_id Term ID.
     * @param bool $listed  Whether it has a rule.
     */
    private static function list_term($term_id, $listed) {
        $ids = self::restricted_terms();
        if ($listed === isset($ids[$term_id])) {
            return;
        }
        if ($listed) {
            $ids[$term_id] = true;
        } else {
            unset($ids[$term_id]);
        }
        $ids = array_keys($ids);
        sort($ids);
        update_option(self::TERMS_OPTION, $ids, true);
    }

    /**
     * Rules from the terms a post is in, with each term.
     *
     * @param int $post_id Post ID.
     * @return array<int,array{show:string,roles:string[],term:WP_Term}>
     */
    private static function term_rules($post_id) {
        $listed = self::restricted_terms();
        if (!$listed) {
            return array();
        }
        $rules = array();
        foreach (get_object_taxonomies((string) get_post_type($post_id)) as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (!isset($listed[$term->term_id])) {
                    continue;
                }
                $rule = self::clean_rule(get_term_meta($term->term_id, self::META, true));
                if ($rule && 'everyone' !== $rule['show']) {
                    $rule['term'] = $term;
                    $rules[]      = $rule;
                }
            }
        }
        return $rules;
    }

    /**
     * The rules a post follows: its own, or else those of its terms.
     *
     * @param int $post_id Post ID.
     * @return array[] Empty when everyone sees it.
     */
    public static function post_rules($post_id) {
        $own = self::clean_rule(get_post_meta($post_id, self::META, true));
        if ($own) {
            return 'everyone' === $own['show'] ? array() : array($own);
        }
        return self::term_rules($post_id);
    }

    /**
     * Whether the current visitor may see a post.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function can_see($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return true;
        }
        if (!isset(self::$seen[$post_id])) {
            $rules   = self::post_rules($post_id);
            $allowed = true;
            if ($rules && !current_user_can('edit_post', $post_id)) {
                foreach ($rules as $rule) {
                    if (!self::meets($rule)) {
                        $allowed = false;
                        break;
                    }
                }
            }
            /**
             * Whether the current visitor may see a post's content.
             *
             * @param bool  $allowed Allowed.
             * @param int   $post_id Post ID.
             * @param array $rules   Rules it follows (show, roles; term when from a term). Empty for everyone.
             */
            self::$seen[$post_id] = (bool) apply_filters('seoprostack_can_see_content', $allowed, $post_id, $rules);
        }
        return self::$seen[$post_id];
    }

    /* --------------------------------------------------------------------- */
    /* Posts on the site                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * What a visitor who may not see a post is told, as text.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private static function message_text($post_id) {
        $rules = self::post_rules($post_id);
        return self::rule_message($rules ? $rules[0] : null);
    }

    /**
     * What a visitor who does not meet a rule is told, as text.
     *
     * @param array|null $rule   Rule.
     * @param string     $custom Message chosen for this content, if any.
     * @return string
     */
    private static function rule_message($rule, $custom = '') {
        if ($rule && 'out' === $rule['show'] && self::logged_in()) {
            return __('This content is for visitors who are not logged in.', 'seoprostack');
        }
        $message = trim($custom);
        if ('' === $message) {
            $message = trim((string) SEOProStack_Settings::get('restrict_content_message'));
        }
        return '' !== $message ? $message : self::default_message();
    }

    /**
     * What a visitor who may not see a post gets in place of its content.
     *
     * @param WP_Post $post    Post.
     * @param bool    $excerpt Whether to show its hand-written excerpt first.
     * @return string
     */
    private static function message_html($post, $excerpt = true) {
        $html = '';
        if ($excerpt && '' !== trim($post->post_excerpt)) {
            $html .= '<p class="sps-restricted__excerpt">' . esc_html(wp_strip_all_tags($post->post_excerpt)) . '</p>';
        }
        return '<div class="sps-restricted">' . $html . self::notice_html(self::message_text($post->ID), (string) get_permalink($post)) . '</div>';
    }

    /**
     * The message, with a login link for logged-out visitors.
     *
     * @param string $message  Message.
     * @param string $back_to  Address to come back to after logging in.
     * @return string
     */
    private static function notice_html($message, $back_to) {
        $html = '<p class="sps-restricted__message">' . esc_html($message) . '</p>';
        if (!is_user_logged_in()) {
            $html .= sprintf(
                '<p class="sps-restricted__login"><a href="%1$s">%2$s</a></p>',
                esc_url(wp_login_url($back_to)),
                esc_html__('Log in to see it', 'seoprostack')
            );
        }
        return $html;
    }

    /**
     * The part of a post above its More block (or <!--more--> tag), shown
     * to everyone; null when it has none.
     *
     * @param WP_Post $post Post.
     * @return string|null
     */
    public static function teaser($post) {
        if (!preg_match('/<!--more(?:\s.*?)?-->/', $post->post_content, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $teaser = substr($post->post_content, 0, (int) $match[0][1]);
        // The More block's opening comment, left open by the cut.
        $teaser = (string) preg_replace('/<!--\s+wp:more\b[^>]*-->\s*$/', '', $teaser);
        return '' !== trim($teaser) ? $teaser : null;
    }

    /**
     * Replace the content of a post the visitor may not see: the part above
     * its More block, if it has one, else its hand-written excerpt, then the
     * message. Runs first, so the rest is never built.
     *
     * @param string $content Content.
     * @return string
     */
    public static function filter_content($content) {
        $post = get_post();
        if (!$post instanceof WP_Post || self::can_see($post->ID)) {
            return $content;
        }
        $teaser = self::teaser($post);
        if (null === $teaser) {
            return self::message_html($post);
        }
        // The rest of the_content (blocks, shortcodes) builds the teaser.
        return $teaser . "\n\n" . self::message_html($post, false);
    }

    /**
     * Excerpts: a hand-written one stays, as a teaser; else the part above
     * the More block; else the message.
     *
     * @param string           $excerpt Excerpt.
     * @param WP_Post|int|null $post    Post.
     * @return string
     */
    public static function filter_excerpt($excerpt, $post = null) {
        $post = get_post($post);
        if (!$post instanceof WP_Post || self::can_see($post->ID) || '' !== trim($post->post_excerpt)) {
            return $excerpt;
        }
        $teaser = self::teaser($post);
        if (null !== $teaser) {
            $text = trim(wp_strip_all_tags(strip_shortcodes(excerpt_remove_blocks($teaser))));
            if ('' !== $text) {
                $length = (int) apply_filters('excerpt_length', 55); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, as wp_trim_excerpt() reads it.
                return wp_trim_words($text, $length, '') . ' ' . self::message_text($post->ID);
            }
        }
        return self::message_text($post->ID);
    }

    /**
     * Ask search engines not to index a page the visitor may not see.
     *
     * @param array $robots Robots directives.
     * @return array
     */
    public static function robots($robots) {
        if (is_singular() && !self::can_see(get_queried_object_id())) {
            $robots['noindex'] = true;
        }
        return $robots;
    }

    /**
     * Close comments on posts the visitor may not see.
     *
     * @param bool $open    Open.
     * @param int  $post_id Post ID.
     * @return bool
     */
    public static function comments_open($open, $post_id) {
        return $open && self::can_see((int) $post_id);
    }

    /**
     * Hide comments on posts the visitor may not see.
     *
     * @param array $comments Comments.
     * @param int   $post_id  Post ID.
     * @return array
     */
    public static function comments_array($comments, $post_id) {
        return self::can_see((int) $post_id) ? $comments : array();
    }

    /**
     * No comment count on posts the visitor may not see.
     *
     * @param int|string $count   Count.
     * @param int        $post_id Post ID.
     * @return int|string
     */
    public static function comments_number($count, $post_id) {
        return self::can_see((int) $post_id) ? $count : 0;
    }

    /**
     * IDs of posts with a rule the current visitor does not meet.
     *
     * @return int[]
     */
    private static function hidden_post_ids() {
        if (null !== self::$hidden) {
            return self::$hidden;
        }
        $query = array(
            'post_type'        => 'any',
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
        );
        $ids = get_posts(
            $query + array(
                'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only posts with a rule, once per request.
                'meta_compare' => 'EXISTS',
            )
        );
        $terms = array_keys(self::restricted_terms());
        if ($terms) {
            $by_taxonomy = array();
            foreach (get_terms(array('include' => $terms, 'hide_empty' => false, 'taxonomy' => get_taxonomies())) as $term) {
                if ($term instanceof WP_Term) {
                    $by_taxonomy[$term->taxonomy][] = $term->term_id;
                }
            }
            $tax_query = array('relation' => 'OR');
            foreach ($by_taxonomy as $taxonomy => $term_ids) {
                $tax_query[] = array(
                    'taxonomy'         => $taxonomy,
                    'terms'            => $term_ids,
                    'include_children' => false,
                );
            }
            if (count($tax_query) > 1) {
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- only posts in restricted terms, once per request.
                $ids = array_merge($ids, get_posts($query + array('tax_query' => $tax_query)));
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids) {
            _prime_post_caches($ids, true, true);
        }
        self::$hidden = array();
        foreach ($ids as $id) {
            if (!self::can_see($id)) {
                self::$hidden[] = $id;
            }
        }
        return self::$hidden;
    }

    /**
     * SQL that leaves out comments on posts the visitor may not see.
     *
     * @return string Empty when there are none.
     */
    private static function hidden_comments_sql() {
        global $wpdb;
        $hidden = self::hidden_post_ids();
        if (!$hidden) {
            return '';
        }
        return " AND {$wpdb->comments}.comment_post_ID NOT IN (" . implode(',', array_map('intval', $hidden)) . ')';
    }

    /**
     * Leave comments on posts the visitor may not see out of comment queries.
     *
     * @param array            $clauses Query clauses.
     * @param WP_Comment_Query $query   Query.
     * @return array
     */
    public static function comments_clauses($clauses, $query) {
        if ((defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
            // No visitor: WP-CLI and scheduled tasks (spam checks, emails) see every comment.
            return $clauses;
        }
        $post_id = isset($query->query_vars['post_id']) ? (int) $query->query_vars['post_id'] : 0;
        if ($post_id > 0) {
            // One post's comments: no need to look up every restricted post.
            if (!self::can_see($post_id)) {
                $clauses['where'] .= ' AND 0 = 1';
            }
            return $clauses;
        }
        $clauses['where'] .= self::hidden_comments_sql();
        return $clauses;
    }

    /**
     * Leave comments on posts the visitor may not see out of comment feeds.
     *
     * @param string $where WHERE clause.
     * @return string
     */
    public static function comment_feed_where($where) {
        return $where . self::hidden_comments_sql();
    }

    /**
     * A single comment on a post the visitor may not see, from the REST API.
     *
     * @param WP_REST_Response $response Response.
     * @param WP_Comment       $comment  Comment.
     * @return WP_REST_Response
     */
    public static function rest_comment($response, $comment) {
        if (!$comment instanceof WP_Comment || self::can_see((int) $comment->comment_post_ID)) {
            return $response;
        }
        return new WP_REST_Response(
            array(
                'code'    => 'rest_forbidden',
                'message' => self::message_text((int) $comment->comment_post_ID),
                'data'    => array('status' => rest_authorization_required_code()),
            ),
            rest_authorization_required_code()
        );
    }

    /* --------------------------------------------------------------------- */
    /* Shops                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Whether a WooCommerce product is kept from the current visitor: its
     * own rule or its categories', for variations their product's. WP-CLI
     * and scheduled tasks (feeds, stock sync) have no visitor and see all.
     *
     * @param mixed $product Product.
     * @return bool
     */
    private static function woo_hidden($product) {
        if (!$product instanceof WC_Product || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
            return false;
        }
        $id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        return $id > 0 && !self::can_see($id);
    }

    /**
     * No price for products the visitor may not see, so it is not shown,
     * sent (Store API, structured data) or charged.
     *
     * @param mixed      $price   Price.
     * @param WC_Product $product Product.
     * @return mixed
     */
    public static function woo_price($price, $product) {
        return self::woo_hidden($product) ? '' : $price;
    }

    /**
     * The message in place of the description of products the visitor may
     * not see.
     *
     * @param string     $description Description.
     * @param WC_Product $product     Product.
     * @return string
     */
    public static function woo_description($description, $product) {
        if (!self::woo_hidden($product)) {
            return $description;
        }
        $id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        return self::message_text($id);
    }

    /**
     * No attributes (sizes, materials, pack sizes) for products the visitor
     * may not see.
     *
     * @param array      $attributes Attributes.
     * @param WC_Product $product    Product.
     * @return array
     */
    public static function woo_attributes($attributes, $product) {
        return self::woo_hidden($product) ? array() : $attributes;
    }

    /**
     * No price range for variable products the visitor may not see.
     *
     * @param array      $prices  Prices: price, regular_price, sale_price.
     * @param WC_Product $product Product.
     * @return array
     */
    public static function woo_variation_prices($prices, $product) {
        if (!self::woo_hidden($product)) {
            return $prices;
        }
        return array('price' => array(), 'regular_price' => array(), 'sale_price' => array());
    }

    /**
     * Products the visitor may not see cannot be bought.
     *
     * @param bool       $purchasable Purchasable.
     * @param WC_Product $product     Product.
     * @return bool
     */
    public static function woo_purchasable($purchasable, $product) {
        return $purchasable && !self::woo_hidden($product);
    }

    /**
     * In place of the price: a login link for logged-out visitors.
     *
     * @param string     $html    Price HTML.
     * @param WC_Product $product Product.
     * @return string
     */
    public static function woo_price_html($html, $product) {
        if (!self::woo_hidden($product)) {
            return $html;
        }
        if (is_user_logged_in()) {
            return '';
        }
        $back_to = (string) get_permalink($product->get_parent_id() ? $product->get_parent_id() : $product->get_id());
        return sprintf(
            '<a class="sps-restricted__login" href="%1$s">%2$s</a>',
            esc_url(wp_login_url($back_to)),
            esc_html__('Log in to see prices', 'seoprostack')
        );
    }

    /**
     * No Additional information (attributes, weights, sizes) or Reviews
     * tabs on products the visitor may not see; the Description tab shows
     * the message.
     *
     * @param array $tabs Tabs.
     * @return array
     */
    public static function woo_tabs($tabs) {
        $product = isset($GLOBALS['product']) ? $GLOBALS['product'] : null;
        if (is_array($tabs) && self::woo_hidden($product)) {
            unset($tabs['additional_information'], $tabs['reviews']);
        }
        return $tabs;
    }

    /**
     * FluentCart: products the visitor may not see cannot go in the cart.
     *
     * @param true|WP_Error $can  Whether it can be bought.
     * @param array         $args Cart, variation and quantity.
     * @return true|WP_Error
     */
    public static function fluent_cart_can_purchase($can, $args) {
        if (is_wp_error($can) || !is_array($args) || empty($args['variation']) || !is_object($args['variation']) || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
            return $can;
        }
        $post_id = isset($args['variation']->post_id) ? (int) $args['variation']->post_id : 0;
        if ($post_id > 0 && !self::can_see($post_id)) {
            return new WP_Error('seoprostack_restricted', self::message_text($post_id));
        }
        return $can;
    }

    /* --------------------------------------------------------------------- */
    /* Blocks                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Let every block keep its visibility, and Content Control's.
     *
     * @param array  $args Block type arguments.
     * @param string $name Block name.
     * @return array
     */
    public static function block_args($args, $name) {
        if (!isset($args['attributes']) || !is_array($args['attributes'])) {
            $args['attributes'] = array();
        }
        $args['attributes']['spsVisibility'] = array('type' => 'object');
        if (!isset($args['attributes']['contentControls'])) {
            $args['attributes']['contentControls'] = array('type' => 'object');
        }
        return $args;
    }

    /**
     * Roles from Content Control: a list, a comma-separated string or
     * role => flag.
     *
     * @param mixed $roles Roles.
     * @return string[]
     */
    private static function cc_roles($roles) {
        if (is_string($roles)) {
            $roles = array_map('strtolower', array_map('trim', explode(',', $roles)));
        }
        if (!is_array($roles)) {
            return array();
        }
        if ($roles && is_string(key($roles))) {
            $roles = array_keys($roles);
        }
        return array_values(array_filter(array_map('sanitize_key', array_filter($roles, 'is_string'))));
    }

    /**
     * A rule from Content Control's user settings (status, role match, roles).
     *
     * @param mixed $status Status: logged_in or logged_out.
     * @param mixed $match  any, match or exclude.
     * @param mixed $roles  Roles.
     * @return array|null Null when the settings are not understood.
     */
    private static function cc_user_rule($status, $match, $roles) {
        if ('logged_out' === $status) {
            return array('show' => 'out', 'roles' => array());
        }
        if ('logged_in' !== $status) {
            return null;
        }
        $roles = self::cc_roles($roles);
        if (!$roles || !in_array($match, array('match', 'exclude'), true)) {
            return array('show' => 'in', 'roles' => array());
        }
        return array('show' => 'match' === $match ? 'roles' : 'not_roles', 'roles' => $roles);
    }

    /**
     * The rule of a block: ours, else Content Control's.
     *
     * @param array $block Parsed block.
     * @return array|null
     */
    public static function block_rule(array $block) {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        if (isset($attrs['spsVisibility'])) {
            $rule = self::clean_rule($attrs['spsVisibility']);
            if ($rule || !isset($block['blockName']) || self::BLOCK !== $block['blockName']) {
                return $rule;
            }
        }
        if (isset($block['blockName']) && self::BLOCK === $block['blockName']) {
            // The Members only block is for logged-in people until chosen otherwise.
            return array('show' => 'in', 'roles' => array());
        }
        if (empty($attrs['contentControls']['enabled']) || empty($attrs['contentControls']['rules']['user']) || !is_array($attrs['contentControls']['rules']['user'])) {
            return null;
        }
        $user = $attrs['contentControls']['rules']['user'];
        $rule = self::cc_user_rule(
            isset($user['userStatus']) ? $user['userStatus'] : '',
            isset($user['roleMatch']) ? $user['roleMatch'] : 'any',
            isset($user['userRoles']) ? $user['userRoles'] : array()
        );
        // Content Control hides a block whose rule it cannot read; keep it
        // for logged-in people instead.
        return $rule ? $rule : array('show' => 'in', 'roles' => array());
    }

    /**
     * Whether this request is the editor previewing a block.
     *
     * @return bool
     */
    private static function editor_preview() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }
        $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
        return 0 === strpos($route, '/wp/v2/block-renderer');
    }

    /**
     * Whether the current visitor sees a block.
     *
     * @param array $block Parsed block.
     * @return bool
     */
    public static function block_visible(array $block) {
        $rule = self::block_rule($block);
        if (!$rule || 'everyone' === $rule['show'] || self::editor_preview()) {
            return true;
        }
        $visible = self::meets($rule, current_user_can('edit_others_posts'));
        /**
         * Whether the current visitor sees a block.
         *
         * @param bool  $visible Visible.
         * @param array $block   Parsed block.
         * @param array $rule    The block's rule (show, roles).
         */
        return (bool) apply_filters('seoprostack_block_visible', $visible, $block, $rule);
    }

    /**
     * Skip hidden blocks before they are built.
     *
     * @param string|null $pre   Output so far.
     * @param array       $block Parsed block.
     * @return string|null
     */
    public static function pre_render_block($pre, $block) {
        if (null !== $pre || !is_array($block)) {
            return $pre;
        }
        return self::block_visible($block) ? null : self::hidden_block_html($block);
    }

    /**
     * What replaces a hidden block: the message for the Members only block,
     * else nothing.
     *
     * @param array $block Parsed block.
     * @return string
     */
    private static function hidden_block_html(array $block) {
        if (!isset($block['blockName']) || self::BLOCK !== $block['blockName']) {
            return '';
        }
        $custom  = isset($block['attrs']['message']) && is_string($block['attrs']['message']) ? $block['attrs']['message'] : '';
        $back_to = get_permalink();
        $back_to = $back_to ? $back_to : home_url('/');
        $classes = 'wp-block-seoprostack-members-only sps-restricted';
        if (!empty($block['attrs']['align']) && is_string($block['attrs']['align'])) {
            $classes .= ' align' . sanitize_html_class($block['attrs']['align']);
        }
        return '<div class="' . esc_attr($classes) . '">' . self::notice_html(self::rule_message(self::block_rule($block), $custom), $back_to) . '</div>';
    }

    /**
     * The Members only block, for people who see it: its blocks.
     *
     * @param array  $attributes Attributes.
     * @param string $content    Inner blocks' HTML.
     * @return string
     */
    public static function render_members_only($attributes, $content) {
        return '<div ' . get_block_wrapper_attributes() . '>' . $content . '</div>';
    }

    /**
     * Hide inner blocks that pre_render_block does not see, and mark blocks
     * that Content Control hid on some devices.
     *
     * @param string $content Block HTML.
     * @param array  $block   Parsed block.
     * @return string
     */
    public static function render_block($content, $block) {
        if (!is_array($block) || (empty($block['attrs']) && (!isset($block['blockName']) || self::BLOCK !== $block['blockName']))) {
            return $content;
        }
        if (!self::block_visible($block)) {
            return self::hidden_block_html($block);
        }
        $controls = isset($block['attrs']['contentControls']) ? $block['attrs']['contentControls'] : null;
        if (empty($controls['enabled']) || empty($controls['rules']['device']['hideOn']) || !is_array($controls['rules']['device']['hideOn']) || !class_exists('WP_HTML_Tag_Processor')) {
            return $content;
        }
        $tags = new WP_HTML_Tag_Processor((string) $content);
        if (!$tags->next_tag()) {
            return $content;
        }
        $added = false;
        foreach (array('mobile', 'tablet', 'desktop') as $device) {
            if (!empty($controls['rules']['device']['hideOn'][$device])) {
                $tags->add_class('sps-hide-on-' . $device);
                $added = true;
            }
        }
        if (!$added) {
            return $content;
        }
        self::device_styles();
        return $tags->get_updated_html();
    }

    /**
     * Styles for blocks hidden on some devices, at Content Control's
     * breakpoints (its own, if it had any set).
     */
    private static function device_styles() {
        if (self::$device_styles) {
            return;
        }
        self::$device_styles = true;
        $cc      = get_option('content_control_settings', null);
        $queries = is_array($cc) && !empty($cc['mediaQueries']) && is_array($cc['mediaQueries']) ? $cc['mediaQueries'] : null;
        $hide    = '{display:none!important}';
        if (!$queries) {
            $css = '@media (max-width:480px){.sps-hide-on-mobile' . $hide . '}'
                . '@media (min-width:481px) and (max-width:991px){.sps-hide-on-tablet' . $hide . '}'
                . '@media (min-width:992px){.sps-hide-on-desktop' . $hide . '}';
        } else {
            $mobile  = isset($queries['mobile']['breakpoint']) ? absint($queries['mobile']['breakpoint']) : 640;
            $tablet  = isset($queries['tablet']['breakpoint']) ? absint($queries['tablet']['breakpoint']) : 920;
            $desktop = isset($queries['desktop']['breakpoint']) ? absint($queries['desktop']['breakpoint']) : 1440;
            $css     = sprintf(
                '@media (max-width:%1$dpx){.sps-hide-on-mobile%4$s}@media (min-width:%2$dpx) and (max-width:%3$dpx){.sps-hide-on-tablet%4$s}@media (min-width:%5$dpx) and (max-width:%6$dpx){.sps-hide-on-desktop%4$s}',
                $mobile,
                $mobile + 1,
                $tablet,
                $hide,
                $tablet + 1,
                $desktop
            );
        }
        wp_register_style('seoprostack-restrict', false, array(), SEOPROSTACK_VERSION);
        wp_add_inline_style('seoprostack-restrict', $css);
        wp_enqueue_style('seoprostack-restrict');
    }

    /**
     * Content Control's [content_control] shortcode, once it is deactivated.
     *
     * @param array|string $atts    Attributes.
     * @param string       $content Content.
     * @return string
     */
    public static function shortcode($atts, $content = '') {
        $atts = is_array($atts) ? $atts : array();
        // Valueless attributes, such as [content_control inline].
        foreach ($atts as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $atts[strtolower($value)] = true;
                unset($atts[$key]);
            }
        }
        $old  = shortcode_atts(array('logged_out' => null, 'roles' => null), $atts);
        $atts = shortcode_atts(array(
            'status'         => 'logged_in',
            'allowed_roles'  => null,
            'excluded_roles' => null,
            'class'          => '',
            'inline'         => false,
            'message'        => (string) SEOProStack_Settings::get('restrict_content_message'),
        ), $atts, 'content_control');
        if (null !== $old['logged_out']) {
            $atts['status'] = wp_validate_boolean($old['logged_out']) ? 'logged_out' : 'logged_in';
        }
        if (!empty($old['roles'])) {
            $atts['allowed_roles'] = $old['roles'];
        }

        $roles = array();
        $match = 'any';
        if (!empty($atts['excluded_roles'])) {
            $roles = $atts['excluded_roles'];
            $match = 'exclude';
        } elseif (!empty($atts['allowed_roles'])) {
            $roles = $atts['allowed_roles'];
            $match = 'match';
        }
        $rule    = self::cc_user_rule((string) $atts['status'], $match, $roles);
        $allowed = $rule && self::meets($rule);

        $classes   = array_filter(array_map('sanitize_html_class', explode(' ', (string) $atts['class'])));
        $classes[] = 'content-control-container';
        $classes[] = $allowed ? 'content-control-accessible' : 'content-control-not-accessible';
        $tag       = wp_validate_boolean($atts['inline']) ? 'span' : 'div';
        $output    = $allowed ? do_shortcode((string) $content) : wp_kses_post(do_shortcode((string) $atts['message']));

        // Nested shortcodes the visitor may see render as they would anywhere.
        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', $tag, esc_attr(implode(' ', $classes)), $output);
    }

    /* --------------------------------------------------------------------- */
    /* Editing                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Choices, with the first one's label.
     *
     * @param string $first   Label of the "no rule" choice.
     * @param bool   $explicit Also offer "Everyone", to outrank term rules.
     * @return array<string,string>
     */
    private static function choices($first, $explicit = false) {
        $choices = array('' => $first);
        if ($explicit) {
            $choices['everyone'] = __('Everyone', 'seoprostack');
        }
        return $choices + array(
            'in'        => __('Logged-in people', 'seoprostack'),
            'out'       => __('Logged-out visitors', 'seoprostack'),
            'roles'     => __('Only these roles', 'seoprostack'),
            'not_roles' => __('Logged-in people, except these roles', 'seoprostack'),
        );
    }

    /**
     * The "Who sees this" fields.
     *
     * @param string     $id      Field ID.
     * @param array|null $rule    Current rule.
     * @param array      $choices Choices.
     */
    private static function fields($id, $rule, array $choices) {
        $show  = $rule ? $rule['show'] : '';
        $roles = self::role_options();
        $with  = in_array($show, array('roles', 'not_roles'), true);
        wp_nonce_field(self::NONCE, '_seoprostack_restrict', false);
        ?>
        <select id="<?php echo esc_attr($id); ?>" name="sps_restrict[show]" class="seoprostack-restrict-show" onchange="this.parentNode.querySelector('.seoprostack-restrict-roles').hidden = this.value !== 'roles' && this.value !== 'not_roles';">
            <?php foreach ($choices as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($show, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <span class="seoprostack-restrict-roles" style="display:block;margin-top:6px;" <?php echo $with ? '' : 'hidden'; ?>>
            <?php foreach ($roles as $role => $name) : ?>
                <label style="display:inline-block;margin:0 12px 4px 0;">
                    <input type="checkbox" name="sps_restrict[roles][]" value="<?php echo esc_attr($role); ?>" <?php checked($rule && in_array($role, $rule['roles'], true)); ?> />
                    <?php echo esc_html($name); ?>
                </label>
            <?php endforeach; ?>
        </span>
        <?php
    }

    /**
     * The rule sent with a form, after the nonce is checked; false when no
     * rule was sent.
     *
     * @return array|null|false Null for no rule.
     */
    private static function posted_rule() {
        if (!isset($_POST['_seoprostack_restrict'], $_POST['sps_restrict']) || !is_array($_POST['sps_restrict'])) {
            return false;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_seoprostack_restrict'])), self::NONCE)) {
            return false;
        }
        $data = wp_unslash($_POST['sps_restrict']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_rule() checks every value.
        return self::clean_rule(array(
            'show'  => isset($data['show']) ? sanitize_key((string) $data['show']) : '',
            'roles' => isset($data['roles']) ? (array) $data['roles'] : array(),
        ));
    }

    /**
     * Post types that can be restricted.
     *
     * @return string[]
     */
    private static function post_types() {
        $types = get_post_types(array('public' => true, 'show_ui' => true));
        unset($types['attachment']);
        return array_values($types);
    }

    /**
     * Meta box on public post types.
     */
    public static function add_box() {
        add_meta_box('seoprostack-restrict', __('Who sees this', 'seoprostack'), array(__CLASS__, 'render_box'), self::post_types(), 'side', 'default');
    }

    /**
     * The meta box.
     *
     * @param WP_Post $post Post.
     */
    public static function render_box($post) {
        $rule  = self::clean_rule(get_post_meta($post->ID, self::META, true));
        $terms = self::term_rules($post->ID);
        $names = array();
        foreach ($terms as $term_rule) {
            $names[] = $term_rule['term']->name;
        }
        $first = $names
            /* translators: %s: category or tag names */
            ? sprintf(__('Same as %s', 'seoprostack'), implode(', ', $names))
            : __('Everyone', 'seoprostack');
        echo '<p>';
        self::fields('seoprostack-restrict-show', $rule, self::choices($first, (bool) $names));
        echo '</p><p class="description">' . esc_html__('Others see the excerpt, if it has one, and a message. People who can edit it always see it.', 'seoprostack') . '</p>';
    }

    /**
     * Save a post's rule.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function save($post_id, $post) {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !in_array($post->post_type, self::post_types(), true)) {
            return;
        }
        $rule = self::posted_rule();
        if (false === $rule || !current_user_can('edit_post', $post_id)) {
            return;
        }
        if (null === $rule) {
            delete_post_meta($post_id, self::META);
            return;
        }
        update_post_meta($post_id, self::META, $rule);
    }

    /**
     * Admin set-up that needs taxonomies registered.
     */
    public static function admin_init() {
        foreach (get_taxonomies(array('public' => true, 'show_ui' => true)) as $taxonomy) {
            add_action($taxonomy . '_add_form_fields', array(__CLASS__, 'term_add_fields'));
            add_action($taxonomy . '_edit_form_fields', array(__CLASS__, 'term_edit_fields'));
        }
        if (current_user_can('manage_options') && false === get_option(self::IMPORT_OPTION, false)) {
            self::import_content_control();
        }
    }

    /**
     * Fields on the Add term form.
     */
    public static function term_add_fields() {
        echo '<div class="form-field"><label for="seoprostack-restrict-show">' . esc_html__('Who sees its posts', 'seoprostack') . '</label>';
        self::fields('seoprostack-restrict-show', null, self::choices(__('Everyone', 'seoprostack')));
        echo '<p>' . esc_html__('For posts in it that have no choice of their own.', 'seoprostack') . '</p></div>';
    }

    /**
     * Fields on the Edit term form.
     *
     * @param WP_Term $term Term.
     */
    public static function term_edit_fields($term) {
        echo '<tr class="form-field"><th scope="row"><label for="seoprostack-restrict-show">' . esc_html__('Who sees its posts', 'seoprostack') . '</label></th><td>';
        self::fields('seoprostack-restrict-show', self::clean_rule(get_term_meta($term->term_id, self::META, true)), self::choices(__('Everyone', 'seoprostack')));
        echo '<p class="description">' . esc_html__('For posts in it that have no choice of their own.', 'seoprostack') . '</p></td></tr>';
    }

    /**
     * Save a term's rule.
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy.
     */
    public static function save_term($term_id, $tt_id = 0, $taxonomy = '') {
        // Only from the term forms: a post saved with this box can create
        // tags, which must not take the post's rule.
        $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- posted_rule() checks the nonce.
        if (!in_array($action, array('add-tag', 'editedtag'), true)) {
            return;
        }
        $rule = self::posted_rule();
        if (false === $rule || !current_user_can('edit_term', $term_id)) {
            return;
        }
        if (null === $rule || 'everyone' === $rule['show']) {
            delete_term_meta($term_id, self::META);
            self::list_term((int) $term_id, false);
            return;
        }
        update_term_meta($term_id, self::META, $rule);
        self::list_term((int) $term_id, true);
    }

    /**
     * A deleted term no longer has a rule.
     *
     * @param int $term_id Term ID.
     */
    public static function term_deleted($term_id) {
        self::list_term((int) $term_id, false);
    }

    /**
     * The Visibility panel for every block.
     */
    public static function editor_assets() {
        $roles = array();
        foreach (self::role_options() as $role => $name) {
            $roles[] = array('value' => $role, 'label' => $name);
        }
        $shows = array();
        foreach (self::choices(__('Everyone', 'seoprostack')) as $value => $label) {
            $shows[] = array('value' => '' === $value ? 'everyone' : $value, 'label' => $label);
        }
        $cfg = array(
            'panel'   => __('Visibility', 'seoprostack'),
            'label'   => __('Who sees this block', 'seoprostack'),
            'help'    => __('People who can edit others’ posts see blocks kept for members or roles.', 'seoprostack'),
            'shows'   => $shows,
            'roles'   => $roles,
            'ccNote'  => __('Content Control’s rule for this block applies until you choose here.', 'seoprostack'),
            'ccClear' => __('Remove Content Control’s rule', 'seoprostack'),
            'block'   => self::BLOCK,
            'message' => self::rule_message(null),
        );
        wp_register_script('seoprostack-restrict', false, array('wp-hooks', 'wp-blocks', 'wp-element', 'wp-components', 'wp-compose', 'wp-block-editor'), SEOPROSTACK_VERSION, false);
        wp_enqueue_script('seoprostack-restrict');
        wp_add_inline_script('seoprostack-restrict', '(function (wp, cfg) {
    var el = wp.element.createElement, c = wp.components, be = wp.blockEditor;
    window.seoprostackRestrict = cfg;
    wp.hooks.addFilter("blocks.registerBlockType", "seoprostack/restrict", function (settings) {
        var attrs = Object.assign({}, settings.attributes || {});
        attrs.spsVisibility = attrs.spsVisibility || { type: "object" };
        attrs.contentControls = attrs.contentControls || { type: "object" };
        return Object.assign({}, settings, { attributes: attrs });
    });
    var withPanel = wp.compose.createHigherOrderComponent(function (BlockEdit) {
        return function (props) {
            if (!props.isSelected) { return el(BlockEdit, props); }
            var members = cfg.block === props.name;
            var v = props.attributes.spsVisibility || {}, show = v.show || (members ? "in" : "everyone"), roles = v.roles || [];
            var cc = props.attributes.contentControls, ccRule = !!(cc && cc.enabled && !props.attributes.spsVisibility);
            function set(s, r) { props.setAttributes({ spsVisibility: "everyone" === s && !members ? undefined : { show: s, roles: r } }); }
            var kids = [el(c.SelectControl, { key: "show", label: cfg.label, value: show, options: cfg.shows, help: cfg.help, onChange: function (s) { set(s, roles); } })];
            if ("roles" === show || "not_roles" === show) {
                cfg.roles.forEach(function (r) {
                    kids.push(el(c.CheckboxControl, { key: r.value, label: r.label, checked: roles.indexOf(r.value) > -1, onChange: function (on) {
                        var next = roles.filter(function (x) { return x !== r.value; });
                        if (on) { next.push(r.value); }
                        set(show, next);
                    } }));
                });
            }
            if (ccRule) {
                kids.push(el("p", { key: "cc" }, cfg.ccNote));
                kids.push(el(c.Button, { key: "ccx", variant: "secondary", onClick: function () { props.setAttributes({ contentControls: undefined }); } }, cfg.ccClear));
            }
            return el(wp.element.Fragment, null, el(BlockEdit, props),
                el(be.InspectorControls, null, el(c.PanelBody, { title: cfg.panel, initialOpen: "everyone" !== show || ccRule || members }, kids)));
        };
    }, "withSeoprostackVisibility");
    wp.hooks.addFilter("editor.BlockEdit", "seoprostack/restrict", withPanel);
})(window.wp, ' . wp_json_encode($cfg) . ');');
    }

    /* --------------------------------------------------------------------- */
    /* Content Control's restrictions                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Content Control's rule names this feature can follow, with what they
     * point at: post IDs of a post type, or term IDs of a taxonomy.
     *
     * @return array<string,array{0:string,1:string}> name => (post|term, type)
     */
    private static function cc_rule_names() {
        $names      = array();
        $taxonomies = get_taxonomies();
        foreach (get_post_types() as $type) {
            $names['content_is_selected_' . $type] = array('post', $type);
            $names['content_is_' . $type . '_with_id'] = array('post', $type);
            foreach (get_object_taxonomies($type) as $taxonomy) {
                $names['content_is_' . $type . '_with_' . $taxonomy] = array('term', $taxonomy);
            }
        }
        foreach ($taxonomies as $taxonomy) {
            $names['content_is_selected_tax_' . $taxonomy] = array('term', $taxonomy);
            $names['content_is_tax_' . $taxonomy . '_with_id'] = array('term', $taxonomy);
        }
        return $names;
    }

    /**
     * IDs chosen in a Content Control rule.
     *
     * @param mixed $options Rule options.
     * @return int[]
     */
    private static function cc_ids($options) {
        $selected = is_array($options) && isset($options['selected']) ? $options['selected'] : array();
        if (is_string($selected) || is_int($selected)) {
            return wp_parse_id_list($selected);
        }
        $ids = array();
        foreach ((array) $selected as $item) {
            if (is_array($item)) {
                $item = isset($item['value']) ? $item['value'] : (isset($item['id']) ? $item['id'] : 0);
            }
            if (is_scalar($item)) {
                $ids[] = absint($item);
            }
        }
        return array_values(array_filter(array_unique($ids)));
    }

    /**
     * Turn Content Control's restrictions for chosen posts and terms into
     * rules here, once. Posts and terms that have a rule already keep it.
     * Content Control's restrictions are only read.
     *
     * @return array{posts:int,terms:int,skipped:string[]}
     */
    public static function import_content_control() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once; the post type is not registered without Content Control.
        $restrictions = (array) $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'cc_restriction' AND post_status = 'publish' ORDER BY menu_order, ID");
        $result       = array('posts' => 0, 'terms' => 0, 'skipped' => array());
        $names        = $restrictions ? self::cc_rule_names() : array();

        foreach ($restrictions as $restriction) {
            $title    = '' !== trim($restriction->post_title) ? $restriction->post_title : '#' . $restriction->ID;
            $settings = get_post_meta((int) $restriction->ID, 'restriction_settings', true);
            $rule     = is_array($settings) ? self::cc_user_rule(
                isset($settings['userStatus']) ? $settings['userStatus'] : '',
                isset($settings['roleMatch']) ? $settings['roleMatch'] : 'any',
                isset($settings['userRoles']) ? $settings['userRoles'] : array()
            ) : null;
            $query    = is_array($settings) && isset($settings['conditions']) && is_array($settings['conditions']) ? $settings['conditions'] : array();
            $items    = isset($query['items']) && is_array($query['items']) ? $query['items'] : array();
            $operator = isset($query['logicalOperator']) ? $query['logicalOperator'] : 'and';
            // Each condition must restrict on its own: one condition, or any of several.
            if (!$rule || !$items || (count($items) > 1 && 'or' !== $operator)) {
                $result['skipped'][] = $title;
                continue;
            }
            $partial = false;
            foreach ($items as $item) {
                $name = is_array($item) && isset($item['name']) && is_string($item['name']) ? $item['name'] : '';
                if ('' === $name || !isset($names[$name]) || !empty($item['notOperand']) || (isset($item['type']) && 'rule' !== $item['type'])) {
                    $partial = true;
                    continue;
                }
                list($kind, $type) = $names[$name];
                foreach (self::cc_ids(isset($item['options']) ? $item['options'] : array()) as $id) {
                    if ('post' === $kind) {
                        if (get_post_type($id) === $type && '' === get_post_meta($id, self::META, true)) {
                            update_post_meta($id, self::META, $rule);
                            $result['posts']++;
                        }
                        continue;
                    }
                    $term = get_term($id, $type);
                    if ($term instanceof WP_Term && '' === get_term_meta($id, self::META, true)) {
                        update_term_meta($id, self::META, $rule);
                        self::list_term($id, true);
                        $result['terms']++;
                    }
                }
            }
            if ($partial) {
                $result['skipped'][] = $title;
            }
        }
        update_option(self::IMPORT_OPTION, $result, false);
        return $result;
    }

    /**
     * What the settings card says about Content Control's restrictions.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field) {
        if (self::KEY !== $key || !self::switched_on()) {
            return;
        }
        $result = get_option(self::IMPORT_OPTION, false);
        if (!is_array($result)) {
            return;
        }
        $done = (int) $result['posts'] + (int) $result['terms'];
        if (!$done && empty($result['skipped'])) {
            return;
        }
        echo '<div class="sps-panel-note">';
        if ($done) {
            $posts = (int) $result['posts'];
            $terms = (int) $result['terms'];
            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: 1: "3 posts or pages", 2: "2 categories or tags" */
                    __('From Content Control, “Who sees this” is set on %1$s and %2$s.', 'seoprostack'),
                    /* translators: %s: number of posts */
                    sprintf(_n('%s post or page', '%s posts or pages', $posts, 'seoprostack'), number_format_i18n($posts)),
                    /* translators: %s: number of terms */
                    sprintf(_n('%s category or tag', '%s categories or tags', $terms, 'seoprostack'), number_format_i18n($terms))
                ))
            );
        }
        if (!empty($result['skipped'])) {
            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: %s: Content Control restriction names */
                    __('Content Control restrictions not carried over, or only in part, to set again where needed: %s.', 'seoprostack'),
                    implode(', ', array_map('wp_strip_all_tags', (array) $result['skipped']))
                ))
            );
        }
        echo '</div>';
    }
}
