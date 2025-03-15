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
    register_setting('wp-allstars-settings', 'wp_allstars_lazy_load');
    register_setting('wp-allstars-settings', 'wp_allstars_minify_css');
    register_setting('wp-allstars-settings', 'wp_allstars_minify_js');
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
            'fluent-crm',
            'fluentform',
            'fluentforms-pdf',
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
            'code-snippets',
            'favorites',
            'remove-cpt-base',
            'remove-old-slugspermalinks',
            'yellow-pencil-visual-theme-customizer'
        ),
        'debug' => array(
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
    check_ajax_referer('updates');
    
    if (!current_user_can('install_plugins')) {
        wp_die(-1);
    }

    $category = isset($_GET['category']) ? sanitize_key($_GET['category']) : 'minimal';
    
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
            // Setup the list table with cached data
            $GLOBALS['tab'] = 'plugin-install';
            $_REQUEST['tab'] = 'plugin-install';
            $_REQUEST['type'] = 'plugin-install';
            set_current_screen('plugin-install');
            
            $wp_list_table = _get_list_table('WP_Plugin_Install_List_Table', array(
                'screen' => 'plugin-install'
            ));
            
            // Override the items with our cached data
            $wp_list_table->items = $cached_data->plugins;
            $wp_list_table->set_pagination_args(array(
                'total_items' => count($cached_data->plugins),
                'per_page' => count($cached_data->plugins),
            ));
            
            add_filter('plugin_install_action_links', 'wp_allstars_add_pro_button', 10, 2);
            
            ob_start();
            $wp_list_table->display();
            $html = ob_get_clean();
            
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
        
        // Setup the list table
        $GLOBALS['tab'] = 'plugin-install';
        $_REQUEST['tab'] = 'plugin-install';
        $_REQUEST['type'] = 'plugin-install';
        set_current_screen('plugin-install');
        
        $wp_list_table = _get_list_table('WP_Plugin_Install_List_Table', array(
            'screen' => 'plugin-install'
        ));
        
        // Set the items directly
        $wp_list_table->items = $plugins;
        $wp_list_table->set_pagination_args(array(
            'total_items' => count($plugins),
            'per_page' => count($plugins),
        ));
        
        add_filter('plugin_install_action_links', 'wp_allstars_add_pro_button', 10, 2);
        
        ob_start();
        $wp_list_table->display();
        $html = ob_get_clean();
        
        if (empty($html)) {
            wp_send_json_error('Failed to generate plugin display HTML');
            return;
        }
        
        wp_send_json_success($html);
        
    } catch (Exception $e) {
        error_log('Failed to fetch plugin data: ' . $e->getMessage());
        wp_send_json_error('Failed to fetch plugin data: ' . $e->getMessage());
    }
}
add_action('wp_ajax_wp_allstars_get_plugins', 'wp_allstars_ajax_get_plugins');

// Helper function to add pro button to plugin cards
function wp_allstars_add_pro_button($action_links, $plugin) {
    // Get pro plugins configuration
    $pro_plugins = wp_allstars_get_pro_plugins_config();
    
    // Check if this plugin has a pro version
    foreach ($pro_plugins as $pro_plugin) {
        if (isset($pro_plugin['free_slug']) && $pro_plugin['free_slug'] === $plugin['slug']) {
            $action_links[] = sprintf(
                '<a class="button button-primary" href="%s" target="_blank">%s</a>',
                esc_url($pro_plugin['url']),
                esc_html__('Go Pro', 'wp-allstars')
            );
            break;
        }
    }
    
    return $action_links;
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
function wp_allstars_ajax_get_theme() {
    check_ajax_referer('updates');
    
    if (!current_user_can('install_themes')) {
        error_log('WP ALLSTARS: User does not have permission to install themes');
        wp_send_json_error('Permission denied');
        return;
    }
    
    error_log('WP ALLSTARS: Starting theme fetch process');
    
    try {
        error_log('WP ALLSTARS: Fetching theme data for kadence');
        
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
        
        // Generate custom HTML for the theme
        ob_start();
        ?>
        <style>
        .theme-browser .theme {
            cursor: pointer;
            float: none;
            margin: 0;
            position: relative;
            width: 100%;
            border: 1px solid #dcdcde;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            box-sizing: border-box;
        }
        .theme-browser .theme .theme-screenshot {
            display: block;
            overflow: hidden;
            position: relative;
            background: #fff;
        }
        .theme-browser .theme .theme-screenshot img {
            display: block;
            width: 100%;
            height: auto;
        }
        .theme-browser .theme .theme-name {
            font-size: 15px;
            font-weight: 600;
            height: 48px;
            margin: 0;
            padding: 15px;
            box-shadow: inset 0 1px 0 rgba(0,0,0,.1);
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            background: #f6f7f7;
            position: relative;
        }
        .theme-browser .theme .theme-actions {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            left: 0;
            right: 0;
            bottom: auto;
            height: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            background: rgba(255, 255, 255, 0.9);
            padding: 20px 0;
        }
        .theme-browser .theme .theme-actions .button {
            margin: 0;
        }
        </style>
        <div class="theme-browser">
            <div class="themes wp-clearfix">
                <div class="theme" tabindex="0">
                    <div class="theme-screenshot">
                        <img src="<?php echo esc_url($theme_data->screenshot_url); ?>" alt="">
                    </div>
                    <span class="theme-author"><?php echo esc_html(sprintf(__('By %s'), $author)); ?></span>
                    <h3 class="theme-name"><?php echo esc_html($theme_data->name); ?></h3>
                    <div class="theme-actions">
                        <?php if (current_user_can('install_themes')): ?>
                            <?php 
                            $installed_theme = wp_get_theme('kadence');
                            if ($installed_theme->exists()): ?>
                                <button type="button" class="button button-primary activate-theme" data-slug="kadence">
                                    <?php esc_html_e('Activate'); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="button button-primary install-theme" data-slug="kadence">
                                    <?php esc_html_e('Install'); ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a class="button button-secondary preview install-theme-preview" href="<?php echo esc_url($theme_data->preview_url); ?>" target="_blank">
                            <?php esc_html_e('Preview'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        
        if (empty($html)) {
            error_log('WP ALLSTARS: Empty HTML generated');
            wp_send_json_error('Failed to generate theme display');
            return;
        }
        
        error_log('WP ALLSTARS: Successfully generated theme display');
        wp_send_json_success($html);
        
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
add_action('wp_ajax_wp_allstars_get_theme', 'wp_allstars_ajax_get_theme');

// Clear theme cache when themes are updated
function wp_allstars_clear_theme_cache() {
    delete_transient('wp_allstars_theme_kadence');
}
add_action('upgrader_process_complete', 'wp_allstars_clear_theme_cache', 10, 0);
add_action('switch_theme', 'wp_allstars_clear_theme_cache');

// Add AJAX handler for theme activation
function wp_allstars_activate_theme() {
    check_ajax_referer('updates');
    
    if (!current_user_can('switch_themes')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    $theme = isset($_POST['theme']) ? sanitize_text_field($_POST['theme']) : '';
    if (empty($theme)) {
        wp_send_json_error('No theme specified');
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

// Settings page HTML
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
    } elseif ($active_tab === 'theme') {
        wp_allstars_clear_theme_cache();
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        wp_enqueue_script('theme-install');
        wp_enqueue_script('updates');
        add_thickbox();
        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', __FILE__));
    }
    ?>
    <div class="wrap wp-allstars-wrap">
        <div class="wp-allstars-header">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="wp-allstars-header-actions">
                <span class="wp-allstars-version">Version <?php echo esc_html(WP_ALLSTARS_VERSION); ?></span>
                <a href="https://www.wpallstars.com/docs/superstar-plugin/" target="_blank" class="button button-secondary">
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
                    <a href="?page=wp-allstars&tab=workflow" class="nav-tab <?php echo $active_tab == 'workflow' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Workflow', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Advanced', 'wp-allstars'); ?>
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
        </h2>
            </div>

            <div class="wpa-settings-content">
                <?php if ($active_tab == 'workflow'): ?>
                    <div class="wp-allstars-settings-content">
                        <div class="wp-allstars-toggle">
                            <div class="wp-allstars-toggle-header">
                                <label for="wp_allstars_auto_upload_images">
                                    <div class="wp-toggle-switch">
                                        <input type="checkbox" 
                                               id="wp_allstars_auto_upload_images"
                                               name="wp_allstars_auto_upload_images" 
                                               value="1"
                                               <?php checked(get_option('wp_allstars_auto_upload_images', false)); ?> 
                                        />
                                        <span class="wp-toggle-slider"></span>
                                    </div>
                                    <?php esc_html_e('Enable Auto Upload Images', 'wp-allstars'); ?>
                                </label>
                                <button type="button" class="wp-allstars-expand-settings" aria-expanded="false">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Import images that have external URLs into your Media Library when saving. Consider disabling during large data imports with many external image URLs.', 'wp-allstars'); ?>
                            </p>
                            <div class="wp-allstars-toggle-settings">
                                <div class="wp-allstars-setting-row">
                                    <label for="wp_max_width"><?php esc_html_e('Max Width', 'wp-allstars'); ?></label>
                                    <input type="number" 
                                           id="wp_max_width"
                                           name="wp_max_width"
                                           value="<?php echo esc_attr(get_option('wp_allstars_max_width', 1920)); ?>"
                                           min="0"
                                    />
                                    <p class="description"><?php esc_html_e('Maximum width of uploaded images (px). Leave empty for no limit.', 'wp-allstars'); ?></p>
                                </div>

                                <div class="wp-allstars-setting-row">
                                    <label for="wp_max_height"><?php esc_html_e('Max Height', 'wp-allstars'); ?></label>
                                    <input type="number" 
                                           id="wp_max_height"
                                           name="wp_max_height"
                                           value="<?php echo esc_attr(get_option('wp_allstars_max_height', 1080)); ?>"
                                           min="0"
                                    />
                                    <p class="description"><?php esc_html_e('Maximum height of uploaded images (px). Leave empty for no limit.', 'wp-allstars'); ?></p>
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

                    <script>
                    jQuery(document).ready(function($) {
                        $('.wp-allstars-expand-settings').on('click', function(e) {
                            e.preventDefault();
                            var $button = $(this);
                            var $settings = $button.closest('.wp-allstars-toggle').find('.wp-allstars-toggle-settings');
                            var isExpanded = $button.attr('aria-expanded') === 'true';
                            
                            $button.attr('aria-expanded', !isExpanded);
                            $button.find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
                            $settings.slideToggle(200);
                        });
                    });
                    </script>

                <?php elseif ($active_tab == 'theme'): ?>
                    <div class="wp-list-table-container">
                        <div class="wpa-loading-overlay">
                            <span class="spinner is-active"></span>
                        </div>
                        <div id="wpa-theme-list"></div>
                    </div>

                    <script>
                    jQuery(document).ready(function($) {
                        var loadingStartTime;
                        var currentRequest = null;
                        
                        function loadTheme() {
                            // Show loading overlay
                            $('.wpa-loading-overlay').fadeIn();
                            loadingStartTime = Date.now();
                            
                            // Cancel previous request if it exists
                            if (currentRequest) {
                                currentRequest.abort();
                            }
                            
                            // Make the AJAX request
                            currentRequest = $.ajax({
                                url: ajaxurl,
                                data: {
                                    action: 'wp_allstars_get_theme',
                                    _ajax_nonce: '<?php echo wp_create_nonce("updates"); ?>'
                                },
                                beforeSend: function() {
                                    if (currentRequest) {
                                        currentRequest.abort();
                                    }
                                },
                                success: function(response) {
                                    if (response.success) {
                                        ensureMinLoadingTime(function() {
                                            $('#wpa-theme-list').html(response.data);
                                            $('.wpa-loading-overlay').fadeOut();
                                            
                                            // Initialize theme installation handlers
                                            initThemeHandlers();
                                        });
                                    } else {
                                        console.error('Server returned error:', response);
                                        $('.wpa-loading-overlay').fadeOut();
                                        $('#wpa-theme-list').html('<div class="notice notice-error"><p>Failed to load theme: ' + (response.data || 'Unknown error') + '</p></div>');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('Failed to load theme:', {xhr: xhr, status: status, error: error});
                                    $('.wpa-loading-overlay').fadeOut();
                                    $('#wpa-theme-list').html('<div class="notice notice-error"><p>Failed to load theme. Please try again. Error: ' + error + '</p></div>');
                                }
                            });
                        }
                        
                        function initThemeHandlers() {
                            // Handle theme installation
                            $('.install-theme').on('click', function(e) {
                                e.preventDefault();
                                var $button = $(this);
                                var slug = $button.data('slug');
                                
                                $button.addClass('updating-message').text('Installing...');
                                
                                wp.updates.installTheme({
                                    slug: slug,
                                    success: function(response) {
                                        $button
                                            .removeClass('updating-message install-theme')
                                            .addClass('button-primary activate-theme')
                                            .text('Activate');
                                        
                                        // Refresh the theme display
                                        loadTheme();
                                    },
                                    error: function(error) {
                                        $button.removeClass('updating-message');
                                        console.error('Theme installation failed:', error);
                                        if (error.errorMessage) {
                                            alert(error.errorMessage);
                                        }
                                    }
                                });
                            });
                            
                            // Handle theme activation
                            $('.activate-theme').on('click', function(e) {
                                e.preventDefault();
                                var $button = $(this);
                                var slug = $button.data('slug');
                                
                                $button.addClass('updating-message').text('Activating...');
                                
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'wp_allstars_activate_theme',
                                        theme: slug,
                                        _ajax_nonce: '<?php echo wp_create_nonce("updates"); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            $button.removeClass('updating-message').text('Activated');
                                            setTimeout(function() {
                                                if (response.data && response.data.customize_url) {
                                                    window.location.href = response.data.customize_url;
                                                } else {
                                                    window.location.reload();
                                                }
                                            }, 1000);
                                        } else {
                                            $button.removeClass('updating-message').text('Activate');
                                            alert(response.data || 'Theme activation failed. Please try again.');
                                        }
                                    },
                                    error: function(xhr, status, error) {
                                        $button.removeClass('updating-message').text('Activate');
                                        console.error('Theme activation failed:', error);
                                        alert('Theme activation failed: ' + error);
                                    }
                                });
                            });
                        }
                        
                        // Function to ensure minimum loading time
                        function ensureMinLoadingTime(callback) {
                            var currentTime = Date.now();
                            var elapsed = currentTime - loadingStartTime;
                            var minLoadingTime = 500;
                            
                            if (elapsed < minLoadingTime) {
                                setTimeout(callback, minLoadingTime - elapsed);
                            } else {
                                callback();
                            }
                        }
                        
                        // Load theme on page load
                        loadTheme();
                    });
                    </script>

                <?php elseif ($active_tab == 'recommended'): ?>
                    <div class="wpa-plugin-filters">
                        <a href="?page=wp-allstars&tab=recommended&category=minimal" 
                           class="button <?php echo $active_category == 'minimal' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Minimal', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=admin" 
                           class="button <?php echo $active_category == 'admin' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Admin', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=ai" 
                           class="button <?php echo $active_category == 'ai' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('AI', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=cms" 
                           class="button <?php echo $active_category == 'cms' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('CMS', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=compliance" 
                           class="button <?php echo $active_category == 'compliance' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Compliance', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=crm" 
                           class="button <?php echo $active_category == 'crm' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('CRM', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=ecommerce" 
                           class="button <?php echo $active_category == 'ecommerce' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Ecommerce', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=lms" 
                           class="button <?php echo $active_category == 'lms' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('LMS', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=media" 
                           class="button <?php echo $active_category == 'media' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Media', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=seo" 
                           class="button <?php echo $active_category == 'seo' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('SEO', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=setup" 
                           class="button <?php echo $active_category == 'setup' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Setup', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=social" 
                           class="button <?php echo $active_category == 'social' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Social', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=speed" 
                           class="button <?php echo $active_category == 'speed' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Speed', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=translation" 
                           class="button <?php echo $active_category == 'translation' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Translation', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=advanced" 
                           class="button <?php echo $active_category == 'advanced' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Advanced', 'wp-allstars'); ?>
                        </a>
                        <a href="?page=wp-allstars&tab=recommended&category=debug" 
                           class="button <?php echo $active_category == 'debug' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Debug', 'wp-allstars'); ?>
                        </a>
                    </div>

                    <div class="wp-list-table-container">
                        <div class="wpa-loading-overlay">
                            <span class="spinner is-active"></span>
                        </div>
                        <div id="wpa-plugin-list"></div>
                    </div>

                    <script>
                    jQuery(document).ready(function($) {
                        var loadingStartTime;
                        var currentRequest = null;
                        
                        function loadPlugins(category) {
                            // Show loading overlay
                            $('.wpa-loading-overlay').fadeIn();
                            loadingStartTime = Date.now();
                            
                            // Cancel previous request if it exists
                            if (currentRequest) {
                                currentRequest.abort();
                            }
                            
                            // Make the AJAX request
                            currentRequest = $.ajax({
                                url: ajaxurl,
                                data: {
                                    action: 'wp_allstars_get_plugins',
                                    category: category || 'minimal',
                                    _ajax_nonce: '<?php echo wp_create_nonce("updates"); ?>'
                                },
                                beforeSend: function() {
                                    if (currentRequest) {
                                        currentRequest.abort();
                                    }
                                },
                                success: function(response) {
                                    if (response.success) {
                                        ensureMinLoadingTime(function() {
                                            $('#wpa-plugin-list').html(response.data);
                                            $('.wpa-loading-overlay').fadeOut();
                                        });
                                    } else {
                                        console.error('Server returned error:', response);
                                        $('.wpa-loading-overlay').fadeOut();
                                        $('#wpa-plugin-list').html('<div class="notice notice-error"><p>Failed to load plugins: ' + (response.data || 'Unknown error') + '</p></div>');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('Failed to load plugins:', {xhr: xhr, status: status, error: error});
                                    $('.wpa-loading-overlay').fadeOut();
                                    $('#wpa-plugin-list').html('<div class="notice notice-error"><p>Failed to load plugins. Please try again. Error: ' + error + '</p></div>');
                                }
                            });
                        }
                        
                        // Function to ensure minimum loading time
                        function ensureMinLoadingTime(callback) {
                            var currentTime = Date.now();
                            var elapsed = currentTime - loadingStartTime;
                            var minLoadingTime = 500;
                            
                            if (elapsed < minLoadingTime) {
                                setTimeout(callback, minLoadingTime - elapsed);
                            } else {
                                callback();
                            }
                        }
                        
                        // Load plugins on page load with current category from URL
                        var urlParams = new URLSearchParams(window.location.search);
                        var currentCategory = urlParams.get('category') || 'minimal';
                        loadPlugins(currentCategory);
                        
                        // Handle category filter clicks
                        $('.wpa-plugin-filters a').on('click', function(e) {
                            e.preventDefault();
                            var category = new URLSearchParams($(this).attr('href').split('?')[1]).get('category');
                            loadPlugins(category);
                            
                            // Update URL without page reload
                            var newUrl = $(this).attr('href');
                            history.pushState({}, '', newUrl);
                            
                            // Update active state
                            $('.wpa-plugin-filters a').removeClass('button-primary');
                            $(this).addClass('button-primary');
                        });
                    });
                    </script>

                <?php elseif ($active_tab == 'pro'): ?>
                    <div class="wpa-pro-plugins">
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
                                border: 1px solid #0071a1;
                                border-radius: 3px;
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
                            .wpa-pro-plugin .button-group {
                                display: flex;
                                flex-wrap: wrap;
                                gap: 8px;
                                margin-top: auto;
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
                        
                        <?php
                        $pro_plugins = wp_allstars_get_pro_plugins_config();
                        foreach ($pro_plugins as $key => $plugin) {
                            // Skip WP Ultimo
                            if ($key === 'wp-ultimo') continue;
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
                                        <a href="<?php echo esc_url($plugin['url']); ?>" target="_blank" class="button button-primary">Learn More</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                <?php elseif ($active_tab == 'general'): ?>
                <div class="wp-allstars-toggle">
                        <label for="wp_allstars_lazy_load">
                            <div class="wp-toggle-switch">
                                <input type="checkbox" 
                                       id="wp_allstars_lazy_load"
                                       name="wp_allstars_lazy_load" 
                                       value="1" 
                                       <?php checked(get_option('wp_allstars_lazy_load', 1)); ?> 
                                />
                                <span class="wp-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable lazy loading for images', 'wp-allstars'); ?>
                    </label>
                        <p class="description">
                            <?php esc_html_e('Improves page load time by loading images only when they enter the viewport.', 'wp-allstars'); ?>
                        </p>
                </div>

                <?php elseif ($active_tab == 'advanced'): ?>
                <div class="wp-allstars-toggle">
                        <label for="wp_allstars_minify_css">
                            <div class="wp-toggle-switch">
                                <input type="checkbox" 
                                       id="wp_allstars_minify_css"
                                       name="wp_allstars_minify_css" 
                                       value="1" 
                                       <?php checked(get_option('wp_allstars_minify_css', 0)); ?> 
                                />
                                <span class="wp-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable CSS minification', 'wp-allstars'); ?>
                    </label>
                        <p class="description">
                            <?php esc_html_e('Minifies CSS files to reduce file size and improve load times.', 'wp-allstars'); ?>
                        </p>
                </div>

                <div class="wp-allstars-toggle">
                        <label for="wp_allstars_minify_js">
                            <div class="wp-toggle-switch">
                                <input type="checkbox" 
                                       id="wp_allstars_minify_js"
                                       name="wp_allstars_minify_js" 
                                       value="1" 
                                       <?php checked(get_option('wp_allstars_minify_js', 0)); ?> 
                                />
                                <span class="wp-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable JS minification', 'wp-allstars'); ?>
                    </label>
                        <p class="description">
                            <?php esc_html_e('Minifies JavaScript files to reduce file size and improve load times.', 'wp-allstars'); ?>
                        </p>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}