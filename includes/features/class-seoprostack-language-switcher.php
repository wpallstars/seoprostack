<?php
/**
 * Language switcher: TranslatePress's languages with their flags, beside
 * Kadence Pro's dark-mode switch.
 *
 * In the header's top bar, the mobile menu and the footer (Kadence Pro's
 * kadence_header_dark_mode, kadence_mobile_dark_mode and
 * kadence_footer_dark_mode actions: after its own switch in the header and
 * mobile menu, before it in the footer), so no Customizer setting changes. A
 * button with the current language's flag and code opens a list of the
 * site's languages, each with its flag and name, linking to the same page in
 * that language. In the footer it sits left of the switch, and the footer row
 * is centred vertically.
 *
 * Shown only on Kadence (and its child themes) while TranslatePress is
 * active and publishes more than one language, and not where the theme
 * already prints its own (the Property Services Directory child theme up to
 * 0.1.28 hooks the same actions; later versions leave it to this feature).
 * TranslatePress leaves it as written (data-no-translation).
 *
 * A language's flag is its locale's country (fr_CA → CA, lv → LV), from
 * assets/flags/, else TranslatePress's own flag for the locale. English
 * follows the site's country: the psdtheme_english_flag option (JE on a
 * Jersey site; the name is shared with that child theme), else its locale's.
 * Filter: seoprostack_language_flag.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 * From the Property Services Directory child theme (Provenance: wpas-2bec2bd6b77c93d7).
 *
 * @package SEOProStack
 * @since 1.3.138
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Language_Switcher extends SEOProStack_Feature {

    const KEY = 'language_switcher';

    const HANDLE = 'seoprostack-language-switcher';

    /** Flags, one 3:2 SVG per country code, in the plugin folder. */
    const FLAG_DIR = 'assets/flags/';

    /** The English flag's country (two letters), set per site. */
    const ENGLISH_FLAG_OPTION = 'psdtheme_english_flag';

    /** A theme switcher on the same actions (it prints its own). */
    const THEME_SWITCHER = 'PSDTHEME_Languages';

    /** Two-letter language codes whose country is not the same letters. */
    const LANGUAGE_COUNTRIES = array(
        'en' => 'GB',
        'et' => 'EE',
        'da' => 'DK',
        'el' => 'GR',
        'cs' => 'CZ',
        'sv' => 'SE',
        'uk' => 'UA',
        'ja' => 'JP',
        'ko' => 'KR',
        'zh' => 'CN',
        'ga' => 'IE',
        'ca' => 'ES',
    );

    /** Switchers on this page, for unique IDs. */
    private static $count = 0;

    /**
     * This request's languages, once read.
     *
     * @var array<string, array{name: string, url: string, code: string, flag: string, current: bool}>|null
     */
    private static $languages = null;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // Off: a site may already show TranslatePress's own switcher.
                'default'     => false,
                'tab'         => 'content',
                'label'       => __('Language switcher beside Kadence’s dark mode switch', 'seoprostack'),
                'description' => __('With TranslatePress and more than one language: a compact button with the language’s flag and code beside Kadence Pro’s Dark Mode switch in the header, mobile menu and footer, opening a list of the site’s languages with their flags and names. English shows the site’s own country flag. Turn TranslatePress’s floating switcher off, and remove its shortcode from the header and footer, so only one shows. Nothing shows on other themes or with one language.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks, on Kadence and its child themes.
     */
    public static function boot() {
        if (!self::enabled() || is_admin() || 'kadence' !== get_template()) {
            return;
        }
        // After Kadence Pro's own dark-mode switch (10) in the header and
        // mobile menu; before it in the footer, left of the switch in markup
        // and keyboard order.
        add_action('kadence_header_dark_mode', array(__CLASS__, 'header'), 20);
        add_action('kadence_mobile_dark_mode', array(__CLASS__, 'mobile'), 20);
        add_action('kadence_footer_dark_mode', array(__CLASS__, 'footer'), 5);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 30);
    }

    /**
     * Whether this feature prints the switcher on this request: there are
     * languages to switch between, and the theme does not print its own.
     *
     * @return bool
     */
    private static function shows() {
        if (has_action('kadence_header_dark_mode', array(self::THEME_SWITCHER, 'header'))) {
            return false;
        }
        /**
         * Skip this switcher when a theme prints its own under another callback.
         *
         * @param bool $skip Whether to suppress output and its assets.
         */
        if (apply_filters('seoprostack_language_switcher_skip', false)) {
            return false;
        }
        return (bool) self::languages();
    }

    /**
     * The site's languages, current first in TranslatePress's order, or
     * none when it is inactive or publishes one language.
     *
     * @return array<string, array{name: string, url: string, code: string, flag: string, current: bool}>
     */
    public static function languages() {
        if (null === self::$languages) {
            self::$languages = self::read_languages();
        }
        return self::$languages;
    }

    /**
     * Read the languages from TranslatePress.
     *
     * @return array<string, array{name: string, url: string, code: string, flag: string, current: bool}>
     */
    private static function read_languages() {
        if (!function_exists('trp_custom_language_switcher')) {
            return array();
        }
        if (!apply_filters('trp_allow_tp_to_run', true)) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- TranslatePress's path-exclusion filter.
            return array();
        }
        $list = trp_custom_language_switcher();
        if (!is_array($list) || count($list) < 2) {
            return array();
        }
        global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress's global.
        $current   = is_string($TRP_LANGUAGE) ? $TRP_LANGUAGE : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        $own_flag  = 1 === preg_match('/^[A-Za-z]{2}$/', (string) get_option(self::ENGLISH_FLAG_OPTION, ''));
        $languages = array();
        foreach ($list as $locale => $item) {
            $language = self::language((string) $locale, $item, $current, $own_flag);
            if (null !== $language) {
                $languages[(string) $locale] = $language;
            }
        }
        return count($languages) > 1 ? $languages : array();
    }

    /**
     * One of TranslatePress's languages, or null without an address.
     *
     * @param string $locale   TranslatePress language code.
     * @param mixed  $item     Its trp_custom_language_switcher() entry.
     * @param string $current  This request's language code.
     * @param bool   $own_flag Whether English shows the site's own flag.
     * @return array{name: string, url: string, code: string, flag: string, current: bool}|null
     */
    private static function language($locale, $item, $current, $own_flag) {
        if (!is_array($item) || empty($item['current_page_url'])) {
            return null;
        }
        // "English (UK)" beside a site's own flag (Jersey's): "English".
        $name = $own_flag && 0 === strpos($locale, 'en_') ? 'English' : (string) ($item['language_name'] ?? $locale);
        return array(
            'name'    => $name,
            'url'     => (string) $item['current_page_url'],
            'code'    => strtoupper((string) strtok($locale, '_')),
            'flag'    => self::flag_url($locale, (string) ($item['flag_link'] ?? '')),
            'current' => $locale === $current,
        );
    }

    /**
     * A locale's flag country (two capital letters).
     *
     * @param string $locale TranslatePress language code (fr_CA, lv, pt_PT_ao90).
     * @return string
     */
    public static function country($locale) {
        $parts    = explode('_', (string) $locale);
        $language = strtolower($parts[0]);
        $country  = isset($parts[1]) && preg_match('/^[A-Za-z]{2}$/', $parts[1]) ? strtoupper($parts[1]) : '';
        if ('' === $country) {
            $country = self::LANGUAGE_COUNTRIES[$language] ?? strtoupper($language);
        }
        if ('en' === $language) {
            $site = strtoupper((string) get_option(self::ENGLISH_FLAG_OPTION, ''));
            if (preg_match('/^[A-Z]{2}(-[A-Z]{3})?$/', $site)) {
                $country = $site;
            }
        }
        /**
         * Filter a language's flag country.
         *
         * @param string $country Two capital letters, or a subdivision such as GB-ENG, the name of a file in assets/flags/.
         * @param string $locale  TranslatePress language code.
         */
        return (string) apply_filters('seoprostack_language_flag', $country, $locale);
    }

    /**
     * A locale's flag image address: the plugin's 3:2 flag for its country,
     * else TranslatePress's supplied flag, then its bundled flag, else ''.
     *
     * @param string $locale   TranslatePress language code.
     * @param string $fallback TranslatePress's flag_link (may be an uploaded flag).
     * @return string
     */
    public static function flag_url($locale, $fallback = '') {
        $country = self::country($locale);
        if (preg_match('/^[A-Z]{2}(-[A-Z]{3})?$/', $country) && is_readable(SEOPROSTACK_DIR . self::FLAG_DIR . $country . '.svg')) {
            return SEOPROSTACK_URL . self::FLAG_DIR . $country . '.svg';
        }
        if ('' !== $fallback) {
            return $fallback;
        }
        $file = 'assets/flags/4x3/' . preg_replace('/\W/', '', (string) $locale) . '.svg';
        if (defined('TRP_PLUGIN_DIR') && defined('TRP_PLUGIN_URL') && is_readable(TRP_PLUGIN_DIR . $file)) {
            return TRP_PLUGIN_URL . $file;
        }
        return '';
    }

    /**
     * Styles and script, only on pages that show the switcher.
     */
    public static function enqueue() {
        if (!self::shows()) {
            return;
        }
        wp_enqueue_style(self::HANDLE, SEOPROSTACK_URL . 'assets/language-switcher.css', array(), SEOPROSTACK_VERSION);
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . 'assets/language-switcher.js', array(), SEOPROSTACK_VERSION, array('strategy' => 'defer', 'in_footer' => true));
    }

    /** Top bar. */
    public static function header() {
        self::render('header');
    }

    /** Mobile menu. */
    public static function mobile() {
        self::render('mobile');
    }

    /** Footer: the list opens upwards. */
    public static function footer() {
        self::render('footer');
    }

    /**
     * Print a switcher.
     *
     * @param string $place header, mobile or footer.
     */
    public static function render($place) {
        if (!self::shows()) {
            return;
        }
        $languages = self::languages();
        $current   = reset($languages);
        foreach ($languages as $language) {
            if ($language['current']) {
                $current = $language;
                break;
            }
        }
        if (false === $current) {
            return;
        }
        $id = 'seoprostack-languages-' . (++self::$count);
        echo '<div class="seoprostack-languages seoprostack-languages--' . esc_attr($place) . '" data-no-translation>';
        printf(
            '<button type="button" class="seoprostack-languages__button" aria-expanded="false" aria-controls="%1$s" aria-label="%2$s">%3$s<span class="seoprostack-languages__code">%4$s</span>%5$s</button>',
            esc_attr($id),
            /* translators: %s: the current language's name */
            esc_attr(sprintf(__('Language: %s', 'seoprostack'), $current['name'])),
            self::flag_img($current['flag']), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in flag_img().
            esc_html($current['code']),
            '<svg class="seoprostack-languages__chevron" aria-hidden="true" focusable="false" viewBox="0 0 16 16" width="12" height="12"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        );
        echo '<ul class="seoprostack-languages__menu" id="' . esc_attr($id) . '" hidden>';
        foreach ($languages as $locale => $language) {
            $hreflang = str_replace('_', '-', (string) preg_replace('/_(formal|informal|ao90)$/', '', (string) $locale));
            printf(
                '<li><a class="seoprostack-languages__item" href="%1$s" hreflang="%2$s" lang="%2$s"%3$s>%4$s<span class="seoprostack-languages__name">%5$s</span>%6$s</a></li>',
                esc_url($language['url']),
                esc_attr($hreflang),
                $language['current'] ? ' aria-current="true"' : '',
                self::flag_img($language['flag']), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in flag_img().
                esc_html($language['name']),
                $language['current'] ? '<svg class="seoprostack-languages__check" aria-hidden="true" focusable="false" viewBox="0 0 16 16" width="14" height="14"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>' : ''
            );
        }
        echo '</ul></div>';
    }

    /**
     * A decorative flag image (the language's name or code is its label).
     *
     * @param string $url Flag image address, or '' for none.
     * @return string
     */
    private static function flag_img($url) {
        if ('' === $url) {
            return '<span class="seoprostack-languages__flag seoprostack-languages__flag--none" aria-hidden="true"></span>';
        }
        return sprintf(
            // Tiny and in the first screen: no lazy loading (skip-lazy and data-no-lazy for LiteSpeed Cache and others).
            '<img class="seoprostack-languages__flag skip-lazy" src="%s" alt="" width="21" height="14" decoding="async" data-no-lazy="1">',
            esc_url($url)
        );
    }
}
