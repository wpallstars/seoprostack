<?php
/**
 * Keep pages built with TaxoPress and Tag Groups working after those
 * plugins are deactivated (Tag clouds and related posts).
 *
 * Their saved settings are read in place and never changed, so turning the
 * plugin back on finds everything as it was:
 *
 * - TaxoPress: taxonomies made with it (taxopress_taxonomies) stay
 *   registered; its Terms Display, Terms for Current Post and Related Posts
 *   blocks, shortcodes and widgets, and the st_tag_cloud(), st_the_tags()
 *   and st_related_posts() template functions, are drawn with the Term list
 *   and Related posts blocks from their saved displays.
 * - Tag Groups (and Pro): the groups (term_groups, term_group_labels,
 *   term_group_positions and each term's _cm_term_group_array) group the
 *   Term list; its tabbed, accordion, alphabetical and list clouds, as
 *   blocks or shortcodes, are drawn the same way.
 *
 * Left out, so their shortcodes and blocks show nothing instead of raw
 * text: Tag Groups Pro's post filters and post lists, and its group info.
 *
 * @package SEOProStack
 * @since 0.11.11
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Term_Legacy {

    /** TaxoPress shortcodes, tag => display type. */
    const TAXOPRESS_SHORTCODES = array(
        'taxopress_termsdisplay' => 'cloud',
        'taxopress_postterms'    => 'post',
        'taxopress_relatedposts' => 'related',
    );

    /** TaxoPress blocks, name => [display type, ID attribute]. */
    const TAXOPRESS_BLOCKS = array(
        'taxopress/tag-clouds'    => array('cloud', 'tagcloud_id'),
        'taxopress/post-tags'     => array('post', 'posttags_id'),
        'taxopress/related-posts' => array('related', 'relatedpost_id'),
    );

    /** Tag Groups shortcodes, tag => display. */
    const TAG_GROUPS_SHORTCODES = array(
        'tag_groups_cloud'              => 'tabs',
        'tag_groups_accordion'          => 'accordion',
        'tag_groups_alphabet_tabs'      => 'index',
        'tag_groups_alphabetical_index' => 'index',
        'tag_groups_tag_list'           => 'list',
        'tag_groups_table'              => 'list',
        'tag_groups_simple_cloud'       => 'cloud',
        'tag_groups_combined_cloud'     => 'cloud',
        'tag_groups_shuffle_box'        => 'headings',
    );

    /** Tag Groups blocks, name => display. */
    const TAG_GROUPS_BLOCKS = array(
        'chatty-mango/tag-groups-cloud-tabs'             => 'tabs',
        'chatty-mango/tag-groups-cloud-accordion'        => 'accordion',
        'chatty-mango/tag-groups-alphabet-tabs'          => 'index',
        'chatty-mango/tag-groups-alphabetical-tag-index' => 'index',
        'chatty-mango/tag-groups-tag-list'               => 'list',
        'chatty-mango/tag-groups-premium-cloud-table'    => 'list',
        'chatty-mango/tag-groups-premium-cloud-combined' => 'cloud',
        'chatty-mango/tag-groups-premium-shuffle-box'    => 'headings',
    );

    /** Tag Groups Pro post filters and lists: left out, shown as nothing. */
    const LEFT_OUT_SHORTCODES = array(
        'tag_groups_info', 'tag_groups_menu', 'tag_groups_dpf', 'tag_groups_dpf_body', 'tag_groups_post_list',
        'tag_groups_tpf_menu', 'tag_groups_dpf_toggle_menu', 'tag_groups_tpf_body', 'tag_groups_dpf_toggle_body',
        'tag_groups_tpf_messages', 'tag_groups_dpf_toggle_messages', 'tag_groups_tpf_reset', 'tag_groups_dpf_toggle_reset',
        'tag_groups_tpf_slider_button', 'tag_groups_tpf_order_menu', 'tag_groups_tpf_text_search', 'tag_groups_cloud_search',
    );

    /** Left-out blocks. */
    const LEFT_OUT_BLOCKS = array(
        'chatty-mango/tag-groups-premium-cloud-search', 'chatty-mango/tag-groups-premium-dpf',
        'chatty-mango/chatty-mango-guten-dpfwt-menu', 'chatty-mango/tag-groups-premium-post-filter',
        'chatty-mango/chatty-mango-guten-dpfwt-body', 'chatty-mango/chatty-mango-tpf-menu',
        'chatty-mango/chatty-mango-guten-dpfwt-messages', 'chatty-mango/chatty-mango-tpf-order-menu',
        'chatty-mango/chatty-mango-guten-dpfwt-reset', 'chatty-mango/chatty-mango-tpf-slider-button',
        'chatty-mango/chatty-mango-tpf-text-search',
    );

    /**
     * Whether a site has anything made with TaxoPress or Tag Groups.
     *
     * @return bool
     */
    public static function in_use() {
        foreach (array('taxopress_taxonomies', 'taxopress_tagclouds', 'taxopress_posttags', 'taxopress_relatedposts') as $option) {
            if (array_filter((array) get_option($option, array()))) {
                return true;
            }
        }
        return (bool) SEOProStack_Term_List::tag_groups();
    }

    /**
     * Register the stand-ins (on init:0, after the plugins would have).
     */
    public static function boot() {
        self::register_taxonomies();

        foreach (self::TAXOPRESS_SHORTCODES as $tag => $type) {
            self::shortcode($tag, static function ($atts) use ($type) {
                $atts = (array) $atts;
                return self::taxopress_display($type, isset($atts['id']) ? (string) $atts['id'] : '', array());
            });
        }
        foreach (self::TAG_GROUPS_SHORTCODES as $tag => $display) {
            self::shortcode($tag, static function ($atts) use ($display) {
                return self::tag_groups_display($display, (array) $atts);
            });
        }
        foreach (self::LEFT_OUT_SHORTCODES as $tag) {
            self::shortcode($tag, '__return_empty_string');
        }

        $registry = WP_Block_Type_Registry::get_instance();
        foreach (self::TAXOPRESS_BLOCKS as $name => $block) {
            if (!$registry->is_registered($name)) {
                register_block_type($name, array(
                    'render_callback' => static function ($attributes) use ($block) {
                        $attributes = (array) $attributes;
                        $post_id    = isset($attributes['post_id']) ? (int) $attributes['post_id'] : 0;
                        return self::taxopress_display($block[0], isset($attributes[$block[1]]) ? (string) $attributes[$block[1]] : '', $post_id > 0 ? array('postId' => $post_id) : array());
                    },
                ));
            }
        }
        foreach (self::TAG_GROUPS_BLOCKS as $name => $display) {
            if (!$registry->is_registered($name)) {
                register_block_type($name, array(
                    'render_callback' => static function ($attributes) use ($display) {
                        return self::tag_groups_display($display, (array) $attributes);
                    },
                ));
            }
        }
        foreach (self::LEFT_OUT_BLOCKS as $name) {
            if (!$registry->is_registered($name)) {
                register_block_type($name, array('render_callback' => '__return_empty_string'));
            }
        }

        add_action('widgets_init', array(__CLASS__, 'register_widgets'));
        add_filter('the_content', array(__CLASS__, 'post_terms_after_content'), 26);
        self::template_functions();
    }

    /**
     * Terms for Current Post displays that TaxoPress added after the
     * content of chosen post types.
     *
     * @param string $content Post content.
     * @return string
     */
    public static function post_terms_after_content($content) {
        if (is_feed() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        foreach ((array) get_option('taxopress_posttags', array()) as $config) {
            $where = is_array($config) && isset($config['embedded']) ? (array) $config['embedded'] : array();
            if (in_array('singleonly', $where, true) || in_array(get_post_type(), $where, true)) {
                $content .= self::taxopress_render('post', $config, array('postId' => get_the_ID()));
            }
        }
        return $content;
    }

    /**
     * Add a shortcode unless something else has it.
     *
     * @param string   $tag      Tag.
     * @param callable $callback Callback.
     */
    private static function shortcode($tag, $callback) {
        if (!shortcode_exists($tag)) {
            add_shortcode($tag, $callback);
        }
    }

    /**
     * Keep taxonomies made with TaxoPress, with the choices saved for them,
     * so their terms, archives and post links stay.
     */
    public static function register_taxonomies() {
        $saved = get_option('taxopress_taxonomies', array());
        if (!is_array($saved) || !$saved) {
            return;
        }
        $off = (array) get_option('taxopress_deactivated_taxonomies', array());
        foreach ($saved as $tax) {
            if (!is_array($tax) || empty($tax['name'])) {
                continue;
            }
            $name = sanitize_key($tax['name']);
            if ('' === $name || taxonomy_exists($name) || in_array($name, $off, true)) {
                continue;
            }
            register_taxonomy($name, self::taxonomy_types($tax), self::taxonomy_args($tax, $name));
        }
    }

    /**
     * A TaxoPress true/false choice ("true", "1", "false", "0" or empty).
     *
     * @param array  $tax     Saved taxonomy.
     * @param string $key     Key.
     * @param bool   $default When not saved.
     * @return bool
     */
    private static function flag(array $tax, $key, $default) {
        if (!isset($tax[$key]) || '' === (string) $tax[$key]) {
            return $default;
        }
        return !in_array((string) $tax[$key], array('0', 'false'), true);
    }

    /**
     * Post types a TaxoPress taxonomy belongs to.
     *
     * @param array $tax Saved taxonomy.
     * @return string[]
     */
    private static function taxonomy_types(array $tax) {
        return isset($tax['object_types']) && is_array($tax['object_types']) ? array_values(array_filter(array_map('sanitize_key', $tax['object_types']))) : array();
    }

    /**
     * register_taxonomy() arguments, the way TaxoPress builds them.
     *
     * @param array  $tax  Saved taxonomy.
     * @param string $name Taxonomy name.
     * @return array
     */
    private static function taxonomy_args(array $tax, $name) {
        $plural   = isset($tax['label']) && '' !== (string) $tax['label'] ? (string) $tax['label'] : $name;
        $singular = isset($tax['singular_label']) && '' !== (string) $tax['singular_label'] ? (string) $tax['singular_label'] : $plural;
        $labels   = array('name' => $plural, 'singular_name' => $singular);
        foreach ((array) (isset($tax['labels']) ? $tax['labels'] : array()) as $key => $label) {
            if (is_string($key) && is_string($label) && '' !== $label) {
                $labels[$key] = $label;
            }
        }

        $public  = self::flag($tax, 'public', true);
        $rewrite = self::flag($tax, 'rewrite', true);
        if ($rewrite) {
            $rewrite = array(
                'slug'         => !empty($tax['rewrite_slug']) ? (string) $tax['rewrite_slug'] : $name,
                'with_front'   => self::flag($tax, 'rewrite_withfront', true),
                'hierarchical' => self::flag($tax, 'rewrite_hierarchical', false),
            );
        }
        $query_var = self::flag($tax, 'query_var', true);
        if ($query_var && !empty($tax['query_var_slug'])) {
            $query_var = (string) $tax['query_var_slug'];
        }
        $show_ui = self::flag($tax, 'show_ui', true);

        $default_term = null;
        if (!empty($tax['default_term'])) {
            $parts = array_filter(array_map('trim', explode(',', (string) $tax['default_term'])));
            if ($parts) {
                // register_taxonomy() takes one default term.
                $first        = reset($parts);
                $default_term = array('name' => $first, 'slug' => sanitize_title($first));
            }
        }

        $args = array(
            'labels'             => $labels,
            'label'              => $plural,
            'description'        => isset($tax['description']) ? (string) $tax['description'] : '',
            'public'             => $public,
            'publicly_queryable' => self::flag($tax, 'publicly_queryable', $public),
            'hierarchical'       => self::flag($tax, 'hierarchical', false),
            'show_ui'            => $show_ui,
            'show_in_menu'       => self::flag($tax, 'show_in_menu', $show_ui),
            'show_in_nav_menus'  => self::flag($tax, 'show_in_nav_menus', $public),
            'query_var'          => $query_var,
            'rewrite'            => $rewrite,
            'show_admin_column'  => self::flag($tax, 'show_admin_column', false),
            'show_in_rest'       => self::flag($tax, 'show_in_rest', true),
            'show_in_quick_edit' => self::flag($tax, 'show_in_quick_edit', $show_ui),
            'show_tagcloud'      => self::flag($tax, 'show_tagcloud', true),
            'default_term'       => $default_term,
        );
        if (!empty($tax['rest_base'])) {
            $args['rest_base'] = sanitize_key($tax['rest_base']);
        }
        /**
         * Filters the arguments of a taxonomy kept from TaxoPress.
         *
         * @param array  $args Arguments for register_taxonomy().
         * @param string $name Taxonomy name.
         * @param array  $tax  TaxoPress' saved settings for it.
         */
        return (array) apply_filters('seoprostack_taxopress_taxonomy_args', $args, $name, $tax);
    }

    /**
     * A saved TaxoPress display.
     *
     * @param string $type cloud, post or related.
     * @param string $id   Display ID.
     * @return array|null
     */
    private static function taxopress_config($type, $id) {
        $options = array('cloud' => 'taxopress_tagclouds', 'post' => 'taxopress_posttags', 'related' => 'taxopress_relatedposts');
        $saved   = (array) get_option($options[$type], array());
        if ('' === $id) {
            // Blocks saved without a choice used the first display.
            $first = reset($saved);
            return is_array($first) ? $first : null;
        }
        return isset($saved[$id]) && is_array($saved[$id]) ? $saved[$id] : null;
    }

    /**
     * Draw a TaxoPress display from its saved settings.
     *
     * @param string $type  cloud, post or related.
     * @param string $id    Display ID.
     * @param array  $extra Extra attributes (postId).
     * @return string
     */
    public static function taxopress_display($type, $id, array $extra) {
        $config = self::taxopress_config($type, $id);
        return null === $config ? '' : self::taxopress_render($type, $config, $extra);
    }

    /**
     * Draw TaxoPress settings (a saved display, or template function
     * arguments) with the Term list or Related posts block.
     *
     * @param string $type   cloud, post or related.
     * @param array  $config TaxoPress settings.
     * @param array  $extra  Extra attributes.
     * @return string
     */
    public static function taxopress_render($type, array $config, array $extra = array()) {
        $c     = $config;
        $title = '';
        if (empty($c['hide_title']) && isset($c['title']) && '' !== trim(wp_strip_all_tags((string) $c['title']))) {
            $tag   = isset($c['title_header']) && preg_match('/^h[1-6]$/', (string) $c['title_header']) ? $c['title_header'] : 'h4';
            $title = '<' . $tag . ' class="wp-block-seoprostack-term-list__title">' . esc_html(wp_strip_all_tags((string) $c['title'])) . '</' . $tag . '>';
        }

        if ('related' === $type) {
            $taxonomies = isset($c['taxonomy']) && '' !== (string) $c['taxonomy'] ? array_map('trim', explode(',', (string) $c['taxonomy'])) : array();
            $format     = isset($c['format']) ? (string) $c['format'] : 'box';
            $html       = SEOProStack_Term_Displays::render($extra + array(
                'taxonomies' => $taxonomies,
                'number'     => isset($c['number']) ? (int) $c['number'] : 5,
                'layout'     => 'box' === $format ? 'grid' : 'list',
                'columns'    => 4,
                'titleHtml'  => str_replace('wp-block-seoprostack-term-list__title', 'wp-block-seoprostack-related-posts__title', $title),
            ), false);
            if ('' === $html && !empty($c['nopoststext'])) {
                $html = '<p class="wp-block-seoprostack-related-posts__empty">' . esc_html((string) $c['nopoststext']) . '</p>';
            }
            return $html;
        }

        $format = isset($c['format']) ? (string) $c['format'] : 'flat';
        $map    = array(
            'list'         => 'list',
            'ol'           => 'list',
            'table'        => 'list',
            'parent/child' => 'list',
            'border'       => 'cloud',
            'box'          => 'cloud',
            'comma'        => 'inline',
        );
        $layout = isset($map[$format]) ? $map[$format] : ('post' === $type ? 'inline' : 'cloud');
        $unit   = isset($c['unit']) ? (string) $c['unit'] : 'pt';
        $per_em = array('pt' => 12, 'px' => 16, 'em' => 1, '%' => 100);
        $per_em = isset($per_em[$unit]) ? $per_em[$unit] : 12;
        $order  = isset($c['orderby']) ? (string) $c['orderby'] : 'name';

        $attributes = $extra + array(
            'taxonomy'     => isset($c['taxonomy']) && '' !== (string) $c['taxonomy'] ? (string) $c['taxonomy'] : 'post_tag',
            'source'       => 'post' === $type ? 'post' : 'all',
            'layout'       => $layout,
            'pills'        => in_array($format, array('border', 'box'), true),
            'orderBy'      => in_array($order, array('count', 'random'), true) ? $order : 'name',
            'number'       => isset($c['max']) ? (int) $c['max'] : (isset($c['number']) ? (int) $c['number'] : 0),
            'minCount'     => isset($c['min_usage']) ? (int) $c['min_usage'] : 0,
            'smallest'     => isset($c['smallest']) && is_numeric($c['smallest']) ? (float) $c['smallest'] / $per_em : 0.875,
            'largest'      => isset($c['largest']) && is_numeric($c['largest']) ? (float) $c['largest'] / $per_em : 1.75,
            'showCount'    => 'table' === $format,
            'showChildren' => 'parent/child' === $format || 'post' === $type,
            'showEmpty'    => false,
            'title'        => $title,
            'prefix'       => 'post' === $type && isset($c['before']) ? (string) $c['before'] : '',
            'emptyText'    => 'post' === $type && isset($c['notagtext']) ? (string) $c['notagtext'] : '',
        );
        if ('post' !== $type && !empty($c['include_terms']) && (!isset($c['term_selection_mode']) || 'custom' === $c['term_selection_mode'])) {
            $attributes['include'] = self::term_ids($c['include_terms'], $attributes['taxonomy']);
        }
        if (!empty($c['exclude_terms'])) {
            $attributes['exclude'] = self::term_ids($c['exclude_terms'], $attributes['taxonomy']); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams -- Term list block attribute (terms), not a post query.
        }
        $classes = isset($c['wrap_class']) ? array_map('sanitize_html_class', preg_split('/\s+/', (string) $c['wrap_class'])) : array();
        SEOProStack_Term_List::enqueue_assets(false);
        return SEOProStack_Term_List::render($attributes, $classes, false);
    }

    /**
     * Term IDs from a comma-separated list of IDs or names.
     *
     * @param string $list     List.
     * @param string $taxonomy Taxonomy.
     * @return int[]
     */
    private static function term_ids($list, $taxonomy) {
        $ids = array();
        foreach (array_filter(array_map('trim', explode(',', (string) $list))) as $item) {
            if (ctype_digit($item)) {
                $ids[] = (int) $item;
                continue;
            }
            $term = get_term_by('name', $item, $taxonomy);
            if ($term) {
                $ids[] = (int) $term->term_id;
            }
        }
        // An empty list would mean "all terms".
        return $ids ? $ids : array(-1);
    }

    /**
     * Draw a Tag Groups cloud or list from its shortcode or block settings.
     *
     * @param string $display tabs, accordion, headings, list, index or cloud.
     * @param array  $atts    Shortcode or block attributes (Tag Groups' names).
     * @return string
     */
    public static function tag_groups_display($display, array $atts) {
        $taxonomies = isset($atts['taxonomy']) && '' !== trim((string) $atts['taxonomy'])
            ? array_map('trim', explode(',', (string) $atts['taxonomy']))
            : (array) get_option('tag_group_taxonomy', array('post_tag'));
        $taxonomy   = sanitize_key((string) reset($taxonomies));
        $groups     = isset($atts['include']) && '' !== trim((string) $atts['include']) ? array_values(array_filter(array_map('intval', explode(',', (string) $atts['include'])))) : array();
        $order      = isset($atts['orderby']) ? strtolower((string) $atts['orderby']) : 'name';
        $post_id    = isset($atts['tags_post_id']) ? (int) $atts['tags_post_id'] : -1;
        $unassigned = !empty($atts['show_not_assigned']) && !in_array((string) $atts['show_not_assigned'], array('0', 'false'), true);
        $hide_empty = !isset($atts['hide_empty']) || !in_array((string) $atts['hide_empty'], array('0', 'false', ''), true);

        $layouts = array('tabs' => 'cloud', 'accordion' => 'cloud', 'headings' => 'cloud', 'index' => 'index', 'list' => 'list', 'cloud' => 'cloud');
        $attributes = array(
            'taxonomy'       => '' !== $taxonomy ? $taxonomy : 'post_tag',
            'source'         => $post_id >= 0 ? 'post' : 'all',
            'layout'         => $layouts[$display],
            'groupBy'        => in_array($display, array('tabs', 'accordion', 'headings', 'list'), true) ? 'tag-groups' : 'none',
            'groupStyle'     => in_array($display, array('tabs', 'accordion'), true) ? $display : 'headings',
            'groups'         => $groups,
            'showUnassigned' => $unassigned,
            'orderBy'        => in_array($order, array('count', 'random'), true) ? $order : 'name',
            'number'         => isset($atts['amount']) ? max(0, (int) $atts['amount']) : 0,
            'minCount'       => isset($atts['threshold']) ? max(0, (int) $atts['threshold']) : 0,
            'smallest'       => isset($atts['smallest']) && is_numeric($atts['smallest']) ? (float) $atts['smallest'] / 16 : 0.75,
            'largest'        => isset($atts['largest']) && is_numeric($atts['largest']) ? (float) $atts['largest'] / 16 : 1.375,
            'showCount'      => 'list' === $display && !empty($atts['show_tag_count']),
            'showChildren'   => true,
            'showEmpty'      => !$hide_empty,
        );
        if ($post_id > 0) {
            $attributes['postId'] = $post_id;
        }
        if (!empty($atts['include_terms'])) {
            $attributes['include'] = self::term_ids($atts['include_terms'], $attributes['taxonomy']);
        }
        if (!empty($atts['exclude_terms'])) {
            $attributes['exclude'] = self::term_ids($atts['exclude_terms'], $attributes['taxonomy']); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams -- Term list block attribute (terms), not a post query.
        }
        $classes = isset($atts['div_class']) ? array_map('sanitize_html_class', preg_split('/\s+/', (string) $atts['div_class'])) : array();
        SEOProStack_Term_List::enqueue_assets(SEOProStack_Term_List::needs_script($attributes));
        return SEOProStack_Term_List::render($attributes, $classes, false);
    }

    /**
     * TaxoPress' widgets, so widget areas keep them.
     */
    public static function register_widgets() {
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-term-legacy-widgets.php';
        global $wp_widget_factory;
        $taken = array();
        foreach ($wp_widget_factory->widgets as $widget) {
            $taken[$widget->id_base] = true;
        }
        foreach (SEOProStack_Taxopress_Display_Widget::TYPES as $id_base => $type) {
            if (!isset($taken[$id_base])) {
                register_widget(new SEOProStack_Taxopress_Display_Widget($id_base));
            }
        }
        if (!isset($taken['simpletags'])) {
            register_widget('SEOProStack_Taxopress_Widget');
        }
    }

    /**
     * TaxoPress' template functions, for themes that call them.
     */
    private static function template_functions() {
        if (!function_exists('st_tag_cloud')) {
            require_once SEOPROSTACK_DIR . 'includes/taxopress-functions.php';
        }
    }
}
