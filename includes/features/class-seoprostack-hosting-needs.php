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
 * From those, it suggests settings to ask the host for, and how much memory
 * each PHP worker needs. CPU and the number of workers depend on traffic the
 * page cache does not serve, which PHP cannot see, so they are explained, not
 * guessed.
 *
 * Shown in a row below the plugin list (filled in by AJAX, so the screen
 * opens straight away) and in Tools → Site Health: a test for each, and a
 * Hosting needs section on the Info tab that can be copied for a host.
 *
 * @package SEOProStack
 * @since 0.4.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Hosting_Needs extends SEOProStack_Feature {

    const KEY = 'hosting_needs';

    /** Autoloaded option: Y-m-d => kind => array(peak bytes, limit bytes). */
    const MEMORY = 'seoprostack_hosting_memory';

    /** Option: PHP code of WordPress, the theme, must-use plugins and drop-ins. */
    const CODE = 'seoprostack_hosting_code';

    /** Days of memory peaks kept. */
    const DAYS = 7;

    /** AJAX action for the row on the Plugins screen. */
    const AJAX = 'seoprostack_hosting_needs';

    /** Site Health test ID; core posts it to health-check-{ID}. */
    const TEST = 'seoprostack-opcache';

    /** Memory a PHP worker uses besides the request itself, for planning. */
    const WORKER_BASE = 32;

    /** Share of a limit above which more is suggested. */
    const NEAR = 0.8;

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
     * Record this request's memory peak if it is the highest today for its
     * kind of request.
     */
    public static function record() {
        if ((defined('WP_CLI') && WP_CLI) || wp_installing()) {
            return; // The command line has its own limit, often none.
        }
        $kind  = self::kind();
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
     * @return array bytes, files and missing (active plugins not measured yet).
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

        $code = array('bytes' => (int) $base['bytes'], 'files' => (int) $base['files'], 'missing' => 0);
        if (class_exists('SEOProStack_Plugin_Sizes')) {
            $active = (array) get_option('active_plugins', array());
            if (is_multisite()) {
                $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
            }
            $plugins          = SEOProStack_Plugin_Sizes::php_code($active, $budget);
            $code['bytes']   += $plugins['bytes'];
            $code['files']   += $plugins['files'];
            $code['missing']  = $plugins['missing'];
        }
        return $code;
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
     * @return array opcache, memory and worker (bytes per PHP worker, or 0).
     */
    public static function assess($budget) {
        $code    = self::code($budget);
        $opcache = self::assess_opcache($code);
        $memory  = self::assess_memory();
        $peak    = 0;
        foreach (self::peaks() as $pair) {
            $peak = max($peak, $pair[0]);
        }
        return array(
            'code'    => $code,
            'opcache' => $opcache,
            'memory'  => $memory,
            'worker'  => $peak ? (int) (ceil(($peak / MB_IN_BYTES + self::WORKER_BASE) / 16) * 16 * MB_IN_BYTES) : 0,
        );
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
            // False when opcache.restrict_api keeps this script out.
            $status = @opcache_get_status(false); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if (is_array($status) && empty($status['opcache_enabled'])) {
                $enabled = false;
            }
            $status = is_array($status) ? $status : null;
        }
        $fields = array(
            'php_code' => array(
                'label' => __('PHP code that can load', 'seoprostack'),
                'value' => self::code_text($code),
            ),
            'opcache' => array(
                'label' => __('OPcache', 'seoprostack'),
                'value' => $enabled ? __('On', 'seoprostack') : __('Off', 'seoprostack'),
            ),
        );
        if (!$enabled) {
            return array(
                'status'  => 'recommended',
                'summary' => __('OPcache is off, so PHP compiles the site’s code again on every request. Ask your host to turn it on.', 'seoprostack'),
                'ask'     => array('opcache.enable' => '1'),
                'fields'  => $fields,
                'off'     => true,
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
                $ask['opcache.memory_consumption'] = self::step(max($used * 1.5, $memory + 1) / MB_IN_BYTES, array(128, 192, 256, 384, 512, 768, 1024, 2048));
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
            if ($code['bytes'] > $memory) {
                // Compiled code takes at least about as much memory as its source.
                $ask['opcache.memory_consumption'] = self::step($code['bytes'] * 1.5 / MB_IN_BYTES, array(128, 192, 256, 384, 512, 768, 1024, 2048));
                /* translators: 1: size of PHP code, 2: OPcache memory. */
                $problems[] = sprintf(__('the site has %1$s of PHP code, more than its %2$s of memory', 'seoprostack'), self::size($code['bytes']), self::size($memory));
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
            $ask['opcache.max_accelerated_files'] = self::step(max($code['files'], (int) $cached, $max_keys + 1) * 1.3, array(10000, 20000, 30000, 50000, 100000, 200000, 500000));
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
                $ask['opcache.interned_strings_buffer'] = self::step(max($used * 1.5, $strings + 1) / MB_IN_BYTES, array(16, 32, 64, 128));
                $problems[] = __('its space for shared strings is nearly full', 'seoprostack');
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
                $want = self::step(max($peak * 1.5, $limit + 1) / MB_IN_BYTES, array(128, 256, 512, 1024, 2048));
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
                __('Each PHP worker needs up to about %1$s, plus %2$s of OPcache shared by all of them. How many workers you need depends on the traffic your page cache does not serve.', 'seoprostack'),
                self::size($worker),
                self::size($opcache * MB_IN_BYTES)
            );
        }
        return sprintf(
            /* translators: %s: memory per worker. */
            __('Each PHP worker needs up to about %s. How many workers you need depends on the traffic your page cache does not serve.', 'seoprostack'),
            self::size($worker)
        );
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
        $html .= '</ul>';
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
            .sps-hosting td { background: #f6f7f7; border-top: 1px solid #dcdcde; }
            .sps-hosting.is-first td { border-top: 2px solid #c3c4c7; }
            .sps-hosting__list { margin: 6px 0; max-width: 60em; }
            .sps-hosting__list li { display: flex; gap: 6px; margin: 0 0 4px; }
            .sps-hosting__list .dashicons { flex: none; font-size: 18px; width: 18px; height: 18px; color: #646970; }
            .sps-hosting__list .is-good .dashicons { color: #00a32a; }
            .sps-hosting__list .is-recommended .dashicons { color: #dba617; }
            .sps-hosting__list code { font-size: 12px; white-space: nowrap; }
            .sps-hosting.is-pending .sps-hosting__cell, .sps-hosting.is-failed .sps-hosting__cell { color: #646970; }
            @media screen and (max-width: 782px) {
                .sps-hosting td.check-column { display: none !important; }
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
        $info['seoprostack-hosting'] = array(
            'label'       => __('Hosting needs', 'seoprostack'),
            'description' => __('What this site uses, against what the server allows. Copy the site info to send it to your host.', 'seoprostack'),
            'fields'      => $fields,
        );
        return $info;
    }
}
