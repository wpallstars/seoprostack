<?php
/**
 * Dark mode's fallback for screens without a hand-written section: every
 * other plugin's and theme's stylesheet gets a dark copy of its colours.
 *
 * In Dark, each stylesheet from wp-content (not WordPress's own, not SEO
 * Pro Stack's) is read once, and every rule that paints a light surface, a
 * light border or dark grey text gets the same rule again, behind
 * `html.sps-dark`, with the colour of the dark palette (`--sps-ui-*`):
 * white surfaces, light greys, light tints (as notices'), light borders and
 * dark grey text, also when painted through one of the stylesheet's own
 * colour properties (`var(--white)`). Brand colours (saturated buttons, badges), images and
 * custom colours set by script are left as they are. The copy loads right
 * after its stylesheet (style_loader_tag), and keeps the original
 * selector's specificity (`:where(html.sps-dark)`), so it wins over the
 * light rule it replaces and loses to anything more specific: the
 * hand-written sections in seoprostack-whole-theme.css, which load after,
 * still win. The block editor's canvas keeps the theme's colours.
 *
 * Copies are kept in uploads/seoprostack-dark, one per stylesheet, made
 * again when the stylesheet changes. A screen makes the missing ones for
 * up to 0.15 seconds and leaves the rest to the end of the request, so a
 * newly installed plugin's screens are dark from their first or second
 * load. Deactivation and uninstall remove the folder. The
 * `seoprostack_admin_dark_fallback` filter turns it off on a screen, and
 * `seoprostack_admin_dark_fallback_style` for one stylesheet.
 *
 * Not reached: inline styles, `<style>` blocks, colours set by script and
 * stylesheets from other hosts (the contrast guard still fixes their text).
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

final class SEOProStack_Dark_Fallback {

    /** The generator's version: a change makes every copy again. */
    const VERSION = '2';

    /** Folder in uploads for the copies. */
    const DIR = 'seoprostack-dark';

    /** Larger stylesheets are left as they are (bytes). */
    const MAX_BYTES = 1572864;

    /** Seconds a screen spends making copies before leaving the rest to the end of the request. */
    const BUDGET = 0.15;

    /** Seconds for those, at the end of the request. */
    const LATE_BUDGET = 3.0;

    /** The palette (seoprostack-whole-theme.css): name => RGB. */
    const PALETTE = array(
        'canvas'  => array(16, 21, 23),
        'surface' => array(29, 35, 39),
        'raised'  => array(44, 51, 56),
        'border'  => array(60, 67, 74),
        'text'    => array(240, 240, 241),
        'muted'   => array(195, 196, 199),
        'subtle'  => array(167, 170, 173),
    );

    /** Named colours plugins use for light surfaces and dark text. */
    const NAMED = array(
        'white'          => 'ffffff',
        'whitesmoke'     => 'f5f5f5',
        'snow'           => 'fffafa',
        'ghostwhite'     => 'f8f8ff',
        'aliceblue'      => 'f0f8ff',
        'ivory'          => 'fffff0',
        'linen'          => 'faf0e6',
        'floralwhite'    => 'fffaf0',
        'seashell'       => 'fff5ee',
        'honeydew'       => 'f0fff0',
        'mintcream'      => 'f5fffa',
        'azure'          => 'f0ffff',
        'lavender'       => 'e6e6fa',
        'lavenderblush'  => 'fff0f5',
        'beige'          => 'f5f5dc',
        'oldlace'        => 'fdf5e6',
        'cornsilk'       => 'fff8dc',
        'lightyellow'    => 'ffffe0',
        'lemonchiffon'   => 'fffacd',
        'mistyrose'      => 'ffe4e1',
        'antiquewhite'   => 'faebd7',
        'papayawhip'     => 'ffefd5',
        'blanchedalmond' => 'ffebcd',
        'gainsboro'      => 'dcdcdc',
        'lightgray'      => 'd3d3d3',
        'lightgrey'      => 'd3d3d3',
        'silver'         => 'c0c0c0',
        'darkgray'       => 'a9a9a9',
        'darkgrey'       => 'a9a9a9',
        'gray'           => '808080',
        'grey'           => '808080',
        'dimgray'        => '696969',
        'dimgrey'        => '696969',
        'black'          => '000000',
    );

    /**
     * Plugins with a dark mode of their own, which the head sets to match
     * (SEOProStack_Admin_Whole_Theme::head_script()): their stylesheets are
     * left to it.
     */
    const OWN_DARK = '#/plugins/(?:fluent-(?:crm|booking|support|smtp|boards|community|cart)(?:-pro)?|fluentcampaign-pro|litespeed-cache|bit-social(?:-pro)?)/#';

    /** One declaration: property and value (brackets and quotes kept whole). */
    const DECLARATION = '/(?<prop>-{0,2}[A-Za-z][\w-]*)\s*:\s*(?<val>(?:[^;()"\']++|(?<p>\((?:[^()"\']++|"[^"]*"|\'[^\']*\'|(?&p))*\))|"[^"]*"|\'[^\']*\')*)/';

    /** Colours in a value: hex, rgb(), hsl(), oklch() and the names above. */
    const COLOUR = '/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![0-9a-zA-Z])|\b(?:rgba?|hsla?|oklch)\([^()]*\)|(?<![\w#-])(?:white|whitesmoke|snow|ghostwhite|aliceblue|ivory|linen|floralwhite|seashell|honeydew|mintcream|azure|lavender|lavenderblush|beige|oldlace|cornsilk|lightyellow|lemonchiffon|mistyrose|antiquewhite|papayawhip|blanchedalmond|gainsboro|lightgr[ae]y|silver|darkgr[ae]y|gr[ae]y|dimgr[ae]y|black)(?![\w-])/i';

    /** Whether this request gets copies (null until asked). */
    private static $on = null;

    /** Seconds spent making copies in this request. */
    private static $spent = 0.0;

    /** Copies left to the end of the request: target => array(source, base). */
    private static $late = array();

    /** Property groups changed so far in the stylesheet being copied. */
    private static $changed = array();

    /** The stylesheet's folder (URL path), for its relative url()s. */
    private static $base = '';

    /** Whether the copy being made leaves out the block editor's canvas. */
    private static $canvas = false;

    /** The stylesheet's custom properties that hold one colour: name => value. */
    private static $vars = array();

    /**
     * Register on admin requests. Deactivation removes the copies through
     * SEOProStack_Setup::remove_dark_copies(), registered on every request.
     */
    public static function init() {
        add_filter('style_loader_tag', array(__CLASS__, 'tag'), 20, 4);
    }

    /**
     * Whether this screen gets copies: Dark (or System) chosen, a screen
     * that turns dark, and the filter.
     *
     * @return bool
     */
    private static function on() {
        if (null === self::$on) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            /**
             * Filter whether Dark mode's fallback (dark copies of other
             * plugins' stylesheets) loads on an admin screen.
             *
             * @param bool           $on     Whether it loads. Default true.
             * @param WP_Screen|null $screen The screen.
             */
            self::$on = is_admin() && SEOProStack_Admin_Whole_Theme::darkens() && (bool) apply_filters('seoprostack_admin_dark_fallback', true, $screen);
        }
        return self::$on;
    }

    /**
     * Print a stylesheet's dark copy right after it.
     *
     * @param string $tag    The stylesheet's <link> tag.
     * @param string $handle Its handle.
     * @param string $href   Its address.
     * @param string $media  Its media.
     * @return string
     */
    public static function tag($tag, $handle, $href = '', $media = 'all') {
        if (!is_string($tag) || '' === $tag || !self::on()) {
            return $tag;
        }
        $file = self::source((string) $href);
        if ('' === $file) {
            return $tag;
        }
        /**
         * Filter whether one stylesheet gets a dark copy in Dark mode.
         *
         * @param bool   $copy   Whether it does. Default true, but for
         *                       plugins with a dark mode of their own that
         *                       follows this one (OWN_DARK).
         * @param string $handle The stylesheet's handle.
         * @param string $href   Its address.
         */
        if (!apply_filters('seoprostack_admin_dark_fallback_style', !preg_match(self::OWN_DARK, wp_normalize_path($file)), $handle, $href)) {
            return $tag;
        }
        $url = self::copy($file, (string) $href);
        if ('' === $url) {
            return $tag;
        }
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- printed right after the stylesheet it follows, as standalone_tag().
        return $tag . '<link rel="stylesheet" id="seoprostack-dark-fallback-' . esc_attr($handle) . '-css" href="' . esc_url($url) . '" media="' . esc_attr($media ? $media : 'all') . '" />' . "\n";
    }

    /**
     * The file of a stylesheet in wp-content on this site, outside SEO Pro
     * Stack; empty for anything else.
     *
     * @param string $href Address.
     * @return string
     */
    private static function source($href) {
        $path = wp_parse_url($href, PHP_URL_PATH);
        $host = wp_parse_url($href, PHP_URL_HOST);
        if (!is_string($path) || '.css' !== strtolower(substr($path, -4))) {
            return '';
        }
        $content = content_url();
        if ($host && strtolower((string) $host) !== strtolower((string) wp_parse_url($content, PHP_URL_HOST))) {
            return '';
        }
        $root = untrailingslashit((string) wp_parse_url($content, PHP_URL_PATH));
        if (0 !== strpos($path, $root . '/')) {
            return '';
        }
        $relative = rawurldecode(substr($path, strlen($root)));
        if (false !== strpos($relative, '..') || 0 === strpos($relative, '/uploads/' . self::DIR . '/')) {
            return '';
        }
        $file = wp_normalize_path(WP_CONTENT_DIR . $relative);
        if (0 === strpos($file, wp_normalize_path(SEOPROSTACK_DIR))) {
            return '';
        }
        return is_file($file) && is_readable($file) ? $file : '';
    }

    /**
     * The folder for copies, and its address.
     *
     * @return array{path:string,url:string}|null
     */
    private static function folder() {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return null;
        }
        return array(
            'path' => untrailingslashit(wp_normalize_path($uploads['basedir'])) . '/' . self::DIR,
            'url'  => set_url_scheme(untrailingslashit($uploads['baseurl'])) . '/' . self::DIR,
        );
    }

    /**
     * The address of a stylesheet's copy, made now if time allows; empty
     * when it has none (yet).
     *
     * @param string $file Stylesheet file.
     * @param string $href Its address.
     * @return string
     */
    private static function copy($file, $href) {
        $folder = self::folder();
        $size   = (int) filesize($file);
        if (!$folder || $size < 1 || $size > self::MAX_BYTES) {
            return '';
        }
        $base   = dirname((string) wp_parse_url($href, PHP_URL_PATH));
        $canvas = self::canvas();
        // One copy per stylesheet and kind of screen (block editor or not), named for what it was made from.
        $name   = substr(hash('sha256', $file . '|' . (int) $canvas), 0, 12) . '-' . substr(hash('sha256', filemtime($file) . '|' . $size . '|' . $base . '|' . self::VERSION), 0, 8) . '.css';
        $target = $folder['path'] . '/' . $name;
        if (!file_exists($target)) {
            if (self::$spent >= self::BUDGET) {
                self::later($file, $target, array($base, $canvas));
                return '';
            }
            self::make($file, $target, array($base, $canvas));
        }
        return is_file($target) && filesize($target) > 0 ? $folder['url'] . '/' . $name : '';
    }

    /**
     * Whether this is the block editor, whose canvas, when not in a frame,
     * is part of the screen: its copies leave the canvas out.
     *
     * @return bool
     */
    private static function canvas() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen instanceof WP_Screen && $screen->is_block_editor();
    }

    /**
     * Leave a copy to the end of the request.
     *
     * @param string              $file   Stylesheet file.
     * @param string              $target Copy's file.
     * @param array{0:string,1:bool} $how Stylesheet's folder (URL path), and whether to leave out the editor's canvas.
     */
    private static function later($file, $target, array $how) {
        if (!self::$late) {
            add_action('shutdown', array(__CLASS__, 'finish'));
        }
        self::$late[$target] = array($file, $how);
    }

    /** Make the copies left to the end of the request, for up to LATE_BUDGET seconds. */
    public static function finish() {
        $until = microtime(true) + self::LATE_BUDGET;
        foreach (self::$late as $target => $job) {
            if (microtime(true) > $until) {
                break;
            }
            if (!file_exists($target)) {
                self::make($job[0], $target, $job[1]);
            }
        }
        self::$late = array();
    }

    /**
     * Make a stylesheet's copy; an empty file when it has nothing to change.
     *
     * @param string                 $file   Stylesheet file.
     * @param string                 $target Copy's file.
     * @param array{0:string,1:bool} $how    Stylesheet's folder (URL path), and whether to leave out the editor's canvas.
     */
    private static function make($file, $target, array $how) {
        $start = microtime(true);
        $out   = '';
        try {
            $css = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local stylesheet.
            $out = is_string($css) ? self::convert($css, $how[0], $how[1]) : '';
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('[SEO Pro Stack] Dark fallback skipped %s: %s', $file, $e->getMessage()));
            }
            $out = '';
        }
        if ('' !== $out) {
            $out = '/* SEO Pro Stack: Dark mode fallback for ' . str_replace('*/', '', wp_basename($file)) . " */\n" . $out;
        }
        self::write($target, $out);
        self::$spent += microtime(true) - $start;
    }

    /**
     * Write a copy whole (a temporary file, then renamed, as requests run
     * at once), and remove the stylesheet's older copies.
     *
     * @param string $target Copy's file.
     * @param string $css    Its content.
     */
    private static function write($target, $css) {
        $dir = dirname($target);
        if (!wp_mkdir_p($dir)) {
            return;
        }
        $temp = $target . '.' . wp_generate_password(8, false) . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file renamed into place.
        if (false === file_put_contents($temp, $css)) {
            return;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same directory.
        if (!@rename($temp, $target)) {
            wp_delete_file($temp);
            return;
        }
        $key = substr(wp_basename($target), 0, 13);
        foreach ((array) glob($dir . '/' . $key . '*.css') as $old) {
            if (is_string($old) && $old !== $target) {
                wp_delete_file($old);
            }
        }
    }

    /**
     * A stylesheet's dark copy: its rules that paint light surfaces,
     * borders or dark grey text, with dark colours.
     *
     * @param string $css    Stylesheet.
     * @param string $base   Its folder (URL path).
     * @param bool   $canvas Whether to leave out the block editor's canvas.
     * @return string
     */
    public static function convert($css, $base = '', $canvas = false) {
        self::$changed = array();
        self::$base    = rtrim($base, '/');
        self::$canvas  = (bool) $canvas;
        $css           = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        self::$vars    = self::colour_vars($css);
        return self::block($css);
    }

    /**
     * The stylesheet's custom properties that hold one colour, such as
     * `--white: #fff` or Tailwind's `--color-white`, so rules painting with
     * `var(--white)` can be copied too. A property given different colours
     * in the stylesheet (themes, states) is left out.
     *
     * @param string $css Stylesheet, without comments.
     * @return array<string,string>
     */
    private static function colour_vars($css) {
        $vars = array();
        if (!preg_match_all('/(?<![\w-])(--[\w-]+)\s*:\s*([^;{}]+)/', $css, $found, PREG_SET_ORDER)) {
            return $vars;
        }
        foreach ($found as $m) {
            $value = strtolower(trim((string) preg_replace('/!\s*important\s*$/i', '', $m[2])));
            if (!self::parse($value)) {
                $vars[$m[1]] = false;
                continue;
            }
            if (!isset($vars[$m[1]])) {
                $vars[$m[1]] = $value;
            } elseif ($vars[$m[1]] !== $value) {
                $vars[$m[1]] = false;
            }
        }
        return array_filter($vars, 'is_string');
    }

    /**
     * A value with the stylesheet's colour properties (colour_vars()) put
     * in place of their var().
     *
     * @param string $value Value.
     * @return string
     */
    private static function resolve($value) {
        if (!self::$vars || false === strpos($value, 'var(')) {
            return $value;
        }
        return (string) preg_replace_callback('/var\(\s*(--[\w-]+)\s*(?:,(?:[^()]|\([^()]*\))*)?\)/', function ($m) {
            return isset(self::$vars[$m[1]]) ? self::$vars[$m[1]] : $m[0];
        }, $value);
    }

    /**
     * The rules of a stylesheet or of a block (@media, @supports...).
     *
     * @param string $css Rules.
     * @return string
     */
    private static function block($css) {
        $out = '';
        $pos = 0;
        $len = strlen($css);
        while ($pos < $len) {
            $n  = strcspn($css, '{};', $pos);
            $at = $pos + $n;
            if ($at >= $len) {
                break;
            }
            if ('{' !== $css[$at]) {
                // A statement (@import, @charset) or a stray brace.
                $pos = $at + 1;
                continue;
            }
            $prelude = trim(substr($css, $pos, $n));
            $close   = self::close($css, $at);
            if ($close < 0) {
                break;
            }
            $body = substr($css, $at + 1, $close - $at - 1);
            $pos  = $close + 1;
            if ('' === $prelude) {
                continue;
            }
            if ('@' === $prelude[0]) {
                // Conditions keep their rules; @font-face, @keyframes and the like are left out.
                if (preg_match('/^@(?:media|supports|container|layer|scope)\b/i', $prelude)) {
                    $inner = self::block($body);
                    if ('' !== $inner) {
                        $out .= $prelude . '{' . $inner . '}';
                    }
                }
                continue;
            }
            $out .= self::rule($prelude, $body);
        }
        return $out;
    }

    /**
     * Where the block opened at `$open` closes.
     *
     * @param string $css  Stylesheet.
     * @param int    $open Position of `{`.
     * @return int -1 when it does not close.
     */
    private static function close($css, $open) {
        $depth = 0;
        $pos   = $open;
        $len   = strlen($css);
        while ($pos < $len) {
            $pos += strcspn($css, '{}', $pos);
            if ($pos >= $len) {
                return -1;
            }
            $depth += '{' === $css[$pos] ? 1 : -1;
            if (0 === $depth) {
                return $pos;
            }
            $pos++;
        }
        return -1;
    }

    /**
     * One rule's dark copy. Its declarations of a group (backgrounds,
     * borders, text colour, shadows, one custom property) are copied as
     * they are once an earlier rule's were changed, so the stylesheet's own
     * order still decides between its rules.
     *
     * @param string $selectors Selector list.
     * @param string $body      Declarations.
     * @return string
     */
    private static function rule($selectors, $body) {
        if (false !== strpos($body, '{') || !preg_match_all(self::DECLARATION, $body, $found, PREG_SET_ORDER)) {
            return '';
        }
        $declarations = array();
        $ground       = 'none';
        foreach ($found as $d) {
            $prop  = 0 === strpos($d['prop'], '--') ? $d['prop'] : strtolower($d['prop']);
            $value = trim($d['val']);
            $flag  = (bool) preg_match('/!\s*important\s*$/i', $value);
            if ($flag) {
                $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
            }
            $group = self::group($prop);
            if ('' === $group || '' === $value) {
                continue;
            }
            $new = 'color' === $group ? null : self::map($group, $prop, $value);
            if ('bg' === $group) {
                // Text on a ground the copy keeps (a brand colour, an image) stays as it is.
                $ground = null !== $new ? 'dark' : (preg_match(self::COLOUR, self::resolve($value)) || false !== stripos($value, 'url(') ? 'kept' : $ground);
            }
            $declarations[] = array($prop, $value, $flag, $group, $new);
        }
        $lines = array();
        foreach ($declarations as $d) {
            list($prop, $value, $flag, $group, $new) = $d;
            if ('color' === $group && 'kept' !== $ground) {
                $new = self::map($group, $prop, $value);
            }
            if (null !== $new) {
                self::$changed[$group] = true;
                $lines[]               = $prop . ':' . $new . ($flag ? '!important' : '');
            } elseif (!empty(self::$changed[$group])) {
                $lines[] = $prop . ':' . self::urls($value) . ($flag ? '!important' : '');
            }
        }
        if (!$lines) {
            return '';
        }
        $list = self::selectors($selectors);
        return '' === $list ? '' : $list . '{' . implode(';', $lines) . '}';
    }

    /**
     * A property's group: bg, border, color, shadow, the custom property's
     * own name, or empty for properties the copy leaves out.
     *
     * @param string $prop Property.
     * @return string
     */
    private static function group($prop) {
        if (0 === strpos($prop, '--')) {
            return $prop;
        }
        if ('background' === $prop || 'background-color' === $prop) {
            return 'bg';
        }
        if ('color' === $prop) {
            return 'color';
        }
        if ('box-shadow' === $prop) {
            return 'shadow';
        }
        if (preg_match('/^border(?:-(?:top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end))?(?:-color)?$/', $prop)) {
            return 'border';
        }
        return '';
    }

    /**
     * A declaration's dark value, or null to leave it.
     *
     * @param string $group Group (group()).
     * @param string $prop  Property.
     * @param string $value Value, without !important.
     * @return string|null
     */
    private static function map($group, $prop, $value) {
        if (0 !== strpos($group, '--')) {
            $value = self::resolve($value);
        }
        if ('bg' === $group) {
            return false !== stripos($value, 'url(') || false !== stripos($value, 'image-set(') ? null : self::each($value, 'ground');
        }
        if ('border' === $group) {
            return self::each($value, 'line');
        }
        if ('shadow' === $group) {
            return self::each($value, 'shadow');
        }
        $colour = self::parse($value);
        if (!$colour) {
            return null;
        }
        if ('color' === $group) {
            return self::text($colour);
        }
        /*
         * A custom property holding one colour, by its name: surfaces and
         * lines when light, text when dark. Names that say only a colour
         * (--white, --light) may be text on a brand colour, so they stay, as
         * do WordPress's presets (the theme's colours).
         */
        $name = strtolower(substr($prop, 2));
        if (0 === strpos($name, 'wp--')) {
            return null;
        }
        $line = (bool) preg_match('/border|divider|separator|outline|stroke|line/', $name);
        $bg   = (bool) preg_match('/bg|background|surface|canvas|panel|card|box|body|backdrop/', $name);
        if (self::luminance($colour) > 0.5) {
            if ($line) {
                return self::line($colour, true);
            }
            return $bg ? self::ground($colour) : null;
        }
        if (!$line && !$bg && preg_match('/text|font|heading|title|label|fg|foreground|ink|copy/', $name)) {
            return self::text($colour);
        }
        return null;
    }

    /**
     * Every colour in a value, through one of ground(), line() or shadow;
     * null when none changes.
     *
     * @param string $value Value.
     * @param string $how   ground, line or shadow.
     * @return string|null
     */
    private static function each($value, $how) {
        $changed = false;
        $out     = preg_replace_callback(self::COLOUR, function ($m) use ($how, &$changed) {
            $colour = self::parse($m[0]);
            if (!$colour) {
                return $m[0];
            }
            $new = 'ground' === $how ? self::ground($colour) : self::line($colour, 'line' === $how);
            if (null === $new) {
                return $m[0];
            }
            $changed = true;
            return $new;
        }, $value);
        return $changed && is_string($out) ? $out : null;
    }

    /**
     * A light surface's dark colour: white to surface, light greys to
     * canvas, mid greys to raised, light tints to a dark one of their hue.
     * Brand colours, dark colours and faint overlays stay.
     *
     * @param array{0:float,1:float,2:float,3:float} $c Colour.
     * @return string|null
     */
    private static function ground(array $c) {
        if ($c[3] < 0.5 || self::luminance($c) <= 0.5) {
            return null;
        }
        list($h, $s, $l, $chroma) = self::hsl($c);
        if ($chroma < 0.03) {
            $y = self::luminance($c);
            return self::token($y >= 0.97 ? 'surface' : ($y >= 0.8 ? 'canvas' : 'raised'), $c[3]);
        }
        if ($l >= 0.85) {
            return self::rgb(self::from_hsl($h, min($s, 0.4), 0.14), $c[3]);
        }
        return null;
    }

    /**
     * A light line's dark colour: greys to the palette's border, light tints
     * (with $tints) to a dark one of their hue.
     *
     * @param array{0:float,1:float,2:float,3:float} $c     Colour.
     * @param bool                                   $tints Whether tinted lines change too.
     * @return string|null
     */
    private static function line(array $c, $tints) {
        if ($c[3] < 0.3 || self::luminance($c) <= 0.3) {
            return null;
        }
        list($h, $s, $l, $chroma) = self::hsl($c);
        if ($chroma < 0.06) {
            return self::token('border', $c[3]);
        }
        if ($tints && $l >= 0.75) {
            return self::rgb(self::from_hsl($h, min($s, 0.4), 0.3), $c[3]);
        }
        return null;
    }

    /**
     * Dark grey text's light colour: near black to text, dark greys to
     * muted, mid greys to subtle. Coloured text is left to the contrast
     * guard.
     *
     * @param array{0:float,1:float,2:float,3:float} $c Colour.
     * @return string|null
     */
    private static function text(array $c) {
        if ($c[3] < 0.5) {
            return null;
        }
        $hsl = self::hsl($c);
        $y   = self::luminance($c);
        if ($hsl[3] >= 0.06 || $y >= 0.33) {
            return null;
        }
        return self::token($y < 0.05 ? 'text' : ($y < 0.2 ? 'muted' : 'subtle'), $c[3]);
    }

    /**
     * A palette colour: its custom property, or its value with the original
     * opacity.
     *
     * @param string $name  Palette name.
     * @param float  $alpha Opacity.
     * @return string
     */
    private static function token($name, $alpha) {
        $rgb = self::PALETTE[$name];
        if ($alpha >= 0.99) {
            return sprintf('var(--sps-ui-%s,#%02x%02x%02x)', $name, $rgb[0], $rgb[1], $rgb[2]);
        }
        return self::rgb($rgb, $alpha);
    }

    /**
     * An rgb() value.
     *
     * @param array<int,float|int> $rgb   Red, green, blue (0-255).
     * @param float                $alpha Opacity.
     * @return string
     */
    private static function rgb(array $rgb, $alpha) {
        $rgb = array_map('round', array_slice($rgb, 0, 3));
        return $alpha >= 0.99 ? sprintf('rgb(%d,%d,%d)', $rgb[0], $rgb[1], $rgb[2]) : sprintf('rgba(%d,%d,%d,%s)', $rgb[0], $rgb[1], $rgb[2], round($alpha, 3));
    }

    /**
     * Parse one colour (the whole value): hex, rgb(), hsl() or a name above.
     *
     * @param string $value Colour.
     * @return array{0:float,1:float,2:float,3:float}|null Red, green, blue (0-255) and opacity.
     */
    private static function parse($value) {
        $value = strtolower(trim($value));
        if (isset(self::NAMED[$value])) {
            $value = '#' . self::NAMED[$value];
        }
        if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $m)) {
            $hex = $m[1];
            if (3 === strlen($hex) || 4 === strlen($hex)) {
                $hex = preg_replace('/(.)/', '$1$1', $hex);
            }
            if (6 !== strlen((string) $hex) && 8 !== strlen((string) $hex)) {
                return null;
            }
            $hex = (string) $hex;
            return array((float) hexdec(substr($hex, 0, 2)), (float) hexdec(substr($hex, 2, 2)), (float) hexdec(substr($hex, 4, 2)), 8 === strlen($hex) ? hexdec(substr($hex, 6, 2)) / 255 : 1.0);
        }
        if (!preg_match('/^(rgba?|hsla?|oklch)\(([^()]*)\)$/', $value, $m) || preg_match('/[a-z]{3,}/', str_replace(array('deg', 'turn'), '', $m[2]))) {
            return null;
        }
        $parts = preg_split('/[\s,\/]+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 3) {
            return null;
        }
        $alpha = 1.0;
        if (isset($parts[3])) {
            $alpha = '%' === substr($parts[3], -1) ? (float) $parts[3] / 100 : (float) $parts[3];
        }
        if ('oklch' === $m[1]) {
            $rgb = self::from_oklch('%' === substr($parts[0], -1) ? (float) $parts[0] / 100 : (float) $parts[0], '%' === substr($parts[1], -1) ? (float) $parts[1] * 0.004 : (float) $parts[1], (float) $parts[2] * (false !== strpos($parts[2], 'turn') ? 360 : 1));
            return array($rgb[0], $rgb[1], $rgb[2], max(0.0, min(1.0, $alpha)));
        }
        if (0 === strpos($m[1], 'hsl')) {
            $hue = (float) $parts[0] * (false !== strpos($parts[0], 'turn') ? 360 : 1);
            $rgb = self::from_hsl(fmod(fmod($hue, 360) + 360, 360), (float) $parts[1] / 100, (float) $parts[2] / 100);
            return array($rgb[0], $rgb[1], $rgb[2], max(0.0, min(1.0, $alpha)));
        }
        return array(self::channel($parts[0]), self::channel($parts[1]), self::channel($parts[2]), max(0.0, min(1.0, $alpha)));
    }

    /**
     * RGB (0-255, clipped to sRGB) from OKLCH: lightness (0-1), chroma and
     * hue (degrees).
     *
     * @param float $l Lightness.
     * @param float $c Chroma.
     * @param float $h Hue.
     * @return array{0:float,1:float,2:float}
     */
    private static function from_oklch($l, $c, $h) {
        $a  = $c * cos(deg2rad($h));
        $b  = $c * sin(deg2rad($h));
        $l1 = pow($l + 0.3963377774 * $a + 0.2158037573 * $b, 3);
        $m1 = pow($l - 0.1055613458 * $a - 0.0638541728 * $b, 3);
        $s1 = pow($l - 0.0894841775 * $a - 1.2914855480 * $b, 3);
        $lin = array(
            4.0767416621 * $l1 - 3.3077115913 * $m1 + 0.2309699292 * $s1,
            -1.2684380046 * $l1 + 2.6097574011 * $m1 - 0.3413193965 * $s1,
            -0.0041960863 * $l1 - 0.7034186147 * $m1 + 1.7076147010 * $s1,
        );
        $out = array();
        foreach ($lin as $v) {
            $v     = max(0.0, min(1.0, $v));
            $out[] = 255 * ($v <= 0.0031308 ? 12.92 * $v : 1.055 * pow($v, 1 / 2.4) - 0.055);
        }
        return array($out[0], $out[1], $out[2]);
    }

    /**
     * One rgb() channel (a number or a percentage) as 0-255.
     *
     * @param string $part Channel.
     * @return float
     */
    private static function channel($part) {
        return max(0.0, min(255.0, '%' === substr($part, -1) ? (float) $part * 2.55 : (float) $part));
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
     * Hue (degrees), saturation and lightness (0-1), and chroma (0-1).
     *
     * @param array<int,float> $c Colour.
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function hsl(array $c) {
        $r   = $c[0] / 255;
        $g   = $c[1] / 255;
        $b   = $c[2] / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d   = $max - $min;
        $l   = ($max + $min) / 2;
        $s   = $d > 0 ? $d / (1 - abs(2 * $l - 1)) : 0.0;
        $h   = 0.0;
        if ($d > 0) {
            if ($max === $r) {
                $h = 60 * fmod(($g - $b) / $d + 6, 6);
            } elseif ($max === $g) {
                $h = 60 * (($b - $r) / $d + 2);
            } else {
                $h = 60 * (($r - $g) / $d + 4);
            }
        }
        return array($h, min(1.0, $s), $l, $d);
    }

    /**
     * RGB (0-255) from hue (degrees), saturation and lightness (0-1).
     *
     * @param float $h Hue.
     * @param float $s Saturation.
     * @param float $l Lightness.
     * @return array{0:float,1:float,2:float}
     */
    private static function from_hsl($h, $s, $l) {
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        $rgb = array(array($c, $x, 0), array($x, $c, 0), array(0, $c, $x), array(0, $x, $c), array($x, 0, $c), array($c, 0, $x));
        $one = $rgb[(int) floor(fmod($h, 360) / 60) % 6];
        return array(($one[0] + $m) * 255, ($one[1] + $m) * 255, ($one[2] + $m) * 255);
    }

    /**
     * Relative url()s made absolute (from the site's root), as the copy is
     * in another folder.
     *
     * @param string $value Value.
     * @return string
     */
    private static function urls($value) {
        if (false === stripos($value, 'url(') || '' === self::$base) {
            return $value;
        }
        return (string) preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ($m) {
            $url = trim($m[2]);
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $url)) {
                return $m[0];
            }
            $path = self::$base . '/' . $url;
            do {
                $path = (string) preg_replace('#/(?:\./)+#', '/', $path, -1, $n);
                $path = (string) preg_replace('#/(?!\.\./)[^/]+/\.\./#', '/', $path, -1, $k);
            } while ($n + $k > 0);
            return 'url("' . str_replace('"', '%22', $path) . '")';
        }, $value);
    }

    /**
     * The selector list behind `html.sps-dark`, at the original
     * specificity, outside the block editor's canvas.
     *
     * @param string $list Selector list.
     * @return string Empty when no selector is left.
     */
    private static function selectors($list) {
        $out = array();
        foreach (self::split($list) as $one) {
            $one = trim($one);
            // The canvas keeps the theme's colours; selected text keeps its highlight.
            if ('' === $one || false !== strpos($one, 'editor-styles-wrapper') || false !== stripos($one, 'selection')) {
                continue;
            }
            // Pseudo-elements stay last.
            $pseudo = '';
            if (preg_match('/^(.*?)((?:::?(?:before|after|first-line|first-letter|placeholder|selection|marker|backdrop|file-selector-button|-webkit-[\w-]+|-moz-[\w-]+|-ms-[\w-]+)(?:\([^)]*\))?)+)$/is', $one, $m)) {
                $one    = '' === trim($m[1]) || preg_match('/[\s>+~]$/', $m[1]) ? $m[1] . '*' : $m[1];
                $pseudo = $m[2];
            }
            $one .= (self::$canvas ? ':where(:not(.editor-styles-wrapper *))' : '') . $pseudo;
            if (preg_match('/^html(?![\w-])/i', $one)) {
                $one = 'html:where(.sps-dark)' . substr($one, 4);
            } elseif (0 === stripos($one, ':root')) {
                $one = ':root:where(.sps-dark)' . substr($one, 5);
            } else {
                $one = ':where(html.sps-dark) ' . $one;
            }
            $out[] = $one;
        }
        return implode(',', $out);
    }

    /**
     * A selector list's selectors (commas inside brackets kept).
     *
     * @param string $list Selector list.
     * @return string[]
     */
    private static function split($list) {
        $parts = array();
        $depth = 0;
        $start = 0;
        $len   = strlen($list);
        for ($i = 0; $i < $len; $i++) {
            $ch = $list[$i];
            if ('(' === $ch || '[' === $ch) {
                $depth++;
            } elseif (')' === $ch || ']' === $ch) {
                $depth--;
            } elseif (',' === $ch && 0 === $depth) {
                $parts[] = substr($list, $start, $i - $start);
                $start   = $i + 1;
            }
        }
        $parts[] = substr($list, $start);
        return $parts;
    }

    /** Remove this site's copies. */
    public static function remove() {
        $folder = self::folder();
        if (!$folder || !is_dir($folder['path'])) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $filesystem = new WP_Filesystem_Direct(null);
        $filesystem->delete($folder['path'], true, 'd');
    }
}
