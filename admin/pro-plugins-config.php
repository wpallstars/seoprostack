<?php
/**
 * Pro Plugins Configuration
 */

function wp_allstars_get_pro_plugins_config() {
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
                    'url' => 'https://sigmaplugin.com/downloads/wordpress-advanced-database-cleaner'
                )
            )
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
            'description' => 'Advanced form builder with premium features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentforms.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentforms.com/pricing/'
                ),
                array(
                    'text' => 'PDF Add-on',
                    'url' => 'https://fluentforms.com/modules/pdf-add-on/'
                )
            ),
            'free_slug' => 'fluentform'
        ),
        'fluent-support' => array(
            'name' => 'Fluent Support Pro',
            'description' => 'Premium help desk and support ticket system.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://fluentsupport.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentsupport.com/pricing/'
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
                    'url' => 'https://fluentbooking.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentbooking.com/pricing/'
                )
            ),
            'free_slug' => 'fluent-booking'
        ),
        'freesoul-deactivate-plugins' => array(
            'name' => 'Freesoul Deactivate Plugins PRO',
            'description' => 'Advanced plugin management with conditional loading and optimization.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://freesoul-deactivate-plugins.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://freesoul-deactivate-plugins.com/pro/'
                )
            ),
            'free_slug' => 'freesoul-deactivate-plugins'
        ),
        'kadence-blocks' => array(
            'name' => 'Kadence Blocks Pro',
            'description' => 'Premium blocks and templates for the WordPress editor.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.kadencewp.com/kadence-blocks/pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.kadencewp.com/pricing/'
                ),
                array(
                    'text' => 'Theme Pro',
                    'url' => 'https://www.kadencewp.com/kadence-theme/pro/'
                )
            ),
            'free_slug' => 'kadence-blocks'
        ),
        'link-whisper' => array(
            'name' => 'Link Whisper',
            'description' => 'AI-powered internal linking suggestions and management.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://linkwhisper.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://linkwhisper.com/pricing/'
                )
            )
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
                    'url' => 'https://wpmanageninja.com/ninja-tables/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://wpmanageninja.com/downloads/ninja-tables-pro-add-on/'
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
                    'url' => 'https://scalability.pro/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://scalability.pro/#pricing'
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
                    'url' => 'https://wpsocialninja.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://wpsocialninja.com/pricing/'
                )
            )
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
                    'url' => 'https://fluentboards.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentboards.com/pricing/'
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
                    'url' => 'https://fluentcommunity.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://fluentcommunity.com/pricing/'
                )
            ),
            'free_slug' => 'fluent-community'
        )
    );
} 