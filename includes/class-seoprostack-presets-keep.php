<?php
/**
 * Keep recommended settings: apply plugin presets and SEO Pro Stack's own
 * changed defaults on their own, and again after later releases change
 * them, while settings someone changed by hand stay as they set them.
 *
 * Runs in WP-Cron when Plugin presets and its Keep recommended settings
 * are on (Plugins tab; networks can apply them to every site from Network
 * Admin): a minute after it is switched on, after each SEO Pro Stack
 * update, after a plugin with a preset is activated, and daily.
 *
 * Presets: for every active plugin with a preset, the settings that differ
 * are applied through SEOProStack_Presets::write(), so each plugin's own
 * save code, the undo copy and seoprostack_plugin_preset_changed still
 * run. The value written to each setting is remembered (KEPT): a setting
 * whose stored value no longer matches it was changed by hand (or undone)
 * and is left alone from then on; one still as written gets a preset's
 * new value from a later release. Settings a preset lists as `manual`
 * (what visitors see, such as a chatbot or an accessibility button) stay
 * for Apply preset.
 *
 * SEO Pro Stack's own settings: SEOProStack_Defaults_Keep.
 *
 * WP-CLI: `wp seoprostack presets keep [--dry-run]`.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-defaults-keep.php';

final class SEOProStack_Presets_Keep {

    /** WP-Cron hook for a run soon after a change. */
    const HOOK = 'seoprostack_keep_settings';

    /** WP-Cron hook for the daily run. */
    const DAILY = 'seoprostack_keep_settings_daily';

    /** Plugin folder => setting path => value last written. Not autoloaded. */
    const KEPT = 'seoprostack_presets_kept';

    /** Seconds before a run after a change. */
    const DELAY = 60;

    /**
     * Register hooks.
     */
    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action(self::DAILY, array(__CLASS__, 'run'));
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        if (!self::enabled()) {
            return;
        }
        add_action('seoprostack_page_cache_purge', array(__CLASS__, 'soon'));
        add_action('activated_plugin', array(__CLASS__, 'activated'));
        if ((is_admin() || wp_doing_cron()) && !wp_next_scheduled(self::DAILY)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY);
        }
    }

    /**
     * Whether Plugin presets and Keep recommended settings are on.
     *
     * @return bool
     */
    public static function enabled() {
        return self::is_on(SEOProStack_Settings::all());
    }

    /**
     * Whether settings have Plugin presets and Keep recommended settings on.
     *
     * @param array $settings Settings, as features read them.
     * @return bool
     */
    private static function is_on(array $settings) {
        return !empty($settings[SEOProStack_Plugin_Presets::KEY]) && !empty($settings[SEOProStack_Plugin_Presets::KEEP]);
    }

    /**
     * Run a minute after Keep recommended settings is switched on; stop
     * the daily run when it is switched off.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        $was = self::is_on((array) $old);
        $now = self::is_on((array) $new);
        if ($now && !$was) {
            self::soon();
        } elseif ($was && !$now) {
            self::deactivate();
        }
    }

    /**
     * Run a minute from now (after an update or a change).
     */
    public static function soon() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + self::DELAY, self::HOOK);
        }
    }

    /**
     * A plugin with a preset was activated: apply it once its own set-up ran.
     *
     * @param string $file Plugin file.
     */
    public static function activated($file) {
        if (SEOProStack_Presets::get(SEOProStack_Presets::slug_of((string) $file))) {
            self::soon();
        }
    }

    /**
     * Plugin deactivated: no runs left scheduled.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::DAILY);
    }

    /**
     * Apply what changed. Waits for a request that loads every plugin, so
     * each plugin's own save code runs.
     */
    public static function run() {
        if (!self::enabled()) {
            return;
        }
        if (SEOProStack_Plugin_Loader::is_filtered()) {
            self::soon();
            return;
        }
        self::presets(false);
        SEOProStack_Defaults_Keep::run(false);
    }

    /**
     * Keep every active plugin's preset.
     *
     * @param bool $dry Only list what would change.
     * @return array<string,string[]|WP_Error> Plugin folder => paths changed (or that would be), or the error.
     */
    public static function presets($dry) {
        $changed = array();
        foreach (self::active() as $slug) {
            $paths = self::keep($slug, $dry);
            if (is_wp_error($paths) || $paths) {
                $changed[$slug] = $paths;
            }
        }
        return $changed;
    }

    /**
     * Folders of the active plugins (here or network-wide, loaded on this
     * request or not) that have a preset.
     *
     * @return string[]
     */
    private static function active() {
        $files = SEOProStack_Plugin_Loader::stored_active_plugins();
        if (is_multisite()) {
            $files = array_merge($files, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $slugs = array_unique(array_map(array('SEOProStack_Presets', 'slug_of'), $files));
        return array_values(array_intersect($slugs, array_keys(SEOProStack_Presets::all())));
    }

    /**
     * Apply one plugin's preset where its settings are still as last
     * written, and remember what each is now.
     *
     * @param string $slug Plugin folder.
     * @param bool   $dry  Only list what would change.
     * @return string[]|WP_Error Paths changed (or that would be).
     */
    private static function keep($slug, $dry) {
        $kept = self::kept();
        list($apply, $record) = self::plan($slug, isset($kept[$slug]) && is_array($kept[$slug]) ? $kept[$slug] : array());
        if ($dry) {
            return $apply;
        }
        $result = $apply ? SEOProStack_Presets::write($slug, 'options', $apply) : 0;
        if (is_wp_error($result)) {
            return $result;
        }
        $kept[$slug] = $record;
        update_option(self::KEPT, $kept, false);
        return $apply;
    }

    /**
     * The settings of one preset to apply, and what each will be once they are.
     *
     * @param string $slug Plugin folder.
     * @param array  $mine Path => value last written.
     * @return array{0:string[],1:array} Paths to apply; path => value to remember.
     */
    private static function plan($slug, array $mine) {
        $preset = SEOProStack_Presets::get($slug);
        $apply  = array();
        $record = array();
        foreach (SEOProStack_Presets::paths($slug) as $path => $values) {
            $path = (string) $path;
            if (self::manual($preset, $path)) {
                continue;
            }
            if (array_key_exists($path, $mine) && !SEOProStack_Presets::same($mine[$path], $values[0])) {
                $record[$path] = $mine[$path]; // Changed by hand: left alone.
                continue;
            }
            if (!SEOProStack_Presets::same($values[0], $values[1])) {
                $apply[] = $path;
            }
            $record[$path] = $values[1];
        }
        return array($apply, $record);
    }

    /**
     * Values last written, by plugin folder and setting path.
     *
     * @return array
     */
    private static function kept() {
        $kept = get_option(self::KEPT, array());
        return is_array($kept) ? $kept : array();
    }

    /**
     * Whether a preset leaves a setting for Apply preset.
     *
     * @param array|null $preset Preset.
     * @param string     $path   Setting path.
     * @return bool
     */
    private static function manual($preset, $path) {
        foreach (empty($preset['manual']) ? array() : (array) $preset['manual'] as $manual) {
            if ($path === $manual || 0 === strpos($path, $manual . '.')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run now, for `wp seoprostack presets keep`, whether or not Keep
     * recommended settings is on, and say what changed.
     *
     * @param bool $dry Only list what would change.
     * @return string[] One line per plugin, and per SEO Pro Stack setting.
     */
    public static function lines($dry) {
        $lines = array();
        foreach (self::presets($dry) as $slug => $paths) {
            $lines[] = $slug . ': ' . (is_wp_error($paths) ? 'not saved, ' . $paths->get_error_message() : implode(', ', $paths));
        }
        foreach (SEOProStack_Defaults_Keep::run($dry) as $key => $value) {
            $lines[] = 'seoprostack: ' . $key . ' = ' . wp_json_encode($value);
        }
        return $lines;
    }
}
