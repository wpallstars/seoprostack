<?php
/**
 * Updates from GitHub: the setting for the shared GitHub updater
 * (includes/github-updater/), which adds GitHub releases of SEO Pro Stack
 * and of any other plugin that names its GitHub repository to WordPress's
 * own update check.
 *
 * Only in builds made from GitHub releases. The WordPress.org build leaves
 * this file and the updater out (see SEOProStack_Setup::OPTIONAL_FEATURES
 * and .distignore-wporg), because plugins hosted there may not install or
 * update code from anywhere else.
 *
 * Other plugins may carry a copy of the updater too; the newest copy on the
 * site runs, so this drives it through its filters only:
 *
 * - Off: no plugin is updated from GitHub (`wpallstars_github_updater_enabled`).
 * - "Early updates from GitHub" (`wpallstars_github_updater_early`).
 * - "Auto-update plugins from GitHub", on by default: WordPress auto-updates
 *   the plugins the updater serves (`auto_update_plugin`). This file is in
 *   GitHub builds only, as the update rule in STANDARDS.md asks.
 * - SEOPROSTACK_GITHUB_TOKEN and the `seoprostack_github_token` and
 *   `seoprostack_github_plugins` filters, from before the updater was
 *   shared, still work.
 * - Replaces Git Updater: while Git Updater is active, the updater waits and
 *   Git Updater keeps doing the job; this keeps SEO Pro Stack on its GitHub
 *   releases when asked to, and fixes Git Updater's error cache for it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Github_Updates extends SEOProStack_Feature {

    const KEY   = 'github_updates';
    const EARLY = 'github_early_updates';
    const AUTO  = 'github_auto_updates';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY   => array(
                'type'        => 'bool',
                // On by default at the owner's request: without it, copies
                // installed from GitHub would never hear of a new version.
                'default'     => true,
                'tab'         => 'maintenance',
                'label'       => __('Updates from GitHub', 'seoprostack'),
                'description' => __('New releases of SEO Pro Stack, and of other plugins that name their GitHub repository, show on the Updates screen like any other update. Auto-updates work too. GitHub is asked at most twice a day.', 'seoprostack'),
                'replaces'    => array('git-updater' => 'Git Updater'),
            ),
            self::EARLY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Early updates from GitHub', 'seoprostack'),
                'description' => __('For plugins that are also on WordPress.org: take each version from GitHub as soon as it is released, instead of waiting for WordPress.org.', 'seoprostack'),
            ),
            self::AUTO  => array(
                'type'        => 'bool',
                // On by default at the owner's request: a plugin installed
                // from GitHub whose own auto-update toggle was never turned
                // on falls behind without anyone noticing.
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Auto-update plugins from GitHub', 'seoprostack'),
                'description' => __('WordPress installs new GitHub releases of these plugins by itself, as it does for plugins with auto-updates on. Other plugins keep their own auto-update choice. Switch off to choose per plugin on the Plugins screen.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            // Switched off, or waiting for Git Updater: the shared updater
            // stays off, whichever plugin's copy runs.
            add_filter('wpallstars_github_updater_enabled', '__return_false', 100);
            // While Git Updater does the job, keep this plugin on its GitHub
            // releases when asked to, and stop it asking GitHub on every page.
            if (self::switched_on() && SEOProStack_Settings::get(self::EARLY)) {
                add_filter('gu_override_dot_org', array(__CLASS__, 'git_updater_override'));
            }
            add_filter('pre_update_site_option_' . self::error_cache_key(), array(__CLASS__, 'renew_error_cache'));
            return;
        }
        if (SEOProStack_Settings::get(self::EARLY)) {
            add_filter('wpallstars_github_updater_early', '__return_true');
        }
        if (SEOProStack_Settings::get(self::AUTO)) {
            add_filter('auto_update_plugin', array(__CLASS__, 'auto_update'), 10, 2);
        }
        add_filter('wpallstars_github_token', array(__CLASS__, 'token'), 5, 2);
        add_filter('wpallstars_github_plugins', array(__CLASS__, 'plugins'), 5);
    }

    /**
     * Auto-update plugins from GitHub: WordPress auto-updates every plugin
     * the shared updater serves, whatever its own toggle on the Plugins
     * screen says. Other plugins keep their choice, and the
     * `auto_update_plugins` option is never written, so switching this off
     * brings each plugin's own choice back. WordPress's own off switches
     * (AUTOMATIC_UPDATER_DISABLED, automatic_updater_disabled) and plugins
     * filtering later still win.
     *
     * @param mixed $update Whether to auto-update, so far.
     * @param mixed $item   The update offer (or, on the Plugins screen, the
     *                      plugin's details), with its `plugin` file.
     * @return mixed
     */
    public static function auto_update($update, $item) {
        // plugins() is public in every copy since the updater was shared.
        if (!is_object($item) || !isset($item->plugin) || !is_string($item->plugin)
            || !class_exists('WPAllStars_GitHub_Updater')) {
            return $update;
        }
        $served = WPAllStars_GitHub_Updater::plugins();
        return isset($served[$item->plugin]) ? true : $update;
    }

    /**
     * Token from before the updater was shared: SEOPROSTACK_GITHUB_TOKEN and
     * the `seoprostack_github_token` filter.
     *
     * @param mixed  $token Token so far (WPALLSTARS_GITHUB_TOKEN or '').
     * @param string $repo  owner/repo.
     * @return string
     */
    public static function token($token, $repo) {
        $token = trim((string) $token);
        if ('' === $token && defined('SEOPROSTACK_GITHUB_TOKEN')) {
            $token = trim((string) SEOPROSTACK_GITHUB_TOKEN);
        }
        /**
         * Filter the GitHub token used for a repository. Kept for sites that
         * use it; new code can use `wpallstars_github_token`.
         *
         * @param string $token Token.
         * @param string $repo  owner/repo.
         */
        return (string) apply_filters('seoprostack_github_token', $token, $repo);
    }

    /**
     * Plugins filter from before the updater was shared.
     *
     * @param mixed $plugins Plugin file => array(repo, asset_only, version, name).
     * @return array
     */
    public static function plugins($plugins) {
        /**
         * Filter the plugins updated from GitHub releases. Kept for sites
         * that use it; new code can use `wpallstars_github_plugins`.
         *
         * @param array $plugins Plugin file => array(repo, asset_only, version, name).
         */
        return (array) apply_filters('seoprostack_github_plugins', (array) $plugins);
    }

    /**
     * Git Updater: update this plugin from GitHub even once it is on
     * WordPress.org.
     *
     * @param mixed $plugins Plugin files.
     * @return array
     */
    public static function git_updater_override($plugins) {
        $plugins   = (array) $plugins;
        $plugins[] = plugin_basename(SEOPROSTACK_FILE);
        return array_values(array_unique($plugins));
    }

    /**
     * Git Updater's cache of GitHub errors for this plugin (the site option
     * its get_cache_key() makes from our folder and "_error").
     *
     * @return string
     */
    private static function error_cache_key() {
        return 'ghu-' . md5(dirname(plugin_basename(SEOPROSTACK_FILE)) . '_error'); // NOSONAR: a cache or lock key, not security.
    }

    /**
     * When GitHub answers with an error (rate limit, or Not Found while the
     * repository is private), Git Updater caches it for 5 or 60 minutes so
     * it stops asking. Git Updater 14.4.2 keeps the first expiry time when it
     * caches the next error, so after one expiry every page load asked GitHub
     * again (and logged "Git Updater Error"). Give a new error its own expiry,
     * as Git Updater's develop branch does. Only this plugin's error cache.
     *
     * @param mixed $value Cache Git Updater is saving.
     * @return mixed
     */
    public static function renew_error_cache($value) {
        if (is_array($value) && isset($value['error_cache']['timeout'], $value['timeout'])
            && (int) $value['timeout'] <= time()) {
            $value['timeout'] = time() + max(1, (int) $value['error_cache']['timeout']) * MINUTE_IN_SECONDS;
        }
        return $value;
    }
}
