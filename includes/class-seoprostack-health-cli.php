<?php
/**
 * Site Health audit and opt-in copied-site repairs for WP-CLI's --url.
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

class SEOProStack_Health_CLI {

    /** Whether this invocation is the health audit, including bootstrap. @return bool */
    public static function auditing() {
        return defined('WP_CLI') && WP_CLI
            && array_slice(WP_CLI::get_runner()->arguments, 0, 2) === array('seoprostack', 'health');
    }

    /** Skip saving work; register only the existing Site Health providers. */
    public static function prepare() {
        // Core's read APIs populate caches. Keep those incidental writes out of
        // a persistent backend too, for the remainder of this audit process.
        global $wp_object_cache;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Process-local read-only facade; never changes the site's cache backend.
        $wp_object_cache = new SEOProStack_Health_Cache($wp_object_cache);
        remove_action('init', array('SEOProStack_Settings', 'maybe_migrate'), 0);
        remove_action('wp_loaded', array('SEOProStack_Network_Sites', 'sync'));
        // Core moved spawning from wp_loaded to shutdown in newer versions.
        remove_action('wp_loaded', '_wp_cron');
        remove_action('shutdown', '_wp_cron');
        add_action('init', array(__CLASS__, 'boot_tests'), -1);
    }

    /** Register health hooks without booting unrelated features that save files or jobs. */
    public static function boot_tests() {
        remove_action('init', array('SEOProStack', 'boot_features'), 0);
        foreach (array('SEOProStack_Hardening', 'SEOProStack_Database_Keys', 'SEOProStack_Database_Charset', 'SEOProStack_Snippets_Audit', 'SEOProStack_Security_Headers', 'SEOProStack_Https_Check', 'SEOProStack_Hosting_Needs', 'SEOProStack_Integrity_Checks', 'SEOProStack_Vulnerability_Feed') as $class) {
            $class::boot();
        }
    }

    /**
     * Read a probe without get_transient() deleting an expired database record.
     * Stale web probes remain useful; this command never refreshes them.
     *
     * @param string $name Transient name.
     * @return mixed
     */
    public static function stored_transient($name) {
        return wp_using_ext_object_cache() ? wp_cache_get($name, 'transient') : get_option('_transient_' . $name);
    }

    /**
     * List SEO Pro Stack's Site Health results and stored web-PHP records.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report only (the default); cannot be combined with --repair.
     *
     * [--repair]
     * : Repair copied font paths and missing active Rank Math module tables.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * [--fail-on=<status>]
     * : Exit 1 for critical results, or for recommended and critical results.
     * ---
     * options:
     *   - critical
     *   - recommended
     * ---
     *
     * @param array $args Positional arguments.
     * @param array $assoc_args Options.
     */
    public function __invoke($args, $assoc_args) {
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $fail   = isset($assoc_args['fail-on']) ? $assoc_args['fail-on'] : '';
        if (!in_array($format, array('table', 'json'), true) || !in_array($fail, array('', 'critical', 'recommended'), true)) {
            WP_CLI::error('Use --format=table|json and --fail-on=critical|recommended.');
        }
        $repair = isset($assoc_args['repair']);
        if ($repair && isset($assoc_args['dry-run'])) {
            WP_CLI::error('Use either --dry-run or --repair, not both.');
        }
        if ($repair) {
            global $wp_object_cache;
            if ($wp_object_cache instanceof SEOProStack_Health_Cache) {
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original backend only for explicitly requested repairs.
                $wp_object_cache = $wp_object_cache->backend();
            }
        }
        if (!class_exists('WP_Site_Health')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }
        $rows = self::results();
        $rows[] = self::font_paths($repair);
        $rows[] = self::rank_math_tables($repair);
        SEOProStack_Health_Output::render($format, $rows, SEOProStack_Health_Output::records());
        WP_CLI::halt(self::failed($rows, $fail) ? 1 : 0);
    }

    /** @param bool $repair Whether to repair. @return array */
    private static function font_paths($repair) {
        if (is_multisite()) {
            return SEOProStack_Health_Output::row('seoprostack_copied_font_paths', array(
                'status' => 'not_run',
                'label' => 'Copied-site font paths',
                'description' => 'The font cache is network-wide; no shared paths were changed.',
            ));
        }
        $stored = get_site_option('downloaded_font_files', array());
        list($updated, $changed, $unresolved) = self::font_changes(is_array($stored) ? $stored : array());
        if ($repair && $changed) {
            update_site_option('downloaded_font_files', $updated);
            if (get_site_option('downloaded_font_files') !== $updated) {
                $unresolved += $changed;
            }
        }
        return self::font_result($changed, $unresolved, $repair);
    }

    /** @param array $stored Font cache. @return array */
    private static function font_changes(array $stored) {
        $changed = 0;
        $unresolved = 0;
        $root = trailingslashit(wp_normalize_path(WP_CONTENT_DIR)) . 'fonts/';
        foreach ($stored as $url => $path) {
            if (!is_string($path) || 0 === strpos(wp_normalize_path($path), $root)) {
                continue;
            }
            $local = self::local_font_path($path, $root);
            if (false === $local) {
                $unresolved++;
                continue;
            }
            if (null === $local) {
                // Drop only this cache entry; Kadence downloads the font again.
                unset($stored[$url]);
            } else {
                $stored[$url] = $local;
            }
            $changed++;
        }
        return array($stored, $changed, $unresolved);
    }

    /** @param string $path Copied path. @param string $root Local font root. @return string|false|null */
    private static function local_font_path($path, $root) {
        $position = strrpos(wp_normalize_path($path), '/fonts/');
        $relative = false === $position ? '' : substr(wp_normalize_path($path), $position + 7);
        // Never follow a copied path outside the current site's font cache.
        if ('' === $relative || 0 !== validate_file($relative)) {
            return false;
        }
        $local = $root . $relative;
        $real = realpath($local);
        return false !== $real && 0 === strpos(wp_normalize_path($real), $root) && is_file($real) ? $local : null;
    }

    /** @param int $changed Copied entries. @param int $unresolved Unsafe or unsaved entries. @param bool $repair Repair mode. @return array */
    private static function font_result($changed, $unresolved, $repair) {
        return SEOProStack_Health_Output::row('seoprostack_copied_font_paths', array(
            'status' => $unresolved || ($changed && !$repair) ? 'recommended' : 'good',
            'label' => 'Copied-site font paths',
            'description' => sprintf('%d copied font cache paths %s; %d unrecognized or unsaved entries left unchanged.', $changed, $repair ? 'repaired' : 'found', $unresolved),
            'actions' => $changed && !$repair ? 'Run wp seoprostack health --repair to rebase local files or discard stale cache entries. No font files are deleted.' : '',
        ));
    }

    /** @param bool $repair Whether to repair. @return array */
    private static function rank_math_tables($repair) {
        $missing = array();
        if (did_action('rank_math/loaded')) {
            $missing = self::missing_rank_math_tables();
            if ($repair && $missing) {
                self::repair_rank_math_tables(array_values(array_unique($missing)));
                $missing = self::missing_rank_math_tables();
            }
        }
        $names = array_keys($missing);
        return SEOProStack_Health_Output::row('seoprostack_rank_math_tables', array(
            'status' => $missing ? 'recommended' : 'good',
            'label' => 'Rank Math module tables',
            'description' => $missing ? 'Missing tables for active modules: ' . implode(', ', $names) . '.' : 'No missing tables for supported active Rank Math modules.',
            'actions' => $missing ? 'Run wp seoprostack health --repair. Only Rank Math’s own table creation routines are used; no modules are enabled and lost data cannot be recovered.' : '',
        ));
    }

    /** Missing known tables, mapped to their active module. @return array<string,string> */
    private static function missing_rank_math_tables() {
        $missing = array();
        $active = (array) get_option('rank_math_modules', array());
        $tables = array(
            '404-monitor' => array('404_logs'),
            'redirections' => array('redirections', 'redirections_cache'),
            'link-counter' => array('internal_links', 'internal_meta'),
            'analytics' => array('analytics_objects', 'analytics_gsc', 'analytics_inspections'),
        );
        foreach ($tables as $module => $names) {
            if (!in_array($module, $active, true)) {
                continue;
            }
            foreach ($names as $name) {
                if (!self::rank_math_table_exists($name)) {
                    $missing[$name] = $module;
                }
            }
        }
        return $missing;
    }

    /** @param string[] $modules Active modules with missing tables. */
    private static function repair_rank_math_tables(array $modules) {
        if (is_callable(array('RankMath\\Installer', 'create_tables'))) {
            call_user_func(array('RankMath\\Installer', 'create_tables'), array_values(array_diff($modules, array('analytics'))));
        }
        if (in_array('analytics', $modules, true)) {
            foreach (array('Objects', 'Console', 'Inspections') as $workflow) {
                self::create_analytics_table('RankMath\\Analytics\\Workflow\\' . $workflow);
            }
        }
    }

    /** @param string $class Native Analytics schema provider. */
    private static function create_analytics_table($class) {
        if (!class_exists($class) || !method_exists($class, 'create_tables')) {
            return;
        }
        // Constructors start data jobs. Invoke only the native schema routine,
        // which needs no constructor state in the supported Rank Math version.
        $reflection = new ReflectionClass($class);
        $method = $reflection->getMethod('create_tables');
        if ($method->isPublic() && 0 === $method->getNumberOfRequiredParameters()) {
            $method->invoke($reflection->newInstanceWithoutConstructor());
        }
    }

    /** @param string $name Known Rank Math table suffix. @return bool */
    private static function rank_math_table_exists($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_' . $name;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit CLI schema inspection must see tables just created, never a cached result.
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    /** Run the registered plugin tests, never core or other plugins' tests. @return array */
    private static function results() {
        $registered = WP_Site_Health::get_tests();
        $rows       = array();
        foreach (array('direct' => 'test', 'async' => 'async_direct_test') as $group => $key) {
            foreach (isset($registered[$group]) ? $registered[$group] : array() as $id => $test) {
                if (0 !== strpos((string) $id, 'seoprostack')) {
                    continue;
                }
                $rows[] = self::run_test((string) $id, $test, $key);
            }
        }
        return $rows;
    }

    /** @param string $id Test ID. @param array $test Registration. @param string $key Callable key. @return array */
    private static function run_test($id, array $test, $key) {
        $result = array('status' => 'not_run', 'label' => isset($test['label']) ? $test['label'] : $id, 'description' => 'No callable is registered for this test.', 'actions' => '');
        if (isset($test[$key]) && is_callable($test[$key])) {
            try {
                $answer = call_user_func($test[$key]);
                if (is_array($answer)) {
                    $result = $answer;
                }
            } catch (Throwable $error) {
                // Do not expose exception messages: they can contain server paths.
                $result['description'] = 'The test could not run. Check the server diagnostics.';
            }
        }
        return SEOProStack_Health_Output::row($id, $result);
    }

    /** @param array $rows Results. @param string $fail Requested threshold. @return bool */
    private static function failed(array $rows, $fail) {
        $statuses = array('critical' => array('critical'), 'recommended' => array('critical', 'recommended'));
        if (!isset($statuses[$fail])) {
            return false;
        }
        foreach ($rows as $row) {
            if (in_array($row['status'], $statuses[$fail], true)) {
                return true;
            }
        }
        return false;
    }
}

/** Process-local cache reader: reject backend writes, retain namespace metadata. */
class SEOProStack_Health_Cache {
    /** @var object */
    private $reader;

    /** @param object $reader Existing site's cache. */
    public function __construct($reader) {
        $this->reader = $reader;
    }

    /** Original backend, restored only for explicit repairs. @return object */
    public function backend() {
        return $this->reader;
    }

    /** @param string $method Cache operation. @param array $args Arguments. @return mixed */
    public function __call($method, $args) {
        if (in_array($method, array('get', 'get_multiple', 'supports', 'switch_to_blog', 'add_global_groups', 'add_non_persistent_groups'), true) && is_callable(array($this->reader, $method))) {
            return call_user_func_array(array($this->reader, $method), $args);
        }
        return false; // Never forward add/set/delete/flush/incr or shutdown writes.
    }

    /** get() must pass the found flag by reference (a magic call cannot). */
    public function get($key, $group = 'default', $force = false, &$found = null) {
        $getter = array($this->reader, 'get');
        if (is_callable($getter)) {
            return call_user_func_array($getter, array($key, $group, $force, &$found));
        }
        $found = false;
        return false;
    }
}

/** Plain-text rendering and UTC web-record serialization, shared by both formats. */
class SEOProStack_Health_Output {

    /** @param string $id Test ID. @param array $result Site Health result. @return array */
    public static function row($id, array $result) {
        $row = array('status' => '', 'label' => '', 'description' => '', 'actions' => '');
        foreach (array_keys($row) as $field) {
            $row[$field] = self::text(isset($result[$field]) ? (string) $result[$field] : '');
        }
        return array('id' => $id) + $row;
    }

    /** Stored web records, with memory peaks named rather than positional. @return array */
    public static function records() {
        $records = array();
        foreach (array('seoprostack_php_sapi', 'seoprostack_hosting_object_cache', 'seoprostack_hosting_memory', 'seoprostack_hosting_opcache') as $name) {
            $records[$name] = self::record(get_option($name, null));
        }
        $records['seoprostack_hosting_memory'] = self::memory($records['seoprostack_hosting_memory']);
        return $records;
    }

    /** @param mixed $stored Daily memory peak pairs. @return mixed */
    private static function memory($stored) {
        if (!is_array($stored)) {
            return $stored;
        }
        foreach ($stored as $day => $kinds) {
            if (!is_array($kinds)) {
                continue;
            }
            foreach ($kinds as $kind => $pair) {
                if (is_array($pair) && isset($pair[0], $pair[1])) {
                    $stored[$day][$kind] = array('peak' => $pair[0], 'limit' => $pair[1], 'time' => isset($pair[2]) ? gmdate('Y-m-d\TH:i:s\Z', (int) $pair[2]) : null);
                }
            }
        }
        return $stored;
    }

    /** @param string $format Output format. @param array $rows Results. @param array $records Web records. */
    public static function render($format, array $rows, array $records) {
        if ('json' === $format) {
            WP_CLI::line((string) wp_json_encode(array('tests' => $rows, 'records' => $records)));
        } else {
            WP_CLI\Utils\format_items('table', $rows, array('id', 'status', 'label'));
            foreach ($rows as $row) {
                if ('good' !== $row['status']) {
                    WP_CLI::line($row['id'] . ': ' . $row['description'] . ' ' . $row['actions']);
                }
            }
            WP_CLI::line('Web-request records (UTC; null means none recorded):');
            WP_CLI::line((string) wp_json_encode($records));
        }
    }

    /** @param string $html HTML text. @return string */
    private static function text($html) {
        $html = (string) preg_replace('/<\/(?:p|li|div|pre)>|<br\s*\/?\s*>/i', "\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace(array(ABSPATH, wp_normalize_path(ABSPATH), untrailingslashit(ABSPATH)), '[site]/', $text));
    }

    /** @param mixed $value Stored data. @return mixed */
    private static function record($value) {
        if (is_array($value)) {
            foreach ($value as $key => $part) {
                $value[$key] = in_array($key, array('checked', 'time', 'start_time', 'last_restart_time'), true) && is_numeric($part) && $part > 0
                    ? gmdate('Y-m-d\TH:i:s\Z', (int) $part) : self::record($part);
            }
        } elseif (is_string($value)) {
            $value = self::text($value);
        }
        return $value;
    }
}
