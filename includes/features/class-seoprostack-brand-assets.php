<?php
/**
 * Brand assets page.
 *
 * A Brand assets block (seoprostack/brand-assets) that shows the site's
 * logos, colours, fonts and text styles, read from the Kadence theme's
 * Customizer settings each time the page is built, so the page stays right
 * when they change. Nothing about the brand is stored in the post.
 *
 * Where the header shows the site title beside the logo (or the title
 * alone), the block draws the same lockup as the header and offers it as
 * SVG and PNG files, made in the visitor's browser from the page's own
 * fonts, with an email signature banner (blocks/brand-assets/view.js).
 *
 * wp seoprostack brand-assets (includes/class-seoprostack-brand-assets-cli.php)
 * and a button on the feature's card make the page and can add a link to it
 * after the About link in the footer.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.213
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Brand_Assets extends SEOProStack_Feature {

    const KEY = 'brand_assets';

    /** Block name. */
    const BLOCK = 'seoprostack/brand-assets';

    /** The page made for the block (post ID), per site. */
    const PAGE_OPTION = 'seoprostack_brand_assets_page';

    /** The page's address. */
    const SLUG = 'brand-assets';

    /** admin-post action of the card's buttons. */
    const ACTION = 'seoprostack_brand_assets';

    /** Query argument of the card's result. */
    const RESULT = 'sps-brand-assets';

    /** Kadence's palette slots with a role in its own descriptions. */
    const ROLES = array(
        'palette1' => 'Accent',
        'palette2' => 'Accent, alternative',
        'palette3' => 'Strongest text',
        'palette4' => 'Strong text',
        'palette5' => 'Medium text',
        'palette6' => 'Subtle text',
        'palette7' => 'Subtle background',
        'palette8' => 'Lighter background',
        'palette9' => 'Page background',
    );

    /** Headings shown under Formatting. */
    const HEADINGS = array(1, 2, 3, 4);

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
                'label'       => __('Brand assets page', 'seoprostack'),
                'description' => __('Adds a Brand assets block that shows your logos, colours, fonts and text styles from the Kadence theme’s Customizer settings, so it stays up to date when they change. Visitors can download the logos, copy colours and, where the header shows the site title, download the logo with the title as SVG or PNG and an email signature banner. The card can make the page and link it from the footer, after About.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-brand-assets-cli.php';
            WP_CLI::add_command('seoprostack brand-assets', 'SEOProStack_Brand_Assets_CLI');
        }
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'act'));
            add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
        }
        if (!self::enabled()) {
            return;
        }
        // boot() runs on init, which is where blocks are registered.
        register_block_type(SEOPROSTACK_DIR . 'blocks/brand-assets');
        // The page is built from these settings, so a cached copy is stale once they change.
        add_action('customize_save_after', array(__CLASS__, 'purge'));
        add_action('update_option_kadence_global_palette', array(__CLASS__, 'purge'));
        add_action('update_option_site_icon', array(__CLASS__, 'purge'));
        add_action('update_option_blogname', array(__CLASS__, 'purge'));
        add_action('update_option_blogdescription', array(__CLASS__, 'purge'));
    }

    /**
     * Whether the Kadence theme (or a child of it) is active and loaded.
     *
     * @return bool
     */
    public static function applies() {
        return 'kadence' === get_template() && function_exists('Kadence\kadence');
    }

    /**
     * A theme setting as Kadence reads it: the saved value, else its default.
     *
     * @param string $key Setting.
     * @return mixed
     */
    private static function option($key) {
        if (!self::applies()) {
            return get_theme_mod($key);
        }
        $theme = \Kadence\kadence();
        return is_object($theme) && is_callable(array($theme, 'option')) ? $theme->option($key) : get_theme_mod($key);
    }

    /**
     * Whether Kadence Pro's dark mode is on.
     *
     * @return bool
     */
    private static function dark_mode() {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Kadence Pro's hook.
        return defined('KTP_VERSION') && self::option('dark_mode_enable') && apply_filters('kadence_dark_mode_enable', true);
    }

    /* ---------------------------------------------------------------------
     * What the page shows
     * ------------------------------------------------------------------- */

    /**
     * The palettes: light (the active set) and dark (Kadence Pro's dark
     * palette while its dark mode is on), each slot => [colour, name].
     *
     * @return array<string,array<string,array{color:string,name:string}>>
     */
    public static function palettes() {
        $theme    = self::applies() ? \Kadence\kadence() : null;
        $defaults = self::palette_sets(is_object($theme) && is_callable(array($theme, 'get_default_palette')) ? $theme->get_default_palette() : '');
        $stored   = self::palette_sets(get_option('kadence_global_palette'));
        $sets     = $stored['sets'] ? $stored : $defaults;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the Kadence theme's own filter, applied as the theme does.
        $active = apply_filters('kadence_active_palette', '' !== $sets['active'] ? $sets['active'] : 'palette');
        $names  = array('light' => is_string($active) && '' !== $active ? $active : 'palette');
        if (self::dark_mode()) {
            $dark          = self::option('dark_mode_dark_palette');
            $names['dark'] = is_string($dark) && '' !== $dark ? $dark : 'second-palette';
        }
        $palettes = array();
        foreach ($names as $mode => $name) {
            $own  = isset($sets['sets'][$name]) ? $sets['sets'][$name] : array();
            $base = isset($defaults['sets'][$name]) ? $defaults['sets'][$name] : (isset($defaults['sets']['palette']) ? $defaults['sets']['palette'] : array());
            $set  = array_merge($base, $own);
            if ($set) {
                $palettes[$mode] = $set;
            }
        }
        return $palettes;
    }

    /**
     * Kadence's palette sets from its stored JSON, with each colour's name.
     *
     * @param mixed $stored Stored palette.
     * @return array{active:string,sets:array<string,array<string,array{color:string,name:string}>>}
     */
    private static function palette_sets($stored) {
        $palette = is_string($stored) && '' !== $stored ? json_decode($stored, true) : $stored;
        $out     = array('active' => '', 'sets' => array());
        if (!is_array($palette)) {
            return $out;
        }
        foreach ($palette as $name => $items) {
            if ('active' === $name) {
                $out['active'] = is_string($items) ? $items : '';
                continue;
            }
            if (!is_string($name) || !is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['slug'], $item['color']) || !is_string($item['slug']) || !is_string($item['color']) || '' === $item['color']) {
                    continue;
                }
                $out['sets'][$name][$item['slug']] = array(
                    'color' => $item['color'],
                    'name'  => isset($item['name']) && is_string($item['name']) ? $item['name'] : '',
                );
            }
        }
        foreach ($out['sets'] as $name => $set) {
            uksort($set, 'strnatcmp');
            $out['sets'][$name] = $set;
        }
        return $out;
    }

    /**
     * A colour's role: the name the site gave it, else Kadence's role.
     *
     * @param string $slot Palette slot.
     * @param string $name Stored name.
     * @return string
     */
    private static function role($slot, $name) {
        $name = trim($name);
        if ('' !== $name && !preg_match('/^Palette Colou?r \d+$/i', $name)) {
            // Kadence Pro's extra slots: "Palette Color Success" → "Success".
            return preg_match('/^Palette Colou?r\s+(.+)$/i', $name, $m) ? $m[1] : $name;
        }
        return isset(self::ROLES[$slot]) ? self::ROLES[$slot] : $name;
    }

    /**
     * A palette slot's colour ("palette3" or "var(--global-palette3)").
     *
     * @param mixed  $value   Colour setting.
     * @param array  $palette Slot => colour.
     * @param string $default Colour when the setting is empty.
     * @return string
     */
    private static function resolve($value, array $palette, $default) {
        if (!is_string($value) || '' === $value) {
            return $default;
        }
        if (preg_match('/^(?:var\(--global-)?(palette\d+)\)?$/', $value, $m)) {
            return isset($palette[$m[1]]['color']) ? $palette[$m[1]]['color'] : $default;
        }
        return $value;
    }

    /**
     * A hex colour as #rrggbb, or '' for other colours.
     *
     * @param string $colour Colour.
     * @return string
     */
    private static function hex($colour) {
        $colour = trim((string) $colour);
        if (preg_match('/^#?([0-9a-f]{3})$/i', $colour, $m)) {
            $colour = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#?([0-9a-f]{6})$/i', $colour, $m) ? '#' . strtolower($m[1]) : '';
    }

    /**
     * RGB and CMYK values of a hex colour.
     *
     * @param string $hex #rrggbb.
     * @return array{rgb:string,cmyk:string}
     */
    private static function values($hex) {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $k = 1 - max($r, $g, $b) / 255;
        $cmyk = array(0, 0, 0, 100);
        if ($k < 1) {
            $cmyk = array(
                (int) round((1 - $r / 255 - $k) / (1 - $k) * 100),
                (int) round((1 - $g / 255 - $k) / (1 - $k) * 100),
                (int) round((1 - $b / 255 - $k) / (1 - $k) * 100),
                (int) round($k * 100),
            );
        }
        return array(
            'rgb'  => sprintf('%d, %d, %d', $r, $g, $b),
            'cmyk' => vsprintf('%d, %d, %d, %d', $cmyk),
        );
    }

    /**
     * The logo files: main, dark-mode and mobile logos and the site icon,
     * each with its address, original file, type and size.
     *
     * @return array<string,array> Kind (logo, dark, mobile, icon) => file().
     */
    public static function logos() {
        $ids = array('logo' => (int) self::option('custom_logo'));
        if (!$ids['logo']) {
            $ids['logo'] = (int) get_theme_mod('custom_logo');
        }
        if (self::dark_mode() && self::option('dark_mode_custom_logo')) {
            $ids['dark'] = (int) self::option('dark_mode_logo');
        }
        if (self::option('use_mobile_logo')) {
            $ids['mobile'] = (int) self::option('mobile_logo');
        }
        $ids['icon'] = (int) get_option('site_icon');
        $logos = array();
        foreach ($ids as $kind => $id) {
            $logo = $id > 0 ? self::file($id) : null;
            if ($logo && !('dark' === $kind && isset($logos['logo']) && $logos['logo']['id'] === $id)) {
                $logos[$kind] = $logo;
            }
        }
        return $logos;
    }

    /**
     * One picture from the Media Library.
     *
     * @param int $id Attachment ID.
     * @return array{id:int,url:string,file:string,type:string,width:int,height:int,alt:string}|null
     */
    private static function file($id) {
        $url  = wp_get_attachment_url($id);
        $mime = (string) get_post_mime_type($id);
        if (!$url || !preg_match('#^image/([a-z0-9.-]+)#i', $mime, $m)) {
            return null;
        }
        $file = function_exists('wp_get_original_image_url') ? wp_get_original_image_url($id) : '';
        $meta = wp_get_attachment_metadata($id);
        $type = strtoupper($m[1]);
        return array(
            'id'     => $id,
            'url'    => $url,
            'file'   => $file ? $file : $url,
            'type'   => 'JPEG' === $type ? 'JPG' : $type,
            'width'  => is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0,
            'height' => is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0,
            'alt'    => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        );
    }

    /**
     * What the header shows, from Kadence's logo layout: the parts in order
     * (logo, title, tagline) and the direction they are laid out in.
     *
     * @return array{parts:string[],direction:string}
     */
    public static function branding() {
        $layout  = self::option('logo_layout');
        $include = is_array($layout) && isset($layout['include']['desktop']) && is_string($layout['include']['desktop']) && '' !== $layout['include']['desktop'] ? $layout['include']['desktop'] : 'logo_only';
        $slug    = is_array($layout) && isset($layout['layout']['desktop']) && is_string($layout['layout']['desktop']) && '' !== $layout['layout']['desktop'] ? $layout['layout']['desktop'] : 'standard';
        $parts   = array();
        foreach (array('logo', 'title', 'tagline') as $part) {
            if (false !== strpos($include, $part)) {
                $parts[] = $part;
            }
        }
        $directions = array(
            'title_logo'         => 'row-reverse',
            'title_tag_logo'     => 'row-reverse',
            'top_logo_title'     => 'column',
            'top_logo_title_tag' => 'column',
            'top_title_logo'     => 'column-reverse',
            'top_title_tag_logo' => 'column-reverse',
            'top_title_logo_tag' => 'column',
        );
        return array(
            'parts'     => $parts ? $parts : array('title'),
            'direction' => isset($directions[$slug]) ? $directions[$slug] : 'row',
        );
    }

    /**
     * A Kadence typography setting's desktop value.
     *
     * @param array  $font Setting.
     * @param string $key  size, lineHeight or letterSpacing.
     * @return string Number, or ''.
     */
    private static function desktop(array $font, $key) {
        if (!isset($font[$key])) {
            return '';
        }
        $value = is_array($font[$key]) ? (isset($font[$key]['desktop']) ? $font[$key]['desktop'] : '') : $font[$key];
        return is_numeric($value) ? (string) (0 + $value) : '';
    }

    /**
     * A font family as CSS, as Kadence writes it.
     *
     * @param array  $font    Typography setting.
     * @param string $inherit Family when the setting inherits.
     * @return string
     */
    private static function css_family(array $font, $inherit) {
        $family = isset($font['family']) && is_string($font['family']) ? trim($font['family']) : '';
        if ('' === $family || 'inherit' === $family) {
            return $inherit;
        }
        if (false !== strpos($family, 'var(') || false !== strpos($family, ',')) {
            return $family;
        }
        $fallback = isset($font['fallback']) && is_string($font['fallback']) && '' !== $font['fallback'] ? $font['fallback'] : 'sans-serif';
        return '"' . str_replace('"', '', $family) . '", ' . $fallback;
    }

    /**
     * The fonts: body and headings, each with its name, CSS family,
     * weights and whether it is a Google font.
     *
     * @return array<string,array{name:string,css:string,weights:string[],google:bool}>
     */
    public static function fonts() {
        $base    = self::option('base_font');
        $heading = self::option('heading_font');
        $base    = is_array($base) ? $base : array();
        $heading = is_array($heading) ? $heading : array();
        $fonts   = array('body' => self::font($base, 'var(--global-body-font-family)'));
        $family  = isset($heading['family']) && is_string($heading['family']) ? trim($heading['family']) : '';
        if ('' === $family || 'inherit' === $family) {
            // Same family as the body, with the headings' own weights.
            $fonts['heading']            = $fonts['body'];
            $own                         = self::font($heading, 'var(--global-heading-font-family)');
            $fonts['heading']['weights'] = $own['weights'];
        } else {
            $fonts['heading'] = self::font($heading, 'var(--global-heading-font-family)');
        }
        if (!$fonts['heading']['weights']) {
            $fonts['heading']['weights'] = self::weights_used();
        }
        return $fonts;
    }

    /**
     * One font.
     *
     * @param array  $font    Typography setting.
     * @param string $inherit CSS family when it inherits.
     * @return array{name:string,css:string,weights:string[],google:bool}
     */
    private static function font(array $font, $inherit) {
        $family = isset($font['family']) && is_string($font['family']) ? trim($font['family']) : '';
        $name   = $family;
        if ('' === $family || 'inherit' === $family || false !== strpos($family, ',') || false !== strpos($family, 'var(')) {
            $name = __('The visitor’s system font', 'seoprostack');
        }
        $variants = isset($font['variant']) ? (array) $font['variant'] : array();
        $weights  = array();
        foreach ($variants as $variant) {
            // Google variants: regular, italic, 700, 700italic. Upright ones only.
            if ('regular' === $variant) {
                $weights[] = '400';
            } elseif (is_string($variant) && preg_match('/^\d{3}$/', $variant)) {
                $weights[] = $variant;
            }
        }
        if (!$weights && isset($font['weight']) && is_scalar($font['weight']) && '' !== (string) $font['weight']) {
            $weights[] = 'regular' === $font['weight'] ? '400' : (string) $font['weight'];
        }
        $weights = array_values(array_unique($weights));
        sort($weights);
        return array(
            'name'    => $name,
            'css'     => self::css_family($font, $inherit),
            'weights' => $weights,
            'google'  => !empty($font['google']) && $name === $family,
        );
    }

    /**
     * The weights headings use, when the heading font lists none.
     *
     * @return string[]
     */
    private static function weights_used() {
        $weights = array();
        foreach (self::HEADINGS as $level) {
            $font = self::option('h' . $level . '_font');
            if (is_array($font) && isset($font['weight']) && is_scalar($font['weight']) && '' !== (string) $font['weight']) {
                $weights[] = 'regular' === $font['weight'] ? '400' : (string) $font['weight'];
            }
        }
        $weights = array_values(array_unique($weights));
        sort($weights);
        return $weights;
    }

    /**
     * Inline CSS for a Kadence typography setting at its desktop size.
     *
     * @param array  $font    Typography setting.
     * @param string $inherit CSS family when it inherits.
     * @param array  $palette Slot => colour, for a fixed colour; empty to
     *                        use the page's palette variables.
     * @return string
     */
    private static function style(array $font, $inherit, array $palette = array()) {
        $css  = array('font-family:' . self::css_family($font, $inherit));
        $size = self::desktop($font, 'size');
        if ('' !== $size) {
            $unit  = isset($font['sizeType']) && in_array($font['sizeType'], array('px', 'em', 'rem', 'vw'), true) ? $font['sizeType'] : 'px';
            $css[] = 'font-size:' . $size . $unit;
        }
        $line = self::desktop($font, 'lineHeight');
        if ('' !== $line) {
            $unit  = isset($font['lineType']) && in_array($font['lineType'], array('px', 'em', 'rem'), true) ? $font['lineType'] : '';
            $css[] = 'line-height:' . $line . $unit;
        }
        $spacing = self::desktop($font, 'letterSpacing');
        if ('' !== $spacing) {
            $unit  = isset($font['spacingType']) && in_array($font['spacingType'], array('px', 'em', 'rem'), true) ? $font['spacingType'] : 'em';
            $css[] = 'letter-spacing:' . $spacing . $unit;
        }
        if (isset($font['weight']) && is_scalar($font['weight']) && '' !== (string) $font['weight']) {
            $css[] = 'font-weight:' . ('regular' === $font['weight'] ? '400' : preg_replace('/[^0-9a-z]/', '', (string) $font['weight']));
        }
        if (!empty($font['style']) && in_array($font['style'], array('normal', 'italic'), true)) {
            $css[] = 'font-style:' . $font['style'];
        }
        if (!empty($font['transform']) && in_array($font['transform'], array('none', 'uppercase', 'lowercase', 'capitalize'), true)) {
            $css[] = 'text-transform:' . $font['transform'];
        }
        if (!empty($font['color']) && is_string($font['color'])) {
            if ($palette) {
                $css[] = 'color:' . self::resolve($font['color'], $palette, 'inherit');
            } elseif (preg_match('/^palette(\d+)$/', $font['color'], $m)) {
                $css[] = 'color:var(--global-palette' . $m[1] . ')';
            } elseif (self::hex($font['color'])) {
                $css[] = 'color:' . self::hex($font['color']);
            }
        }
        return implode(';', $css);
    }

    /**
     * A short description of a typography setting: "Sora 700, 52px / 1.2".
     *
     * @param array  $font Typography setting.
     * @param string $name Family name when it inherits.
     * @return string
     */
    private static function spec(array $font, $name) {
        $family = isset($font['family']) && is_string($font['family']) && '' !== trim($font['family']) && 'inherit' !== $font['family'] && false === strpos($font['family'], ',') && false === strpos($font['family'], 'var(') ? $font['family'] : $name;
        $out    = $family;
        if (isset($font['weight']) && is_scalar($font['weight']) && '' !== (string) $font['weight']) {
            $out .= ' ' . ('regular' === $font['weight'] ? '400' : (string) $font['weight']);
        }
        $size = self::desktop($font, 'size');
        if ('' !== $size) {
            $unit = isset($font['sizeType']) && is_string($font['sizeType']) && '' !== $font['sizeType'] ? $font['sizeType'] : 'px';
            $out .= ', ' . $size . $unit;
            $line = self::desktop($font, 'lineHeight');
            if ('' !== $line) {
                $out .= ' / ' . $line . (isset($font['lineType']) && '-' !== $font['lineType'] && is_string($font['lineType']) ? $font['lineType'] : '');
            }
            $mobile = isset($font['size']['mobile']) && is_numeric($font['size']['mobile']) ? (0 + $font['size']['mobile']) : '';
            if ('' !== $mobile && (string) $mobile !== $size) {
                /* translators: %s: font size on phones, such as 36px. */
                $out .= ' ' . sprintf(__('(%s on phones)', 'seoprostack'), $mobile . $unit);
            }
        }
        return $out;
    }

    /**
     * The header's lockup in one colour scheme, for the page and the
     * browser's SVG and PNG files.
     *
     * @param string $scheme  light or dark.
     * @param array  $palette Slot => colour of that scheme.
     * @param array  $logos   Logo files.
     * @return array
     */
    private static function lockup($scheme, array $palette, array $logos) {
        $branding = self::branding();
        $brand    = self::option('brand_typography');
        $tag      = self::option('brand_tag_typography');
        $base     = self::option('base_font');
        $brand    = is_array($brand) ? $brand : array();
        $tag      = is_array($tag) ? $tag : array();
        $base     = is_array($base) ? $base : array();
        $fonts    = self::fonts();
        $logo     = null;
        if (in_array('logo', $branding['parts'], true)) {
            $logo = 'dark' === $scheme && isset($logos['dark']) ? $logos['dark'] : (isset($logos['logo']) ? $logos['logo'] : null);
        }
        $width      = self::option('logo_width');
        $logo_width = is_array($width) && isset($width['size']['desktop']) && is_numeric($width['size']['desktop']) ? (int) $width['size']['desktop'] : 200;
        $text_font  = function (array $font, $default_size, $default_colour) use ($fonts, $palette) {
            $family = isset($font['family']) && is_string($font['family']) ? trim($font['family']) : '';
            if (false !== strpos($family, 'heading-font')) {
                $css = $fonts['heading']['css'];
            } elseif ('' === $family || 'inherit' === $family || false !== strpos($family, 'body-font')) {
                $css = $fonts['body']['css'];
            } else {
                $css = self::css_family($font, $fonts['body']['css']);
            }
            // The drawings take the size in pixels and the line height as a multiple of it.
            $size      = self::desktop($font, 'size');
            $line      = self::desktop($font, 'lineHeight');
            $size_unit = isset($font['sizeType']) && is_string($font['sizeType']) ? $font['sizeType'] : 'px';
            $line_unit = isset($font['lineType']) && is_string($font['lineType']) ? $font['lineType'] : '';
            $px        = $default_size;
            if ('' !== $size) {
                $px = (float) $size * (in_array($size_unit, array('em', 'rem'), true) ? 16 : ('vw' === $size_unit ? 14.4 : 1));
            }
            $height = 1.2;
            if ('' !== $line && $px > 0) {
                if ('px' === $line_unit) {
                    $height = (float) $line / $px;
                } elseif ('rem' === $line_unit) {
                    $height = (float) $line * 16 / $px;
                } else {
                    $height = (float) $line;
                }
            }
            return array(
                'family'     => $css,
                'weight'     => isset($font['weight']) && is_scalar($font['weight']) && '' !== (string) $font['weight'] ? ('regular' === $font['weight'] ? '400' : (string) $font['weight']) : '400',
                'style'      => isset($font['style']) && 'italic' === $font['style'] ? 'italic' : 'normal',
                'size'       => round($px, 2),
                'lineHeight' => round($height, 3),
                'transform'  => isset($font['transform']) && is_string($font['transform']) ? $font['transform'] : '',
                'color'      => self::resolve(isset($font['color']) ? $font['color'] : '', $palette, $default_colour),
            );
        };
        $strong = self::resolve('palette3', $palette, '#000000');
        $medium = self::resolve('palette5', $palette, $strong);
        $gap    = self::desktop($base, 'size');
        return array(
            'scheme'    => $scheme,
            'direction' => $branding['direction'],
            'gap'       => '' !== $gap ? (float) $gap : 16,
            'background' => self::resolve('palette9', $palette, 'light' === $scheme ? '#ffffff' : '#000000'),
            'logo'      => $logo ? array(
                'url'    => $logo['url'],
                'width'  => $logo_width,
                'ratio'  => $logo['width'] && $logo['height'] ? $logo['height'] / $logo['width'] : 0,
                'alt'    => '' !== $logo['alt'] ? $logo['alt'] : get_bloginfo('name'),
            ) : null,
            'title'     => in_array('title', $branding['parts'], true) ? wp_strip_all_tags(get_bloginfo('name')) : '',
            'titleFont' => $text_font($brand, 26.0, $strong),
            'tagline'   => in_array('tagline', $branding['parts'], true) ? wp_strip_all_tags(get_bloginfo('description')) : '',
            'tagFont'   => $text_font($tag, 16.0, $medium),
            'file'      => sanitize_title(get_bloginfo('name')) ? sanitize_title(get_bloginfo('name')) : 'logo',
        );
    }

    /* ---------------------------------------------------------------------
     * The block
     * ------------------------------------------------------------------- */

    /**
     * Build the block.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_block($attributes) {
        $attributes = wp_parse_args((array) $attributes, array(
            'showLogos'      => true,
            'showColours'    => true,
            'showFonts'      => true,
            'showFormatting' => true,
        ));
        if (!self::applies()) {
            return current_user_can('edit_theme_options') ? '<p>' . esc_html__('The Brand assets block reads the Kadence theme’s settings, and Kadence is not the active theme.', 'seoprostack') . '</p>' : '';
        }
        $palettes = self::palettes();
        $html     = '';
        if ($attributes['showLogos']) {
            $html .= self::render_logos($palettes);
            $html .= self::render_icons($palettes);
            $html .= self::render_email($palettes);
        }
        if ($attributes['showColours'] && $palettes) {
            $html .= self::render_colours($palettes);
        }
        if ($attributes['showFonts']) {
            $html .= self::render_fonts();
        }
        if ($attributes['showFormatting']) {
            $html .= self::render_formatting();
        }
        if ('' === $html) {
            return '';
        }
        $html .= '<p class="wp-block-seoprostack-brand-assets__live" aria-live="polite"></p>';
        return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
    }

    /**
     * Logos.
     *
     * @param array $palettes Palettes.
     * @return string
     */
    private static function render_logos(array $palettes) {
        $c       = 'wp-block-seoprostack-brand-assets';
        $logos   = self::logos();
        $light   = isset($palettes['light']) ? $palettes['light'] : array();
        $schemes = array(
            'light' => array(__('On light backgrounds', 'seoprostack'), $light),
            'dark'  => array(__('On dark backgrounds', 'seoprostack'), isset($palettes['dark']) ? $palettes['dark'] : self::inverted($light)),
        );
        $cards = '';
        foreach ($schemes as $scheme => $item) {
            list($label, $palette) = $item;
            $lockup = self::lockup($scheme, $palette, $logos);
            if (!$lockup['logo'] && '' === $lockup['title']) {
                continue;
            }
            $links = array();
            // Each card offers the logo file its header shows: the dark logo, where the site has one.
            $file = 'dark' === $scheme && isset($logos['dark']) ? $logos['dark'] : (isset($logos['logo']) ? $logos['logo'] : null);
            if ($file && $lockup['logo']) {
                /* translators: %s: file type, such as SVG or PNG. */
                $links[] = self::download_link($file['file'], sprintf(__('Logo (%s)', 'seoprostack'), $file['type']));
            }
            $json = wp_json_encode($lockup);
            if (is_string($json) && '' !== $lockup['title']) {
                $links[] = self::make_button('svg', $json, $lockup['logo'] ? __('Logo with name (SVG)', 'seoprostack') : __('Name (SVG)', 'seoprostack'));
                $links[] = self::make_button('png', $json, $lockup['logo'] ? __('Logo with name (PNG)', 'seoprostack') : __('Name (PNG)', 'seoprostack'));
            }
            $cards .= self::card(self::lockup_html($lockup), self::stage_style($palette, $lockup['background']), $label, $links);
        }
        if ('' === $cards) {
            return '';
        }
        $html  = '<h2 class="' . $c . '__title">' . esc_html__('Logos', 'seoprostack') . '</h2>';
        $html .= '<div class="' . $c . '__grid ' . $c . '__grid--two">' . $cards . '</div>';
        $html .= '<p class="' . $c . '__note">' . esc_html__('Please keep clear space around the logo, and do not stretch, recolour or add effects to it. Use the version made for the background it sits on.', 'seoprostack') . '</p>';
        return $html;
    }

    /**
     * Icons: the small logo (the header's on phones) and the site icon.
     *
     * @param array $palettes Palettes.
     * @return string
     */
    private static function render_icons(array $palettes) {
        $c     = 'wp-block-seoprostack-brand-assets';
        $logos = self::logos();
        $light = isset($palettes['light']) ? $palettes['light'] : array();
        $dark  = isset($palettes['dark']) ? $palettes['dark'] : self::inverted($light);
        $cards = '';
        if (isset($logos['mobile'])) {
            $file   = $logos['mobile'];
            $cards .= self::card(
                self::icon_img($file['url'], $file, __('Small logo', 'seoprostack'), true),
                self::stage_style($light, self::resolve('palette9', $light, '#ffffff')),
                __('Small logo', 'seoprostack'),
                array(self::download_link($file['file'], self::size_label($file['type'], $file['width'], $file['height']))),
                __('The header’s logo on phones.', 'seoprostack')
            );
        }
        if (isset($logos['icon'])) {
            $file  = $logos['icon'];
            $shown = get_site_icon_url(192);
            $shown = $shown ? $shown : $file['url'];
            $stage = '';
            foreach (array($light, $dark) as $palette) {
                $stage .= '<span class="' . $c . '__half" style="' . esc_attr(self::stage_style($palette, self::resolve('palette9', $palette, '#ffffff'))) . '">' . self::icon_img($shown, $file, __('Site icon', 'seoprostack'), false) . '</span>';
            }
            $cards .= self::card($stage, '', __('Site icon', 'seoprostack'), self::icon_links($file), __('Browser tabs, bookmarks and phone home screens.', 'seoprostack'), 'halves');
        }
        if ('' === $cards) {
            return current_user_can('edit_theme_options') ? '<p class="' . $c . '__note">' . esc_html__('Set a site icon in Appearance → Customize → Site Identity to show it here (only editors of the site see this).', 'seoprostack') . '</p>' : '';
        }
        return '<h2 class="' . $c . '__title">' . esc_html__('Icons', 'seoprostack') . '</h2><div class="' . $c . '__grid ' . $c . '__grid--two">' . $cards . '</div>';
    }

    /**
     * An icon picture.
     *
     * @param string $url  Address shown.
     * @param array  $file Logo file.
     * @param string $alt  Text when the file has none.
     * @param bool   $edge Give it the effect the theme gives the header's logo.
     * @return string
     */
    private static function icon_img($url, array $file, $alt, $edge) {
        return '<img class="wp-block-seoprostack-brand-assets__icon" src="' . esc_url($url) . '" alt="' . esc_attr('' !== $file['alt'] ? $file['alt'] : $alt) . '"' . ($edge ? ' data-sps-brand-edge' : '') . ' loading="lazy" decoding="async">';
    }

    /**
     * The site icon's downloads: the original and the sizes WordPress makes.
     *
     * @param array $file Site icon file.
     * @return string[]
     */
    private static function icon_links(array $file) {
        $links = array(self::download_link($file['file'], self::size_label($file['type'], $file['width'], $file['height'])));
        $seen  = array($file['file'] => true);
        $sizes = array(
            192 => '',
            /* translators: the icon an iPhone or iPad shows on its home screen. */
            180 => __('Apple', 'seoprostack'),
            /* translators: the icon a browser shows in its tab. */
            32  => __('browser tab', 'seoprostack'),
        );
        foreach ($sizes as $size => $use) {
            $url = get_site_icon_url($size);
            if (!$url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $label      = self::size_label(self::url_type($url), $size, $size);
            $links[]    = self::download_link($url, '' !== $use ? $label . ' (' . $use . ')' : $label);
        }
        return $links;
    }

    /**
     * A file's type and size: "PNG, 512 × 512".
     *
     * @param string $type   File type.
     * @param int    $width  Width, or 0.
     * @param int    $height Height.
     * @return string
     */
    private static function size_label($type, $width, $height) {
        return $type . ($width ? ', ' . (int) $width . ' × ' . (int) $height : '');
    }

    /**
     * A file's type from its address: PNG, SVG, WEBP.
     *
     * @param string $url Address.
     * @return string
     */
    private static function url_type($url) {
        $type = strtoupper((string) pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return 'JPEG' === $type ? 'JPG' : $type;
    }

    /**
     * Email signature: the logo with the name in a 600 × 150 banner.
     *
     * @param array $palettes Palettes.
     * @return string
     */
    private static function render_email(array $palettes) {
        $c      = 'wp-block-seoprostack-brand-assets';
        $light  = isset($palettes['light']) ? $palettes['light'] : array();
        $lockup = self::lockup('light', $light, self::logos());
        $json   = wp_json_encode($lockup);
        if ((!$lockup['logo'] && '' === $lockup['title']) || !is_string($json)) {
            return '';
        }
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $card = self::card(
            self::lockup_html($lockup),
            self::stage_style($light, $lockup['background']),
            __('Email signature banner', 'seoprostack'),
            array(self::make_button('email', $json, __('Download (PNG, 600 × 150)', 'seoprostack'))),
            /* translators: %s: the site's address, such as example.com. */
            sprintf(__('Add it to your email signature as a picture, 600 pixels wide, linked to %s.', 'seoprostack'), preg_replace('/^www\./', '', $host)),
            'banner'
        );
        return '<h2 class="' . $c . '__title">' . esc_html__('Email signature', 'seoprostack') . '</h2><div class="' . $c . '__grid ' . $c . '__grid--two">' . $card . '</div>';
    }

    /**
     * A card: a stage, then its label, a note and its links.
     *
     * @param string   $stage    Stage HTML.
     * @param string   $style    Stage CSS (background and palette).
     * @param string   $label    Label.
     * @param string[] $links    Links and buttons (HTML).
     * @param string   $note     Text under the label.
     * @param string   $modifier Stage modifier: halves or banner.
     * @return string
     */
    private static function card($stage, $style, $label, array $links, $note = '', $modifier = '') {
        $c     = 'wp-block-seoprostack-brand-assets';
        $html  = '<figure class="' . $c . '__card">';
        $html .= '<div class="' . $c . '__stage' . ('' !== $modifier ? ' ' . $c . '__stage--' . $modifier : '') . '" data-sps-brand-stage' . ('' !== $style ? ' style="' . esc_attr($style) . '"' : '') . '>' . $stage . '</div>';
        $html .= '<figcaption class="' . $c . '__caption"><strong>' . esc_html($label) . '</strong>';
        if ('' !== $note) {
            $html .= '<span>' . esc_html($note) . '</span>';
        }
        if ($links) {
            $html .= '<span class="' . $c . '__links">' . implode(' ', $links) . '</span>';
        }
        return $html . '</figcaption></figure>';
    }

    /**
     * A stage's CSS: its background and its scheme's palette as Kadence's
     * variables, so the theme's own styles inside it take that scheme.
     *
     * @param array  $palette    Slot => colour.
     * @param string $background Background colour.
     * @return string
     */
    private static function stage_style(array $palette, $background) {
        $css = array('background-color:' . $background);
        foreach ($palette as $slot => $item) {
            $hex = self::hex($item['color']);
            if ('' !== $hex && preg_match('/^palette\d+$/', (string) $slot)) {
                $css[] = '--global-' . $slot . ':' . $hex;
            }
        }
        return implode(';', $css);
    }

    /**
     * A dark scheme for sites without Kadence Pro's dark mode: the light
     * palette with its text and background slots swapped.
     *
     * @param array $palette Light palette.
     * @return array
     */
    private static function inverted(array $palette) {
        $swap = array('palette3' => 'palette9', 'palette4' => 'palette8', 'palette5' => 'palette7', 'palette7' => 'palette5', 'palette8' => 'palette4', 'palette9' => 'palette3');
        $out  = $palette;
        foreach ($swap as $to => $from) {
            if (isset($palette[$from])) {
                $out[$to] = $palette[$from];
            }
        }
        return $out;
    }

    /**
     * The lockup as HTML, like Kadence's header branding.
     *
     * @param array $lockup Lockup.
     * @return string
     */
    private static function lockup_html(array $lockup) {
        $c    = 'wp-block-seoprostack-brand-assets';
        $html = '<span class="' . $c . '__lockup" style="flex-direction:' . esc_attr($lockup['direction']) . ';gap:' . esc_attr((string) $lockup['gap']) . 'px">';
        if ($lockup['logo']) {
            $height = $lockup['logo']['ratio'] ? (int) round($lockup['logo']['width'] * $lockup['logo']['ratio']) : 0;
            $html  .= '<img data-sps-brand-edge src="' . esc_url($lockup['logo']['url']) . '" alt="' . esc_attr($lockup['logo']['alt']) . '" width="' . (int) $lockup['logo']['width'] . '"' . ($height ? ' height="' . $height . '"' : '') . ' style="width:' . (int) $lockup['logo']['width'] . 'px;max-width:100%;height:auto" loading="lazy" decoding="async">';
        }
        if ('' !== $lockup['title']) {
            $html .= '<span class="' . $c . '__name"><span style="' . esc_attr(self::text_style($lockup['titleFont'])) . '">' . esc_html($lockup['title']) . '</span>';
            if ('' !== $lockup['tagline']) {
                $html .= '<span style="' . esc_attr(self::text_style($lockup['tagFont'])) . ';margin-top:.4375em">' . esc_html($lockup['tagline']) . '</span>';
            }
            $html .= '</span>';
        }
        return $html . '</span>';
    }

    /**
     * Inline CSS of a lockup's text.
     *
     * @param array $font Lockup font.
     * @return string
     */
    private static function text_style(array $font) {
        return 'display:block;font-family:' . $font['family'] . ';font-weight:' . $font['weight'] . ';font-style:' . $font['style'] . ';font-size:' . $font['size'] . 'px;line-height:' . $font['lineHeight'] . ';color:' . $font['color'] . ($font['transform'] ? ';text-transform:' . $font['transform'] : '');
    }

    /**
     * A download link.
     *
     * @param string $url   File.
     * @param string $label Text.
     * @return string
     */
    private static function download_link($url, $label) {
        return '<a class="' . 'wp-block-seoprostack-brand-assets__download" href="' . esc_url($url) . '" download>' . esc_html($label) . '</a>';
    }

    /**
     * A button that makes a file in the browser.
     *
     * @param string $kind  svg, png or email.
     * @param string $json  Lockup.
     * @param string $label Text.
     * @return string
     */
    private static function make_button($kind, $json, $label) {
        return '<button type="button" class="wp-block-seoprostack-brand-assets__download" data-sps-brand-make="' . esc_attr($kind) . '" data-sps-brand-lockup="' . esc_attr((string) $json) . '">' . esc_html($label) . '</button>';
    }

    /**
     * Colours.
     *
     * @param array $palettes Palettes.
     * @return string
     */
    private static function render_colours(array $palettes) {
        $c     = 'wp-block-seoprostack-brand-assets';
        $names = array(
            'light' => __('Light mode', 'seoprostack'),
            'dark'  => __('Dark mode', 'seoprostack'),
        );
        $html = '<h2 class="' . $c . '__title">' . esc_html__('Colours', 'seoprostack') . '</h2>';
        foreach ($palettes as $mode => $palette) {
            $html .= '<h3 class="' . $c . '__subtitle">' . esc_html($names[$mode]) . '</h3>';
            $html .= '<ul class="' . $c . '__grid ' . $c . '__swatches">';
            foreach ($palette as $slot => $item) {
                $hex = self::hex($item['color']);
                if ('' === $hex) {
                    continue;
                }
                $values = self::values($hex);
                $html  .= '<li class="' . $c . '__swatch">';
                $html  .= '<span class="' . $c . '__chip" style="background-color:' . esc_attr($hex) . '"></span>';
                $html  .= '<span class="' . $c . '__swatch-text"><strong>' . esc_html(self::role($slot, $item['name'])) . '</strong>';
                $html  .= self::value_row('HEX', strtoupper($hex));
                $html  .= self::value_row('RGB', $values['rgb']);
                $html  .= self::value_row('CMYK', $values['cmyk']);
                $html  .= '</span></li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    /**
     * One colour value with a button that copies it, as shown.
     *
     * @param string $format HEX, RGB or CMYK.
     * @param string $value  Value.
     * @return string
     */
    private static function value_row($format, $value) {
        $c    = 'wp-block-seoprostack-brand-assets';
        $icon = '<svg class="%s" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</svg>';
        $copy = sprintf($icon, $c . '__copy-icon', '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>');
        $done = sprintf($icon, $c . '__done-icon', '<path d="M5 12.5l4.5 4.5L19 7.5"/>');
        /* translators: 1: colour format, such as HEX; 2: its value, such as #0070CC. */
        $label = sprintf(__('Copy %1$s %2$s', 'seoprostack'), $format, $value);
        return '<span class="' . $c . '__value"><span class="' . $c . '__value-label">' . esc_html($format) . '</span><span class="' . $c . '__value-text">' . esc_html($value) . '</span>'
            . '<button type="button" class="' . $c . '__copy" data-sps-brand-copy="' . esc_attr($value) . '" aria-label="' . esc_attr($label) . '" title="' . esc_attr__('Copy', 'seoprostack') . '">' . $copy . $done . '</button></span>';
    }

    /**
     * Fonts.
     *
     * @return string
     */
    private static function render_fonts() {
        $c     = 'wp-block-seoprostack-brand-assets';
        $fonts = self::fonts();
        $list  = array('heading' => __('Headings', 'seoprostack'), 'body' => __('Body text', 'seoprostack'));
        $html  = '<h2 class="' . $c . '__title">' . esc_html__('Fonts', 'seoprostack') . '</h2>';
        $html .= '<div class="' . $c . '__grid ' . $c . '__grid--two">';
        foreach ($list as $kind => $label) {
            $font  = $fonts[$kind];
            $html .= '<div class="' . $c . '__card ' . $c . '__font">';
            $html .= '<p class="' . $c . '__specimen" style="font-family:' . esc_attr($font['css']) . '" aria-hidden="true">Aa Bb Cc 123</p>';
            $html .= '<p class="' . $c . '__caption"><strong>' . esc_html($label) . ': ' . esc_html($font['name']) . '</strong>';
            if ($font['weights']) {
                /* translators: %s: font weights, such as 400, 700. */
                $html .= '<span>' . esc_html(sprintf(__('Weights: %s', 'seoprostack'), implode(', ', $font['weights']))) . '</span>';
            }
            if ($font['google']) {
                $html .= '<span><a href="' . esc_url('https://fonts.google.com/specimen/' . rawurlencode(str_replace(' ', '+', $font['name']))) . '" rel="noopener">' . esc_html__('Get it from Google Fonts (free)', 'seoprostack') . '</a></span>';
            }
            $html .= '</p></div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Formatting: the site's heading, body, link and quote styles.
     *
     * @return string
     */
    private static function render_formatting() {
        $c     = 'wp-block-seoprostack-brand-assets';
        $fonts = self::fonts();
        $html  = '<h2 class="' . $c . '__title">' . esc_html__('Formatting', 'seoprostack') . '</h2>';
        $html .= '<div class="' . $c . '__card ' . $c . '__formatting">';
        $names = array(
            1 => __('Page title', 'seoprostack'),
            2 => __('Heading 2', 'seoprostack'),
            3 => __('Heading 3', 'seoprostack'),
            4 => __('Heading 4', 'seoprostack'),
        );
        foreach (self::HEADINGS as $level) {
            $font  = self::option('h' . $level . '_font');
            $font  = is_array($font) ? $font : array();
            $html .= '<div class="' . $c . '__sample">';
            $html .= '<p class="' . $c . '__sample-text" style="' . esc_attr(self::style($font, $fonts['heading']['css'])) . '">' . esc_html($names[$level]) . '</p>';
            $html .= '<p class="' . $c . '__spec">' . esc_html(self::spec($font, $fonts['heading']['name'])) . '</p>';
            $html .= '</div>';
        }
        $base  = self::option('base_font');
        $base  = is_array($base) ? $base : array();
        $html .= '<div class="' . $c . '__sample">';
        $html .= '<p class="' . $c . '__sample-text" style="' . esc_attr(self::style($base, $fonts['body']['css'])) . '">' . esc_html__('Body text is set like this, with', 'seoprostack') . ' <a href="' . esc_url(home_url('/')) . '">' . esc_html__('links', 'seoprostack') . '</a> ' . esc_html__('in the accent colour and', 'seoprostack') . ' <strong>' . esc_html__('bold text', 'seoprostack') . '</strong> ' . esc_html__('for emphasis.', 'seoprostack') . '</p>';
        $html .= '<p class="' . $c . '__spec">' . esc_html(self::spec($base, $fonts['body']['name'])) . '</p>';
        $html .= '</div>';
        $html .= '<blockquote class="wp-block-quote ' . $c . '__quote"><p>' . esc_html__('Quotes stand out from the text around them.', 'seoprostack') . '</p></blockquote>';
        $html .= '<p class="' . $c . '__buttons"><span class="wp-block-button"><span class="wp-block-button__link wp-element-button button">' . esc_html__('Button', 'seoprostack') . '</span></span></p>';
        $html .= '</div>';
        return $html;
    }

    /* ---------------------------------------------------------------------
     * The page and its footer link
     * ------------------------------------------------------------------- */

    /**
     * The Brand assets page: the one made here, else a page at
     * /brand-assets/.
     *
     * @return WP_Post|null
     */
    public static function page() {
        $id   = (int) get_option(self::PAGE_OPTION);
        $page = $id ? get_post($id) : null;
        if ($page instanceof WP_Post && 'page' === $page->post_type && 'trash' !== $page->post_status) {
            return $page;
        }
        $page = get_page_by_path(self::SLUG);
        return $page instanceof WP_Post && 'trash' !== $page->post_status ? $page : null;
    }

    /**
     * The block markup a new page holds.
     *
     * @return string
     */
    public static function page_content() {
        return "<!-- wp:paragraph -->\n<p>" . esc_html__('Our logos, colours and fonts, for press, print and design.', 'seoprostack') . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:" . self::BLOCK . " /-->";
    }

    /**
     * Show Kadence's page title and the space above and below the content
     * on the page, where nobody has chosen otherwise for it. Sites whose
     * pages draw their own hero (directory sites) hide both for every page,
     * which would leave this page with no title and no space at the end.
     * Kadence's "show" title uses the site's own title layout.
     *
     * @param int $id Page ID.
     * @return void
     */
    private static function kadence_layout($id) {
        if ('kadence' !== get_template()) {
            return;
        }
        foreach (array('_kad_post_title', '_kad_post_vertical_padding') as $key) {
            $value = get_post_meta($id, $key, true);
            if ('' === $value || 'default' === $value) {
                update_post_meta($id, $key, 'show');
            }
        }
    }

    /**
     * Make (or adopt) the page.
     *
     * @param bool $replace Put the block in an existing page without it (a revision keeps the old content).
     * @param bool $dry_run Report only.
     * @return array{status:string,id:int,message:string}
     */
    public static function create_page($replace = false, $dry_run = false) {
        $page = self::page();
        if ($page) {
            if (has_block(self::BLOCK, $page)) {
                if (!$dry_run) {
                    update_option(self::PAGE_OPTION, (int) $page->ID, false);
                    self::kadence_layout((int) $page->ID);
                }
                return array('status' => 'exists', 'id' => (int) $page->ID, 'message' => sprintf('The page already shows the block: %s', get_permalink($page)));
            }
            if (!$replace) {
                return array('status' => 'kept', 'id' => (int) $page->ID, 'message' => sprintf('A page at %s exists without the block, so it was left as it is. Use --replace to put the block in it (a revision keeps the old content).', get_permalink($page)));
            }
            if ($dry_run) {
                return array('status' => 'would-replace', 'id' => (int) $page->ID, 'message' => sprintf('Would replace the content of %s with the block (a revision keeps the old content).', get_permalink($page)));
            }
            // Keep the old content even when Limit post revisions is 0: force one revision with the limit lifted.
            $lift = function () {
                return -1;
            };
            add_filter('wp_revisions_to_keep', $lift, PHP_INT_MAX);
            $revision = wp_save_post_revision((int) $page->ID);
            remove_filter('wp_revisions_to_keep', $lift, PHP_INT_MAX);
            if (!$revision || is_wp_error($revision)) {
                // A revision is skipped when the content is unchanged since the last one; look for one that holds it.
                $revisions = wp_get_post_revisions((int) $page->ID, array('numberposts' => 1));
                $revision  = $revisions ? (int) key($revisions) : 0;
            }
            if (!$revision) {
                return array('status' => 'error', 'id' => (int) $page->ID, 'message' => 'Could not save the old content as a revision, so the page was left as it is.');
            }
            $saved = wp_update_post(array('ID' => (int) $page->ID, 'post_content' => self::page_content()), true);
            if (is_wp_error($saved)) {
                return array('status' => 'error', 'id' => (int) $page->ID, 'message' => $saved->get_error_message());
            }
            update_option(self::PAGE_OPTION, (int) $page->ID, false);
            self::kadence_layout((int) $page->ID);
            return array('status' => 'replaced', 'id' => (int) $page->ID, 'message' => sprintf('Replaced the content of %s with the block. The old content is saved as revision %d: restore it from the page\'s Revisions panel in the editor.', get_permalink($page), (int) $revision));
        }
        if ($dry_run) {
            return array('status' => 'would-create', 'id' => 0, 'message' => sprintf('Would publish a Brand assets page at %s.', home_url('/' . self::SLUG . '/')));
        }
        $id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('Brand assets', 'seoprostack'),
            'post_name'    => self::SLUG,
            'post_content' => self::page_content(),
            'post_author'  => get_current_user_id(),
        ), true);
        if (is_wp_error($id)) {
            return array('status' => 'error', 'id' => 0, 'message' => $id->get_error_message());
        }
        update_option(self::PAGE_OPTION, (int) $id, false);
        self::kadence_layout((int) $id);
        return array('status' => 'created', 'id' => (int) $id, 'message' => sprintf('Published %s.', get_permalink($id)));
    }

    /**
     * A link's path, without the site's own path or a trailing slash.
     *
     * @param string $url Link.
     * @return string Lower case, or '' for other sites and anchors.
     */
    private static function path($url) {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES);
        if ('' === $url || '#' === $url[0] || preg_match('/^(mailto|tel):/i', $url)) {
            return '';
        }
        $parts = wp_parse_url($url);
        $home  = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !is_array($home)) {
            return '';
        }
        $host = function ($name) {
            $name = strtolower((string) $name);
            return 0 === strpos($name, 'www.') ? substr($name, 4) : $name;
        };
        if (!empty($parts['host']) && (!isset($home['host']) || $host($parts['host']) !== $host($home['host']))) {
            return '';
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $base = isset($home['path']) ? rtrim($home['path'], '/') : '';
        if ('' !== $base && 0 === strpos($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        $path = strtolower('/' . trim($path, '/'));
        // Plain permalinks name the page in the query, so keep that part.
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array('page_id', 'p') as $key) {
                if (isset($query[$key]) && is_scalar($query[$key]) && ctype_digit((string) $query[$key])) {
                    return $path . '?' . $key . '=' . (int) $query[$key];
                }
            }
        }
        return $path;
    }

    /**
     * Paths of the About page.
     *
     * @return string[]
     */
    private static function about_paths() {
        $paths = array('/about', '/about-us');
        foreach (array('about', 'about-us') as $slug) {
            $page = get_page_by_path($slug);
            if ($page instanceof WP_Post) {
                $paths[] = self::path(get_permalink($page));
            }
        }
        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * The footer's places for links: block widgets and nav menus in the
     * footer widget areas, and the footer menu location.
     *
     * @return array{blocks:int[],menus:int[]}
     */
    private static function footer_places() {
        $areas  = get_option('sidebars_widgets');
        $blocks = array();
        $menus  = array();
        $navs   = get_option('widget_nav_menu');
        if (is_array($areas)) {
            foreach ($areas as $area => $widgets) {
                if (!is_string($area) || 0 !== strpos($area, 'footer') || !is_array($widgets)) {
                    continue;
                }
                foreach ($widgets as $widget) {
                    if (preg_match('/^block-(\d+)$/', (string) $widget, $m)) {
                        $blocks[] = (int) $m[1];
                    } elseif (preg_match('/^nav_menu-(\d+)$/', (string) $widget, $m) && isset($navs[(int) $m[1]]['nav_menu'])) {
                        $menus[] = (int) $navs[(int) $m[1]]['nav_menu'];
                    }
                }
            }
        }
        $locations = get_nav_menu_locations();
        if (!empty($locations['footer'])) {
            $menus[] = (int) $locations['footer'];
        }
        return array('blocks' => array_values(array_unique($blocks)), 'menus' => array_values(array_unique(array_filter($menus))));
    }

    /**
     * Add a link to the page after the footer's About link.
     *
     * @param bool $dry_run Report only.
     * @return array{status:string,message:string}
     */
    public static function add_footer_link($dry_run = false) {
        $page = self::page();
        if (!$page || 'publish' !== $page->post_status) {
            return array('status' => 'no-page', 'message' => 'There is no published Brand assets page to link to.');
        }
        $target = self::path(get_permalink($page));
        $about  = self::about_paths();
        $places = self::footer_places();
        $blocks = get_option('widget_block');
        $found  = null;
        foreach ($places['menus'] as $menu) {
            $items = wp_get_nav_menu_items($menu, array('post_status' => 'any'));
            foreach ((array) $items as $item) {
                if (((int) $item->object_id === (int) $page->ID && 'page' === $item->object) || self::path($item->url) === $target) {
                    return array('status' => 'exists', 'message' => sprintf('The footer menu "%s" already links to the page.', self::menu_name($menu)));
                }
                if (!$found && in_array(self::path($item->url), $about, true)) {
                    $found = array('menu', $menu, $item);
                }
            }
        }
        foreach ($places['blocks'] as $number) {
            $content = isset($blocks[$number]['content']) ? (string) $blocks[$number]['content'] : '';
            if (preg_match_all('/<a\b[^>]*\bhref=(["\'])(.*?)\1/i', $content, $links)) {
                foreach ($links[2] as $href) {
                    if (self::path($href) === $target) {
                        return array('status' => 'exists', 'message' => sprintf('The footer widget block-%d already links to the page.', $number));
                    }
                }
                foreach ($links[2] as $href) {
                    if (!$found && in_array(self::path($href), $about, true)) {
                        $found = array('block', $number, null);
                    }
                }
            }
        }
        if (!$found) {
            return array('status' => 'no-about', 'message' => 'No About link was found in the footer\'s widgets or menus, so nothing was added. Add the link by hand where it fits.');
        }
        $label = __('Brand assets', 'seoprostack');
        if ('menu' === $found[0]) {
            $menu = self::menu_name((int) $found[1]);
            if ($dry_run) {
                return array('status' => 'would-add', 'message' => sprintf('Would add "%s" after "%s" in the footer menu "%s".', $label, $found[2]->title, $menu));
            }
            return self::add_menu_item((int) $found[1], $found[2], $page) ? array('status' => 'added', 'message' => sprintf('Added "%s" after "%s" in the footer menu "%s".', $label, $found[2]->title, $menu)) : array('status' => 'error', 'message' => 'The menu item could not be saved.');
        }
        $number  = (int) $found[1];
        $updated = self::insert_after_about((string) $blocks[$number]['content'], $about, wp_make_link_relative(get_permalink($page)), $label);
        if (null === $updated) {
            return array('status' => 'no-about', 'message' => sprintf('The footer widget block-%d links to About from a block that cannot be copied, so nothing was added.', $number));
        }
        if ($dry_run) {
            return array('status' => 'would-add', 'message' => sprintf('Would add "%s" after the About link in the footer widget block-%d.', $label, $number));
        }
        $blocks[$number]['content'] = $updated;
        update_option('widget_block', $blocks);
        $check = get_option('widget_block');
        return isset($check[$number]['content']) && $check[$number]['content'] === $updated ? array('status' => 'added', 'message' => sprintf('Added "%s" after the About link in the footer widget block-%d.', $label, $number)) : array('status' => 'error', 'message' => 'The footer widget could not be saved.');
    }

    /**
     * A menu's name.
     *
     * @param int $menu Menu.
     * @return string
     */
    private static function menu_name($menu) {
        $object = wp_get_nav_menu_object($menu);
        return $object ? $object->name : (string) $menu;
    }

    /**
     * Add the page to a menu after an item, at the same level.
     *
     * @param int     $menu  Menu.
     * @param WP_Post $after Item.
     * @param WP_Post $page  Page.
     * @return bool
     */
    private static function add_menu_item($menu, $after, WP_Post $page) {
        $items = wp_get_nav_menu_items($menu, array('post_status' => 'any'));
        $order = (int) $after->menu_order + 1;
        foreach ((array) $items as $item) {
            if ((int) $item->menu_order >= $order) {
                wp_update_post(array('ID' => (int) $item->ID, 'menu_order' => (int) $item->menu_order + 1));
            }
        }
        $id = wp_update_nav_menu_item($menu, 0, array(
            'menu-item-object-id' => (int) $page->ID,
            'menu-item-object'    => 'page',
            'menu-item-type'      => 'post_type',
            'menu-item-parent-id' => (int) get_post_meta((int) $after->ID, '_menu_item_menu_item_parent', true),
            'menu-item-position'  => $order,
            'menu-item-status'    => 'publish',
        ));
        return !is_wp_error($id) && $id > 0;
    }

    /**
     * Block markup with a copy of the block holding the About link, linking
     * to the page instead, right after it.
     *
     * @param string   $content Widget content.
     * @param string[] $about   About paths.
     * @param string   $href    Page address.
     * @param string   $label   Link text.
     * @return string|null Null when no simple block holds the About link.
     */
    public static function insert_after_about($content, array $about, $href, $label) {
        $blocks = parse_blocks($content);
        $done   = false;
        $blocks = self::insert_in($blocks, $about, $href, $label, $done);
        return $done ? serialize_blocks($blocks) : null;
    }

    /**
     * Walk a list of blocks (and their inner blocks) for insert_after_about().
     *
     * @param array    $blocks Parsed blocks.
     * @param string[] $about  About paths.
     * @param string   $href   Page address.
     * @param string   $label  Link text.
     * @param bool     $done   Set once the copy is in.
     * @param array    $parent The parent block, whose innerContent follows.
     * @return array
     */
    private static function insert_in(array $blocks, array $about, $href, $label, &$done, ?array &$parent = null) {
        foreach ($blocks as $i => $block) {
            if ($done) {
                break;
            }
            if (!empty($block['innerBlocks'])) {
                $inner = $block['innerBlocks'];
                $blocks[$i]['innerBlocks'] = self::insert_in($inner, $about, $href, $label, $done, $blocks[$i]);
                continue;
            }
            $html = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
            // Only a block holding the one link (a paragraph or list item) is copied, never classic HTML.
            if (empty($block['blockName']) || in_array($block['blockName'], array('core/html', 'core/freeform', 'core/shortcode'), true) || 1 !== preg_match_all('/<a\b/i', $html) || !preg_match('/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>.*?<\/a>/is', $html, $m) || !in_array(self::path($m[2]), $about, true)) {
                continue;
            }
            $link = '<a href="' . esc_url($href) . '">' . esc_html($label) . '</a>';
            $copy = $block;
            $copy['innerHTML']    = str_replace($m[0], $link, $html);
            $copy['innerContent'] = array_map(function ($part) use ($m, $link) {
                return is_string($part) ? str_replace($m[0], $link, $part) : $part;
            }, $block['innerContent']);
            array_splice($blocks, $i + 1, 0, array($copy));
            if (null !== $parent) {
                // innerContent holds a null for each inner block, with the text between them.
                $seen = -1;
                foreach ($parent['innerContent'] as $at => $part) {
                    if (null === $part && ++$seen === $i) {
                        $gap = isset($parent['innerContent'][$at + 1]) && is_string($parent['innerContent'][$at + 1]) && '' === trim(wp_strip_all_tags($parent['innerContent'][$at + 1])) && isset($parent['innerContent'][$at + 2]) ? $parent['innerContent'][$at + 1] : "\n\n";
                        array_splice($parent['innerContent'], $at + 1, 0, array($gap, null));
                        break;
                    }
                }
            }
            $done = true;
            break;
        }
        return $blocks;
    }

    /**
     * Purge the page's cached copy.
     */
    public static function purge() {
        $page = self::page();
        if ($page && class_exists('SEOProStack_Page_Cache')) {
            SEOProStack_Page_Cache::purge_posts(array((int) $page->ID), true);
        }
    }

    /* ---------------------------------------------------------------------
     * The feature's card
     * ------------------------------------------------------------------- */

    /**
     * The card's page and footer buttons.
     *
     * @param string $key   Setting.
     * @param array  $field Field.
     */
    public static function panel($key, $field = array()) {
        if (self::KEY !== $key || !self::switched_on() || !current_user_can('edit_theme_options') || !current_user_can('publish_pages')) {
            return;
        }
        echo '<div class="sps-panel-note">';
        if (!self::applies()) {
            echo '<p>' . esc_html__('The block reads the Kadence theme’s settings, and Kadence is not the active theme.', 'seoprostack') . '</p></div>';
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $result = isset($_GET[self::RESULT]) ? sanitize_key(wp_unslash($_GET[self::RESULT])) : '';
        $messages = array(
            'created'  => __('The Brand assets page is published.', 'seoprostack'),
            'exists'   => __('The Brand assets page already shows the block.', 'seoprostack'),
            'kept'     => __('A page at /brand-assets/ exists without the block, so it was left as it is. Add the Brand assets block to it in the editor.', 'seoprostack'),
            'error'    => __('That could not be saved. Please try again.', 'seoprostack'),
            'footer-added'    => __('The footer now links to the Brand assets page, after About.', 'seoprostack'),
            'footer-exists'   => __('The footer already links to the Brand assets page.', 'seoprostack'),
            'footer-no-about' => __('No About link was found in the footer, so nothing was added. Add the link where it fits.', 'seoprostack'),
            'footer-no-page'  => __('Make the page first.', 'seoprostack'),
            'footer-error'    => __('That could not be saved. Please try again.', 'seoprostack'),
        );
        if (isset($messages[$result])) {
            echo '<p><strong>' . esc_html($messages[$result]) . '</strong></p>';
        }
        $page = self::page();
        if ($page && has_block(self::BLOCK, $page)) {
            echo '<p>' . esc_html__('Page:', 'seoprostack') . ' <a href="' . esc_url(get_permalink($page)) . '">' . esc_html(get_the_title($page)) . '</a>';
            $edit = current_user_can('edit_post', (int) $page->ID) ? get_edit_post_link((int) $page->ID) : '';
            if ($edit) {
                echo ' (<a href="' . esc_url($edit) . '">' . esc_html__('edit', 'seoprostack') . '</a>)';
            }
            echo '</p>';
        }
        echo '<p>';
        if (!$page || !has_block(self::BLOCK, $page)) {
            echo '<a class="button" href="' . esc_url(self::action_url('page')) . '">' . esc_html__('Make the Brand assets page', 'seoprostack') . '</a> ';
        }
        if ($page && 'publish' === $page->post_status) {
            echo '<a class="button" href="' . esc_url(self::action_url('footer')) . '">' . esc_html__('Link it from the footer', 'seoprostack') . '</a>';
        }
        echo '</p>';
        echo '<p class="description">' . esc_html__('The footer link goes after the About link in the footer’s widgets or menu, and is not added twice.', 'seoprostack') . '</p>';
        echo '</div>';
    }

    /**
     * A card button's address.
     *
     * @param string $do page or footer.
     * @return string
     */
    private static function action_url($do) {
        return wp_nonce_url(add_query_arg(array('action' => self::ACTION, 'do' => $do), admin_url('admin-post.php')), self::ACTION . '_' . $do);
    }

    /** Capability and nonce protected page and footer buttons. */
    public static function act() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below for this value.
        $do = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
        if (!in_array($do, array('page', 'footer'), true) || !current_user_can('edit_theme_options') || !current_user_can('publish_pages')) {
            wp_die(esc_html__('You cannot change this site’s pages.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION . '_' . $do);
        $result = 'page' === $do ? self::create_page() : self::add_footer_link();
        $url    = class_exists('SEOProStack_Admin_Manager') ? SEOProStack_Admin_Manager::tab_url('content') : admin_url('options-general.php?page=seoprostack&tab=content');
        wp_safe_redirect(add_query_arg(self::RESULT, ('footer' === $do ? 'footer-' : '') . $result['status'], $url));
        exit;
    }

    /**
     * Drop the card's result from the address once shown.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::RESULT;
        return $args;
    }
}
