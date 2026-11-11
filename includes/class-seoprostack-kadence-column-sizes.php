<?php
/**
 * Pictures in a Kadence Blocks row column: a `sizes` that says how wide the
 * column can be on a computer (GH#1517), for Image loading
 * (SEOProStack_Image_Loading).
 *
 * WordPress gives a picture `sizes="(max-width: Wpx) 100vw, Wpx"`: the full
 * window up to the picture's own width. A picture in a column a third of a
 * 1,350 pixel window wide was fetched for 768 or 1,024 pixels. Pictures that
 * wait (loading="lazy") have `sizes="auto, …"`, so the browser takes the
 * width they show at; pictures that load straight away, often the page's
 * largest (LCP), cannot.
 *
 * Each row tells its columns their share of the row on a computer, read
 * from the row's attributes as Kadence Blocks reads them for its CSS
 * (columns, layout, custom widths), and the most pixels the row can be wide
 * when it has a maximum width in px (the Kadence theme's content width, or
 * its own). Gaps and padding are left out, and blocks inside a column, rows
 * too, take its share, so every value is at least the width the picture
 * shows at: never less sharp. A WordPress Image or Kadence Blocks Image
 * block in a column marks its picture; after WordPress adds `srcset` and
 * `sizes`, the mark becomes entries for windows from 1,025 pixels (Kadence's
 * computer layout) before WordPress's own, which tablets and phones still
 * read. A picture cropped to a shape (an Image block's aspect ratio, a
 * Kadence Image block's ratio) is counted as wide as it paints. Only
 * WordPress's own `sizes` form is changed.
 *
 * Left as before: Kadence's old row markup, rows with a column that breaks
 * out to the window's edge, rows whose columns may be hidden or left out on
 * a computer (so the next would take its place), layouts Kadence does not
 * name, wide and full-width blocks and what is inside them, galleries,
 * covers, media and text blocks, and other blocks' pictures.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.198
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Column_Sizes {

    /** Key on a parsed block: its share of the window and most pixels, array(float, float|null). */
    const SPAN = 'seoprostackSpan';

    /** Temporary attribute on a picture in a column; content_image() removes it. */
    const MARK = 'data-seoprostack-span';

    /** Narrowest window of Kadence Blocks' computer layout. */
    const DESKTOP = 1025;

    /** Fewest pixels a change must save at the narrowest computer window. */
    const MIN_SAVING = 16;

    /** Blocks whose inner blocks may be cropped or wider than the block. */
    const STOP = array('core/gallery', 'core/cover', 'core/media-text');

    /** Kadence Blocks Image ratios: the box's height as a share of its width. */
    const KADENCE_RATIOS = array(
        'square'  => 1.0,
        'land43'  => 0.75,
        'land32'  => 0.6667,
        'land169' => 0.5625,
        'land21'  => 0.5,
        'land31'  => 0.33,
        'land41'  => 0.25,
        'port34'  => 1.3333,
        'port23'  => 1.5,
    );

    /** @var array<int,bool> Whether the filter allows it, by post ID. */
    private static $allowed = array();

    /**
     * Register hooks.
     */
    public static function boot() {
        add_filter('render_block_data', array(__CLASS__, 'block_data'), 10, 3);
        add_filter('render_block_core/image', array(__CLASS__, 'mark_image'), 40, 2);
        add_filter('render_block_kadence/image', array(__CLASS__, 'mark_image'), 40, 2);
        // After own_sizes() (5) and core's srcset and auto sizes, before
        // content_image() (20).
        add_filter('wp_content_img_tag', array(__CLASS__, 'content_image'), 15);
    }

    /**
     * Before a row renders, give each of its columns its share; before any
     * other block with a share renders, give its inner blocks the same.
     *
     * @param array         $block  Parsed block.
     * @param array         $source Parsed block before filters.
     * @param WP_Block|null $parent Parent block.
     * @return array
     */
    public static function block_data($block, $source = array(), $parent = null) {
        if (!is_array($block) || empty($block['innerBlocks']) || !is_array($block['innerBlocks'])) {
            return $block;
        }
        $name = $block['blockName'] ?? '';
        if ('kadence/rowlayout' === $name) {
            return self::row_data($block);
        }
        if (!isset($block[self::SPAN]) || !is_array($block[self::SPAN]) || in_array($name, self::STOP, true) || self::wide($block)) {
            return $block;
        }
        foreach ($block['innerBlocks'] as $key => $inner) {
            if (is_array($inner)) {
                $block['innerBlocks'][$key][self::SPAN] = $block[self::SPAN];
            }
        }
        return $block;
    }

    /**
     * Whether a block is wide or full width, so it may be wider than the
     * column it is in.
     *
     * @param array $block Parsed block.
     * @return bool
     */
    private static function wide(array $block) {
        return in_array($block['attrs']['align'] ?? '', array('full', 'wide'), true);
    }

    /**
     * A row's columns, each with its share of the window and most pixels.
     *
     * @param array $block Parsed row block.
     * @return array
     */
    private static function row_data(array $block) {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        if (empty($attrs['kbVersion']) || (int) $attrs['kbVersion'] < 2 || !empty($attrs['breakoutLeft']) || !empty($attrs['breakoutRight'])
            || !self::desktop_from_1025()) {
            return $block;
        }
        // A row in a column is no wider than it, unless it is wide or full
        // width, which may break out of it: then the window bounds it.
        $outer = array(1.0, null);
        if (isset($block[self::SPAN]) && is_array($block[self::SPAN]) && !self::wide($block)) {
            $outer = $block[self::SPAN];
        }
        $cap = self::row_cap($attrs);
        if (null !== $outer[1]) {
            $cap = null === $cap ? $outer[1] : min($cap, $outer[1]);
        }
        $columns = array();
        foreach ($block['innerBlocks'] as $key => $inner) {
            if (!is_array($inner) || 'kadence/column' !== ($inner['blockName'] ?? '')) {
                continue;
            }
            if (self::may_hide(isset($inner['attrs']) && is_array($inner['attrs']) ? $inner['attrs'] : array())) {
                return $block;
            }
            $columns[] = $key;
        }
        $count = isset($attrs['columns']) && is_numeric($attrs['columns']) && (int) $attrs['columns'] > 0 ? (int) $attrs['columns'] : 2;
        $spans = array();
        foreach ($columns as $position => $key) {
            $share = self::share($attrs, $count, $position % $count);
            if (null === $share) {
                return $block;
            }
            $spans[$key] = array($outer[0] * $share, null === $cap ? null : $cap * $share);
        }
        foreach ($spans as $key => $span) {
            $block['innerBlocks'][$key][self::SPAN] = $span;
        }
        return $block;
    }

    /**
     * Whether a column may be hidden, or left out, on a computer: the next
     * column would then take its place in the grid.
     *
     * @param array $attrs Column attributes.
     * @return bool
     */
    private static function may_hide(array $attrs) {
        foreach ($attrs as $name => $value) {
            if (!empty($value) && preg_match('/^(?:vsdesk|logged|kadenceConditional|conditional|visibility|hide|show)/i', (string) $name)) {
                return true;
            }
        }
        if (isset($attrs['metadata']) && is_array($attrs['metadata']) && array_key_exists('blockVisibility', $attrs['metadata'])) {
            return true;
        }
        return isset($attrs['className']) && is_string($attrs['className']) && false !== stripos($attrs['className'], 'hid');
    }

    /**
     * The most pixels a row's columns can share on a computer, or null.
     *
     * @param array $attrs Row attributes.
     * @return float|null
     */
    private static function row_cap(array $attrs) {
        if (true === ($attrs['inheritMaxWidth'] ?? null)) {
            return self::content_width();
        }
        $unit = $attrs['maxWidthUnit'] ?? '';
        if (isset($attrs['maxWidth']) && is_numeric($attrs['maxWidth']) && (float) $attrs['maxWidth'] > 0 && in_array($unit, array('', 'px'), true)) {
            return (float) $attrs['maxWidth'];
        }
        return null;
    }

    /**
     * The width a row with inheritMaxWidth keeps to, in px, as Kadence
     * Blocks sets it: with the Kadence theme its content width, else the
     * theme's $content_width; null when not in px (em and rem follow the
     * visitor's font size) or not set.
     *
     * @return float|null
     */
    private static function content_width() {
        global $content_width;

        if (!isset($content_width)) {
            return null;
        }
        if (class_exists('Kadence\Theme')) {
            $theme = function_exists('Kadence\kadence') ? \Kadence\kadence() : null;
            // Its template tags reach sub_option() through __call().
            if (!is_object($theme) || !is_callable(array($theme, 'sub_option'))) {
                return null;
            }
            $size = $theme->sub_option('content_width', 'size');
            $unit = $theme->sub_option('content_width', 'unit');
            return is_numeric($size) && (float) $size > 0 && in_array($unit, array('', 'px', null), true) ? (float) $size : null;
        }
        return is_numeric($content_width) && absint($content_width) > 0 ? (float) absint($content_width) : null;
    }

    /**
     * Whether Kadence Blocks' computer layout starts at 1,025 pixels, as it
     * does unless its tablet media query is filtered.
     *
     * @return bool
     */
    private static function desktop_from_1025() {
        static $ok = null;
        if (null === $ok) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Kadence Blocks' hook, read only.
            $ok = '(max-width: 1024px)' === apply_filters('kadence_tablet_media_query', '(max-width: 1024px)');
        }
        return $ok;
    }

    /**
     * A column's share of its row on a computer, as Kadence Blocks'
     * get_template_columns() lays it out (gaps left out), or null for a
     * layout it does not name.
     *
     * @param array $attrs  Row attributes.
     * @param int   $count  Columns in the row.
     * @param int   $column The column's track, from 0.
     * @return float|null
     */
    private static function share(array $attrs, $count, $column) {
        $widths = array();
        foreach (array('first', 'second', 'third', 'fourth', 'fifth', 'sixth') as $name) {
            $value = $attrs[$name . 'ColumnWidth'] ?? '';
            // Empty: not set; false: set to what this cannot read.
            $widths[] = empty($value) ? null : (is_numeric($value) ? (float) $value : false);
        }
        $set = array_slice($widths, 0, max(1, $count - 1));
        // Kadence uses custom widths when all but the last are set (the first
        // for two columns); its fixed 50/25, 25/50 and 25/25 three-column
        // cases are the same shares.
        if ($count >= 2 && $count <= 6 && !in_array(null, $set, true)) {
            if (in_array(false, $set, true)) {
                return null;
            }
            $sum = array_sum($set);
            // The last column takes what is left of 100, as get_custom_layout() has it.
            $width = $column === $count - 1 ? abs($sum - 100) : $widths[$column];
            return $width > 0 && $width <= 100 ? $width / 100 : null;
        }
        $layout = !empty($attrs['colLayout']) && is_string($attrs['colLayout']) ? $attrs['colLayout'] : 'equal';
        $fr     = self::layout($count, $layout);
        if (null === $fr || is_float($fr)) {
            return $fr;
        }
        if (isset($fr['span'])) {
            // One column of each set spans the row; the others share it.
            return $column === $fr['span'] ? 1.0 : $fr['others'];
        }
        $total = array_sum($fr);
        return isset($fr[$column]) && $total > 0 ? $fr[$column] / $total : null;
    }

    /**
     * Kadence's named layouts on a computer: the fr of each track, one share
     * for every column, or which column of each set spans the row.
     *
     * @param int    $count  Columns.
     * @param string $layout Layout name.
     * @return array|float|null
     */
    private static function layout($count, $layout) {
        $all = array(
            1 => array('grid-layout' => null, '' => 1.0),
            2 => array('left-golden' => array(2, 1), 'right-golden' => array(1, 2), 'row' => 1.0, '' => 0.5),
            3 => array(
                'left-half'     => array(2, 1, 1),
                'right-half'    => array(1, 1, 2),
                'center-half'   => array(1, 2, 1),
                'center-wide'   => array(1, 3, 1),
                'center-exwide' => array(1, 6, 1),
                'first-row'     => array('span' => 0, 'others' => 0.5),
                'last-row'      => array('span' => 2, 'others' => 0.5),
                'two-grid'      => 0.5,
                'row'           => 1.0,
                ''              => 1 / 3,
            ),
            4 => array('left-forty' => array(2, 1, 1, 1), 'right-forty' => array(1, 1, 1, 2), 'two-grid' => 0.5, 'row' => 1.0, '' => 0.25),
            5 => array(
                'first-row'  => array('span' => 0, 'others' => 0.25),
                'last-row'   => array('span' => 4, 'others' => 0.25),
                'two-grid'   => 0.5,
                'three-grid' => 1 / 3,
                'row'        => 1.0,
                ''           => 0.2,
            ),
            6 => array('two-grid' => 0.5, 'three-grid' => 1 / 3, 'row' => 1.0, '' => 1 / 6),
        );
        if (!isset($all[$count])) {
            return null;
        }
        // Kadence gives any other name its default for the count.
        return array_key_exists($layout, $all[$count]) ? $all[$count][$layout] : $all[$count][''];
    }

    /**
     * Mark the picture of a WordPress Image or Kadence Blocks Image block in
     * a column, on the content of a front-end page: its share, most pixels
     * and, when it is cropped to a box, the box's height as a share of its
     * width.
     *
     * @param mixed $html  Block HTML.
     * @param array $block Parsed block.
     * @return mixed
     */
    public static function mark_image($html, $block) {
        if (!is_string($html) || false === stripos($html, '<img') || !is_array($block) || !isset($block[self::SPAN]) || !is_array($block[self::SPAN])
            || self::wide($block) || !doing_filter('the_content') || is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $html;
        }
        list($share, $cap) = $block[self::SPAN];
        $share             = is_numeric($share) ? (float) $share : 0.0;
        if ($share <= 0 || $share > 1 || ($share >= 1 && null === $cap) || !self::allowed()) {
            return $html;
        }
        $box = self::box($html, $block);
        if (null === $box) {
            return $html;
        }
        $value = self::up($share, 10000) . ',' . (null === $cap ? '' : (string) (int) ceil((float) $cap)) . ',' . self::up($box, 10000);
        $tags  = new WP_HTML_Tag_Processor($html);
        if ($tags->next_tag(array('tag_name' => 'IMG')) && null === $tags->get_attribute(self::MARK)) {
            $tags->set_attribute(self::MARK, $value);
        }
        return $tags->get_updated_html();
    }

    /**
     * The box an Image block crops its picture to, as its height over its
     * width: 0 when the picture is not cropped, null when it cannot be told.
     *
     * @param string $html  Block HTML.
     * @param array  $block Parsed block.
     * @return float|null
     */
    private static function box($html, array $block) {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        if ('kadence/image' === ($block['blockName'] ?? '')) {
            if (false === strpos($html, 'kb-is-ratio-image')) {
                return 0.0;
            }
            return preg_match('/\bkb-image-ratio-([a-z0-9]+)\b/', $html, $match) && isset(self::KADENCE_RATIOS[$match[1]]) ? self::KADENCE_RATIOS[$match[1]] : null;
        }
        $ratio = $attrs['aspectRatio'] ?? '';
        if ('' === $ratio || 'auto' === $ratio) {
            // A set height without an aspect ratio may crop too.
            return empty($attrs['height']) ? 0.0 : null;
        }
        if ('contain' === ($attrs['scale'] ?? 'cover')) {
            return 0.0;
        }
        if (!is_string($ratio) || !preg_match('#^\s*(\d+(?:\.\d+)?)\s*(?:/\s*(\d+(?:\.\d+)?)\s*)?$#', $ratio, $match)) {
            return null;
        }
        $wide = (float) $match[1];
        // Without a second number the ratio is width over 1.
        $high = isset($match[2]) ? (float) $match[2] : 1.0;
        return $wide > 0 && $high > 0 ? $high / $wide : null;
    }

    /**
     * A number rounded up to a step, as text without trailing zeros.
     *
     * @param float $value Number.
     * @param int   $steps Steps per unit.
     * @return string
     */
    private static function up($value, $steps) {
        // round() first, so float noise (0.2 × 10000) does not add a step.
        $up = ceil(round((float) $value * $steps, 6)) / $steps;
        return rtrim(rtrim(number_format($up, 4, '.', ''), '0'), '.');
    }

    /**
     * Whether the seoprostack_kadence_column_sizes filter allows it for the
     * post shown.
     *
     * @return bool
     */
    private static function allowed() {
        $post = get_post();
        $id   = $post instanceof WP_Post ? (int) $post->ID : 0;
        if (!isset(self::$allowed[$id])) {
            /**
             * Whether pictures in a Kadence Blocks row column get a `sizes`
             * for the column's width on a computer, with Image loading on.
             * Off: WordPress's `sizes`, as before.
             *
             * @param bool         $on   Default true.
             * @param WP_Post|null $post Post shown.
             */
            self::$allowed[$id] = (bool) apply_filters('seoprostack_kadence_column_sizes', true, $post instanceof WP_Post ? $post : null);
        }
        return self::$allowed[$id];
    }

    /**
     * Turn a picture's mark into `sizes` entries for computers, before
     * WordPress's own, and remove it.
     *
     * @param mixed $image HTML img tag.
     * @return mixed
     */
    public static function content_image($image) {
        if (!is_string($image) || false === strpos($image, self::MARK)) {
            return $image;
        }
        $tag = new WP_HTML_Tag_Processor($image);
        if (!$tag->next_tag(array('tag_name' => 'IMG'))) {
            return $image;
        }
        $mark = $tag->get_attribute(self::MARK);
        if (null === $mark) {
            return $image;
        }
        $tag->remove_attribute(self::MARK);
        $parts = is_string($mark) ? explode(',', $mark) : array();
        $sizes = $tag->get_attribute('sizes');
        if (3 === count($parts) && is_numeric($parts[0]) && is_numeric($parts[2]) && is_string($sizes) && is_string($tag->get_attribute('srcset'))) {
            $share = (float) $parts[0];
            $cap   = is_numeric($parts[1]) ? (float) $parts[1] : null;
            $box   = (float) $parts[2];
            if ($box > 0) {
                // Cropped to a box: the picture paints wider than the box
                // when it is wider in proportion than the box.
                $width  = (int) $tag->get_attribute('width');
                $height = (int) $tag->get_attribute('height');
                $grow   = $width > 0 && $height > 0 ? max(1.0, $width / $height * $box) : 0.0;
                $share *= $grow;
                $cap    = null === $cap ? null : $cap * $grow;
            }
            $new = $share > 0 ? self::sizes($sizes, $share, $cap) : null;
            if (null !== $new) {
                $tag->set_attribute('sizes', $new);
            }
        }
        return $tag->get_updated_html();
    }

    /**
     * WordPress's `sizes` with entries for computers before it, or null when
     * it is not WordPress's form or would save too little.
     *
     * For windows from 1,025 pixels the picture shows at most share ×
     * window, the most pixels, and its own width W: from the window where
     * share × window reaches the smaller limit, that limit in px; below it,
     * share × window in vw.
     *
     * @param string     $sizes The picture's sizes.
     * @param float      $share Share of the window.
     * @param float|null $cap   Most pixels, or null.
     * @return string|null
     */
    public static function sizes($sizes, $share, $cap) {
        $sizes = trim((string) $sizes);
        if (!preg_match('/^(auto,\s*)?\(max-width:\s*(\d+)px\)\s+100vw,\s*(\d+)px$/i', $sizes, $match) || $match[2] !== $match[3]) {
            return null;
        }
        $share = (float) $share;
        $width = (float) $match[2];
        if ($share <= 0 || $width <= 0) {
            return null;
        }
        $most = null === $cap ? $width : min($width, (float) $cap);
        // WordPress's sizes at the narrowest computer window, and this one's.
        $before = min((float) self::DESKTOP, $width);
        $after  = min($share * self::DESKTOP, $most);
        if ($before - $after < self::MIN_SAVING && $most >= $width) {
            return null;
        }
        $from    = $most / $share;
        $entries = array();
        if ($from <= self::DESKTOP) {
            $entries[] = '(min-width: ' . self::DESKTOP . 'px) ' . (int) ceil($most) . 'px';
        } else {
            $entries[] = '(min-width: ' . (int) ceil($from) . 'px) ' . (int) ceil($most) . 'px';
            $entries[] = '(min-width: ' . self::DESKTOP . 'px) ' . self::up($share * 100, 100) . 'vw';
        }
        return (empty($match[1]) ? '' : 'auto, ') . implode(', ', $entries) . ', ' . trim(substr($sizes, strlen($match[1])));
    }
}
