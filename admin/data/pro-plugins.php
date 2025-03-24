<?php
/**
 * Pro Plugins Data
 *
 * This file contains the configuration data for premium plugins
 * displayed in the Pro Plugins tab.
 *
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get pro plugins configuration data
 *
 * @return array Array of pro plugins with their details
 */
function wp_allstars_get_pro_plugins() {
    return array(
        'magic-login-pro' => array(
            'name' => 'Magic Login Pro',
            'description' => 'Enable passwordless authentication with magic login links and enhance WordPress site security.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://handyplugins.co/magic-login-pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://handyplugins.co/magic-login-pro/#pricing'
                )
            ),
            'free_slug' => 'magic-login'
        ),
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
        'admin-menu-editor' => array(
            'name' => 'Admin Menu Editor Pro',
            'description' => 'Customize the WordPress admin menu with advanced features and role management.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://adminmenueditor.com/upgrade-to-pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://adminmenueditor.com/upgrade-to-pro/'
                )
            ),
            'free_slug' => 'admin-menu-editor'
        ),
        'advanced-custom-fields' => array(
            'name' => 'Advanced Custom Fields PRO',
            'description' => 'Create custom fields and content types with advanced features and options.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.advancedcustomfields.com/pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.advancedcustomfields.com/pro/#pricing'
                )
            ),
            'free_slug' => 'advanced-custom-fields'
        ),
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
        'disable-bloat' => array(
            'name' => 'Disable Bloat PRO',
            'description' => 'Advanced optimization for WordPress & WooCommerce with premium features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://disablebloat.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://disablebloat.com/#pricing'
                )
            )
        ),
        'fluent-crm' => array(
            'name' => 'FluentCRM Pro',
            'description' => 'Advanced CRM and email marketing automation.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentcrm.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentcrm.com/pricing/'
                ),
                array(
                    'text' => 'Automation Pack',
                    'url' => 'https://fluentcrm.com/modules/automation-pack/'
                )
            ),
            'free_slug' => 'fluent-crm'
        ),
        'fluent-forms' => array(
            'name' => 'Fluent Forms Pro',
            'description' => 'Advanced form builder with conversational forms and powerful integrations.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentforms.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentforms.com/pricing/'
                )
            ),
            'free_slug' => 'fluentform'
        ),
        'fluent-smtp' => array(
            'name' => 'FluentSMTP Pro',
            'description' => 'Advanced SMTP and email delivery solution with analytics and logs.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentsmtp.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentsmtp.com/pricing/'
                )
            ),
            'free_slug' => 'fluent-smtp'
        ),
        'flyingpress' => array(
            'name' => 'FlyingPress',
            'description' => 'Advanced WordPress performance optimization with automatic critical CSS and more.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://flyingpress.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://flyingpress.com/pricing/'
                )
            )
        ),
        'freesoul-deactivate-plugins' => array(
            'name' => 'Freesoul Deactivate Plugins PRO',
            'description' => 'Advanced plugin management with selective deactivation and performance optimization.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://freesoul-deactivate-plugins.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://freesoul-deactivate-plugins.com/pricing/'
                )
            ),
            'free_slug' => 'freesoul-deactivate-plugins'
        ),
        'generateblocks-pro' => array(
            'name' => 'GenerateBlocks Pro',
            'description' => 'Advanced block library extension with powerful features for GenerateBlocks.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://generateblocks.com/pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://generateblocks.com/pro/#pricing'
                )
            ),
            'free_slug' => 'generateblocks'
        ),
        'oxygen-builder' => array(
            'name' => 'Oxygen Builder',
            'description' => 'Advanced visual website builder with unlimited design flexibility.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://oxygenbuilder.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://oxygenbuilder.com/pricing/'
                )
            )
        ),
        'perfmatters' => array(
            'name' => 'Perfmatters',
            'description' => 'Lightweight performance plugin for WordPress to disable unused features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://perfmatters.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://perfmatters.io/pricing/'
                )
            )
        ),
        'pixelbuddha' => array(
            'name' => 'PixelBuddha PRO',
            'description' => 'Premium design assets and templates for WordPress.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://pixelbuddha.net/membership',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://pixelbuddha.net/membership'
                )
            )
        ),
        'rank-math-pro' => array(
            'name' => 'Rank Math PRO',
            'description' => 'Advanced SEO features including schema markup, rank tracking, and content analysis.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://rankmath.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://rankmath.com/pricing/'
                )
            ),
            'free_slug' => 'seo-by-rank-math'
        ),
        'self-hosted-google-fonts' => array(
            'name' => 'OMGF Pro',
            'description' => 'Advanced control over Google Fonts with local hosting and optimization.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://daan.dev/wordpress/omgf-pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://daan.dev/wordpress/omgf-pro/#pricing'
                )
            ),
            'free_slug' => 'host-webfonts-local'
        ),
        'shortpixel-image-optimizer' => array(
            'name' => 'ShortPixel Image Optimizer',
            'description' => 'Premium image optimization with advanced compression algorithms.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://shortpixel.com/otp/af/WMBPNHN1231799',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://shortpixel.com/otp/af/WMBPNHN1231799#pricing'
                )
            ),
            'free_slug' => 'shortpixel-image-optimiser'
        ),
        'simpletoc-pro' => array(
            'name' => 'Simple TOC Pro',
            'description' => 'Advanced table of contents with custom designs and display options.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://marc.tv/simpletoc-pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://marc.tv/en/checkout/purchase-history/?level=6'
                )
            ),
            'free_slug' => 'simpletoc'
        ),
        'swift-control-pro' => array(
            'name' => 'Swift Control Pro',
            'description' => 'Advanced admin bar replacement with customizable controls and shortcuts.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://swiftcontrolpro.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://swiftcontrolpro.com/#pricing'
                )
            ),
            'free_slug' => 'swift-control'
        ),
        'swiftperformance' => array(
            'name' => 'Swift Performance',
            'description' => 'Advanced performance optimization with caching, minification, and CDN integration.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://swiftperformance.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://swiftperformance.io/pricing/'
                )
            ),
            'free_slug' => 'swift-performance-lite'
        ),
        'wp-rocket' => array(
            'name' => 'WP Rocket',
            'description' => 'Premium caching and performance plugin with advanced optimization features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://wp-rocket.me/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://wp-rocket.me/pricing/'
                )
            )
        ),
        'wp-seopress-pro' => array(
            'name' => 'SEOPress Pro',
            'description' => 'Advanced SEO plugin with premium features for optimization and analytics.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.seopress.org/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.seopress.org/pricing/'
                )
            ),
            'free_slug' => 'wp-seopress'
        ),
        'woocommerce-color-swatches' => array(
            'name' => 'Variation Swatches for WooCommerce Pro',
            'description' => 'Advanced color and image swatches for WooCommerce product variations.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://getwooplugins.com/plugins/woocommerce-variation-swatches/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://getwooplugins.com/plugins/woocommerce-variation-swatches/'
                )
            ),
            'free_slug' => 'woo-variation-swatches'
        ),
        'woocommerce-subscriptions' => array(
            'name' => 'WooCommerce Subscriptions',
            'description' => 'Add subscription-based products and recurring payments to WooCommerce.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://woocommerce.com/products/woocommerce-subscriptions/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://woocommerce.com/products/woocommerce-subscriptions/'
                )
            )
        ),
        'woocommerce-wholesale-suite' => array(
            'name' => 'WooCommerce Wholesale Suite',
            'description' => 'Add wholesale functionality to your WooCommerce store.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://wholesalesuiteplugin.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://wholesalesuiteplugin.com/pricing/'
                )
            )
        ),
        'yoast-seo-premium' => array(
            'name' => 'Yoast SEO Premium',
            'description' => 'Advanced SEO tools with premium features for optimization and content analysis.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://yoast.com/wordpress/plugins/seo/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://yoast.com/wordpress/plugins/seo/#licenses'
                )
            ),
            'free_slug' => 'wordpress-seo'
        )
    );
}
