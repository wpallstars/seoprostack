<?php
/**
 * Recommended plugins data for WP ALLSTARS plugin
 */

// Define recommended plugins
function wp_allstars_get_recommended_plugins() {
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
            'magic-login',
            'manage-notification-emails',
            'plugin-groups',
            'plugin-toggle'
        ),
        'affiliates' => array(
            'pretty-links',
            'simple-urls',
            'slicewp'
        ),
        'ai' => array(
            'ai-engine',
        ),
        'cms' => array(
            'auto-post-scheduler',
            'block-options',
            'bookmark-card',
            'browser-shots',
            'bulk-actions-select-all',
            'bulk-edit-categories-tags',
            'bulk-edit-user-profiles-in-spreadsheet',
            'carbon-copy',
            'code-block-pro',
            'iframe-block',
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
            'wp-social-ninja',
            'wp-social-reviews'
        ),
        'speed' => array(
            'disable-wordpress-updates',
            'flying-analytics',
            'flying-pages',
            'flying-scripts',
            'freesoul-deactivate-plugins',
            'index-wp-mysql-for-speed',
            'litespeed-cache',
            'performant-translations',
            'wp-optimize',
            'wp-widget-disable'
        ),
        'translation' => array(
            'hreflang-manager-lite',
            'performant-translations',
            'translatepress-multilingual'
        ),
        'advanced' => array(
            'acf-better-search',
            'advanced-custom-fields',
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
