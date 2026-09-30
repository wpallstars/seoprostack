<?php
/**
 * Recommended plugins data for SEO Pro Stack plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define recommended plugins.
// Plugins that SEO Pro Stack features replace are not listed (see each
// feature's "replaces"): Admin Bar & Dashboard Control, Manage Notification
// E-mails, Bulk Actions Select All, Carbon Copy, Post Draft Preview, Sticky
// Posts Switch, Ultimate 410, Flying Analytics/Pages/Scripts, Widget Disable,
// Plugin Toggle, Hide Admin Notices, The Paste, Avatar Privacy, Safe SVG,
// Imsanity, Enable Media Replace, CompressX, Easy Watermark, Remove CPT base,
// Pretty Links, Browser Shots.
// String Locator is not listed: searching code is better done in an editor
// or with WP-CLI than from wp-admin.
// EditorsKit (block-options) is not listed: its last update was in May 2024,
// tested up to WordPress 6.5, and WordPress and Kadence Blocks cover its
// features (block visibility by login state and device, underline, highlight,
// letter case, nofollow links).
function seoprostack_get_free_plugins() {
    return array(
        'minimal' => array(
            'antispam-bee',
            'fluent-smtp',
            'kadence-blocks',
            'simple-cloudflare-turnstile'
        ),
        'admin' => array(
            'codepress-admin-columns',
            'admin-menu-editor',
            'mainwp-child',
            'mainwp-child-reports',
            'plugin-groups'
        ),
        'affiliates' => array(
            'simple-urls',
            'slicewp'
        ),
        'ai' => array(
            'ai-engine',
        ),
        'cms' => array(
            'bookmark-card',
            'bulk-edit-categories-tags',
            'bulk-edit-user-profiles-in-spreadsheet',
            'code-block-pro',
            'ics-calendar',
            'mammoth-docx-converter',
            'nav-menu-roles',
            'ninja-tables',
            'post-type-switcher',
            'simple-custom-post-order',
            'simple-icons',
            'term-management-tools',
            'ultimate-addons-for-gutenberg',
            'wikipedia-preview',
            'wp-sheet-editor-bulk-spreadsheet-editor-for-posts-and-pages'
        ),
        'compliance' => array(
            'complianz-gdpr',
            'complianz-terms-conditions',
            'really-simple-ssl'
        ),
        'crm' => array(
            'fluent-boards',
            'fluent-booking',
            'fluent-community',
            'fluent-crm',
            'fluentform',
            'fluentforms-pdf',
            'fluentform-block',
            'fluent-support'
        ),
        'ecommerce' => array(
            'woocommerce',
            'woo-bulk-edit-products',
            'woo-coupons-bulk-editor',
            'woocommerce-gateway-gocardless',
            'kadence-woocommerce-email-designer',
            'pymntpl-paypal-woocommerce',
            'woo-stripe-payment'
        ),
        'events' => array(
            'eventon-lite'
        ),
        'lms' => array(
            'fluent-community',
            'masterstudy-lms-learning-management-system',
            'tutor'
        ),
        'media' => array(
            'image-copytrack',
            'media-file-renamer'
        ),
        'members' => array(
            'content-control'
        ),
        'seo' => array(
            'burst-statistics',
            'revive-so',
            'seo-by-rank-math',
            'syndication-links',
            'webmention'
        ),
        'setup' => array(
            'kadence-starter-templates',
            'wordpress-importer'
        ),
        'social' => array(
            'bit-social',
            'easy-video-reviews',
            'social-engine',
            'wp-social-reviews'
        ),
        'speed' => array(
            'disable-wordpress-updates',
            'freesoul-deactivate-plugins',
            'http-requests-manager',
            'index-wp-mysql-for-speed',
            'litespeed-cache',
            'wp-optimize'
        ),
        'translation' => array(
            'hreflang-manager-lite',
            'translatepress-multilingual'
        ),
        'advanced' => array(
            'acf-better-search',
            'automatorwp',
            'bit-pi',
            'bit-integrations',
            'code-snippets',
            'easy-code-manager',
            'favorites',
            'remove-old-slugspermalinks',
            'secure-custom-fields',
            'yellow-pencil-visual-theme-customizer'
        ),
        'debug' => array(
            'advanced-database-cleaner',
            'debug-log-manager',
            'gotmls',
            'query-monitor',
            'user-switching',
            'wp-crontrol'
        )
    );
}

/**
 * Listed plugins that WordPress.org has closed.
 *
 * They stay listed as reminders to find or build a replacement. Cards show
 * the closure and never offer an install. Any other listed slug the API
 * reports as closed or missing gets a generic "unavailable" card.
 *
 * @return array<string,array{name:string,description:string,closed:string,reason:string,replacement:string}>
 */
function seoprostack_get_removed_plugins() {
    return array(
        'content-control' => array(
            'name'        => 'Content Control',
            'description' => 'Restrict content, menus and blocks by user role or login status.',
            'closed'      => '2026-08-12',
            'reason'      => 'Temporary closure pending a full review.',
            'replacement' => 'Needs an alternative for restricting content by role or login status.',
        ),
        'easy-video-reviews' => array(
            'name'        => 'Easy Video Reviews',
            'description' => 'Collect and display video testimonials.',
            'closed'      => '2026-08-05',
            'reason'      => 'Temporary closure pending a full review.',
            'replacement' => 'Needs an alternative for collecting video testimonials.',
        ),
        'remove-old-slugspermalinks' => array(
            'name'        => 'Slugs Manager: Delete Old Permalinks',
            'description' => 'List and delete the old slugs WordPress stores for redirects.',
            'closed'      => '2026-04-27',
            'reason'      => 'Guideline violation.',
            'replacement' => 'Candidate for a lightweight SEO Pro Stack feature.',
        ),
    );
}
