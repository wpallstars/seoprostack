<?php
/**
 * Keep text still while web fonts load: metric-matched fallback fonts.
 *
 * While a page's web fonts load (font-display: swap, as the Kadence theme
 * and Google Fonts use), text shows in a local font first and moves when the
 * web font arrives: a layout shift. For each web font the page declares
 * (an @font-face in the page, in a font stylesheet on this site such as the
 * Kadence theme's local Google Fonts in wp-content/fonts/, or a Google Fonts
 * link), this adds `"<Family> Fallback"` faces: a local font (Arial, or
 * Times New Roman for serif fonts) resized with size-adjust and
 * ascent/descent/line-gap overrides to the web font's measurements, regular
 * and bold separately, with its capitals A to Z and its spaces sized on
 * their own (a face with that unicode-range) when they differ. A second set,
 * `"<Family> Fallback 2"`, does the same from Roboto and Noto Serif for
 * Android phones, which have no Arial. Each
 * font-family declaration in the page's own `<style>` elements (and each
 * --*font-family custom property, as the Kadence theme's) gets them after
 * the web font, and a small script starts loading them as the head is read,
 * so they are ready for the first paint (GitHub issue #1372).
 *
 * Measurements: assets/font-metrics/metrics.json, made by
 * scripts/update-font-metrics.sh from Capsize's @capsizecss/metrics (MIT,
 * SEEK; the heights) and the fonts Google Fonts serves (the widths). Fonts it
 * does not know are left as they are. No requests and no font files.
 *
 * Average-width matching cannot fit every line: a heading whose letters are
 * far from the average can still wrap differently; such a font can be left
 * out (font_fallbacks_skip).
 *
 * Only the page's own inline styles are rewritten: Used CSS's buffer starts
 * first (SEOProStack_Setup::FEATURES), so this rewrite runs before it adds
 * its copies of the stylesheets, whose full versions load later without the
 * fallback anyway.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.177
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-links.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-policy.php';

class SEOProStack_Font_Fallbacks extends SEOProStack_Feature {

    const KEY      = 'font_fallbacks';
    const SKIP_KEY = 'font_fallbacks_skip';

    /** The measurements, relative to SEOPROSTACK_DIR. */
    const METRICS = 'assets/font-metrics/metrics.json';

    /**
     * Local fonts each fallback is made from: the metrics.json base, then the
     * regular and bold local() names. Liberation and Arimo/Tinos have
     * Arial's and Times New Roman's measurements (Linux and ChromeOS).
     */
    const BASES = array(
        's' => array(
            array('arial', array('Arial', 'ArialMT', 'Liberation Sans', 'LiberationSans', 'Arimo', 'Arimo-Regular'), array('Arial Bold', 'Arial-BoldMT', 'Liberation Sans Bold', 'LiberationSans-Bold', 'Arimo Bold', 'Arimo-Bold')),
            array('roboto', array('Roboto', 'Roboto-Regular'), array('Roboto Bold', 'Roboto-Bold')),
        ),
        'e' => array(
            array('timesNewRoman', array('Times New Roman', 'TimesNewRomanPSMT', 'Liberation Serif', 'LiberationSerif', 'Tinos', 'Tinos-Regular'), array('Times New Roman Bold', 'TimesNewRomanPS-BoldMT', 'Liberation Serif Bold', 'LiberationSerif-Bold', 'Tinos Bold', 'Tinos-Bold')),
            array('notoSerif', array('Noto Serif', 'NotoSerif-Regular'), array('Noto Serif Bold', 'NotoSerif-Bold')),
        ),
    );

    /** Characters that get a face of their own: spaces and capitals. */
    const PARTS = array(
        'space' => 'U+20,U+A0',
        'caps'  => 'U+41-5A',
    );

    /** The unicode-range of the face for the rest, by the parts given their own. */
    const REST = array(
        ''           => '',
        'space'      => 'U+0-1F,U+21-9F,U+A1-10FFFF',
        'caps'       => 'U+0-40,U+5B-10FFFF',
        'space caps' => 'U+0-1F,U+21-40,U+5B-9F,U+A1-10FFFF',
    );

    /** Font stylesheets on this site read for their @font-face rules, at most. */
    const MAX_FILES = 8;

    /** Largest font stylesheet read, in bytes. */
    const MAX_BYTES = 262144;

    /**
     * The measurements, or null before they are read.
     *
     * @var array|null
     */
    private static $metrics = null;

    /** Whether a font stylesheet on this site was named but its file was not there, on this page. */
    private static $unreadable = false;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY      => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Keep text still while web fonts load', 'seoprostack'),
                'description' => __('While a page’s web fonts (Google Fonts, such as the Kadence theme’s) load, text shows in a local font such as Arial, then moves when the web font arrives, which speed scores count as layout shift. This sizes the local font to each web font’s measurements, regular and bold, so text hardly moves. It knows about 1,870 Google Fonts and leaves others as they are. No requests and no font files; turning it on or off empties LiteSpeed Cache’s or WP-Optimize’s page cache.', 'seoprostack'),
            ),
            self::SKIP_KEY => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'label'       => __('Leave these fonts as they are', 'seoprostack'),
                'description' => __('One font name per line, such as Poppins: for a heading that still moves when its web font arrives.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        if (!self::enabled() || is_admin()) {
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'start'), PHP_INT_MAX);
    }

    /**
     * Settings changed: when this feature's did, cached pages were made the
     * other way, so empty the page cache.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        foreach (array(self::KEY, self::SKIP_KEY) as $key) {
            $before = isset($old[$key]) ? $old[$key] : null;
            $after  = isset($new[$key]) ? $new[$key] : null;
            if ($before != $after) { // phpcs:ignore Universal.Operators.StrictComparisons -- stored as text or true/false.
                SEOProStack_Page_Cache::purge_all();
                return;
            }
        }
    }

    /**
     * Buffer the page on normal front-end views.
     */
    public static function start() {
        if (self::html_view()) {
            ob_start(array(__CLASS__, 'rewrite'));
        }
    }

    /**
     * Whether this request is a normal front-end view of an HTML page (also
     * used by Lighter WooCommerce pages).
     *
     * @return bool
     */
    public static function html_view() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        return 'GET' === $method && !self::special_view() && !self::special_request();
    }

    /**
     * Whether this is a preview, feed, embed, robots.txt or trackback.
     *
     * @return bool
     */
    private static function special_view() {
        return is_preview() || is_customize_preview() || is_feed() || is_embed() || is_robots() || is_trackback();
    }

    /**
     * Whether this is an AJAX, REST, JSON or AMP request.
     *
     * @return bool
     */
    private static function special_request() {
        return wp_doing_ajax() || wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('amp_is_request') && amp_is_request());
    }

    /**
     * Add the fallback faces and put them in the page's font stacks.
     *
     * @param string $html Page.
     * @return string
     */
    public static function rewrite($html) {
        if (false === stripos($html, '</head>') || false === stripos(ltrim(substr($html, 0, 500)), '<')) {
            return $html; // Not an HTML page.
        }
        self::$unreadable = false;
        $families         = self::web_fonts($html);
        $note             = '';
        $why              = '';
        if (empty(self::metrics()['fonts'])) {
            $why = 'the font measurements could not be read';
        } elseif (self::font_stylesheet_missing()) {
            $why = 'a font stylesheet file was not found';
        }
        if ('' !== $why) {
            // A reason that may not repeat: the next build should replace this page (GitHub issue #1603).
            SEOProStack_Used_Css_Policy::degraded('Font fallbacks skipped: ' . $why);
            $note = SEOProStack_Used_Css_Policy::marker('Font fallbacks', $why);
        }
        if (!$families) {
            return $html . $note;
        }
        if (!SEOProStack_Used_Css_Policy::allows($html, true)) {
            return $html . $note . SEOProStack_Used_Css_Policy::marker('Font fallbacks', 'a content security policy blocks inline code');
        }
        $count = 0;
        $out   = preg_replace_callback('#(<style\b[^>]*>)(.*?)(</style>)#is', function ($m) use ($families, &$count) {
            $body = preg_replace_callback('/@font-face\s*\{[^}]*\}|((?:--[\w-]*)?font-family\s*:\s*)([^;}{<]+)/i', function ($d) use ($families, &$count) {
                return empty($d[1]) ? $d[0] : $d[1] . self::stack($d[2], $families, $count);
            }, $m[2]);
            return null === $body ? $m[0] : $m[1] . $body . $m[3];
        }, $html);
        if (null === $out) {
            SEOProStack_Used_Css_Policy::degraded('Font fallbacks skipped: the regular expression hit a PCRE limit');
            return $html . $note . SEOProStack_Used_Css_Policy::marker('Font fallbacks', 'the regular expression hit a PCRE limit');
        }
        if (!$count) {
            return $html . $note; // No stack names them.
        }
        $tag = self::tag($families);
        // After the charset tag, which belongs in the first 1,024 bytes.
        if (preg_match('#<meta\s+charset\s*=[^>]*>#i', $out, $found, PREG_OFFSET_CAPTURE) && $found[0][1] < stripos($out, '</head>')) {
            $at = (int) $found[0][1] + strlen($found[0][0]);
        } elseif (preg_match('#<head\b[^>]*>#i', $out, $found, PREG_OFFSET_CAPTURE)) {
            $at = (int) $found[0][1] + strlen($found[0][0]);
        } else {
            return $html . $note;
        }
        return substr_replace($out, $tag, $at, 0) . $note;
    }

    /**
     * A font-family value with the fallbacks after each web font in it.
     *
     * @param string $value    Value.
     * @param array  $families Lower-case name => [name, metrics].
     * @param int    $count    Fonts given fallbacks so far.
     * @return string
     */
    private static function stack($value, array $families, &$count) {
        $tokens = explode(',', $value);
        foreach ($tokens as $i => $token) {
            $important = (bool) preg_match('/\s*!important\s*$/i', $token);
            $plain     = $important ? (string) preg_replace('/\s*!important\s*$/i', '', $token) : $token;
            $key       = strtolower(trim(trim($plain), '"\''));
            if (!isset($families[$key])) {
                continue;
            }
            $name = $families[$key][0];
            $count++;
            $tokens[$i] = $plain . ',"' . $name . ' Fallback","' . $name . ' Fallback 2"' . ($important ? ' !important' : '');
        }
        return implode(',', $tokens);
    }

    /**
     * The `<style>` with the faces, and the script that starts loading them.
     *
     * @param array $families Lower-case name => [name, metrics].
     * @return string
     */
    private static function tag(array $families) {
        $metrics = self::metrics();
        $css     = '';
        $loads   = array();
        foreach ($families as $family) {
            list($name, $m) = $family;
            $bold           = count($m) >= 15;
            foreach (self::BASES['e' === $m[0] ? 'e' : 's'] as $i => $base) {
                $local = $metrics['bases'][$base[0]];
                $fname = $name . ' Fallback' . ($i ? ' 2' : '');
                $css  .= self::face($fname, array_slice($m, 1, 7), array_slice($local, 0, 7), $base[1], $bold ? '100 500' : 'normal');
                $loads[] = '400 1em "' . $fname . '"';
                if ($bold) {
                    $css    .= self::face($fname, array_slice($m, 8, 7), array_slice($local, 7, 7), $base[2], '600 900');
                    $loads[] = '700 1em "' . $fname . '"';
                }
            }
        }
        // "aA ": the faces for small letters, capitals and spaces.
        $script = '(function(f){if(f&&f.load){' . wp_json_encode($loads, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . '.forEach(function(s){f.load(s,"aA ").then(null,function(){})})}})(document.fonts)';
        return '<style id="seoprostack-font-fallbacks">' . $css . '</style>'
            . '<script id="seoprostack-font-fallbacks-js" data-seoprostack-nodelay>' . $script . '</script>';
    }

    /**
     * One fallback face.
     *
     * @param string $name   Family.
     * @param array  $font   The web font's [unitsPerEm, ascent, descent, lineGap, lowerWidth, capsWidth, spaceWidth].
     * @param array  $local  The local font's, the same.
     * @param array  $locals Its local() names.
     * @param string $weight Weights it is for.
     * @return string
     */
    private static function face($name, array $font, array $local, array $locals, $weight) {
        if (count($font) < 7 || count($local) < 7 || $font[0] <= 0 || $local[0] <= 0
            || min($font[4], $font[5], $font[6], $local[4], $local[5], $local[6]) <= 0) {
            return '';
        }
        $ratio = function ($i) use ($font, $local) {
            return ($font[$i] / $font[0]) / ($local[$i] / $local[0]);
        };
        $size  = $ratio(4);
        $src   = implode(',', array_map(function ($l) {
            return 'local("' . $l . '")';
        }, $locals));
        // Fonts' small letters, capitals and spaces are not in the same
        // proportion (Poppins Bold's capitals are narrower than Arial Bold's,
        // its small letters wider and its space much narrower), so capitals
        // and spaces get faces of their own, sized to the web font's: a line
        // wraps nearer where the web font's does. All have the web font's
        // height, so lines keep their height. The face with the space gives
        // the ex and ch units while the web font loads.
        $parts = array();
        $css   = '';
        foreach (array('space' => 6, 'caps' => 5) as $part => $i) {
            $own = $ratio($i);
            if (abs($own / $size - 1) >= 0.01) {
                $parts[] = $part;
                $css    .= self::rule($name, $src, $weight, $own, $font, self::PARTS[$part]);
            }
        }
        return self::rule($name, $src, $weight, $size, $font, self::REST[implode(' ', $parts)]) . $css;
    }

    /**
     * One @font-face rule.
     *
     * @param string $name   Family.
     * @param string $src    Its src.
     * @param string $weight Weights it is for.
     * @param float  $size   size-adjust, as a ratio.
     * @param array  $font   The web font's measurements.
     * @param string $range  unicode-range, or '' for every character.
     * @return string
     */
    private static function rule($name, $src, $weight, $size, array $font, $range) {
        // The overrides are scaled by size-adjust too: divided by it here, so
        // the line is the web font's height.
        $em = $font[0] * $size;
        return sprintf(
            '@font-face{font-family:"%s";src:%s;font-weight:%s;size-adjust:%s;ascent-override:%s;descent-override:%s;line-gap-override:%s%s}',
            $name,
            $src,
            $weight,
            self::percent($size),
            self::percent($font[1] / $em),
            self::percent(abs($font[2]) / $em),
            self::percent($font[3] / $em),
            '' === $range ? '' : ';unicode-range:' . $range
        );
    }

    /**
     * A ratio as a CSS percentage.
     *
     * @param float $ratio Ratio.
     * @return string
     */
    private static function percent($ratio) {
        return number_format($ratio * 100, 2, '.', '') . '%';
    }

    /**
     * The web fonts this page declares that the measurements know.
     *
     * @param string $html Page.
     * @return array Lower-case name => [name, metrics].
     */
    private static function web_fonts($html) {
        $names   = self::declared_families($html);
        $metrics = self::metrics();
        if (empty($metrics['fonts'])) {
            return array();
        }
        $skip = self::skipped();
        $out  = array();
        foreach ($names as $name) {
            $name = trim($name);
            $key  = strtolower($name);
            if (isset($out[$key]) || !isset($metrics['fonts'][$key]) || in_array($key, $skip, true) || !preg_match('/^[a-z0-9][a-z0-9 _-]*$/i', $name)) {
                continue;
            }
            $font = $metrics['fonts'][$key];
            if (is_array($font) && count($font) >= 8) {
                $out[$key] = array($name, $font);
            }
        }
        /**
         * The web fonts that get fallbacks on this page.
         *
         * @param array $out Lower-case name => [name, metrics].
         */
        return (array) apply_filters('seoprostack_font_fallbacks', $out);
    }

    /**
     * The font families a page declares: its @font-face rules, its Google
     * Fonts stylesheets and the @font-face rules of its font stylesheets on
     * this site (also used by Lighter WooCommerce pages).
     *
     * @param string $html Page.
     * @return string[] Family names, as written.
     */
    public static function declared_families($html) {
        $names = self::font_faces($html);
        if (preg_match_all('#<link\b[^>]*>#i', $html, $links)) {
            $files = array();
            foreach ($links[0] as $tag) {
                $names = array_merge($names, self::link_families($tag, $files));
            }
        }
        return $names;
    }

    /**
     * The families a <link> declares: a Google Fonts stylesheet's, or the
     * @font-face rules of a font stylesheet on this site.
     *
     * @param string $tag   The <link> tag.
     * @param array  $files Font stylesheets read so far (path => true).
     * @return string[]
     */
    private static function link_families($tag, array &$files) {
        $attrs = SEOProStack_Used_Css_Links::attributes($tag);
        $href  = isset($attrs['href']) ? trim($attrs['href']) : '';
        if ('' === $href) {
            return array();
        }
        $google = self::google_fonts($href);
        if ($google) {
            return $google;
        }
        $file = self::font_file($href);
        if ('' === $file || isset($files[$file]) || count($files) >= self::MAX_FILES) {
            return array();
        }
        $files[$file] = true;
        $size         = (int) filesize($file);
        return $size > 0 && $size <= self::MAX_BYTES
            ? self::font_faces((string) file_get_contents($file)) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local stylesheet.
            : array();
    }

    /**
     * Whether a font stylesheet on this site was named on this page but its
     * file was not there (set by font_file()).
     *
     * @return bool
     */
    private static function font_stylesheet_missing() {
        return self::$unreadable;
    }

    /**
     * The fonts to leave as they are, lower case.
     *
     * @return string[]
     */
    private static function skipped() {
        $lines = preg_split('/\R/', (string) SEOProStack_Settings::get(self::SKIP_KEY), -1, PREG_SPLIT_NO_EMPTY);
        $lines = array_map('strtolower', array_map('trim', is_array($lines) ? $lines : array()));
        return array_values(array_filter($lines, function ($line) {
            return '' !== $line;
        }));
    }

    /**
     * Family names of the @font-face rules in some CSS or HTML.
     *
     * @param string $css CSS or HTML.
     * @return string[]
     */
    private static function font_faces($css) {
        if (false === stripos($css, '@font-face') || !preg_match_all('/@font-face\s*\{[^}]*?font-family\s*:\s*["\']?([^"\';}]+)["\']?/i', $css, $found)) {
            return array();
        }
        return $found[1];
    }

    /**
     * Family names in a Google Fonts stylesheet address.
     *
     * @param string $href Address.
     * @return string[]
     */
    private static function google_fonts($href) {
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (!preg_match('#^(?:https?:)?//fonts\.googleapis\.com/css2?\?#i', $href) || !preg_match_all('/[?&]family=([^&]+)/', $href, $found)) {
            return array();
        }
        $names = array();
        foreach ($found[1] as $value) {
            // css: Poppins:400,700|Lexend; css2: Poppins:wght@400;700 (one family each).
            foreach (explode('|', urldecode(str_replace('+', ' ', $value))) as $family) {
                $names[] = trim(explode(':', $family)[0]);
            }
        }
        return $names;
    }

    /**
     * The local file of a font stylesheet on this site: under wp-content/fonts/
     * (the Kadence theme's and WordPress's local fonts) or the uploads folder,
     * or named for fonts.
     *
     * @param string $href Address.
     * @return string Empty when it is not one.
     */
    private static function font_file($href) {
        $path = (string) wp_parse_url($href, PHP_URL_PATH);
        if ('' === $path || !SEOProStack_Used_Css_Links::on_site($href)) {
            return '';
        }
        $content = untrailingslashit((string) wp_parse_url(content_url(), PHP_URL_PATH));
        $uploads = wp_get_upload_dir();
        $upload  = untrailingslashit((string) wp_parse_url(isset($uploads['baseurl']) ? $uploads['baseurl'] : '', PHP_URL_PATH));
        $fonts   = 0 === strpos($path, $content . '/fonts/') || ('' !== $upload && 0 === strpos($path, $upload . '/'))
            || false !== stripos(basename($path), 'font');
        if (!$fonts) {
            return '';
        }
        $file = SEOProStack_Used_Css_Links::stylesheet_file($href);
        if ('' === $file && '.css' === strtolower(substr($path, -4))) {
            self::$unreadable = true; // Named as a font stylesheet on this site, but not there (GitHub issue #1603).
        }
        return $file;
    }

    /**
     * The measurements: version, fonts and bases (scripts/update-font-metrics.js).
     *
     * @return array
     */
    private static function metrics() {
        if (null === self::$metrics) {
            $file          = SEOPROSTACK_DIR . self::METRICS;
            $json          = is_readable($file) ? file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own data file.
            $data          = '' === $json ? null : json_decode((string) $json, true);
            self::$metrics = is_array($data) && isset($data['fonts'], $data['bases']) ? $data : array();
            foreach (array('arial', 'roboto', 'timesNewRoman', 'notoSerif') as $base) {
                if (empty(self::$metrics['bases'][$base]) || count(self::$metrics['bases'][$base]) < 14) {
                    self::$metrics = array();
                    break;
                }
            }
        }
        return self::$metrics;
    }
}
