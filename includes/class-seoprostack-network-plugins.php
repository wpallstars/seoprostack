<?php
/**
 * Multisite: activate network-activated plugins on each site instead.
 *
 * "Load plugins only where needed" can leave out only plugins activated on
 * a site; network-activated plugins always load. On the Network Plugins
 * screen, while that setting is on for the main site, a notice offers to
 * activate them on each site instead, keeping network-wide the plugins that
 * must run everywhere or keep network settings. Every site loads the same
 * plugins before and after; each site can then leave out the ones a page or
 * screen does not use.
 *
 * Moving adds a plugin to every site's active plugins first, then removes
 * it from the network's, without activation or deactivation hooks: it never
 * stops running anywhere, and network activation's set-up stays. What moved
 * is kept in a network option for Undo, and new sites get those plugins
 * activated on them, as network activation did.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.13.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Network_Plugins {

    /** Network option: plugin file => IDs of the sites it was added to. */
    const MOVED = 'seoprostack_network_moved';

    /** User meta: the suggested list the person hid the notice for. */
    const HIDDEN = 'seoprostack_network_plugins_hidden';

    /** Query argument of the screen's actions and of their result. */
    const ARG    = 'seoprostack-network';
    const RESULT = 'seoprostack-network-done';

    /** Bulk action. */
    const BULK = 'seoprostack-network-move';

    /** Networks with more sites are left to WP-CLI: one request writes every site. */
    const MAX_SITES = 500;

    /**
     * Plugins kept for the whole network, by folder, with why.
     *
     * @return array<string,string> Folder => reason key (email, server, security, network).
     */
    private static function known() {
        $keep = array();
        foreach (array(
            'email'    => array('fluent-smtp', 'wp-mail-smtp', 'wp-mail-smtp-pro', 'post-smtp', 'easy-wp-smtp', 'smtp-mailer',
                'gmail-smtp', 'wp-ses', 'wp-offload-ses', 'mailgun', 'sendgrid-email-delivery-simplified', 'suremails'),
            'server'   => array('litespeed-cache', 'redis-cache', 'object-cache-pro', 'w3-total-cache', 'wp-super-cache', 'wp-rocket',
                'wp-optimize', 'wp-optimize-premium', 'breeze', 'sg-cachepress', 'wp-fastest-cache', 'scalability-pro',
                'index-wp-mysql-for-speed', 'advanced-database-cleaner', 'advanced-database-cleaner-pro',
                'advanced-database-cleaner-premium', 'http-requests-manager', 'debug-log-manager'),
            'security' => array('really-simple-ssl', 'really-simple-ssl-pro', 'wordfence', 'better-wp-security', 'ithemes-security-pro',
                'all-in-one-wp-security-and-firewall', 'sucuri-scanner', 'limit-login-attempts-reloaded', 'two-factor', 'wp-2fa',
                'wp-defender', 'ninjafirewall'),
            'network'  => array('ultimate-multisite', 'wp-ultimo', 'mercator', 'wordpress-mu-domain-mapping', 'multisite-enhancements',
                'ns-cloner-site-copier', 'network-media-library', 'plugin-groups', 'mainwp-child', 'worker'),
        ) as $reason => $folders) {
            foreach ($folders as $folder) {
                $keep[$folder] = $reason;
            }
        }
        return $keep;
    }

    /**
     * Register hooks (multisite only).
     */
    public static function init() {
        if (!is_multisite()) {
            return;
        }
        // New sites are made in the network admin, by sign-up, by WP-CLI
        // and by network tools, so this runs on every request.
        add_action('wp_initialize_site', array(__CLASS__, 'new_site'), 900);
        if (is_network_admin()) {
            add_action('load-plugins.php', array(__CLASS__, 'load_screen'));
        }
    }

    /**
     * Whether the screen offers the move: super admins, while "Load plugins
     * only where needed" is on for the main site.
     *
     * @return bool
     */
    private static function offered() {
        return current_user_can('manage_network_plugins')
            && class_exists('SEOProStack_Plugin_Loader', false)
            && SEOProStack_Settings::get(SEOProStack_Plugin_Loader::SWITCH_KEY);
    }

    /**
     * Network Plugins screen: run an action, then add the notice, row
     * actions and bulk action.
     */
    public static function load_screen() {
        if (!self::offered()) {
            return;
        }
        self::handle();
        add_action('network_admin_notices', array(__CLASS__, 'notice'));
        add_filter('network_admin_plugin_action_links', array(__CLASS__, 'action_links'), 20, 2);
        add_filter('bulk_actions-plugins-network', array(__CLASS__, 'bulk_actions'));
        add_filter('handle_bulk_actions-plugins-network', array(__CLASS__, 'handle_bulk'), 10, 3);
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Network-activated plugins, split into those to activate on each site
     * and those kept for the network (with why).
     *
     * @return array{move: string[], keep: array<string,string>}
     */
    public static function plan() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all     = get_plugins();
        $network = array_keys((array) get_site_option('active_sitewide_plugins', array()));
        $known   = self::known();
        $self    = plugin_basename(SEOPROSTACK_FILE);
        $keep    = array();
        foreach ($network as $file) {
            $folder = dirname($file);
            if ($self === $file) {
                $keep[$file] = 'self';
            } elseif (!isset($all[$file]) || !empty($all[$file]['Network'])) {
                $keep[$file] = 'network-only';
            } elseif (isset($known[$folder])) {
                $keep[$file] = $known[$folder];
            }
        }
        // What a kept plugin requires stays with it (Requires Plugins, WordPress 6.5).
        $by_folder = array();
        foreach ($network as $file) {
            $by_folder[dirname($file)] = $file;
        }
        foreach (array_keys($keep) as $file) {
            $needs = isset($all[$file]['RequiresPlugins']) ? (string) $all[$file]['RequiresPlugins'] : '';
            foreach (array_filter(array_map('trim', explode(',', $needs))) as $slug) {
                if (isset($by_folder[$slug]) && !isset($keep[$by_folder[$slug]])) {
                    $keep[$by_folder[$slug]] = 'required';
                }
            }
        }
        /**
         * Network-activated plugins kept for the whole network rather than
         * offered for activating on each site.
         *
         * @param array<string,string> $keep    Plugin file => reason key ('email', 'server', 'security', 'network', 'self', 'network-only', 'required', or your own).
         * @param string[]             $network Network-activated plugin files.
         */
        $keep = (array) apply_filters('seoprostack_network_plugins_keep', $keep, $network);
        return array('move' => array_values(array_diff($network, array_keys($keep))), 'keep' => $keep);
    }

    /**
     * Whether a network-activated plugin may be moved when the owner asks
     * for it by name (row action or bulk): not SEO Pro Stack, and not a
     * plugin that can only be network-activated.
     *
     * @param string $file Plugin file.
     * @return bool
     */
    private static function movable($file) {
        $plan = self::plan();
        if (in_array($file, $plan['move'], true)) {
            return true;
        }
        return isset($plan['keep'][$file]) && !in_array($plan['keep'][$file], array('self', 'network-only'), true);
    }

    /**
     * The current site's active plugins as stored, and saving them, without
     * "Load plugins only where needed" filtering this request's list (that
     * list is the main site's, and its save filter never removes plugins).
     *
     * @param string[]|null $save Plugins to save, or null to read.
     * @return string[]
     */
    private static function site_plugins($save = null) {
        $loader = class_exists('SEOProStack_Plugin_Loader', false);
        if ($loader) {
            remove_filter('option_active_plugins', array('SEOProStack_Plugin_Loader', 'filter_active'), PHP_INT_MAX);
            remove_filter('pre_update_option_active_plugins', array('SEOProStack_Plugin_Loader', 'keep_active'), PHP_INT_MAX);
        }
        if (null !== $save) {
            update_option('active_plugins', array_values($save));
        }
        $active = get_option('active_plugins', array());
        if ($loader && SEOProStack_Plugin_Loader::is_filtered()) {
            add_filter('option_active_plugins', array('SEOProStack_Plugin_Loader', 'filter_active'), PHP_INT_MAX);
            add_filter('pre_update_option_active_plugins', array('SEOProStack_Plugin_Loader', 'keep_active'), PHP_INT_MAX);
        }
        return is_array($active) ? array_values(array_filter($active, 'is_string')) : array();
    }

    /**
     * Activate plugins on each site instead of the whole network.
     *
     * @param string[] $files Network-activated plugin files.
     * @return int|WP_Error Plugins moved.
     */
    public static function move(array $files) {
        $files = array_values(array_filter($files, array(__CLASS__, 'movable')));
        if (!$files) {
            return 0;
        }
        $sites = get_sites(array('fields' => 'ids', 'number' => self::MAX_SITES + 1));
        if (count($sites) > self::MAX_SITES) {
            return new WP_Error('seoprostack_too_many_sites', '');
        }
        $moved = (array) get_site_option(self::MOVED, array());
        // Every site first, so no site is ever without the plugin.
        foreach ($sites as $site) {
            switch_to_blog((int) $site);
            $active = self::site_plugins();
            $added  = array_diff($files, $active);
            if ($added) {
                self::site_plugins(array_merge($active, $added));
                foreach ($added as $file) {
                    $moved[$file][] = (int) $site;
                }
            }
            restore_current_blog();
        }
        foreach ($files as $file) {
            $moved[$file] = array_values(array_unique((array) ($moved[$file] ?? array())));
        }
        update_site_option(self::MOVED, $moved);
        $network = (array) get_site_option('active_sitewide_plugins', array());
        foreach ($files as $file) {
            unset($network[$file]);
        }
        update_site_option('active_sitewide_plugins', $network);
        return count($files);
    }

    /**
     * Put moved plugins back on the network and take them off the sites
     * they were added to.
     *
     * @return int Plugins put back.
     */
    public static function undo() {
        $moved = (array) get_site_option(self::MOVED, array());
        if (!$moved) {
            return 0;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all     = get_plugins();
        $files   = array_values(array_filter(array_keys($moved), function ($file) use ($all) {
            return isset($all[$file]);
        }));
        // The network first, so no site is ever without the plugin.
        $network = (array) get_site_option('active_sitewide_plugins', array());
        foreach ($files as $file) {
            if (!isset($network[$file])) {
                $network[$file] = time();
            }
        }
        update_site_option('active_sitewide_plugins', $network);
        $by_site = array();
        foreach ($files as $file) {
            foreach ((array) $moved[$file] as $site) {
                $by_site[(int) $site][] = $file;
            }
        }
        foreach ($by_site as $site => $remove) {
            if (!get_site($site)) {
                continue;
            }
            switch_to_blog($site);
            $active = self::site_plugins();
            $left   = array_values(array_diff($active, $remove));
            if ($left !== $active) {
                self::site_plugins($left);
            }
            restore_current_blog();
        }
        delete_site_option(self::MOVED);
        return count($files);
    }

    /**
     * A new site gets the plugins moved off the network, as network
     * activation gave them. Plugins already running on this request are
     * activated properly, so their own set-up for the site runs; one this
     * request left out is only listed (loading it now could fail), and
     * sets itself up on the site's first full request, as most do.
     *
     * @param WP_Site $site New site.
     */
    public static function new_site($site) {
        $moved = (array) get_site_option(self::MOVED, array());
        if (!$moved || !($site instanceof WP_Site)) {
            return;
        }
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all     = get_plugins();
        $network = (array) get_site_option('active_sitewide_plugins', array());
        $loaded  = array_flip(array_map('wp_normalize_path', get_included_files()));
        switch_to_blog((int) $site->blog_id);
        foreach (array_keys($moved) as $file) {
            if (!isset($all[$file]) || isset($network[$file])) {
                continue;
            }
            if (isset($loaded[wp_normalize_path(WP_PLUGIN_DIR . '/' . $file)])) {
                activate_plugin($file);
            } else {
                $active = self::site_plugins();
                if (!in_array($file, $active, true)) {
                    $active[] = $file;
                    self::site_plugins($active);
                }
            }
            $moved[$file][] = (int) $site->blog_id;
        }
        restore_current_blog();
        update_site_option(self::MOVED, $moved);
    }

    /**
     * Address of a screen action.
     *
     * @param string $do   move, move-all, undo or hide.
     * @param string $file Plugin file, for move.
     * @return string
     */
    private static function url($do, $file = '') {
        $args = array(self::ARG => $do);
        if ('' !== $file) {
            $args['plugin'] = rawurlencode($file);
        }
        return wp_nonce_url(add_query_arg($args, network_admin_url('plugins.php')), self::ARG . '_' . $do . '_' . $file);
    }

    /**
     * Run move, move-all, undo or hide, then come back with the result.
     */
    private static function handle() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below with the action and plugin in the nonce.
        $do   = isset($_GET[self::ARG]) ? sanitize_key(wp_unslash($_GET[self::ARG])) : '';
        $file = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
        // phpcs:enable
        if (!in_array($do, array('move', 'move-all', 'undo', 'hide'), true)) {
            return;
        }
        check_admin_referer(self::ARG . '_' . $do . '_' . $file);
        $back = remove_query_arg(array(self::ARG, 'plugin', '_wpnonce', self::RESULT), network_admin_url('plugins.php'));
        if ('hide' === $do) {
            update_user_meta(get_current_user_id(), self::HIDDEN, self::fingerprint());
            wp_safe_redirect($back);
            exit;
        }
        if ('undo' === $do) {
            $result = 'undo:' . self::undo();
        } else {
            $moved  = self::move('move' === $do ? array($file) : self::plan()['move']);
            $result = is_wp_error($moved) ? 'error:sites' : 'move:' . $moved;
        }
        wp_safe_redirect(add_query_arg(self::RESULT, rawurlencode($result), $back));
        exit;
    }

    /**
     * Fingerprint of the suggested list, so a hidden notice comes back when
     * it changes.
     *
     * @return string
     */
    private static function fingerprint() {
        $move = self::plan()['move'];
        sort($move);
        return md5(implode(',', $move)); // NOSONAR -- notices a changed list; not security.
    }

    /**
     * Bulk action in the Network Plugins list.
     *
     * @param array<string,string> $actions Bulk actions.
     * @return array<string,string>
     */
    public static function bulk_actions($actions) {
        $actions[self::BULK] = __('Activate on each site instead', 'seoprostack');
        return $actions;
    }

    /**
     * Run the bulk action. Core has checked the bulk-plugins nonce.
     *
     * @param string   $sendback Where to go next.
     * @param string   $action   Bulk action.
     * @param string[] $files    Ticked plugin files.
     * @return string
     */
    public static function handle_bulk($sendback, $action, $files) {
        if (self::BULK !== $action || !self::offered()) {
            return $sendback;
        }
        $network = (array) get_site_option('active_sitewide_plugins', array());
        $files   = array_values(array_filter(array_map('sanitize_text_field', (array) $files), function ($file) use ($network) {
            return isset($network[$file]);
        }));
        $moved = self::move($files);
        return add_query_arg(self::RESULT, rawurlencode(is_wp_error($moved) ? 'error:sites' : 'move:' . $moved), $sendback);
    }

    /**
     * Row action for a network-activated plugin that can move.
     *
     * @param string[] $links Action links.
     * @param string   $file  Plugin file.
     * @return string[]
     */
    public static function action_links($links, $file) {
        if (is_plugin_active_for_network($file) && in_array($file, self::plan()['move'], true)) {
            $links['seoprostack-network-move'] = sprintf('<a href="%1$s">%2$s</a>', esc_url(self::url('move', $file)), esc_html__('Activate on each site instead', 'seoprostack'));
        }
        return $links;
    }

    /**
     * Drop the result argument from the address after it is shown.
     *
     * @param string[] $args Removable query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::RESULT;
        return $args;
    }

    /**
     * Why a plugin stays for the network, in words.
     *
     * @param string $reason Reason key.
     * @return string
     */
    private static function reason($reason) {
        $texts = array(
            'email'        => __('sends email', 'seoprostack'),
            'server'       => __('works for the whole server or network', 'seoprostack'),
            'security'     => __('protects logins and the network', 'seoprostack'),
            'network'      => __('manages the network or its sites', 'seoprostack'),
            'self'         => __('serves every site', 'seoprostack'),
            'network-only' => __('can only be activated for the network', 'seoprostack'),
            'required'     => __('another kept plugin needs it', 'seoprostack'),
        );
        return isset($texts[$reason]) ? $texts[$reason] : __('kept by a filter', 'seoprostack');
    }

    /**
     * The result of an action, and the offer.
     */
    public static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $result = isset($_GET[self::RESULT]) ? sanitize_text_field(wp_unslash($_GET[self::RESULT])) : '';
        if ('' !== $result) {
            list($done, $count) = array_pad(explode(':', $result, 2), 2, '');
            if ('error' === $done) {
                /* translators: %d: number of sites */
                $text = sprintf(__('Nothing changed: this network has more than %d sites. Activate the plugins on each site with WP-CLI instead.', 'seoprostack'), self::MAX_SITES);
                printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($text));
            } elseif ('undo' === $done) {
                /* translators: %d: number of plugins */
                $text = sprintf(_n('%d plugin is network-activated again.', '%d plugins are network-activated again.', (int) $count, 'seoprostack'), (int) $count);
                printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
            } else {
                /* translators: %d: number of plugins */
                $text = sprintf(_n('%d plugin is now activated on each site instead of the network. Every site still runs it.', '%d plugins are now activated on each site instead of the network. Every site still runs them.', (int) $count, 'seoprostack'), (int) $count);
                printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
            }
        }

        $plan  = self::plan();
        $moved = (array) get_site_option(self::MOVED, array());
        $parts = array();
        if ($plan['move'] && get_user_meta(get_current_user_id(), self::HIDDEN, true) !== self::fingerprint()) {
            $count   = count($plan['move']);
            /* translators: %d: number of plugins */
            $parts[] = '<p>' . sprintf(esc_html(_n('%d plugin is network-activated, so it loads on every page and screen of every site. Load plugins only where needed can leave out only plugins activated on each site. Activated on each site instead, every site still runs it, new sites get it too, and each site can leave it out where it is not used.', '%d plugins are network-activated, so they load on every page and screen of every site. Load plugins only where needed can leave out only plugins activated on each site. Activated on each site instead, every site still runs them, new sites get them too, and each site can leave them out where they are not used.', $count, 'seoprostack')), (int) $count) . '</p>';
            $keep = array();
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all = get_plugins();
            foreach ($plan['keep'] as $file => $reason) {
                $name   = isset($all[$file]['Name']) ? $all[$file]['Name'] : $file;
                $keep[] = esc_html($name) . ' (' . esc_html(self::reason($reason)) . ')';
            }
            if ($keep) {
                $parts[] = '<p>' . esc_html__('Kept for the network:', 'seoprostack') . ' ' . implode(', ', $keep) . '.</p>';
            }
            $parts[] = sprintf(
                '<p><a class="button button-primary" href="%1$s">%2$s</a> <a href="%3$s">%4$s</a></p>',
                esc_url(self::url('move-all')),
                /* translators: %d: number of plugins */
                esc_html(sprintf(_n('Activate %d plugin on each site instead', 'Activate %d plugins on each site instead', $count, 'seoprostack'), $count)),
                esc_url(self::url('hide')),
                esc_html__('Hide', 'seoprostack')
            );
        }
        if ($moved) {
            $parts[] = sprintf(
                '<p>%1$s <a href="%2$s">%3$s</a></p>',
                /* translators: %d: number of plugins */
                esc_html(sprintf(_n('%d plugin was moved from the network to each site by SEO Pro Stack.', '%d plugins were moved from the network to each site by SEO Pro Stack.', count($moved), 'seoprostack'), count($moved))),
                esc_url(self::url('undo')),
                esc_html__('Undo: network-activate them again', 'seoprostack')
            );
        }
        if ($parts) {
            echo '<div class="notice notice-info"><p><strong>' . esc_html__('SEO Pro Stack: network-activated plugins', 'seoprostack') . '</strong></p>' . implode('', $parts) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
        }
    }
}
