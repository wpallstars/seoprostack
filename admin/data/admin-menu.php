<?php
/**
 * Where "Organise the admin menu" puts menu entries.
 *
 * Built from the menus of 32 sites that were arranged by hand with Admin
 * Menu Editor Pro, so the usual places match what people already know.
 *
 * - menus:   menu address => place. Top-level entries use their own
 *            address; an entry inside another menu (such as a plugin's page
 *            under Settings) uses its address too, and moves out of that
 *            menu to the place given.
 * - plugins: plugin folder => place, for entries that are not listed under
 *            menus, matched to the plugin that handles the page. Plugins
 *            placed in super-admin are also hidden from the Plugins screen
 *            for people who are not developers while Developer admins is on.
 * - hidden:  addresses (or menu>address) of entries left out of the menu.
 * - bar:     plugin folder => admin bar item IDs, removed for people who
 *            are not developers while that plugin is placed in super-admin
 *            and Developer admins is on.
 *
 * A place is a section (top, content, communications, seo, shop,
 * admin-heading, admin, super-admin) or the address of another menu, to go
 * inside that menu. Under the Admin heading come the Administrators menu
 * (admin), the Developers menu (super-admin), then entries placed in
 * admin-heading. Entries with no place go into Administrators (top-level
 * entries) or stay where they are (entries inside a menu).
 *
 * Filter with `seoprostack_admin_menu_catalog`; people change places
 * with the "Move menu entries" setting.
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

return array(
    'menus' => array(
        // Top, without a heading.
        'index.php'                             => 'top',
        'burst'                                 => 'top',

        // Content.
        'edit.php?post_type=page'               => 'content',
        'edit.php'                              => 'content',
        'upload.php'                            => 'content',
        'link-manager.php'                      => 'content',
        'tutor'                                 => 'content',
        'vg_sheet_editor_setup'                 => 'content',
        'propertyhive'                          => 'content',

        // Under the Admin heading, after the Administrators and Developers menus.
        'users.php'                             => 'admin-heading',
        'profile.php'                           => 'admin-heading',

        // Communications.
        'edit-comments.php'                     => 'communications',
        'fluent-boards'                         => 'communications',
        'fluent-booking'                        => 'communications',
        'fluentcrm-admin'                       => 'communications',
        'fluent_forms'                          => 'communications',
        'fluent-mail'                           => 'communications',
        'fluent-support'                        => 'communications',
        'fluent-community'                      => 'communications',
        'meowapps-main-menu'                    => 'communications',
        'bookly-menu'                           => 'communications',
        'sfr-settings'                          => 'communications',
        'wpcf7'                                 => 'communications',

        // SEO.
        'rank-math'                             => 'seo',
        'link_whisper'                          => 'seo',
        'edit.php?post_type=pretty-link'        => 'seo',
        'edit.php?post_type=sps_short_link'     => 'seo',
        'edit.php?post_type=surl'               => 'seo',
        'reviveso'                              => 'seo',
        'search-console'                        => 'seo',
        'wpseo_dashboard'                       => 'seo',
        'surfer'                                => 'seo',

        // Shop.
        'woocommerce'                           => 'shop',
        'fluent-cart'                           => 'shop',
        'edit.php?post_type=product'            => 'shop',
        'wc-admin&path=/analytics/overview'     => 'shop',
        'woocommerce-marketing'                 => 'shop',
        'wc-admin&path=/marketing'              => 'shop',
        'wc-admin&path=/payments/overview'      => 'shop',

        // Admin: site design comes first (WordPress's own menus), then
        // site features.
        'themes.php'                            => 'admin',
        'plugins.php'                           => 'admin',
        'tools.php'                             => 'admin',
        'options-general.php'                   => 'admin',
        'complianz'                             => 'admin',
        'kadence-blocks'                        => 'admin',
        'mdp_readabler_settings'                => 'admin',
        'ultimate-410'                          => 'admin',
        'wpsocialninja.php'                     => 'admin',
        'wp-grid-builder'                       => 'admin',
        'ninja_tables'                          => 'admin',
        'st_options'                            => 'admin',
        'syndication_links'                     => 'admin',
        'affiliate-wp'                          => 'admin',
        'ics-calendar'                          => 'admin',
        'kadence-conversions'                   => 'admin',
        'kadence-starter'                       => 'admin',
        'kadence-shop-kit-settings'             => 'admin',
        'edit.php?post_type=kadence_element'    => 'admin',
        'edit.php?post_type=kadence_form'       => 'admin',
        'edit.php?post_type=kadence_cloud'      => 'admin',
        'comment-goblin'                        => 'admin',
        'wp-map-block'                          => 'admin',
        'spectra'                               => 'admin',
        'bookly-cloud-menu'                     => 'admin',

        // Super Admin: developer tools, caching, security and site plumbing.
        'seoprostack'                           => 'super-admin',
        'litespeed'                             => 'super-admin',
        'CompressX'                             => 'super-admin',
        'WP-Optimize'                           => 'super-admin',
        'really-simple-security'                => 'super-admin',
        'gplvault_settings'                     => 'super-admin',
        'snippets'                              => 'super-admin',
        'fluent-snippets'                       => 'super-admin',
        'GOTMLS-settings'                       => 'super-admin',
        'eos_dp_menu'                           => 'super-admin',
        'fdp_hidden_menu'                       => 'super-admin',
        'flying-pages'                          => 'super-admin',
        'flying-scripts'                        => 'super-admin',
        'flying-analytics'                      => 'super-admin',
        'famne-admin'                           => 'super-admin',
        'admin-bar-dashboard-control'           => 'super-admin',
        'hide-admin-notices'                    => 'super-admin',
        'disable-bloat'                         => 'super-admin',
        'wp-widget-disable'                     => 'super-admin',
        'http-requests-manager'                 => 'super-admin',
        'wp-crontrol'                           => 'super-admin',
        'wp-crontrol-schedules'                 => 'super-admin',
        'crontrol_admin_manage_page'            => 'super-admin',
        'debug-log-manager'                     => 'super-admin',
        'imfs_settings'                         => 'super-admin',
        'imsanity/imsanity.php'                 => 'super-admin',
        'remove-cpt-base.php'                   => 'super-admin',
        'scalabilitypro'                        => 'super-admin',
        'superspeedy'                           => 'super-admin',
        'wp-migrate-db-pro'                     => 'super-admin',
        'wp-migrate-db'                         => 'super-admin',
        'advanced_db_cleaner'                   => 'super-admin',
        'mainwp_child_tab'                      => 'super-admin',
        'code-profiler-pro'                     => 'super-admin',
        'cfturnstile'                           => 'super-admin',
        'acfbs_admin_page'                      => 'super-admin',
        'edit.php?post_type=acf-field-group'    => 'super-admin',
        'da_hm_connections'                     => 'super-admin',
        'daexthrmal_connections'                => 'super-admin',
        // WP Sheet Editor add-ons' welcome pages (also hidden, below);
        // their editors sit in Posts, Products, Users and WooCommerce.
        'wpsett_welcome_page'                   => 'super-admin',
        'wpseu_welcome_page'                    => 'super-admin',
        'wpsewcc_welcome_page'                  => 'super-admin',
        'wpsewcp_welcome_page'                  => 'super-admin',
        'wpfactory'                             => 'super-admin',
        'action-scheduler'                      => 'super-admin',
        'scporder-settings'                     => 'super-admin',
        'webmention-tools'                      => 'super-admin',
        'indieweb'                              => 'super-admin',
        'print-my-blog-projects'                => 'super-admin',
        'twentig'                               => 'super-admin',
        'aiowpsec'                              => 'super-admin',
        'Wordfence'                             => 'super-admin',
        'kadence_build_child_theme_config'      => 'super-admin',
        'editorskit-getting-started'            => 'super-admin',
        'menu_editor'                           => 'super-admin',
        'ws-admin-bar-editor'                   => 'super-admin',
        // YellowPencil restyles the whole site. Its editor pages have no
        // menu of their own (they sit under its About page), so they are
        // placed here to be refused to people who are not developers.
        'yellow-pencil-changes'                 => 'super-admin',
        'yellow-pencil-editor'                  => 'super-admin',
        'yellow-pencil-customize-type'          => 'super-admin',

        // Pages that belong inside another menu.
        'yellow-pencil'                         => 'yellow-pencil-changes',
        'easy-watermark'                        => 'upload.php',
        'imsanity-options'                      => 'upload.php',
        'terms-conditions'                      => 'complianz',
        'fluent_pdf_settings'                   => 'fluent_forms',
        'pdp-dashboard'                         => 'options-general.php',
        'kadence-shareoptions'                  => 'options-general.php',
        'litespeed-cache-options'               => 'litespeed',
        'kadence_plugin_activation'             => 'kadence-blocks',
        'lw-software-manager'                   => 'kadence-blocks',
        'eos_dp_pro_import_export'              => 'eos_dp_menu',
        'mwai_dashboard'                        => 'meowapps-main-menu',
        'mwai_content_generator'                => 'meowapps-main-menu',
        'mwai_images_generator'                 => 'meowapps-main-menu',
        'mwai_videos_generator'                 => 'meowapps-main-menu',
    ),

    'plugins' => array(
        // Communications.
        'fluent-boards'                         => 'communications',
        'fluent-booking'                        => 'communications',
        'fluent-crm'                            => 'communications',
        'fluent-smtp'                           => 'communications',
        'fluent-support'                        => 'communications',
        'fluent-community'                      => 'communications',
        'fluentform'                            => 'communications',
        'bookly-responsive-appointment-booking-tool' => 'communications',

        // SEO.
        'seo-by-rank-math'                      => 'seo',
        'seo-by-rank-math-pro'                  => 'seo',
        'link-whisper-premium'                  => 'seo',
        'pretty-link'                           => 'seo',
        'search-console'                        => 'seo',
        'wordpress-seo'                         => 'seo',
        'surferseo'                             => 'seo',

        // Shop.
        'woocommerce'                           => 'shop',
        'fluent-cart'                           => 'shop',
        'woocommerce-subscriptions'             => 'shop',
        'woocommerce-payments'                  => 'shop',

        // Content.
        'tutor'                                 => 'content',
        'tutor-pro'                             => 'content',

        // Super Admin.
        'seoprostack'                           => 'super-admin',
        'litespeed-cache'                       => 'super-admin',
        'compressx'                             => 'super-admin',
        'compressx-multisite'                   => 'super-admin',
        'wp-optimize'                           => 'super-admin',
        'wp-optimize-premium'                   => 'super-admin',
        'really-simple-ssl'                     => 'super-admin',
        'really-simple-ssl-pro'                 => 'super-admin',
        'really-simple-ssl-pro-multisite'       => 'super-admin',
        'gplvault-updater'                      => 'super-admin',
        'code-snippets'                         => 'super-admin',
        'code-snippets-pro'                     => 'super-admin',
        'easy-code-manager'                     => 'super-admin',
        'gotmls'                                => 'super-admin',
        'plugin-check'                          => 'super-admin',
        'git-updater'                           => 'super-admin',
        'disable-wordpress-updates'             => 'super-admin',
        'eos-deactivate-plugins'                => 'super-admin',
        'freesoul-deactivate-plugins'           => 'super-admin',
        'freesoul-deactivate-plugins-pro'       => 'super-admin',
        'flying-pages'                          => 'super-admin',
        'flying-scripts'                        => 'super-admin',
        'flying-analytics'                      => 'super-admin',
        'manage-notification-emails'            => 'super-admin',
        'admin-bar-dashboard-control'           => 'super-admin',
        'hide-admin-notices'                    => 'super-admin',
        'disable-dashboard-for-woocommerce-pro' => 'super-admin',
        'wp-widget-disable'                     => 'super-admin',
        'http-requests-manager'                 => 'super-admin',
        'wp-crontrol'                           => 'super-admin',
        'debug-log-manager'                     => 'super-admin',
        'index-wp-mysql-for-speed'              => 'super-admin',
        'imsanity'                              => 'super-admin',
        'remove-cpt-base'                       => 'super-admin',
        'scalability-pro'                       => 'super-admin',
        'wp-migrate-db'                         => 'super-admin',
        'wp-migrate-db-pro'                     => 'super-admin',
        'advanced-database-cleaner'             => 'super-admin',
        'advanced-database-cleaner-pro'         => 'super-admin',
        'mainwp-child'                          => 'super-admin',
        'mainwp-child-reports'                  => 'super-admin',
        'code-profiler-pro'                     => 'super-admin',
        'simple-cloudflare-turnstile'           => 'super-admin',
        'acf-better-search'                     => 'super-admin',
        'secure-custom-fields'                  => 'super-admin',
        'advanced-custom-fields'                => 'super-admin',
        'advanced-custom-fields-pro'            => 'super-admin',
        'hreflang-manager'                      => 'super-admin',
        'hreflang-manager-lite'                 => 'super-admin',
        'remove-old-slugspermalinks'            => 'super-admin',
        'simple-custom-post-order'              => 'super-admin',
        'plugin-toggle'                         => 'super-admin',
        'plugin-groups'                         => 'super-admin',
        'query-monitor'                         => 'super-admin',
        'string-locator'                        => 'super-admin',
        'user-switching'                        => 'super-admin',
        'wordfence'                             => 'super-admin',
        'all-in-one-wp-security-and-firewall'   => 'super-admin',
        'kadence-build-child-defaults'          => 'super-admin',
        'admin-menu-editor'                     => 'super-admin',
        'admin-menu-editor-pro'                 => 'super-admin',
        'wp-toolbar-editor'                     => 'super-admin',
        'hostinger'                             => 'super-admin',
        'hostinger-auto-updates'                => 'super-admin',
        'performant-translations'               => 'super-admin',
        'action-scheduler'                      => 'super-admin',
        'wp-fix-plugin-does-not-exist-notices'  => 'super-admin',
        'waspthemes-yellow-pencil'              => 'super-admin',
        'yellow-pencil-visual-theme-customizer' => 'super-admin',
    ),

    // Admin bar items of plugins, by plugin folder: removed for people who
    // are not developers while the plugin is placed in Developers, so they
    // get no link to a page they are refused.
    'bar' => array(
        'waspthemes-yellow-pencil'              => array('yp'),
        'yellow-pencil-visual-theme-customizer' => array('yp'),
    ),

    // Entries left out of the menu. Their pages still open, and keep the
    // place given above for the safeguards. Upgrade links (Upgrade, Go Pro,
    // Get Pro, Unlock Pro and the like) and pages without a name are left
    // out without being listed.
    'hidden' => array(
        // Freesoul's holder for its pages; Freesoul hides it too, but only
        // after the organised menu is built.
        'fdp_hidden_menu',
        // Freemius opt-in prompts that WP Sheet Editor add-ons show as menus
        // until someone opts in or skips.
        'wpsett_welcome_page',
        'wpseu_welcome_page',
        'wpsewcc_welcome_page',
        'wpsewcp_welcome_page',
        // AutomatorWP's advert for another plugin.
        'https://wordpress.org/plugins/shortlinkspro',
    ),
);
