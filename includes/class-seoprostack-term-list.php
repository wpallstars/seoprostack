<?php
/**
 * The Term list block (seoprostack/term-list): the terms of any taxonomy as
 * a list, grid, drop-down, tag cloud, comma-separated line or A–Z index,
 * optionally only the current post's terms, and optionally grouped (by
 * parent term, or by Tag Groups' groups) under headings, in an accordion
 * or in tabs.
 *
 * Shared by Spectra block replacements (which also draws Spectra's Taxonomy
 * List with it) and Tag clouds and related posts (which also draws
 * TaxoPress' and Tag Groups' term displays with it).
 *
 * Front-end markup uses no fixed colours: links and text inherit the
 * theme's, and boxes use translucent borders, so Kadence's dark mode
 * switcher (and themes that swap palettes the same way) changes them too.
 *
 * @package SEOProStack
 * @since 0.11.11
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Term_List {

    /** Block name. */
    const BLOCK = 'seoprostack/term-list';

    /** Layouts. */
    const LAYOUTS = array('list', 'grid', 'dropdown', 'cloud', 'inline', 'index');

    /** Layouts that show the terms as one flat set (child terms are not nested). */
    const FLAT = array('cloud', 'inline', 'index');

    /** List markers offered by the block ('' = theme default). */
    const MARKERS = array('', 'none', 'disc', 'circle', 'square', 'decimal');

    /** Most terms a list shows (filterable through seoprostack_term_list_args). */
    const MAX_TERMS = 1000;

    /**
     * Register the block once, for whichever feature needs it first.
     */
    public static function register() {
        if (WP_Block_Type_Registry::get_instance()->is_registered(self::BLOCK)) {
            return;
        }
        register_block_type(SEOPROSTACK_DIR . 'blocks/term-list');
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_settings'));
    }

    /**
     * Tell the editor whether Tag Groups' groups can be used.
     */
    public static function editor_settings() {
        $handle = function_exists('generate_block_asset_handle') ? generate_block_asset_handle(self::BLOCK, 'editorScript') : '';
        if ('' === $handle || !wp_script_is($handle, 'registered')) {
            return;
        }
        wp_add_inline_script($handle, 'window.seoprostackTermList = ' . wp_json_encode(array(
            'tagGroups' => (bool) self::tag_groups(),
        )) . ';', 'before');
    }

    /**
     * Enqueue the block's style (and script, for drop-downs and tabs) when
     * a list is drawn outside the block: for another plugin's block,
     * shortcode or widget.
     *
     * @param bool $script Also the script.
     */
    public static function enqueue_assets($script = false) {
        $type = WP_Block_Type_Registry::get_instance()->get_registered(self::BLOCK);
        if (!$type) {
            return;
        }
        foreach ($type->style_handles as $handle) {
            wp_enqueue_style($handle);
        }
        if ($script) {
            foreach ($type->view_script_handles as $handle) {
                if ('' !== $handle) {
                    wp_enqueue_script($handle);
                }
            }
        }
    }

    /**
     * Whether a list with these attributes needs the view script.
     *
     * @param array $a Attributes.
     * @return bool
     */
    public static function needs_script(array $a) {
        $layout = isset($a['layout']) ? $a['layout'] : 'list';
        return 'dropdown' === $layout || ('index' !== $layout && isset($a['groupBy']) && 'none' !== $a['groupBy'] && isset($a['groupStyle']) && 'tabs' === $a['groupStyle']);
    }

    /**
     * Tag Groups' groups, in its order: group ID => label. Read in place from
     * its options, which stay after it is deactivated.
     *
     * @return array<int,string>
     */
    public static function tag_groups() {
        static $groups = null;
        if (null !== $groups) {
            return $groups;
        }
        $groups    = array();
        $ids       = get_option('term_groups');
        $labels    = get_option('term_group_labels');
        $positions = get_option('term_group_positions');
        if (!is_array($ids) || !is_array($labels)) {
            return $groups;
        }
        $positions = is_array($positions) ? $positions : array();
        $order     = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($labels[$id]) && '' !== trim((string) $labels[$id])) {
                $order[$id] = isset($positions[$id]) ? (int) $positions[$id] : PHP_INT_MAX;
            }
        }
        asort($order);
        foreach (array_keys($order) as $id) {
            $groups[$id] = (string) $labels[$id];
        }
        return $groups;
    }

    /**
     * Tag Groups' groups of a term (0 = not in a group).
     *
     * @param int $term_id Term ID.
     * @return int[]
     */
    public static function term_tag_groups($term_id) {
        $value = get_term_meta((int) $term_id, '_cm_term_group_array', true);
        $ids   = array_values(array_filter(array_map('intval', explode(',', (string) $value))));
        return $ids ? $ids : array(0);
    }

    /**
     * Build a term list.
     *
     * Besides the block attributes (blocks/term-list/block.json), the other
     * plugins' displays pass: include and exclude (term IDs), groups (Tag
     * Groups group IDs to show), showUnassigned (Tag Groups' "not assigned"
     * group), postId, title (HTML before the list) and prefix (HTML before
     * the terms of a comma-separated list).
     *
     * @param array    $attributes Attributes.
     * @param string[] $classes    Extra wrapper classes.
     * @param bool     $in_block   Drawn as a block, so block supports (colours, spacing) apply.
     * @return string HTML, or '' when there is nothing to show.
     */
    public static function render(array $attributes, array $classes = array(), $in_block = true) {
        $a        = $attributes;
        $name     = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : 'category';
        $taxonomy = get_taxonomy($name);
        if (!$taxonomy || !is_taxonomy_viewable($taxonomy)) {
            return '';
        }

        $layout = isset($a['layout']) && in_array($a['layout'], self::LAYOUTS, true) ? $a['layout'] : 'list';
        $flat   = in_array($layout, self::FLAT, true);
        $group  = isset($a['groupBy']) && in_array($a['groupBy'], array('parent', 'tag-groups'), true) && 'index' !== $layout ? $a['groupBy'] : 'none';
        if ('parent' === $group && !is_taxonomy_hierarchical($name)) {
            $group = 'none';
        }
        if ('tag-groups' === $group && !self::tag_groups()) {
            $group = 'none';
        }
        $style    = isset($a['groupStyle']) && in_array($a['groupStyle'], array('headings', 'accordion', 'tabs'), true) ? $a['groupStyle'] : 'headings';
        $children = !empty($a['showChildren']) && is_taxonomy_hierarchical($name);
        $count    = !empty($a['showCount']);
        $post     = isset($a['source']) && 'post' === $a['source'];

        $terms = $post ? self::post_terms($a, $name) : self::all_terms($a, $name, $children || 'parent' === $group, $layout);
        $terms = self::pick($terms, $a);

        $style_attr = array();
        $classes    = array_merge(array('wp-block-seoprostack-term-list'), $classes, array('sps-term-list--' . $layout));
        $marker     = isset($a['marker']) && in_array($a['marker'], self::MARKERS, true) ? $a['marker'] : '';
        if ('list' === $layout && '' !== $marker) {
            $classes[] = 'sps-term-list--marker-' . $marker;
        }
        if (!empty($a['pills']) && in_array($layout, array('cloud', 'inline'), true)) {
            $classes[] = 'sps-term-list--pills';
        }
        if ('none' !== $group) {
            $classes[] = 'sps-term-list--groups';
            $classes[] = 'sps-term-list--groups-' . $style;
        }
        if ('dropdown' !== $layout) {
            $link  = self::clean_color(isset($a['linkColor']) ? $a['linkColor'] : '');
            $hover = self::clean_color(isset($a['linkHoverColor']) ? $a['linkHoverColor'] : '');
            if ('' !== $link) {
                $classes[]    = 'has-sps-link-color';
                $style_attr[] = '--sps-term-link:' . $link;
            }
            if ('' !== $hover) {
                $classes[]    = 'has-sps-hover-color';
                $style_attr[] = '--sps-term-link-hover:' . $hover;
            }
            if (isset($a['gap']) && is_numeric($a['gap'])) {
                $classes[]    = 'has-sps-gap';
                $style_attr[] = '--sps-term-gap:' . max(0, min(200, (int) $a['gap'])) . 'px';
            }
        }
        if (in_array($layout, array('grid', 'index'), true)) {
            $columns      = isset($a['columns']) ? max(1, min(6, (int) $a['columns'])) : 3;
            $style_attr[] = '--sps-term-columns:' . $columns;
            $style_attr[] = '--sps-term-columns-small:' . min($columns, 2);
        }

        $classes = array_values(array_unique(array_filter($classes)));
        $wrapper = array('class' => implode(' ', $classes));
        if ($style_attr) {
            $wrapper['style'] = implode(';', $style_attr) . ';';
        }
        $wrapper = $in_block && function_exists('get_block_wrapper_attributes') ? get_block_wrapper_attributes($wrapper) : '';
        if ('' === $wrapper) {
            $wrapper = sprintf('class="%s"', esc_attr(implode(' ', $classes)));
            if ($style_attr) {
                $wrapper .= sprintf(' style="%s"', esc_attr(implode(';', $style_attr) . ';'));
            }
        }

        $title = isset($a['title']) ? wp_kses_post((string) $a['title']) : '';

        if (!$terms) {
            $empty = isset($a['emptyText']) ? trim((string) $a['emptyText']) : '';
            return '' === $empty ? '' : sprintf('<div %s>%s<p class="wp-block-seoprostack-term-list__empty">%s</p></div>', $wrapper, $title, esc_html($empty));
        }

        $ctx = array(
            'layout'   => $layout,
            'count'    => $count,
            'children' => $children && !$flat && 'none' === $group,
            'sizes'    => 'cloud' === $layout ? self::sizes($terms, $a) : array(),
            'labels'   => null,
            'prefix'   => isset($a['prefix']) ? wp_kses_post((string) $a['prefix']) : '',
        );
        if ('grid' === $layout && $count) {
            $type          = !empty($a['postType']) ? get_post_type_object((string) $a['postType']) : null;
            $type          = $type ? $type : get_post_type_object((string) reset($taxonomy->object_type));
            $ctx['labels'] = $type ? $type->labels : null;
        }

        if ('dropdown' === $layout) {
            $html = self::dropdown($terms, $taxonomy, $group, $a, $ctx);
        } elseif ('index' === $layout) {
            $html = self::index($terms, $a, $ctx);
        } elseif ('none' !== $group) {
            $html = self::groups(self::grouped($terms, $group, $a), $style, $ctx);
        } else {
            $html = self::terms_html($terms, $ctx);
        }
        return '' === $html ? '' : sprintf('<div %s>%s%s</div>', $wrapper, $title, $html);
    }

    /**
     * The current post's terms.
     *
     * @param array  $a    Attributes.
     * @param string $name Taxonomy.
     * @return WP_Term[]
     */
    private static function post_terms(array $a, $name) {
        $post_id = !empty($a['postId']) ? (int) $a['postId'] : (int) get_the_ID();
        if ($post_id <= 0) {
            return array();
        }
        $terms = get_the_terms($post_id, $name);
        if (!is_array($terms)) {
            return array();
        }
        if (empty($a['showChildren'])) {
            // "Show child terms" off: only the post's top-level terms.
            $terms = array_filter($terms, static function ($term) {
                return 0 === (int) $term->parent;
            });
        }
        return array_values($terms);
    }

    /**
     * Terms of the taxonomy.
     *
     * @param array  $a        Attributes.
     * @param string $name     Taxonomy.
     * @param bool   $children Include child terms.
     * @param string $layout   Layout.
     * @return WP_Term[]
     */
    private static function all_terms(array $a, $name, $children, $layout) {
        $args = array(
            'taxonomy'   => $name,
            'hide_empty' => empty($a['showEmpty']),
            'number'     => self::MAX_TERMS,
        );
        if (!$children) {
            $args['parent'] = 0;
        }
        if (!empty($a['include'])) {
            $args['include'] = array_map('intval', (array) $a['include']);
        }
        if (!empty($a['exclude'])) {
            $args['exclude'] = array_map('intval', (array) $a['exclude']); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams -- get_terms(), capped at MAX_TERMS, not a post query.
        }
        /**
         * Filters the get_terms() arguments of a Term list.
         *
         * @param array $args       get_terms() arguments.
         * @param array $attributes Block attributes.
         */
        $terms = get_terms(apply_filters('seoprostack_term_list_args', $args, $a));
        // A filter that asks for IDs or names gets no list, not broken links.
        return is_array($terms) ? array_values(array_filter($terms, function ($term) {
            return $term instanceof WP_Term;
        })) : array();
    }

    /**
     * Apply the minimum post count, the number of terms and the order.
     *
     * get_terms() returns terms by name, or in the order set with Order by
     * hand; "name" keeps that order.
     *
     * @param WP_Term[] $terms Terms.
     * @param array     $a     Attributes.
     * @return WP_Term[]
     */
    private static function pick(array $terms, array $a) {
        $min = isset($a['minCount']) ? max(0, (int) $a['minCount']) : 0;
        if ($min > 0) {
            $terms = array_values(array_filter($terms, static function ($term) use ($min) {
                return (int) $term->count >= $min;
            }));
        }
        if (!empty($a['groups']) || !empty($a['showUnassigned'])) {
            $terms = self::in_tag_groups($terms, $a);
        }
        $position = array();
        foreach ($terms as $i => $term) {
            $position[(int) $term->term_id] = $i;
        }
        $number = isset($a['number']) ? max(0, (int) $a['number']) : 0;
        if ($number > 0 && count($terms) > $number) {
            // Keep the most used.
            usort($terms, static function ($x, $y) use ($position) {
                return ((int) $y->count - (int) $x->count) ?: ($position[(int) $x->term_id] - $position[(int) $y->term_id]);
            });
            $terms = array_slice($terms, 0, $number);
            usort($terms, static function ($x, $y) use ($position) {
                return $position[(int) $x->term_id] - $position[(int) $y->term_id];
            });
        }
        $order = isset($a['orderBy']) ? $a['orderBy'] : 'name';
        if ('count' === $order) {
            usort($terms, static function ($x, $y) use ($position) {
                return ((int) $y->count - (int) $x->count) ?: ($position[(int) $x->term_id] - $position[(int) $y->term_id]);
            });
        } elseif ('random' === $order) {
            shuffle($terms);
        }
        return $terms;
    }

    /**
     * Only terms in the chosen Tag Groups groups (or in none, when the "not
     * assigned" group is shown).
     *
     * @param WP_Term[] $terms Terms.
     * @param array     $a     Attributes.
     * @return WP_Term[]
     */
    private static function in_tag_groups(array $terms, array $a) {
        $wanted = array_map('intval', (array) (isset($a['groups']) ? $a['groups'] : array()));
        $none   = !empty($a['showUnassigned']);
        if (!$wanted && !$none) {
            return $terms;
        }
        $wanted = $wanted ? $wanted : array_keys(self::tag_groups());
        return array_values(array_filter($terms, static function ($term) use ($wanted, $none) {
            $in = self::term_tag_groups((int) $term->term_id);
            return array_intersect($in, $wanted) || ($none && array(0) === $in);
        }));
    }

    /**
     * Font sizes (em) for a tag cloud, from fewest to most posts on a log
     * scale, so a few very popular terms do not flatten the rest.
     *
     * @param WP_Term[] $terms Terms.
     * @param array     $a     Attributes.
     * @return array<int,float> term ID => size
     */
    private static function sizes(array $terms, array $a) {
        $small = isset($a['smallest']) && is_numeric($a['smallest']) ? max(0.5, min(4, (float) $a['smallest'])) : 0.875;
        $large = isset($a['largest']) && is_numeric($a['largest']) ? max($small, min(6, (float) $a['largest'])) : 1.75;
        $low   = PHP_INT_MAX;
        $high  = 0;
        foreach ($terms as $term) {
            $low  = min($low, (int) $term->count);
            $high = max($high, (int) $term->count);
        }
        $range = log($high + 1) - log($low + 1);
        $sizes = array();
        foreach ($terms as $term) {
            $share                            = $range > 0 ? (log((int) $term->count + 1) - log($low + 1)) / $range : 0;
            $sizes[(int) $term->term_id] = round($small + ($large - $small) * $share, 3);
        }
        return $sizes;
    }

    /**
     * Terms sorted into groups: [label, link, terms].
     *
     * @param WP_Term[] $terms Terms.
     * @param string    $group parent or tag-groups.
     * @param array     $a     Attributes.
     * @return array[]
     */
    private static function grouped(array $terms, $group, array $a) {
        $groups = array();
        if ('tag-groups' === $group) {
            $wanted = array_map('intval', (array) (isset($a['groups']) ? $a['groups'] : array()));
            foreach (self::tag_groups() as $id => $label) {
                if (!$wanted || in_array($id, $wanted, true)) {
                    $groups[$id] = array('label' => $label, 'link' => '', 'terms' => array());
                }
            }
            $none = array();
            foreach ($terms as $term) {
                $in = self::term_tag_groups((int) $term->term_id);
                foreach ($in as $id) {
                    if (isset($groups[$id])) {
                        $groups[$id]['terms'][] = $term;
                    }
                }
                if (array(0) === $in) {
                    $none[] = $term;
                }
            }
            if ($none && !empty($a['showUnassigned'])) {
                $groups[0] = array('label' => __('Not assigned', 'seoprostack'), 'link' => '', 'terms' => $none);
            }
        } else {
            // By top-level term: each heads its descendants; top-level
            // terms without any go together at the end.
            $by_id = array();
            foreach ($terms as $term) {
                $by_id[(int) $term->term_id] = $term;
            }
            $top = static function ($term) use ($by_id) {
                $guard = 0;
                while ((int) $term->parent && isset($by_id[(int) $term->parent]) && $guard++ < 50) {
                    $term = $by_id[(int) $term->parent];
                }
                return $term;
            };
            $alone = array();
            foreach ($terms as $term) {
                if (0 === (int) $term->parent || !isset($by_id[(int) $term->parent])) {
                    continue;
                }
                $head = $top($term);
                $id   = (int) $head->term_id;
                if (!isset($groups[$id])) {
                    $link        = get_term_link($head);
                    $groups[$id] = array('label' => $head->name, 'link' => is_wp_error($link) ? '' : $link, 'terms' => array());
                }
                $groups[$id]['terms'][] = $term;
            }
            foreach ($terms as $term) {
                $id = (int) $term->term_id;
                if ((0 === (int) $term->parent || !isset($by_id[(int) $term->parent])) && !isset($groups[$id])) {
                    $alone[] = $term;
                }
            }
            // Keep the heads in the terms' order.
            $ordered = array();
            foreach ($terms as $term) {
                $id = (int) $term->term_id;
                if (isset($groups[$id])) {
                    $ordered[$id] = $groups[$id];
                }
            }
            $groups = $ordered;
            if ($alone) {
                $groups[-1] = array('label' => __('Other', 'seoprostack'), 'link' => '', 'terms' => $alone);
            }
        }
        return array_filter($groups, static function ($g) {
            return !empty($g['terms']);
        });
    }

    /**
     * Groups under headings, in an accordion, or in tabs (headings until
     * the view script turns them into tabs).
     *
     * @param array[] $groups Groups.
     * @param string  $style  headings, accordion or tabs.
     * @param array   $ctx    Render context.
     * @return string
     */
    private static function groups(array $groups, $style, array $ctx) {
        if (!$groups) {
            return '';
        }
        $base = function_exists('wp_unique_id') ? wp_unique_id('sps-terms-') : 'sps-terms-' . wp_rand();
        $html = '';
        $i    = 0;
        foreach ($groups as $group) {
            $label = esc_html($group['label']);
            $inner = self::terms_html($group['terms'], $ctx);
            if ('accordion' === $style) {
                $html .= '<details class="wp-block-seoprostack-term-list__group"' . (0 === $i ? ' open' : '') . '><summary class="wp-block-seoprostack-term-list__group-title">' . $label . '</summary>' . $inner . '</details>';
            } else {
                $id    = $base . '-' . $i;
                $title = '' !== $group['link'] ? '<a href="' . esc_url($group['link']) . '">' . $label . '</a>' : $label;
                $html .= '<section class="wp-block-seoprostack-term-list__group" id="' . esc_attr($id) . '" data-sps-tab-label="' . esc_attr($group['label']) . '"><h3 class="wp-block-seoprostack-term-list__group-title">' . $title . '</h3>' . $inner . '</section>';
            }
            ++$i;
        }
        return 'tabs' === $style ? '<div class="wp-block-seoprostack-term-list__tabs" data-sps-tabs>' . $html . '</div>' : $html;
    }

    /**
     * Terms in the layout (list, grid, cloud or inline).
     *
     * @param WP_Term[] $terms Terms.
     * @param array     $ctx   Render context.
     * @return string
     */
    private static function terms_html(array $terms, array $ctx) {
        if ($ctx['children']) {
            $ids = array();
            foreach ($terms as $term) {
                $ids[(int) $term->term_id] = true;
            }
            $tree = array();
            foreach ($terms as $term) {
                $parent          = isset($ids[(int) $term->parent]) ? (int) $term->parent : 0;
                $tree[$parent][] = $term;
            }
        } else {
            $tree = array(0 => $terms);
        }
        $html = self::items($tree, 0, $ctx, 'wp-block-seoprostack-term-list__list');
        if ('inline' === $ctx['layout'] && '' !== $ctx['prefix'] && '' !== $html) {
            $html = '<span class="wp-block-seoprostack-term-list__prefix">' . $ctx['prefix'] . '</span> ' . $html;
        }
        return $html;
    }

    /**
     * A level of the list.
     *
     * @param array<int,WP_Term[]> $tree   Terms by parent ID.
     * @param int                  $parent Parent term ID.
     * @param array                $ctx    Render context.
     * @param string               $class  List class.
     * @return string
     */
    private static function items(array $tree, $parent, array $ctx, $class) {
        if (empty($tree[$parent])) {
            return '';
        }
        $html = '';
        foreach ($tree[$parent] as $term) {
            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }
            $size  = isset($ctx['sizes'][(int) $term->term_id]) ? ' style="font-size:' . esc_attr((string) $ctx['sizes'][(int) $term->term_id]) . 'em"' : '';
            $html .= '<li class="wp-block-seoprostack-term-list__item"' . $size . '><a class="wp-block-seoprostack-term-list__link" href="' . esc_url($link) . '">' . esc_html($term->name) . '</a>';
            if ($ctx['count']) {
                $number = (int) $term->count;
                $labels = $ctx['labels'];
                $text   = $labels && 0 === $parent
                    ? number_format_i18n($number) . ' ' . (1 === $number ? $labels->singular_name : $labels->name)
                    : '(' . number_format_i18n($number) . ')';
                $html  .= ' <span class="wp-block-seoprostack-term-list__count">' . esc_html($text) . '</span>';
            }
            if ($ctx['children']) {
                $html .= self::items($tree, (int) $term->term_id, $ctx, 'wp-block-seoprostack-term-list__children');
            }
            $html .= '</li>';
        }
        return '' === $html ? '' : '<ul class="' . esc_attr($class) . '">' . $html . '</ul>';
    }

    /**
     * A drop-down that opens the chosen term; groups become option groups.
     *
     * @param WP_Term[]   $terms    Terms.
     * @param WP_Taxonomy $taxonomy Taxonomy.
     * @param string      $group    Grouping.
     * @param array       $a        Attributes.
     * @param array       $ctx      Render context.
     * @return string
     */
    private static function dropdown(array $terms, $taxonomy, $group, array $a, array $ctx) {
        $id   = function_exists('wp_unique_id') ? wp_unique_id('sps-term-select-') : 'sps-term-select-' . wp_rand();
        $html = sprintf('<label class="screen-reader-text" for="%s">%s</label>', esc_attr($id), esc_html($taxonomy->labels->name));
        $html .= sprintf('<select id="%s" class="wp-block-seoprostack-term-list__select" data-sps-term-select><option value="">%s</option>', esc_attr($id), esc_html($taxonomy->labels->name));
        if ('none' !== $group) {
            foreach (self::grouped($terms, $group, $a) as $g) {
                $html .= '<optgroup label="' . esc_attr($g['label']) . '">' . self::options(array(0 => $g['terms']), 0, 0, $ctx['count']) . '</optgroup>';
            }
        } else {
            $tree = array();
            $ids  = array();
            foreach ($terms as $term) {
                $ids[(int) $term->term_id] = true;
            }
            foreach ($terms as $term) {
                $parent          = !empty($a['showChildren']) && isset($ids[(int) $term->parent]) ? (int) $term->parent : 0;
                $tree[$parent][] = $term;
            }
            $html .= self::options($tree, 0, 0, $ctx['count']);
        }
        return $html . '</select>';
    }

    /**
     * Drop-down options, children indented under their parent.
     *
     * @param array<int,WP_Term[]> $tree   Terms by parent ID.
     * @param int                  $parent Parent term ID.
     * @param int                  $depth  Depth.
     * @param bool                 $count  Show post counts.
     * @return string
     */
    private static function options(array $tree, $parent, $depth, $count) {
        if (empty($tree[$parent])) {
            return '';
        }
        $html = '';
        foreach ($tree[$parent] as $term) {
            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }
            $text  = str_repeat("\u{2014} ", $depth) . $term->name . ($count ? ' (' . number_format_i18n((int) $term->count) . ')' : '');
            $html .= '<option value="' . esc_url($link) . '">' . esc_html($text) . '</option>';
            $html .= self::options($tree, (int) $term->term_id, $depth + 1, $count);
        }
        return $html;
    }

    /**
     * An A–Z index: terms under their first letter, with links to each
     * letter above.
     *
     * @param WP_Term[] $terms Terms.
     * @param array     $a     Attributes.
     * @param array     $ctx   Render context.
     * @return string
     */
    private static function index(array $terms, array $a, array $ctx) {
        $letters = array();
        foreach ($terms as $term) {
            $first = function_exists('mb_substr') ? mb_substr(remove_accents($term->name), 0, 1) : substr(remove_accents($term->name), 0, 1);
            $first = function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
            $key   = preg_match('/^\p{L}$/u', $first) ? $first : '#';
            $letters[$key][] = $term;
        }
        uksort($letters, static function ($x, $y) {
            if ('#' === $x || '#' === $y) {
                return '#' === $x ? ('#' === $y ? 0 : 1) : -1;
            }
            return strcmp($x, $y);
        });
        foreach ($letters as $key => $list) {
            usort($list, static function ($x, $y) {
                return strnatcasecmp(remove_accents($x->name), remove_accents($y->name));
            });
            $letters[$key] = $list;
        }
        $base = function_exists('wp_unique_id') ? wp_unique_id('sps-index-') : 'sps-index-' . wp_rand();
        $nav  = '';
        $body = '';
        $ctx['layout'] = 'list';
        foreach ($letters as $key => $list) {
            $id    = $base . '-' . ('#' === $key ? 'other' : rawurlencode((string) $key));
            $nav  .= '<li><a href="#' . esc_attr($id) . '">' . esc_html((string) $key) . '</a></li>';
            $body .= '<section class="wp-block-seoprostack-term-list__letter" id="' . esc_attr($id) . '"><h3 class="wp-block-seoprostack-term-list__group-title">' . esc_html((string) $key) . '</h3>' . self::terms_html($list, $ctx) . '</section>';
        }
        $show_nav = !isset($a['indexNav']) || !empty($a['indexNav']);
        return ($show_nav && count($letters) > 1 ? '<nav class="wp-block-seoprostack-term-list__letters" aria-label="' . esc_attr__('Letters', 'seoprostack') . '"><ul>' . $nav . '</ul></nav>' : '')
            . '<div class="wp-block-seoprostack-term-list__index">' . $body . '</div>';
    }

    /**
     * A CSS colour: hex, rgb()/rgba(), a CSS variable, or a palette colour
     * stored as "var:preset|color|slug". Palette colours become the preset
     * variable, with the slug kebab-cased as core does, so themes that swap
     * palettes for dark mode (Kadence's "theme-palette3" →
     * --wp--preset--color--theme-palette-3) change the colour too.
     *
     * @param mixed $color Colour.
     * @return string Clean colour, or ''.
     */
    public static function clean_color($color) {
        $color = trim((string) $color);
        if (0 === strpos($color, 'var:preset|color|')) {
            $slug = preg_replace('/[^a-z0-9-]/i', '', _wp_to_kebab_case(substr($color, 17)));
            return '' === $slug ? '' : 'var(--wp--preset--color--' . $slug . ')';
        }
        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color)
            || preg_match('/^rgba?\(\s*[0-9.%\s,\/]+\)$/i', $color)
            || preg_match('/^var\(--[a-z0-9_-]+\)$/i', $color)) {
            return $color;
        }
        return '';
    }
}
