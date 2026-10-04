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
 *             stored. null means "not stored": the key or option is removed
 *             (an option left as an empty array by that is deleted);
 * - defaults: the plugin's own values for the same settings, in the same
 *             form (null where the plugin stores nothing until a setting is
 *             changed). "Reset to defaults" writes them.
 * - settings: optional, what each setting is called, for the Apply preset
 *             dialog: setting path (option name, then keys, joined with
 *             dots, as in differences()) => label (the plugin's own wording),
 *             description (one short sentence) and values (stored value =>
 *             what it means; "null" for not stored, "true"/"false" for
 *             booleans, "" for an empty string).
 * - cache:    optional groups (group names) and keys (group => key names)
 *             to clear after apply, reset and undo. List keys too for caches
 *             without group flushing. Code Snippets uses its live constants.
 * - when:     optional, to work the preset out for each site: option name =>
 *             condition, or a list of conditions that must all hold. An
 *             option whose conditions fail is left out of options and
 *             defaults on this site. Conditions: single_site,
 *             feature:{key} (an SEO Pro Stack feature is on),
 *             feature:{key}:{item} (and that item of it is chosen),
 *             option:{name} (the option is stored, for plugins that
 *             expect their whole option once it exists),
 *             litespeed_server, and more through the
 *             seoprostack_preset_condition filter. A leading "!" turns a
 *             condition round (!litespeed_server: not a LiteSpeed server).
 * - limits:   optional, for numbers the plugin caps on each site (such as by
 *             plan): setting path => filter (the plugin's own filter for the
 *             most it allows) and max (the value it filters, the plugin's
 *             default). A preset value above the filtered limit is lowered
 *             to it, and the Apply preset dialog says so.
 *
 * Secrets are never stored or changed: option names and keys that look like
 * licence keys, API keys, tokens, passwords or similar are skipped when a
 * preset loads, when it is applied, in the undo copy and when settings are
 * exported. Applying and resetting keep a copy of the settings they change
 * (without secrets), so the last change can be undone.
 *
 * Presets are made with WP-CLI (see SEOProStack_Plugin_Presets::cli_export()
 * and docs/presets.md).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
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
         * @param array<string,array> $presets Plugin folder => preset (name, tested, updated, notes, options, defaults, when).
         */
        $presets = (array) apply_filters('seoprostack_plugin_presets', $presets);

        self::$presets = array();
        foreach ($presets as $slug => $preset) {
            $preset = self::validate(is_array($preset) ? self::for_this_site($preset) : $preset);
            if ($preset && is_string($slug) && '' !== $slug) {
                self::$presets[$slug] = $preset;
            }
        }
        ksort(self::$presets);
        return self::$presets;
    }

    /**
     * A preset without the options whose `when` conditions fail on this site.
     *
     * @param array $preset Preset from its file.
     * @return array
     */
    private static function for_this_site(array $preset) {
        if (!empty($preset['limits']) && is_array($preset['limits'])) {
            foreach ($preset['limits'] as $path => $limit) {
                if (is_string($path) && is_array($limit) && !empty($limit['filter']) && is_string($limit['filter'])) {
                    $preset = self::limit($preset, $path, $limit);
                }
            }
        }
        unset($preset['limits']);
        if (empty($preset['when']) || !is_array($preset['when'])) {
            return $preset;
        }
        foreach ($preset['when'] as $name => $conditions) {
            foreach ((array) $conditions as $condition) {
                if (!self::condition((string) $condition)) {
                    unset($preset['options'][$name], $preset['defaults'][$name]);
                    break;
                }
            }
        }
        unset($preset['when']);
        return $preset;
    }

    /**
     * Lower a numeric preset value to the most the plugin allows on this
     * site (its own filter, such as a plan limit), and say so in the
     * setting's description.
     *
     * @param array  $preset Preset from its file.
     * @param string $path   Setting path (option name, then keys, joined with dots).
     * @param array  $limit  filter: the plugin's filter for the most it allows; max: the value it filters.
     * @return array
     */
    private static function limit(array $preset, $path, array $limit) {
        $keys  = explode('.', $path);
        $value = $preset['options'] ?? null;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $preset;
            }
            $value = $value[$key];
        }
        $most = apply_filters($limit['filter'], isset($limit['max']) ? $limit['max'] : 0); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- reads the other plugin's own limit.
        if (!is_numeric($value) || !is_numeric($most) || (float) $value <= (float) $most) {
            return $preset;
        }
        $most = 0 + $most;
        $slot = &$preset['options'];
        foreach ($keys as $key) {
            $slot = &$slot[$key];
        }
        $slot = is_string($value) ? (string) $most : $most;
        unset($slot);
        if (isset($preset['settings'][$path]) && is_array($preset['settings'][$path])) {
            $about = &$preset['settings'][$path];
            /* translators: 1: the most the plugin allows on this site, 2: the preset's value. */
            $note = sprintf(__('The plugin allows at most %1$s on this site (such as by its plan), so the preset sets %1$s instead of %2$s.', 'seoprostack'), $most, $value);
            $about['description'] = trim((isset($about['description']) ? (string) $about['description'] : '') . ' ' . $note);
            unset($about);
        }
        return $preset;
    }

    /**
     * Whether a preset condition holds on this site.
     *
     * @param string $condition Condition (see the file docblock).
     * @return bool
     */
    private static function condition($condition) {
        if ('!' === substr($condition, 0, 1)) {
            return !self::condition(substr($condition, 1));
        }
        $parts = explode(':', $condition);
        if ('single_site' === $condition) {
            $holds = !is_multisite();
        } elseif ('feature' === $parts[0] && isset($parts[1])) {
            $key   = $parts[1];
            $holds = (bool) SEOProStack_Settings::get($key) && !SEOProStack_Feature::replaced_active($key);
            if ($holds && isset($parts[2])) {
                $holds = in_array($parts[2], (array) SEOProStack_Settings::get($key . '_items'), true);
            }
        } elseif ('option' === $parts[0] && isset($parts[1])) {
            list($exists) = self::read(substr($condition, 7));
            $holds = $exists;
        } else {
            $holds = false;
        }

        /**
         * Filter whether a preset condition holds on this site.
         *
         * @param bool   $holds     Whether it holds (false for conditions SEO Pro Stack does not know).
         * @param string $condition Condition, such as litespeed_server.
         */
        return (bool) apply_filters('seoprostack_preset_condition', $holds, $condition);
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
            'settings' => array(),
            'cache'    => array('groups' => array(), 'keys' => array()),
        );
        if (!empty($preset['cache']) && is_array($preset['cache'])) {
            if (!empty($preset['cache']['groups']) && is_array($preset['cache']['groups'])) {
                foreach ($preset['cache']['groups'] as $group) {
                    if (is_string($group) && '' !== $group) {
                        $clean['cache']['groups'][] = $group;
                    }
                }
                $clean['cache']['groups'] = array_values(array_unique($clean['cache']['groups']));
            }
            if (!empty($preset['cache']['keys']) && is_array($preset['cache']['keys'])) {
                foreach ($preset['cache']['keys'] as $group => $keys) {
                    if (!is_string($group) || '' === $group || !is_array($keys)) {
                        continue;
                    }
                    foreach ($keys as $key) {
                        if ((is_string($key) && '' !== $key) || is_int($key)) {
                            $clean['cache']['keys'][$group][] = $key;
                        }
                    }
                }
            }
        }
        if (!empty($preset['settings']) && is_array($preset['settings'])) {
            foreach ($preset['settings'] as $path => $about) {
                if (!is_string($path) || !is_array($about)) {
                    continue;
                }
                $values = array();
                if (!empty($about['values']) && is_array($about['values'])) {
                    foreach ($about['values'] as $value => $meaning) {
                        if (is_scalar($meaning)) {
                            $values[(string) $value] = (string) $meaning;
                        }
                    }
                }
                $clean['settings'][$path] = array(
                    'label'       => isset($about['label']) && is_scalar($about['label']) ? (string) $about['label'] : '',
                    'description' => isset($about['description']) && is_scalar($about['description']) ? (string) $about['description'] : '',
                    'values'      => $values,
                );
            }
        }
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
        if (self::is_list($wanted)) {
            return self::without_nulls($wanted);
        }
        if (!is_array($current)) {
            // Named keys that are all "not stored" leave nothing to store,
            // so resetting a setting that was never saved stores nothing.
            $value = array();
            foreach ($wanted as $key => $item) {
                $item = self::merge(null, $item);
                if (null !== $item && !self::is_secret($key)) {
                    $value[$key] = $item;
                }
            }
            return $value || !$wanted ? $value : null;
        }
        foreach ($wanted as $key => $value) {
            if (self::is_secret($key)) {
                continue;
            }
            $value = self::merge(array_key_exists($key, $current) ? $current[$key] : null, $value);
            if (null === $value) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
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
        if (is_array($wanted) && !self::is_list($wanted) && (is_array($current) || null === $current)) {
            // Not stored yet counts as empty, so each setting is listed on its own.
            $current = (array) $current;
            foreach ($wanted as $key => $value) {
                if (!self::is_secret($key)) {
                    self::diff_into($diffs, $path . '.' . $key, array_key_exists($key, $current) ? $current[$key] : null, $value);
                }
            }
            return;
        }
        $wanted = is_array($wanted) ? self::merge(null, $wanted) : $wanted;
        if (!self::same($current, $wanted)) {
            $diffs[$path] = array($current, $wanted);
        }
    }

    /**
     * The part of a wanted value that covers the chosen paths. Walks the
     * value as diff_into() does, so paths match differences().
     *
     * @param string $path    Path of the value.
     * @param mixed  $current Stored value.
     * @param mixed  $wanted  Wanted value.
     * @param array  $chosen  Chosen paths (as keys).
     * @return array{0:mixed}|null The wanted part, wrapped; null when nothing is chosen.
     */
    private static function pick($path, $current, $wanted, array $chosen) {
        if (isset($chosen[$path])) {
            return array($wanted);
        }
        if (!is_array($wanted) || self::is_list($wanted) || !(is_array($current) || null === $current)) {
            return null;
        }
        $current = (array) $current;
        $part    = array();
        foreach ($wanted as $key => $value) {
            if (self::is_secret($key)) {
                continue;
            }
            $picked = self::pick($path . '.' . $key, array_key_exists($key, $current) ? $current[$key] : null, $value, $chosen);
            if (null !== $picked) {
                $part[$key] = $picked[0];
            }
        }
        return $part ? array($part) : null;
    }

    /**
     * Write a preset's options (apply) or defaults (reset), keeping a copy
     * of what was there so it can be undone.
     *
     * With $only, just those settings change (paths as differences() names
     * them, such as tutor_option.course_retake_feature); the preset's other
     * settings stay as they are.
     *
     * @param string        $slug Plugin folder.
     * @param string        $set  options or defaults.
     * @param string[]|null $only Paths of the settings to change; null for all.
     * @return int|WP_Error Number of settings changed.
     */
    public static function write($slug, $set = 'options', $only = null) {
        $error = apply_filters('seoprostack_preset_write_check', null, $slug);
        if (is_wp_error($error)) {
            return $error;
        }
        $preset = self::get($slug);
        if (!$preset) {
            return new WP_Error('seoprostack_no_preset', __('There is no preset for this plugin.', 'seoprostack'));
        }
        if (empty($preset[$set])) {
            return new WP_Error('seoprostack_no_defaults', __('This preset does not list the plugin’s defaults.', 'seoprostack'));
        }
        $diffs = self::differences($slug, $set);
        $wants = $preset[$set];
        if (is_array($only)) {
            $diffs = array_intersect_key($diffs, array_flip(array_map('strval', $only)));
            $wants = array();
            foreach ($preset[$set] as $name => $wanted) {
                list(, $current) = self::read($name);
                $picked = self::pick((string) $name, $current, $wanted, $diffs);
                if (null !== $picked) {
                    $wants[$name] = $picked[0];
                }
            }
        }
        $changed = count($diffs);
        if (!$changed) {
            return 0; // Keep the copy from the change that made it so.
        }

        $backup = array();
        foreach ($wants as $name => $wanted) {
            list($exists, $current) = self::read($name);
            if (is_object($current)) {
                continue;
            }
            $backup[$name] = array('existed' => $exists, 'value' => self::strip_secrets($current));
            $value         = self::merge($current, $wanted);
            if (array() === $value && is_array($wanted) && $wanted && !self::is_list($wanted)) {
                $value = null; // Every named setting removed: the plugin's defaults apply.
            }
            $result = apply_filters('seoprostack_preset_store', null, $slug, $name, $value);
            if (is_wp_error($result)) {
                return $result;
            }
            if (true !== $result) {
                self::store($name, $value, $exists, $current);
            }
        }

        $undo        = self::undo_data();
        $undo[$slug] = array('time' => time(), 'set' => $set, 'options' => $backup);
        update_option(self::UNDO, $undo, false);
        self::clear_cache($slug);
        self::changed($slug, array_keys($backup));
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
        $error = apply_filters('seoprostack_preset_write_check', null, $slug);
        if (is_wp_error($error)) {
            return $error;
        }
        $undo = self::undo_data();
        if (empty($undo[$slug]['options']) || !is_array($undo[$slug]['options'])) {
            return new WP_Error('seoprostack_no_undo', __('There is nothing to undo for this plugin.', 'seoprostack'));
        }
        $count = 0;
        $names = array();
        foreach ($undo[$slug]['options'] as $name => $saved) {
            if (!is_string($name) || self::is_secret($name) || !is_array($saved)) {
                continue;
            }
            list($exists, $current) = self::read($name);
            $value  = empty($saved['existed']) || !isset($saved['value']) ? null : $saved['value'];
            $result = apply_filters('seoprostack_preset_store', null, $slug, $name, $value);
            if (is_wp_error($result)) {
                return $result; // Keep the undo copy until the native save succeeds.
            }
            if (true !== $result) {
                self::store($name, $value, $exists, $current);
            }
            $names[] = $name;
            $count++;
        }
        unset($undo[$slug]);
        if ($undo) {
            update_option(self::UNDO, $undo, false);
        } else {
            delete_option(self::UNDO);
        }
        if ($count) {
            self::clear_cache($slug);
            self::changed($slug, $names);
        }
        return $count;
    }

    /**
     * Tell the plugin's own code its settings changed, for plugins that do
     * more on save than store them (LiteSpeed Cache writes .htaccess and
     * purges, see SEOProStack_Litespeed::save_through_plugin(); WP-Optimize
     * writes advanced-cache.php and WP_CACHE, see
     * SEOProStack_WP_Optimize::save_through_plugin()).
     *
     * @param string   $slug  Plugin folder.
     * @param string[] $names Option names written.
     */
    private static function changed($slug, array $names) {
        /**
         * Fires after a preset is applied, reset or undone.
         *
         * @param string   $slug  Plugin folder.
         * @param string[] $names Option names written.
         */
        do_action('seoprostack_plugin_preset_changed', $slug, $names);
    }

    /**
     * Clear only the preset's named caches, never the site's entire cache.
     *
     * Code Snippets versions its group. Resolve its current constants rather
     * than only clearing the group from the version the preset was tested on.
     * Its settings save handler deletes this key; no version bump is needed.
     *
     * @param string $slug Plugin folder.
     */
    private static function clear_cache($slug) {
        $preset = self::get($slug);
        if (!$preset) {
            return;
        }
        $cache = $preset['cache'];
        if ('code-snippets' === $slug && defined('Code_Snippets\\CACHE_GROUP') && defined('Code_Snippets\\Settings\\CACHE_KEY')) {
            $group = constant('Code_Snippets\\CACHE_GROUP');
            $cache = array(
                'groups' => array($group),
                'keys'   => array($group => array(constant('Code_Snippets\\Settings\\CACHE_KEY'))),
            );
        }
        $can_flush = function_exists('wp_cache_flush_group') && function_exists('wp_cache_supports') && wp_cache_supports('flush_group');
        // Redis Object Cache otherwise falls back to flushing the whole site.
        if (defined('WP_REDIS_DISABLE_GROUP_FLUSH') && WP_REDIS_DISABLE_GROUP_FLUSH) {
            $can_flush = false;
        }
        $flushed   = array();
        if ($can_flush) {
            foreach ($cache['groups'] as $group) {
                if (wp_cache_flush_group($group)) {
                    $flushed[$group] = true;
                }
            }
        }
        foreach ($cache['keys'] as $group => $keys) {
            if (isset($flushed[$group])) {
                continue;
            }
            foreach ($keys as $key) {
                wp_cache_delete($key, $group);
            }
        }
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
