<?php
/**
 * Memory ceilings: a PHP memory limit for every process of the site, sized
 * from the memory it uses, so one process that grows stops at PHP's limit
 * instead of filling the hosting account's memory.
 *
 * On shared-memory hosting (CloudLinux LVE, such as Hostinger) PHP's limit
 * is often the whole account's memory, and WP-CLI removes its own (-1) once
 * WordPress has loaded, for every command. When the account fills, the host
 * stops processes on every site in it, visitors' requests and commands
 * alike. With this on:
 *
 * - WP-CLI: every command gets the ceiling, from the plugin's load and again
 *   on WP-CLI's after_wp_load: at least CLI_MB, twice the most that command
 *   used in 30 days, and never more than half the account's memory when
 *   known. Commands that often need more (HEAVY) get at least HEAVY_MB. A
 *   limit given on purpose is used instead: SEOPROSTACK_CLI_MEMORY_LIMIT in
 *   the environment, or memory_limit in WP_CLI_PHP_ARGS (never -1). The
 *   seoprostack_cli_memory_limit filter changes a command's ceiling.
 * - Pages, admin screens and cron over HTTP: lowered, never raised, and only
 *   from evidence: after 7 days of peaks of that kind of request (Hosting
 *   needs), when the server's limit is far above what the site needs
 *   (Hosting needs' rule), or at least half the account's memory, to what
 *   it needs. A kind with nothing measured keeps the host's limit, unless
 *   that is none (-1).
 * - SEO Pro Stack's own background jobs and `wp seoprostack health` start
 *   with JOB_MB, or twice the most they used in 30 days, at most; a cron
 *   job's lower limit ends with it.
 *
 * WordPress raises the limit itself (wp_raise_memory_limit()): to
 * WP_MAX_MEMORY_LIMIT for administrators' screens, wp-cron.php and image
 * editing, and its filters can only raise it. The ceiling is set again on
 * admin_init and before cron runs its events. Where WP_MAX_MEMORY_LIMIT is
 * no limit (PHP had none when WordPress started), the filters keep the
 * ceiling instead. Image editing otherwise keeps WP_MAX_MEMORY_LIMIT, which
 * Hosting needs reports (SEOProStack_Memory_Report).
 *
 * Sizing rules and their constants: SEOProStack_Memory_Needs. Sizes:
 * SEOProStack_Memory_Sizes. WP-CLI and cron peaks, recorded by Hosting
 * needs: SEOProStack_Memory_Peaks.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.230
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Memory_Ceilings extends SEOProStack_Feature {

    const KEY = 'memory_ceilings';

    /** Environment variable that gives one command its ceiling. */
    const ENV = 'SEOPROSTACK_CLI_MEMORY_LIMIT';

    /** SEO Pro Stack's own background jobs (cron hooks). */
    const JOBS = array(SEOProStack_Media_Storage::HOOK, SEOProStack_Watermark_Images::HOOK, SEOProStack_Watermark_Images::PRODUCTS_HOOK);

    /**
     * Ceiling this process keeps, in bytes; 0 for none.
     *
     * @var int
     */
    private static $ceiling = 0;

    /**
     * Whether a person set this command's limit (ENV or WP_CLI_PHP_ARGS).
     *
     * @var bool
     */
    private static $explicit = false;

    /**
     * Ceiling and memory_limit before a background job lowered them, until
     * it ends; null outside one.
     *
     * @var array{0: int, 1: string}|null
     */
    private static $before = null;

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
                'tab'         => 'server',
                'label'       => __('Memory ceilings', 'seoprostack'),
                'description' => __('Give every PHP process of this site a memory limit sized from the memory it uses, where PHP allows the change. WP-CLI commands, which WP-CLI otherwise runs with no limit, get at least 2 GB, or twice the most that command used in 30 days, and never more than half the hosting account’s memory when known. Pages, admin screens and cron each get a lower limit only after 7 days of their own measurements show the server’s is far above what they need, or is at least half the account’s memory. A runaway request or command then stops with PHP’s “Allowed memory size” error instead of filling the account’s memory, where the host stops processes on every site in it. A command that needs more can ask for it: SEOPROSTACK_CLI_MEMORY_LIMIT=6G wp …', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks: SEO Pro Stack's own background jobs start with their
     * ceiling, and later cron events get the limit back when they end.
     * WP-CLI's ceiling and the web's are set earlier (early()).
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        foreach (self::JOBS as $hook) {
            add_action($hook, function () use ($hook) {
                self::job($hook);
            }, -1000);
            add_action($hook, array(__CLASS__, 'end_job'), PHP_INT_MAX);
        }
    }

    /**
     * At the plugin's load (SEOProStack_Setup::init()): WP-CLI's ceiling at
     * once, the web's on plugins_loaded. Settings are read from the option,
     * as the settings schema is not ready yet.
     */
    public static function early() {
        $cli = defined('WP_CLI') && WP_CLI;
        if ($cli) {
            // Also with Memory ceilings off: WP-CLI's own limit, for the report.
            WP_CLI::add_hook('after_wp_load', array(__CLASS__, 'after_wp_load'));
        }
        if (!self::is_on() || !self::changeable()) {
            return;
        }
        if ($cli) {
            self::cli();
            return;
        }
        add_action('plugins_loaded', array(__CLASS__, 'web'), 0);
    }

    /**
     * WP-CLI: a limit asked for on purpose, else the command's ceiling. Set
     * now, for loading plugins, and again on WP-CLI's after_wp_load: WP-CLI
     * removes the limit (-1) as soon as WordPress has loaded, for every
     * command, so php.ini's and php -d memory_limit=… do not last. -1 asked
     * for is not accepted: the ceiling stays.
     */
    private static function cli() {
        $asked = SEOProStack_Memory_Sizes::bytes((string) getenv(self::ENV));
        $asked = $asked > 0 ? $asked : SEOProStack_Memory_Sizes::php_args_limit();
        if ($asked > 0) {
            self::$explicit = true;
            self::$ceiling  = $asked;
            self::set($asked);
        } else {
            self::$ceiling = SEOProStack_Memory_Needs::cli(SEOProStack_Memory_Peaks::command());
            self::hold();
        }
        self::keep_from_core();
    }

    /**
     * WP-CLI has loaded WordPress: note its own limit (usually none), then
     * set the ceiling again, sized now that every command is registered.
     * Say so when PHP refuses it, as the command then has no limit.
     */
    public static function after_wp_load() {
        SEOProStack_Memory_Peaks::note_cli(SEOProStack_Memory_Sizes::bytes(ini_get('memory_limit')));
        if (self::$explicit) {
            self::set(self::$ceiling);
        } elseif (self::$ceiling > 0) {
            self::$ceiling = SEOProStack_Memory_Needs::cli(SEOProStack_Memory_Peaks::command());
            self::hold();
        }
        if (self::$ceiling > 0 && -1 === SEOProStack_Memory_Sizes::bytes(ini_get('memory_limit'))) {
            /* translators: %s: memory limit, such as 2048M. */
            WP_CLI::warning(sprintf(__('Memory ceilings could not set a memory limit of %s: this command already uses more. It runs with no limit.', 'seoprostack'), size_format(self::$ceiling)));
        }
    }

    /**
     * Web requests: lower the limit when the evidence says so
     * (SEOProStack_Memory_Needs::web()), and set it again after WordPress
     * raises it for administrators' screens and for cron.
     */
    public static function web() {
        $cap = SEOProStack_Memory_Needs::web(SEOProStack_Memory_Sizes::bytes(ini_get('memory_limit')));
        if ($cap <= 0) {
            return;
        }
        self::$ceiling = $cap;
        self::hold();
        // wp-admin/admin.php raises it to WP_MAX_MEMORY_LIMIT just before admin_init.
        add_action('admin_init', function () {
            self::hold();
        }, PHP_INT_MIN);
        // wp-cron.php raises it before it reads the events that are due.
        add_filter('pre_get_ready_cron_jobs', array(__CLASS__, 'hold'), PHP_INT_MIN);
        self::keep_from_core();
    }

    /**
     * Give one of SEO Pro Stack's own long jobs its ceiling
     * (SEOProStack_Memory_Needs::job()). Only lowers the limit, never past a
     * limit a person asked for.
     *
     * @param string $name Job: a WP-CLI command, such as "seoprostack health", or a cron hook.
     */
    public static function job($name) {
        if (self::$explicit || !self::is_on() || !self::changeable()) {
            return;
        }
        if (null === self::$before) {
            self::$before = array(self::$ceiling, (string) ini_get('memory_limit'));
        }
        $limit = SEOProStack_Memory_Needs::job((string) $name);
        if (self::$ceiling <= 0 || $limit < self::$ceiling) {
            self::$ceiling = $limit;
        }
        self::hold();
        self::keep_from_core();
    }

    /**
     * A background job has ended: give the cron events after it the ceiling
     * and limit they had before it, which can only be higher.
     */
    public static function end_job() {
        if (null === self::$before) {
            return;
        }
        list(self::$ceiling, $limit) = self::$before;
        self::$before = null;
        ini_set('memory_limit', $limit); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- the point of this feature.
    }

    /**
     * Set the ceiling again when the limit is above it or none. Also a
     * filter, returning its value unchanged.
     *
     * @param mixed $value Filtered value, if any.
     * @return mixed
     */
    public static function hold($value = null) {
        if (self::$ceiling > 0) {
            $now = SEOProStack_Memory_Sizes::bytes(ini_get('memory_limit'));
            if (-1 === $now || $now > self::$ceiling) {
                self::set(self::$ceiling);
            }
        }
        return $value;
    }

    /**
     * While WP_MAX_MEMORY_LIMIT is no limit, wp_raise_memory_limit() would
     * remove the ceiling for admin screens, cron and image editing. Its
     * filters can only raise the limit, so they ask for one byte more than
     * now, which keeps it.
     */
    private static function keep_from_core() {
        static $added = false;
        if ($added || !defined('WP_MAX_MEMORY_LIMIT') || -1 !== SEOProStack_Memory_Sizes::bytes(WP_MAX_MEMORY_LIMIT)) {
            return;
        }
        $added = true;
        foreach (array('admin_memory_limit', 'image_memory_limit', 'cron_memory_limit') as $filter) {
            add_filter($filter, array(__CLASS__, 'core_limit'), PHP_INT_MAX);
        }
    }

    /**
     * Filter for wp_raise_memory_limit(): keep the limit in force instead of
     * none, unless other code asked for a higher one.
     *
     * @param int|string $limit Limit WordPress would set.
     * @return int|string
     */
    public static function core_limit($limit) {
        $now  = SEOProStack_Memory_Sizes::bytes(ini_get('memory_limit'));
        $want = SEOProStack_Memory_Sizes::bytes($limit);
        if (self::$ceiling <= 0 || $now <= 0 || $want > $now) {
            return $limit;
        }
        return $now + 1;
    }

    /**
     * Set PHP's memory limit, unless it is below the memory in use, which
     * PHP refuses with a warning.
     *
     * @param int $bytes Limit.
     */
    private static function set($bytes) {
        if ($bytes <= memory_get_usage(true)) {
            return;
        }
        $value = 0 === $bytes % MB_IN_BYTES ? intdiv($bytes, MB_IN_BYTES) . 'M' : (string) $bytes;
        ini_set('memory_limit', $value); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- the point of this feature.
    }

    /** Whether this process may change memory_limit. @return bool */
    public static function changeable() {
        return function_exists('ini_set') && wp_is_ini_value_changeable('memory_limit');
    }

    /** Whether Memory ceilings is on, read straight from the options. @return bool */
    private static function is_on() {
        return (bool) SEOProStack_Memory_Sizes::setting(self::KEY, true);
    }
}
