<?php
/**
 * Plugin presets: SEO Pro Stack's preferred settings for other plugins.
 *
 * A preset is a JSON file in presets/, named after the plugin's folder
 * (presets/antispam-bee.json), with:
 * - name:     the plugin's name;
 * - tested:   the plugin version the settings were taken from;
 * - updated:  date of the last change (YYYY-MM-DD);
 * - notes:    why these settings, for people and agents updating the preset;
 * - options:  option name => value to set. Arrays with named keys are
 *             merged into what is stored, so settings the preset does not
 *             name stay as they are; lists and plain values replace what is
 *             stored. null means "not stored": the key or option is removed;
 * - defaults: the plugin's own values for the same settings, in the same
 *             form (null where the plugin stores nothing until a setting is
 *             changed). "Reset to defaults" writes them.
 *
 * Secrets are never stored or changed: option names and keys that look like
 * licence keys, API keys, tokens, passwords or similar are skipped when a
 * preset loads, when it is applied, in the undo copy and when settings are
 * exported. Applying and resetting keep a copy of the settings they change
 * (without secrets), so the last change can be undone.
 *
 * Presets are made with WP-CLI (see SEOProStack_Plugin_Presets::cli_export()
 * and AGENTS.md → Plugin presets).
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Presets {

    /** Option holding the settings to put back, per plugin folder. Not autoloaded. */
    const UNDO = 'seoprostack_plugin_presets_undo';

    /**
     * Names that look like secrets. Matched against option names and array
     * keys. "auth" is not matched inside "author".
     */
    const SECRET = '/licen[cs]e|secret|passw|pwd|token|api[_-]?key|site[_-]?key|private|credential|auth(?!or)|salt|nonce|signature|bearer|webhook|dsn|(^|[^a-z])key([^a-z]|$)|_key$/i';

    /**
     * Loaded presets.
     *
     * @var array<string,array>|null
     */
    private static $presets = null;

    /**
     * Every preset, keyed by plugin folder.
     *
     * @return array<string,array>
     */
    public static function all() {
        if (null !== self::$presets) {
            return self::$presets;
        }
        $presets = array();
        $files   = glob(SEOPROSTACK_DIR . 'presets/*.json');
        foreach (is_array($files) ? $files : array() as $file) {
            $data = wp_json_file_decode($file, array('associative' => true));
            if (is_array($data)) {
                $presets[basename($file, '.json')] = $data;
            }
        }

        /**
         * Filter the plugin presets.
         *
         * @param array<string,array> $presets Plugin folder => preset (name, tested, updated, notes, options, defaults).
         */
        $presets = (array) apply_filters('seoprostack_plugin_presets', $presets);

        self::$presets = array();
        foreach ($presets as $slug => $preset) {
            $preset = self::validate($preset);
            if ($preset && is_string($slug) && '' !== $slug) {
                self::$presets[$slug] = $preset;
            }
        }
        ksort(self::$presets);
        return self::$presets;
    }

    /**
     * A clean preset, or null if it has nothing to set.
     *
     * @param mixed $preset Raw preset.
     * @return array|null
     */
    private static function validate($preset) {
        if (!is_array($preset) || empty($preset['options']) || !is_array($preset['options'])) {
            return null;
        }
        $clean = array(
            'name'     => isset($preset['name']) ? (string) $preset['name'] : '',
            'tested'   => isset($preset['tested']) ? (string) $preset['tested'] : '',
            'updated'  => isset($preset['updated']) ? (string) $preset['updated'] : '',
            'notes'    => isset($preset['notes']) ? (string) $preset['notes'] : '',
            'options'  => array(),
            'defaults' => array(),
        );
        foreach (array('options', 'defaults') as $set) {
            if (empty($preset[$set]) || !is_array($preset[$set])) {
                continue;
            }
            foreach ($preset[$set] as $name => $value) {
                if (is_string($name) && '' !== $name && !self::is_secret($name)) {
                    $clean[$set][$name] = self::strip_secrets($value);
                }
            }
        }
        return $clean['options'] ? $clean : null;
    }

    /**
     * A plugin's preset.
     *
     * @param string $slug Plugin folder.
     * @return array|null
     */
    public static function get($slug) {
        $all = self::all();
        return isset($all[$slug]) ? $all[$slug] : null;
    }

    /**
     * Plugin folder of a plugin file (akismet/akismet.php → akismet;
     * hello.php → hello).
     *
     * @param string $file Plugin file.
     * @return string
     */
    public static function slug_of($file) {
        $dir = dirname((string) $file);
        return '.' === $dir ? basename((string) $file, '.php') : $dir;
    }

    /**
     * Whether an option name or key looks like a secret.
     *
     * @param string|int $name Option name or array key.
     * @return bool
     */
    public static function is_secret($name) {
        return is_string($name) && 1 === preg_match(self::SECRET, $name);
    }

    /**
     * Whether an array is a list (keys 0, 1, 2…), which is replaced as a whole.
     *
     * @param array $value Array.
     * @return bool
     */
    private static function is_list(array $value) {
        return array() === $value || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * A value without secret-looking keys, at any depth.
     *
     * @param mixed  $value   Value.
     * @param array  $skipped Collects the paths left out.
     * @param string $path    Path of $value, for $skipped.
     * @return mixed
     */
    public static function strip_secrets($value, array &$skipped = array(), $path = '') {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $here = '' === $path ? (string) $key : $path . '.' . $key;
            if (self::is_secret($key)) {
                unset($value[$key]);
                $skipped[] = $here;
                continue;
            }
            $value[$key] = self::strip_secrets($item, $skipped, $here);
        }
        return $value;
    }

    /**
     * Copy the secret-looking keys of $old into $new, at any depth, so they
     * survive any change.
     *
     * @param mixed $new New value.
     * @param mixed $old Stored value.
     * @return mixed
     */
    private static function keep_secrets($new, $old) {
        if (!is_array($old)) {
            return $new;
        }
        $secrets = self::secrets_of($old);
        if (null === $secrets) {
            return $new;
        }
        if (!is_array($new)) {
            // Only possible when a whole array setting is replaced by a plain
            // value; the secrets go back in an array of their own.
            return $secrets;
        }
        foreach ($old as $key => $item) {
            if (self::is_secret($key)) {
                $new[$key] = $item;
            } elseif (is_array($item) && isset($new[$key]) && is_array($new[$key])) {
                $new[$key] = self::keep_secrets($new[$key], $item);
            }
        }
        return $new;
    }

    /**
     * Only the secret-looking parts of a value.
     *
     * @param mixed $value Value.
     * @return array|null Null when there are none.
     */
    private static function secrets_of($value) {
        if (!is_array($value)) {
            return null;
        }
        $secrets = array();
        foreach ($value as $key => $item) {
            if (self::is_secret($key)) {
                $secrets[$key] = $item;
            } elseif (is_array($item)) {
                $inner = self::secrets_of($item);
                if (null !== $inner) {
                    $secrets[$key] = $inner;
                }
            }
        }
        return $secrets ? $secrets : null;
    }

    /**
     * Apply wanted settings to a stored value.
     *
     * @param mixed $current Stored value (null when not stored).
     * @param mixed $wanted  Preset value.
     * @return mixed New value; null to remove it.
     */
    private static function merge($current, $wanted) {
        if (null === $wanted) {
            return null;
        }
        if (!is_array($wanted)) {
            return $wanted;
        }
        if (self::is_list($wanted) || !is_array($current)) {
            return self::without_nulls($wanted);
        }
        foreach ($wanted as $key => $value) {
            if (self::is_secret($key)) {
                continue;
            }
            if (null === $value) {
                unset($current[$key]);
                continue;
            }
            $current[$key] = self::merge(array_key_exists($key, $current) ? $current[$key] : null, $value);
        }
        return $current;
    }

    /**
     * An array without null entries (null means "not stored").
     *
     * @param array $value Array.
     * @return array
     */
    private static function without_nulls(array $value) {
        foreach ($value as $key => $item) {
            if (null === $item) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = self::without_nulls($item);
            }
        }
        return $value;
    }

    /**
     * Whether two stored values are the same setting. Plugins store numbers
     * and booleans as strings or numbers, so plain values compare as text.
     *
     * @param mixed $a Value.
     * @param mixed $b Value.
     * @return bool
     */
    private static function same($a, $b) {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $item) {
                if (!array_key_exists($key, $b) || !self::same($item, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        if (is_scalar($a) && is_scalar($b)) {
            return $a === $b || (string) $a === (string) $b;
        }
        return $a === $b;
    }

    /**
     * A stored option, with whether it exists. Objects cannot be presets.
     *
     * @param string $name Option name.
     * @return array{0:bool,1:mixed} Exists, value (null when not stored).
     */
    private static function read($name) {
        $missing = new stdClass();
        $value   = get_option($name, $missing);
        return $value === $missing ? array(false, null) : array(true, $value);
    }

    /**
     * Settings that differ from a preset's options or defaults.
     *
     * @param string $slug Plugin folder.
     * @param string $set  options or defaults.
     * @return array<string,array{0:mixed,1:mixed}> Path => stored, wanted.
     */
    public static function differences($slug, $set = 'options') {
        $preset = self::get($slug);
        $diffs  = array();
        if (!$preset || empty($preset[$set])) {
            return $diffs;
        }
        foreach ($preset[$set] as $name => $wanted) {
            list(, $current) = self::read($name);
            self::diff_into($diffs, $name, $current, $wanted);
        }
        return $diffs;
    }

    /**
     * Collect differences between a stored value and a wanted one.
     *
     * @param array  $diffs   Differences, by path.
     * @param string $path    Path of the value.
     * @param mixed  $current Stored value.
     * @param mixed  $wanted  Wanted value.
     */
    private static function diff_into(array &$diffs, $path, $current, $wanted) {
        if (null === $wanted) {
            if (null !== $current) {
                $diffs[$path] = array($current, null);
            }
            return;
        }
        if (is_array($wanted) && !self::is_list($wanted) && is_array($current)) {
            foreach ($wanted as $key => $value) {
                if (!self::is_secret($key)) {
                    self::diff_into($diffs, $path . '.' . $key, array_key_exists($key, $current) ? $current[$key] : null, $value);
                }
            }
            return;
        }
        $wanted = is_array($wanted) ? self::without_nulls($wanted) : $wanted;
        if (!self::same($current, $wanted)) {
            $diffs[$path] = array($current, $wanted);
        }
    }

    /**
     * Write a preset's options (apply) or defaults (reset), keeping a copy
     * of what was there so it can be undone.
     *
     * @param string $slug Plugin folder.
     * @param string $set  options or defaults.
     * @return int|WP_Error Number of settings changed.
     */
    public static function write($slug, $set = 'options') {
        $preset = self::get($slug);
        if (!$preset) {
            return new WP_Error('seoprostack_no_preset', __('There is no preset for this plugin.', 'seoprostack'));
        }
        if (empty($preset[$set])) {
            return new WP_Error('seoprostack_no_defaults', __('This preset does not list the plugin’s defaults.', 'seoprostack'));
        }
        $changed = count(self::differences($slug, $set));
        if (!$changed) {
            return 0; // Keep the copy from the change that made it so.
        }

        $backup = array();
        foreach ($preset[$set] as $name => $wanted) {
            list($exists, $current) = self::read($name);
            if (is_object($current)) {
                continue;
            }
            $backup[$name] = array('existed' => $exists, 'value' => self::strip_secrets($current));
            self::store($name, self::merge($current, $wanted), $exists, $current);
        }

        $undo        = self::undo_data();
        $undo[$slug] = array('time' => time(), 'set' => $set, 'options' => $backup);
        update_option(self::UNDO, $undo, false);
        return $changed;
    }

    /**
     * Saved copies, by plugin folder.
     *
     * @return array
     */
    private static function undo_data() {
        $undo = get_option(self::UNDO, array());
        return is_array($undo) ? $undo : array();
    }

    /**
     * When the last change to a plugin's settings can be undone.
     *
     * @param string $slug Plugin folder.
     * @return array{time:int,set:string}|null
     */
    public static function undo_info($slug) {
        $undo = self::undo_data();
        if (empty($undo[$slug]['options'])) {
            return null;
        }
        return array('time' => (int) $undo[$slug]['time'], 'set' => (string) $undo[$slug]['set']);
    }

    /**
     * Put back the settings from before the last apply or reset.
     *
     * @param string $slug Plugin folder.
     * @return int|WP_Error Number of options put back.
     */
    public static function undo($slug) {
        $undo = self::undo_data();
        if (empty($undo[$slug]['options']) || !is_array($undo[$slug]['options'])) {
            return new WP_Error('seoprostack_no_undo', __('There is nothing to undo for this plugin.', 'seoprostack'));
        }
        $count = 0;
        foreach ($undo[$slug]['options'] as $name => $saved) {
            if (!is_string($name) || self::is_secret($name) || !is_array($saved)) {
                continue;
            }
            list($exists, $current) = self::read($name);
            self::store($name, empty($saved['existed']) || !isset($saved['value']) ? null : $saved['value'], $exists, $current);
            $count++;
        }
        unset($undo[$slug]);
        if ($undo) {
            update_option(self::UNDO, $undo, false);
        } else {
            delete_option(self::UNDO);
        }
        return $count;
    }

    /**
     * Store an option's new value, keeping the secrets stored now.
     *
     * Some plugins keep their settings in the object cache under the
     * option's own name (Antispam Bee does), which would hide the change on
     * sites with a persistent object cache until it expires; that copy is
     * dropped.
     *
     * @param string $name    Option name.
     * @param mixed  $value   New value; null to remove the option.
     * @param bool   $exists  Whether the option is stored now.
     * @param mixed  $current Stored value.
     */
    private static function store($name, $value, $exists, $current) {
        if (null === $value) {
            $secrets = self::secrets_of($current);
            if (null !== $secrets) {
                update_option($name, $secrets);
            } elseif ($exists) {
                delete_option($name);
            }
        } else {
            update_option($name, self::keep_secrets($value, $current));
        }
        wp_cache_delete($name);
    }

    /**
     * Settings as a preset, without secrets.
     *
     * With $compare (an earlier export, for example from a fresh install),
     * only settings that differ from it are kept, as options, and its values
     * for them become the defaults.
     *
     * @param string[]   $names   Option names.
     * @param array|null $compare Earlier export ({"options": {...}}) or option name => value.
     * @return array{options:array,defaults:array,skipped:string[],warnings:string[]}
     */
    public static function export(array $names, $compare = null) {
        if (is_array($compare) && isset($compare['options']) && is_array($compare['options'])) {
            $compare = $compare['options'];
        }
        $out  = array('options' => array(), 'defaults' => array(), 'skipped' => array(), 'warnings' => array());
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        foreach (array_unique(array_filter(array_map('strval', $names))) as $name) {
            if (self::is_secret($name)) {
                $out['skipped'][] = $name;
                continue;
            }
            list(, $value) = self::read($name);
            if (is_object($value)) {
                $out['warnings'][] = sprintf('%s is stored as an object and cannot be part of a preset.', $name);
                continue;
            }
            $value = self::strip_secrets($value, $out['skipped'], $name);
            self::warn_site_values($out['warnings'], $name, $value, $host);

            if (!is_array($compare)) {
                $out['options'][$name] = $value;
                continue;
            }
            $before = array_key_exists($name, $compare) ? self::strip_secrets($compare[$name]) : null;
            $delta  = self::delta($before, $value);
            if (null !== $delta) {
                $out['options'][$name]  = $delta[0];
                $out['defaults'][$name] = $delta[1];
            }
        }
        return $out;
    }

    /**
     * What changed from one value to another, keeping named keys apart.
     *
     * @param mixed $from Earlier value (null when not stored).
     * @param mixed $to   Current value (null when not stored).
     * @return array{0:mixed,1:mixed}|null New and old values, or null when the same.
     */
    private static function delta($from, $to) {
        if (is_array($from) && is_array($to) && !self::is_list($to) && !self::is_list($from)) {
            $new = array();
            $old = array();
            foreach (array_unique(array_merge(array_keys($from), array_keys($to)), SORT_REGULAR) as $key) {
                if (self::is_secret($key)) {
                    continue;
                }
                $inner = self::delta(array_key_exists($key, $from) ? $from[$key] : null, array_key_exists($key, $to) ? $to[$key] : null);
                if (null !== $inner) {
                    $new[$key] = $inner[0];
                    $old[$key] = $inner[1];
                }
            }
            return $new ? array($new, $old) : null;
        }
        return self::same($from, $to) ? null : array($to, $from);
    }

    /**
     * Note values that belong to this site (its address, email addresses),
     * which a shared preset should not carry.
     *
     * @param string[] $warnings Warnings.
     * @param string   $path     Path of the value.
     * @param mixed    $value    Value.
     * @param string   $host     Site host.
     */
    private static function warn_site_values(array &$warnings, $path, $value, $host) {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::warn_site_values($warnings, $path . '.' . $key, $item, $host);
            }
            return;
        }
        if (!is_string($value)) {
            return;
        }
        if (('' !== $host && false !== stripos($value, $host)) || preg_match('/[^\s@"]+@[^\s@"]+\.[a-z]{2,}/i', $value)) {
            $warnings[] = sprintf('%s holds this site’s address or an email address; check it belongs in a shared preset.', $path);
        }
    }
}
