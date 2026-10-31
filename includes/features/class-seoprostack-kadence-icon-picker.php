<?php
/**
 * Faster Kadence icon picker.
 *
 * Kadence Blocks' icon picker (Select Icon in its blocks' settings) drew all
 * of its icons each time it opened: 5,482 with Kadence Blocks Pro, over
 * 22,000 elements, which held the editor for seconds. The picker script
 * (admin/js/seoprostack-kadence-icon-picker.js) has it draw the first 50
 * icons of each set, and more as you scroll or search, and open on the press
 * of its button; the icons it draws are skipped by the browser until they
 * are on screen, and the picker shows at once instead of fading in.
 *
 * Until 1.3.130 this came with Faster editor with Kadence Blocks, which
 * shipped off by default; sites set up before that turned on by default
 * kept it off, so their picker stayed slow. As its own setting, on by
 * default, it reaches every site.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.130
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Icon_Picker extends SEOProStack_Feature {

    const KEY = 'kadence_icon_picker';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // Safe on any site: it changes only how the picker draws.
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Faster Kadence icon picker', 'seoprostack'),
                'description' => __('Kadence Blocks’ icon picker draws all of its 5,000 or so icons each time it opens, which can hold the editor for seconds. This draws the first icons of each set, and more as you scroll or search, so it opens at once. Choosing an icon works as before, and nothing changes if Kadence Blocks is not active.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'enqueue'));
    }

    /**
     * The picker script, and styles that let the browser skip drawing the
     * icons off screen ("auto" keeps each one's real size once drawn) and
     * show the picker at once (WordPress's popover fades its opacity in;
     * the slide stays, as it carries the position).
     */
    public static function enqueue() {
        if (!defined('KADENCE_BLOCKS_VERSION')) {
            return;
        }
        $file = 'admin/js/seoprostack-kadence-icon-picker.js';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_script('seoprostack-kadence-icon-picker', SEOPROSTACK_URL . $file, array('wp-hooks', 'wp-element'), $ver, false);
        wp_register_style('seoprostack-kadence-icon-picker', false, array(), $ver);
        wp_enqueue_style('seoprostack-kadence-icon-picker');
        wp_add_inline_style(
            'seoprostack-kadence-icon-picker',
            '.kadence-icon-picker-pop-selection .kadence-icon-picker-content>.components-panel__body{content-visibility:auto;contain-intrinsic-width:0px;contain-intrinsic-height:auto 1200px}'
            . '.kadence-icon-picker-pop-selection .kadence-icon-picker-link{content-visibility:auto;contain-intrinsic-size:auto 60px}'
            . '.components-popover.kadence-icon-picker-pop-selection{opacity:1!important}'
        );
    }
}
