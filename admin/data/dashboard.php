<?php
/**
 * How "Tidy the dashboard" lays out Dashboard widgets.
 *
 * Built from the Dashboard settings of 12 sites arranged by hand with Admin
 * Menu Editor Pro, so the layout matches what people already know.
 *
 * - columns:    column => widget IDs, top to bottom. normal is the first
 *               column, side the second, column3 the third. Widgets that
 *               are not listed stay in the column their plugin chose,
 *               below the listed ones.
 * - hidden:     widgets nobody sees: news, plugin promotions and boxes
 *               that repeat a plugin's own screen.
 * - developers: widgets only developers see (see Developer admins;
 *               without it, people who can manage options).
 * - reports:    statistics only people who can publish see (not
 *               contributors).
 * - start_hidden: widgets that start unticked in Screen Options, once
 *               per person (also people who saved Screen Options before);
 *               ticking one shows it from then on.
 *
 * People who cannot edit posts (subscribers, customers) see no widgets,
 * and the Welcome panel is hidden for everyone.
 *
 * Filter with `seoprostack_dashboard_layout`.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'columns'      => array(
        // Writing and what is happening on the site.
        'normal'  => array(
            // Core's warnings stay at the top, where WordPress puts them.
            'dashboard_browser_nag',
            'dashboard_php_nag',
            'dashboard_quick_press',
            'dashboard_activity',
            'woocommerce_dashboard_recent_reviews',
        ),
        // Forms, support and email.
        'side'    => array(
            'fluentform_stat_widget',
            'fluent_support_reports_widget',
            'fluentsmtp_reports_widget',
        ),
        // Visitors, SEO and the site itself.
        'column3' => array(
            'dashboard_widget_burst',
            'rank_math_dashboard_widget',
            'dashboard_right_now',
            'dashboard_site_health',
            'mwai_advisor_widget',
        ),
    ),
    'hidden'       => array(
        'dashboard_primary',                // WordPress Events and News.
        'woocommerce_dashboard_status',     // Repeats WooCommerce → Home.
        'wc_admin_dashboard_setup',         // WooCommerce Setup: repeats WooCommerce → Home's task list.
        'prli_quick_add',                   // Pretty Links quick add.
        'wpil_link_health_widget',          // Link Whisper promotion.
        'vg_sheet_editor_usage_stats',      // WP Sheet Editor edit counts.
        // Debug Log Manager reads and parses the whole debug log on every
        // Dashboard load (6 of 7 seconds with a 32 MB log). Its own screen
        // under Tools shows the same entries.
        'debug_log_manager_widget',
    ),
    'developers'   => array(
        'dashboard_site_health',
        'mwai_advisor_widget',              // AI Engine Advisor.
    ),
    'start_hidden' => array(
        // AI Engine Advisor: a daily list of general advice about the
        // site's plugins, too much for a Dashboard.
        'mwai_advisor_widget',
    ),
    'reports'      => array(
        'dashboard_right_now',
        'fluentform_stat_widget',
        'fluent_support_reports_widget',
        'fluentsmtp_reports_widget',
    ),
);
