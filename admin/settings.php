<?php
/**
 * Admin settings page
 */

// Add menu item
function wp_allstars_admin_menu() {
    add_options_page(
        'WP ALLSTARS Settings',
        'WP ALLSTARS',
        'manage_options',
        'wp-allstars',
        'wp_allstars_settings_page'
    );
}
add_action('admin_menu', 'wp_allstars_admin_menu');

// Register settings
function wp_allstars_register_settings() {
    // Removed minification settings
}
add_action('admin_init', 'wp_allstars_register_settings');

// AJAX handler for settings
function wp_allstars_update_option() {
    check_ajax_referer('wp-allstars-nonce', 'nonce');
    $option = sanitize_text_field($_POST['option']);
    $value = intval($_POST['value']);
    update_option($option, $value);
    wp_send_json_success('Option updated');
}
add_action('wp_ajax_wp_allstars_update_option', 'wp_allstars_update_option');

// Define tools
function wp_allstars_get_tools() {
    return array(
        'advise' => array(
            'name' => 'Advise.so',
            'description' => 'Website analytics and optimization tool for improving user experience and conversion rates.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://advise.so/',
                    'primary' => true
                )
            )
        ),
        'seoutils' => array(
            'name' => 'SEO Utils',
            'description' => 'Collection of SEO tools to analyze and improve website search engine optimization.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://seoutils.app/',
                    'primary' => true
                )
            )
        ),
        'dataforseo' => array(
            'name' => 'DataForSEO',
            'description' => 'API-based SEO data provider for rank tracking, keyword research and competitive analysis.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://dataforseo.com/',
                    'primary' => true
                )
            )
        ),
        'ahrefs' => array(
            'name' => 'Ahrefs',
            'description' => 'Comprehensive SEO toolset for backlink analysis, keyword research, and competitor research.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://ahrefs.com/',
                    'primary' => true
                )
            )
        ),
        'localrank' => array(
            'name' => 'LocalRank.so',
            'description' => 'Local SEO tool for tracking and improving local search rankings for businesses.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://localrank.so/',
                    'primary' => true
                )
            )
        ),
        'turnithuman' => array(
            'name' => 'Turn It Human',
            'description' => 'AI content humanizer that makes AI-generated content sound more natural and authentic.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://turnithuman.com/',
                    'primary' => true
                )
            )
        ),
        'searchconsole' => array(
            'name' => 'Google Search Console',
            'description' => 'Free tool from Google to monitor and troubleshoot your site\'s presence in Google Search results.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://search.google.com/search-console/about',
                    'primary' => true
                )
            )
        ),
        'bingwebmaster' => array(
            'name' => 'Bing Webmaster Tools',
            'description' => 'Free tool from Microsoft to help optimize your website for Bing search engine.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.bing.com/webmasters/about',
                    'primary' => true
                )
            )
        ),
        'fiverr' => array(
            'name' => 'Fiverr',
            'description' => 'Freelance services marketplace for businesses to find digital services including web development.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.fiverr.com/',
                    'primary' => true
                )
            )
        ),
        'legiit' => array(
            'name' => 'Legiit',
            'description' => 'Marketplace for digital marketing services including SEO, content writing, and web design.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://legiit.com/',
                    'primary' => true
                )
            )
        ),
        'openwebui' => array(
            'name' => 'Open WebUI',
            'description' => 'Open-source web interface for interacting with AI models and chatbots.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://openwebui.com/',
                    'primary' => true
                )
            )
        ),
        'nextcloud' => array(
            'name' => 'Nextcloud',
            'description' => 'Self-hosted productivity platform and file sync solution for secure collaboration.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://nextcloud.com/',
                    'primary' => true
                )
            )
        ),
        'enpass' => array(
            'name' => 'Enpass',
            'description' => 'Password manager that stores sensitive information locally on your device.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.enpass.io/',
                    'primary' => true
                )
            )
        ),
        'pdfstudio' => array(
            'name' => 'PDF Studio',
            'description' => 'Professional PDF editor with advanced features for creating and modifying PDF documents.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.qoppa.com/pdfstudio/',
                    'primary' => true
                )
            )
        ),
        'affinity' => array(
            'name' => 'Affinity',
            'description' => 'Professional creative software suite including Photo, Designer, and Publisher applications.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://affinity.serif.com/',
                    'primary' => true
                )
            )
        ),
        'pixelmator' => array(
            'name' => 'Pixelmator Pro',
            'description' => 'Professional image editing software for Mac with powerful tools and an intuitive interface.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.pixelmator.com/pro/',
                    'primary' => true
                )
            )
        ),
        'upscayl' => array(
            'name' => 'Upscayl',
            'description' => 'Open-source AI image upscaler that enhances and enlarges images with improved quality.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://upscayl.org/',
                    'primary' => true
                )
            )
        ),
        'sitesucker' => array(
            'name' => 'SiteSucker',
            'description' => 'Website downloading tool that allows you to save entire websites for offline viewing.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://ricks-apps.com/osx/sitesucker/index.html',
                    'primary' => true
                )
            )
        ),
        'virustotal' => array(
            'name' => 'VirusTotal',
            'description' => 'Free service that analyzes files and URLs for viruses, worms, trojans, and other malicious content.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.virustotal.com/',
                    'primary' => true
                )
            )
        ),
        'transmit' => array(
            'name' => 'Transmit',
            'description' => 'File transfer client for macOS with support for FTP, SFTP, WebDAV, and cloud services.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://panic.com/transmit/',
                    'primary' => true
                )
            )
        ),
        'iterm2' => array(
            'name' => 'iTerm2',
            'description' => 'Terminal emulator for macOS with advanced features beyond the default Terminal app.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://iterm2.com/',
                    'primary' => true
                )
            )
        ),
        'cloudron' => array(
            'name' => 'Cloudron',
            'description' => 'Self-hosted platform that makes it easy to run web applications like WordPress on your server.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.cloudron.io/',
                    'primary' => true
                )
            )
        ),
        'urlmonitor' => array(
            'name' => 'URL Monitor',
            'description' => 'Website monitoring service that tracks uptime, performance, and alerts you to issues.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://urlmonitor.com/',
                    'primary' => true
                )
            )
        ),
        'speedyindex' => array(
            'name' => 'Speedy Index',
            'description' => 'Tool for monitoring website indexing speed and performance in search engines.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://en.speedyindex.com/',
                    'primary' => true
                )
            )
        ),
        'pagespeed' => array(
            'name' => 'PageSpeed Insights',
            'description' => 'Google tool that analyzes web page performance on mobile and desktop devices.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://pagespeed.web.dev/',
                    'primary' => true
                )
            )
        ),
        'windsurf' => array(
            'name' => 'Codeium Windsurf',
            'description' => 'AI-powered IDE with advanced code completion and generation capabilities.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://codeium.com/windsurf',
                    'primary' => true
                )
            )
        ),
        'lowfruits' => array(
            'name' => 'Low Fruits',
            'description' => 'SEO tool for finding low-competition keywords to target for faster ranking.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://lowfruits.io/',
                    'primary' => true
                )
            )
        ),
        'keysearch' => array(
            'name' => 'Keysearch',
            'description' => 'Affordable keyword research tool for finding valuable keywords for SEO.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.keysearch.co/',
                    'primary' => true
                )
            )
        ),
        'smartlead' => array(
            'name' => 'SmartLead',
            'description' => 'Email outreach platform for cold email campaigns and lead generation.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.smartlead.ai/',
                    'primary' => true
                )
            )
        ),
        'muraena' => array(
            'name' => 'Muraena AI',
            'description' => 'AI-powered writing assistant for creating and optimizing content.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://muraena.ai/',
                    'primary' => true
                )
            )
        ),
        'googlebusiness' => array(
            'name' => 'Google Business Profile',
            'description' => 'Free tool to manage your business presence on Google Search and Maps.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://business.google.com/',
                    'primary' => true
                )
            )
        ),
        'chatgptdetector' => array(
            'name' => 'ChatGPT Detector',
            'description' => 'Tool to detect AI-generated content from models like ChatGPT.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://textvisualization.app/chatgpt-detector/',
                    'primary' => true
                )
            )
        ),
        'zerogpt' => array(
            'name' => 'ZeroGPT',
            'description' => 'AI content detector that identifies text generated by AI models.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://zerogpt.tools/',
                    'primary' => true
                )
            )
        ),
        'zerogptplus' => array(
            'name' => 'ZeroGPT Plus',
            'description' => 'Advanced AI content detector with improved accuracy and additional features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.zerogpt.plus/en',
                    'primary' => true
                )
            )
        ),
        'neuronwriter' => array(
            'name' => 'NeuronWriter',
            'description' => 'AI-powered SEO content optimization tool for creating high-ranking content.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.neuronwriter.com/',
                    'primary' => true
                )
            )
        ),
        'serposcope' => array(
            'name' => 'Serposcope',
            'description' => 'Open-source rank tracker to monitor website positions in search engines.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.serposcope.com/en/',
                    'primary' => true
                )
            )
        ),
        'seoptimer' => array(
            'name' => 'SEOptimer',
            'description' => 'Website audit tool that provides SEO, usability, and performance recommendations.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.seoptimer.com/',
                    'primary' => true
                )
            )
        ),
        'jitsi' => array(
            'name' => 'Jitsi Meet',
            'description' => 'Free, open-source video conferencing platform with no account required.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://meet.jit.si/',
                    'primary' => true
                )
            )
        ),
        'appsumo' => array(
            'name' => 'AppSumo',
            'description' => 'Marketplace for discounted digital products and services for entrepreneurs.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://appsumo.com/',
                    'primary' => true
                )
            )
        ),
        'screenstudio' => array(
            'name' => 'Screen Studio',
            'description' => 'Screen recording software with automatic editing and professional results.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://screen.studio/',
                    'primary' => true
                )
            )
        ),
        'screenflow' => array(
            'name' => 'ScreenFlow',
            'description' => 'Professional screen recording and video editing software for macOS.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.telestream.net/screenflow/overview.htm',
                    'primary' => true
                )
            )
        ),
        'acronis' => array(
            'name' => 'Acronis Cyber Protect Connect',
            'description' => 'Remote desktop and support solution for secure access to remote computers.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.acronis.com/en-us/products/cyber-protect-connect/',
                    'primary' => true
                )
            )
        ),
        'espocrm' => array(
            'name' => 'EspoCRM',
            'description' => 'Open-source customer relationship management (CRM) application.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.espocrm.com/',
                    'primary' => true
                )
            )
        ),
        'libreoffice' => array(
            'name' => 'LibreOffice',
            'description' => 'Free and open-source office suite compatible with Microsoft Office formats.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.libreoffice.org/',
                    'primary' => true
                )
            )
        ),
        'localwp' => array(
            'name' => 'Local',
            'description' => 'Local WordPress development tool for creating WordPress sites locally.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://localwp.com/',
                    'primary' => true
                )
            )
        ),
        'notability' => array(
            'name' => 'Notability',
            'description' => 'Note-taking app for iPad and Mac with handwriting and PDF annotation features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://notability.com/',
                    'primary' => true
                )
            )
        ),
        'ulysses' => array(
            'name' => 'Ulysses',
            'description' => 'Writing app for Mac, iPad, and iPhone with a clean interface and powerful features.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://ulysses.app/',
                    'primary' => true
                )
            )
        )
    );
}

// Define hosting providers
function wp_allstars_get_hosting_providers() {
    return array(
        'closte' => array(
            'name' => 'Closte',
            'description' => 'Managed WordPress hosting with advanced performance optimization and auto-scaling.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://closte.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://closte.com/pricing'
                )
            )
        ),
        'cloudron' => array(
            'name' => 'Cloudron',
            'description' => 'Self-hosted platform that makes it easy to run web applications like WordPress on your server.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.cloudron.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.cloudron.io/pricing.html'
                )
            )
        ),
        'hostinger' => array(
            'name' => 'Hostinger',
            'description' => 'Affordable WordPress hosting with good performance and user-friendly management tools.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.hostinger.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.hostinger.com/wordpress-hosting'
                )
            )
        ),
        'hetzner' => array(
            'name' => 'Hetzner Cloud',
            'description' => 'High-performance cloud servers with excellent price-to-performance ratio for self-managed WordPress hosting.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.hetzner.com/cloud/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.hetzner.com/cloud#pricing'
                )
            )
        ),
        'simplehost' => array(
            'name' => 'SimpleHost',
            'description' => 'Streamlined WordPress hosting with a focus on simplicity and performance.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://simplehost.so/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://simplehost.so/#pricing'
                )
            )
        ),
        'cloudflare' => array(
            'name' => 'Cloudflare',
            'description' => 'Global cloud platform that provides CDN, security, and performance optimization services.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.cloudflare.com/en-gb/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.cloudflare.com/en-gb/plans/'
                )
            )
        ),
        'spaceship' => array(
            'name' => 'Spaceship',
            'description' => 'Modern hosting platform with advanced features for WordPress sites.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.spaceship.com/',
                    'primary' => true
                )
            )
        ),
        '101domain' => array(
            'name' => '101Domain',
            'description' => 'Domain registration and management service with support for hundreds of TLDs.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.101domain.com/',
                    'primary' => true
                )
            )
        ),
        'namecheap' => array(
            'name' => 'Namecheap',
            'description' => 'Domain registrar and web hosting provider with competitive pricing and good support.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.namecheap.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.namecheap.com/hosting/shared/'
                )
            )
        ),
        'updownio' => array(
            'name' => 'Updown.io',
            'description' => 'Simple and affordable website monitoring service with uptime checks and performance metrics.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://updown.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://updown.io/pricing'
                )
            )
        )
    );
}

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

// Add transient caching for plugin data
function wp_allstars_get_cached_plugins($category) {
    $cache_key = 'wp_allstars_plugins_' . $category;
    $cached_data = get_transient($cache_key);
    
    if ($cached_data !== false) {
        return $cached_data;
    }
    
    return false;
}

function wp_allstars_set_cached_plugins($category, $data) {
    $cache_key = 'wp_allstars_plugins_' . $category;
    set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
}

// Add AJAX endpoint for plugin list
function wp_allstars_ajax_get_plugins() {
    // Check nonce with the correct action name
    if (!check_ajax_referer('wp-allstars-nonce', '_wpnonce', false)) {
        wp_send_json_error('Invalid security token sent.');
        return;
    }
    
    if (!current_user_can('install_plugins')) {
        wp_die(-1);
    }

    $category = isset($_POST['category']) ? sanitize_key($_POST['category']) : 'minimal';
    
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    
    // Get our recommended plugins for this category
    $recommended_plugins = wp_allstars_get_recommended_plugins();
    if (!isset($recommended_plugins[$category])) {
        wp_send_json_error('Invalid category: ' . $category);
        return;
    }
    
    // Try to get cached data first
    $cached_data = wp_allstars_get_cached_plugins($category);
    if ($cached_data !== false) {
        error_log('Using cached data for category: ' . $category);
        try {
            // Generate plugin cards HTML
            $html = wp_allstars_generate_plugin_cards($cached_data->plugins);
            wp_send_json_success($html);
            return;
        } catch (Exception $e) {
            error_log('Error displaying cached plugins: ' . $e->getMessage());
            // Fall through to fetch fresh data
        }
    }
    
    error_log('Fetching fresh data for category: ' . $category);
    error_log('Plugins to fetch: ' . implode(', ', $recommended_plugins[$category]));
    
    try {
        $plugins = array();
        
        // Only fetch plugins that are in our recommended list for this category
        foreach ($recommended_plugins[$category] as $slug) {
            try {
                error_log('Fetching plugin data for: ' . $slug);
                $plugin_data = plugins_api('plugin_information', array(
                    'slug' => $slug,
                    'fields' => array(
                        'short_description' => true,
                        'sections' => false,
                        'requires' => true,
                        'rating' => true,
                        'ratings' => false,
                        'downloaded' => true,
                        'last_updated' => true,
                        'added' => false,
                        'tags' => false,
                        'compatibility' => false,
                        'homepage' => true,
                        'versions' => false,
                        'donate_link' => false,
                        'reviews' => false,
                        'banners' => false,
                        'icons' => true,
                        'active_installs' => true,
                        'group' => false,
                        'contributors' => false,
                    )
                ));
                
                if (is_wp_error($plugin_data)) {
                    error_log('Error fetching plugin data for ' . $slug . ': ' . $plugin_data->get_error_message());
                } else {
                    $plugins[] = $plugin_data;
                    error_log('Successfully fetched data for: ' . $slug);
                }
            } catch (Exception $e) {
                error_log('Exception fetching plugin data for ' . $slug . ': ' . $e->getMessage());
                continue;
            }
        }
        
        if (empty($plugins)) {
            wp_send_json_error('No plugin data could be retrieved for category: ' . $category);
            return;
        }
        
        error_log('Total plugins fetched: ' . count($plugins));
        
        // Create response object
        $res = (object) array(
            'info' => array(
                'page' => 1,
                'pages' => 1,
                'results' => count($plugins),
            ),
            'plugins' => $plugins
        );
        
        // Cache the results
        wp_allstars_set_cached_plugins($category, $res);
        
        // Generate plugin cards HTML
        $html = wp_allstars_generate_plugin_cards($plugins);
        wp_send_json_success($html);
        
    } catch (Exception $e) {
        error_log('Failed to fetch plugin data: ' . $e->getMessage());
        wp_send_json_error('Failed to fetch plugin data: ' . $e->getMessage());
    }
}
add_action('wp_ajax_wp_allstars_get_plugins', 'wp_allstars_ajax_get_plugins');

// Function to generate plugin cards HTML
function wp_allstars_generate_plugin_cards($plugins) {
    if (empty($plugins)) {
        return '<div class="notice notice-error"><p>No plugins found.</p></div>';
    }
    
    ob_start();
    ?>
    <div class="wp-list-table widefat plugin-install">
        <div id="the-list">
            <?php foreach ($plugins as $plugin): ?>
                <div class="plugin-card plugin-card-<?php echo esc_attr($plugin->slug); ?>">
                    <div class="plugin-card-top">
                        <div class="name column-name">
                            <h3>
                                <?php echo esc_html($plugin->name); ?>
                            </h3>
                        </div>
                        <div class="action-links">
                            <ul class="plugin-action-buttons">
                                <?php
                                $status = install_plugin_install_status($plugin);
                                switch ($status['status']) {
                                    case 'install':
                                        echo '<li><a class="button button-primary install-now" data-slug="' . esc_attr($plugin->slug) . '" href="' . esc_url($status['url']) . '" aria-label="' . esc_attr(sprintf(__('Install %s now'), $plugin->name)) . '">' . __('Install Now') . '</a></li>';
                                        break;
                                    case 'update_available':
                                        echo '<li><a class="button button-primary update-now" data-slug="' . esc_attr($plugin->slug) . '" href="' . esc_url($status['url']) . '" aria-label="' . esc_attr(sprintf(__('Update %s now'), $plugin->name)) . '">' . __('Update Now') . '</a></li>';
                                        break;
                                    case 'latest_installed':
                                    case 'newer_installed':
                                        if (is_plugin_active($status['file'])) {
                                            echo '<li><button type="button" class="button button-disabled" disabled="disabled">' . __('Active') . '</button></li>';
                                        } else {
                                            echo '<li><a class="button activate-now" href="' . esc_url(wp_nonce_url(admin_url('plugins.php?action=activate&plugin=' . $status['file']), 'activate-plugin_' . $status['file'])) . '" aria-label="' . esc_attr(sprintf(__('Activate %s'), $plugin->name)) . '">' . __('Activate') . '</a></li>';
                                        }
                                        break;
                                }
                                
                                // Add "Go Pro" button if applicable
                                $pro_plugins = wp_allstars_get_pro_plugins_config();
                                foreach ($pro_plugins as $pro_plugin) {
                                    if (isset($pro_plugin['free_slug']) && $pro_plugin['free_slug'] === $plugin->slug) {
                                        // Find the primary button URL in the button_group array
                                        $pro_url = '';
                                        foreach ($pro_plugin['button_group'] as $button) {
                                            if (isset($button['primary']) && $button['primary']) {
                                                $pro_url = $button['url'];
                                                break;
                                            }
                                        }
                                        // If no primary button found, use the first button URL
                                        if (empty($pro_url) && !empty($pro_plugin['button_group'][0]['url'])) {
                                            $pro_url = $pro_plugin['button_group'][0]['url'];
                                        }
                                        echo '<li><a class="button button-primary" href="' . esc_url($pro_url) . '" target="_blank">' . esc_html__('Go Pro', 'wp-allstars') . '</a></li>';
                                        break;
                                    }
                                }
                                
                                // Add "More Details" link
                                echo '<li><a class="more-details thickbox open-plugin-details-modal" href="' . esc_url(admin_url('plugin-install.php?tab=plugin-information&plugin=' . $plugin->slug . '&TB_iframe=true&width=600&height=550')) . '" aria-label="' . esc_attr(sprintf(__('More information about %s'), $plugin->name)) . '">' . __('More Details') . '</a></li>';
                                ?>
                            </ul>
                        </div>
                        <?php if (!empty($plugin->icons) && !empty($plugin->icons['1x'])): ?>
                            <div class="plugin-icon">
                                <img src="<?php echo esc_url($plugin->icons['1x']); ?>" alt="">
                            </div>
                        <?php endif; ?>
                        <div class="desc column-description">
                            <p><?php echo esc_html($plugin->short_description); ?></p>
                            <p class="authors">
                                <cite><?php printf(__('By %s'), $plugin->author); ?></cite>
                            </p>
                        </div>
                    </div>
                    <div class="plugin-card-bottom">
                        <div class="vers column-rating">
                            <?php wp_star_rating(array('rating' => $plugin->rating, 'type' => 'percent', 'number' => $plugin->num_ratings)); ?>
                            <span class="num-ratings">(<?php echo number_format_i18n($plugin->num_ratings); ?>)</span>
                        </div>
                        <div class="column-updated">
                            <strong><?php _e('Last Updated:'); ?></strong> 
                            <?php printf(__('%s ago'), human_time_diff(strtotime($plugin->last_updated))); ?>
                        </div>
                        <div class="column-downloaded">
                            <?php echo sprintf(_n('%s download', '%s downloads', $plugin->downloaded), number_format_i18n($plugin->downloaded)); ?>
                        </div>
                        <div class="column-compatibility">
                            <?php
                            if (!empty($plugin->tested) && version_compare(substr($GLOBALS['wp_version'], 0, strlen($plugin->tested)), $plugin->tested, '>')) {
                                echo '<span class="compatibility-untested">' . __('Untested with your version of WordPress') . '</span>';
                            } elseif (!empty($plugin->requires) && version_compare($GLOBALS['wp_version'], $plugin->requires, '<')) {
                                echo '<span class="compatibility-incompatible">' . __('Incompatible with your version of WordPress') . '</span>';
                            } else {
                                echo '<span class="compatibility-compatible">' . __('Compatible with your version of WordPress') . '</span>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Helper function to add pro button to plugin cards
function wp_allstars_add_pro_button($action_links, $plugin) {
    // Get pro plugins configuration
    $pro_plugins = wp_allstars_get_pro_plugins_config();
    
    // Check if this plugin has a pro version
    foreach ($pro_plugins as $pro_plugin) {
        if (isset($pro_plugin['free_slug']) && $pro_plugin['free_slug'] === $plugin['slug']) {
            $action_links[] = sprintf(
                '<a class="button button-primary" href="%s" target="_blank">%s</a>',
                esc_url(wp_allstars_get_pro_plugin_url($pro_plugin)),
                esc_html__('Go Pro', 'wp-allstars')
            );
            break;
        }
    }
    
    return $action_links;
}

/**
 * Helper function to get the pro plugin URL from the button_group array
 *
 * @param array $pro_plugin The pro plugin configuration array
 * @return string The URL for the pro plugin
 */
function wp_allstars_get_pro_plugin_url($pro_plugin) {
    // Find the primary button URL in the button_group array
    $pro_url = '';
    
    if (!empty($pro_plugin['button_group'])) {
        // First try to find a primary button
        foreach ($pro_plugin['button_group'] as $button) {
            if (isset($button['primary']) && $button['primary']) {
                $pro_url = $button['url'];
                break;
            }
        }
        
        // If no primary button found, use the first button URL
        if (empty($pro_url) && !empty($pro_plugin['button_group'][0]['url'])) {
            $pro_url = $pro_plugin['button_group'][0]['url'];
        }
    }
    
    return $pro_url;
}

// Remove the old plugins API filter since we're handling everything in the AJAX endpoint
remove_filter('plugins_api_result', 'wp_allstars_plugins_api_result');

// Clear plugin cache when plugins are updated, activated, or deactivated
function wp_allstars_clear_plugin_cache() {
    $recommended_plugins = wp_allstars_get_recommended_plugins();
    foreach (array_keys($recommended_plugins) as $category) {
        delete_transient('wp_allstars_plugins_' . $category);
    }
}
add_action('upgrader_process_complete', 'wp_allstars_clear_plugin_cache', 10, 0);
add_action('activated_plugin', 'wp_allstars_clear_plugin_cache');
add_action('deactivated_plugin', 'wp_allstars_clear_plugin_cache');
add_action('deleted_plugin', 'wp_allstars_clear_plugin_cache');
add_action('update_option_active_plugins', 'wp_allstars_clear_plugin_cache');

// Add transient caching for theme data
function wp_allstars_get_cached_theme() {
    $cache_key = 'wp_allstars_theme_kadence';
    return get_transient($cache_key);
}

function wp_allstars_set_cached_theme($data) {
    $cache_key = 'wp_allstars_theme_kadence';
    set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
}

// Add AJAX endpoint for theme
function wp_allstars_ajax_get_themes() {
    error_log('WP ALLSTARS: Theme AJAX handler started');
    
    // Check nonce with the correct action name
    if (!isset($_POST['_wpnonce'])) {
        error_log('WP ALLSTARS: No nonce provided');
        wp_send_json_error('No security token provided.');
        return;
    }
    
    if (!check_ajax_referer('wp-allstars-nonce', '_wpnonce', false)) {
        error_log('WP ALLSTARS: Invalid nonce: ' . sanitize_text_field($_POST['_wpnonce']));
        wp_send_json_error('Invalid security token sent.');
        return;
    }
    
    if (!current_user_can('install_themes')) {
        error_log('WP ALLSTARS: User does not have permission to install themes');
        wp_send_json_error('Permission denied');
        return;
    }
    
    error_log('WP ALLSTARS: Starting theme fetch process');
    
    try {
        error_log('WP ALLSTARS: Fetching theme data for kadence');
        
        // Check if we have cached data first
        $theme_data = wp_allstars_get_cached_theme();
        
        // If no cached data, fetch from API
        if (empty($theme_data)) {
            error_log('WP ALLSTARS: No cached theme data, fetching from API');
            
            // Get theme data with minimal fields
            $theme_data = themes_api('theme_information', array(
                'slug' => 'kadence',
                'fields' => array(
                    'sections' => false,
                    'description' => true,
                    'rating' => true,
                    'ratings' => false,
                    'downloaded' => true,
                    'download_link' => true,
                    'last_updated' => true,
                    'homepage' => true,
                    'tags' => false,
                    'screenshot_url' => true,
                    'version' => true,
                    'requires' => true,
                    'requires_php' => true,
                    'active_installs' => true,
                    'author' => true,
                    'preview_url' => true,
                )
            ));
            
            // Cache the result if successful
            if (!is_wp_error($theme_data)) {
                wp_allstars_set_cached_theme($theme_data);
            }
        } else {
            error_log('WP ALLSTARS: Using cached theme data');
        }
        
        if (is_wp_error($theme_data)) {
            error_log('WP ALLSTARS Theme API Error: ' . $theme_data->get_error_message());
            wp_send_json_error('Theme API Error: ' . $theme_data->get_error_message());
            return;
        }
        
        error_log('WP ALLSTARS: Successfully fetched theme data');
        
        // Format author data
        $author = '';
        if (is_string($theme_data->author)) {
            $author = $theme_data->author;
        } elseif (is_array($theme_data->author)) {
            $author = isset($theme_data->author['display_name']) ? $theme_data->author['display_name'] : '';
        }
        
        error_log('WP ALLSTARS: Theme data retrieved, generating HTML');
        
        // Generate custom HTML for the theme using our template partial
        ob_start();
        include(plugin_dir_path(__FILE__) . 'partials/theme-panel.php');
        $html = ob_get_clean();
        
        if (empty($html)) {
            error_log('WP ALLSTARS: Empty HTML generated');
            wp_send_json_error('Failed to generate theme display');
            return;
        }
        
        error_log('WP ALLSTARS: Successfully generated theme display, HTML length: ' . strlen($html));
        wp_send_json_success($html);
        exit; // Ensure we exit after sending the JSON response
        
    } catch (Exception $e) {
        error_log('WP ALLSTARS Theme loading exception: ' . $e->getMessage());
        error_log('WP ALLSTARS Theme loading exception trace: ' . $e->getTraceAsString());
        wp_send_json_error('Theme loading error: ' . $e->getMessage());
    } catch (Error $e) {
        error_log('WP ALLSTARS Theme loading error: ' . $e->getMessage());
        error_log('WP ALLSTARS Theme loading error trace: ' . $e->getTraceAsString());
        wp_send_json_error('Theme loading error: ' . $e->getMessage());
    }
}
add_action('wp_ajax_wp_allstars_get_themes', 'wp_allstars_ajax_get_themes');

// Clear theme cache when themes are updated
function wp_allstars_clear_theme_cache() {
    delete_transient('wp_allstars_theme_kadence');
}
add_action('upgrader_process_complete', 'wp_allstars_clear_theme_cache', 10, 0);
add_action('switch_theme', 'wp_allstars_clear_theme_cache');

// Add AJAX handler for theme activation
function wp_allstars_activate_theme() {
    // Check nonce with the correct action name
    if (!check_ajax_referer('wp-allstars-nonce', '_wpnonce', false)) {
        wp_send_json_error('Invalid security token sent.');
        return;
    }
    
    if (!current_user_can('switch_themes')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    $theme = isset($_POST['theme']) ? sanitize_text_field($_POST['theme']) : '';
    if (empty($theme)) {
        wp_send_json_error('No theme specified');
        return;
    }
    
    // Get the theme object
    $theme_obj = wp_get_theme($theme);
    if (!$theme_obj->exists() || $theme_obj->errors()) {
        wp_send_json_error('Theme does not exist');
        return;
    }
    
    // Switch the theme
    switch_theme($theme);
    
    // Check if the switch was successful
    $active_theme = wp_get_theme();
    if ($active_theme->get_stylesheet() === $theme) {
        wp_send_json_success(array(
            'message' => 'Theme activated successfully',
            'customize_url' => admin_url('customize.php')
        ));
    } else {
        wp_send_json_error('Failed to activate theme');
    }
}
add_action('wp_ajax_wp_allstars_activate_theme', 'wp_allstars_activate_theme');

// Register settings page HTML
function wp_allstars_settings_page() {
    global $tabs;
    
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
    $active_category = isset($_GET['category']) ? $_GET['category'] : 'minimal';
    
    // Clear cache and load required files
    if ($active_tab === 'recommended') {
        wp_allstars_clear_plugin_cache();
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        wp_enqueue_script('plugin-install');
        wp_enqueue_script('updates');
        add_thickbox();
        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', __FILE__));
        wp_enqueue_style('wp-allstars-plugins', plugins_url('css/wp-allstars-plugins.css', __FILE__));
        
        // Add inline script to load plugins on page load
        wp_add_inline_script('wp-allstars-admin', '
            jQuery(document).ready(function($) {
                if ($("#wpa-plugin-list").length && $("#wpa-plugin-list").is(":empty")) {
                    var category = "' . esc_js($active_category) . '";
                    var $container = $("#wpa-plugin-list");
                    var $loadingOverlay = $("<div class=\"wp-allstars-loading-overlay\"><span class=\"spinner is-active\"></span></div>");
                    
                    // Show loading overlay
                    $container.css("position", "relative").append($loadingOverlay);
                    
                    // AJAX request to get plugins
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "wp_allstars_get_plugins",
                            category: category,
                            _wpnonce: wpAllstars.nonce
                        },
                        success: function(response) {
                            $loadingOverlay.remove();
                            if (response.success) {
                                $container.html(response.data);
                                // Initialize plugin action buttons
                                if (typeof initPluginActions === "function") {
                                    initPluginActions();
                                }
                                
                                // Spinners have been removed from individual cards
                            } else {
                                $container.html("<div class=\"notice notice-error\"><p>" + response.data + "</p></div>");
                            }
                        },
                        error: function(xhr, status, error) {
                            $loadingOverlay.remove();
                            $container.html("<div class=\"notice notice-error\"><p>Failed to load plugins. Please try again. Error: " + error + "</p></div>");
                            console.error("AJAX Error:", xhr.responseText);
                        }
                    });
                }
            });
        ');
    } elseif ($active_tab === 'theme') {
        wp_allstars_clear_theme_cache();
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        wp_enqueue_script('theme-install');
        wp_enqueue_script('updates');
        add_thickbox();
        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', __FILE__));
        wp_enqueue_style('wp-allstars-plugins', plugins_url('css/wp-allstars-plugins.css', __FILE__));
        
        // Add inline script to load themes directly - same approach as plugins tab
        wp_add_inline_script('wp-allstars-admin', '
            jQuery(document).ready(function($) {
                if ($("#wpa-theme-list").length && $("#wpa-theme-list").is(":empty")) {
                    var $container = $("#wpa-theme-list");
                    var $loadingOverlay = $("<div class=\"wp-allstars-loading-overlay\"><span class=\"spinner is-active\"></span></div>");
                    
                    // Show loading overlay
                    $container.css("position", "relative").append($loadingOverlay);
                    
                    // AJAX request to get themes
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "wp_allstars_get_themes",
                            _wpnonce: wpAllstars.nonce
                        },
                        success: function(response) {
                            $loadingOverlay.remove();
                            if (response.success) {
                                $container.html(response.data);
                                // Initialize theme action buttons
                                if (typeof initThemeHandlers === "function") {
                                    initThemeHandlers();
                                }
                            } else {
                                $container.html("<div class=\"notice notice-error\"><p>" + response.data + "</p></div>");
                            }
                        },
                        error: function(xhr, status, error) {
                            $loadingOverlay.remove();
                            $container.html("<div class=\"notice notice-error\"><p>Failed to load themes. Please try again. Error: " + error + "</p></div>");
                            console.error("AJAX Error:", xhr.responseText);
                        }
                    });
                }
            });
        ');
    }
    ?>
    <div class="wrap wp-allstars-wrap">
        <div class="wp-allstars-header">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="wp-allstars-header-actions">
                <span class="wp-allstars-version">Version <?php echo esc_html(WP_ALLSTARS_VERSION); ?></span>
                <a href="https://www.wpallstars.com/" target="_blank" class="button button-secondary">
                    <?php esc_html_e('Documentation', 'wp-allstars'); ?>
                </a>
            </div>
        </div>
        
        <div class="wpa-settings-container">
            <div class="wp-allstars-nav">
        <h2 class="nav-tab-wrapper">
                    <a href="?page=wp-allstars&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('General', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Advanced', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=workflow" class="nav-tab <?php echo $active_tab == 'workflow' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Workflow', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=recommended" class="nav-tab <?php echo $active_tab == 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Free Plugins', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=pro" class="nav-tab <?php echo $active_tab == 'pro' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Pro Plugins', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=theme" class="nav-tab <?php echo $active_tab == 'theme' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Theme', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=hosting" class="nav-tab <?php echo $active_tab == 'hosting' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Hosting', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=tools" class="nav-tab <?php echo $active_tab == 'tools' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Tools', 'wp-allstars'); ?>
                    </a>
        </h2>
            </div>

            <div class="wpa-settings-content">
                <?php if ($active_tab == 'workflow'): ?>
                    <div class="wp-allstars-settings-content tab-content" id="workflow">
                        <div class="wp-allstars-toggle">
                            <div class="wp-allstars-toggle-header" aria-expanded="false">
                                <div class="wp-allstars-toggle-main">
                                    <div class="wp-allstars-toggle-left">
                                        <div class="wp-toggle-switch">
                                            <input type="checkbox" 
                                                   id="wp_allstars_auto_upload_images"
                                                   name="wp_allstars_auto_upload_images" 
                                                   value="1"
                                                   <?php checked(get_option('wp_allstars_auto_upload_images', false)); ?> 
                                            />
                                            <span class="wp-toggle-slider"></span>
                                        </div>
                                        <label for="wp_allstars_auto_upload_images">
                                            <?php esc_html_e('Enable Auto Upload Images', 'wp-allstars'); ?>
                    </label>
                                    </div>
                                    </div>
                                    <p class="wp-setting-description">
                                        <?php esc_html_e('Import images that have external URLs into your Media Library when saving. Consider disabling during large data imports with many external image URLs.', 'wp-allstars'); ?>
                                    </p>
                                </div>
                                <div class="wp-allstars-toggle-settings">
                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_allstars_max_width"><?php esc_html_e('Max Width', 'wp-allstars'); ?></label>
                                        <input type="number" 
                                               id="wp_allstars_max_width"
                                               name="wp_allstars_max_width" 
                                               value="<?php echo esc_attr(get_option('wp_allstars_max_width', 2560)); ?>" 
                                        />
                                        <p class="description"><?php esc_html_e('Maximum width for uploaded images in pixels.', 'wp-allstars'); ?></p>
                                    </div>
                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_allstars_max_height"><?php esc_html_e('Max Height', 'wp-allstars'); ?></label>
                                        <input type="number" 
                                               id="wp_allstars_max_height"
                                               name="wp_allstars_max_height" 
                                               value="<?php echo esc_attr(get_option('wp_allstars_max_height', 2560)); ?>" 
                                        />
                                        <p class="description"><?php esc_html_e('Maximum height for uploaded images in pixels.', 'wp-allstars'); ?></p>
                                    </div>

                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_exclude_urls"><?php esc_html_e('Exclude URLs', 'wp-allstars'); ?></label>
                                        <textarea id="wp_exclude_urls"
                                                  name="wp_exclude_urls"
                                                  rows="3"
                                                  placeholder="example.com&#10;another-domain.com"
                                        ><?php echo esc_textarea(get_option('wp_allstars_exclude_urls', '')); ?></textarea>
                                        <p class="description"><?php esc_html_e('Enter domains to exclude (one per line). Images from these domains will not be imported.', 'wp-allstars'); ?></p>
                                    </div>

                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_image_name"><?php esc_html_e('Image Name Pattern', 'wp-allstars'); ?></label>
                                        <input type="text" 
                                               id="wp_image_name"
                                               name="wp_image_name"
                                               value="<?php echo esc_attr(get_option('wp_allstars_image_name_pattern', '%filename%')); ?>"
                                        />
                                        <p class="description">
                                            <?php esc_html_e('Available patterns:', 'wp-allstars'); ?> %filename%, %post_id%, %postname%, %timestamp%, %date%, %year%, %month%, %day%
                                        </p>
                                    </div>

                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_image_alt"><?php esc_html_e('Image Alt Pattern', 'wp-allstars'); ?></label>
                                        <input type="text" 
                                               id="wp_image_alt"
                                               name="wp_image_alt"
                                               value="<?php echo esc_attr(get_option('wp_allstars_image_alt_pattern', '%filename%')); ?>"
                                        />
                                        <p class="description">
                                            <?php esc_html_e('Available patterns:', 'wp-allstars'); ?> %filename%, %post_title%, %post_id%, %postname%, %timestamp%
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'theme'): ?>
                    <div class="tab-content" id="theme">
                        <style>
                        #wpa-theme-list {
                            max-width: 1200px;
                            margin: 0 auto;
                        }
                        .theme-card {
                            background: #fff;
                            border-radius: 8px;
                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                            overflow: hidden;
                            margin-bottom: 30px;
                        }
                        .theme-image {
                            position: relative;
                            width: 100%;
                            height: 0;
                            padding-bottom: 75%; /* 4:3 aspect ratio */
                            overflow: hidden;
                            background-color: #f8f9fa;
                        }
                        .theme-image img {
                            position: absolute;
                            top: 0;
                            left: 0;
                            width: 100%;
                            height: 100%;
                            object-fit: cover;
                            object-position: top center;
                            display: block;
                        }
                        .theme-info {
                            padding: 20px;
                            border-bottom: 1px solid #eee;
                        }
                        .theme-name {
                            font-size: 22px;
                            font-weight: 600;
                            margin: 0 0 10px 0;
                            color: #333;
                            line-height: 1.3;
                        }
                        .theme-author {
                            font-size: 14px;
                            color: #555;
                            margin: 0;
                            line-height: 1.5;
                        }
                        .theme-actions {
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            gap: 20px;
                            padding: 20px 0;
                            background: #fff;
                        }
                        .theme-actions .button {
                            width: 120px;
                            height: 36px;
                            line-height: 34px;
                            text-align: center;
                            padding: 0 10px;
                            margin: 0;
                        }
                        @media (max-width: 782px) {
                            .theme-actions {
                                flex-direction: column;
                                gap: 10px;
                                padding: 15px 0;
                            }
                            .theme-actions .button {
                                width: 80%;
                                max-width: 200px;
                            }
                        }
                        </style>
                        <div id="wpa-theme-list"></div>
                    </div>
                <?php elseif ($active_tab == 'hosting'): ?>
                    <div class="tab-content" id="hosting">
                        <style>
                        .wpa-pro-plugins {
                            padding: 20px;
                            display: grid;
                            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
                            gap: 24px;
                            max-width: 1920px;
                            margin: 0 auto;
                        }
                        .wpa-pro-plugin {
                            background: #fff;
                            border: 1px solid #ddd;
                            padding: 24px;
                            border-radius: 8px;
                            display: flex;
                            flex-direction: column;
                            transition: all 0.2s ease;
                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                        }
                        .wpa-pro-plugin:hover {
                            border-color: #2271b1;
                            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                        }
                        .wpa-pro-plugin h3 {
                            margin: 0 0 12px;
                            font-size: 16px;
                            font-weight: 600;
                            color: #1d2327;
                            line-height: 1.4;
                        }
                        .wpa-pro-plugin p {
                            margin: 0 0 16px;
                            color: #50575e;
                            font-size: 14px;
                            line-height: 1.6;
                            min-height: 80px;
                        }
                        .wpa-pro-plugin .button-group {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 8px;
                            margin-top: auto;
                        }
                        .wpa-pro-plugin .button {
                            text-decoration: none;
                            min-width: 120px;
                            text-align: center;
                            height: 30px;
                            line-height: 28px;
                            padding: 0 12px;
                            font-size: 13px;
                            font-weight: normal;
                            margin: 0;
                            border: 1px solid #0071a1 !important;
                            border-radius: 3px !important;
                            background: #f6f7f7;
                            color: #0071a1;
                            display: inline-block;
                            vertical-align: top;
                            box-shadow: none;
                            cursor: pointer;
                        }
                        .wpa-pro-plugin .button:hover {
                            background: #f0f0f1;
                            border-color: #0071a1;
                            color: #0071a1;
                        }
                        .wpa-pro-plugin .button.button-primary {
                            background: #2271b1;
                            border-color: #2271b1 !important;
                            color: #fff;
                        }
                        .wpa-pro-plugin .button.button-primary:hover {
                            background: #135e96;
                            border-color: #135e96 !important;
                        }
                        </style>
                        <div class="wpa-pro-plugins">
                            <?php
                            $hosting_providers = wp_allstars_get_hosting_providers();
                            // Sort providers alphabetically by name
                            uasort($hosting_providers, function($a, $b) {
                                return strcmp($a['name'], $b['name']);
                            });
                            foreach ($hosting_providers as $provider) {
                                ?>
                                <div class="wpa-pro-plugin">
                                    <h3><?php echo esc_html($provider['name']); ?></h3>
                                    <p><?php echo esc_html($provider['description']); ?></p>
                                    <?php if (isset($provider['button_group'])): ?>
                                        <div class="button-group">
                                            <?php foreach ($provider['button_group'] as $button): ?>
                                                <a href="<?php echo esc_url($button['url']); ?>" target="_blank" class="button <?php echo isset($button['primary']) && $button['primary'] ? 'button-primary' : ''; ?>">
                                                    <?php echo esc_html($button['text']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'recommended'): ?>
                    <div class="tab-content" id="recommended">
                        <div id="wpa-plugin-filters" class="wp-filter">
                            <ul class="filter-links">
                                <li><a href="#" data-category="minimal" class="<?php echo $active_category == 'minimal' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Minimal', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="admin" class="<?php echo $active_category == 'admin' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Admin', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="ai" class="<?php echo $active_category == 'ai' ? 'current' : ''; ?>">
                                    <?php esc_html_e('AI', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="cms" class="<?php echo $active_category == 'cms' ? 'current' : ''; ?>">
                                    <?php esc_html_e('CMS', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="compliance" class="<?php echo $active_category == 'compliance' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Compliance', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="crm" class="<?php echo $active_category == 'crm' ? 'current' : ''; ?>">
                                    <?php esc_html_e('CRM', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="ecommerce" class="<?php echo $active_category == 'ecommerce' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Ecommerce', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="lms" class="<?php echo $active_category == 'lms' ? 'current' : ''; ?>">
                                    <?php esc_html_e('LMS', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="media" class="<?php echo $active_category == 'media' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Media', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="seo" class="<?php echo $active_category == 'seo' ? 'current' : ''; ?>">
                                    <?php esc_html_e('SEO', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="setup" class="<?php echo $active_category == 'setup' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Setup', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="social" class="<?php echo $active_category == 'social' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Social', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="speed" class="<?php echo $active_category == 'speed' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Speed', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="translation" class="<?php echo $active_category == 'translation' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Translation', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="advanced" class="<?php echo $active_category == 'advanced' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Advanced', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="debug" class="<?php echo $active_category == 'debug' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Debug', 'wp-allstars'); ?>
                                </a></li>
                            </ul>
                        </div>

                        <div class="wp-allstars-plugin-browser">
                            <div id="wpa-plugin-list"></div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'pro'): ?>
                    <div class="tab-content" id="pro">
                        <div class="wpa-pro-plugins">
                            <?php
                            $pro_plugins = wp_allstars_get_pro_plugins_config();
                            // Sort plugins alphabetically by name
                            uasort($pro_plugins, function($a, $b) {
                                return strcmp($a['name'], $b['name']);
                            });
                            foreach ($pro_plugins as $plugin) {
                                ?>
                                <div class="wpa-pro-plugin">
                                    <h3><?php echo esc_html($plugin['name']); ?></h3>
                                    <p><?php echo esc_html($plugin['description']); ?></p>
                                    <?php if (isset($plugin['button_group'])): ?>
                                        <div class="button-group">
                                            <?php foreach ($plugin['button_group'] as $button): ?>
                                                <a href="<?php echo esc_url($button['url']); ?>" target="_blank" class="button <?php echo isset($button['primary']) && $button['primary'] ? 'button-primary' : ''; ?>">
                                                    <?php echo esc_html($button['text']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="button-group">
                                            <?php if (!empty($plugin['demo_url'])): ?>
                                                <a href="<?php echo esc_url($plugin['demo_url']); ?>" class="button" target="_blank">
                                                    <?php esc_html_e('View Demo', 'wp-allstars'); ?>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url($plugin['url']); ?>" class="button button-primary" target="_blank">
                                                <?php esc_html_e('Learn More', 'wp-allstars'); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }
                            ?>
                        </div>

                        <style>
                        .wpa-pro-plugins {
                            padding: 20px;
                            display: grid;
                            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
                            gap: 24px;
                            max-width: 1920px;
                            margin: 0 auto;
                        }
                        .wpa-pro-plugin {
                            background: #fff;
                            border: 1px solid #ddd;
                            padding: 24px;
                            border-radius: 8px;
                            display: flex;
                            flex-direction: column;
                            transition: all 0.2s ease;
                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                        }
                        .wpa-pro-plugin:hover {
                            border-color: #2271b1;
                            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                        }
                        .wpa-pro-plugin h3 {
                            margin: 0 0 12px;
                            font-size: 16px;
                            font-weight: 600;
                            color: #1d2327;
                            line-height: 1.4;
                        }
                        .wpa-pro-plugin p {
                            margin: 0 0 16px;
                            color: #50575e;
                            font-size: 14px;
                            line-height: 1.6;
                        }
                        .wpa-pro-plugin .button-group {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 8px;
                            margin-top: auto;
                        }
                        .wpa-pro-plugin .button {
                            text-decoration: none;
                            min-width: 120px;
                            text-align: center;
                            height: 30px;
                            line-height: 28px;
                            padding: 0 12px;
                            font-size: 13px;
                            font-weight: normal;
                            margin: 0;
                            border: 1px solid #0071a1 !important;
                            border-radius: 3px !important;
                            background: #f6f7f7;
                            color: #0071a1;
                            display: inline-block;
                            vertical-align: top;
                            box-shadow: none;
                            cursor: pointer;
                        }
                        .wpa-pro-plugin .button:hover {
                            background: #f0f0f1;
                            border-color: #0071a1;
                            color: #0071a1;
                        }
                        .wpa-pro-plugin .button-primary {
                            background: #0071a1;
                            border-color: #0071a1;
                            color: #fff;
                        }
                        .wpa-pro-plugin .button-primary:hover {
                            background: #005d8c;
                            border-color: #005d8c;
                            color: #fff;
                        }
                        @media screen and (max-width: 960px) {
                            .wpa-pro-plugins {
                                grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
                                gap: 20px;
                                padding: 16px;
                            }
                            .wpa-pro-plugin {
                                padding: 20px;
                            }
                        }
                        @media screen and (max-width: 782px) {
                            .wpa-pro-plugins {
                                grid-template-columns: 1fr;
                                gap: 16px;
                                padding: 12px;
                            }
                            .wpa-pro-plugin {
                                padding: 16px;
                            }
                            .wpa-pro-plugin .button {
                                width: 100%;
                            }
                        }
                        </style>
                    </div>
                <?php elseif ($active_tab == 'general'): ?>
                    <div class="tab-content" id="general">
                        <div class="wp-allstars-settings-section">
                            <div class="wp-allstars-settings-grid">
                                <!-- Example of a simple toggle setting (no panel) -->
                                <div class="wp-setting-row">
                                    <div class="wp-setting-header">
                                        <div class="wp-setting-main">
                                            <div class="wp-setting-left">
                                                <div class="wp-toggle-switch">
                                                    <input type="checkbox" 
                                                           id="wp_allstars_simple_setting"
                                                           name="wp_allstars_simple_setting" 
                                                           value="1"
                                                           <?php checked(get_option('wp_allstars_simple_setting', false)); ?> 
                                                    />
                                                    <span class="wp-toggle-slider"></span>
                                                </div>
                                                <label for="wp_allstars_simple_setting" class="wp-setting-label">
                                                    <?php esc_html_e('Example: Simple Toggle', 'wp-allstars'); ?>
                    </label>
                                    </div>
                                    </div>
                                    <p class="wp-setting-description">
                                        <?php esc_html_e('This is an example of a simple toggle setting without an expandable panel. Currently for demonstration purposes only.', 'wp-allstars'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'advanced'): ?>
                    <div class="tab-content" id="advanced">
                        <div class="wp-allstars-settings-section">
                            <div class="wp-allstars-settings-grid">
                                <!-- Example of an expandable panel setting -->
                                <div class="wp-allstars-toggle">
                                    <div class="wp-allstars-toggle-header" aria-expanded="false">
                                        <div class="wp-allstars-toggle-main">
                                            <div class="wp-allstars-toggle-left">
                                                <div class="wp-toggle-switch">
                                                    <input type="checkbox" 
                                                           id="wp_allstars_auto_upload_images"
                                                           name="wp_allstars_auto_upload_images" 
                                                           value="1"
                                                           <?php checked(get_option('wp_allstars_auto_upload_images', false)); ?> 
                                                    />
                                                    <span class="wp-toggle-slider"></span>
                                                </div>
                                                <label for="wp_allstars_auto_upload_images">
                                                    <?php esc_html_e('Example: Expandable Panel', 'wp-allstars'); ?>
                    </label>
                                            </div>
                                        </div>
                                        <p class="wp-setting-description">
                                            <?php esc_html_e('This is an example of an expandable panel setting. Currently for demonstration purposes only - no actual functionality.', 'wp-allstars'); ?>
                                        </p>
                                    </div>
                                    <div class="wp-allstars-toggle-settings">
                                        <div class="wp-allstars-setting-row">
                                            <label for="example_text"><?php esc_html_e('Example Text Field', 'wp-allstars'); ?></label>
                                            <input type="text" 
                                                   id="example_text"
                                                   name="example_text"
                                                   value="Example value"
                                            />
                                            <p class="description"><?php esc_html_e('This is an example text field for demonstration purposes.', 'wp-allstars'); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                    </div>
                <?php elseif ($active_tab == 'tools'): ?>
                    <div class="tab-content" id="tools">
                        <style>
                        .wpa-pro-plugins {
                            padding: 20px;
                            display: grid;
                            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
                            gap: 24px;
                            max-width: 1920px;
                            margin: 0 auto;
                        }
                        .wpa-pro-plugin {
                            background: #fff;
                            border: 1px solid #ddd;
                            padding: 24px;
                            border-radius: 8px;
                            display: flex;
                            flex-direction: column;
                            transition: all 0.2s ease;
                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                        }
                        .wpa-pro-plugin:hover {
                            border-color: #2271b1;
                            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                        }
                        .wpa-pro-plugin h3 {
                            margin: 0 0 12px;
                            font-size: 16px;
                            font-weight: 600;
                            color: #1d2327;
                            line-height: 1.4;
                        }
                        .wpa-pro-plugin p {
                            margin: 0 0 16px;
                            color: #50575e;
                            font-size: 14px;
                            line-height: 1.6;
                            min-height: 80px;
                        }
                        .wpa-pro-plugin .button-group {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 8px;
                            margin-top: auto;
                        }
                        .wpa-pro-plugin .button {
                            text-decoration: none;
                            min-width: 120px;
                            text-align: center;
                            height: 30px;
                            line-height: 28px;
                            padding: 0 12px;
                            font-size: 13px;
                            font-weight: normal;
                            margin: 0;
                            border: 1px solid #0071a1 !important;
                            border-radius: 3px !important;
                            background: #f6f7f7;
                            color: #0071a1;
                            display: inline-block;
                            vertical-align: top;
                            box-shadow: none;
                            cursor: pointer;
                        }
                        .wpa-pro-plugin .button:hover {
                            background: #f0f0f1;
                            border-color: #0071a1;
                            color: #0071a1;
                        }
                        .wpa-pro-plugin .button.button-primary {
                            background: #2271b1;
                            border-color: #2271b1 !important;
                            color: #fff;
                        }
                        .wpa-pro-plugin .button.button-primary:hover {
                            background: #135e96;
                            border-color: #135e96 !important;
                        }
                        </style>
                        <div class="wpa-pro-plugins">
                            <?php
                            $tools = wp_allstars_get_tools();
                            // Sort tools alphabetically by name
                            uasort($tools, function($a, $b) {
                                return strcmp($a['name'], $b['name']);
                            });
                            foreach ($tools as $tool) {
                                ?>
                                <div class="wpa-pro-plugin">
                                    <h3><?php echo esc_html($tool['name']); ?></h3>
                                    <p><?php echo esc_html($tool['description']); ?></p>
                                    <?php if (isset($tool['button_group'])): ?>
                                        <div class="button-group">
                                            <?php foreach ($tool['button_group'] as $button): ?>
                                                <a href="<?php echo esc_url($button['url']); ?>" target="_blank" class="button <?php echo isset($button['primary']) && $button['primary'] ? 'button-primary' : ''; ?>">
                                                    <?php echo esc_html($button['text']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// Enqueue admin scripts and styles
function wp_allstars_admin_enqueue_scripts($hook) {
    if ('settings_page_wp-allstars' !== $hook) {
        return;
    }

    wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', __FILE__));
    wp_enqueue_script('wp-allstars-admin', plugins_url('js/wp-allstars-admin.js', __FILE__), array('jquery'), WP_ALLSTARS_VERSION, true);
    
    // Localize the script with new data
    wp_localize_script('wp-allstars-admin', 'wpAllstars', array(
        'nonce' => wp_create_nonce('wp-allstars-nonce'),
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
}
add_action('admin_enqueue_scripts', 'wp_allstars_admin_enqueue_scripts');