<?php
/**
 * TranslatePress hreflang codes with the site's own country.
 *
 * TranslatePress writes each language's hreflang from its locale: en_GB
 * gives en-GB and es_MX gives es-MX, so a Jersey site's pages tell search
 * engines they are for the United Kingdom and a Californian site's Spanish
 * pages that they are for Mexico. With a country set, the country part of
 * every such code becomes the site's own (en-JE, fr-JE, es-US), in the
 * page's head and in TranslatePress SEO Pack's sitemap, which use the same
 * `trp_hreflang` filter. Language-only codes (en, fr) and locales without a
 * country (lv, et) are kept. Where two languages would get the same code
 * (en_GB and en_US on one site), both keep their own, so no two languages
 * share one.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Hreflang_Country extends SEOProStack_Feature {

    const KEY = 'hreflang_country';

    /** Setting: the site's two-letter country code. */
    const CODE_KEY = 'hreflang_country_code';

    /** TranslatePress's settings option. */
    const TRP_OPTION = 'trp_settings';

    /**
     * Locale => hreflang code with the country, for the site's languages
     * (null until worked out).
     *
     * @var array<string,string>|null
     */
    private static $codes = null;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY      => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'content',
                'label'       => __('Hreflang codes with the site’s country', 'seoprostack'),
                'description' => __('Search engines read the country in each language’s hreflang code. TranslatePress takes it from the language (en-GB, es-MX); this gives every language the country below instead (en-JE, fr-JE, es-US), in the page and in the sitemap. Language-only codes (en, fr) stay. Nothing changes without TranslatePress.', 'seoprostack'),
            ),
            self::CODE_KEY => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Country', 'seoprostack'),
                'description' => __('The site’s two-letter ISO country code, such as JE, US, CA or BE. Empty or not two letters: TranslatePress’s own codes are kept.', 'seoprostack'),
            ),
        );
    }

    /** Filter TranslatePress's codes while it runs and a country is set. */
    public static function boot() {
        if (!self::enabled() || !class_exists('TRP_Translate_Press', false) || '' === self::country()) {
            return;
        }
        // After TranslatePress's own and other plugins' default-priority filters.
        add_filter('trp_hreflang', array(__CLASS__, 'filter'), 20, 2);
        // Settings saved in this request (TranslatePress or this plugin).
        add_action('update_option_' . self::TRP_OPTION, array(__CLASS__, 'forget'));
        add_action('update_option_' . SEOProStack_Settings::OPTION, array(__CLASS__, 'forget'));
    }

    /**
     * The country code set, upper case, or '' when it is not two letters.
     *
     * @return string
     */
    public static function country() {
        $code = strtoupper(trim((string) SEOProStack_Settings::get(self::CODE_KEY)));
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
    }

    /**
     * A language's hreflang code with the site's country.
     *
     * @param mixed $hreflang Code TranslatePress made (en-GB, or en).
     * @param mixed $language Its locale (en_GB).
     * @return mixed
     */
    public static function filter($hreflang, $language = '') {
        if (!is_string($hreflang) || false === strpos($hreflang, '-') || !is_string($language)) {
            return $hreflang;
        }
        $codes = self::codes();
        if (isset($codes[$language]) && self::without_country($hreflang) === self::without_country($codes[$language])) {
            return $codes[$language];
        }
        return $hreflang;
    }

    /** Work the codes out again after settings change. */
    public static function forget() {
        self::$codes = null;
    }

    /**
     * Locale => code with the country, for each of TranslatePress's languages
     * that has a country and whose new code no other language gets.
     *
     * @return array<string,string>
     */
    public static function codes() {
        if (null !== self::$codes) {
            return self::$codes;
        }
        self::$codes = array();
        $country     = self::country();
        $settings    = get_option(self::TRP_OPTION);
        if ('' === $country || !is_array($settings)) {
            return self::$codes;
        }
        $languages = array();
        foreach (array('translation-languages', 'publish-languages') as $key) {
            if (!empty($settings[$key]) && is_array($settings[$key])) {
                $languages = array_merge($languages, $settings[$key]);
            }
        }
        $original = array();
        $mapped   = array();
        foreach (array_unique(array_filter($languages, 'is_string')) as $language) {
            $code = self::code_for($language);
            $new  = self::with_country($code, $country);
            if ($new !== $code) {
                $original[$language] = $code;
                $mapped[$language]   = $new;
            }
        }
        // Languages whose codes differed before must not share one now.
        $count = array_count_values($mapped);
        foreach ($mapped as $language => $new) {
            $clash = false;
            if ($count[$new] > 1) {
                foreach ($mapped as $other => $other_new) {
                    if ($other !== $language && $other_new === $new && $original[$other] !== $original[$language]) {
                        $clash = true;
                        break;
                    }
                }
            }
            // A language without a mapping already using the code (nrf-JE).
            if (!$clash) {
                foreach ($languages as $other) {
                    if (is_string($other) && !isset($mapped[$other]) && self::code_for($other) === $new) {
                        $clash = true;
                        break;
                    }
                }
            }
            if (!$clash) {
                self::$codes[$language] = $new;
            }
        }
        return self::$codes;
    }

    /**
     * TranslatePress's code for a locale: no formality, hyphens (de_DE_formal
     * → de-DE).
     *
     * @param string $language Locale.
     * @return string
     */
    private static function code_for($language) {
        $language = (string) preg_replace('/_(in)?formal$/', '', $language);
        return str_replace('_', '-', $language);
    }

    /**
     * A code with its country part replaced; unchanged without one.
     *
     * @param string $code    Code (en-GB, zh-Hant-TW, lv).
     * @param string $country Two upper-case letters.
     * @return string
     */
    private static function with_country($code, $country) {
        $parts = explode('-', $code);
        if (count($parts) < 2 || !preg_match('/^(?:[A-Z]{2}|\d{3})$/', (string) end($parts))) {
            return $code;
        }
        $parts[count($parts) - 1] = $country;
        return implode('-', $parts);
    }

    /**
     * A code without its country part (en-GB → en, zh-Hant-TW → zh-Hant).
     *
     * @param string $code Code.
     * @return string
     */
    private static function without_country($code) {
        $parts = explode('-', $code);
        if (count($parts) >= 2 && preg_match('/^(?:[A-Z]{2}|\d{3})$/', (string) end($parts))) {
            array_pop($parts);
        }
        return implode('-', $parts);
    }
}
