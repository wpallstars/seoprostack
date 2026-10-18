<?php
/**
 * File and account integrity checks.
 *
 * Once a day, the site (the main site, on a network) checks itself, read-only
 * and in parts of at most 20 seconds:
 *
 * 1. WordPress's own files against the checksums WordPress.org publishes
 *    for this version (get_core_checksums()), and extra PHP files in
 *    wp-admin/ and wp-includes/.
 * 2. Plugins from WordPress.org against the checksums WP-CLI's
 *    `wp plugin verify-checksums` reads (downloads.wordpress.org/plugin-checksums/).
 * 3. Other plugins (premium, GitHub-hosted) against the hashes kept when
 *    each was installed, updated or first seen.
 * 4. PHP files in the uploads folder, and administrators added recently.
 *
 * Findings show in Tools → Site Health and `wp seoprostack health` (which
 * reads the last results and never starts a check). New findings are emailed
 * once to the administration address, by path or user name only. Nothing is
 * changed, quarantined or deleted.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.56
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Integrity_Checks extends SEOProStack_Feature {

    const KEY = 'integrity_checks';

    /** Email new findings. */
    const EMAIL_KEY = 'integrity_email';

    /** Daily event. */
    const HOOK = 'seoprostack_integrity_checks';

    /** The rest of a check that reached its time limit. */
    const MORE = 'seoprostack_integrity_more';

    /** The last complete check (not autoloaded). */
    const RESULTS = 'seoprostack_integrity_results';

    /** The check in progress: where it got to (not autoloaded). */
    const STATE = 'seoprostack_integrity_state';

    /** Administrators, with when each was first seen (0: before the first check). */
    const ADMINS = 'seoprostack_integrity_admins';

    /** Taken while a part runs, so two cannot overlap. */
    const LOCK = 'seoprostack_integrity_lock';

    /** Per-plugin record of file sizes, times and hashes, + md5(plugin file). */
    const RECORD = 'seoprostack_integrity_p_';

    /** The Hello Dolly WordPress ships, checked with core. */
    const HELLO = 'wp-content/plugins/hello.php';

    /** Transient: WordPress.org's core checksums for this version. */
    const CORE_SUMS = 'seoprostack_integrity_core';

    /** Transient prefix: WordPress.org's checksums for a plugin version. */
    const PLUGIN_SUMS = 'seoprostack_integrity_sums_';

    /** Seconds per part. */
    const BUDGET = 20;

    /** Larger files are skipped. */
    const MAX_SIZE = 2097152;

    /** Files and folders looked at in one plugin before it is left unchecked. */
    const MAX_PLUGIN_ENTRIES = 100000;

    /** Files and folders looked at in the uploads folder per check. */
    const MAX_UPLOAD_ENTRIES = 300000;

    /** Days an added administrator is reported. */
    const NEW_ADMIN_DAYS = 7;

    /** Patterns from seoprostack_integrity_ignore. @var array<int,string>|null */
    private static $ignore = null;

    /**
     * Settings.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function settings() {
        return array(
            self::KEY       => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'security',
                'label'       => __('File and account integrity checks', 'seoprostack'),
                'description' => __('Once a day, compare WordPress’s files and the plugins’ code with WordPress.org’s checksums, or with a record kept when each other plugin was installed or updated, and look for PHP files in the uploads folder and new administrators. Findings show in Tools → Site Health. Read-only: nothing is changed or deleted. On a network, the main site checks for all sites.', 'seoprostack'),
            ),
            self::EMAIL_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Email new findings', 'seoprostack'),
                'description' => __('Email the site’s administration address when a check finds something new, naming files and user names only.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        $on = self::enabled() && is_main_site();
        if ($on) {
            add_filter('site_status_tests', array(__CLASS__, 'tests'));
        }
        // The health audit reads the last results only.
        if (defined('WP_CLI') && WP_CLI && class_exists('SEOProStack_Health_CLI') && SEOProStack_Health_CLI::auditing()) {
            return;
        }
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 1);
        if (is_admin()) {
            // Also when switched off, to take the schedule away.
            add_action('admin_init', array(__CLASS__, 'sync_schedule'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
        }
        if (!$on) {
            return;
        }
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action(self::MORE, array(__CLASS__, 'run'));
        add_action('upgrader_process_complete', array(__CLASS__, 'upgraded'), 10, 2);
    }

    /* ------------------------------------------------------------------
     * Schedule
     * ------------------------------------------------------------------ */

    /**
     * Keep the daily event in step with the switch. The first check starts
     * a minute after switching on.
     */
    public static function sync_schedule() {
        if (self::enabled() && is_main_site()) {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(self::quiet_time(), 'daily', self::HOOK);
                if (!get_option(self::RESULTS) && !wp_next_scheduled(self::MORE)) {
                    wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::MORE);
                }
            }
            return;
        }
        if (wp_next_scheduled(self::HOOK) || wp_next_scheduled(self::MORE)) {
            wp_clear_scheduled_hook(self::HOOK);
            wp_clear_scheduled_hook(self::MORE);
        }
        if (false !== get_option(self::STATE, false)) {
            delete_option(self::STATE);
        }
    }

    /**
     * A setting changed: update the schedule.
     *
     * @param string $key Setting key.
     */
    public static function setting_saved($key) {
        if (0 === strpos((string) $key, 'integrity_')) {
            self::sync_schedule();
        }
    }

    /**
     * 4am tomorrow, site time: few visitors, after Clean the database's 3am.
     *
     * @return int
     */
    private static function quiet_time() {
        try {
            $time = new DateTimeImmutable('tomorrow 04:00', wp_timezone());
            return $time->getTimestamp();
        } catch (Exception $e) {
            return time() + DAY_IN_SECONDS;
        }
    }

    /* ------------------------------------------------------------------
     * Running a check
     * ------------------------------------------------------------------ */

    /**
     * Run the next part of the check: a new one, or the rest of one that
     * reached its time limit. The results are saved when a check completes,
     * so readers never see half of one.
     */
    public static function run() {
        if (!self::lock()) {
            return;
        }
        $start = microtime(true);
        $state = get_option(self::STATE);
        if (!is_array($state) || empty($state['started'])) {
            $state = array(
                'started'   => time(),
                'phase'     => 0,
                'pos'       => null,
                'found'     => array(),
                'unchecked' => array(),
                'records'   => array(),
                'seen'      => 0,
            );
        }
        if (self::advance($state, $start)) {
            self::finish($state);
            delete_option(self::STATE);
            wp_clear_scheduled_hook(self::MORE);
        } else {
            update_option(self::STATE, $state, false);
            if (!wp_next_scheduled(self::MORE)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::MORE);
            }
        }
        delete_option(self::LOCK);
    }

    /**
     * Take the lock; one left by a part that stopped expires in 10 minutes.
     *
     * @return bool
     */
    private static function lock() {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }
        if ((int) get_option(self::LOCK) > time() - 10 * MINUTE_IN_SECONDS) {
            return false;
        }
        update_option(self::LOCK, time(), false);
        return true;
    }

    /**
     * Work through the steps until done or out of time.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether the check is complete.
     */
    private static function advance(array &$state, $start) {
        $steps = array('core', 'core_extra', 'plugins', 'uploads', 'admins');
        while ($state['phase'] < count($steps)) {
            switch ($steps[$state['phase']]) {
                case 'core':
                    $done = self::step_core($state, $start);
                    break;
                case 'core_extra':
                    $done = self::step_core_extra($state, $start);
                    break;
                case 'plugins':
                    $done = self::step_plugins($state, $start);
                    break;
                case 'uploads':
                    $done = self::step_uploads($state, $start);
                    break;
                default:
                    $done = self::step_admins($state);
            }
            if (!$done) {
                return false;
            }
            $state['phase']++;
            $state['pos'] = null;
        }
        return true;
    }

    /**
     * Save a complete check, tidy records of removed plugins and email new
     * findings.
     *
     * @param array<string,mixed> $state Completed check.
     */
    private static function finish(array $state) {
        $before  = get_option(self::RESULTS);
        $before  = is_array($before) ? $before : array();
        $records = array_values(array_unique((array) $state['records']));
        foreach (isset($before['records']) ? (array) $before['records'] : array() as $name) {
            if (!in_array($name, $records, true)) {
                delete_option((string) $name);
            }
        }
        $found = array();
        foreach (array_keys(self::checks()) as $check) {
            $found[$check] = isset($state['found'][$check]) ? array_values((array) $state['found'][$check]) : array();
        }
        // Email only when a finding is new since the last email: not when
        // findings go. One that goes and comes back is new again.
        $keys     = self::finding_keys($found);
        $notified = isset($before['notified']) ? (array) $before['notified'] : array();
        if (array_diff($keys, $notified) && SEOProStack_Settings::get(self::EMAIL_KEY) && !self::email($found)) {
            $keys = $notified; // Try again next time.
        }
        update_option(self::RESULTS, array(
            'time'      => time(),
            'started'   => (int) $state['started'],
            'found'     => $found,
            'unchecked' => (array) $state['unchecked'],
            'notified'  => $keys,
            'records'   => $records,
        ), false);
    }

    /**
     * A short key for each finding, to tell new ones.
     *
     * @param array<string,array<int,mixed>> $found Findings.
     * @return array<int,string>
     */
    private static function finding_keys(array $found) {
        $keys = array();
        foreach ($found as $check => $items) {
            foreach ($items as $item) {
                $keys[] = md5($check . '|' . (is_array($item) && isset($item['user']) ? 'user:' . $item['user'] : (string) $item)); // NOSONAR: a key to notice new findings, not security.
            }
        }
        return $keys;
    }

    /**
     * Note a finding, unless an ignore pattern matches its path.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param string              $check Check.
     * @param string              $path  Path as shown.
     */
    private static function found_path(array &$state, $check, $path) {
        if (null === self::$ignore) {
            /**
             * Paths the integrity checks ignore, as fnmatch() patterns
             * matched against the path as shown in the finding:
             * `wp-includes/…`, `plugins/{folder}/…`, `uploads/…`.
             *
             * @param array<int,string> $patterns Patterns.
             */
            $patterns     = apply_filters('seoprostack_integrity_ignore', array());
            self::$ignore = is_array($patterns) ? array_values(array_filter(array_map('strval', $patterns))) : array();
        }
        foreach (self::$ignore as $pattern) {
            if (fnmatch($pattern, $path)) {
                return;
            }
        }
        $state['found'][$check][] = $path;
    }

    /**
     * Note what could not be checked.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param string              $check Check.
     * @param string              $text  Reason.
     */
    private static function unchecked(array &$state, $check, $text) {
        $state['unchecked'][$check][] = $text;
    }

    /* ------------------------------------------------------------------
     * 1. WordPress core
     * ------------------------------------------------------------------ */

    /**
     * WordPress.org's checksums for this version: code files only, kept for
     * a week. Null when they cannot be fetched.
     *
     * @return array<string,array<int,string>>|null
     */
    private static function core_sums() {
        global $wp_local_package;
        $version = (string) get_bloginfo('version');
        $locale  = isset($wp_local_package) ? (string) $wp_local_package : 'en_US';
        $id      = $version . '|' . $locale;
        $cached  = get_transient(self::CORE_SUMS);
        if (is_array($cached) && isset($cached['id'], $cached['sums']) && $id === $cached['id'] && is_array($cached['sums'])) {
            return $cached['sums'];
        }
        if (!function_exists('get_core_checksums')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        add_filter('http_request_args', array(__CLASS__, 'short_timeout'));
        $sums = get_core_checksums($version, $locale);
        remove_filter('http_request_args', array(__CLASS__, 'short_timeout'));
        if (!is_array($sums) || !$sums) {
            return null;
        }
        $kept = array();
        foreach ($sums as $path => $md5) {
            // Of wp-content, only the Hello Dolly WordPress ships (hello.php), as WP-CLI does.
            if ((0 !== strpos((string) $path, 'wp-content/') || self::HELLO === $path) && self::is_code((string) $path)) {
                $kept[(string) $path] = array_values(array_map('strval', (array) $md5));
            }
        }
        set_transient(self::CORE_SUMS, array('id' => $id, 'sums' => $kept), WEEK_IN_SECONDS);
        return $kept;
    }

    /**
     * Wait at most 10 seconds for WordPress.org.
     *
     * @param array<string,mixed> $args Request arguments.
     * @return array<string,mixed>
     */
    public static function short_timeout($args) {
        $args['timeout'] = isset($args['timeout']) ? min(10, (float) $args['timeout']) : 10;
        return $args;
    }

    /**
     * Changed core code files.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    private static function step_core(array &$state, $start) {
        $sums = self::core_sums();
        if (null === $sums) {
            self::unchecked($state, 'core', __('WordPress.org’s checksums for this version of WordPress could not be fetched.', 'seoprostack'));
            return true;
        }
        $paths = array_keys($sums);
        $from  = (int) $state['pos'];
        $count = count($paths);
        for ($i = $from; $i < $count; $i++) {
            if ($i > $from && 0 === $i % 50 && !self::more_time($start, self::BUDGET)) {
                $state['pos'] = $i;
                return false;
            }
            $file = self::HELLO === $paths[$i] ? untrailingslashit(WP_PLUGIN_DIR) . '/hello.php' : ABSPATH . $paths[$i];
            if (!is_file($file)) {
                continue; // Removed files are not tampering.
            }
            $md5 = md5_file($file);
            if (false !== $md5 && !in_array($md5, $sums[$paths[$i]], true)) {
                self::found_path($state, 'core', $paths[$i]);
            }
        }
        return true;
    }

    /**
     * PHP files in wp-admin/ and wp-includes/ that WordPress does not have.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    private static function step_core_extra(array &$state, $start) {
        $sums = self::core_sums();
        if (null === $sums) {
            return true;
        }
        $queue = is_array($state['pos']) ? $state['pos'] : array('wp-admin', 'wp-includes');
        $first = true;
        while ($queue) {
            if (!$first && !self::more_time($start, self::BUDGET)) {
                $state['pos'] = $queue;
                return false;
            }
            $first = false;
            $dir   = (string) array_shift($queue);
            foreach (self::entries(ABSPATH . $dir) as $name => $is_dir) {
                $path = $dir . '/' . $name;
                if ($is_dir) {
                    $queue[] = $path;
                } elseif (self::is_php($name) && !isset($sums[$path])) {
                    self::found_path($state, 'core', $path);
                }
            }
        }
        return true;
    }

    /* ------------------------------------------------------------------
     * 2 and 3. Plugins
     * ------------------------------------------------------------------ */

    /**
     * Every installed plugin, active or not, one at a time.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    private static function step_plugins(array &$state, $start) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        ksort($plugins);
        $files   = array_keys($plugins);
        $updates = get_site_transient('update_plugins');
        $from    = (int) $state['pos'];
        $count   = count($files);
        for ($i = $from; $i < $count; $i++) {
            if ($i > $from && !self::more_time($start, self::BUDGET)) {
                $state['pos'] = $i;
                return false;
            }
            $file                = (string) $files[$i];
            if ('hello.php' === $file) {
                continue; // WordPress's own copy: checked with core.
            }
            $state['records'][] = self::RECORD . md5($file); // NOSONAR: an option name, not security.
            self::check_plugin($state, $file, $plugins[$file], $updates);
        }
        return true;
    }

    /**
     * Check one plugin against WordPress.org's checksums or its own record.
     * A record follows the files that matched, with their sizes and times,
     * so unchanged files are not hashed again.
     *
     * @param array<string,mixed> $state   Check in progress.
     * @param string              $file    Plugin file.
     * @param array<string,mixed> $data    Plugin headers.
     * @param mixed               $updates The update_plugins transient.
     */
    private static function check_plugin(array &$state, $file, array $data, $updates) {
        list($base, $label, $list) = self::plugin_files($file);
        $name = isset($data['Name']) ? (string) $data['Name'] : $file;
        if (null === $list) {
            /* translators: %s: plugin name */
            self::unchecked($state, 'local', sprintf(__('%s: too many files to check.', 'seoprostack'), $name));
            return;
        }
        $version = isset($data['Version']) ? (string) $data['Version'] : '';
        $option  = self::RECORD . md5($file); // NOSONAR: an option name, not security.
        $record  = get_option($option);
        $slug    = self::wporg_slug($file, $data, $updates);
        if ('' !== $slug) {
            $known  = self::record_files($record, $version, 'wporg');
            $result = self::check_wporg($state, $slug, $version, $list, null === $known ? array() : $known, $label, $base);
            if (false === $result) {
                /* translators: %s: plugin name */
                self::unchecked($state, 'wporg', sprintf(__('%s: WordPress.org’s checksums could not be fetched.', 'seoprostack'), $name));
                return;
            }
            if (is_array($result)) {
                if ($result !== $known) {
                    update_option($option, array('plugin' => $file, 'version' => $version, 'source' => 'wporg', 'files' => $result), false);
                }
                return;
            }
            // No checksums for this version (a development copy): use a record.
        }
        $known = self::record_files($record, $version, 'local');
        if (null === $known) {
            // Installed, updated or changed version outside the updater: a new record.
            update_option($option, array('plugin' => $file, 'version' => $version, 'source' => 'local', 'files' => self::hash_all($base, $list)), false);
            return;
        }
        foreach ($list as $path => $meta) {
            if (!isset($known[$path])) {
                if (self::is_php($path)) {
                    self::found_path($state, 'local', $label . $path);
                }
                continue;
            }
            if ($known[$path][0] === $meta[0] && $known[$path][1] === $meta[1]) {
                continue;
            }
            $sha1 = sha1_file($base . '/' . $path);
            if (false !== $sha1 && $sha1 !== $known[$path][2]) {
                self::found_path($state, 'local', $label . $path);
            }
        }
    }

    /**
     * The files of a record for this version and source, or null.
     *
     * @param mixed  $record  Stored record.
     * @param string $version Installed version.
     * @param string $source  wporg or local.
     * @return array<string,array{0:int,1:int,2:string}>|null
     */
    private static function record_files($record, $version, $source) {
        if (!is_array($record) || !isset($record['version'], $record['source'], $record['files']) || !is_array($record['files'])
            || $version !== $record['version'] || $source !== $record['source']) {
            return null;
        }
        return $record['files'];
    }

    /**
     * A plugin's folder, the prefix its findings show, and its code files.
     *
     * @param string $file Plugin file.
     * @return array{0:string,1:string,2:array<string,array{0:int,1:int}>|null}
     */
    private static function plugin_files($file) {
        $dir = dirname($file);
        if ('.' === $dir) {
            // A single-file plugin in the plugins folder.
            $base = untrailingslashit(WP_PLUGIN_DIR);
            $path = $base . '/' . $file;
            $size = is_file($path) ? filesize($path) : false;
            return array($base, 'plugins/', false === $size ? array() : array($file => array((int) $size, (int) filemtime($path))));
        }
        $base = untrailingslashit(WP_PLUGIN_DIR) . '/' . $dir;
        return array($base, 'plugins/' . $dir . '/', self::code_files($base));
    }

    /**
     * Code files under a folder (not in hidden folders such as .git), with
     * sizes and times. Null when there are too many to look at.
     *
     * @param string $base Folder.
     * @return array<string,array{0:int,1:int}>|null
     */
    private static function code_files($base) {
        $files = array();
        $queue = array('');
        $seen  = 0;
        while ($queue) {
            $rel = (string) array_shift($queue);
            foreach (self::entries('' === $rel ? $base : $base . '/' . $rel) as $name => $is_dir) {
                if (++$seen > self::MAX_PLUGIN_ENTRIES) {
                    return null;
                }
                $path = '' === $rel ? (string) $name : $rel . '/' . $name;
                if ($is_dir) {
                    if ('.' !== substr((string) $name, 0, 1)) {
                        $queue[] = $path;
                    }
                    continue;
                }
                if (!self::is_code((string) $name)) {
                    continue;
                }
                $size = filesize($base . '/' . $path);
                if (false !== $size && $size <= self::MAX_SIZE) {
                    $files[$path] = array((int) $size, (int) filemtime($base . '/' . $path));
                }
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Hash every listed file, for a new record.
     *
     * @param string                           $base Folder.
     * @param array<string,array{0:int,1:int}> $list Files.
     * @return array<string,array{0:int,1:int,2:string}>
     */
    private static function hash_all($base, array $list) {
        $files = array();
        foreach ($list as $path => $meta) {
            $sha1 = sha1_file($base . '/' . $path);
            if (false !== $sha1) {
                $files[$path] = array($meta[0], $meta[1], $sha1);
            }
        }
        return $files;
    }

    /**
     * The WordPress.org slug of a plugin from WordPress.org, or ''. Only
     * plugins WordPress.org's update check names as its own count (w.org/
     * plugins/ IDs), so a premium or GitHub plugin of the same name is
     * never compared with another plugin's checksums.
     *
     * @param string              $file    Plugin file.
     * @param array<string,mixed> $data    Plugin headers.
     * @param mixed               $updates The update_plugins transient.
     * @return string
     */
    private static function wporg_slug($file, array $data, $updates) {
        if (plugin_basename(SEOPROSTACK_FILE) === $file || !is_object($updates)) {
            return ''; // Its GitHub builds differ from the WordPress.org build.
        }
        if (!empty($data['UpdateURI'])) {
            $host = wp_parse_url(esc_url_raw((string) $data['UpdateURI']), PHP_URL_HOST);
            if (!in_array($host, array('w.org', 'wordpress.org'), true)) {
                return '';
            }
        }
        foreach (array('response', 'no_update') as $group) {
            $items = isset($updates->$group) && is_array($updates->$group) ? $updates->$group : array();
            $item  = isset($items[$file]) ? (object) $items[$file] : null;
            if ($item && isset($item->id, $item->slug) && 0 === strpos((string) $item->id, 'w.org/plugins/')) {
                return (string) $item->slug;
            }
        }
        return '';
    }

    /**
     * Compare a WordPress.org plugin's code with its checksums. Files
     * already matched, with the same size and time, are not hashed again,
     * and checksums are fetched only when a file needs them.
     *
     * @param array<string,mixed>                       $state   Check in progress.
     * @param string                                    $slug    WordPress.org slug.
     * @param string                                    $version Version.
     * @param array<string,array{0:int,1:int}>          $list    Code files.
     * @param array<string,array{0:int,1:int,2:string}> $known   Files matched before.
     * @param string                                    $label   Prefix for findings.
     * @param string                                    $base    Folder.
     * @return array<string,array{0:int,1:int,2:string}>|false|null Matched files; false when the checksums
     *                                                              could not be fetched; null when there are none.
     */
    private static function check_wporg(array &$state, $slug, $version, array $list, array $known, $label, $base) {
        $matched = array();
        $pending = array();
        foreach ($list as $path => $meta) {
            if (isset($known[$path]) && $known[$path][0] === $meta[0] && $known[$path][1] === $meta[1]) {
                $matched[$path] = $known[$path];
            } else {
                $pending[$path] = $meta;
            }
        }
        if (!$pending) {
            return $matched;
        }
        $sums = self::plugin_sums($slug, $version);
        if (!is_array($sums)) {
            return $sums;
        }
        foreach ($pending as $path => $meta) {
            if (!isset($sums[$path])) {
                if (self::is_php($path)) {
                    self::found_path($state, 'wporg', $label . $path);
                }
                continue;
            }
            $md5 = md5_file($base . '/' . $path);
            if (false !== $md5 && in_array($md5, $sums[$path], true)) {
                $matched[$path] = array($meta[0], $meta[1], $md5);
            } else {
                self::found_path($state, 'wporg', $label . $path);
            }
        }
        ksort($matched);
        return $matched;
    }

    /**
     * WordPress.org's md5 checksums of a plugin version's code files, from
     * the source WP-CLI's `wp plugin verify-checksums` uses. Kept for a week
     * (a day when there are none); null when there are none, false when they
     * could not be fetched.
     *
     * @param string $slug    Slug.
     * @param string $version Version.
     * @return array<string,array<int,string>>|false|null
     */
    private static function plugin_sums($slug, $version) {
        $key    = self::PLUGIN_SUMS . md5($slug . '|' . $version); // NOSONAR: a cache or lock key, not security.
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ('none' === $cached) {
            return null;
        }
        $url      = 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($slug) . '/' . rawurlencode($version) . '.json';
        $response = wp_remote_get($url, array('timeout' => 10)); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- cron only, at most once a week per plugin version; a large plugin's list can take seconds.
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if (404 === $code) {
            set_transient($key, 'none', DAY_IN_SECONDS);
            return null;
        }
        $data = 200 === $code ? json_decode(wp_remote_retrieve_body($response), true) : null;
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return false;
        }
        $sums = array();
        foreach ($data['files'] as $path => $hashes) {
            if (is_array($hashes) && isset($hashes['md5']) && self::is_code((string) $path)) {
                $sums[(string) $path] = array_values(array_map('strval', (array) $hashes['md5']));
            }
        }
        set_transient($key, $sums, WEEK_IN_SECONDS);
        return $sums;
    }

    /**
     * A plugin was installed or updated through WordPress: record its files
     * now, so later changes are found. Plugins from WordPress.org are
     * checked against its checksums instead.
     *
     * @param mixed $upgrader Upgrader.
     * @param mixed $extra    What it did.
     */
    public static function upgraded($upgrader, $extra) {
        if (!is_array($extra) || !isset($extra['type']) || 'plugin' !== $extra['type']) {
            return;
        }
        $files = array();
        if (!empty($extra['plugins']) && is_array($extra['plugins'])) {
            $files = $extra['plugins'];
        } elseif (!empty($extra['plugin'])) {
            $files = array($extra['plugin']);
        } elseif (is_object($upgrader) && method_exists($upgrader, 'plugin_info')) {
            $info  = $upgrader->plugin_info();
            $files = $info ? array($info) : array();
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $updates = get_site_transient('update_plugins');
        $start   = microtime(true);
        foreach ($files as $file) {
            $file   = (string) $file;
            $option = self::RECORD . md5($file); // NOSONAR: an option name, not security.
            $path   = untrailingslashit(WP_PLUGIN_DIR) . '/' . $file;
            // Out of time, or not a local record: the next check makes it.
            if (!is_file($path) || !self::more_time($start, 10)) {
                delete_option($option);
                continue;
            }
            $data = get_plugin_data($path, false, false);
            list($base, , $list) = self::plugin_files($file);
            if (null === $list || '' !== self::wporg_slug($file, $data, $updates)) {
                delete_option($option);
                continue;
            }
            update_option($option, array('plugin' => $file, 'version' => (string) $data['Version'], 'source' => 'local', 'files' => self::hash_all($base, $list)), false);
        }
    }

    /* ------------------------------------------------------------------
     * 4. Uploaded PHP and new administrators
     * ------------------------------------------------------------------ */

    /**
     * PHP files anywhere in the uploads folder, folder by folder.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    private static function step_uploads(array &$state, $start) {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir']) || !is_dir($uploads['basedir'])) {
            return true;
        }
        $base  = untrailingslashit($uploads['basedir']);
        $queue = is_array($state['pos']) ? $state['pos'] : array('');
        $first = true;
        while ($queue) {
            if (!$first && !self::more_time($start, self::BUDGET)) {
                $state['pos'] = $queue;
                return false;
            }
            $first = false;
            $rel   = (string) array_shift($queue);
            foreach (self::entries('' === $rel ? $base : $base . '/' . $rel) as $name => $is_dir) {
                if (++$state['seen'] > self::MAX_UPLOAD_ENTRIES) {
                    /* translators: %s: number of files and folders */
                    self::unchecked($state, 'tamper', sprintf(__('The uploads folder was checked only as far as %s files and folders.', 'seoprostack'), number_format_i18n(self::MAX_UPLOAD_ENTRIES)));
                    return true;
                }
                $path = '' === $rel ? (string) $name : $rel . '/' . $name;
                if ($is_dir) {
                    $queue[] = $path;
                } elseif (self::executable($base . '/' . $path, (string) $name)) {
                    self::found_path($state, 'tamper', 'uploads/' . $path);
                }
            }
        }
        return true;
    }

    /**
     * Whether an uploaded file is PHP: by extension, or a .cache file with
     * PHP in it. Files with nothing but comments or exit (the "silence is
     * golden" index.php plugins add) do not count.
     *
     * @param string $file Path.
     * @param string $name File name.
     * @return bool
     */
    private static function executable($file, $name) {
        if ('cache' === strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            $head = (string) file_get_contents($file, false, null, 0, 4096); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file's start.
            return false !== stripos($head, '<?php') || false !== strpos($head, '<?=');
        }
        return self::is_php($name) && !self::harmless($file);
    }

    /**
     * Whether a small PHP file holds only comments, whitespace and exit.
     *
     * @param string $file Path.
     * @return bool
     */
    private static function harmless($file) {
        $size = filesize($file);
        if (false === $size || $size > 2048 || !function_exists('token_get_all')) {
            return false;
        }
        $quiet = array(T_OPEN_TAG, T_CLOSE_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_EXIT);
        foreach (token_get_all((string) file_get_contents($file)) as $token) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local file, read, never run.
            if (is_array($token)) {
                if (in_array($token[0], $quiet, true) || (T_INLINE_HTML === $token[0] && '' === trim($token[1]))) {
                    continue;
                }
                return false;
            }
            if (!in_array($token, array(';', '(', ')'), true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Administrators first seen in the last 7 days: added, or given the role.
     * The first check records those there already.
     *
     * @param array<string,mixed> $state Check in progress.
     * @return bool
     */
    private static function step_admins(array &$state) {
        $known  = get_option(self::ADMINS);
        $now    = time();
        $record = array();
        foreach (self::administrators() as $id => $login) {
            if (is_array($known) && isset($known[$id])) {
                $record[$id] = (int) $known[$id];
            } else {
                $record[$id] = is_array($known) ? $now : 0;
            }
            if ($record[$id] > $now - self::NEW_ADMIN_DAYS * DAY_IN_SECONDS) {
                $state['found']['tamper'][] = array('user' => $login, 'since' => $record[$id]);
            }
        }
        update_option(self::ADMINS, $record, false);
        return true;
    }

    /**
     * Administrators of this site, and on a network its super admins.
     *
     * @return array<int,string> ID => user name
     */
    private static function administrators() {
        $admins = array();
        foreach (get_users(array('role' => 'administrator', 'fields' => array('ID', 'user_login'), 'number' => 1000)) as $user) {
            $admins[(int) $user->ID] = (string) $user->user_login;
        }
        if (is_multisite()) {
            foreach (get_super_admins() as $login) {
                $user = get_user_by('login', $login);
                if ($user) {
                    $admins[(int) $user->ID] = (string) $login;
                }
            }
        }
        ksort($admins);
        return $admins;
    }

    /* ------------------------------------------------------------------
     * Files
     * ------------------------------------------------------------------ */

    /**
     * A folder's entries: name => whether a folder. Linked folders are left
     * out, so a loop cannot trap the check.
     *
     * @param string $dir Folder.
     * @return array<string,bool>
     */
    private static function entries($dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            return array();
        }
        $names = scandir($dir);
        $out   = array();
        foreach (is_array($names) ? $names : array() as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                if (!is_link($path)) {
                    $out[(string) $name] = true;
                }
                continue;
            }
            $out[(string) $name] = false;
        }
        return $out;
    }

    /**
     * Whether a file holds code worth checking.
     *
     * @param string $path File.
     * @return bool
     */
    private static function is_code($path) {
        return '.htaccess' === basename($path) || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('php', 'phtml', 'phar', 'js'), true);
    }

    /**
     * Whether a server may run a file as PHP.
     *
     * @param string $path File.
     * @return bool
     */
    private static function is_php($path) {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('php', 'phtml', 'phar', 'pht', 'php3', 'php4', 'php5', 'php7', 'php8'), true);
    }

    /* ------------------------------------------------------------------
     * Showing the results
     * ------------------------------------------------------------------ */

    /**
     * The checks: key => label.
     *
     * @return array<string,string>
     */
    private static function checks() {
        return array(
            'core'   => __('WordPress core files', 'seoprostack'),
            'wporg'  => __('Plugins from WordPress.org', 'seoprostack'),
            'local'  => __('Other plugins', 'seoprostack'),
            'tamper' => __('Uploaded PHP and new administrators', 'seoprostack'),
        );
    }

    /**
     * What a check compares.
     *
     * @param string $check Check.
     * @return string
     */
    private static function about($check) {
        switch ($check) {
            case 'core':
                return __('Compares WordPress’s own code files with the checksums WordPress.org publishes for this version, and looks for PHP files in wp-admin and wp-includes that WordPress does not have, where malware can hide as a core file.', 'seoprostack');
            case 'wporg':
                return __('Compares the code of plugins from WordPress.org with the checksums WordPress.org publishes for each version, as WP-CLI’s wp plugin verify-checksums does.', 'seoprostack');
            case 'local':
                return __('Compares the code of other plugins (premium and GitHub-hosted) with the record kept when each was installed, updated or first seen. Updating through WordPress makes a new record, so it cannot tell a harmful update from a good one.', 'seoprostack');
            default:
                /* translators: %d: number of days */
                return sprintf(__('Looks for PHP files in the uploads folder, which should hold only media, and for administrators added in the last %d days.', 'seoprostack'), self::NEW_ADMIN_DAYS);
        }
    }

    /**
     * A finding as text.
     *
     * @param mixed $item Path, or administrator.
     * @return string
     */
    private static function item_text($item) {
        if (is_array($item) && isset($item['user'])) {
            return empty($item['since'])
                /* translators: %s: user name */
                ? sprintf(__('Administrator %s', 'seoprostack'), $item['user'])
                /* translators: 1: user name, 2: date */
                : sprintf(__('Administrator %1$s, first seen %2$s', 'seoprostack'), $item['user'], (string) wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) $item['since']));
        }
        return (string) $item;
    }

    /**
     * Email the findings, by path and user name only.
     *
     * @param array<string,array<int,mixed>> $found Findings.
     * @return bool
     */
    private static function email(array $found) {
        $lines = array();
        foreach (self::checks() as $check => $label) {
            if (empty($found[$check])) {
                continue;
            }
            $lines[] = $label . ':';
            foreach (array_slice($found[$check], 0, 50) as $item) {
                $lines[] = '- ' . self::item_text($item);
            }
            if (count($found[$check]) > 50) {
                /* translators: %d: number of findings */
                $lines[] = sprintf(__('…and %d more', 'seoprostack'), count($found[$check]) - 50);
            }
            $lines[] = '';
        }
        $site    = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] File and account integrity: new findings', 'seoprostack'), $site);
        $message = __('SEO Pro Stack’s daily integrity checks found the following. It changed nothing. Check each one: an unexpected file or administrator can mean someone has broken into the site.', 'seoprostack')
            . "\n\n" . implode("\n", $lines) . "\n" . __('Details in Site Health:', 'seoprostack') . ' ' . admin_url('site-health.php');
        return wp_mail((string) get_option('admin_email'), $subject, $message);
    }

    /**
     * Add the tests: they read the last results only.
     *
     * @param array<string,mixed> $tests Tests.
     * @return array<string,mixed>
     */
    public static function tests($tests) {
        foreach (self::checks() as $check => $label) {
            $tests['direct']['seoprostack_integrity_' . $check] = array(
                /* translators: %s: check name */
                'label' => sprintf(__('Integrity: %s', 'seoprostack'), $label),
                'test'  => array(__CLASS__, 'test_' . $check),
            );
        }
        return $tests;
    }

    /** @return array<string,mixed> */
    public static function test_core() {
        return self::result('core');
    }

    /** @return array<string,mixed> */
    public static function test_wporg() {
        return self::result('wporg');
    }

    /** @return array<string,mixed> */
    public static function test_local() {
        return self::result('local');
    }

    /** @return array<string,mixed> */
    public static function test_tamper() {
        return self::result('tamper');
    }

    /**
     * A check's Site Health result.
     *
     * @param string $check Check.
     * @return array<string,mixed>
     */
    private static function result($check) {
        $labels  = self::checks();
        $results = get_option(self::RESULTS);
        $about   = '<p>' . esc_html(self::about($check)) . '</p>';
        $answer  = array(
            'test'    => 'seoprostack_integrity_' . $check,
            'badge'   => array('label' => __('Security', 'seoprostack'), 'color' => 'blue'),
            'actions' => '',
        );
        if (!is_array($results) || empty($results['time'])) {
            return $answer + array(
                'status'      => 'recommended',
                /* translators: %s: check name */
                'label'       => sprintf(__('%s: the first integrity check has not finished yet', 'seoprostack'), $labels[$check]),
                'description' => $about . '<p>' . esc_html__('The first check starts a minute after the feature is switched on and runs in parts of up to 20 seconds; then it runs once a day. It needs WP-Cron.', 'seoprostack') . '</p>',
            );
        }
        $when      = (string) wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) $results['time']);
        $found     = isset($results['found'][$check]) ? (array) $results['found'][$check] : array();
        $unchecked = isset($results['unchecked'][$check]) ? (array) $results['unchecked'][$check] : array();
        if ($found) {
            return array_merge($answer, array(
                'status'      => 'critical',
                /* translators: 1: check name, 2: number of findings */
                'label'       => sprintf(_n('%1$s: %2$d finding at the last integrity check', '%1$s: %2$d findings at the last integrity check', count($found), 'seoprostack'), $labels[$check], count($found)),
                'description' => $about . self::list_html($found)
                    /* translators: %s: date and time */
                    . '<p>' . esc_html(sprintf(__('Checked %s. SEO Pro Stack changed nothing. Compare each file with a fresh copy of WordPress or the plugin, and remove what you do not recognise; an unexpected file or administrator can mean someone has broken into the site. A file you expected (a plugin’s own cache, a deliberate edit) can be ignored with the seoprostack_integrity_ignore filter.', 'seoprostack'), $when)) . '</p>',
            ));
        }
        if ($unchecked) {
            return $answer + array(
                'status'      => 'recommended',
                /* translators: %s: check name */
                'label'       => sprintf(__('%s: some of the integrity check could not run', 'seoprostack'), $labels[$check]),
                /* translators: %s: date and time */
                'description' => $about . self::list_html($unchecked) . '<p>' . esc_html(sprintf(__('Checked %s. Nothing else was found.', 'seoprostack'), $when)) . '</p>',
            );
        }
        if ((int) $results['time'] < time() - 3 * DAY_IN_SECONDS) {
            return $answer + array(
                'status'      => 'recommended',
                /* translators: 1: check name, 2: date and time */
                'label'       => sprintf(__('%1$s: the last integrity check was on %2$s', 'seoprostack'), $labels[$check], $when),
                'description' => $about . '<p>' . esc_html__('The check runs once a day from WP-Cron. If it has stopped, check that WP-Cron runs (a real cron job calling wp-cron.php, or visitors when DISABLE_WP_CRON is not set).', 'seoprostack') . '</p>',
            );
        }
        return $answer + array(
            'status'      => 'good',
            /* translators: 1: check name, 2: date and time */
            'label'       => sprintf(__('%1$s: no findings at the last integrity check (%2$s)', 'seoprostack'), $labels[$check], $when),
            'description' => $about,
        );
    }

    /**
     * Findings as a list, at most 50.
     *
     * @param array<int,mixed> $items Findings.
     * @return string
     */
    private static function list_html(array $items) {
        $html = '<ul class="ul-disc">';
        foreach (array_slice($items, 0, 50) as $item) {
            $html .= '<li><code>' . esc_html(self::item_text($item)) . '</code></li>';
        }
        if (count($items) > 50) {
            /* translators: %d: number of findings */
            $html .= '<li>' . esc_html(sprintf(__('…and %d more', 'seoprostack'), count($items) - 50)) . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * The last check, under the setting.
     *
     * @param string              $key   Setting key.
     * @param array<string,mixed> $field Setting.
     */
    public static function panel($key, $field = array()) {
        unset($field);
        if (self::KEY !== $key || !self::switched_on() || !current_user_can('view_site_health_checks')) {
            return;
        }
        echo '<div class="sps-panel-note"><p>';
        if (!is_main_site()) {
            echo esc_html__('On a network, the main site runs the checks for all sites; its Site Health shows the findings.', 'seoprostack');
        } else {
            $results = get_option(self::RESULTS);
            if (!is_array($results) || empty($results['time'])) {
                echo esc_html__('The first check has not finished yet.', 'seoprostack');
            } else {
                $count = 0;
                foreach ((array) $results['found'] as $items) {
                    $count += count((array) $items);
                }
                /* translators: 1: date and time, 2: number of findings */
                echo esc_html(sprintf(_n('Last check: %1$s, %2$d finding.', 'Last check: %1$s, %2$d findings.', $count, 'seoprostack'), (string) wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) $results['time']), $count));
            }
            echo ' <a href="' . esc_url(admin_url('site-health.php')) . '">' . esc_html__('Site Health', 'seoprostack') . '</a>';
        }
        echo '</p></div>';
    }
}
