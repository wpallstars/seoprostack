<?php
/**
 * Dark mode image contrast.
 *
 * Logos, icons and line art with a transparent background are usually made
 * for a light page. When the Kadence Pro dark mode switcher (or a theme that
 * switches palettes the same way) turns the page dark, a dark logo on a dark
 * section all but disappears. This measures each such image against the
 * background it really sits on, and in dark mode only, recolours the ones
 * that are hard to see with a CSS filter. Photos and images without
 * transparency are left alone.
 *
 * The work is in assets/dark-image-contrast.js, loaded in the footer only on
 * pages whose body has a colour switch class (color-switch-light or
 * color-switch-dark). Nothing is stored and nothing is sent anywhere.
 *
 * Keep colours in dark mode: a picture ticked in the Media Library is left
 * alone wherever it is used (any size, WebP/AVIF copy or CSS background);
 * the toggle in a block's sidebar adds the class seoprostack-keep-colours
 * to that block.
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

class SEOProStack_Dark_Image_Contrast extends SEOProStack_Feature {

    const KEY = 'dark_image_contrast';

    const HANDLE = 'seoprostack-dark-image-contrast';

    const WATERMARKS = 'dark_image_contrast_watermarks';

    /** Attachment meta: 1 when the picture keeps its colours in dark mode. */
    const META = '_seoprostack_keep_colours';

    /** Option: upload-relative stems (no size, -scaled or extension) of those pictures. */
    const KEPT = 'seoprostack_keep_colours';

    /** Class that keeps an image, or every image in a block, as it is. */
    const KEEP_CLASS = 'seoprostack-keep-colours';

    /** Whether the page's body has a dark mode switch class. @var bool */
    private static $switcher = false;

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
                'tab'         => 'media',
                'label'       => __('Dark mode image contrast', 'seoprostack'),
                'description' => __('In Kadence’s dark mode, transparent logos, icons and drawings that would be hard to see on the background behind them are lightened or darkened to stand out. Photos and images without transparency are left alone. To keep a picture as it is everywhere, tick Keep colours in dark mode in its Media Library details; for one block, turn on Keep colours in dark mode in the block’s sidebar. Nothing changes without the Kadence Pro dark mode switcher.', 'seoprostack'),
            ),
            self::WATERMARKS => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Keep watermarks subtle', 'seoprostack'),
                'description' => __('Logos and drawings shown faintly on purpose (under 75% opacity, such as a row or column background overlay) stay faint in dark mode: a white watermark that would stand out on a dark page is made fainter, and one that would vanish is lightened, then made faint. Keep colours in dark mode (picture or block) keeps one as it is.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // Keep colours in dark mode: Media Library details (list, grid and
        // media window) and the block sidebar.
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'attachment_field'), 10, 2);
        add_filter('attachment_fields_to_save', array(__CLASS__, 'attachment_save'), 10, 2);
        add_action('delete_attachment', array(__CLASS__, 'attachment_deleted'));
        add_action('updated_post_meta', array(__CLASS__, 'file_changed'), 10, 3);
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_assets'));
        if (is_admin()) {
            return;
        }
        // Last, after Kadence Pro adds its classes.
        add_filter('body_class', array(__CLASS__, 'body_class'), PHP_INT_MAX);
        // Before wp_print_footer_scripts (priority 20) prints footer scripts.
        add_action('wp_footer', array(__CLASS__, 'enqueue'), 5);
    }

    /**
     * Note whether the theme switches between light and dark palettes.
     *
     * @param string[] $classes Body classes.
     * @return string[] Unchanged.
     */
    public static function body_class($classes) {
        self::$switcher = (bool) array_intersect(array('color-switch-dark', 'color-switch-light'), (array) $classes);
        return $classes;
    }

    /**
     * Load the script on pages with a dark mode switcher.
     */
    public static function enqueue() {
        if (!self::$switcher) {
            return;
        }
        $js = 'assets/dark-image-contrast.js';
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $js, array(), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        $config = array('watermarks' => (bool) SEOProStack_Settings::get(self::WATERMARKS));
        $kept   = get_option(self::KEPT, array());
        if (is_array($kept) && $kept) {
            $config['keep'] = array_values($kept);
        }
        wp_add_inline_script(self::HANDLE, 'window.seoprostackDarkImageContrast = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * Keep colours in dark mode, in a picture's details.
     *
     * @param array   $fields Fields.
     * @param WP_Post $post   Attachment.
     * @return array
     */
    public static function attachment_field($fields, $post) {
        if (!self::is_picture($post->ID)) {
            return $fields;
        }
        $name = 'attachments[' . (int) $post->ID . ']';
        $html = '<input type="hidden" name="' . esc_attr($name . '[seoprostack_keep_colours_shown]') . '" value="1">'
            . '<label><input type="checkbox" name="' . esc_attr($name . '[seoprostack_keep_colours]') . '" value="1"'
            . checked((bool) get_post_meta($post->ID, self::META, true), true, false) . '> '
            . esc_html__('Keep colours in dark mode', 'seoprostack') . '</label>';

        $fields['seoprostack_keep_colours'] = array(
            'label' => __('Dark mode', 'seoprostack'),
            'input' => 'html',
            'html'  => $html,
            'helps' => __('Shown as it is in dark mode wherever it is used, also as a background.', 'seoprostack'),
        );
        return $fields;
    }

    /**
     * Save Keep colours in dark mode from a picture's details.
     *
     * @param array $post       Attachment data.
     * @param array $attachment Submitted fields.
     * @return array Unchanged.
     */
    public static function attachment_save($post, $attachment) {
        // Only forms that showed the field (an unticked box sends nothing).
        $id = isset($post['ID']) ? (int) $post['ID'] : 0;
        if (!$id || !is_array($attachment) || empty($attachment['seoprostack_keep_colours_shown']) || !self::is_picture($id)) {
            return $post;
        }
        $keep = !empty($attachment['seoprostack_keep_colours']);
        if ($keep !== (bool) get_post_meta($id, self::META, true)) {
            if ($keep) {
                update_post_meta($id, self::META, 1);
            } else {
                delete_post_meta($id, self::META);
            }
            self::rebuild();
        }
        return $post;
    }

    /**
     * Forget a deleted picture.
     *
     * @param int $id Attachment ID.
     */
    public static function attachment_deleted($id) {
        if (!get_post_meta($id, self::META, true)) {
            return;
        }
        delete_post_meta($id, self::META);
        self::rebuild();
    }

    /**
     * A kept picture's file was replaced or renamed.
     *
     * @param int    $meta_id   Meta ID.
     * @param int    $object_id Post ID.
     * @param string $meta_key  Meta key.
     */
    public static function file_changed($meta_id, $object_id, $meta_key) {
        unset($meta_id);
        if ('_wp_attached_file' === $meta_key && get_post_meta($object_id, self::META, true)) {
            self::rebuild();
        }
    }

    /**
     * Rebuild the list of kept pictures the script matches addresses with,
     * and clear LiteSpeed Cache's pages, which hold the old list.
     */
    public static function rebuild() {
        $ids = get_posts(
            array(
                'post_type'        => 'attachment',
                'post_status'      => 'any',
                'fields'           => 'ids',
                'posts_per_page'   => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- IDs of ticked pictures only, when a box is ticked or cleared.
                'no_found_rows'    => true,
                'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only when a box is ticked or cleared.
            )
        );
        $stems = array();
        foreach ($ids as $id) {
            $file = (string) get_post_meta($id, '_wp_attached_file', true);
            if ('' !== $file) {
                $stems[] = self::stem($file);
            }
        }
        $stems = array_values(array_unique($stems));
        sort($stems);
        if ($stems) {
            update_option(self::KEPT, $stems, true);
        } else {
            delete_option(self::KEPT);
        }
        do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.
    }

    /**
     * Upload-relative file name without -scaled, -rotated or extension, so
     * every size and WebP/AVIF copy of the picture matches.
     *
     * @param string $file Upload-relative file (2026/10/logo-scaled.png).
     * @return string 2026/10/logo
     */
    public static function stem($file) {
        $file = (string) preg_replace('/\.[a-z0-9]+$/i', '', $file);
        return (string) preg_replace('/-(?:scaled|rotated)$/', '', $file);
    }

    /**
     * Whether the attachment is a picture (SVG included).
     *
     * @param int $id Attachment ID.
     * @return bool
     */
    private static function is_picture($id) {
        return 0 === strpos((string) get_post_mime_type($id), 'image/');
    }

    /**
     * Keep colours in dark mode, in the sidebar of blocks that show pictures
     * or hold them (rows, columns, groups). It adds or removes the class in
     * the block's Additional CSS class(es), so both stay in step.
     */
    public static function editor_assets() {
        $cfg = array(
            'panel' => __('Dark mode', 'seoprostack'),
            'label' => __('Keep colours in dark mode', 'seoprostack'),
            'help'  => __('Pictures in this block, its background included, are shown as they are in dark mode.', 'seoprostack'),
            'keep'  => self::KEEP_CLASS,
        );
        wp_register_script('seoprostack-keep-colours', false, array('wp-hooks', 'wp-blocks', 'wp-element', 'wp-components', 'wp-compose', 'wp-block-editor'), SEOPROSTACK_VERSION, false);
        wp_enqueue_script('seoprostack-keep-colours');
        wp_add_inline_script('seoprostack-keep-colours', '(function (wp, cfg) {
    var el = wp.element.createElement, c = wp.components, be = wp.blockEditor;
    var NAMES = /^(core\/(image|cover|group|columns|column|media-text|gallery|site-logo|post-featured-image)|kadence\/(image|rowlayout|column|advancedgallery|infobox))$/;
    function classes(cls) {
        return String(cls || "").split(/\s+/).filter(function (x) { return x && x !== cfg.keep; });
    }
    function has(cls) {
        return String(cls || "").split(/\s+/).indexOf(cfg.keep) !== -1;
    }
    var withToggle = wp.compose.createHigherOrderComponent(function (BlockEdit) {
        return function (props) {
            if (!props.isSelected || !NAMES.test(props.name) || !wp.blocks.hasBlockSupport(props.name, "customClassName", true)) {
                return el(BlockEdit, props);
            }
            var on = has(props.attributes.className);
            return el(wp.element.Fragment, null, el(BlockEdit, props),
                el(be.InspectorControls, null, el(c.PanelBody, { title: cfg.panel, initialOpen: on },
                    el(c.ToggleControl, { __nextHasNoMarginBottom: true, label: cfg.label, help: cfg.help, checked: on, onChange: function (v) {
                        var list = classes(props.attributes.className);
                        if (v) { list.push(cfg.keep); }
                        props.setAttributes({ className: list.length ? list.join(" ") : undefined });
                    } }))));
        };
    }, "withSeoprostackKeepColours");
    wp.hooks.addFilter("editor.BlockEdit", "seoprostack/keep-colours", withToggle);
})(window.wp, ' . wp_json_encode($cfg) . ');');
    }
}
