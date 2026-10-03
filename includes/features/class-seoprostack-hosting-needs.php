<?php
/**
 * Hosting needs: does the server fit the site?
 *
 * Compares what the site uses with what the server allows, from numbers PHP
 * reports rather than rules of thumb:
 *
 * - OPcache keeps compiled PHP in memory. Its memory, file and string limits
 *   are checked against how full it is and against the PHP code that can
 *   load (WordPress, the theme, must-use and active plugins, drop-ins).
 *   Plugin code comes from the Plugin sizes cache, measured on demand.
 * - PHP memory: each request records its peak at shutdown, per day and per
 *   kind of request, when it beats that day's peak (a few writes a day, to
 *   one small autoloaded option). The highest of the last 7 days is
 *   compared with the limit that request ran under.
 *
 * - Traffic: 1 in 20 requests that reach PHP records how long it took and
 *   the hour it ran in. Pages served from a page cache never reach PHP, so
 *   this counts exactly the requests that need PHP workers.
 * - The site: database size, autoloaded options, object and page cache,
 *   and whether it is a shop or membership site (more visitors skip the
 *   page cache).
 *
 * From those, it suggests settings to ask the host for, and plans to buy
 * for now and for low, medium and high traffic: PHP workers, RAM, CPU
 * cores, OPcache, PHP memory limit and object cache
 * (SEOProStack_Hosting_Plans).
 *
 * Shown in a row below the plugin list (filled in by AJAX, so the screen
 * opens straight away) and in Tools → Site Health: a test for each, and a
 * Hosting needs section on the Info tab that can be copied for a host.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-hosting-plans.php';

class SEOProStack_Hosting_Needs extends SEOProStack_Feature {

    const KEY = 'hosting_needs';

    /** Autoloaded option: Y-m-d => kind => array(peak bytes, limit bytes). */
    const MEMORY = 'seoprostack_hosting_memory';

    /** Option: PHP code of WordPress, the theme, must-use plugins and drop-ins. */
    const CODE = 'seoprostack_hosting_code';

    /** Option: Y-m-d => rate, hours (requests per hour) and kinds (samples, seconds, buckets, slowest). */
    const TRAFFIC = 'seoprostack_hosting_traffic';

    /** Transient: database size, autoloaded options and products, read at most hourly. */
    const FACTS = 'seoprostack_hosting_facts';

    /** One request in this many records its time (filter seoprostack_hosting_sample_rate). */
    const SAMPLE = 20;

    /** Upper bounds of the time buckets, in seconds; one more bucket holds the slower ones. */
    const BUCKETS = array(0.1, 0.25, 0.5, 1, 2, 4, 8);

    /** Samples needed before measured times replace typical ones. */
    const ENOUGH = 30;

    /** Days of memory peaks and traffic kept. */
    const DAYS = 7;

    /** AJAX action for the row on the Plugins screen. */
    const AJAX = 'seoprostack_hosting_needs';

    /** Site Health test ID; core posts it to health-check-{ID}. */
    const TEST = 'seoprostack-opcache';

    /** Memory a PHP worker uses besides the request itself, for planning. */
    const WORKER_BASE = 32;

    /** Share of a limit above which more is suggested. */
    const NEAR = 0.8;

    /** Values offered for opcache.memory_consumption, in MB. */
    const MEMORY_STEPS = array(128, 192, 256, 384, 512, 768, 1024, 1536, 2048);

    /** Values offered for opcache.interned_strings_buffer, in MB. */
    const STRING_STEPS = array(16, 32, 64, 128, 256);

    /** Values offered for memory_limit, in MB. */
    const LIMIT_STEPS = array(128, 256, 384, 512, 768, 1024, 2048);

    /** Values offered for opcache.max_accelerated_files. */
    const FILE_STEPS = array(10000, 20000, 30000, 50000, 100000, 200000, 500000);

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
                'tab'         => 'plugins',
                'label'       => __('Hosting needs', 'seoprostack'),
                'description' => __('Check whether your hosting fits this site, and what to ask your host for. Compares OPcache, which keeps PHP code in memory, with the code your plugins and theme can load, and the PHP memory limit with the most memory a request used in the last 7 days. Shown below the plugin list and in Tools → Site Health.', 'seoprostack'),
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
        add_action('shutdown', array(__CLASS__, 'record'), PHP_INT_MAX);
        // Site Health also runs its tests from cron, outside wp-admin.
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        add_filter('debug_information', array(__CLASS__, 'info'));
        // Plugins change autoloaded options and tables.
        add_action('activated_plugin', array(__CLASS__, 'forget_facts'));
        add_action('deactivated_plugin', array(__CLASS__, 'forget_facts'));
        if (!is_admin()) {
            return;
        }
        add_action('wp_ajax_health-check-' . self::TEST, array(__CLASS__, 'ajax_test'));
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax_row'));
        add_action('load-plugins.php', array(__CLASS__, 'load_screen'));
    }

    /* ------------------------------------------------------------------
     * Memory peaks
     * ------------------------------------------------------------------ */

    /**
     * Record this request: its memory peak, and its time for 1 in 20.
     */
    public static function record() {
        if ((defined('WP_CLI') && WP_CLI) || wp_installing()) {
            return; // The command line has its own limit, often none, and no visitors.
        }
        $kind = self::kind();
        self::record_peak($kind);
        self::sample($kind);
    }

    /**
     * Record this request's memory peak if it is the highest today for its
     * kind of request.
     *
     * @param string $kind Kind of request.
     */
    private static function record_peak($kind) {
        $peak  = (int) memory_get_peak_usage(true); // What the limit is checked against.
        $day   = gmdate('Y-m-d');
        $peaks = get_option(self::MEMORY, array());
        $peaks = is_array($peaks) ? $peaks : array();
        if (isset($peaks[$day][$kind][0]) && $peak <= (int) $peaks[$day][$kind][0]) {
            return;
        }
        $peaks[$day][$kind] = array($peak, (int) wp_convert_hr_to_bytes((string) ini_get('memory_limit')));
        krsort($peaks);
        update_option(self::MEMORY, array_slice($peaks, 0, self::DAYS, true), true);
    }

    /**
     * For 1 in 20 requests, record how long it took and the hour it ran in.
     * Other requests do nothing; the option is not autoloaded, so only the
     * sampled requests read it.
     *
     * @param string $kind Kind of request.
     */
    private static function sample($kind) {
        $rate = (int) apply_filters('seoprostack_hosting_sample_rate', self::SAMPLE);
        if ($rate < 1 || 1 !== wp_rand(1, $rate) || empty($_SERVER['REQUEST_TIME_FLOAT'])) {
            return;
        }
        // Until now, which is when the PHP worker is free for the next request.
        $seconds = max(0, microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']);
        $day     = gmdate('Y-m-d');
        $traffic = get_option(self::TRAFFIC, array());
        $traffic = is_array($traffic) ? $traffic : array();
        if (!isset($traffic[$day]['hours'], $traffic[$day]['kinds'])) {
            $traffic[$day] = array('hours' => array_fill(0, 24, 0), 'kinds' => array());
        }
        $traffic[$day]['hours'][(int) gmdate('G')] += $rate; // Requests this sample stands for.
        if (!isset($traffic[$day]['kinds'][$kind])) {
            $traffic[$day]['kinds'][$kind] = array('n' => 0, 'sum' => 0.0, 'max' => 0.0, 'b' => array_fill(0, count(self::BUCKETS) + 1, 0));
        }
        $slot = count(self::BUCKETS);
        foreach (self::BUCKETS as $i => $bound) {
            if ($seconds <= $bound) {
                $slot = $i;
                break;
            }
        }
        $stats = &$traffic[$day]['kinds'][$kind];
        $stats['n']++;
        $stats['sum'] += $seconds;
        $stats['max']  = max((float) $stats['max'], $seconds);
        $stats['b'][$slot]++;
        unset($stats);
        krsort($traffic);
        update_option(self::TRAFFIC, array_slice($traffic, 0, self::DAYS, true), false);
    }

    /**
     * Traffic that reached PHP in the last 7 days.
     *
     * @return array samples, days, per_day (requests a day), peak_hour (requests in the busiest
     *               hour), p95 (seconds, all kinds), site_p95 (seconds, pages; null below 30
     *               samples) and kinds (kind => n, avg, p95).
     */
    public static function traffic() {
        $stored = get_option(self::TRAFFIC, array());
        $since  = gmdate('Y-m-d', time() - (self::DAYS - 1) * DAY_IN_SECONDS);
        $zero   = array('n' => 0, 'sum' => 0.0, 'max' => 0.0, 'b' => array_fill(0, count(self::BUCKETS) + 1, 0));
        $all    = $zero;
        $kinds  = array();
        $days   = 0;
        $total  = 0;
        $peak   = 0;
        foreach (is_array($stored) ? $stored : array() as $day => $data) {
            if ((string) $day < $since || !isset($data['hours'], $data['kinds']) || !is_array($data['hours']) || !is_array($data['kinds'])) {
                continue;
            }
            $days++;
            $total += array_sum($data['hours']);
            $peak   = max($peak, (int) max($data['hours']));
            foreach ($data['kinds'] as $kind => $stats) {
                if (!isset($stats['n'], $stats['sum'], $stats['max'], $stats['b']) || !is_array($stats['b'])) {
                    continue;
                }
                $kinds[$kind] = self::merge(isset($kinds[$kind]) ? $kinds[$kind] : $zero, $stats);
                $all          = self::merge($all, $stats);
            }
        }
        $summary = array();
        foreach (array_intersect_key(self::kinds(), $kinds) as $kind => $label) {
            $summary[$kind] = array(
                'n'   => $kinds[$kind]['n'],
                'avg' => $kinds[$kind]['n'] ? $kinds[$kind]['sum'] / $kinds[$kind]['n'] : 0,
                'p95' => self::p95($kinds[$kind]),
            );
        }
        return array(
            'samples'   => $all['n'],
            'days'      => $days,
            'per_day'   => $days ? (int) round($total / $days) : 0,
            'peak_hour' => $peak,
            'p95'       => self::p95($all),
            'site_p95'  => isset($kinds['site']) && $kinds['site']['n'] >= self::ENOUGH ? self::p95($kinds['site']) : null,
            'kinds'     => $summary,
        );
    }

    /**
     * Add one day's timings to a total.
     *
     * @param array $into  Total: n, sum, max and b.
     * @param array $stats One day's timings, the same shape.
     * @return array
     */
    private static function merge(array $into, array $stats) {
        $into['n']   += (int) $stats['n'];
        $into['sum'] += (float) $stats['sum'];
        $into['max']  = max($into['max'], (float) $stats['max']);
        foreach ($stats['b'] as $i => $count) {
            if (isset($into['b'][$i])) {
                $into['b'][$i] += (int) $count;
            }
        }
        return $into;
    }

    /**
     * Time that 95% of requests took no longer than: the top of the bucket
     * holding the 95th percentile, or the slowest request if sooner.
     *
     * @param array $stats n, max and b (bucket counts).
     * @return float Seconds.
     */
    private static function p95(array $stats) {
        if (!$stats['n']) {
            return 0.0;
        }
        $bounds = self::BUCKETS;
        $want   = 0.95 * $stats['n'];
        $seen   = 0;
        foreach ($stats['b'] as $i => $count) {
            $seen += $count;
            if ($seen >= $want) {
                return isset($bounds[$i]) ? min((float) $bounds[$i], (float) $stats['max']) : (float) $stats['max'];
            }
        }
        return (float) $stats['max'];
    }

    /**
     * Kind of request: site (pages), admin (screens and AJAX) or other
     * (REST API, cron, XML-RPC).
     *
     * @return string
     */
    private static function kind() {
        if (wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return 'other';
        }
        return is_admin() ? 'admin' : 'site';
    }

    /**
     * Names of the kinds of request.
     *
     * @return array<string,string>
     */
    private static function kinds() {
        return array(
            'site'  => __('Pages', 'seoprostack'),
            'admin' => __('Admin', 'seoprostack'),
            'other' => __('REST API and cron', 'seoprostack'),
        );
    }

    /**
     * Highest peak of the last 7 days for each kind of request, with the
     * limit that request ran under.
     *
     * @return array<string,array> kind => array(peak, limit)
     */
    public static function peaks() {
        $stored = get_option(self::MEMORY, array());
        $since  = gmdate('Y-m-d', time() - (self::DAYS - 1) * DAY_IN_SECONDS);
        $peaks  = array();
        foreach (is_array($stored) ? $stored : array() as $day => $kinds) {
            if ((string) $day < $since || !is_array($kinds)) {
                continue;
            }
            foreach ($kinds as $kind => $pair) {
                if (is_array($pair) && isset($pair[0], $pair[1]) && (!isset($peaks[$kind]) || $pair[0] > $peaks[$kind][0])) {
                    $peaks[$kind] = array((int) $pair[0], (int) $pair[1]);
                }
            }
        }
        return array_intersect_key(array_merge(self::kinds(), $peaks), $peaks); // Display order.
    }

    /* ------------------------------------------------------------------
     * PHP code that can load
     * ------------------------------------------------------------------ */

    /**
     * PHP code that can load: WordPress, the theme, must-use plugins,
     * drop-ins and active plugins. Not all of it loads on every request, so
     * this is an upper limit.
     *
     * @param float $budget Seconds to spend measuring unmeasured plugins.
     * @return array bytes, files and missing (active plugins not measured yet), and
     *               installed: the same if every installed plugin were active.
     */
    public static function code($budget) {
        $theme  = wp_get_theme();
        $parent = $theme->parent();
        $print  = implode('|', array(
            get_bloginfo('version'),
            get_stylesheet(),
            $theme->get('Version'),
            $parent ? $parent->get('Version') : '',
            is_dir(WPMU_PLUGIN_DIR) ? (int) filemtime(WPMU_PLUGIN_DIR) : 0,
            (int) filemtime(WP_CONTENT_DIR), // Drop-ins added or removed.
        ));
        $base = get_option(self::CODE);
        if (!is_array($base) || !isset($base['print'], $base['bytes'], $base['files']) || $base['print'] !== $print) {
            $base = array('print' => $print, 'bytes' => 0, 'files' => 0);
            $dirs = array(
                array(ABSPATH, false),
                array(ABSPATH . 'wp-admin', true),
                array(ABSPATH . WPINC, true),
                array(get_stylesheet_directory(), true),
                array(WPMU_PLUGIN_DIR, true),
                array(WP_CONTENT_DIR, false),
            );
            if (get_template_directory() !== get_stylesheet_directory()) {
                $dirs[] = array(get_template_directory(), true);
            }
            foreach ($dirs as $dir) {
                list($bytes, $files) = self::scan($dir[0], $dir[1]);
                $base['bytes']      += $bytes;
                $base['files']      += $files;
            }
            update_option(self::CODE, $base, false);
        }

        $code              = array('bytes' => (int) $base['bytes'], 'files' => (int) $base['files'], 'missing' => 0);
        $code['installed'] = $code;
        if (class_exists('SEOProStack_Plugin_Sizes')) {
            $plugins          = SEOProStack_Plugin_Sizes::php_code(self::active_files(), $budget);
            $code['bytes']   += $plugins['bytes'];
            $code['files']   += $plugins['files'];
            $code['missing']  = $plugins['missing'];
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            // Measured by the Plugins screen; not measured here.
            $all                           = SEOProStack_Plugin_Sizes::php_code(array_keys(get_plugins()), 0);
            $code['installed']['bytes']   += $all['bytes'];
            $code['installed']['files']   += $all['files'];
            $code['installed']['missing']  = $all['missing'];
        }
        return $code;
    }

    /**
     * Active plugin files, including network-activated and single-file
     * plugins (self::active_plugins() leaves those out).
     *
     * @return string[] Plugin files.
     */
    private static function active_files() {
        $active = SEOProStack_Plugin_Loader::stored_active_plugins();
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        return array_values(array_unique(array_map('strval', $active)));
    }

    /* ------------------------------------------------------------------
     * The site
     * ------------------------------------------------------------------ */

    /**
     * Facts about the site that change slowly: database size, postmeta rows,
     * autoloaded options and products. Read at most hourly, from table
     * statistics rather than counting rows.
     *
     * @return array db (bytes), postmeta (rows, estimated), autoload (bytes), autoload_count, products.
     */
    public static function facts() {
        $facts = get_transient(self::FACTS);
        if (is_array($facts) && isset($facts['db'], $facts['postmeta'], $facts['autoload'], $facts['autoload_count'], $facts['products'])) {
            return $facts;
        }
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- Statistics, cached in a transient.
        $db       = $wpdb->get_var($wpdb->prepare(
            'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
            $wpdb->dbname,
            $wpdb->esc_like($wpdb->base_prefix) . '%'
        ));
        $postmeta = $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            $wpdb->dbname,
            $wpdb->postmeta
        ));
        // 'on' and 'auto-on' since WordPress 6.6; 'auto' loads unless the options are too big.
        $autoload = $wpdb->get_row(
            "SELECT COUNT(*) AS n, SUM(LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
        $products = post_type_exists('product') ? wp_count_posts('product') : null;
        $facts    = array(
            'db'             => (int) $db,
            'postmeta'       => (int) $postmeta,
            'autoload'       => isset($autoload['bytes']) ? (int) $autoload['bytes'] : 0,
            'autoload_count' => isset($autoload['n']) ? (int) $autoload['n'] : 0,
            'products'       => $products && isset($products->publish) ? (int) $products->publish : 0,
        );
        set_transient(self::FACTS, $facts, HOUR_IN_SECONDS);
        return $facts;
    }

    /**
     * Read the facts again next time.
     */
    public static function forget_facts() {
        delete_transient(self::FACTS);
    }

    /**
     * Whether a page cache serves pages before WordPress loads: an
     * advanced-cache.php drop-in, LiteSpeed's server cache, or a page cache
     * plugin. Caches run by the host outside the site cannot be seen.
     *
     * @return bool
     */
    private static function page_cache() {
        if (defined('WP_CACHE') && WP_CACHE && file_exists(WP_CONTENT_DIR . '/advanced-cache.php')) {
            return true;
        }
        $known = array(
            'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache',
            'cache-enabler', 'breeze', 'sg-cachepress', 'wp-optimize', 'nitropack', 'wp-cloudflare-page-cache',
            'swift-performance-lite', 'comet-cache', 'hummingbird-performance', 'flying-press', 'powered-cache',
        );
        // LiteSpeed Cache's page cache is kept by the LiteSpeed server.
        if (SEOProStack_Litespeed::is_server()) {
            $known[] = 'litespeed-cache';
        }
        return (bool) array_intersect($known, self::active_slugs());
    }

    /**
     * Active plugins that make a shop, membership, course or community
     * site: more visitors are logged in or have a cart, so the page cache
     * serves fewer pages, and those pages take longer.
     *
     * @return string[] Plugin names.
     */
    private static function dynamic() {
        $known = array(
            'woocommerce', 'easy-digital-downloads', 'surecart', 'memberpress', 'paid-memberships-pro',
            'restrict-content', 'ultimate-member', 'sfwd-lms', 'lifterlms', 'tutor', 'buddypress', 'bbpress',
            'buddyboss-platform', 'wp-job-manager', 'give',
        );
        $found = array_intersect_key(self::active_plugins(), array_flip($known));
        if (!$found) {
            return array();
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $names   = array();
        foreach ($found as $slug => $file) {
            $names[] = isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $slug;
        }
        return $names;
    }

    /**
     * Folder names of the active plugins.
     *
     * @return string[]
     */
    private static function active_slugs() {
        return array_keys(self::active_plugins());
    }

    /**
     * PHP files in a folder.
     *
     * @param string $dir       Folder.
     * @param bool   $recursive Include subfolders.
     * @return int[] Bytes and number of files.
     */
    private static function scan($dir, $recursive) {
        $bytes = 0;
        $files = 0;
        if (!is_dir($dir)) {
            return array(0, 0);
        }
        try {
            $items = $recursive
                ? new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                )
                : new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
            foreach ($items as $item) {
                if ($item->isFile() && !$item->isLink() && in_array(strtolower($item->getExtension()), array('php', 'phtml', 'inc'), true)) {
                    $bytes += (int) $item->getSize();
                    $files++;
                }
            }
        } catch (Exception $e) {
            unset($e); // Unreadable folder: count what was read.
        }
        return array($bytes, $files);
    }

    /* ------------------------------------------------------------------
     * Assessment
     * ------------------------------------------------------------------ */

    /**
     * What the site needs from its hosting.
     *
     * Each part has status (good, recommended or unknown), summary (one
     * sentence), ask (PHP setting => value to ask the host for) and fields
     * (label => value, for Site Health Info).
     *
     * @param float $budget Seconds to spend measuring plugin code.
     * @return array code, opcache, memory, worker (bytes per PHP worker, or 0), traffic, facts,
     *               dynamic (plugin names), page_cache, measured (time per request), seconds
     *               (per request, as the plans use it), plans and
     *               advice (list of status => sentence).
     */
    public static function assess($budget) {
        $code    = self::code($budget);
        $opcache = self::assess_opcache($code);
        $memory  = self::assess_memory();
        $peak    = 0;
        foreach (self::peaks() as $pair) {
            $peak = max($peak, $pair[0]);
        }
        $worker     = $peak ? (int) (ceil(($peak / MB_IN_BYTES + self::WORKER_BASE) / 16) * 16 * MB_IN_BYTES) : 0;
        $traffic    = self::traffic();
        $facts      = self::facts();
        $dynamic    = self::dynamic();
        $page_cache = self::page_cache();
        $enough     = $traffic['samples'] >= self::ENOUGH;
        // Visitors' pages only, once enough are sampled. Admin screens, imports
        // and cron take far longer and say nothing about visitor traffic.
        $seconds = $traffic['site_p95'];
        $site    = array(
            'seconds'      => null !== $seconds ? max(0.05, $seconds) : ($dynamic ? SEOProStack_Hosting_Plans::SECONDS_DYNAMIC : SEOProStack_Hosting_Plans::SECONDS),
            'worker'       => $worker,
            'opcache'      => $opcache['need']['memory'],
            'db'           => $facts['db'],
            'object_cache' => self::big_data($facts),
            'page_cache'   => $page_cache,
            'dynamic'      => (bool) $dynamic,
        );
        // The busiest hour measured, with bursts within it.
        $now_rps = $enough ? $traffic['peak_hour'] / HOUR_IN_SECONDS * SEOProStack_Hosting_Plans::BURST : null;
        $needs   = array(
            'code'       => $code,
            'opcache'    => $opcache,
            'memory'     => $memory,
            'worker'     => $worker,
            'traffic'    => $traffic,
            'facts'      => $facts,
            'dynamic'    => $dynamic,
            'page_cache' => $page_cache,
            'measured'   => null !== $seconds,
            'seconds'    => $site['seconds'],
            'plans'      => SEOProStack_Hosting_Plans::plans($site, $now_rps),
        );
        $needs['advice'] = self::advice($needs);
        return $needs;
    }

    /**
     * On a LiteSpeed server: use LiteSpeed Cache, and not WP-Optimize's
     * page cache and minify alongside it.
     *
     * @return array[] Each status and text.
     */
    private static function litespeed_advice() {
        if (!SEOProStack_Litespeed::is_server()) {
            return array();
        }
        $active = self::active_slugs();
        if (!in_array('litespeed-cache', $active, true)) {
            return array(array('recommended', __('This site runs on a LiteSpeed server, whose own page cache is the fastest one here. Install LiteSpeed Cache to use it, then choose Apply preset for it on the Plugins screen.', 'seoprostack')));
        }
        if (!in_array('wp-optimize', $active, true) || !SEOProStack_Litespeed::wp_optimize_overlap()) {
            return array();
        }
        return array(array('recommended', __('WP-Optimize’s page cache or minify runs alongside LiteSpeed Cache, so pages are cached or minified twice. Turn them off in WP-Optimize: LiteSpeed Cache and SEO Pro Stack’s speed features do those jobs on LiteSpeed servers.', 'seoprostack')));
    }

    /**
     * Enough data that a persistent object cache helps at any traffic.
     * Thresholds from Super Speedy Performance Analysis.
     *
     * @param array $facts From facts().
     * @return bool
     */
    private static function big_data(array $facts) {
        return $facts['postmeta'] > 500000 || $facts['products'] > 10000;
    }

    /**
     * The time per page the plans use, as a sentence.
     *
     * @param bool  $measured Measured from enough pages.
     * @param float $seconds  Seconds per page.
     * @param int   $pages    Pages timed so far.
     * @return string
     */
    private static function page_time_text($measured, $seconds, $pages) {
        if ($measured) {
            return sprintf(
                /* translators: %s: seconds. */
                __('95%% of pages took under %s seconds.', 'seoprostack'),
                number_format_i18n($seconds, 2)
            );
        }
        return sprintf(
            /* translators: 1: seconds, 2: pages needed, 3: pages timed so far. */
            __('The plans take %1$s seconds a page, typical for this kind of site, until %2$s pages are timed (%3$s so far); admin screens and cron are not counted.', 'seoprostack'),
            number_format_i18n($seconds, 1),
            number_format_i18n(self::ENOUGH),
            number_format_i18n($pages)
        );
    }

    /**
     * Advice beyond OPcache and memory: page cache, object cache,
     * autoloaded options, PHP version and traffic now.
     *
     * @param array $needs From assess(), without advice.
     * @return array[] Each status (recommended or info) and text.
     */
    private static function advice(array $needs) {
        $advice  = array();
        $traffic = $needs['traffic'];
        $pages   = isset($traffic['kinds']['site']['n']) ? (int) $traffic['kinds']['site']['n'] : 0;
        if (isset($needs['plans']['now'])) {
            $now      = $needs['plans']['now'];
            $advice[] = array('info', sprintf(
                /* translators: 1: requests, 2: number of PHP workers. */
                _n(
                    'Traffic now: about %1$s requests reached PHP in the busiest hour of the last 7 days. With bursts, this site needs %2$s PHP worker.',
                    'Traffic now: about %1$s requests reached PHP in the busiest hour of the last 7 days. With bursts, this site needs %2$s PHP workers.',
                    $now['workers'],
                    'seoprostack'
                ),
                number_format_i18n($traffic['peak_hour']),
                number_format_i18n($now['workers'])
            ) . ' ' . self::page_time_text($needs['measured'], $needs['seconds'], $pages));
        } else {
            $advice[] = array('info', sprintf(
                /* translators: 1: requests sampled so far, 2: requests needed. */
                __('Traffic is measured from now on: 1 in 20 requests that reach PHP records its time and hour. The Now plan appears once %2$s are recorded (%1$s so far).', 'seoprostack'),
                number_format_i18n($traffic['samples']),
                number_format_i18n(self::ENOUGH)
            ) . ' ' . self::page_time_text($needs['measured'], $needs['seconds'], $pages));
        }
        if (!$needs['page_cache']) {
            $advice[] = array('recommended', __('No page cache was found, so every page view runs PHP. A page cache, from your host or a plugin, serves most pages without PHP and cuts the PHP workers you need. If your host caches pages itself, ignore this.', 'seoprostack'));
        }
        $advice = array_merge($advice, self::litespeed_advice());
        if (!wp_using_ext_object_cache() && self::big_data($needs['facts'])) {
            $advice[] = array('recommended', sprintf(
                /* translators: 1: postmeta rows, 2: products. */
                __('This site has about %1$s rows of post data and %2$s products, so a persistent object cache (Redis or Memcached) would save database work on every request. Ask your host for one, with its object cache plugin.', 'seoprostack'),
                number_format_i18n($needs['facts']['postmeta']),
                number_format_i18n($needs['facts']['products'])
            ));
        }
        if ($needs['facts']['autoload'] > MB_IN_BYTES) {
            $advice[] = array('recommended', sprintf(
                /* translators: 1: size, 2: number of options. */
                __('Options loaded on every request total %1$s (%2$s options). Above 1 MB this slows every request that reaches PHP; plugins you removed often leave large ones behind.', 'seoprostack'),
                self::size($needs['facts']['autoload']),
                number_format_i18n($needs['facts']['autoload_count'])
            ));
            $largest = array();
            foreach (SEOProStack_Autoload_Options::largest() as $option) {
                $largest[] = $option['option_name'] . ' (' . self::size($option['bytes']) . ')';
            }
            $advice[] = array('recommended', sprintf(
                /* translators: %s: three option names and sizes. */
                __('Largest settings: %s. “Load large settings only where they are used” on the Speed tab can learn which to stop loading on site pages. Turn it off to undo its changes.', 'seoprostack'),
                implode(', ', $largest)
            ));
        }
        if (version_compare(PHP_VERSION, '8.2', '<')) {
            $advice[] = array('recommended', sprintf(
                /* translators: %s: PHP version. */
                __('PHP %s is slower than current versions and no longer gets all fixes. Ask your host for PHP 8.3 or later, once your plugins and theme support it.', 'seoprostack'),
                PHP_VERSION
            ));
        }
        return $advice;
    }

    /**
     * OPcache against the code that can load.
     *
     * @param array $code From code().
     * @return array
     */
    private static function assess_opcache(array $code) {
        $enabled = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        $status  = null;
        if ($enabled && function_exists('opcache_get_status')) {
            // False when opcache.restrict_api keeps this script out. With
            // scripts, for how much memory compiled code takes here.
            $status = @opcache_get_status(true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if (is_array($status) && empty($status['opcache_enabled'])) {
                $enabled = false;
            }
            $status = is_array($status) ? $status : null;
        }
        $need   = self::need($code, $status);
        $fields = array(
            'php_code' => array(
                'label' => __('PHP code that can load', 'seoprostack'),
                'value' => self::code_text($code),
            ),
            'php_code_installed' => array(
                'label' => __('PHP code if every installed plugin were active', 'seoprostack'),
                'value' => self::code_text($code['installed']),
            ),
            'opcache' => array(
                'label' => __('OPcache', 'seoprostack'),
                'value' => $enabled ? __('On', 'seoprostack') : __('Off', 'seoprostack'),
            ),
            'opcache_need' => array(
                'label' => __('OPcache for all the code that can load', 'seoprostack'),
                'value' => self::ask_text(self::need_ask($need)) . ' (' . ($need['measured']
                    /* translators: %s: ratio, such as 2.6. */
                    ? sprintf(__('compiled code is %s times its size on disk here', 'seoprostack'), number_format_i18n($need['ratio'], 1))
                    /* translators: %s: ratio, such as 2.6. */
                    : sprintf(__('assuming compiled code is %s times its size on disk', 'seoprostack'), number_format_i18n($need['ratio'], 1))) . ')',
            ),
            'opcache_need_installed' => array(
                'label' => __('OPcache if every installed plugin were active', 'seoprostack'),
                'value' => self::ask_text(self::need_ask($need['installed'])),
            ),
        );
        if (!$enabled) {
            return array(
                'status'  => 'recommended',
                'summary' => __('OPcache is off, so PHP compiles the site’s code again on every request. Ask your host to turn it on.', 'seoprostack'),
                'ask'     => array('opcache.enable' => '1') + self::need_ask($need),
                'fields'  => $fields,
                'off'     => true,
                'need'    => $need,
            );
        }

        $memory   = (int) ini_get('opcache.memory_consumption') * MB_IN_BYTES;
        $strings  = (int) ini_get('opcache.interned_strings_buffer') * MB_IN_BYTES;
        $max_keys = isset($status['opcache_statistics']['max_cached_keys']) ? (int) $status['opcache_statistics']['max_cached_keys'] : self::prime((int) ini_get('opcache.max_accelerated_files'));
        $ask      = array();
        $problems = array();

        // Memory.
        if ($status && isset($status['memory_usage']['free_memory'])) {
            $used     = max(0, $memory - (int) $status['memory_usage']['free_memory']);
            $restarts = isset($status['opcache_statistics']['oom_restarts']) ? (int) $status['opcache_statistics']['oom_restarts'] : 0;
            $fields['opcache_memory'] = array(
                'label' => __('OPcache memory', 'seoprostack'),
                /* translators: 1: memory used, 2: OPcache memory. */
                'value' => sprintf(__('%1$s of %2$s used', 'seoprostack'), self::size($used), self::size($memory)),
            );
            if ($restarts || $used >= self::NEAR * $memory) {
                // A full OPcache hides how much it needs, so ask for enough for
                // all the code that can load, and at least half as much again.
                $ask['opcache.memory_consumption'] = max($need['memory'], self::step(max($used * 1.5, $memory + 1) / MB_IN_BYTES, self::MEMORY_STEPS));
                $problems[] = $restarts
                    /* translators: 1: memory used, 2: OPcache memory. */
                    ? sprintf(__('its memory filled up and it had to start again (%1$s of %2$s used)', 'seoprostack'), self::size($used), self::size($memory))
                    /* translators: 1: memory used, 2: OPcache memory. */
                    : sprintf(__('its memory is nearly full (%1$s of %2$s used)', 'seoprostack'), self::size($used), self::size($memory));
            }
        } else {
            $fields['opcache_memory'] = array(
                'label' => __('OPcache memory', 'seoprostack'),
                /* translators: %s: OPcache memory. */
                'value' => sprintf(__('%s (this server does not say how much is used)', 'seoprostack'), self::size($memory)),
            );
            if ($need['memory'] * MB_IN_BYTES > $memory) {
                $ask['opcache.memory_consumption'] = $need['memory'];
                $problems[] = sprintf(
                    /* translators: 1: size of PHP code, 2: memory it needs, 3: OPcache memory. */
                    __('the site’s %1$s of PHP code needs about %2$s once compiled, more than its %3$s', 'seoprostack'),
                    self::size($code['bytes']),
                    self::size($need['memory'] * MB_IN_BYTES),
                    self::size($memory)
                );
            }
        }

        // Files.
        $cached = isset($status['opcache_statistics']['num_cached_keys']) ? (int) $status['opcache_statistics']['num_cached_keys'] : null;
        $hash   = isset($status['opcache_statistics']['hash_restarts']) ? (int) $status['opcache_statistics']['hash_restarts'] : 0;
        $fields['opcache_files'] = array(
            'label' => __('OPcache files', 'seoprostack'),
            'value' => null === $cached
                /* translators: %s: number of files. */
                ? sprintf(__('Up to %s', 'seoprostack'), number_format_i18n($max_keys))
                /* translators: 1: files cached, 2: most files. */
                : sprintf(__('%1$s of %2$s', 'seoprostack'), number_format_i18n($cached), number_format_i18n($max_keys)),
        );
        if ($max_keys && ($hash || $code['files'] > $max_keys || (null !== $cached && $cached >= self::NEAR * $max_keys))) {
            $ask['opcache.max_accelerated_files'] = max($need['files'], self::step(max($code['files'], (int) $cached, $max_keys + 1) * 1.3, self::FILE_STEPS));
            $problems[] = $code['files'] > $max_keys
                /* translators: 1: PHP files, 2: most files OPcache keeps. */
                ? sprintf(__('the site has %1$s PHP files and it keeps at most %2$s', 'seoprostack'), number_format_i18n($code['files']), number_format_i18n($max_keys))
                /* translators: 1: files cached, 2: most files OPcache keeps. */
                : sprintf(__('its file list is nearly full (%1$s of %2$s)', 'seoprostack'), number_format_i18n((int) $cached), number_format_i18n($max_keys));
        }

        // Interned strings (names and texts shared between scripts).
        if (isset($status['interned_strings_usage']['buffer_size'], $status['interned_strings_usage']['free_memory']) && $status['interned_strings_usage']['buffer_size'] > 0) {
            $buffer = (int) $status['interned_strings_usage']['buffer_size'];
            $used   = $buffer - (int) $status['interned_strings_usage']['free_memory'];
            $fields['opcache_strings'] = array(
                'label' => __('OPcache interned strings', 'seoprostack'),
                /* translators: 1: memory used, 2: buffer size. */
                'value' => sprintf(__('%1$s of %2$s used', 'seoprostack'), self::size($used), self::size($buffer)),
            );
            if ($used >= 0.9 * $buffer) {
                $ask['opcache.interned_strings_buffer'] = max($need['strings'], self::step(max($used * 1.5, $strings * 2) / MB_IN_BYTES, self::STRING_STEPS));
                $problems[] = __('its space for shared strings is nearly full', 'seoprostack');
                // The strings buffer is part of opcache.memory_consumption.
                if (!isset($ask['opcache.memory_consumption']) && $need['memory'] * MB_IN_BYTES > $memory) {
                    $ask['opcache.memory_consumption'] = $need['memory'];
                }
            }
        }

        if (isset($status['opcache_statistics']['opcache_hit_rate'])) {
            $fields['opcache_hits'] = array(
                'label' => __('OPcache hit rate', 'seoprostack'),
                'value' => number_format_i18n((float) $status['opcache_statistics']['opcache_hit_rate'], 1) . '%',
            );
        }

        if ($problems) {
            $summary = sprintf(
                /* translators: %s: list of problems. */
                __('OPcache is too small for this site: %s. When it runs out, PHP compiles code again, which slows uncached pages.', 'seoprostack'),
                implode('; ', $problems)
            );
            $state = 'recommended';
        } elseif ($status) {
            $summary = sprintf(
                /* translators: 1: memory used, 2: OPcache memory, 3: PHP files, 4: most files. */
                __('OPcache has room: %1$s of %2$s used, and the site has %3$s PHP files for up to %4$s.', 'seoprostack'),
                self::size(max(0, $memory - (int) $status['memory_usage']['free_memory'])),
                self::size($memory),
                number_format_i18n($code['files']),
                number_format_i18n($max_keys)
            );
            $state = 'good';
        } else {
            $summary = sprintf(
                /* translators: 1: OPcache memory, 2: size of PHP code, 3: PHP files, 4: most files. */
                __('OPcache is on with %1$s for the site’s %2$s of PHP code, and up to %4$s files for its %3$s. This server does not say how full it is.', 'seoprostack'),
                self::size($memory),
                self::size($code['bytes']),
                number_format_i18n($code['files']),
                number_format_i18n($max_keys)
            );
            $state = 'unknown';
        }
        if ($code['missing']) {
            $summary .= ' ' . sprintf(
                /* translators: %s: number of plugins. */
                _n('%s active plugin is not measured yet.', '%s active plugins are not measured yet.', $code['missing'], 'seoprostack'),
                number_format_i18n($code['missing'])
            );
        }
        return array(
            'status'  => $state,
            'summary' => $summary,
            'ask'     => $ask,
            'fields'  => $fields,
            'private' => !$status,
            'need'    => $need,
        );
    }

    /**
     * OPcache settings that hold all the code that can load, with room.
     *
     * Compiled code takes more memory than its source: measured here from
     * OPcache's own list of scripts when it is readable (2.6 times on a
     * WooCommerce test site), and assumed otherwise. Shared strings take
     * about 0.7 times the source in the same test, less as more files share
     * them. The strings buffer is part of opcache.memory_consumption, which
     * also needs about 16 MB for itself.
     *
     * @param array      $code   From code().
     * @param array|null $status opcache_get_status(true), or null.
     * @return array memory (MB), strings (MB), files, ratio, measured, and installed (the same for every installed plugin).
     */
    private static function need(array $code, $status) {
        $ratio         = 2.6;
        $strings_ratio = 0.7;
        $measured      = false;
        $strings_used  = 0;
        $strings_full  = false;
        if (isset($status['interned_strings_usage']['buffer_size'], $status['interned_strings_usage']['free_memory'])) {
            $buffer       = (int) $status['interned_strings_usage']['buffer_size'];
            $strings_used = $buffer - (int) $status['interned_strings_usage']['free_memory'];
            $strings_full = $strings_used >= 0.9 * $buffer;
        }
        if (!empty($status['scripts']) && is_array($status['scripts'])) {
            $source   = 0;
            $compiled = 0;
            foreach ($status['scripts'] as $script) {
                if (isset($script['full_path'], $script['memory_consumption']) && is_file($script['full_path'])) {
                    $source   += (int) filesize($script['full_path']);
                    $compiled += (int) $script['memory_consumption'];
                }
            }
            if ($source > MB_IN_BYTES) {
                $ratio    = $compiled / $source;
                $measured = true;
                if ($strings_used && !$strings_full) {
                    $strings_ratio = $strings_used / $source;
                }
            }
        }
        $sizes = array();
        foreach (array('active' => $code, 'installed' => $code['installed']) as $key => $part) {
            // Half the strings ratio for the rest of the code: later files share names with earlier ones.
            $strings = max(16 * MB_IN_BYTES, $strings_full ? $strings_used * 2 : $strings_used * 1.5, $strings_ratio * $part['bytes'] * 0.5);
            $strings = self::step($strings / MB_IN_BYTES, self::STRING_STEPS);
            $sizes[$key] = array(
                'memory'  => self::step($ratio * $part['bytes'] / MB_IN_BYTES + $strings + 16, self::MEMORY_STEPS),
                'strings' => $strings,
                'files'   => self::step($part['files'] * 1.3, self::FILE_STEPS),
            );
        }
        return $sizes['active'] + array(
            'ratio'     => $ratio,
            'measured'  => $measured,
            'installed' => $sizes['installed'],
        );
    }

    /**
     * Settings from need(), as asked of a host.
     *
     * @param array $need memory, strings and files.
     * @return array
     */
    private static function need_ask(array $need) {
        return array(
            'opcache.memory_consumption'      => $need['memory'],
            'opcache.interned_strings_buffer' => $need['strings'],
            'opcache.max_accelerated_files'   => $need['files'],
        );
    }

    /**
     * PHP memory limit against the highest use recorded.
     *
     * @return array
     */
    private static function assess_memory() {
        $kinds = self::kinds();
        // The server's setting: WordPress raises this request's own limit in the admin.
        $ini    = function_exists('ini_get_all') ? ini_get_all(null, true) : array();
        $server = isset($ini['memory_limit']['global_value']) ? $ini['memory_limit']['global_value'] : ini_get('memory_limit');
        $fields = array(
            'memory_limit' => array(
                'label' => __('PHP memory limit set by the server', 'seoprostack'),
                'value' => (string) $server,
            ),
        );
        $peaks = self::peaks();
        if (!$peaks) {
            return array(
                'status'  => 'unknown',
                'summary' => __('Memory use is recorded from now on. Check again after the site has had some visits.', 'seoprostack'),
                'ask'     => array(),
                'fields'  => $fields,
            );
        }
        $near  = array();
        $parts = array();
        $ask   = array();
        foreach ($peaks as $kind => $pair) {
            list($peak, $limit) = $pair;
            $text = $limit > 0
                /* translators: 1: memory used, 2: memory limit. */
                ? sprintf(__('%1$s of %2$s', 'seoprostack'), self::size($peak), self::size($limit))
                /* translators: %s: memory used. */
                : sprintf(__('%s, no limit', 'seoprostack'), self::size($peak));
            $fields['memory_' . $kind] = array(
                /* translators: %s: kind of request, such as Pages or Admin. */
                'label' => sprintf(__('Highest memory use in 7 days: %s', 'seoprostack'), $kinds[$kind]),
                'value' => $text,
            );
            /* translators: 1: kind of request, 2: memory used and limit. */
            $parts[] = sprintf(__('%1$s %2$s', 'seoprostack'), $kinds[$kind], $text);
            if ($limit > 0 && $peak >= self::NEAR * $limit) {
                $near[] = $kinds[$kind];
                // One server limit covers every kind: WordPress only raises it
                // (to WP_MEMORY_LIMIT, and WP_MAX_MEMORY_LIMIT in the admin).
                $want = self::step(max($peak * 1.5, $limit + 1) / MB_IN_BYTES, self::LIMIT_STEPS);
                if (!isset($ask['memory_limit']) || (int) $ask['memory_limit'] < $want) {
                    $ask['memory_limit'] = $want . 'M';
                }
            }
        }
        if ($near) {
            $summary = sprintf(
                /* translators: 1: kinds of request, such as "Pages and Admin", 2: memory use per kind. */
                __('%1$s came close to the PHP memory limit in the last 7 days (%2$s). Requests that reach it stop with an error.', 'seoprostack'),
                wp_sprintf('%l', $near),
                implode('; ', $parts)
            );
            $state = 'recommended';
        } else {
            $summary = sprintf(
                /* translators: %s: memory use per kind of request. */
                __('PHP memory has room. Highest use in the last 7 days: %s.', 'seoprostack'),
                implode('; ', $parts)
            );
            $state = 'good';
        }
        return array(
            'status'  => $state,
            'summary' => $summary,
            'ask'     => $ask,
            'fields'  => $fields,
        );
    }

    /**
     * Size as text: whole units from 10 (110 MB, not 110.0 MB).
     *
     * @param int|float $bytes Bytes.
     * @return string
     */
    private static function size($bytes) {
        $bytes = (int) $bytes;
        return (string) size_format($bytes, $bytes >= 10 * MB_IN_BYTES || 0 === $bytes % MB_IN_BYTES ? 0 : 1);
    }

    /**
     * Smallest step at or above a value.
     *
     * @param float $value Wanted value.
     * @param int[] $steps Steps, smallest first.
     * @return int
     */
    private static function step($value, array $steps) {
        foreach ($steps as $step) {
            if ($step >= $value) {
                return $step;
            }
        }
        return end($steps);
    }

    /**
     * OPcache rounds opcache.max_accelerated_files up to one of these.
     *
     * @param int $files Setting.
     * @return int
     */
    private static function prime($files) {
        return self::step($files, array(223, 463, 983, 1979, 3907, 7963, 16229, 32531, 65407, 130987, 262237, 524521, 1048793));
    }

    /**
     * PHP code as text.
     *
     * @param array $code From code().
     * @return string
     */
    private static function code_text(array $code) {
        $text = sprintf(
            /* translators: 1: size, 2: number of files. */
            __('%1$s in %2$s files', 'seoprostack'),
            self::size($code['bytes']),
            number_format_i18n($code['files'])
        );
        if ($code['missing']) {
            $text .= ' ' . sprintf(
                /* translators: %s: number of plugins. */
                _n('(%s plugin not measured yet)', '(%s plugins not measured yet)', $code['missing'], 'seoprostack'),
                number_format_i18n($code['missing'])
            );
        }
        return $text;
    }

    /**
     * Settings to ask for, as text.
     *
     * @param array $ask Setting => value.
     * @return string
     */
    private static function ask_text(array $ask) {
        $lines = array();
        foreach ($ask as $name => $value) {
            $lines[] = $name . '=' . $value; // As written in php.ini.
        }
        return implode(', ', $lines);
    }

    /**
     * "Ask your host for" with each setting in its own code element.
     *
     * @param array $ask Setting => value.
     * @return string HTML.
     */
    private static function ask_html(array $ask) {
        $codes = array();
        foreach ($ask as $name => $value) {
            $codes[] = '<code>' . esc_html($name . '=' . $value) . '</code>';
        }
        return esc_html__('Ask your host for:', 'seoprostack') . ' ' . implode(' ', $codes);
    }

    /**
     * Memory per PHP worker, as a sentence.
     *
     * @param int $worker Bytes.
     * @return string
     */
    private static function worker_text($worker) {
        $opcache = (int) ini_get('opcache.memory_consumption');
        if ($opcache && extension_loaded('Zend OPcache')) {
            return sprintf(
                /* translators: 1: memory per worker, 2: OPcache memory. */
                __('Each PHP worker needs up to about %1$s, plus %2$s of OPcache shared by all of them.', 'seoprostack'),
                self::size($worker),
                self::size($opcache * MB_IN_BYTES)
            );
        }
        return sprintf(
            /* translators: %s: memory per worker. */
            __('Each PHP worker needs up to about %s.', 'seoprostack'),
            self::size($worker)
        );
    }

    /**
     * Rows of the plans table: label => level => value.
     *
     * @param array $needs From assess().
     * @return array
     */
    private static function plan_rows(array $needs) {
        $plans  = $needs['plans'];
        $need   = $needs['opcache']['need'];
        $limit  = self::memory_limit_need();
        $rows   = array(
            __('PHP workers', 'seoprostack') => array(),
            __('RAM', 'seoprostack')         => array(),
            __('CPU cores', 'seoprostack')   => array(),
            __('Object cache', 'seoprostack') => array(),
            __('Hosting', 'seoprostack')     => array(),
        );
        foreach ($plans as $level => $plan) {
            $rows[__('PHP workers', 'seoprostack')][$level]  = number_format_i18n($plan['workers']);
            /* translators: %s: number of gigabytes. */
            $rows[__('RAM', 'seoprostack')][$level]          = sprintf(__('%s GB', 'seoprostack'), number_format_i18n($plan['ram']));
            $rows[__('CPU cores', 'seoprostack')][$level]    = number_format_i18n($plan['cpu']);
            $rows[__('Object cache', 'seoprostack')][$level] = $plan['object_cache'] ? __('Redis or Memcached', 'seoprostack') : __('Optional', 'seoprostack');
            $rows[__('Hosting', 'seoprostack')][$level]      = $plan['type'];
        }
        // The same at any traffic: one cell across every plan.
        $rows[__('OPcache', 'seoprostack')] = sprintf(
            /* translators: 1: OPcache memory, 2: strings buffer, 3: number of files. */
            __('%1$s, with %2$s for strings and %3$s files', 'seoprostack'),
            self::size($need['memory'] * MB_IN_BYTES),
            self::size($need['strings'] * MB_IN_BYTES),
            number_format_i18n($need['files'])
        );
        $rows[__('PHP memory limit', 'seoprostack')] = $limit . 'M';
        return $rows;
    }

    /**
     * PHP memory limit with room: half as much again as the highest use,
     * and at least 256 MB, as WordPress raises the admin's limit to.
     *
     * @return int MB.
     */
    private static function memory_limit_need() {
        $peak = 0;
        foreach (self::peaks() as $pair) {
            $peak = max($peak, $pair[0]);
        }
        return self::step(max(256, $peak * 1.5 / MB_IN_BYTES), self::LIMIT_STEPS);
    }

    /**
     * Plans table.
     *
     * @param array $needs From assess().
     * @return string HTML.
     */
    private static function plans_html(array $needs) {
        $labels = SEOProStack_Hosting_Plans::labels();
        $html   = '<div class="sps-hosting__plans"><table><thead><tr><th scope="col">' . esc_html__('Hosting to buy', 'seoprostack') . '</th>';
        foreach ($needs['plans'] as $level => $plan) {
            $html .= '<th scope="col">' . esc_html($labels[$level]);
            if (isset($plan['visits'])) {
                /* translators: %s: number of visits. */
                $html .= '<br><span>' . esc_html(sprintf(__('%s visits a month', 'seoprostack'), number_format_i18n($plan['visits']))) . '</span>';
            } else {
                $html .= '<br><span>' . esc_html__('measured', 'seoprostack') . '</span>';
            }
            $html .= '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (self::plan_rows($needs) as $label => $values) {
            $html .= '<tr><th scope="row">' . esc_html($label) . '</th>';
            if (is_array($values)) {
                foreach ($values as $value) {
                    $html .= '<td>' . esc_html($value) . '</td>';
                }
            } else {
                $html .= '<td colspan="' . count($needs['plans']) . '">' . esc_html($values) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<p class="sps-hosting__note">' . esc_html(SEOProStack_Hosting_Plans::assumptions($needs['page_cache'], (bool) $needs['dynamic'], $needs['measured'])) . '</p>';
        return $html;
    }

    /* ------------------------------------------------------------------
     * Plugins screen
     * ------------------------------------------------------------------ */

    /**
     * Hook the Plugins screen.
     */
    public static function load_screen() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        add_action('admin_head', array(__CLASS__, 'style'));
        add_action('admin_print_footer_scripts', array(__CLASS__, 'script'));
    }

    /**
     * Row markup.
     *
     * @param array $needs From assess().
     * @return string
     */
    public static function row_html(array $needs) {
        $items = array(
            array($needs['opcache']['status'], $needs['opcache']['summary'], $needs['opcache']['ask']),
            array($needs['memory']['status'], $needs['memory']['summary'], $needs['memory']['ask']),
        );
        if ($needs['worker']) {
            $items[] = array('info', self::worker_text($needs['worker']), array());
        }
        foreach ($needs['advice'] as $advice) {
            $items[] = array($advice[0], $advice[1], array());
        }
        $icons = array(
            'good'        => 'yes-alt',
            'recommended' => 'warning',
            'unknown'     => 'info-outline',
            'info'        => 'info-outline',
        );
        $html = '<ul class="sps-hosting__list">';
        foreach ($items as $item) {
            list($status, $summary, $ask) = $item;
            $html .= sprintf(
                '<li class="is-%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span><span>%3$s%4$s</span></li>',
                esc_attr($status),
                esc_attr($icons[$status]),
                esc_html($summary),
                $ask ? ' ' . self::ask_html($ask) : ''
            );
        }
        $html .= '</ul>' . self::plans_html($needs);
        if (!is_network_admin() && current_user_can('view_site_health_checks')) {
            $html .= sprintf(
                '<a href="%1$s">%2$s</a>',
                // Newer WordPress opens the section from this hash; 6.2 just opens the Info tab.
                esc_url(admin_url('site-health.php?tab=debug#health-check-section-seoprostack-hosting')),
                esc_html__('Details in Site Health', 'seoprostack')
            );
        }
        return '<strong>' . esc_html__('Hosting needs', 'seoprostack') . '</strong>' . $html;
    }

    /**
     * Fill the row, measuring plugins a few seconds at a time.
     */
    public static function ajax_row() {
        check_ajax_referer(self::AJAX, 'nonce');
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(array('message' => __('You are not allowed to do that.', 'seoprostack')), 403);
        }
        $needs = self::assess(3);
        wp_send_json_success(array(
            'html'    => self::row_html($needs),
            'missing' => $needs['code']['missing'],
        ));
    }

    /**
     * Row styles.
     */
    public static function style() {
        ?>
        <style>
            .sps-hosting > tr > td { background: #f6f7f7; border-top: 1px solid #dcdcde; }
            .sps-hosting.is-first > tr > td { border-top: 2px solid #c3c4c7; }
            .sps-hosting__list { margin: 6px 0; max-width: 60em; }
            .sps-hosting__list li { display: flex; gap: 6px; margin: 0 0 4px; }
            .sps-hosting__list .dashicons { flex: none; font-size: 18px; width: 18px; height: 18px; color: #646970; }
            .sps-hosting__list .is-good .dashicons { color: #00a32a; }
            .sps-hosting__list .is-recommended .dashicons { color: #dba617; }
            .sps-hosting__list code { font-size: 12px; white-space: nowrap; }
            .sps-hosting__plans { overflow-x: auto; max-width: 100%; margin: 10px 0 4px; }
            .sps-hosting__plans table { border-collapse: collapse; min-width: 36em; background: #fff; border: 1px solid #dcdcde; }
            .sps-hosting__plans th, .sps-hosting__plans td { padding: 6px 10px; text-align: left; vertical-align: top; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
            .sps-hosting__plans thead th { font-weight: 600; border-bottom-color: #dcdcde; }
            .sps-hosting__plans thead th span { font-weight: 400; color: #646970; font-size: 12px; }
            .sps-hosting__plans tbody th { font-weight: 400; color: #50575e; white-space: nowrap; }
            .sps-hosting__note { margin: 4px 0 8px; max-width: 60em; color: #646970; font-size: 12px; }
            .sps-hosting.is-pending .sps-hosting__cell, .sps-hosting.is-failed .sps-hosting__cell { color: #646970; }
            @media screen and (max-width: 782px) {
                .sps-hosting td.check-column { display: none !important; }
                /* The list table's phone layout stacks and hides cells; not in this table. */
                .wp-list-table .sps-hosting__plans tr { display: table-row !important; }
                .wp-list-table .sps-hosting__plans tr td, .wp-list-table .sps-hosting__plans tr th { display: table-cell !important; position: static; padding: 6px 10px !important; width: auto !important; }
                .wp-list-table .sps-hosting__plans tr td::before { content: none !important; }
            }
        </style>
        <?php
    }

    /**
     * Add the row below the plugin list and fill it in.
     */
    public static function script() {
        $data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'nonce'   => wp_create_nonce(self::AJAX),
            'i18n'    => array(
                'checking' => __('Hosting needs: checking…', 'seoprostack'),
                'failed'   => __('Hosting needs: could not check.', 'seoprostack'),
            ),
        );
        ?>
        <script>
        (function ($, cfg) {
            var $table = $('.wp-list-table.plugins');
            if (!$table.length) { return; }
            var $columns = $table.find('thead tr').first().children().not('.hidden');
            var $row = $('<tbody class="sps-hosting is-pending"><tr><td class="check-column"></td><td class="column-primary sps-hosting__cell"></td></tr></tbody>');
            var $cell = $row.find('.sps-hosting__cell').attr('colspan', Math.max(1, $columns.length - 1)).text(cfg.i18n.checking);
            // Last of the rows below the list, after the Size totals when they are on.
            $table.children('tbody').last().after($row);
            $row.toggleClass('is-first', !$table.find('.sps-size-totals').length);

            var last = -1;
            function fail() { $row.removeClass('is-pending').addClass('is-failed'); $cell.text(cfg.i18n.failed); }
            function load() {
                $.post(cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce })
                    .done(function (response) {
                        if (!response || !response.success) { fail(); return; }
                        $cell.html(response.data.html);
                        $row.removeClass('is-pending').toggleClass('is-first', !$table.find('.sps-size-totals').length);
                        var missing = response.data.missing;
                        // Keep measuring while each round measures something.
                        if (missing && missing !== last) { last = missing; load(); }
                    })
                    .fail(fail);
            }
            load();
        })(jQuery, <?php echo wp_json_encode($data); ?>);
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Site Health
     * ------------------------------------------------------------------ */

    /**
     * Add the tests.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        // WordPress 7.0 and later test whether OPcache is on; then this
        // only adds its size check, and leaves "off" to core.
        $core_checks_on = method_exists('WP_Site_Health', 'get_test_opcode_cache');
        $enabled        = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        if ($enabled || !$core_checks_on) {
            $tests['async'][self::TEST] = array(
                'label'             => __('OPcache size', 'seoprostack'),
                'test'              => self::TEST,
                'async_direct_test' => array(__CLASS__, 'test_opcache'),
            );
        }
        if (self::peaks()) {
            $tests['direct']['seoprostack_memory'] = array(
                'label' => __('PHP memory', 'seoprostack'),
                'test'  => array(__CLASS__, 'test_memory'),
            );
        }
        return $tests;
    }

    /**
     * Run the OPcache test for the Site Health screen.
     */
    public static function ajax_test() {
        check_ajax_referer('health-check-site-status');
        if (!current_user_can('view_site_health_checks')) {
            wp_send_json_error();
        }
        wp_send_json_success(self::test_opcache());
    }

    /**
     * OPcache test.
     *
     * @return array
     */
    public static function test_opcache() {
        $part = self::assess_opcache(self::code(5));
        if (!empty($part['off'])) {
            $label = __('OPcache is off', 'seoprostack');
        } elseif ('recommended' === $part['status']) {
            $label = __('OPcache is too small for this site', 'seoprostack');
        } else {
            $label = __('OPcache has room for this site’s PHP code', 'seoprostack');
        }
        $description = '<p>' . esc_html__('OPcache keeps compiled PHP code in memory, so PHP does not read and compile it again on every request. It needs room for the code your plugins and theme load.', 'seoprostack') . '</p>';
        if (!empty($part['private'])) {
            // Core's own test reads the same status, so it reports OPcache as off.
            $description .= '<p>' . esc_html__('This server keeps OPcache’s status private (opcache.restrict_api), so WordPress’s own check may say it is off.', 'seoprostack') . '</p>';
        }
        return self::result('seoprostack_opcache', $label, $part, $description);
    }

    /**
     * PHP memory test.
     *
     * @return array
     */
    public static function test_memory() {
        $part  = self::assess_memory();
        $label = 'recommended' === $part['status']
            ? __('Requests come close to the PHP memory limit', 'seoprostack')
            : __('PHP memory has room for this site', 'seoprostack');
        $description = '<p>' . esc_html__('Each request can use memory up to the PHP memory limit. SEO Pro Stack records the most memory a request used each day, for pages, the admin and the REST API with cron.', 'seoprostack') . '</p>';
        $result      = self::result('seoprostack_memory', $label, $part, $description);
        if ($part['ask']) {
            $result['actions'] .= '<p>' . esc_html__('If your host lets sites raise it themselves, WP_MEMORY_LIMIT (pages) and WP_MAX_MEMORY_LIMIT (admin) in wp-config.php do the same.', 'seoprostack') . '</p>';
        }
        return $result;
    }

    /**
     * Site Health result.
     *
     * @param string $test        Test ID.
     * @param string $label       Heading.
     * @param array  $part        Assessment part.
     * @param string $description Explanation (HTML).
     * @return array
     */
    private static function result($test, $label, array $part, $description) {
        $actions = '';
        if ($part['ask']) {
            $actions = '<p>' . self::ask_html($part['ask']) . '</p>';
        }
        return array(
            'label'       => $label,
            'status'      => 'recommended' === $part['status'] ? 'recommended' : 'good',
            'badge'       => array(
                'label' => __('Performance', 'seoprostack'),
                'color' => 'blue',
            ),
            'description' => '<p>' . esc_html($part['summary']) . '</p>' . $description,
            'actions'     => $actions,
            'test'        => $test,
        );
    }

    /**
     * Hosting needs section on the Info tab, which can be copied for a host.
     *
     * @param array $info Sections.
     * @return array
     */
    public static function info($info) {
        $needs  = self::assess(0); // Plugins are measured by the test and the Plugins screen.
        $fields = array_merge($needs['opcache']['fields'], $needs['memory']['fields']);
        if ($needs['worker']) {
            $fields['worker'] = array(
                'label' => __('Memory per PHP worker', 'seoprostack'),
                /* translators: %s: memory. */
                'value' => sprintf(__('Up to about %s', 'seoprostack'), self::size($needs['worker'])),
            );
        }
        $ask              = array_merge($needs['opcache']['ask'], $needs['memory']['ask']);
        $fields['ask']    = array(
            'label' => __('Settings to ask your host for', 'seoprostack'),
            'value' => $ask ? self::ask_text($ask) : __('None', 'seoprostack'),
        );
        $fields += self::site_fields($needs);

        // Plans, one line each.
        $labels = SEOProStack_Hosting_Plans::labels();
        $rows   = self::plan_rows($needs);
        foreach ($needs['plans'] as $level => $plan) {
            $parts = array();
            foreach ($rows as $label => $values) {
                $parts[] = $label . ': ' . (is_array($values) ? $values[$level] : $values);
            }
            $fields['plan_' . $level] = array(
                'label' => isset($plan['visits'])
                    /* translators: 1: Low traffic, Medium traffic or High traffic, 2: visits a month. */
                    ? sprintf(__('%1$s (%2$s visits a month)', 'seoprostack'), $labels[$level], number_format_i18n($plan['visits']))
                    /* translators: %s: Now. */
                    : sprintf(__('%s (measured traffic)', 'seoprostack'), $labels[$level]),
                'value' => implode('; ', $parts),
            );
        }
        $fields['assumptions'] = array(
            'label' => __('How the plans are worked out', 'seoprostack'),
            'value' => SEOProStack_Hosting_Plans::assumptions($needs['page_cache'], (bool) $needs['dynamic'], $needs['measured']),
        );
        $info['seoprostack-hosting'] = array(
            'label'       => __('Hosting needs', 'seoprostack'),
            'description' => __('What this site uses, against what the server allows, and hosting to buy for its traffic. Copy the site info to send it to your host.', 'seoprostack'),
            'fields'      => $fields,
        );
        return $info;
    }

    /**
     * Site Health Info fields for traffic and the site.
     *
     * @param array $needs From assess().
     * @return array
     */
    private static function site_fields(array $needs) {
        $traffic = $needs['traffic'];
        $facts   = $needs['facts'];
        $kinds   = self::kinds();
        $fields  = array();
        if ($traffic['samples']) {
            $fields['traffic'] = array(
                'label' => __('Requests that reached PHP', 'seoprostack'),
                'value' => sprintf(
                    /* translators: 1: requests a day, 2: requests in the busiest hour, 3: requests sampled. */
                    __('About %1$s a day, %2$s in the busiest hour (from %3$s sampled requests)', 'seoprostack'),
                    number_format_i18n($traffic['per_day']),
                    number_format_i18n($traffic['peak_hour']),
                    number_format_i18n($traffic['samples'])
                ),
            );
            foreach ($traffic['kinds'] as $kind => $stats) {
                $fields['time_' . $kind] = array(
                    /* translators: %s: kind of request, such as Pages or Admin. */
                    'label' => sprintf(__('Time per request: %s', 'seoprostack'), $kinds[$kind]),
                    'value' => sprintf(
                        /* translators: 1: average seconds, 2: seconds 95% stay under, 3: samples. */
                        __('%1$s s on average, 95%% under %2$s s (%3$s samples)', 'seoprostack'),
                        number_format_i18n($stats['avg'], 2),
                        number_format_i18n($stats['p95'], 2),
                        number_format_i18n($stats['n'])
                    ),
                );
            }
        }
        $fields['web_server'] = array(
            'label' => __('Web server', 'seoprostack'),
            'value' => SEOProStack_Litespeed::server_name(),
        );
        $fields['page_cache'] = array(
            'label' => __('Page cache', 'seoprostack'),
            'value' => $needs['page_cache'] ? __('Found', 'seoprostack') : __('None found (a cache run by the host cannot be seen)', 'seoprostack'),
        );
        $fields['object_cache'] = array(
            'label' => __('Persistent object cache', 'seoprostack'),
            'value' => wp_using_ext_object_cache() ? __('Yes', 'seoprostack') : __('No', 'seoprostack'),
        );
        $fields['site_kind'] = array(
            'label' => __('Shop, membership or course plugins', 'seoprostack'),
            'value' => $needs['dynamic'] ? implode(', ', $needs['dynamic']) : __('None', 'seoprostack'),
        );
        $fields['database'] = array(
            'label' => __('Database size', 'seoprostack'),
            'value' => sprintf(
                /* translators: 1: size, 2: rows of post data, 3: products. */
                __('%1$s, about %2$s rows of post data, %3$s products', 'seoprostack'),
                self::size($facts['db']),
                number_format_i18n($facts['postmeta']),
                number_format_i18n($facts['products'])
            ),
        );
        $fields['autoload'] = array(
            'label' => __('Options loaded on every request', 'seoprostack'),
            /* translators: 1: size, 2: number of options. */
            'value' => sprintf(__('%1$s in %2$s options', 'seoprostack'), self::size($facts['autoload']), number_format_i18n($facts['autoload_count'])),
        );
        $fields['php_version'] = array(
            'label' => __('PHP version', 'seoprostack'),
            'value' => PHP_VERSION,
        );
        return $fields;
    }
}
