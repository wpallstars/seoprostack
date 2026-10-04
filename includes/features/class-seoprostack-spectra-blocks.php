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
        SEOProStack_Term_List::register();
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
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-data', 'wp-hooks', 'wp-i18n', 'wp-block-serialization-default-parser', 'wp-dom'),
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

        $classes = array();
        if (!empty($a['block_id'])) {
            $classes[] = 'uagb-block-' . sanitize_html_class((string) $a['block_id']);
        }

        // The Term list's styles and drop-down script are registered with
        // its block type, which is not the block being drawn here.
        SEOProStack_Term_List::enqueue_assets('dropdown' === $layout);

        return self::render_terms($mapped, $classes);
    }

    /**
     * Build a term list (kept for code that calls it; see SEOProStack_Term_List).
     *
     * @param array    $attributes Term list attributes (see blocks/term-list/block.json).
     * @param string[] $classes    Extra wrapper classes.
     * @return string HTML, or '' when there is nothing to show.
     */
    public static function render_terms(array $attributes, array $classes = array()) {
        return SEOProStack_Term_List::render($attributes, $classes);
    }

    /**
     * A CSS colour (kept for code that calls it; see SEOProStack_Term_List).
     *
     * @param mixed $color Colour.
     * @return string Clean colour, or ''.
     */
    public static function clean_color($color) {
        return SEOProStack_Term_List::clean_color($color);
    }

    /**
     * Posts that contain Spectra blocks, newest change first.
     *
     * @param int $limit Most rows.
     * @return array<int, object{ID: string, post_title: string, post_type: string, post_status: string}>
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
