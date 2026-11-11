<?php
/**
 * Fixes for other plugins: Tutor LMS Pro's push service worker as a static file.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * Tutor LMS Pro's Notifications add-on (checked with 4.1.1 and 4.2.0)
 * registers a service worker at the site's address plus
 * /tutor-push-notification.js on every page, and answers that address
 * from PHP on init by reading a fixed file. Browsers check a service
 * worker's script again on navigations, past the HTTP cache, so each check
 * starts WordPress and every plugin for 2 KB that never changes, and page
 * caches do not keep it. With the add-on turned off, browsers that have the
 * worker keep asking, and each check becomes a WordPress 404.
 *
 * From an admin screen of the main site, the file is written to the site's
 * root folder, so the web server serves it without PHP: Tutor's own file
 * while the add-on is on (on a network, only when every site with Tutor LMS
 * Pro has it on, as the network shares the folder), and once it is off, a
 * worker that removes itself, so browsers stop asking. The removing worker
 * is written only when browsers are known to have Tutor's: this wrote it
 * before, or a check reached WordPress while the add-on was off (that check
 * is answered with the removing worker at once). Each file starts with the
 * MARKER comment; a file without it is never changed or deleted. It is
 * checked again when Tutor LMS Pro's version or the add-on changes, and
 * daily, and removed when Fixes for other plugins is switched off or SEO
 * Pro Stack is deactivated or deleted. Hosting needs says when the folder
 * cannot be written, and suggests turning the add-on off on a site with no
 * published courses.
 */

defined('ABSPATH') || exit;

/**
 * Tutor LMS Pro's push service worker, served as a static file.
 */
final class SEOProStack_Tutor_Push_Worker {

    /** The worker's file name, at the site's root address. */
    const FILE = 'tutor-push-notification.js';

    /** Tutor LMS Pro's main file. */
    const PRO = 'tutor-pro/tutor-pro.php';

    /** The Notifications add-on's key in Tutor's tutor_addons_config option. */
    const ADDON = 'tutor-pro/addons/tutor-notifications/tutor-notifications.php';

    /** Tutor's worker, inside its plugin folder. */
    const SOURCE = 'tutor-pro/addons/tutor-notifications/assets/js/tutor-push-notification.js';

    /** First line of every file this writes; a file without it is someone else's. */
    const MARKER = '/* SEO Pro Stack (Fixes for other plugins): Tutor LMS Pro push worker. Removed when SEO Pro Stack is deactivated. */';

    /** A worker that replaces Tutor's and removes itself. */
    const UNREGISTER = "self.addEventListener('install', function () { self.skipWaiting(); });\nself.addEventListener('activate', function () { self.registration.unregister(); });\n";

    /**
     * What is on disk and why, as a site option (a network shares the
     * root folder): mode 'tutor', 'unregister' or ''; note 'unwritable',
     * 'mixed', 'theirs' or ''; asked (a check reached WordPress with the
     * add-on off); stamp and checked, to know when to look again.
     */
    const STATE = 'seoprostack_tutor_push_worker';

    /** Sites read on a network before giving up (the file is left to PHP). */
    const MAX_SITES = 1000;

    /**
     * Hooks. The front-end one only for requests for the worker's address.
     *
     * @param bool $enabled Whether Fixes for other plugins is on.
     */
    public static function boot($enabled) {
        if (is_admin()) {
            // Also when switched off, to remove the file.
            add_action('admin_init', array(__CLASS__, 'maybe_sync'));
            add_action('update_option_tutor_addons_config', array(__CLASS__, 'recheck'));
            add_action('add_option_tutor_addons_config', array(__CLASS__, 'recheck'));
            add_action('upgrader_process_complete', array(__CLASS__, 'recheck'));
            return;
        }
        if ($enabled && self::is_worker_request()) {
            // Before Tutor's own answer, which uses init priority 10.
            add_action('init', array(__CLASS__, 'serve'), 1);
        }
    }

    /**
     * Whether this request asks for the worker's address.
     *
     * @return bool
     */
    private static function is_worker_request() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with a fixed name.
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        return '/' . self::FILE === substr($path, -strlen(self::FILE) - 1);
    }

    /**
     * A check for the worker reached WordPress: with the add-on on, Tutor
     * answers it; with it off, browsers still have the worker, so answer
     * with the removing one and note it for the next admin screen.
     */
    public static function serve() {
        // Only where Tutor LMS Pro is, or was, installed.
        if (self::addon_on() || (!file_exists(WP_PLUGIN_DIR . '/' . self::PRO) && '' === self::state()['mode'])) {
            return;
        }
        $state = self::state();
        if (!$state['asked']) {
            $state['asked'] = true;
            update_site_option(self::STATE, $state);
        }
        nocache_headers();
        header('Content-Type: text/javascript; charset=utf-8');
        echo self::UNREGISTER; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed script.
        exit;
    }

    /** Look again on the next admin screen (the add-on or a plugin changed). */
    public static function recheck() {
        $state            = self::state();
        $state['checked'] = 0;
        update_site_option(self::STATE, $state);
    }

    /**
     * Keep the file in step, from the main site's admin screens. Reads one
     * site option when nothing changed.
     */
    public static function maybe_sync() {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        $state = self::state();
        $stamp = self::stamp();
        if ($state['stamp'] === $stamp && $state['checked'] > time() - DAY_IN_SECONDS) {
            return;
        }
        $state          = self::sync($state);
        $state['stamp'] = $stamp;
        update_site_option(self::STATE, $state);
    }

    /**
     * What, cheaply read, decides the file on this site. Tutor's worker is
     * read by its modified time, not TUTOR_PRO_VERSION: Load plugins only
     * where needed can skip Tutor LMS Pro on some screens, and a constant
     * there on one screen and missing on the next would sync each time.
     *
     * @return string
     */
    private static function stamp() {
        $on     = SEOProStack_Plugin_Fixes::enabled() ? '1' : '0';
        $source = WP_PLUGIN_DIR . '/' . self::SOURCE;
        $mtime  = file_exists($source) ? (string) filemtime($source) : '';
        return $on . '|' . $mtime . '|' . (self::addon_on() ? '1' : '0');
    }

    /**
     * Work out the file and write, change or remove it.
     *
     * @param array $state Stored state.
     * @return array New state.
     */
    private static function sync(array $state) {
        $state['checked'] = time();
        $state['note']    = '';
        $want             = '';
        if (SEOProStack_Plugin_Fixes::enabled()) {
            $sites = self::addon_sites();
            if (null === $sites) {
                $want = '';
            } elseif ($sites['on'] && $sites['on'] === $sites['pro']) {
                $want = 'tutor';
            } elseif ($sites['on']) {
                $state['note'] = 'mixed';
            } elseif ($state['asked'] || '' !== $state['mode']) {
                $want = 'unregister';
            }
        }
        $contents = self::contents($want);
        if (null === $contents) {
            $want     = '';
            $contents = '';
        }
        $result = self::put($contents);
        if (true === $result) {
            $state['mode'] = $want;
            if ('unregister' === $want) {
                $state['asked'] = false;
            }
        } else {
            $state['note'] = $result;
        }
        return $state;
    }

    /**
     * Sites with Tutor LMS Pro active, and those with the add-on on (one
     * site, or every site of a network).
     *
     * @return array{pro:int,on:int}|null Null on a network too large to read.
     */
    private static function addon_sites() {
        if (!is_multisite()) {
            $pro = self::pro_active();
            return array('pro' => $pro ? 1 : 0, 'on' => $pro && self::addon_config_on() ? 1 : 0);
        }
        $sites = get_sites(array('fields' => 'ids', 'number' => self::MAX_SITES + 1));
        if (count($sites) > self::MAX_SITES) {
            return null;
        }
        $count = array('pro' => 0, 'on' => 0);
        foreach ($sites as $site_id) {
            switch_to_blog((int) $site_id);
            if (self::pro_active()) {
                ++$count['pro'];
                $count['on'] += self::addon_config_on() ? 1 : 0;
            }
            restore_current_blog();
        }
        return $count;
    }

    /**
     * Whether Tutor LMS Pro is active on the current site (stored list, so
     * Load plugins only where needed does not hide it), or on the network.
     *
     * @return bool
     */
    private static function pro_active() {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (is_multisite() && is_plugin_active_for_network(self::PRO)) {
            return true;
        }
        if (!class_exists('SEOProStack_Replaced_Plugins')) {
            require_once SEOPROSTACK_DIR . 'admin/includes/class-replaced-plugins.php';
        }
        return in_array(self::PRO, SEOProStack_Replaced_Plugins::stored_plugins(), true);
    }

    /**
     * Whether the Notifications add-on is turned on in Tutor's settings of
     * the current site.
     *
     * @return bool
     */
    private static function addon_config_on() {
        $config = get_option('tutor_addons_config');
        return is_array($config) && !empty($config[self::ADDON]['is_enable']);
    }

    /**
     * Whether the add-on is on for this request's site, from the stored
     * settings, so a request on which Load plugins only where needed skips
     * Tutor LMS Pro is not taken for the add-on being off.
     *
     * @return bool
     */
    public static function addon_on() {
        return self::addon_config_on() && self::pro_active();
    }

    /**
     * The file's contents for a mode.
     *
     * @param string $mode 'tutor', 'unregister' or ''.
     * @return string|null Null when Tutor's file cannot be read.
     */
    private static function contents($mode) {
        if ('unregister' === $mode) {
            return self::MARKER . "\n" . self::UNREGISTER;
        }
        if ('tutor' !== $mode) {
            return '';
        }
        $source = WP_PLUGIN_DIR . '/' . self::SOURCE;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.
        $worker = is_readable($source) ? file_get_contents($source) : false;
        return is_string($worker) && '' !== $worker ? self::MARKER . "\n" . $worker : null;
    }

    /**
     * The worker's file in the site's root folder (the network's).
     *
     * @return string
     */
    public static function path() {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return get_home_path() . self::FILE;
    }

    /**
     * Whether a file is one this wrote.
     *
     * @param string $file File.
     * @return bool
     */
    public static function is_ours($file) {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, first line only.
        $head = file_get_contents($file, false, null, 0, strlen(self::MARKER));
        return self::MARKER === $head;
    }

    /**
     * Write the file whole, or remove it ('' contents).
     *
     * @param string $contents Contents; '' removes the file.
     * @return true|string True when the file is as asked, else why not: 'theirs' or 'unwritable'.
     */
    private static function put($contents) {
        $file   = self::path();
        $exists = file_exists($file);
        if ($exists && !self::is_ours($file)) {
            return '' === $contents ? true : 'theirs';
        }
        if ('' === $contents) {
            if ($exists) {
                wp_delete_file($file);
            }
            return file_exists($file) ? 'unwritable' : true;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
        if ($exists && file_get_contents($file) === $contents) {
            return true;
        }
        $folder = dirname($file);
        if (!wp_is_writable($folder)) {
            return 'unwritable';
        }
        $temp = $file . '.' . wp_generate_password(8, false) . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file renamed into place.
        if (false === file_put_contents($temp, $contents)) {
            return 'unwritable';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same folder, so the file appears whole.
        if (!rename($temp, $file)) {
            wp_delete_file($temp);
            return 'unwritable';
        }
        return true;
    }

    /**
     * Stored state, with every key.
     *
     * @return array{mode:string,note:string,asked:bool,stamp:string,checked:int}
     */
    private static function state() {
        $state = get_site_option(self::STATE, array());
        $state = is_array($state) ? $state : array();
        return array(
            'mode'    => isset($state['mode']) && is_string($state['mode']) ? $state['mode'] : '',
            'note'    => isset($state['note']) && is_string($state['note']) ? $state['note'] : '',
            'asked'   => !empty($state['asked']),
            'stamp'   => isset($state['stamp']) && is_string($state['stamp']) ? $state['stamp'] : '',
            'checked' => isset($state['checked']) ? (int) $state['checked'] : 0,
        );
    }

    /**
     * Plugin deactivated: remove the file, unless only a network's subsite
     * deactivated it (the network shares the folder). The state stays, so
     * the removing worker follows Tutor's if SEO Pro Stack comes back.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        if (!is_multisite() || $network_wide || is_main_site()) {
            self::put('');
            $state            = self::state();
            $state['checked'] = 0;
            update_site_option(self::STATE, $state);
        }
    }

    /**
     * Hosting needs advice for this site: why the file is left to PHP, and
     * the add-on on with no published courses.
     *
     * @return array<int, array{0:string,1:string}>
     */
    public static function advice() {
        $advice = array();
        $state  = self::state();
        if ('unwritable' === $state['note']) {
            $advice[] = array('recommended', sprintf(
                /* translators: 1: file name, 2: folder, 3: Tutor's file. */
                __('Browsers ask for Tutor LMS Pro’s push notification worker (%1$s) on many page views, and each time PHP and every plugin start to answer, because SEO Pro Stack cannot write it to the site’s root folder (%2$s). Copy %3$s there, or let WordPress write to that folder, so the web server answers without PHP.', 'seoprostack'),
                self::FILE,
                dirname(self::path()),
                'wp-content/plugins/' . self::SOURCE
            ));
        } elseif ('mixed' === $state['note']) {
            $advice[] = array('recommended', __('Tutor LMS Pro’s Notifications add-on is on for some sites of this network and off for others, so its push notification worker, which every site shares at the root address, is still answered by PHP on many page views. Turn the add-on on, or off, for every site with Tutor LMS Pro, so the web server can answer without PHP.', 'seoprostack'));
        }
        if (self::addon_on() && post_type_exists('courses') && 0 === (int) wp_count_posts('courses')->publish) {
            $advice[] = array('recommended', __('Tutor LMS Pro’s Notifications add-on is on, but this site has no published courses. Its push notifications reach only course students, while every visitor’s browser installs and checks its worker. Turn it off in Tutor LMS → Add-ons until courses are published.', 'seoprostack'));
        }
        return $advice;
    }
}
