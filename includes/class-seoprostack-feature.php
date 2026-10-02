<?php
/**
 * Base class for SEO Pro Stack features.
 *
 * A feature is a self-contained class that:
 * - declares its settings in settings() (the on/off switch first, keyed by KEY,
 *   then any options with 'parent' => KEY);
 * - registers its hooks in boot(), which runs on `init` (priority 0, before
 *   widgets_init) for every registered feature. Disabled features should
 *   return early so they cost nothing;
 * - optionally imports settings from the plugin it replaces in migrate().
 *
 * While a plugin that a setting replaces is active, the setting waits:
 * enabled() is false and that plugin keeps doing the job, so the two never
 * run side by side (two admin bar menus, analytics loaded twice). The
 * settings card says so, with a deactivate link.
 *
 * Register extra features with the `seoprostack_features` filter.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class SEOProStack_Feature {

    /** Setting key of the feature's on/off switch. */
    const KEY = '';

    /**
     * Settings schema entries for this feature.
     *
     * @return array<string,array>
     */
    public static function settings() {
        return array();
    }

    /**
     * Whether the feature is switched on and not waiting for a plugin it
     * replaces to be deactivated.
     *
     * @return bool
     */
    public static function enabled() {
        return static::switched_on() && !self::replaced_active(static::KEY);
    }

    /**
     * Whether the feature's switch is on, even if it is waiting.
     *
     * @return bool
     */
    public static function switched_on() {
        return '' !== static::KEY && (bool) SEOProStack_Settings::get(static::KEY);
    }

    /**
     * Names of the active plugins that a setting replaces.
     *
     * @param string $key Setting key.
     * @return array<string,string> slug => name
     */
    public static function replaced_active($key) {
        $schema = SEOProStack_Settings::schema();
        if (empty($schema[$key]['replaces']) || !is_array($schema[$key]['replaces'])) {
            return array();
        }
        return array_intersect_key($schema[$key]['replaces'], self::active_plugins());
    }

    /**
     * Plugins active on this site or network-wide, keyed by folder name.
     *
     * @return array<string,string> slug => plugin file
     */
    public static function active_plugins() {
        static $active = null;
        if (null === $active) {
            // As stored: a plugin skipped on this screen is still active.
            $files = SEOProStack_Plugin_Loader::stored_active_plugins();
            if (is_multisite()) {
                $files = array_merge($files, array_keys((array) get_site_option('active_sitewide_plugins', array())));
            }
            $active = array();
            foreach ($files as $file) {
                $slug = dirname((string) $file);
                if ('.' !== $slug) {
                    $active[$slug] = (string) $file;
                }
            }
        }
        return $active;
    }

    /**
     * Import settings once, when the stored settings version is older than
     * SEOProStack_Settings::DB_VERSION. Only fill keys that are not stored yet.
     *
     * @param array $options      Stored settings (raw, without defaults).
     * @param int   $from_version Stored settings version before this upgrade.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return $options;
    }

    /**
     * Set a setting during migrate() unless it is already stored.
     * (Named import_setting() so features may keep their own import() methods.)
     *
     * @param array  $options Stored settings.
     * @param string $key     Setting key.
     * @param mixed  $value   Raw value; null skips.
     * @return array
     */
    protected static function import_setting(array $options, $key, $value) {
        $schema = SEOProStack_Settings::schema();
        if (null !== $value && !array_key_exists($key, $options) && isset($schema[$key])) {
            $options[$key] = SEOProStack_Settings::sanitize_value($value, $schema[$key]);
        }
        return $options;
    }

    /**
     * Role options for multi settings.
     *
     * @return array<string,string>
     */
    public static function role_options() {
        $roles = array();
        foreach (wp_roles()->get_names() as $role => $name) {
            $roles[$role] = translate_user_role($name);
        }
        return $roles;
    }

    /**
     * Role options for settings that restrict people, without roles that can
     * manage options (such as Administrator): current_user_in_roles() never
     * matches them, so offering them would only mislead.
     *
     * @return array<string,string>
     */
    public static function restrictable_role_options() {
        $roles = array();
        foreach (wp_roles()->roles as $role => $data) {
            if (empty($data['capabilities']['manage_options'])) {
                $roles[$role] = translate_user_role($data['name']);
            }
        }
        return $roles;
    }

    /**
     * Whether the current user has one of the roles. Users who can manage
     * options are never matched, so admins cannot lock themselves out.
     *
     * @param mixed $roles Role slugs.
     * @return bool
     */
    public static function current_user_in_roles($roles) {
        $user = wp_get_current_user();
        if (!$user->exists() || user_can($user, 'manage_options')) {
            return false;
        }
        return (bool) array_intersect((array) $user->roles, (array) $roles);
    }

    /**
     * Whether a batch that began at $start has time for one more item.
     *
     * The batch keeps to its own budget, and also to PHP's time limit for
     * the whole request: WP-Cron runs every due batch in one request, so two
     * 20-second batches would pass a 30-second limit. Where the host allows
     * it, the limit is restarted for the next item instead.
     *
     * @param float $start  microtime(true) when the batch began.
     * @param int   $budget Seconds the batch may run.
     * @return bool
     */
    public static function more_time($start, $budget) {
        $now = microtime(true);
        if ($now - $start >= $budget) {
            return false;
        }
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) {
            return true;
        }
        // set_time_limit() restarts the count; hosts may disable it.
        if (function_exists('set_time_limit') && @set_time_limit(max($limit, 60))) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions -- may be disabled by the host.
            return true;
        }
        $begun = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $start;
        $used  = $now - $begun;
        // On Linux the limit counts CPU time of every thread, and image
        // libraries use several, so CPU time can run ahead of the clock.
        // getrusage() counts the whole process, which under PHP-FPM serves
        // many requests (thousands of seconds on a test site), so only CPU
        // time since the first call in this request counts, plus the clock
        // time before it.
        $cpu = self::cpu_seconds();
        if (null !== $cpu) {
            if (null === self::$cpu_base) {
                self::$cpu_base = array($cpu, $now - $begun);
            }
            $used = max($used, $cpu - self::$cpu_base[0] + self::$cpu_base[1]);
        }
        return $used < $limit - min(10, $limit / 3);
    }

    /** CPU seconds and request seconds at the first more_time() check. @var array|null */
    private static $cpu_base = null;

    /**
     * CPU seconds this process has used, or null when unknown.
     *
     * @return float|null
     */
    private static function cpu_seconds() {
        if (!function_exists('getrusage')) {
            return null;
        }
        $usage = getrusage();
        if (!is_array($usage) || !isset($usage['ru_utime.tv_sec'], $usage['ru_stime.tv_sec'], $usage['ru_utime.tv_usec'], $usage['ru_stime.tv_usec'])) {
            return null;
        }
        return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
    }

    /**
     * Register hooks.
     */
    abstract public static function boot();
}
