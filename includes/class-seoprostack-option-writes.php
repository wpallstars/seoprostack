<?php
/**
 * Options saved again on page views.
 *
 * Some plugins save an option on almost every request although only a
 * timestamp in it changes. Each save is a database write and, with an
 * object cache, a rewrite of the cached autoloaded options that every
 * request reads. This helper:
 * - counts option writes on 1 in 20 page views, for Hosting needs;
 * - with Ask before licence checks on, keeps the stored value of the
 *   licence options known to do this, while their licence is valid and
 *   only the timestamp would change (KNOWN).
 *
 * It needs nothing else from SEO Pro Stack, so the must-use file of "Load
 * plugins only where needed" can load it before other plugins, whose own
 * files often save these options while they load. Without that file it
 * starts when SEO Pro Stack loads, after the plugins whose folders sort
 * before it.
 *
 * @package SEOProStack
 * @since 0.12.8
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Option_Writes {

    /** Page views counted: one in this many. */
    const SAMPLE = 20;

    /**
     * Licence options saved again on every request with only a new
     * timestamp (seen with GPL Vault's patches, 2026). Each option:
     * - entry: key, inside the option, of the licence state ('' for the
     *   option itself);
     * - state: field holding the licence state, and valid: its value when
     *   valid (true for any non-empty value); state '' checks nothing;
     * - time: field that changes, or that is added and removed again;
     * - until: field holding when the licence or its cached state expires
     *   ('' when there is none); the stored value is kept only while it is
     *   at least a week away.
     */
    const KNOWN = array(
        // Complianz Pro.
        'cmplz_transients' => array('entry' => 'cmplz_license_status', 'state' => 'value', 'valid' => 'valid', 'time' => 'expires', 'until' => 'expires'),
        // Really Simple Security Pro.
        'rsssl_transients' => array('entry' => 'rsssl_pro_license_status', 'state' => 'value', 'valid' => 'valid', 'time' => 'expires', 'until' => 'expires'),
        // Fluent Forms Pro.
        '_ff_fluentform_pro_license_status_checking' => array('entry' => '', 'state' => 'license', 'valid' => 'valid', 'time' => 'next_timestamp', 'until' => 'next_timestamp'),
        // Tutor LMS Pro.
        'tutor_license_info' => array('entry' => '', 'state' => 'activated', 'valid' => true, 'time' => 'activated_at', 'until' => ''),
        // WP-Optimize Premium: wp55_option_migrated is added, then removed.
        'wp-optimize-premium_updater_options' => array('entry' => '', 'state' => '', 'valid' => '', 'time' => 'wp55_option_migrated', 'until' => ''),
    );

    /**
     * This page view's writes: option => array(count, source), or null
     * when it is not counted.
     *
     * @var array<string,array>|null
     */
    private static $writes = null;

    /** @var bool Whether this request was considered for counting. */
    private static $watching = false;

    /** @var bool Whether the known licence options are kept. */
    private static $keeping = false;

    /*
     * Counting.
     */

    /**
     * Count option writes on 1 in 20 page views, from now on: GET requests
     * outside wp-admin, cron and the command line. Other requests do no work.
     */
    public static function watch() {
        if (self::$watching) {
            return;
        }
        self::$watching = true;
        if ((defined('WP_CLI') && WP_CLI) || wp_doing_cron() || is_admin()
            || !isset($_SERVER['REQUEST_METHOD']) || 'GET' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }
        // wp_rand() is not loaded yet when the must-use file calls this.
        if (1 !== random_int(1, self::SAMPLE)) {
            return;
        }
        self::$writes = array();
        add_action('updated_option', array(__CLASS__, 'note'), 10, 1);
        add_action('added_option', array(__CLASS__, 'note'), 10, 1);
    }

    /**
     * Note a write (updated_option fires only when the value changed).
     *
     * @param string $option Option name.
     */
    public static function note($option) {
        $option = (string) $option;
        if (null === self::$writes || 0 === strpos($option, '_transient_') || 0 === strpos($option, '_site_transient_')) {
            return;
        }
        if (!isset(self::$writes[$option])) {
            self::$writes[$option] = array(0, self::source());
        }
        self::$writes[$option][0]++;
    }

    /**
     * This page view's writes, or null when it is not counted.
     *
     * @return array<string,array>|null option => array(count, source)
     */
    public static function writes() {
        return self::$writes;
    }

    /**
     * The plugin, must-use plugin or theme that saved the option: the code
     * nearest to the write. Empty for WordPress and SEO Pro Stack.
     *
     * @return string type:slug
     */
    private static function source() {
        $roots = array(
            'plugin' => self::path(WP_PLUGIN_DIR),
            'mu'     => self::path(WPMU_PLUGIN_DIR),
            'theme'  => self::path(WP_CONTENT_DIR . '/themes'),
        );
        $own  = self::path(dirname(__DIR__));
        $self = str_replace('\\', '/', __FILE__);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the plugin saving an option, on counted page views only.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (empty($frame['file'])) {
                continue;
            }
            $file = str_replace('\\', '/', $frame['file']);
            if ($file === $self) {
                continue; // This class noting the write.
            }
            if (0 === strpos($file, $own)) {
                return '';
            }
            foreach ($roots as $type => $root) {
                if (0 === strpos($file, $root)) {
                    $rest = substr($file, strlen($root));
                    $cut  = strpos($rest, '/');
                    return $type . ':' . (false !== $cut ? substr($rest, 0, $cut) : $rest);
                }
            }
        }
        return '';
    }

    /**
     * A folder as a path prefix with forward slashes (wp_normalize_path()
     * may not be loaded yet).
     *
     * @param string $dir Folder.
     * @return string
     */
    private static function path($dir) {
        return rtrim(str_replace('\\', '/', (string) $dir), '/') . '/';
    }

    /*
     * Keeping known licence options.
     */

    /**
     * Keep the stored value of the known licence options when only their
     * timestamp would change.
     */
    public static function keep() {
        if (self::$keeping) {
            return;
        }
        self::$keeping = true;
        foreach (array_keys(self::KNOWN) as $option) {
            add_filter('pre_update_option_' . $option, array(__CLASS__, 'keep_stored'), 10, 3);
        }
    }

    /**
     * Whether an option is one of the known licence options.
     *
     * @param string $option Option name.
     * @return bool
     */
    public static function known($option) {
        return isset(self::KNOWN[(string) $option]);
    }

    /**
     * The value to save: the stored one when its licence is valid (and does
     * not expire within a week) and only the timestamp field differs;
     * otherwise the new one. update_option() saves nothing when the value
     * it gets back equals the stored one.
     *
     * @param mixed  $value  New value.
     * @param mixed  $stored Stored value.
     * @param string $option Option name.
     * @return mixed
     */
    public static function keep_stored($value, $stored, $option) {
        if (!self::known($option)) {
            return $value;
        }
        $rule  = self::KNOWN[$option];
        $old   = '' === $rule['entry'] ? $stored : self::field($stored, $rule['entry']);
        $fresh = '' === $rule['entry'] ? $value : self::field($value, $rule['entry']);
        if ((!is_array($old) && !is_object($old)) || (!is_array($fresh) && !is_object($fresh))) {
            return $value;
        }
        if ('' !== $rule['state']) {
            $state = self::field($old, $rule['state']);
            if (true === $rule['valid'] ? empty($state) : $state !== $rule['valid']) {
                return $value;
            }
        }
        if ('' !== $rule['until']) {
            $until = self::field($old, $rule['until']);
            if (!is_numeric($until) || (int) $until < time() + WEEK_IN_SECONDS) {
                return $value;
            }
        }
        // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- compares array or object values.
        if (self::without($old, $rule['time']) != self::without($fresh, $rule['time'])) {
            return $value;
        }
        if ('' === $rule['entry']) {
            return $stored;
        }
        if (!is_array($value)) {
            return $value;
        }
        // Other entries of the option may change; only the licence entry is kept.
        $value[$rule['entry']] = $old;
        // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- compares array values.
        return $value == $stored ? $stored : $value;
    }

    /**
     * A field of an array or object, or null.
     *
     * @param mixed  $value Array or object.
     * @param string $key   Field.
     * @return mixed
     */
    private static function field($value, $key) {
        if (is_array($value)) {
            return array_key_exists($key, $value) ? $value[$key] : null;
        }
        if (is_object($value)) {
            return isset($value->$key) ? $value->$key : null;
        }
        return null;
    }

    /**
     * An array or object without one field.
     *
     * @param array|object $value Value.
     * @param string       $key   Field.
     * @return array|object
     */
    private static function without($value, $key) {
        if (is_object($value)) {
            $value = clone $value;
            unset($value->$key);
            return $value;
        }
        unset($value[$key]);
        return $value;
    }
}
