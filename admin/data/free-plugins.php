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
// Pretty Links, Browser Shots, Spectra (ultimate-addons-for-gutenberg;
// Kadence Blocks is listed for page building), Admin Menu Editor, Nav Menu
// Roles, Post Type Switcher, Simple Custom Post Order, Term Management Tools,
// ACF: Better Search (Search custom fields), Slugs Manager: Delete Old
// Permalinks (Old post addresses; closed on WordPress.org on 2026-04-27),
// Bookmark Card (Link cards), Wikipedia Preview (Wikipedia previews),
// Mammoth .docx converter (Word documents in the editor), Favorites
// (Like, save and share) and Popular Brand Icons – Simple Icons (Brand
// icons).
// String Locator is not listed: searching code is better done in an editor
// or with WP-CLI than from wp-admin.
// EditorsKit (block-options) is not listed: its last update was in May 2024,
// tested up to WordPress 6.5, and WordPress and Kadence Blocks cover its
// features (block visibility by login state and device, underline, highlight,
// letter case, nofollow links).
// Git Updater is not listed: Updates from GitHub replaces it in builds from
// GitHub releases (includes/features/class-seoprostack-github-updates.php).
// Freesoul Deactivate Plugins (free and PRO) is not listed: Load plugins only
// where needed does its job in wp-admin and for the whole site. It does not
// set "replaces" yet, so the Plugins screen does not suggest removing it,
// until page-by-page rules on the site are covered too (GitHub issue #90).
// Disable All WordPress Updates is not listed: the Speed and Plugins
// features cover why it was used (fewer outgoing requests, a faster
// wp-admin), and it stops WordPress's own update request, which also hides
// updates from GitHub releases. Security updates should keep arriving.
function seoprostack_get_free_plugins() {
    /**
     * Filter the recommended plugins by category. Slugs are WordPress.org
     * slugs, or keys of `seoprostack_external_plugins` for plugins from
     * elsewhere.
     *
     * @param array<string,string[]> $plugins Category => slugs.
     */
    return (array) apply_filters('seoprostack_free_plugins', array(
        'minimal' => array(
            'antispam-bee',
            'fluent-smtp',
            'kadence-blocks',
            'simple-cloudflare-turnstile'
        ),
        'admin' => array(
            'codepress-admin-columns',
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
            'bulk-edit-categories-tags',
            'bulk-edit-user-profiles-in-spreadsheet',
            'code-block-pro',
            'ics-calendar',
            'ninja-tables',
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
            'fluent-cart',
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
            'automatorwp',
            'bit-pi',
            'bit-integrations',
            'code-snippets',
            'easy-code-manager',
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
    ));
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
    );
}
