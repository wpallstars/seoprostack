<?php
/**
 * Site templates: shipped designs with site-owned edits preserved.
 *
 * site-templates/{slug}.json: slug, name, version, updated, notes; pages and
 * elements are lists of {key,title,slug,template,parts:[{key,markup}],meta:{}}.
 * Parts are top-level core/group blocks with a stable className. Never rename
 * keys: a missing tracked part is a site deletion, not permission to recreate.
 * custom_css is a string (the leading base; the remainder belongs to the site).
 * options and widgets map option names to named-key trees; theme_mods is a
 * named-key tree. menus maps theme locations to {name,items:{key:{title,url}}}.
 * images is a list of {key,filename,sha256,placeholder}; files live beneath
 * site-templates/{slug}/images/. {{image:key}} resolves to an imported URL.
 * Use the existing Form by key block/shortcode, never numeric form IDs.
 * See site-templates/README.md for limits, ownership and undo semantics.
 *
 * This class schedules updates and holds the site lock; the work is in
 * includes/site-templates/: the manifest, the run with its journal, one
 * class per resource (posts and their parts, media, menus, values) and undo.
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

class SEOProStack_Site_Templates extends SEOProStack_Feature {

    const KEY = 'site_templates';
    const OPTION = 'seoprostack_site_template';
    const HOOK = 'seoprostack_site_template_update';
    const SEEN = 'seoprostack_site_template_seen';
    const META = '_seoprostack_template_key';

    /** Files in includes/site-templates/, loaded only when a template runs. */
    const PARTS = array('manifest', 'schema', 'run', 'parts', 'posts', 'media', 'menus', 'values', 'journal', 'undo');

    /** @return array */
    public static function settings() {
        return array(self::KEY => array(
            'type' => 'bool', 'default' => true, 'tab' => 'content',
            'label' => __('Update unedited template parts automatically', 'seoprostack'),
            'description' => __('Only sites made from a site template are updated. Edited or removed parts stay as they are. WP-CLI can compare, apply and undo template changes.', 'seoprostack'),
        ));
    }

    /** Register CLI independently of automatic updates. */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-site-templates-cli.php';
            WP_CLI::add_command('seoprostack site-templates', 'SEOProStack_Site_Templates_CLI');
        }
        add_action(self::HOOK, array(__CLASS__, 'cron'));
        add_action('wp_loaded', array(__CLASS__, 'maybe_schedule'));
        add_action('upgrader_process_complete', array(__CLASS__, 'maybe_schedule'));
        add_action('seoprostack_settings_changed', array(__CLASS__, 'maybe_schedule'));
    }

    /** Each site discovers its own version change; never walk a network here. */
    public static function maybe_schedule() {
        if (!self::enabled() || !get_option(self::OPTION)) {
            return;
        }
        if (SEOPROSTACK_VERSION !== get_option(self::SEEN) && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 30, self::HOOK);
        }
    }

    /** A bounded batch; failures remain visible in the site record. */
    public static function cron() {
        $record = get_option(self::OPTION, array());
        if (!self::enabled() || empty($record['slug'])) {
            return;
        }
        $result = self::run($record['slug']);
        if (is_wp_error($result)) {
            $record = get_option(self::OPTION, array());
            $record['error'] = $result->get_error_message();
            update_option(self::OPTION, $record, false);
            return;
        }
        if ($result['pending']) {
            wp_schedule_single_event(time() + 30, self::HOOK);
        } else {
            update_option(self::SEEN, SEOPROSTACK_VERSION, false);
        }
    }

    /** @return array Shipped, valid manifests. */
    public static function manifests() {
        $out = array();
        foreach (glob(SEOPROSTACK_DIR . 'site-templates/*.json') ?: array() as $file) {
            $slug = basename($file, '.json');
            $manifest = self::manifest($slug);
            if (!is_wp_error($manifest)) {
                $out[$slug] = $manifest;
            }
        }
        return $out;
    }

    /**
     * Validate the entire manifest before any writes, including duplicate keys.
     *
     * @param string $slug Shipped slug, never a path or URL.
     * @return array|WP_Error
     */
    public static function manifest($slug) {
        self::load();
        return SEOProStack_Site_Template_Manifest::load($slug);
    }

    /**
     * Apply one bounded batch, or compare without saving anything.
     *
     * @param string $slug Manifest slug.
     * @param bool   $dry Read only.
     * @return array|WP_Error
     */
    public static function run($slug, $dry = false) {
        $manifest = self::manifest($slug);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        $record = self::record($slug);
        if (is_wp_error($record) || $dry) {
            return is_wp_error($record) ? $record : (new SEOProStack_Site_Template_Run($record, true))->batch($manifest);
        }
        return self::locked(function () use ($slug, $manifest) {
            // Re-read after acquiring the lock; another runner may have finished.
            wp_cache_delete(self::OPTION, 'options');
            $fresh = self::record($slug);
            return is_wp_error($fresh) ? $fresh : (new SEOProStack_Site_Template_Run($fresh, false))->batch($manifest);
        });
    }

    /**
     * Restore the last run, refusing to overwrite any subsequent site edits.
     * Created posts become drafts, never deleted. Imported media stay usable.
     *
     * @return true|WP_Error
     */
    public static function undo() {
        self::load();
        return self::locked(function () {
            wp_cache_delete(self::OPTION, 'options');
            $record = get_option(self::OPTION, array());
            if (empty($record['backup'])) {
                return new WP_Error('template_undo', 'No template run to undo.');
            }
            return SEOProStack_Site_Template_Undo::restore(new SEOProStack_Site_Template_Run($record, false));
        });
    }

    /** @param string $slug Manifest slug. @return array|WP_Error This site's record, when it follows $slug or none. */
    private static function record($slug) {
        $record = get_option(self::OPTION, array());
        if ($record && ($record['slug'] ?? '') !== $slug) {
            return new WP_Error('template_owned', 'This site already follows another template.');
        }
        return is_array($record) ? $record : array();
    }

    /**
     * Run $work while holding this site's template lock.
     *
     * MySQL advisory locks are connection-owned and released even on a crash.
     * Unlike an expiring option lease, a slow worker cannot lose ownership.
     *
     * @param callable $work Returns the result.
     * @return mixed|WP_Error
     */
    private static function locked($work) {
        global $wpdb;
        $lock = substr('sps-template-' . hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 64);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-scoped exclusion, no table data/cache involved.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return new WP_Error('template_busy', 'Another template run owns this site.');
        }
        try {
            return $work();
        } catch (RuntimeException $error) {
            return new WP_Error('template_storage', $error->getMessage());
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release this connection's advisory lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Load the template classes. */
    private static function load() {
        foreach (self::PARTS as $part) {
            require_once SEOPROSTACK_DIR . 'includes/site-templates/class-seoprostack-site-template-' . $part . '.php';
        }
    }
}
