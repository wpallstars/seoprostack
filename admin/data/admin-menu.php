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
 *            for people who are not developers while safeguards are on.
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
        'search-console'                        => 'seo',
        'wpseo_dashboard'                       => 'seo',
        'surfer'                                => 'seo',

        // Shop.
        'woocommerce'                           => 'shop',
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
        'yellow-pencil-changes'                 => 'admin',
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

        // Pages that belong inside another menu.
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
    ),
);
