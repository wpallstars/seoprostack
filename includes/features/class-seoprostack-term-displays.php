<?php
/**
 * Tag clouds and related posts.
 *
 * - A Related posts block (seoprostack/related-posts), also added after the
 *   content of chosen post types: posts that share the most categories,
 *   tags or other terms with the one being read. Shared terms used on few
 *   posts count for more than ones used everywhere, so "WordPress" on a
 *   WordPress blog does not make every post related.
 * - The Term list block (SEOProStack_Term_List) with tag clouds, A–Z
 *   indexes, the current post's terms, and groups under headings, in an
 *   accordion or in tabs.
 * - TaxoPress and Tag Groups keep working after they are deactivated
 *   (SEOProStack_Term_Legacy): their blocks, shortcodes, widgets and
 *   template functions are drawn with these blocks from their own saved
 *   settings, and taxonomies made with TaxoPress stay registered.
 *
 * Replaces TaxoPress (and Pro) and Tag Groups (and Pro), in part: their
 * automatic term linking, automatic and AI tagging, and front-end post
 * filters are left out (README.md → Tag clouds and related posts says why).
 *
 * @package SEOProStack
 * @since 0.11.11
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Term_Displays extends SEOProStack_Feature {

    const KEY = 'term_displays';

    /** Related posts block. */
    const RELATED = 'seoprostack/related-posts';

    /** Setting: post types that get related posts after their content. */
    const TYPES = 'related_posts_types';

    /** Object cache group. */
    const CACHE = 'seoprostack_related';

    /** Most related posts a list shows. */
    const MAX_RELATED = 24;

    /** Plugins replaced, folder => name. */
    const REPLACES = array(
        'simple-tags'    => 'TaxoPress',
        'taxopress-pro'  => 'TaxoPress Pro',
        'tag-groups'     => 'Tag Groups',
        'tag-groups-pro' => 'Tag Groups Pro',
    );

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
                'label'       => __('Tag clouds and related posts', 'seoprostack'),
                'description' => __('Adds a Related posts block, and tag clouds, A–Z indexes, the current post’s terms, and terms grouped in tabs or an accordion to the Term list block. Pages built with TaxoPress or Tag Groups keep working after they are deactivated, and taxonomies made with TaxoPress stay.', 'seoprostack'),
                'replaces'    => self::REPLACES,
            ),
            self::TYPES => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array(),
                'parent'  => self::KEY,
                'label'       => __('Add related posts after the content of', 'seoprostack'),
                'description' => __('Some themes show related posts of their own, such as Kadence on posts (Customizer → Posts/Pages Layout → Single Post Layout → Show Related Posts). Turn one off so posts do not show two lists.', 'seoprostack'),
                'options'     => array('SEOProStack_Duplicate_Posts', 'post_type_options'),
            ),
        );
    }

    /**
     * Switch on while TaxoPress or Tag Groups is active and in use, so pages
     * keep working the moment it is deactivated, and take the post types
     * TaxoPress adds related posts to.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (!array_intersect_key(self::REPLACES, self::active_plugins()) || !SEOProStack_Term_Legacy::in_use()) {
            return $options;
        }
        $options = self::import_setting($options, self::KEY, true);
        $types   = array();
        foreach ((array) get_option('taxopress_relatedposts', array()) as $config) {
            foreach ((array) (is_array($config) && isset($config['embedded']) ? $config['embedded'] : array()) as $where) {
                if (is_string($where) && post_type_exists($where)) {
                    $types[] = $where;
                }
            }
        }
        return $types ? self::import_setting($options, self::TYPES, array_values(array_unique($types))) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // boot() runs on init, which is where blocks are registered.
        SEOProStack_Term_List::register();
        register_block_type(SEOPROSTACK_DIR . 'blocks/related-posts', array('render_callback' => array(__CLASS__, 'render_block')));
        // After Like, save and share (20), so the buttons stay next to the post.
        add_filter('the_content', array(__CLASS__, 'after_content'), 25);
        SEOProStack_Term_Legacy::boot();
    }

    /**
     * Render the Related posts block.
     *
     * @param array    $attributes Attributes.
     * @param string   $content    Inner content (unused).
     * @param WP_Block $block      Block.
     * @return string
     */
    public static function render_block($attributes, $content = '', $block = null) {
        $a = (array) $attributes;
        if ($block instanceof WP_Block && isset($block->context['postId'])) {
            $a['postId'] = (int) $block->context['postId'];
        }
        return self::render($a);
    }

    /**
     * Related posts after the content of the chosen post types.
     *
     * @param string $content Post content.
     * @return string
     */
    public static function after_content($content) {
        $types = (array) SEOProStack_Settings::get(self::TYPES);
        if (!$types || is_feed() || !is_singular($types) || !in_the_loop() || !is_main_query() || get_the_ID() !== get_queried_object_id()) {
            return $content;
        }
        if (function_exists('has_block') && has_block(self::RELATED, get_the_ID())) {
            return $content;
        }
        return $content . self::render(array('postId' => get_the_ID()), false);
    }

    /**
     * Build a Related posts list.
     *
     * Attributes: blocks/related-posts/block.json, plus postId and, from
     * other plugins' displays, titleHtml (HTML for the heading).
     *
     * @param array $attributes Attributes.
     * @param bool  $in_block   Drawn as a block, so block supports apply.
     * @return string
     */
    public static function render(array $attributes, $in_block = true) {
        $a       = $attributes;
        $post_id = !empty($a['postId']) ? (int) $a['postId'] : (int) get_the_ID();
        $post    = $post_id > 0 ? get_post($post_id) : null;
        if (!$post) {
            return '';
        }
        $number = isset($a['number']) ? max(1, min(self::MAX_RELATED, (int) $a['number'])) : 4;
        $ids    = self::related_ids($post, isset($a['taxonomies']) ? (array) $a['taxonomies'] : array(), $number);
        if (!$ids) {
            return '';
        }

        $layout  = isset($a['layout']) && 'list' === $a['layout'] ? 'list' : 'grid';
        $image   = !isset($a['showImage']) || !empty($a['showImage']);
        $date    = !empty($a['showDate']);
        $columns = isset($a['columns']) ? max(1, min(6, (int) $a['columns'])) : 4;
        $classes = array('wp-block-seoprostack-related-posts', 'sps-related--' . $layout);
        $style   = 'grid' === $layout ? '--sps-related-columns:' . $columns . ';--sps-related-columns-small:' . min($columns, 2) . ';' : '';

        $wrapper = array('class' => implode(' ', $classes));
        if ('' !== $style) {
            $wrapper['style'] = $style;
        }
        $wrapper = $in_block && function_exists('get_block_wrapper_attributes') ? get_block_wrapper_attributes($wrapper) : '';
        if ('' === $wrapper) {
            $wrapper = sprintf('class="%s"', esc_attr(implode(' ', $classes))) . ('' !== $style ? sprintf(' style="%s"', esc_attr($style)) : '');
        }

        if (isset($a['titleHtml'])) {
            $heading = wp_kses_post((string) $a['titleHtml']);
        } elseif (!isset($a['showTitle']) || !empty($a['showTitle'])) {
            $text    = isset($a['title']) && '' !== trim((string) $a['title']) ? (string) $a['title'] : __('Related posts', 'seoprostack');
            $heading = '<h2 class="wp-block-seoprostack-related-posts__title">' . esc_html($text) . '</h2>';
        } else {
            $heading = '';
        }

        $items = '';
        foreach ($ids as $id) {
            $link = get_permalink($id);
            if (!$link) {
                continue;
            }
            $thumb = $image && 'grid' === $layout && has_post_thumbnail($id)
                ? get_the_post_thumbnail($id, 'medium_large', array('class' => 'wp-block-seoprostack-related-posts__image', 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async'))
                : '';
            $items .= '<li class="wp-block-seoprostack-related-posts__item">'
                . '<a class="wp-block-seoprostack-related-posts__link" href="' . esc_url($link) . '">'
                . ('' !== $thumb ? '<span class="wp-block-seoprostack-related-posts__media">' . $thumb . '</span>' : '')
                . '<span class="wp-block-seoprostack-related-posts__name">' . esc_html(get_the_title($id)) . '</span></a>'
                . ($date ? ' <time class="wp-block-seoprostack-related-posts__date" datetime="' . esc_attr((string) get_the_date('c', $id)) . '">' . esc_html((string) get_the_date('', $id)) . '</time>' : '')
                . '</li>';
        }
        if ('' === $items) {
            return '';
        }
        if (!$in_block) {
            $handle = function_exists('generate_block_asset_handle') ? generate_block_asset_handle(self::RELATED, 'style') : '';
            if ('' !== $handle) {
                wp_enqueue_style($handle);
            }
        }
        return sprintf('<div %s>%s<ul class="wp-block-seoprostack-related-posts__list">%s</ul></div>', $wrapper, $heading, $items);
    }

    /**
     * Published posts of the same type that share the most terms with a
     * post, rarest shared terms weighing most, newest first among equals.
     *
     * Cached in the object cache until posts or terms change.
     *
     * @param WP_Post  $post       Post.
     * @param string[] $taxonomies Taxonomies to compare ([] = all public ones of its type).
     * @param int      $number     Most posts.
     * @return int[]
     */
    public static function related_ids($post, array $taxonomies, $number) {
        global $wpdb;
        $type    = $post->post_type;
        $allowed = array();
        foreach (get_object_taxonomies($type, 'objects') as $taxonomy) {
            if ($taxonomy->public && 'post_format' !== $taxonomy->name) {
                $allowed[] = $taxonomy->name;
            }
        }
        $taxonomies = $taxonomies ? array_values(array_intersect(array_map('sanitize_key', $taxonomies), $allowed)) : $allowed;
        if (!$taxonomies) {
            return array();
        }
        $args = array(
            'post'       => (int) $post->ID,
            'type'       => $type,
            'taxonomies' => $taxonomies,
            'number'     => (int) $number,
        );
        /**
         * Filters what Related posts compares.
         *
         * @param array   $args post (ID), type (post type), taxonomies and number.
         * @param WP_Post $post The post being read.
         */
        $args = (array) apply_filters('seoprostack_related_posts_args', $args, $post);

        $key = md5((string) wp_json_encode($args)) . ':' . wp_cache_get_last_changed('posts') . ':' . wp_cache_get_last_changed('terms');
        $ids = wp_cache_get($key, self::CACHE);
        if (!is_array($ids)) {
            $tt_ids = wp_get_object_terms((int) $post->ID, (array) $args['taxonomies'], array('fields' => 'tt_ids'));
            $ids    = array();
            if (is_array($tt_ids) && $tt_ids) {
                $tt_ids = array_map('intval', $tt_ids);
                $types  = array_map('sanitize_key', (array) $args['type']);
                // Ask for more than needed: some may be hidden from this visitor.
                $limit  = min(100, max(1, (int) $args['number']) * 3);
                $in_tt  = implode(',', array_fill(0, count($tt_ids), '%d'));
                $in_ty  = implode(',', array_fill(0, count($types), '%s'));
                $values = array_merge($tt_ids, array((int) $args['post']), $types, array($limit));
                // Each shared term counts 1 / ln(posts + 2): rare terms weigh most.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one grouped join core has no API for; interpolation holds only placeholders built above; cached in the object cache.
                $rows = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id IN ($in_tt) AND p.ID <> %d AND p.post_type IN ($in_ty) AND p.post_status = 'publish' AND p.post_password = '' GROUP BY p.ID ORDER BY SUM(1 / LN(tt.count + 2)) DESC, MAX(p.post_date) DESC LIMIT %d", $values));
                $ids  = array_map('intval', (array) $rows);
            }
            wp_cache_set($key, $ids, self::CACHE, DAY_IN_SECONDS);
        }

        $restrict = class_exists('SEOProStack_Restrict_Content', false) && SEOProStack_Restrict_Content::enabled();
        $out      = array();
        foreach ($ids as $id) {
            if ($restrict && !SEOProStack_Restrict_Content::can_see($id)) {
                continue;
            }
            $out[] = $id;
            if (count($out) >= (int) $args['number']) {
                break;
            }
        }
        return $out;
    }
}
