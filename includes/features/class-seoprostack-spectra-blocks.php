<?php
/**
 * Spectra block replacements.
 *
 * Lets a site stop using Spectra (Ultimate Addons for Gutenberg) without
 * losing content:
 * - a Term list block (seoprostack/term-list) replaces Spectra's Taxonomy
 *   List, and existing uagb/taxonomy-list blocks are drawn by it, since that
 *   block saves no HTML and would otherwise show nothing;
 * - other Spectra blocks keep their saved HTML; a small stylesheet keeps
 *   images, buttons and testimonials presentable once Spectra's CSS is gone;
 * - in the editor, Spectra blocks get a Convert button that rebuilds them as
 *   core blocks (heading, image, buttons, quote) or a Term list. Core's own
 *   serializer writes the markup, and nothing changes until the post is saved;
 *   the Spectra version is stored as a revision first (keep_original()).
 *
 * Spectra's settings are block styles, so there is nothing to import; the
 * feature switches on while Spectra is active and its blocks are in use.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Spectra_Blocks extends SEOProStack_Feature {

    const KEY = 'spectra_blocks';

    /** Term list block. */
    const BLOCK = 'seoprostack/term-list';

    /** Spectra's Taxonomy List, drawn by the Term list while Spectra is off. */
    const LEGACY = 'uagb/taxonomy-list';

    /** Plugin folder of Spectra. */
    const SPECTRA = 'ultimate-addons-for-gutenberg';

    /** Editor script handle. */
    const HANDLE = 'seoprostack-spectra-convert';

    /** Stylesheet for Spectra blocks that keep their saved HTML. */
    const LEGACY_STYLE = 'seoprostack-spectra-legacy';

    /** List markers offered by the block ('' = theme default). */
    const MARKERS = array('', 'none', 'disc', 'circle', 'square', 'decimal');

    /** Most terms a list shows (filterable through seoprostack_term_list_args). */
    const MAX_TERMS = 1000;

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
                'label'       => __('Spectra block replacements', 'seoprostack'),
                'description' => __('Adds a Term list block and keeps pages built with Spectra working after it is deactivated. In the editor, Spectra blocks get a Convert button that turns them into core blocks.', 'seoprostack'),
                'replaces'    => array(self::SPECTRA => 'Spectra'),
            ),
            'spectra_blocks_styles' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Keep old Spectra blocks looking right', 'seoprostack'),
                'description' => __('Until they are converted, pages with Spectra images, buttons or testimonials get a small stylesheet in place of Spectra’s.', 'seoprostack'),
            ),
        );
    }

    /**
     * Switch on while Spectra is active and its blocks are in use, so the
     * pages keep working the moment Spectra is deactivated.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (isset(self::active_plugins()[self::SPECTRA]) && self::posts_with_blocks(1)) {
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
            return;
        }
        // boot() runs on init, which is where blocks are registered.
        register_block_type(SEOPROSTACK_DIR . 'blocks/term-list');
        if (!WP_Block_Type_Registry::get_instance()->is_registered(self::LEGACY)) {
            // No attributes are declared, so all of Spectra's stored ones
            // reach render_legacy() unchanged.
            register_block_type(self::LEGACY, array('render_callback' => array(__CLASS__, 'render_legacy')));
        }
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_assets'));
        add_action('pre_post_update', array(__CLASS__, 'keep_original'), 10, 2);
        if (SEOProStack_Settings::get('spectra_blocks_styles')) {
            add_filter('render_block', array(__CLASS__, 'legacy_styles'), 10, 2);
        }
    }

    /**
     * The editor script that converts Spectra blocks.
     */
    public static function editor_assets() {
        $file = 'blocks/spectra/convert.js';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_script(
            self::HANDLE,
            SEOPROSTACK_URL . $file,
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-data', 'wp-hooks', 'wp-i18n', 'wp-block-serialization-default-parser'),
            $ver,
            true
        );
        wp_set_script_translations(self::HANDLE, 'seoprostack');
    }

    /**
     * Keep the Spectra version of a post as a revision before it changes.
     *
     * WordPress stores a revision of each version as it is saved, so a post
     * that was never edited after it was created (an import, for example)
     * has no revision of its current content. Converting its blocks would
     * then leave no way back. Runs before the update, while the database
     * still holds the old content; wp_save_post_revision() skips the save
     * when the latest revision already matches it.
     *
     * @param int   $post_id Post ID.
     * @param array $data    Unslashed post data about to be saved.
     */
    public static function keep_original($post_id, $data) {
        $post = get_post($post_id);
        if (!$post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status
            || false === strpos($post->post_content, '<!-- wp:uagb/')
            || !isset($data['post_content']) || $data['post_content'] === $post->post_content
            || !wp_revisions_enabled($post)) {
            return;
        }
        wp_save_post_revision($post->ID);
    }

    /**
     * Add the stylesheet for Spectra blocks that keep their saved HTML, once,
     * on pages that have one.
     *
     * @param string $content Rendered block.
     * @param array  $block   Parsed block.
     * @return string
     */
    public static function legacy_styles($content, $block) {
        static $done = false;
        if ($done || empty($block['blockName']) || 0 !== strpos($block['blockName'], 'uagb/') || self::LEGACY === $block['blockName']) {
            return $content;
        }
        $done = true;
        $file = 'blocks/spectra/legacy.css';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_style(self::LEGACY_STYLE, SEOPROSTACK_URL . $file, array(), $ver);
        return $content;
    }

    /**
     * Draw a Spectra Taxonomy List with the Term list, using Spectra's
     * defaults where the block does not store a value.
     *
     * @param array $attributes Spectra's block attributes.
     * @return string
     */
    public static function render_legacy($attributes) {
        $a      = (array) $attributes;
        $layout = isset($a['layout']) && 'list' === $a['layout'] ? 'list' : 'grid';
        if ('list' === $layout && isset($a['listDisplayStyle']) && 'dropdown' === $a['listDisplayStyle']) {
            $layout = 'dropdown';
        }
        $list = 'list' === $layout;

        $mapped = array(
            'taxonomy'       => isset($a['taxonomyType']) ? (string) $a['taxonomyType'] : 'category',
            'postType'       => isset($a['postType']) ? (string) $a['postType'] : '',
            'layout'         => $layout,
            'showCount'      => isset($a['showCount']) ? (bool) $a['showCount'] : true,
            'showEmpty'      => !empty($a['showEmptyTaxonomy']),
            // Spectra shows child terms only in lists with hierarchy on.
            'showChildren'   => $list && !empty($a['showhierarchy']),
            'columns'        => isset($a['columns']) ? (int) $a['columns'] : 3,
            'marker'         => $list ? (isset($a['listStyle']) ? (string) $a['listStyle'] : 'disc') : '',
            'linkColor'      => $list ? (isset($a['listTextColor']) ? $a['listTextColor'] : '') : (isset($a['titleColor']) ? $a['titleColor'] : ''),
            'linkHoverColor' => $list && isset($a['hoverlistTextColor']) ? $a['hoverlistTextColor'] : '',
            'emptyText'      => isset($a['noTaxDisplaytext']) ? (string) $a['noTaxDisplaytext'] : '',
        );
        if ($list && isset($a['listBottomMargin'])) {
            $mapped['gap'] = $a['listBottomMargin'];
        } elseif ('grid' === $layout && isset($a['rowGap'])) {
            $mapped['gap'] = $a['rowGap'];
        }

        $classes = array('wp-block-seoprostack-term-list');
        if (!empty($a['block_id'])) {
            $classes[] = 'uagb-block-' . sanitize_html_class((string) $a['block_id']);
        }

        // The Term list's styles and drop-down script are registered with
        // its block type, which is not the block being drawn here.
        $type = WP_Block_Type_Registry::get_instance()->get_registered(self::BLOCK);
        if ($type) {
            foreach (isset($type->style_handles) ? (array) $type->style_handles : array() as $handle) {
                wp_enqueue_style($handle);
            }
            if ('dropdown' === $layout) {
                foreach (isset($type->view_script_handles) ? (array) $type->view_script_handles : array() as $handle) {
                    wp_enqueue_script($handle);
                }
            }
        }

        return self::render_terms($mapped, $classes);
    }

    /**
     * Build a term list.
     *
     * @param array    $attributes Term list attributes (see blocks/term-list/block.json).
     * @param string[] $classes    Extra wrapper classes.
     * @return string HTML, or '' when there is nothing to show.
     */
    public static function render_terms(array $attributes, array $classes = array()) {
        $a        = $attributes;
        $name     = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : 'category';
        $taxonomy = get_taxonomy($name);
        if (!$taxonomy || !is_taxonomy_viewable($taxonomy)) {
            return '';
        }

        $layout   = isset($a['layout']) && in_array($a['layout'], array('list', 'grid', 'dropdown'), true) ? $a['layout'] : 'list';
        $children = !empty($a['showChildren']) && is_taxonomy_hierarchical($name);
        $count    = !empty($a['showCount']);

        $args = array(
            'taxonomy'   => $name,
            'hide_empty' => empty($a['showEmpty']),
            'number'     => self::MAX_TERMS,
        );
        if (!$children) {
            $args['parent'] = 0;
        }
        /**
         * Filters the get_terms() arguments of a Term list.
         *
         * @param array $args       get_terms() arguments.
         * @param array $attributes Block attributes.
         */
        $terms = get_terms(apply_filters('seoprostack_term_list_args', $args, $a));
        $terms = is_array($terms) ? $terms : array();

        $style   = array();
        $classes = array_merge($classes, array('sps-term-list--' . $layout));
        $marker  = isset($a['marker']) && in_array($a['marker'], self::MARKERS, true) ? $a['marker'] : '';
        if ('list' === $layout && '' !== $marker) {
            $classes[] = 'sps-term-list--marker-' . $marker;
        }
        if ('dropdown' !== $layout) {
            $link  = self::clean_color(isset($a['linkColor']) ? $a['linkColor'] : '');
            $hover = self::clean_color(isset($a['linkHoverColor']) ? $a['linkHoverColor'] : '');
            if ('' !== $link) {
                $classes[] = 'has-sps-link-color';
                $style[]   = '--sps-term-link:' . $link;
            }
            if ('' !== $hover) {
                $classes[] = 'has-sps-hover-color';
                $style[]   = '--sps-term-link-hover:' . $hover;
            }
            if (isset($a['gap']) && is_numeric($a['gap'])) {
                $classes[] = 'has-sps-gap';
                $style[]   = '--sps-term-gap:' . max(0, min(200, (int) $a['gap'])) . 'px';
            }
        }
        if ('grid' === $layout) {
            $columns = isset($a['columns']) ? max(1, min(6, (int) $a['columns'])) : 3;
            $style[] = '--sps-term-columns:' . $columns;
            $style[] = '--sps-term-columns-small:' . min($columns, 2);
        }

        $wrapper = array('class' => implode(' ', $classes));
        if ($style) {
            $wrapper['style'] = implode(';', $style) . ';';
        }
        $wrapper = function_exists('get_block_wrapper_attributes') ? get_block_wrapper_attributes($wrapper) : '';
        if ('' === $wrapper) {
            $wrapper = sprintf('class="%s"', esc_attr(implode(' ', $classes)));
        }

        if (!$terms) {
            $empty = isset($a['emptyText']) ? trim((string) $a['emptyText']) : '';
            return '' === $empty ? '' : sprintf('<div %s><p class="wp-block-seoprostack-term-list__empty">%s</p></div>', $wrapper, esc_html($empty));
        }

        // Group by parent; terms whose parent is not listed start a branch.
        $ids = array();
        foreach ($terms as $term) {
            $ids[(int) $term->term_id] = true;
        }
        $tree = array();
        foreach ($terms as $term) {
            $parent          = $children && isset($ids[(int) $term->parent]) ? (int) $term->parent : 0;
            $tree[$parent][] = $term;
        }

        if ('dropdown' === $layout) {
            $id   = function_exists('wp_unique_id') ? wp_unique_id('sps-term-select-') : 'sps-term-select-' . wp_rand();
            $html = sprintf('<label class="screen-reader-text" for="%s">%s</label>', esc_attr($id), esc_html($taxonomy->labels->name));
            $html .= sprintf('<select id="%s" class="wp-block-seoprostack-term-list__select" data-sps-term-select><option value="">%s</option>', esc_attr($id), esc_html($taxonomy->labels->name));
            $html .= self::options($tree, 0, 0, $count);
            $html .= '</select>';
            return sprintf('<div %s>%s</div>', $wrapper, $html);
        }

        $labels = null;
        if ('grid' === $layout && $count) {
            $type   = !empty($a['postType']) ? get_post_type_object((string) $a['postType']) : null;
            $type   = $type ? $type : get_post_type_object((string) reset($taxonomy->object_type));
            $labels = $type ? $type->labels : null;
        }

        return sprintf('<div %s>%s</div>', $wrapper, self::items($tree, 0, $count, $labels, 'wp-block-seoprostack-term-list__list'));
    }

    /**
     * A level of the list.
     *
     * @param array<int,WP_Term[]> $tree   Terms by parent ID.
     * @param int                  $parent Parent term ID.
     * @param bool                 $count  Show post counts.
     * @param object|null          $labels Post type labels for grid counts, or null.
     * @param string               $class  List class.
     * @return string
     */
    private static function items(array $tree, $parent, $count, $labels, $class) {
        if (empty($tree[$parent])) {
            return '';
        }
        $html = '<ul class="' . esc_attr($class) . '">';
        foreach ($tree[$parent] as $term) {
            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }
            $html .= '<li class="wp-block-seoprostack-term-list__item"><a class="wp-block-seoprostack-term-list__link" href="' . esc_url($link) . '">' . esc_html($term->name) . '</a>';
            if ($count) {
                $number = (int) $term->count;
                $text   = $labels && 0 === $parent
                    ? number_format_i18n($number) . ' ' . (1 === $number ? $labels->singular_name : $labels->name)
                    : '(' . number_format_i18n($number) . ')';
                $html  .= ' <span class="wp-block-seoprostack-term-list__count">' . esc_html($text) . '</span>';
            }
            $html .= self::items($tree, (int) $term->term_id, $count, $labels, 'wp-block-seoprostack-term-list__children');
            $html .= '</li>';
        }
        return $html . '</ul>';
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

    /**
     * Posts that contain Spectra blocks, newest change first.
     *
     * @param int $limit Most rows.
     * @return object[] ID, post_title, post_type, post_status.
     */
    public static function posts_with_blocks($limit = 50) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off scan for the settings panel and the upgrade import.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status IN ('publish', 'future', 'draft', 'pending', 'private') AND post_content LIKE %s ORDER BY post_modified DESC LIMIT %d",
            '%' . $wpdb->esc_like('<!-- wp:uagb/') . '%',
            max(1, (int) $limit)
        ));
        return is_array($rows) ? $rows : array();
    }

    /**
     * List the posts that still use Spectra blocks, at the top of the
     * options panel.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field = array()) {
        if (self::KEY !== $key || !self::switched_on()) {
            return;
        }
        $limit = 50;
        $rows  = self::posts_with_blocks($limit + 1);
        echo '<div class="sps-panel-note">';
        if (!$rows) {
            echo '<p>' . esc_html__('No posts use Spectra blocks.', 'seoprostack') . '</p></div>';
            return;
        }
        if (self::replaced_active(self::KEY)) {
            echo '<p>' . esc_html__('These use Spectra blocks. Once Spectra is deactivated, open each one in the editor, choose Convert on its Spectra blocks, check the result and save. Saving keeps a revision.', 'seoprostack') . '</p>';
        } else {
            echo '<p>' . esc_html__('These still use Spectra blocks. Open each one in the editor, choose Convert on its Spectra blocks, check the result and save. Saving keeps a revision.', 'seoprostack') . '</p>';
        }
        echo '<ul class="sps-panel-list">';
        foreach (array_slice($rows, 0, $limit) as $row) {
            $title = '' !== trim((string) $row->post_title) ? $row->post_title : __('(no title)', 'seoprostack');
            $type  = get_post_type_object($row->post_type);
            $state = get_post_status_object($row->post_status);
            $meta  = trim(($type ? $type->labels->singular_name : $row->post_type) . ', ' . ($state ? $state->label : $row->post_status), ', ');
            $link  = current_user_can('edit_post', (int) $row->ID) ? get_edit_post_link((int) $row->ID) : '';
            echo '<li>';
            echo $link ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>' : esc_html($title);
            echo ' <span class="description">(' . esc_html($meta) . ')</span></li>';
        }
        echo '</ul>';
        if (count($rows) > $limit) {
            /* translators: %d: number of posts listed */
            echo '<p>' . esc_html(sprintf(__('Showing the %d most recently changed.', 'seoprostack'), $limit)) . '</p>';
        }
        echo '</div>';
    }
}
