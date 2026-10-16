<?php
/**
 * Kadence colour check: Customizer colours that do not follow the palette.
 *
 * The Kadence Customizer saves each colour as a palette colour (palette3)
 * or a fixed one (#f2f2f2). Palette colours follow Kadence Pro's dark mode,
 * which swaps the palette on the body; fixed ones stay. So fixed text on a
 * palette background, or palette text on a fixed background, reads in one
 * mode only: a light footer credit left from an older dark footer vanishes
 * on a light palette background, and white button text sits on a pale tint
 * once dark mode lightens the accent.
 *
 * Site Health lists each such pair whose contrast is below WCAG AA (4.5:1,
 * 3:1 for buttons) in the light palette or the dark one, with both values,
 * and offers the palette colour, nearest the saved one, that passes in both
 * with every colour paired with it. The fix first keeps the values it
 * changes, and Restore puts them back. Pairs of two fixed colours or two
 * palette colours are left alone: they look the same, or change together,
 * in both modes.
 *
 * Text is paired with the background of the same name (header_button_color
 * with header_button_background), dropping words from the end until one is
 * set; a footer row without a background of its own takes the footer's.
 * Text whose background is not saved with it, such as header items, which
 * sit in whichever row they were put in, is not checked.
 *
 * Element styles change what a colour paints, as Kadence's CSS does (GitHub
 * issue #781): social icons in the outline style have no background of
 * their own and brand-coloured ones ignore the saved colours, so they are
 * not checked; a pill category style paints the category colour behind
 * Kadence's white text, so that pair is checked instead, with no fix offered
 * for the white.
 *
 * The check reads Kadence's settings only, so when it lists something on a
 * site with CSS of its own (Additional CSS, YellowPencil, a child theme's
 * rules), it names those and says to look at the page in both modes, since
 * they can change the colours (GitHub issue #785).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.49
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Colour_Check extends SEOProStack_Feature {

    const KEY = 'kadence_colour_check';

    /** admin-post action; each fix's nonce adds what it fixes. */
    const ACTION = 'seoprostack_kadence_colours';

    /** Query argument naming what a fix did, for the notice after it. */
    const DONE = 'seoprostack_kadence_colours_done';

    /** Query argument with the number of colours a fix changed. */
    const COUNT = 'seoprostack_kadence_colours_count';

    /** Option (not autoloaded): the theme's values before the fixes, for Restore. */
    const BACKUP = 'seoprostack_kadence_colours_backup';

    /** WCAG AA contrast for text, and for large text such as buttons. */
    const TEXT_MIN  = 4.5;
    const LARGE_MIN = 3.0;

    /** Palette colours a fix may use: palette1 to palette9 (10 to 15 are the complement and notices). */
    const SLOTS = 9;

    /** Theme settings holding text colours; the first group names their background. */
    const TEXT_KEY = '/^(.+?)_(?:colors?|typography|font|widget_title|widget_content)$/';

    /** Settings with those endings that are not text on a background. */
    const NOT_TEXT = '/border|shadow|divider|overlay|background|dark_mode|toggle/';

    /** States a colour setting can hold. */
    const STATES = array('color', 'hover', 'active');

    /**
     * The findings, worked out once per request.
     *
     * @var array<string,array{field:array,pairs:array[],slot:string|null}>|null
     */
    private static $findings = null;

    /**
     * The palettes, read once per request (fixes change settings, not palettes).
     *
     * @var array<string,array<string,string>>|null
     */
    private static $palettes = null;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'content',
                'label'       => __('Kadence colour check', 'seoprostack'),
                'description' => __('Site Health lists Kadence Customizer text and background colours where one follows the palette and the other is a fixed colour, so they are hard to read in light or dark mode, and offers to switch the fixed one to the palette colour that reads in both. Nothing changes until you click; Restore puts the old colours back. Nothing shows without the Kadence theme.', 'seoprostack'),
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
        // Site Health also runs its tests from cron, outside wp-admin.
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        if (!is_admin()) {
            return;
        }
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'act'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Whether the Kadence theme (or a child of it) is active and loaded.
     *
     * @return bool
     */
    private static function applies() {
        return 'kadence' === get_template() && function_exists('Kadence\kadence');
    }

    /**
     * A theme setting as Kadence reads it: the saved value, else its default.
     *
     * @param string $key Setting.
     * @return mixed
     */
    private static function option($key) {
        $theme = \Kadence\kadence();
        return is_object($theme) && is_callable(array($theme, 'option')) ? $theme->option($key) : get_theme_mod($key);
    }

    /**
     * Whether Kadence keeps its settings as theme mods, which the fix writes.
     *
     * @return bool
     */
    private static function writable() {
        $theme = \Kadence\kadence();
        return is_object($theme) && is_callable(array($theme, 'get_option_type')) && 'theme_mod' === $theme->get_option_type();
    }

    /**
     * Add the Site Health test on Kadence sites.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        if (self::applies()) {
            $tests['direct']['seoprostack_kadence_colours'] = array(
                'label' => __('Kadence colours in light and dark mode', 'seoprostack'),
                'test'  => array(__CLASS__, 'test'),
            );
        }
        return $tests;
    }

    /**
     * Site Health test.
     *
     * @return array
     */
    public static function test() {
        $findings = self::findings();
        $dark     = isset(self::palettes()['dark']);
        $can      = current_user_can('edit_theme_options') && self::writable();
        $text     = '<p>' . esc_html(
            $dark
                ? __('Kadence’s Customizer colours that follow the palette change with Kadence Pro’s dark mode; fixed colours stay. A fixed text colour on a palette background, or the other way round, can then be hard to read in one of the two modes.', 'seoprostack')
                : __('Kadence’s Customizer colours that follow the palette change when the palette does, as with Kadence Pro’s dark mode; fixed colours stay. A fixed text colour on a palette background, or the other way round, can then be hard to read.', 'seoprostack')
        ) . '</p>';
        $links = array();
        if ($findings) {
            $text .= '<ul>';
            foreach ($findings as $id => $finding) {
                $text .= '<li>' . self::describe($finding) . '</li>';
                if ($can && null !== $finding['slot']) {
                    $links[] = self::link($id, sprintf(
                        /* translators: 1: setting name, 2: palette colour number. */
                        __('%1$s: use palette %2$s', 'seoprostack'),
                        self::label($finding['field']),
                        substr($finding['slot'], 7)
                    ));
                }
            }
            $text .= '</ul>';
            $own   = self::custom_css();
            if ($own) {
                $text .= '<p>' . esc_html(sprintf(
                    /* translators: %s: where the site adds CSS, such as "Additional CSS and YellowPencil". */
                    __('This check reads Kadence’s settings only. The site’s own CSS (%s) can change these colours, so look at the page in light and dark mode before you change one.', 'seoprostack'),
                    wp_sprintf_l('%l', $own)
                )) . '</p>';
            }
            if ($can && count($links) > 1) {
                array_unshift($links, self::link('all', __('Fix them all', 'seoprostack')));
            }
            if ($can) {
                $text .= '<p>' . esc_html__('A fix keeps the colours it replaces, so Restore can put them back. Clear the page cache afterwards if pages still show the old colours.', 'seoprostack') . '</p>';
            }
        }
        if ($can && self::backup_ready()) {
            $links[] = self::link('restore', __('Restore the colours from before the fix', 'seoprostack'));
        }
        if (current_user_can('customize')) {
            $links[] = sprintf('<a href="%1$s">%2$s</a>', esc_url(admin_url('customize.php')), esc_html__('Customizer', 'seoprostack'));
        }
        return array(
            'label'       => $findings
                ? __('Some Kadence colours are hard to read in light or dark mode', 'seoprostack')
                : __('Kadence colours read well in light and dark mode', 'seoprostack'),
            'status'      => $findings ? 'recommended' : 'good',
            'badge'       => array(
                'label' => __('Accessibility', 'seoprostack'),
                'color' => 'blue',
            ),
            'description' => $text,
            'actions'     => $links ? '<p>' . implode(' | ', $links) . '</p>' : '',
            'test'        => 'seoprostack_kadence_colours',
        );
    }

    /**
     * Where the site adds CSS of its own, which can change the colours this
     * check works out from Kadence's settings (GitHub issue #785): Additional
     * CSS and a child theme's style.css with rules, YellowPencil with saved
     * styles, and any a plugin names with the seoprostack_kadence_custom_css
     * filter.
     *
     * @return string[] Names for people.
     */
    private static function custom_css() {
        $sources = array();
        if (self::has_rules(wp_get_custom_css())) {
            $sources[] = __('Additional CSS', 'seoprostack');
        }
        if (defined('YP_VERSION') && self::yellow_pencil_css()) {
            $sources[] = 'YellowPencil';
        }
        if (get_stylesheet() !== get_template()) {
            $file = get_stylesheet_directory() . '/style.css';
            // Rules, not only the header comment every child theme has.
            if (is_readable($file) && self::has_rules((string) file_get_contents($file))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the child theme's local stylesheet.
                $sources[] = __('the child theme’s style.css', 'seoprostack');
            }
        }
        $sources = apply_filters('seoprostack_kadence_custom_css', $sources);
        return array_values(array_unique(array_filter(is_array($sources) ? $sources : array(), static function ($source) {
            return is_string($source) && '' !== trim($source);
        })));
    }

    /**
     * Whether CSS has a rule once its comments are taken out.
     *
     * @param string $css CSS.
     * @return bool
     */
    private static function has_rules($css) {
        return false !== strpos((string) preg_replace('#/\*.*?\*/#s', '', $css), '{');
    }

    /**
     * Whether YellowPencil has saved styles: for the whole site or a kind of
     * page (wt_css, wt_home_css and the like), or for one post (_wt_css).
     *
     * @return bool
     */
    private static function yellow_pencil_css() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's settings, read once when Site Health lists colours.
        $option = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value <> '' LIMIT 1", $wpdb->esc_like('wt_') . '%' . $wpdb->esc_like('css')));
        if ($option) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- as above; one indexed key.
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1", '_wt_css'));
    }

    /**
     * One finding as HTML: the fixed colour, what it is paired with and the
     * contrast in each mode.
     *
     * @param array $finding From findings().
     * @return string
     */
    private static function describe(array $finding) {
        $parts = array();
        foreach ($finding['pairs'] as $pair) {
            $ratios = array();
            foreach (self::ratios($pair) as $mode => $ratio) {
                $ratios[] = sprintf(
                    /* translators: 1: light or dark, 2: contrast ratio such as 4.5. */
                    __('%1$s %2$s:1', 'seoprostack'),
                    'dark' === $mode ? __('dark', 'seoprostack') : __('light', 'seoprostack'),
                    number_format_i18n($ratio, 1)
                );
            }
            $parts[] = sprintf(
                /* translators: 1: text setting and colour, 2: background setting and colour, 3: contrast in each mode, 4: contrast needed. */
                __('%1$s on %2$s: %3$s (needs %4$s:1)', 'seoprostack'),
                '<strong>' . esc_html(self::label($pair['text'])) . '</strong> <code>' . esc_html($pair['text']['value']) . '</code>',
                '<strong>' . esc_html(self::label($pair['bg'])) . '</strong> <code>' . esc_html($pair['bg']['value']) . '</code>',
                esc_html(implode(', ', $ratios)),
                esc_html(number_format_i18n($pair['min'], 1))
            );
        }
        $fix = !empty($finding['field']['locked'])
            ? esc_html__('Kadence makes this text white; choose a pill colour in the Customizer that is dark in both modes.', 'seoprostack')
            : (null === $finding['slot']
            ? esc_html__('No palette colour reads in both modes here; change it in the Customizer.', 'seoprostack')
            : esc_html(sprintf(
                /* translators: %s: palette colour number. */
                __('Palette %s reads in both.', 'seoprostack'),
                substr($finding['slot'], 7)
            )));
        return implode('<br>', $parts) . '<br>' . $fix;
    }

    /**
     * A setting's name for people: its key in words, with its state.
     *
     * @param array $field Field (key, path, value).
     * @return string
     */
    private static function label(array $field) {
        if (isset($field['label']) && is_string($field['label'])) {
            return $field['label'];
        }
        $name  = ucfirst(trim(str_replace(array('_', '-'), ' ', $field['key'])));
        $state = end($field['path']);
        if ('hover' === $state) {
            /* translators: %s: setting name. */
            return sprintf(__('%s (hover)', 'seoprostack'), $name);
        }
        if ('active' === $state) {
            /* translators: %s: setting name. */
            return sprintf(__('%s (active)', 'seoprostack'), $name);
        }
        return $name;
    }

    /**
     * A fix's link.
     *
     * @param string $fix   Finding ID, all or restore.
     * @param string $label Link text.
     * @return string
     */
    private static function link($fix, $label) {
        $url = wp_nonce_url(add_query_arg(array('action' => self::ACTION, 'fix' => $fix), admin_url('admin-post.php')), self::ACTION . '-' . $fix);
        return sprintf('<a href="%1$s">%2$s</a>', esc_url($url), esc_html($label));
    }

    /**
     * The palettes: light (the active set) and, while Kadence Pro's dark
     * mode is on, dark (its dark palette), each slot => colour.
     *
     * @return array<string,array<string,string>>
     */
    private static function palettes() {
        if (null !== self::$palettes) {
            return self::$palettes;
        }
        $theme    = \Kadence\kadence();
        $defaults = self::palette_sets(is_object($theme) && is_callable(array($theme, 'get_default_palette')) ? $theme->get_default_palette() : '');
        $stored   = self::palette_sets(get_option('kadence_global_palette'));
        $sets     = $stored['sets'] ? $stored : $defaults;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the Kadence theme's own filter, applied as the theme does.
        $active   = apply_filters('kadence_active_palette', '' !== $sets['active'] ? $sets['active'] : 'palette');
        $names    = array('light' => is_string($active) ? $active : 'palette');
        if (defined('KTP_VERSION') && self::option('dark_mode_enable')) {
            $dark          = self::option('dark_mode_dark_palette');
            $names['dark'] = is_string($dark) && '' !== $dark ? $dark : 'second-palette';
        }
        $palettes = array();
        foreach ($names as $mode => $name) {
            $own             = isset($sets['sets'][$name]) ? $sets['sets'][$name] : array();
            $base            = isset($defaults['sets'][$name]) ? $defaults['sets'][$name] : (isset($defaults['sets']['palette']) ? $defaults['sets']['palette'] : array());
            $palettes[$mode] = array_merge($base, $own);
        }
        self::$palettes = $palettes;
        return $palettes;
    }

    /**
     * Kadence's palette sets from its stored JSON (or the decoded array a
     * site may filter it into).
     *
     * @param mixed $stored Stored palette.
     * @return array{active:string,sets:array<string,array<string,string>>}
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
                if (is_array($item) && isset($item['slug'], $item['color']) && is_string($item['slug']) && is_string($item['color']) && '' !== $item['color']) {
                    $out['sets'][$name][$item['slug']] = $item['color'];
                }
            }
        }
        return $out;
    }

    /**
     * Pairs of a fixed colour and a palette colour, grouped by the fixed
     * setting, where a pair is hard to read in either mode, each with the
     * palette colour that would fix it (or null). Pairs of two fixed colours
     * that share the fixed setting are kept with it, so a fix keeps them
     * readable too.
     *
     * @return array<string,array{field:array,pairs:array[],slot:string|null}> ID => finding.
     */
    private static function findings() {
        if (null !== self::$findings) {
            return self::$findings;
        }
        self::$findings = array();
        if (!self::applies()) {
            return self::$findings;
        }
        $groups = array();
        $bad    = array();
        foreach (self::pairs() as $pair) {
            $text_slot = '' !== self::slot($pair['text']['value']);
            $bg_slot   = '' !== self::slot($pair['bg']['value']);
            if ($text_slot && $bg_slot) {
                continue;
            }
            $failing = $text_slot !== $bg_slot && self::worst($pair) < $pair['min'];
            foreach (array('text' => $text_slot, 'bg' => $bg_slot) as $side => $is_slot) {
                if ($is_slot) {
                    continue;
                }
                $id                       = self::id($pair[$side]);
                $groups[$id]['field']     = $pair[$side];
                $groups[$id]['side']      = $side;
                $groups[$id]['pairs'][]   = $pair;
                $bad[$id]                 = !empty($bad[$id]) || $failing;
            }
        }
        foreach ($groups as $id => $group) {
            if (empty($bad[$id])) {
                continue;
            }
            self::$findings[$id] = array(
                'field' => $group['field'],
                'pairs' => $group['pairs'],
                // Text Kadence sets itself (a category pill's white) is not a setting to change.
                'slot'  => empty($group['field']['locked']) ? self::best_slot($group['field'], $group['side'], $group['pairs']) : null,
            );
        }
        return self::$findings;
    }

    /**
     * A setting's ID: its key and path, as a sanitize_key() value.
     *
     * @param array $field Field.
     * @return string
     */
    private static function id(array $field) {
        return sanitize_key($field['key'] . '-' . implode('-', $field['path']));
    }

    /**
     * Every text colour paired with its background, both set.
     *
     * @return array<int,array{text:array,bg:array,min:float}>
     */
    private static function pairs() {
        $keys = array_keys((array) get_theme_mods());
        if (is_callable(array('Kadence\Options\Component', 'defaults'))) {
            $defaults = call_user_func(array('Kadence\Options\Component', 'defaults'));
            $keys     = array_merge($keys, is_array($defaults) ? array_keys($defaults) : array());
        }
        $pairs = array();
        foreach (array_unique(array_filter($keys, 'is_string')) as $key) {
            if (!preg_match(self::TEXT_KEY, $key, $m) || preg_match(self::NOT_TEXT, $key)) {
                continue;
            }
            $value = self::option($key);
            if (!is_array($value)) {
                continue;
            }
            foreach (self::STATES as $state) {
                if (!isset($value[$state]) || !is_string($value[$state]) || '' === trim($value[$state])) {
                    continue;
                }
                $style = self::style($key, $m[1], $state);
                if ('skip' === $style) {
                    continue;
                }
                if ('pill' === $style) {
                    // The colour is the pill's background; Kadence's own CSS makes its text white.
                    $pairs[] = array(
                        'text' => array('key' => $key, 'path' => array('pill'), 'value' => '#ffffff', 'locked' => true, 'label' => __('Category pill text (white, set by Kadence)', 'seoprostack')),
                        'bg'   => array('key' => $key, 'path' => array($state), 'value' => trim($value[$state])),
                        'min'  => self::TEXT_MIN,
                    );
                    continue;
                }
                $bg = self::background($m[1], $state);
                if (null === $bg) {
                    continue;
                }
                $pairs[] = array(
                    'text' => array('key' => $key, 'path' => array($state), 'value' => trim($value[$state])),
                    'bg'   => $bg,
                    'min'  => false !== strpos($key, 'button') ? self::LARGE_MIN : self::TEXT_MIN,
                );
            }
        }
        return $pairs;
    }

    /**
     * How an element's style changes what a text colour setting paints, as
     * Kadence's styles component and CSS do:
     *
     * - Social icons (header, mobile header, footer): with brand colours
     *   (always, untilhover for the normal state, onhover for hover) the
     *   saved colours are not used; the outline style makes the button see
     *   through, so the background setting is not painted and the icons sit
     *   on the row, which is not known here. Both: skip.
     * - Category colours (post title, archive items): the pill style paints
     *   the colour as the background of white text: pill.
     *
     * @param string $key   Text setting.
     * @param string $base  Its name before the ending (header_social).
     * @param string $state State (color, hover, active).
     * @return string skip, pill or '' (text on the named background).
     */
    private static function style($key, $base, $state) {
        if (preg_match('/^(?:header|header_mobile|footer)_social$/', $base)) {
            $brand = self::option($base . '_brand');
            if ('always' === $brand || ('hover' === $state ? 'onhover' : 'untilhover') === $brand) {
                return 'skip';
            }
            return 'outline' === self::option($base . '_style') ? 'skip' : '';
        }
        if (preg_match('/^(.+)_(title|archive_item)_category_color$/', $key, $m)) {
            $elements = self::option($m[1] . ('title' === $m[2] ? '_title' : '_archive') . '_element_categories');
            return is_array($elements) && isset($elements['style']) && 'pill' === $elements['style'] ? 'pill' : '';
        }
        return '';
    }

    /**
     * The background behind text named $base: {base}_background, else with
     * words dropped from the end; a footer row's falls back to the
     * footer's. Null when none is set, or the nearest is an image, a
     * gradient or a colour that cannot be read.
     *
     * @param string $base  Name before the text setting's ending.
     * @param string $state State (color, hover, active).
     * @return array|null Field (key, path, value).
     */
    private static function background($base, $state) {
        $words = explode('_', $base);
        while ($words) {
            $name  = implode('_', $words);
            $field = self::background_field($name . '_background', $state);
            if (null === $field && preg_match('/^footer_(?:top|middle|bottom)$/', $name)) {
                $field = self::background_field('footer_wrap_background', $state);
                if (null === $field) {
                    return null;
                }
            }
            if (null !== $field) {
                return null !== self::colour($field['value'], 'light') ? $field : null;
            }
            array_pop($words);
        }
        return null;
    }

    /**
     * A background setting's colour for a state: the desktop colour of a
     * responsive background, or the state's colour (else the normal one).
     *
     * @param string $key   Setting.
     * @param string $state State.
     * @return array|null Field; value '' when an image or gradient covers it.
     */
    private static function background_field($key, $state) {
        $value = self::option($key);
        if (!is_array($value)) {
            return null;
        }
        if (isset($value['desktop'])) {
            $desktop = is_array($value['desktop']) ? $value['desktop'] : array();
            $type    = isset($desktop['type']) && is_string($desktop['type']) ? $desktop['type'] : 'color';
            $colour  = isset($desktop['color']) && is_string($desktop['color']) ? trim($desktop['color']) : '';
            if ('' === $colour) {
                return null;
            }
            $covered = ('' !== $type && 'color' !== $type) || !empty($desktop['image']['url']);
            return array('key' => $key, 'path' => array('desktop', 'color'), 'value' => $covered ? '' : $colour);
        }
        foreach (array_unique(array($state, 'color')) as $one) {
            if (isset($value[$one]) && is_string($value[$one]) && '' !== trim($value[$one])) {
                return array('key' => $key, 'path' => array($one), 'value' => trim($value[$one]));
            }
        }
        return null;
    }

    /**
     * The palette slot a colour names (palette3, var(--global-palette3)),
     * or ''.
     *
     * @param string $value Colour.
     * @return string
     */
    private static function slot($value) {
        if (!preg_match('/^(?:palette(\d+)|var\(\s*--global-palette(\d+)\s*\))$/', trim($value), $m)) {
            return '';
        }
        return 'palette' . ('' !== $m[1] ? $m[1] : $m[2]);
    }

    /**
     * A colour in a mode: a palette slot's colour there, or the fixed colour.
     *
     * @param string $value Colour.
     * @param string $mode  light or dark.
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    private static function colour($value, $mode) {
        $slot = self::slot($value);
        if ('' !== $slot) {
            $palettes = self::palettes();
            $value    = isset($palettes[$mode][$slot]) ? $palettes[$mode][$slot] : '';
        }
        return self::parse($value);
    }

    /**
     * The contrast of a pair in each mode.
     *
     * @param array       $pair Pair.
     * @param string|null $text Text colour to use instead of the saved one.
     * @param string|null $bg   Background colour to use instead.
     * @return array<string,float> Mode => ratio; 21 where a colour cannot be read.
     */
    private static function ratios(array $pair, $text = null, $bg = null) {
        $ratios = array();
        foreach (array_keys(self::palettes()) as $mode) {
            $fore = self::colour(null !== $text ? $text : $pair['text']['value'], $mode);
            $back = self::colour(null !== $bg ? $bg : $pair['bg']['value'], $mode);
            if (null === $fore || null === $back) {
                $ratios[$mode] = 21.0;
                continue;
            }
            if ($back[3] < 1) {
                // A see-through background shows the page's, palette 9.
                $page = self::colour('palette9', $mode);
                $back = self::blend($back, null !== $page ? $page : array(255.0, 255.0, 255.0, 1.0));
            }
            $ratios[$mode] = self::contrast(self::blend($fore, $back), $back);
        }
        return $ratios;
    }

    /**
     * A pair's lowest contrast across the modes.
     *
     * @param array       $pair Pair.
     * @param string|null $text Text colour to use instead of the saved one.
     * @param string|null $bg   Background colour to use instead.
     * @return float 21 when there is no palette to measure in.
     */
    private static function worst(array $pair, $text = null, $bg = null) {
        $ratios = self::ratios($pair, $text, $bg);
        return $ratios ? min($ratios) : 21.0;
    }

    /**
     * The palette colour that, in place of a fixed setting, makes every
     * pair it is in readable in each mode: the nearest to the fixed colour
     * in light mode, of the same kind where one passes, so a grey or white
     * stays a grey or white (palette3 to palette9 on Kadence's own palettes)
     * and a colour may take an accent. Null when none passes.
     *
     * @param array   $field Fixed setting.
     * @param string  $side  text or bg.
     * @param array[] $pairs Its pairs.
     * @return string|null Slot, such as palette3.
     */
    private static function best_slot(array $field, $side, array $pairs) {
        $own  = self::parse($field['value']);
        // Nearest of the same kind, and nearest of any.
        $best = array('same' => null, 'any' => null);
        $near = array('same' => INF, 'any' => INF);
        for ($n = 1; $n <= self::SLOTS; $n++) {
            $slot   = 'palette' . $n;
            $colour = self::colour($slot, 'light');
            if (null === $colour) {
                continue;
            }
            foreach ($pairs as $pair) {
                $worst = 'text' === $side ? self::worst($pair, $slot) : self::worst($pair, null, $slot);
                if ($worst < $pair['min']) {
                    continue 2;
                }
            }
            $distance = null === $own ? 0.0 : pow($colour[0] - $own[0], 2) + pow($colour[1] - $own[1], 2) + pow($colour[2] - $own[2], 2);
            $kinds    = null === $own || self::neutral($colour) === self::neutral($own) ? array('same', 'any') : array('any');
            foreach ($kinds as $kind) {
                if ($distance < $near[$kind]) {
                    $near[$kind] = $distance;
                    $best[$kind] = $slot;
                }
            }
        }
        return null !== $best['same'] ? $best['same'] : $best['any'];
    }

    /**
     * Whether a colour is a grey (or white or black): its strongest and
     * weakest channels are within a fifth of the range.
     *
     * @param array<int,float> $c Colour.
     * @return bool
     */
    private static function neutral(array $c) {
        return max($c[0], $c[1], $c[2]) - min($c[0], $c[1], $c[2]) < 51;
    }

    /**
     * Parse a colour: hex (3, 4, 6 or 8 digits) or rgb()/rgba().
     *
     * @param string $value Colour.
     * @return array{0:float,1:float,2:float,3:float}|null Red, green, blue (0-255) and opacity.
     */
    private static function parse($value) {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $m) && in_array(strlen($m[1]), array(3, 4, 6, 8), true)) {
            $hex = strlen($m[1]) < 6 ? (string) preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];
            return array(
                (float) hexdec(substr($hex, 0, 2)),
                (float) hexdec(substr($hex, 2, 2)),
                (float) hexdec(substr($hex, 4, 2)),
                8 === strlen($hex) ? hexdec(substr($hex, 6, 2)) / 255 : 1.0,
            );
        }
        if (!preg_match('/^rgba?\(([0-9.,%\s\/]+)\)$/', $value, $m)) {
            return null;
        }
        $parts = preg_split('/[\s,\/]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 3) {
            return null;
        }
        $rgb = array();
        for ($i = 0; $i < 3; $i++) {
            $rgb[] = max(0.0, min(255.0, '%' === substr($parts[$i], -1) ? (float) $parts[$i] * 2.55 : (float) $parts[$i]));
        }
        $alpha = isset($parts[3]) ? ('%' === substr($parts[3], -1) ? (float) $parts[3] / 100 : (float) $parts[3]) : 1.0;
        return array($rgb[0], $rgb[1], $rgb[2], max(0.0, min(1.0, $alpha)));
    }

    /**
     * A colour painted over another, by its opacity.
     *
     * @param array{0:float,1:float,2:float,3:float} $top    Colour on top.
     * @param array{0:float,1:float,2:float,3:float} $bottom Colour below.
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function blend(array $top, array $bottom) {
        $a = $top[3];
        return array(
            $top[0] * $a + $bottom[0] * (1 - $a),
            $top[1] * $a + $bottom[1] * (1 - $a),
            $top[2] * $a + $bottom[2] * (1 - $a),
            1.0,
        );
    }

    /**
     * WCAG contrast ratio of two colours.
     *
     * @param array<int,float> $a Colour.
     * @param array<int,float> $b Colour.
     * @return float
     */
    private static function contrast(array $a, array $b) {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Relative luminance (WCAG).
     *
     * @param array<int,float> $c Colour.
     * @return float
     */
    private static function luminance(array $c) {
        $lin = array();
        for ($i = 0; $i < 3; $i++) {
            $v       = $c[$i] / 255;
            $lin[$i] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /**
     * Run a fix or Restore, then go back with what it did.
     */
    public static function act() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names the nonce checked next.
        $fix = isset($_GET['fix']) ? sanitize_key(wp_unslash($_GET['fix'])) : '';
        check_admin_referer(self::ACTION . '-' . $fix);
        if (!current_user_can('edit_theme_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        $done  = 'failed';
        $count = 0;
        if (self::applies() && self::writable()) {
            if ('restore' === $fix) {
                $done = self::restore() ? 'restored' : 'failed';
            } elseif ('' !== $fix) {
                $count = self::fix('all' === $fix ? '' : $fix);
                $done  = $count ? 'fixed' : 'failed';
            }
        }
        $back = wp_get_referer();
        wp_safe_redirect(add_query_arg(array(self::DONE => $done, self::COUNT => $count), $back ? $back : admin_url('site-health.php')));
        exit;
    }

    /**
     * Switch fixed colours to their palette colour, keeping the theme's
     * values first.
     *
     * @param string $only Finding ID, or '' for all.
     * @return int Colours changed.
     */
    private static function fix($only) {
        $changes = array();
        foreach (self::findings() as $id => $finding) {
            if (null !== $finding['slot'] && ('' === $only || $only === $id)) {
                $changes[] = array($finding['field'], $finding['slot']);
            }
        }
        if (!$changes) {
            return 0;
        }
        self::keep(array_unique(array_map(function ($change) {
            return $change[0]['key'];
        }, $changes)));
        foreach ($changes as $change) {
            list($field, $slot) = $change;
            $mod = get_theme_mod($field['key'], null);
            if (!is_array($mod)) {
                // Not saved yet: start from Kadence's default, as it shows.
                $mod = self::option($field['key']);
                $mod = is_array($mod) ? $mod : array();
            }
            $at = &$mod;
            foreach ($field['path'] as $step) {
                if (!isset($at[$step]) || !is_array($at[$step])) {
                    $at[$step] = end($field['path']) === $step ? '' : array();
                }
                $at = &$at[$step];
            }
            $at = $slot;
            unset($at);
            set_theme_mod($field['key'], $mod);
        }
        self::$findings = null;
        return count($changes);
    }

    /**
     * Keep the theme's values of settings about to change, for Restore. A
     * setting already kept keeps its first value, so Restore goes back to
     * before the first fix.
     *
     * @param string[] $keys Settings.
     */
    private static function keep(array $keys) {
        $backup = get_option(self::BACKUP);
        if (!is_array($backup) || !isset($backup['theme'], $backup['mods']) || get_stylesheet() !== $backup['theme'] || !is_array($backup['mods'])) {
            $backup = array('theme' => get_stylesheet(), 'time' => time(), 'mods' => array());
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $backup['mods'])) {
                // null: not saved before, so Restore removes it.
                $backup['mods'][$key] = get_theme_mod($key, null);
            }
        }
        update_option(self::BACKUP, $backup, false);
    }

    /**
     * Whether Restore has values for the active theme.
     *
     * @return bool
     */
    private static function backup_ready() {
        $backup = get_option(self::BACKUP);
        return is_array($backup) && isset($backup['theme'], $backup['mods']) && get_stylesheet() === $backup['theme'] && is_array($backup['mods']) && $backup['mods'];
    }

    /**
     * Put back the values kept before the fixes.
     *
     * @return bool
     */
    private static function restore() {
        if (!self::backup_ready()) {
            return false;
        }
        $backup = (array) get_option(self::BACKUP);
        foreach ((array) $backup['mods'] as $key => $value) {
            if (null === $value) {
                remove_theme_mod((string) $key);
            } else {
                set_theme_mod((string) $key, $value);
            }
        }
        delete_option(self::BACKUP);
        self::$findings = null;
        return true;
    }

    /**
     * Say what a fix did.
     */
    public static function notice() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only picks which message to show.
        $done  = isset($_GET[self::DONE]) ? sanitize_key(wp_unslash($_GET[self::DONE])) : '';
        $count = isset($_GET[self::COUNT]) ? absint(wp_unslash($_GET[self::COUNT])) : 0;
        // phpcs:enable
        $messages = array(
            'fixed'    => array('success', sprintf(
                /* translators: %s: number of colours. */
                _n('%s Kadence colour now follows the palette. Restore in Site Health puts the old colour back.', '%s Kadence colours now follow the palette. Restore in Site Health puts the old colours back.', $count, 'seoprostack'),
                number_format_i18n($count)
            )),
            'restored' => array('success', __('The Kadence colours from before the fix are back.', 'seoprostack')),
            'failed'   => array('error', __('Nothing was changed: there was no Kadence colour to fix or restore.', 'seoprostack')),
        );
        if (isset($messages[$done])) {
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($messages[$done][0]), esc_html($messages[$done][1]));
        }
    }

    /**
     * Take the fix's result out of the address after it is shown.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::DONE;
        $args[] = self::COUNT;
        return $args;
    }
}
