<?php
/**
 * Page time and database queries per plugin, measured when someone asks.
 *
 * Part of Plugin sizes. The Plugins screen's Measure page time button adds
 * a small must-use file, asks the site's home page and newest post a few
 * times each with a one-time token, then deletes the file. A page asked
 * with the right token records, for each plugin, must-use plugin and the
 * theme:
 * - the time and memory it took to load its main file;
 * - the time spent in its hook callbacks and shortcodes, not counting
 *   callbacks of others they set off (each callback's file names its
 *   owner, as Code Profiler does);
 * - its database queries and their time (SAVEQUERIES for that page only),
 *   and its calls to other sites and their time.
 * The page writes what it found to a transient; the Plugins screen keeps
 * the fastest of each page's runs and averages the pages. Without the
 * token the file does nothing. The query argument also makes Load plugins
 * only where needed load every plugin, so each active plugin is measured.
 *
 * Callbacks with parameters passed by reference and PHP's own functions
 * are not wrapped: their time counts for the code that set them off.
 *
 * The profiler half runs from the must-use file before any plugin loads,
 * so it uses only WordPress core. Single sites only.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Plugin_Cost {

    /** Query argument carrying the token and the run number. */
    const ARG = 'seoprostack-cost';

    /** Transient: the measurement under way (token, pages, user, time). */
    const TOKEN = 'seoprostack_cost_token';

    /** Option (not autoloaded): the last measurement. */
    const RESULTS = 'seoprostack_plugin_cost';

    /** Must-use file, named to load before other must-use plugins. */
    const FILE = '000-seoprostack-plugin-cost.php';

    /** Marks the must-use file as ours. */
    const MARKER = 'SEO Pro Stack page time profiler';

    /** AJAX action and nonce action. */
    const AJAX = 'seoprostack_plugin_cost';

    /** Runs of each page; the fastest counts. */
    const RUNS = 3;

    /** Seconds a measurement, and each page's findings, are kept for. */
    const LIFETIME = 600;

    /** Whether this request is being measured. */
    private static $on = false;

    /** Transient this request's findings go to. */
    private static $key = '';

    /** hrtime() when measuring started. */
    private static $started = 0;

    /**
     * Callbacks running now, innermost last: owner, start, time in callbacks they set off.
     *
     * @var array<int,array{0:string,1:int|float,2:int|float}>
     */
    private static $stack = array();

    /**
     * Code running outside any callback: owner ('' while a file loads), start, time in callbacks, memory at start.
     *
     * @var array{owner:string,start:int|float,child:int|float,mem:int}
     */
    private static $root = array('owner' => '', 'start' => 0, 'child' => 0, 'mem' => 0);

    /**
     * Findings per owner: ns (own time), load (ns loading), mem (bytes loading), q, qs (seconds), http, hs (seconds).
     *
     * @var array<string,array<string,int|float>>
     */
    private static $found = array();

    /**
     * Owner of each callback seen, by WordPress's callback ID; null when not wrapped.
     *
     * @var array<string,string|null>
     */
    private static $owners = array();

    /**
     * Calls to other sites under way: owner and start.
     *
     * @var array<int,array{0:string,1:int|float}>
     */
    private static $http = array();

    /**
     * Shortcodes under way: whether each pushed a frame.
     *
     * @var bool[]
     */
    private static $shortcodes = array();

    /* ------------------------------------------------------------------
     * Profiler: runs from the must-use file, before plugins load.
     * ------------------------------------------------------------------ */

    /**
     * Start measuring this request if it carries the current token.
     */
    public static function start() {
        if (self::$on || is_multisite() || is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against the token below.
        $arg = isset($_GET[self::ARG]) && is_string($_GET[self::ARG]) ? (string) wp_unslash($_GET[self::ARG]) : '';
        if (!preg_match('/^([A-Za-z0-9]{32})\.(\d{1,2})$/', $arg, $match)) {
            return;
        }
        $token = get_transient(self::TOKEN);
        if (!is_array($token) || empty($token['token']) || !hash_equals((string) $token['token'], $match[1])) {
            return;
        }

        self::$on      = true;
        self::$key     = self::run_key($match[1], (int) $match[2]);
        self::$started = hrtime(true);
        self::$root    = array('owner' => '', 'start' => self::$started, 'child' => 0, 'mem' => memory_get_usage());
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's constant, for this measured page only.
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page caches' shared constant: never keep this page.
        }

        add_action('all', array(__CLASS__, 'wrap'));
        add_action('mu_plugin_loaded', array(__CLASS__, 'mu_plugin_loaded'), PHP_INT_MIN);
        add_action('muplugins_loaded', array(__CLASS__, 'core_done'), PHP_INT_MIN);
        add_action('plugin_loaded', array(__CLASS__, 'plugin_loaded'), PHP_INT_MIN);
        add_action('plugins_loaded', array(__CLASS__, 'plugins_loaded'), PHP_INT_MIN);
        add_action('setup_theme', array(__CLASS__, 'core_done'), PHP_INT_MIN);
        add_action('after_setup_theme', array(__CLASS__, 'theme_loaded'), PHP_INT_MIN);
        add_filter('template_include', array(__CLASS__, 'template'), PHP_INT_MAX);
        add_filter('pre_do_shortcode_tag', array(__CLASS__, 'shortcode_start'), PHP_INT_MAX, 2);
        add_filter('do_shortcode_tag', array(__CLASS__, 'shortcode_end'), PHP_INT_MIN);
        if (SAVEQUERIES) {
            add_filter('log_query_custom_data', array(__CLASS__, 'query_logged'), 10, 3);
        } else {
            add_filter('query', array(__CLASS__, 'query_started'));
        }
        add_filter('pre_http_request', array(__CLASS__, 'http_start'), PHP_INT_MAX);
        add_action('http_api_debug', array(__CLASS__, 'http_end'), PHP_INT_MIN);
        add_action('shutdown', array(__CLASS__, 'finish'), PHP_INT_MAX);
    }

    /**
     * Before a hook's callbacks run, wrap the ones not wrapped yet so their
     * time is counted for their owner. Hooked to "all".
     *
     * @param string $tag Hook name.
     */
    public static function wrap($tag) {
        global $wp_filter;
        if (!self::$on || !isset($wp_filter[$tag]) || !($wp_filter[$tag] instanceof WP_Hook)) {
            return;
        }
        $hook = $wp_filter[$tag];
        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $id => $callback) {
                if ($callback['function'] instanceof SEOProStack_Plugin_Cost_Call) {
                    continue;
                }
                $owner = self::callback_owner((string) $id, $callback['function']);
                if (null !== $owner) {
                    // Same ID, so has_filter() and remove_filter() still find it.
                    $hook->callbacks[$priority][$id] = array(
                        'function'      => new SEOProStack_Plugin_Cost_Call($callback['function'], $owner),
                        'accepted_args' => $callback['accepted_args'],
                    );
                }
            }
        }
    }

    /**
     * A callback started.
     *
     * @param string $owner Owner.
     */
    public static function push($owner) {
        self::$stack[] = array($owner, hrtime(true), 0);
    }

    /**
     * The innermost callback ended: its own time goes to its owner.
     */
    public static function pop() {
        $frame = array_pop(self::$stack);
        if (null === $frame) {
            return;
        }
        $elapsed = hrtime(true) - $frame[1];
        self::add($frame[0], 'ns', $elapsed - $frame[2]);
        if (self::$stack) {
            self::$stack[count(self::$stack) - 1][2] += $elapsed;
        } else {
            self::$root['child'] += $elapsed;
        }
    }

    /**
     * A must-use plugin loaded.
     *
     * @param string $file Its path.
     */
    public static function mu_plugin_loaded($file) {
        $name = basename((string) $file);
        self::mark(self::FILE === $name ? 'core' : 'mu--' . preg_replace('/\.php$/', '', $name), self::FILE !== $name, '');
    }

    /**
     * Must-use plugins have loaded, or the theme is about to: the time since
     * the last file is WordPress's.
     */
    public static function core_done() {
        self::mark('core', false, '');
    }

    /**
     * A plugin loaded.
     *
     * @param string $file Its path.
     */
    public static function plugin_loaded($file) {
        self::mark(self::plugin_key(plugin_basename((string) $file)), true, '');
    }

    /**
     * Every plugin has loaded.
     */
    public static function plugins_loaded() {
        self::mark('core', false, 'core');
    }

    /**
     * The theme's functions.php files have loaded.
     */
    public static function theme_loaded() {
        self::mark('theme--' . get_stylesheet(), true, 'core');
    }

    /**
     * The page's template is chosen: from here, code outside callbacks is the template's.
     *
     * @param string $template Template file.
     * @return string
     */
    public static function template($template) {
        if (self::$on && is_string($template) && '' !== $template) {
            $owner = self::source($template);
            self::mark('core', false, 'other' === $owner ? 'core' : $owner);
        }
        return $template;
    }

    /**
     * A shortcode is about to run.
     *
     * @param false|string $output Output from an earlier filter, or false.
     * @param string       $tag    Shortcode.
     * @return false|string
     */
    public static function shortcode_start($output, $tag) {
        global $shortcode_tags;
        if (false !== $output || !self::$on) {
            return $output;
        }
        $owner = isset($shortcode_tags[$tag]) ? self::callback_owner('shortcode:' . $tag, $shortcode_tags[$tag]) : null;
        if (null !== $owner) {
            self::push($owner);
        }
        self::$shortcodes[] = null !== $owner;
        return $output;
    }

    /**
     * A shortcode has run.
     *
     * @param string $output Output.
     * @return string
     */
    public static function shortcode_end($output) {
        if (self::$shortcodes && array_pop(self::$shortcodes)) {
            self::pop();
        }
        return $output;
    }

    /**
     * A database query ran (SAVEQUERIES on).
     *
     * @param array $data  Custom data.
     * @param string $query Query.
     * @param float  $time  Seconds.
     * @return array
     */
    public static function query_logged($data, $query, $time) {
        unset($query);
        $owner = self::current();
        self::add($owner, 'q', 1);
        self::add($owner, 'qs', (float) $time);
        return $data;
    }

    /**
     * A database query is about to run (SAVEQUERIES set off by the site: counts only).
     *
     * @param string $query Query.
     * @return string
     */
    public static function query_started($query) {
        self::add(self::current(), 'q', 1);
        return $query;
    }

    /**
     * A call to another site is about to start.
     *
     * @param mixed $pre Answer from an earlier filter, or false.
     * @return mixed
     */
    public static function http_start($pre) {
        if (false === $pre && self::$on) {
            self::$http[] = array(self::current(), hrtime(true));
        }
        return $pre;
    }

    /**
     * A call to another site has ended.
     */
    public static function http_end() {
        $call = array_pop(self::$http);
        if (null !== $call) {
            self::add($call[0], 'http', 1);
            self::add($call[0], 'hs', (hrtime(true) - $call[1]) / 1e9);
        }
    }

    /**
     * The page is done: store what was found for the Plugins screen.
     */
    public static function finish() {
        global $wpdb;
        if (!self::$on) {
            return;
        }
        // Callbacks that ended the request (exit) never returned.
        while (self::$stack) {
            self::pop();
        }
        self::mark('' === self::$root['owner'] ? 'core' : self::$root['owner'], false, 'core');
        self::$on = false;
        remove_action('all', array(__CLASS__, 'wrap'));

        $measured = hrtime(true) - self::$started;
        // WordPress's own start, before the must-use file loaded.
        $start  = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
        $before = $start > 0 ? max(0, (int) ((microtime(true) - $start) * 1e9) - $measured) : 0;
        self::add('core', 'ns', $before);

        $owners = array();
        foreach (self::$found as $owner => $found) {
            $owners[$owner] = array(
                'ms'   => round(($found['ns'] ?? 0) / 1e6, 2),
                'load' => round(($found['load'] ?? 0) / 1e6, 2),
                'mem'  => (int) ($found['mem'] ?? 0),
                'q'    => (int) ($found['q'] ?? 0),
                'qms'  => round(($found['qs'] ?? 0) * 1000, 2),
                'http' => (int) ($found['http'] ?? 0),
                'hms'  => round(($found['hs'] ?? 0) * 1000, 2),
            );
        }
        set_transient(self::$key, array(
            'ms'     => round(($measured + $before) / 1e6, 2),
            'q'      => (int) $wpdb->num_queries,
            'mem'    => memory_get_peak_usage(),
            'owners' => $owners,
        ), self::LIFETIME);
    }

    /**
     * The time since the last mark, outside callbacks, belongs to $owner.
     *
     * @param string $owner   Owner.
     * @param bool   $loading Whether that time was spent loading its files.
     * @param string $next    Owner of what runs next outside callbacks ('' until known).
     */
    private static function mark($owner, $loading, $next) {
        if (!self::$on) {
            return;
        }
        $now  = hrtime(true);
        $mem  = memory_get_usage();
        $time = max(0, $now - self::$root['start'] - self::$root['child']);
        self::add($owner, 'ns', $time);
        if ($loading) {
            self::add($owner, 'load', $time);
            self::add($owner, 'mem', max(0, $mem - self::$root['mem']));
        }
        if ('' !== $owner && isset(self::$found[''])) {
            // Queries and calls made while the file loaded, before its owner was known.
            foreach (self::$found[''] as $what => $value) {
                self::add($owner, $what, $value);
            }
            unset(self::$found['']);
        }
        self::$root = array('owner' => $next, 'start' => $now, 'child' => 0, 'mem' => $mem);
    }

    /**
     * Add to an owner's findings.
     *
     * @param string    $owner Owner.
     * @param string    $what  Finding.
     * @param int|float $value Amount.
     */
    private static function add($owner, $what, $value) {
        if (!isset(self::$found[$owner][$what])) {
            self::$found[$owner][$what] = 0;
        }
        self::$found[$owner][$what] += $value;
    }

    /**
     * Owner of the code running now.
     *
     * @return string
     */
    private static function current() {
        return self::$stack ? self::$stack[count(self::$stack) - 1][0] : self::$root['owner'];
    }

    /**
     * Owner of a callback, or null to leave it unwrapped: the profiler's
     * own, PHP's own functions, ones that take parameters by reference, or
     * ones that cannot be read.
     *
     * @param string $id       Callback ID.
     * @param mixed  $function Callback.
     * @return string|null
     */
    private static function callback_owner($id, $function) {
        if (array_key_exists($id, self::$owners)) {
            return self::$owners[$id];
        }
        $owner = null;
        try {
            $reflection = null;
            if (is_array($function) && isset($function[0], $function[1]) && is_string($function[1])) {
                $class = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];
                if (__CLASS__ !== $class && false === strpos($function[1], '::')) {
                    $reflection = new ReflectionMethod($function[0], $function[1]);
                }
            } elseif (is_string($function)) {
                $reflection = false !== strpos($function, '::') ? new ReflectionMethod($function) : new ReflectionFunction($function);
            } elseif ($function instanceof Closure) {
                $reflection = new ReflectionFunction($function);
            } elseif (is_object($function) && method_exists($function, '__invoke')) {
                $reflection = new ReflectionMethod($function, '__invoke');
            }
            if ($reflection && !$reflection->isInternal() && !self::by_reference($reflection)) {
                $owner = self::source((string) $reflection->getFileName());
            }
        } catch (ReflectionException $e) {
            unset($e); // Magic methods and missing functions stay unwrapped.
        }
        self::$owners[$id] = $owner;
        return $owner;
    }

    /**
     * Whether a function takes any parameter by reference.
     *
     * @param ReflectionFunctionAbstract $reflection Function.
     * @return bool
     */
    private static function by_reference(ReflectionFunctionAbstract $reflection) {
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isPassedByReference()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Owner of a PHP file: a plugin key (its folder, or its file name
     * without .php), mu--{name}, theme--{folder}, core, or other.
     *
     * @param string $file Path.
     * @return string
     */
    public static function source($file) {
        static $roots = null;
        if (null === $roots) {
            $roots = array(
                'mu'     => wp_normalize_path(WPMU_PLUGIN_DIR) . '/',
                'plugin' => wp_normalize_path(WP_PLUGIN_DIR) . '/',
                'theme'  => wp_normalize_path(get_theme_root()) . '/',
                'core'   => wp_normalize_path(ABSPATH),
            );
        }
        // eval()'d code reports "path/file.php(12) : eval()'d code".
        $file = (string) preg_replace('/\(\d+\) : .*$/', '', wp_normalize_path($file));
        foreach ($roots as $type => $root) {
            if (0 !== strpos($file, $root)) {
                continue;
            }
            $rest = substr($file, strlen($root));
            if ('core' === $type) {
                return (0 === strpos($rest, WPINC . '/') || 0 === strpos($rest, 'wp-admin/')) ? 'core' : 'other';
            }
            $slash = strpos($rest, '/');
            $name  = (string) (false === $slash ? preg_replace('/\.php$/', '', $rest) : substr($rest, 0, $slash));
            if ('' === $name) {
                return 'other';
            }
            if ('mu' === $type) {
                return 'mu--' . $name;
            }
            return 'plugin' === $type ? $name : 'theme--' . $name;
        }
        return 'other';
    }

    /**
     * Key of a plugin in the findings: its folder, or its file name without .php.
     *
     * @param string $file Plugin file, relative to the plugins folder.
     * @return string
     */
    public static function plugin_key($file) {
        $dir = dirname($file);
        return '.' === $dir ? (string) preg_replace('/\.php$/', '', $file) : $dir;
    }

    /**
     * Transient for one run's findings.
     *
     * @param string $token Token.
     * @param int    $run   Run number.
     * @return string
     */
    private static function run_key($token, $run) {
        return 'seoprostack_cost_' . substr(md5($token . '.' . $run), 0, 16); // NOSONAR: a transient name, not security.
    }

    /* ------------------------------------------------------------------
     * Plugins screen: runs in wp-admin.
     * ------------------------------------------------------------------ */

    /**
     * Register the AJAX steps (Plugin sizes, single sites).
     */
    public static function boot() {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    /**
     * Plugins screen: the box above the list, its script, and removing a
     * must-use file left by a measurement that did not finish.
     */
    public static function load_screen() {
        if (!get_transient(self::TOKEN)) {
            self::remove_file();
        }
        add_action('pre_current_active_plugins', array(__CLASS__, 'box'));
        add_action('admin_print_footer_scripts', array(__CLASS__, 'script'));
    }

    /**
     * The last measurement.
     *
     * @return array|null
     */
    public static function results() {
        static $results = false;
        if (false === $results) {
            $stored  = get_option(self::RESULTS, array());
            $results = is_array($stored) && !empty($stored['owners']) ? $stored : null;
        }
        return $results;
    }

    /**
     * The line for some plugins in the Size column: page time and queries.
     *
     * @param string[] $files Plugin files.
     * @return string
     */
    public static function line(array $files) {
        $results = self::results();
        if (null === $results) {
            return '';
        }
        $sum   = array('ms' => 0.0, 'load' => 0.0, 'mem' => 0, 'q' => 0.0, 'qms' => 0.0, 'http' => 0.0, 'hms' => 0.0);
        $found = false;
        foreach ($files as $file) {
            $key = self::plugin_key($file);
            if (!isset($results['owners'][$key])) {
                continue;
            }
            $found = true;
            foreach (array_keys($sum) as $what) {
                $sum[$what] += $results['owners'][$key][$what] ?? 0;
            }
        }
        if (!$found) {
            return '';
        }
        $text = sprintf(
            /* translators: %s: time, such as 34 ms. */
            __('Page time %s', 'seoprostack'),
            self::ms($sum['ms'])
        );
        if ($sum['q'] >= 0.5) {
            $text .= ' · ' . sprintf(
                /* translators: %s: number of database queries. */
                _n('%s query', '%s queries', (int) round($sum['q']), 'seoprostack'),
                number_format_i18n(round($sum['q']))
            );
        }
        $details = array(
            /* translators: 1: time, such as 5 ms; 2: memory, such as 1.2 MB. */
            sprintf(__('Loading: %1$s, %2$s of memory.', 'seoprostack'), self::ms($sum['load']), (string) size_format((int) $sum['mem'], 1)),
            /* translators: %s: time, such as 29 ms. */
            sprintf(__('Hooks and shortcodes: %s.', 'seoprostack'), self::ms(max(0, $sum['ms'] - $sum['load']))),
        );
        if ($sum['q'] >= 0.5) {
            /* translators: 1: number of database queries; 2: time, such as 3 ms. */
            $details[] = sprintf(__('Database queries: %1$s, taking %2$s (part of the time above).', 'seoprostack'), number_format_i18n(round($sum['q'])), self::ms($sum['qms']));
        }
        if ($sum['http'] >= 0.5) {
            /* translators: 1: number of calls; 2: time, such as 120 ms. */
            $details[] = sprintf(__('Calls to other sites: %1$s, taking %2$s.', 'seoprostack'), number_format_i18n(round($sum['http'])), self::ms($sum['hms']));
        }
        $details[] = __('Average for a page, as last measured with Measure page time above the list.', 'seoprostack');
        return sprintf(
            '<span class="sps-size__cost" title="%1$s">%2$s</span>',
            esc_attr(implode("\n", $details)),
            esc_html($text)
        );
    }

    /**
     * Box above the Plugins list: the last measurement and the button.
     */
    public static function box() {
        $results = self::results();
        echo '<div class="sps-cost" id="sps-cost"><p>';
        if (null === $results) {
            echo '<strong>' . esc_html__('Page time per plugin', 'seoprostack') . '</strong> ';
            esc_html_e('See how much time and how many database queries each active plugin adds to your pages. This loads the home page and the newest post a few times each, with every active plugin loaded, and takes up to a minute.', 'seoprostack');
            $label = __('Measure page time', 'seoprostack');
        } else {
            $groups = self::groups($results['owners']);
            $parts  = array();
            $names  = array(
                /* translators: %s: time, such as 260 ms. */
                'plugins' => __('plugins %s', 'seoprostack'),
                /* translators: %s: time, such as 2 ms. */
                'mu'      => __('must-use plugins %s', 'seoprostack'),
                /* translators: %s: time, such as 40 ms. */
                'theme'   => __('theme %s', 'seoprostack'),
                /* translators: %s: time, such as 120 ms. */
                'core'    => __('WordPress %s', 'seoprostack'),
                /* translators: %s: time, such as 3 ms. */
                'other'   => __('other code %s', 'seoprostack'),
            );
            foreach ($names as $group => $name) {
                if ($groups[$group] >= 0.1) {
                    $parts[] = sprintf($name, self::ms($groups[$group]));
                }
            }
            echo '<strong>' . esc_html__('Page time per plugin', 'seoprostack') . '</strong> ';
            echo esc_html(sprintf(
                /* translators: 1: time ago, such as 5 mins; 2: number of pages; 3: time, such as 420 ms; 4: number of queries; 5: list such as "plugins 260 ms, theme 40 ms". */
                _n(
                    'Measured %1$s ago on %2$s page: %3$s and %4$s database queries a page, with every active plugin loaded (%5$s). Each plugin’s share is in its Size column.',
                    'Measured %1$s ago on %2$s pages: %3$s and %4$s database queries a page, with every active plugin loaded (%5$s). Each plugin’s share is in its Size column.',
                    count($results['pages']),
                    'seoprostack'
                ),
                human_time_diff((int) $results['time']),
                number_format_i18n(count($results['pages'])),
                self::ms((float) $results['ms']),
                number_format_i18n(round((float) $results['q'])),
                implode(', ', $parts)
            ));
            if (($results['active'] ?? '') !== self::fingerprint()) {
                echo ' <em>' . esc_html__('Plugins or the theme have changed since then.', 'seoprostack') . '</em>';
            }
            $label = __('Measure again', 'seoprostack');
        }
        printf(
            ' <button type="button" class="button button-small sps-cost__measure">%1$s</button> <span class="sps-cost__status" role="status" aria-live="polite"></span>',
            esc_html($label)
        );
        echo '</p></div>';
    }

    /**
     * Time by kind of code: plugins, must-use plugins, theme, WordPress, other.
     *
     * @param array $owners Findings per owner.
     * @return array<string,float>
     */
    private static function groups(array $owners) {
        $groups = array('plugins' => 0.0, 'mu' => 0.0, 'theme' => 0.0, 'core' => 0.0, 'other' => 0.0);
        foreach ($owners as $owner => $found) {
            $owner = (string) $owner;
            if ('core' === $owner || 'other' === $owner) {
                $group = $owner;
            } elseif (0 === strpos($owner, 'mu--')) {
                $group = 'mu';
            } elseif (0 === strpos($owner, 'theme--')) {
                $group = 'theme';
            } else {
                $group = 'plugins';
            }
            $groups[$group] += (float) ($found['ms'] ?? 0);
        }
        return $groups;
    }

    /**
     * Milliseconds for people.
     *
     * @param float $ms Milliseconds.
     * @return string
     */
    private static function ms($ms) {
        if ($ms < 0.1) {
            return __('under 0.1 ms', 'seoprostack');
        }
        /* translators: %s: number of milliseconds. */
        return sprintf(__('%s ms', 'seoprostack'), number_format_i18n($ms, $ms < 10 ? 1 : 0));
    }

    /**
     * Fingerprint of the active plugins and theme, to say when a measurement is out of date.
     *
     * @return string
     */
    private static function fingerprint() {
        $active = (array) get_option('active_plugins', array());
        sort($active);
        return md5(implode(',', $active) . '|' . get_stylesheet()); // NOSONAR: a fingerprint, not security.
    }

    /**
     * One step of a measurement: start, page, finish or stop.
     */
    public static function ajax() {
        check_ajax_referer(self::AJAX, 'nonce');
        if (!current_user_can('activate_plugins') || is_multisite()) {
            wp_send_json_error(array('message' => __('You are not allowed to do that.', 'seoprostack')), 403);
        }
        $step = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : '';
        if ('start' === $step) {
            self::ajax_start();
        } elseif ('page' === $step) {
            self::ajax_page(isset($_POST['run']) ? absint($_POST['run']) : 0);
        } elseif ('finish' === $step) {
            self::ajax_finish();
        }
        self::stop();
        wp_send_json_success();
    }

    /**
     * Start: a token, the pages to measure and the must-use file.
     */
    private static function ajax_start() {
        $current = get_transient(self::TOKEN);
        if (is_array($current) && (int) ($current['user'] ?? 0) !== get_current_user_id() && time() - (int) ($current['time'] ?? 0) < 120) {
            wp_send_json_error(array('message' => __('Someone else is measuring page time now. Try again in a few minutes.', 'seoprostack')));
        }
        $pages = self::pages();
        $token = wp_generate_password(32, false, false);
        set_transient(self::TOKEN, array(
            'token' => $token,
            'pages' => $pages,
            'user'  => get_current_user_id(),
            'time'  => time(),
        ), self::LIFETIME);
        if (!self::write_file()) {
            delete_transient(self::TOKEN);
            wp_send_json_error(array('message' => __('SEO Pro Stack could not add its measuring file to the wp-content/mu-plugins folder. Make that folder writable by the web server, then try again.', 'seoprostack')));
        }
        wp_send_json_success(array('runs' => count($pages) * self::RUNS));
    }

    /**
     * Ask one page with the token and check that it was measured.
     *
     * @param int $run Run number.
     */
    private static function ajax_page($run) {
        $token = get_transient(self::TOKEN);
        if (!is_array($token) || empty($token['pages']) || $run >= count($token['pages']) * self::RUNS) {
            wp_send_json_error(array('message' => __('The measurement has expired. Try again.', 'seoprostack')));
        }
        $page    = $token['pages'][$run % count($token['pages'])];
        $headers = array('Cache-Control' => 'no-cache');
        // As Site Health's loopback test: pass on HTTP authentication.
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication, passed on unchanged, as core does.
            $headers['Authorization'] = 'Basic ' . base64_encode(wp_unslash($_SERVER['PHP_AUTH_USER']) . ':' . wp_unslash($_SERVER['PHP_AUTH_PW']));
        }
        $response = wp_remote_get(add_query_arg(self::ARG, $token['token'] . '.' . $run, $page['url']), array(
            'timeout'     => 20, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- the site's own page, only when someone clicks Measure page time; an uncached page can take seconds.
            'redirection' => 0,
            'headers'     => $headers,
            // Core's filter for requests to the site itself, as its loopback test uses.
            'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        ));
        if (is_wp_error($response)) {
            /* translators: %s: error message. */
            wp_send_json_error(array('message' => sprintf(__('Could not load the page: %s', 'seoprostack'), $response->get_error_message())));
        }
        if (!is_array(get_transient(self::run_key((string) $token['token'], $run)))) {
            $code = (int) wp_remote_retrieve_response_code($response);
            wp_send_json_error(array('message' => 200 === $code
                ? __('The page answered without being measured: a page cache or server cache may have answered it.', 'seoprostack')
                /* translators: 1: page address; 2: HTTP status code. */
                : sprintf(__('%1$s answered with status %2$s, so it could not be measured.', 'seoprostack'), $page['url'], number_format_i18n($code))));
        }
        wp_send_json_success();
    }

    /**
     * Keep the fastest run of each page, average the pages and store them.
     */
    private static function ajax_finish() {
        $token = get_transient(self::TOKEN);
        if (!is_array($token) || empty($token['pages'])) {
            wp_send_json_error(array('message' => __('The measurement has expired. Try again.', 'seoprostack')));
        }
        $count = count($token['pages']);
        $best  = array();
        for ($run = 0; $run < $count * self::RUNS; $run++) {
            $key    = self::run_key((string) $token['token'], $run);
            $result = get_transient($key);
            delete_transient($key);
            $page = $run % $count;
            if (is_array($result) && isset($result['ms'], $result['owners']) && (!isset($best[$page]) || $result['ms'] < $best[$page]['ms'])) {
                $best[$page] = $result;
            }
        }
        if (!$best) {
            self::stop();
            wp_send_json_error(array('message' => __('No page could be measured. Try again.', 'seoprostack')));
        }

        $pages  = array();
        $owners = array();
        $total  = array('ms' => 0.0, 'q' => 0.0);
        $share  = 1 / count($best);
        foreach ($best as $page => $result) {
            $pages[] = array(
                'url' => $token['pages'][$page]['url'],
                'ms'  => (float) $result['ms'],
                'q'   => (int) $result['q'],
            );
            $total['ms'] += $share * (float) $result['ms'];
            $total['q']  += $share * (int) $result['q'];
            foreach ((array) $result['owners'] as $owner => $found) {
                foreach ((array) $found as $what => $value) {
                    $owners[$owner][$what] = round(($owners[$owner][$what] ?? 0) + $share * $value, 3);
                }
            }
        }
        update_option(self::RESULTS, array(
            'time'   => time(),
            'active' => self::fingerprint(),
            'pages'  => $pages,
            'ms'     => round($total['ms'], 2),
            'q'      => round($total['q'], 1),
            'owners' => $owners,
        ), false);
        self::stop();
        wp_send_json_success();
    }

    /**
     * The home page and the newest post.
     *
     * @return array<int,array{url:string}>
     */
    private static function pages() {
        $urls  = array(home_url('/'));
        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ));
        if ($posts) {
            $link = get_permalink((int) $posts[0]);
            if ($link) {
                $urls[] = $link;
            }
        }
        $pages = array();
        foreach (array_unique($urls) as $url) {
            $pages[] = array('url' => $url);
        }
        return $pages;
    }

    /**
     * End a measurement: remove the token and the must-use file.
     */
    public static function stop() {
        delete_transient(self::TOKEN);
        self::remove_file();
    }

    /**
     * Path of the must-use file.
     *
     * @return string
     */
    private static function path() {
        return WPMU_PLUGIN_DIR . '/' . self::FILE;
    }

    /**
     * Filesystem for the must-use file.
     *
     * @return WP_Filesystem_Direct
     */
    private static function fs() {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new WP_Filesystem_Direct(null);
    }

    /**
     * Write the must-use file, unless a file of that name is someone else's.
     *
     * @return bool
     */
    private static function write_file() {
        $fs   = self::fs();
        $path = self::path();
        if (is_file($path) && false === strpos((string) $fs->get_contents($path), self::MARKER)) {
            return false;
        }
        $class = wp_normalize_path(__FILE__);
        $code  = "<?php\n// " . self::MARKER . ": SEO Pro Stack adds this file while it measures each plugin's page time\n"
            . "// (Plugins screen) and deletes it afterwards. It does nothing on other requests.\n"
            . 'if (defined(\'ABSPATH\') && isset($_GET[' . var_export(self::ARG, true) . ']) && is_file(' . var_export($class, true) . ")) {\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . '    require_once ' . var_export($class, true) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . "    SEOProStack_Plugin_Cost::start();\n}\n";
        return wp_mkdir_p(WPMU_PLUGIN_DIR) && $fs->put_contents($path, $code, 0644);
    }

    /**
     * Remove the must-use file, if it is ours.
     */
    private static function remove_file() {
        $path = self::path();
        if (!is_file($path)) {
            return;
        }
        $fs = self::fs();
        if (false !== strpos((string) $fs->get_contents($path), self::MARKER)) {
            $fs->delete($path);
        }
    }

    /**
     * The button's script.
     */
    public static function script() {
        $data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'nonce'   => wp_create_nonce(self::AJAX),
            'i18n'    => array(
                'starting' => __('Starting…', 'seoprostack'),
                /* translators: 1: page run number; 2: number of page runs. */
                'progress' => __('Measuring page %1$s of %2$s…', 'seoprostack'),
                'saving'   => __('Saving…', 'seoprostack'),
                'failed'   => __('Could not measure page time. Try again.', 'seoprostack'),
            ),
        );
        ?>
        <script>
        (function ($, cfg) {
            var $box = $('#sps-cost');
            if (!$box.length) { return; }
            var $button = $box.find('.sps-cost__measure');
            var $status = $box.find('.sps-cost__status');
            function post(data) {
                return $.post(cfg.ajaxUrl, $.extend({ action: cfg.action, nonce: cfg.nonce }, data));
            }
            function fail(response) {
                var json = response && response.responseJSON ? response.responseJSON : response;
                $status.text((json && json.data && json.data.message) || cfg.i18n.failed);
                $button.prop('disabled', false);
                post({ step: 'stop' });
            }
            function step(data, next) {
                post(data).done(function (response) {
                    if (response && response.success) { next(response.data || {}); } else { fail(response); }
                }).fail(fail);
            }
            function run(i, total) {
                if (i >= total) {
                    $status.text(cfg.i18n.saving);
                    step({ step: 'finish' }, function () { window.location.reload(); });
                    return;
                }
                $status.text(cfg.i18n.progress.replace('%1$s', i + 1).replace('%2$s', total));
                step({ step: 'page', run: i }, function () { run(i + 1, total); });
            }
            $button.on('click', function () {
                $button.prop('disabled', true);
                $status.text(cfg.i18n.starting);
                step({ step: 'start' }, function (data) { run(0, parseInt(data.runs, 10) || 0); });
            });
        })(jQuery, <?php echo wp_json_encode($data); ?>);
        </script>
        <?php
    }
}

/**
 * A hook callback wrapped so its time counts for its owner.
 */
final class SEOProStack_Plugin_Cost_Call {

    /**
     * The callback.
     *
     * @var callable
     */
    private $callback;

    /**
     * Its owner.
     *
     * @var string
     */
    private $owner;

    /**
     * Wrap a callback.
     *
     * @param callable $callback Callback.
     * @param string   $owner    Owner.
     */
    public function __construct($callback, $owner) {
        $this->callback = $callback;
        $this->owner    = $owner;
    }

    /**
     * Run the callback, timing it.
     *
     * @param mixed ...$args Arguments.
     * @return mixed
     */
    public function __invoke(...$args) {
        SEOProStack_Plugin_Cost::push($this->owner);
        try {
            return call_user_func_array($this->callback, $args);
        } finally {
            SEOProStack_Plugin_Cost::pop();
        }
    }
}
