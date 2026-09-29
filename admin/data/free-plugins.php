<?php
/**
 * Recommended plugins data for Allstars plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define recommended plugins
function allstars_get_free_plugins() {
    return array(
        'minimal' => array(
            'antispam-bee',
            'compressx',
            'fluent-smtp',
            'kadence-blocks',
            'simple-cloudflare-turnstile'
        ),
        'admin' => array(
            'admin-bar-dashboard-control',
            'codepress-admin-columns',
            'admin-menu-editor',
            'hide-admin-notices',
            'mainwp-child',
            'mainwp-child-reports',
            'manage-notification-emails',
            'plugin-groups',
            'plugin-toggle'
        ),
        'affiliates' => array(
            'pretty-link',
            'simple-urls',
            'slicewp'
        ),
        'ai' => array(
            'ai-engine',
        ),
        'cms' => array(
            'block-options',
            'bookmark-card',
            'browser-shots',
            'bulk-actions-select-all',
            'bulk-edit-categories-tags',
            'bulk-edit-user-profiles-in-spreadsheet',
            'carbon-copy',
            'code-block-pro',
            'ics-calendar',
            'mammoth-docx-converter',
            'nav-menu-roles',
            'ninja-tables',
            'post-draft-preview',
            'post-type-switcher',
            'simple-custom-post-order',
            'simple-icons',
            'sticky-posts-switch',
            'term-management-tools',
            'the-paste',
            'ultimate-addons-for-gutenberg',
            'wikipedia-preview',
            'wp-sheet-editor-bulk-spreadsheet-editor-for-posts-and-pages'
        ),
        'compliance' => array(
            'avatar-privacy',
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
            'easy-watermark',
            'enable-media-replace',
            'image-copytrack',
            'imsanity',
            'media-file-renamer',
            'safe-svg'
        ),
        'members' => array(
            'content-control'
        ),
        'seo' => array(
            'burst-statistics',
            'pretty-link',
            'revive-so',
            'seo-by-rank-math',
            'syndication-links',
            'ultimate-410',
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
            'flying-analytics',
            'flying-pages',
            'flying-scripts',
            'freesoul-deactivate-plugins',
            'http-requests-manager',
            'index-wp-mysql-for-speed',
            'litespeed-cache',
            'wp-optimize',
            'wp-widget-disable'
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
            'remove-cpt-base',
            'remove-old-slugspermalinks',
            'secure-custom-fields',
            'yellow-pencil-visual-theme-customizer'
        ),
        'debug' => array(
            'advanced-database-cleaner',
            'debug-log-manager',
            'gotmls',
            'query-monitor',
            'string-locator',
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
function allstars_get_removed_plugins() {
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
            'replacement' => 'Candidate for a lightweight Allstars feature.',
        ),
    );
}
