<?php
/**
 * Pro Plugins Configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

// FlyingPress and Link Whisper are not recommended, at the owner's request.
 function seoprostack_get_pro_plugins() {
    return array(
        'admin-columns' => array(
            'name' => 'Admin Columns Pro',
            'description' => 'Advanced admin columns management with sorting, filtering, and editing capabilities.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.admincolumns.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.admincolumns.com/pricing/'
                )
            ),
            'free_slug' => 'codepress-admin-columns'
        ),
        // Admin Menu Editor Pro: replaced by Organise the admin menu.
        'advanced-database-cleaner' => array(
            'name' => 'Advanced Database Cleaner PRO',
            'description' => 'Clean and optimize your WordPress database with advanced tools and automation.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://sigmaplugin.com/downloads/wordpress-advanced-database-cleaner',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://sigmaplugin.com/downloads/wordpress-advanced-database-cleaner/#price_table'
                )
            ),
            'free_slug' => 'advanced-database-cleaner'
        ),
        'ai-engine' => array(
            'name' => 'AI Engine (Pro)',
            'description' => 'Enhanced AI capabilities for content generation, analysis, and automation.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://meowapps.com/plugin/ai-engine/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://meowapps.com/ai-engine-pricing/'
                )
            ),
            'free_slug' => 'ai-engine'
        ),
        'code-profiler' => array(
            'name' => 'Code Profiler Pro',
            'description' => 'Advanced performance monitoring and debugging tools for WordPress.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://codeprofiler.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://codeprofiler.io/#pricing'
                )
            )
        ),
        'code-snippets' => array(
            'name' => 'Code Snippets Pro',
            'description' => 'Add and manage custom code snippets with advanced features and management tools.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://codesnippets.pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://codesnippets.pro/pricing/'
                )
            ),
            'free_slug' => 'code-snippets'
        ),
        'comment-goblin' => array(
            'name' => 'Comment Goblin',
            'description' => 'Advanced comment management and spam protection system.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://commentgoblin.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://commentgoblin.com/#pricing'
                )
            )
        ),
        'complianz-gdpr' => array(
            'name' => 'Complianz Privacy Suite',
            'description' => 'Complete GDPR/CCPA compliance solution with advanced features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://complianz.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://complianz.io/pricing/'
                )
            ),
            'free_slug' => 'complianz-gdpr'
        ),
        // Disable Bloat PRO: replaced by Tidy WooCommerce admin, Lighter
        // WooCommerce pages, Remove WordPress extras, Tidy the login screen,
        // Tidy admin screens and Simpler block editor.
        'fluent-crm' => array(
            'name' => 'FluentCRM Pro',
            'description' => 'Advanced CRM and email marketing automation.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentcrm.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentcrm.com/pricing/?ref=4630'
                ),
                array(
                    'text' => 'Automation Pack',
                    'url' => 'https://fluentcrm.com/modules/automation-pack/?ref=4630'
                )
            ),
            'free_slug' => 'fluent-crm'
        ),
        'fluent-forms' => array(
            'name' => 'Fluent Forms Pro',
            'description' => 'Advanced form builder with premium features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentforms.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentforms.com/pricing/?ref=4630'
                ),
                array(
                    'text' => 'PDF Add-on',
                    'url' => 'https://fluentforms.com/modules/pdf-add-on/?ref=4630'
                )
            ),
            'free_slug' => 'fluentform'
        ),
        'mainwp' => array(
            'name' => 'MainWP Pro',
            'description' => 'Manage multiple WordPress sites from a single dashboard with advanced features.',
            'button_group' => array(
                array(
                    'text' => 'Go Pro',
                    'url' => 'https://mainwp.com/upgrade/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://mainwp.com/purchase/'
                )
            ),
            'free_slug' => 'mainwp-child'
        ),
        'revive-so' => array(
            'name' => 'Revive.so Pro',
            'description' => 'Advanced content optimization and SEO tool for WordPress.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://revive.so/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://revive.so/pricing/'
                )
            ),
            'free_slug' => 'revive-so'
        ),
        'fluent-support' => array(
            'name' => 'Fluent Support Pro',
            'description' => 'Premium help desk and support ticket system.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentsupport.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentsupport.com/pricing/?ref=4630'
                )
            ),
            'free_slug' => 'fluent-support'
        ),
        'fluentbooking' => array(
            'name' => 'FluentBooking Pro',
            'description' => 'Advanced booking and scheduling system.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentbooking.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentbooking.com/pricing/?ref=4630'
                )
            ),
            'free_slug' => 'fluent-booking'
        ),
        'fluent-cart' => array(
            'name' => 'FluentCart Pro',
            'description' => 'Online store extras: software licences, inventory tracking, order bumps, advanced reports and more payment gateways.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentcart.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentcart.com/pricing/?ref=4630'
                ),
                array(
                    'text' => 'Free vs Pro',
                    'url' => 'https://fluentcart.com/free-vs-pro/?ref=4630'
                )
            ),
            'free_slug' => 'fluent-cart'
        ),
        'kadence-blocks' => array(
            'name' => 'Kadence Blocks Pro',
            'description' => 'Premium blocks, theme and templates for the WordPress editor, sold together as Kadence bundles.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.liquidweb.com/software/kadence/blocks/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.liquidweb.com/software/kadence/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1#pricing'
                ),
                array(
                    'text' => 'Theme Pro',
                    'url' => 'https://www.liquidweb.com/software/kadence/theme/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1'
                ),
                array(
                    'text' => 'Kadence bundles',
                    'url' => 'https://www.liquidweb.com/software/kadence/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1'
                )
            ),
            'free_slug' => 'kadence-blocks'
        ),
        'media-file-renamer' => array(
            'name' => 'Media File Renamer Pro',
            'description' => 'AI-Powered media file renaming for better SEO.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://meowapps.com/plugin/media-file-renamer/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://meowapps.com/media-file-renamer-pricing/'
                )
            ),
            'free_slug' => 'media-file-renamer'
        ),
        'ninja-tables' => array(
            'name' => 'Ninja Tables Pro',
            'description' => 'Advanced table creation and management with premium features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://wpmanageninja.com/ninja-tables/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://wpmanageninja.com/downloads/ninja-tables-pro-add-on/?ref=4630'
                )
            ),
            'free_slug' => 'ninja-tables'
        ),
        'seo-by-rank-math' => array(
            'name' => 'Rank Math SEO PRO',
            'description' => 'Advanced SEO tools and features for better search engine optimization.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://rankmath.com/?ref=marcus%20quinn',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://rankmath.com/pricing/'
                )
            ),
            'free_slug' => 'seo-by-rank-math'
        ),
        'really-simple-ssl' => array(
            'name' => 'Really Simple SSL Pro',
            'description' => 'Advanced SSL management and security features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://really-simple-ssl.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://really-simple-ssl.com/pro/'
                )
            ),
            'free_slug' => 'really-simple-ssl'
        ),
        'scalability-pro' => array(
            'name' => 'Scalability Pro',
            'description' => 'Advanced performance optimization and scaling tools.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.superspeedyplugins.com/product/scalability-pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.superspeedyplugins.com/product/scalability-pro/#ss-pricing'
                )
            )
        ),
        'social-engine' => array(
            'name' => 'Social Engine Pro',
            'description' => 'Advanced social media scheduling and management.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://meowapps.com/plugin/social-engine/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://meowapps.com/social-engine-pricing/'
                )
            ),
            'free_slug' => 'social-engine'
        ),
        'taxopress' => array(
            'name' => 'TaxoPress Pro',
            'description' => 'Advanced taxonomy and tag management tools.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://taxopress.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://taxopress.com/pro/'
                )
            )
        ),
        'tutor' => array(
            'name' => 'Tutor LMS Pro',
            'description' => 'Premium LMS features including certificate builder.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.themeum.com/product/tutor-lms/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.themeum.com/product/tutor-lms/#pricing'
                ),
                array(
                    'text' => 'Certificate Builder',
                    'url' => 'https://www.themeum.com/product/tutor-lms-certificate-builder/'
                ),
                array(
                    'text' => 'Course Preview',
                    'url' => 'https://www.themeum.com/product/tutor-lms-course-preview/'
                )
            ),
            'free_slug' => 'tutor'
        ),
        'wp-migrate' => array(
            'name' => 'WP Migrate',
            'description' => 'Professional WordPress migration and backup solution.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://deliciousbrains.com/wp-migrate-db-pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://deliciousbrains.com/wp-migrate-db-pro/pricing/'
                )
            )
        ),
        'wp-social-ninja' => array(
            'name' => 'WP Social Ninja Pro',
            'description' => 'Advanced social media integration and management tools.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://wpsocialninja.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://wpsocialninja.com/price/?ref=4630'
                )
            ),
            'free_slug' => 'wp-social-reviews'
        ),
        'yellow-pencil' => array(
            'name' => 'YellowPencil Pro',
            'description' => 'Advanced visual CSS style editor and customization tool.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://yellowpencil.waspthemes.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://yellowpencil.waspthemes.com/pricing/'
                )
            ),
            'free_slug' => 'yellow-pencil-visual-theme-customizer'
        ),
        'fluent-boards' => array(
            'name' => 'FluentBoards Pro',
            'description' => 'Advanced dashboard and reporting solution for WordPress.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentboards.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentboards.com/pricing/?ref=4630'
                )
            ),
            'free_slug' => 'fluent-boards'
        ),
        'fluent-community' => array(
            'name' => 'FluentCommunity Pro',
            'description' => 'Advanced community and membership platform for WordPress.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentcommunity.co/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentcommunity.co/pricing/?ref=4630'
                )
            ),
            'free_slug' => 'fluent-community'
        ),
        'wp-sheet-editor' => array(
            'name' => 'WP Sheet Editor',
            'description' => 'Edit WordPress content in spreadsheet-like interface with bulk editing capabilities.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://wpsheeteditor.com/',
                    'primary' => true
                )
            ),
            'free_slug' => 'wp-sheet-editor-bulk-spreadsheet-editor-for-posts-and-pages'
        ),
        // Pretty Links Pro: replaced by Short links.
        'kadence-starter-templates' => array(
            'name' => 'AI Powered Starter Templates by Kadence WP',
            'description' => 'Premium AI-powered starter templates for WordPress with advanced customization options.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.liquidweb.com/software/kadence/kadence-template-gallery/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1',
                    'primary' => true
                ),
                array(
                    'text' => 'Kadence bundles',
                    'url' => 'https://www.liquidweb.com/software/kadence/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1'
                )
            ),
            'free_slug' => 'kadence-starter-templates'
        ),
        'bit-social' => array(
            'name' => 'Bit Social Pro',
            'description' => 'Premium social networking features for WordPress with advanced community building tools.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://bit-social.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://bit-social.com/#pricing'
                ),
                array(
                    'text' => 'Bit Apps store',
                    'url' => 'https://bitapps.pro?r=8064'
                )
            ),
            'free_slug' => 'bit-social'
        ),
        'easy-video-reviews' => array(
            'name' => 'Easy Video Reviews Pro',
            'description' => 'Premium video review collection and display features for WordPress.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://wppool.dev/easy-video-reviews/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://wppool.dev/easy-video-reviews-pricing/'
                )
            ),
            'free_slug' => 'easy-video-reviews'
        ),
        'translatepress' => array(
            'name' => 'TranslatePress Pro',
            'description' => 'Advanced WordPress translation plugin with premium features for multilingual websites.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://translatepress.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://translatepress.com/pricing/'
                )
            ),
            'free_slug' => 'translatepress-multilingual'
        ),
        'hreflang-manager' => array(
            'name' => 'Hreflang Manager Pro',
            'description' => 'Advanced hreflang tag management for multilingual and multi-regional WordPress websites.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://daext.com/hreflang-manager/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://daext.com/hreflang-manager/#pricing'
                )
            ),
            'free_slug' => 'hreflang-manager-lite'
        ),
        'automatorwp' => array(
            'name' => 'AutomatorWP Pro',
            'description' => 'Advanced WordPress automation toolkit with premium integrations and features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://automatorwp.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://automatorwp.com/'
                ),
                array(
                    'text' => 'Integrations',
                    'url' => 'https://automatorwp.com/all-triggers-and-actions/'
                )
            ),
            'free_slug' => 'automatorwp'
        ),
        'bit-integrations' => array(
            'name' => 'Bit Integrations Pro',
            'description' => 'Advanced WordPress integration platform with premium connectors and automation features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://bit-integrations.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://bit-integrations.com/#pricing'
                ),
                array(
                    'text' => 'Integrations',
                    'url' => 'https://bit-integrations.com/all-integrations/'
                ),
                array(
                    'text' => 'Bit Apps store',
                    'url' => 'https://bitapps.pro?r=8064'
                )
            ),
            'free_slug' => 'bit-integrations'
        ),
        'bit-flows' => array(
            'name' => 'Bit Flows Pro',
            'description' => 'Advanced workflow automation platform for WordPress with premium features and integrations.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://bit-flows.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://bit-flows.com/pricing/'
                ),
                array(
                    'text' => 'Integrations',
                    'url' => 'https://bit-flows.com/integrations-list/'
                ),
                array(
                    'text' => 'Bit Apps store',
                    'url' => 'https://bitapps.pro?r=8064'
                )
            ),
            'free_slug' => 'bit-pi'
        ),
        'gotmls' => array(
            'name' => 'Anti-Malware Pro',
            'description' => 'Advanced WordPress malware scanner and security toolkit with premium features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://gotmls.net/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://gotmls.net/donate/'
                )
            ),
            'free_slug' => 'gotmls'
        ),
        // Content Control Pro: replaced by Restrict content (roles come from
        // the form, shop and CRM plugins listed here).
        'eventon' => array(
            'name' => 'EventON',
            'description' => 'Premium WordPress event calendar plugin with advanced features and add-ons.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.myeventon.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Add-ons',
                    'url' => 'https://www.myeventon.com/addons/'
                )
            )
        ),
        'fluent-affiliate' => array(
            'name' => 'FluentAffiliate Pro',
            'description' => 'Manage an affiliate programme with referrals, commissions, partner dashboards and payouts.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentaffiliate.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentaffiliate.com/?ref=4630#pricing'
                )
            ),
            'free_slug' => 'fluent-affiliate'
        ),
        'fluent-player' => array(
            'name' => 'FluentPlayer Pro',
            'description' => 'A video player with lead capture, forms, playlists and viewing reports.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentplayer.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentplayer.com/pricing/?ref=4630'
                )
            ),
            'free_slug' => 'fluent-player'
        ),
        'paymattic' => array(
            'name' => 'Paymattic Pro',
            'description' => 'Payment and donation forms with subscriptions, recurring donations and more payment gateways.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://paymattic.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://paymattic.com/pricing/?ref=4630'
                )
            ),
            'free_slug' => 'wp-payment-form'
        ),
        'azonpress' => array(
            'name' => 'AzonPress',
            'description' => 'Amazon affiliate product displays, comparison tables and affiliate link management.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://azonpress.com/?ref=4630',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://azonpress.com/pricing/?ref=4630'
                )
            )
        )
    );
}
