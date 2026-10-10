<?php
/**
 * Correct update counts.
 *
 * The numbers next to Dashboard > Updates, Plugins and Appearance, and in
 * the admin bar, come from wp_get_update_data(), which counts WordPress's
 * last update check as it was saved. Updates installed outside WordPress's
 * updater (by the host, a deploy, or files copied or deleted) stay in it
 * until the next check, up to 12 hours later, so the menu shows updates that
 * the Updates screen, which checks again when it opens, does not list. With
 * this on, the counts leave out:
 * - plugins that are no longer installed, or whose version changed since
 *   the check and is now the offered one or newer;
 * - themes, the same way;
 * - WordPress, when it was updated since the check to the offered version.
 * Only the numbers change, through core's `wp_get_update_data` filter. What
 * WordPress saved, its checks and the Updates screen are left alone.
 *
 * With Load plugins only where needed, some plugins add their updates only
 * while they are loaded, when the saved check is read (Kadence Blocks
 * through StellarWP Harbor, SliceWP), so screens that skip them would count
 * too few. Requests that load every plugin keep the plugin and theme updates
 * they count (OPTION, not autoloaded, written only when it changes or once
 * an hour), and screens that skip plugins also count those that are not
 * installed yet. That part runs whenever Load plugins only where needed is
 * on, as it keeps that feature's screens right.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Update_Count extends SEOProStack_Feature {

    const KEY = 'update_count';

    /** Updates counted on the last request that loaded every plugin. */
    const OPTION = 'seoprostack_update_counts';

    /** Seconds before kept updates are saved again although unchanged. */
    const REFRESH = 3600;

    /** Seconds kept updates are used for at most. */
    const MAX_AGE = 86400;

    /**
     * Updates counted on this request, kept once at shutdown.
     *
     * @var array{plugins?: array<string,string>, themes?: array<string,string>}
     */
    private static $seen = array();

    /** @var array<string,string|null> Installed plugin versions read on this request; null when not installed. */
    private static $versions = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: safe on any site.
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Correct update counts', 'seoprostack'),
                'description' => __('Leave updates that are already installed out of the numbers next to Updates, Plugins and Appearance. WordPress counts its last update check as it was saved, so updates made by the host, a deploy or a file upload keep showing for up to 12 hours, while the Updates screen lists none.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() && !self::loader_on()) {
            return;
        }
        add_filter('wp_get_update_data', array(__CLASS__, 'update_data'), 10, 2);
    }

    /**
     * Whether Load plugins only where needed is on.
     *
     * @return bool
     */
    private static function loader_on() {
        return class_exists('SEOProStack_Plugin_Loading') && SEOProStack_Plugin_Loading::enabled();
    }

    /**
     * What this request does with kept updates: 'keep' (every plugin
     * loaded), 'use' (plugins skipped) or '' (nothing).
     *
     * @return string
     */
    private static function loader_role() {
        if (!self::loader_on() || !class_exists('SEOProStack_Plugin_Loader')) {
            return '';
        }
        $state = SEOProStack_Plugin_Loader::state();
        if ('filter' === $state['mode']) {
            return 'use';
        }
        // '' means the must-use file did not run; Health Check's
        // troubleshooting mode chooses plugins itself.
        if ('' === $state['mode'] || isset($_COOKIE['wp-health-check-disable-plugins'])) {
            return '';
        }
        return 'keep';
    }

    /**
     * Correct the counts, and rebuild the title of the ones that changed.
     *
     * @param mixed $data   array{counts: array<string,int>, title: string}.
     * @param mixed $titles Titles core built, keyed like the counts.
     * @return mixed
     */
    public static function update_data($data, $titles = array()) {
        if (!is_array($data) || !isset($data['counts']) || !is_array($data['counts'])) {
            return $data;
        }
        $counts  = $data['counts'];
        $titles  = is_array($titles) ? $titles : array();
        $before  = $counts;
        $role    = self::loader_role();
        $correct = self::enabled();

        if (current_user_can('update_plugins')) {
            $counts['plugins'] = count(self::plugin_updates($role, $correct));
        }
        if (current_user_can('update_themes')) {
            $counts['themes'] = count(self::theme_updates($role, $correct));
        }
        if ($correct && !empty($counts['wordpress']) && !self::core_update_wanted()) {
            $counts['wordpress'] = 0;
        }
        if ('keep' === $role && !has_action('shutdown', array(__CLASS__, 'keep'))) {
            add_action('shutdown', array(__CLASS__, 'keep'));
        }
        if ($counts === $before) {
            return $data;
        }

        foreach (array('wordpress', 'plugins', 'themes') as $type) {
            $count = (int) ($counts[$type] ?? 0);
            if ($count === (int) ($before[$type] ?? 0)) {
                continue;
            }
            unset($titles[$type]);
            if ($count) {
                $titles[$type] = self::title($type, $count);
            }
        }
        $counts['total'] = (int) ($counts['wordpress'] ?? 0) + (int) ($counts['plugins'] ?? 0)
            + (int) ($counts['themes'] ?? 0) + (int) ($counts['translations'] ?? 0);
        // Core's order: WordPress, plugins, themes, translations.
        $ordered = array();
        foreach (array('wordpress', 'plugins', 'themes', 'translations') as $type) {
            if (isset($titles[$type])) {
                $ordered[] = $titles[$type];
            }
        }
        $data['counts'] = $counts;
        $data['title']  = $ordered ? esc_attr(implode(', ', $ordered)) : '';
        return $data;
    }

    /**
     * A count's title, worded as core words it.
     *
     * @param string $type  'wordpress', 'plugins' or 'themes'.
     * @param int    $count Count.
     * @return string
     */
    private static function title($type, $count) {
        switch ($type) {
            case 'wordpress':
                /* translators: %d: number of WordPress updates (1) */
                return sprintf(__('%d WordPress Update', 'seoprostack'), $count);
            case 'plugins':
                /* translators: %d: number of plugin updates */
                return sprintf(_n('%d Plugin Update', '%d Plugin Updates', $count, 'seoprostack'), $count);
            default:
                /* translators: %d: number of theme updates */
                return sprintf(_n('%d Theme Update', '%d Theme Updates', $count, 'seoprostack'), $count);
        }
    }

    /**
     * Updates offered in a stored check: item => offered version.
     *
     * @param mixed $current The stored check.
     * @return array<string,string>
     */
    private static function offers($current) {
        $offers = array();
        if (!is_object($current) || empty($current->response) || !is_array($current->response)) {
            return $offers;
        }
        foreach ($current->response as $item => $offer) {
            $offer = (array) $offer;
            $offers[(string) $item] = isset($offer['new_version']) && is_scalar($offer['new_version']) ? (string) $offer['new_version'] : '';
        }
        return $offers;
    }

    /**
     * Versions a stored check was made with: item => version.
     *
     * @param mixed $current The stored check.
     * @return array<string,string>
     */
    private static function checked($current) {
        return is_object($current) && isset($current->checked) && is_array($current->checked) ? array_map('strval', $current->checked) : array();
    }

    /**
     * Whether a stored update is still wanted.
     *
     * @param string|null $installed Installed version, or null if not installed.
     * @param string      $offered   Offered version.
     * @param string|null $checked   Version the check was made with, if any.
     * @return bool
     */
    private static function wanted($installed, $offered, $checked) {
        if (null === $installed) {
            return false;
        }
        if ('' === $offered || $installed === $checked) {
            // Unchanged since the check: count it, as WordPress does.
            return true;
        }
        return version_compare($offered, $installed, '>');
    }

    /**
     * Plugin updates to count: plugin file => offered version.
     *
     * @param string $role    loader_role().
     * @param bool   $correct Whether to leave out installed updates.
     * @return array<string,string>
     */
    private static function plugin_updates($role, $correct) {
        $current = get_site_transient('update_plugins');
        $offers  = self::offers($current);
        $checked = self::checked($current);
        $updates = array();
        foreach ($offers as $file => $offered) {
            if (!$correct || self::wanted(self::plugin_version($file), $offered, $checked[$file] ?? null)) {
                $updates[$file] = $offered;
            }
        }
        if ('keep' === $role) {
            self::$seen['plugins'] = $offers;
        } elseif ('use' === $role) {
            // Added by plugins skipped here: count them until installed.
            foreach (array_diff_key(self::kept('plugins'), $offers) as $file => $offered) {
                if (self::wanted(self::plugin_version($file), $offered, null)) {
                    $updates[$file] = $offered;
                }
            }
        }
        return $updates;
    }

    /**
     * Theme updates to count: stylesheet => offered version.
     *
     * @param string $role    loader_role().
     * @param bool   $correct Whether to leave out installed updates.
     * @return array<string,string>
     */
    private static function theme_updates($role, $correct) {
        $current = get_site_transient('update_themes');
        $offers  = self::offers($current);
        $checked = self::checked($current);
        $updates = array();
        foreach ($offers as $stylesheet => $offered) {
            if (!$correct || self::wanted(self::theme_version($stylesheet), $offered, $checked[$stylesheet] ?? null)) {
                $updates[$stylesheet] = $offered;
            }
        }
        if ('keep' === $role) {
            self::$seen['themes'] = $offers;
        } elseif ('use' === $role) {
            foreach (array_diff_key(self::kept('themes'), $offers) as $stylesheet => $offered) {
                if (self::wanted(self::theme_version($stylesheet), $offered, null)) {
                    $updates[$stylesheet] = $offered;
                }
            }
        }
        return $updates;
    }

    /**
     * Whether the counted WordPress update is still wanted: not when
     * WordPress was updated since the check to the offered version or later.
     *
     * @return bool
     */
    private static function core_update_wanted() {
        $current = get_site_transient('update_core');
        $version = (string) get_bloginfo('version');
        if (!is_object($current) || !isset($current->version_checked) || $version === (string) $current->version_checked
            || !function_exists('get_core_updates')) {
            return true;
        }
        $offers = get_core_updates(array('dismissed' => false));
        $offer  = is_array($offers) && isset($offers[0]) && is_object($offers[0]) ? $offers[0] : null;
        return !$offer || !isset($offer->current) || version_compare((string) $offer->current, $version, '>');
    }

    /**
     * Installed version of a plugin, or null if it is not installed.
     *
     * @param string $file Plugin file, relative to the plugins folder.
     * @return string|null
     */
    private static function plugin_version($file) {
        if (array_key_exists($file, self::$versions)) {
            return self::$versions[$file];
        }
        $version = null;
        // get_plugins() already ran on this request (Plugins screen, Updates).
        $cached = wp_cache_get('plugins', 'plugins');
        if (is_array($cached) && isset($cached[''][$file]['Version'])) {
            $version = (string) $cached[''][$file]['Version'];
        } elseif ('' !== $file && 0 === validate_file($file) && is_file(WP_PLUGIN_DIR . '/' . $file)) {
            $data    = get_file_data(WP_PLUGIN_DIR . '/' . $file, array('Version' => 'Version'), 'plugin');
            $version = (string) $data['Version'];
        }
        self::$versions[$file] = $version;
        return $version;
    }

    /**
     * Installed version of a theme, or null if it is not installed.
     *
     * @param string $stylesheet Theme folder.
     * @return string|null
     */
    private static function theme_version($stylesheet) {
        $theme = wp_get_theme($stylesheet);
        return $theme->exists() ? (string) $theme->get('Version') : null;
    }

    /**
     * Updates kept from a request that loaded every plugin, while they are
     * for the same plugins and recent.
     *
     * @param string $type 'plugins' or 'themes'.
     * @return array<string,string>
     */
    private static function kept($type) {
        $kept = get_option(self::OPTION, array());
        if (!is_array($kept) || !isset($kept['active'], $kept['time'], $kept[$type]) || !is_array($kept[$type])
            || self::fingerprint() !== $kept['active'] || time() - (int) $kept['time'] > self::MAX_AGE) {
            return array();
        }
        return array_map('strval', $kept[$type]);
    }

    /**
     * Fingerprint of the active plugins, so kept updates are used only
     * with the plugins that added them.
     *
     * @return string
     */
    private static function fingerprint() {
        return SEOProStack_Plugin_Loader::fingerprint(SEOProStack_Plugin_Loader::stored_active_plugins());
    }

    /**
     * At shutdown of a request that loaded every plugin: keep the updates it
     * counted, when they changed or are an hour old.
     */
    public static function keep() {
        if (!isset(self::$seen['plugins']) && !isset(self::$seen['themes'])) {
            return;
        }
        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $kept   = array(
            'active'  => self::fingerprint(),
            'time'    => time(),
            'plugins' => self::$seen['plugins'] ?? (array) ($stored['plugins'] ?? array()),
            'themes'  => self::$seen['themes'] ?? (array) ($stored['themes'] ?? array()),
        );
        $same = isset($stored['active'], $stored['time'], $stored['plugins'], $stored['themes'])
            && $stored['active'] === $kept['active'] && $stored['plugins'] === $kept['plugins'] && $stored['themes'] === $kept['themes'];
        if ($same && $kept['time'] - (int) $stored['time'] < self::REFRESH) {
            return;
        }
        update_option(self::OPTION, $kept, false);
    }
}
