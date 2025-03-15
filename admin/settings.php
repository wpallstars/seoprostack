<?php
/**
 * Admin settings page
 */

// Add menu item
function wpa_superstar_admin_menu() {
    add_options_page(
        'WPA Superstar Settings',
        'WPA Superstar',
        'manage_options',
        'wpa-superstar',
        'wpa_superstar_settings_page'
    );
}
add_action('admin_menu', 'wpa_superstar_admin_menu');

// Register settings
function wpa_superstar_register_settings() {
    register_setting('wpa-superstar-settings', 'wpa_superstar_lazy_load');
    register_setting('wpa-superstar-settings', 'wpa_superstar_minify_css');
    register_setting('wpa-superstar-settings', 'wpa_superstar_minify_js');
}
add_action('admin_init', 'wpa_superstar_register_settings');

// AJAX handler for settings
function wpa_superstar_update_option() {
    check_ajax_referer('wpa-superstar-nonce', 'nonce');
    $option = sanitize_text_field($_POST['option']);
    $value = intval($_POST['value']);
    update_option($option, $value);
    wp_send_json_success('Option updated');
}
add_action('wp_ajax_wpa_superstar_update_option', 'wpa_superstar_update_option');

// Define recommended plugins
function wpa_superstar_get_recommended_plugins() {
    return array(
        'minimal' => array(
            'antispam-bee',
            'simple-cloudflare-turnstile'
        ),
        'advanced' => array(
            'advanced-custom-fields',
            'admin-menu-editor',
            'burst-statistics',
            'freesoul-deactivate-plugins',
            'plugin-toggle',
            'pretty-links',
            'seo-by-rank-math',
            'fluent-crm',
            'fluentform',
            'fluent-smtp',
            'fluent-support'
        ),
        'ecommerce' => array(
            'woocommerce',
            'client-booking'
        ),
        'lms' => array(
            'tutor'
        )
    );
}

// Add transient caching for plugin data
function wpa_superstar_get_cached_plugins($category) {
    $cache_key = 'wpa_superstar_plugins_' . $category;
    $cached_data = get_transient($cache_key);
    
    if ($cached_data !== false) {
        return $cached_data;
    }
    
    return false;
}

function wpa_superstar_set_cached_plugins($category, $data) {
    $cache_key = 'wpa_superstar_plugins_' . $category;
    set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
}

// Add AJAX endpoint for plugin list
function wpa_superstar_ajax_get_plugins() {
    check_ajax_referer('updates');
    
    if (!current_user_can('install_plugins')) {
        wp_die(-1);
    }

    $category = isset($_GET['category']) ? sanitize_key($_GET['category']) : 'minimal';
    
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    
    // Get our recommended plugins for this category
    $recommended_plugins = wpa_superstar_get_recommended_plugins();
    if (!isset($recommended_plugins[$category])) {
        wp_send_json_error('Invalid category: ' . $category);
    }
    
    // Try to get cached data first
    $cached_data = wpa_superstar_get_cached_plugins($category);
    if ($cached_data !== false) {
        error_log('Using cached data for category: ' . $category);
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
        
        ob_start();
        $wp_list_table->display();
        $html = ob_get_clean();
        
        wp_send_json_success($html);
        return;
    }
    
    error_log('Fetching fresh data for category: ' . $category);
    error_log('Plugins to fetch: ' . implode(', ', $recommended_plugins[$category]));
    
    // If no cache, get fresh data
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
        wpa_superstar_set_cached_plugins($category, $res);
        
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
        
        ob_start();
        $wp_list_table->display();
        $html = ob_get_clean();
        
        wp_send_json_success($html);
        
    } catch (Exception $e) {
        error_log('Failed to fetch plugin data: ' . $e->getMessage());
        wp_send_json_error('Failed to fetch plugin data: ' . $e->getMessage());
    }
}
add_action('wp_ajax_wpa_get_plugins', 'wpa_superstar_ajax_get_plugins');

// Remove the old plugins API filter since we're handling everything in the AJAX endpoint
remove_filter('plugins_api_result', 'wpa_superstar_plugins_api_result');

// Clear plugin cache when plugins are updated, activated, or deactivated
function wpa_superstar_clear_plugin_cache() {
    $recommended_plugins = wpa_superstar_get_recommended_plugins();
    foreach (array_keys($recommended_plugins) as $category) {
        delete_transient('wpa_superstar_plugins_' . $category);
    }
}
add_action('upgrader_process_complete', 'wpa_superstar_clear_plugin_cache', 10, 0);
add_action('activated_plugin', 'wpa_superstar_clear_plugin_cache');
add_action('deactivated_plugin', 'wpa_superstar_clear_plugin_cache');
add_action('deleted_plugin', 'wpa_superstar_clear_plugin_cache');
add_action('update_option_active_plugins', 'wpa_superstar_clear_plugin_cache');

// Add transient caching for theme data
function wpa_superstar_get_cached_theme() {
    $cache_key = 'wpa_superstar_theme_kadence';
    return get_transient($cache_key);
}

function wpa_superstar_set_cached_theme($data) {
    $cache_key = 'wpa_superstar_theme_kadence';
    set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
}

// Add AJAX endpoint for theme
function wpa_superstar_ajax_get_theme() {
    check_ajax_referer('updates');
    
    if (!current_user_can('install_themes')) {
        error_log('WPA Superstar: User does not have permission to install themes');
        wp_send_json_error('Permission denied');
        return;
    }
    
    error_log('WPA Superstar: Starting theme fetch process');
    
    try {
        error_log('WPA Superstar: Fetching theme data for kadence');
        
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
            error_log('WPA Superstar Theme API Error: ' . $theme_data->get_error_message());
            wp_send_json_error('Theme API Error: ' . $theme_data->get_error_message());
            return;
        }
        
        error_log('WPA Superstar: Successfully fetched theme data');
        
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
            error_log('WPA Superstar: Empty HTML generated');
            wp_send_json_error('Failed to generate theme display');
            return;
        }
        
        error_log('WPA Superstar: Successfully generated theme display');
        wp_send_json_success($html);
        
    } catch (Exception $e) {
        error_log('WPA Superstar Theme loading exception: ' . $e->getMessage());
        error_log('WPA Superstar Theme loading exception trace: ' . $e->getTraceAsString());
        wp_send_json_error('Theme loading error: ' . $e->getMessage());
    } catch (Error $e) {
        error_log('WPA Superstar Theme loading error: ' . $e->getMessage());
        error_log('WPA Superstar Theme loading error trace: ' . $e->getTraceAsString());
        wp_send_json_error('Theme loading error: ' . $e->getMessage());
    }
}
add_action('wp_ajax_wpa_get_theme', 'wpa_superstar_ajax_get_theme');

// Clear theme cache when themes are updated
function wpa_superstar_clear_theme_cache() {
    delete_transient('wpa_superstar_theme_kadence');
}
add_action('upgrader_process_complete', 'wpa_superstar_clear_theme_cache', 10, 0);
add_action('switch_theme', 'wpa_superstar_clear_theme_cache');

// Add AJAX handler for theme activation
function wpa_superstar_activate_theme() {
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
add_action('wp_ajax_wpa_activate_theme', 'wpa_superstar_activate_theme');

// Settings page HTML
function wpa_superstar_settings_page() {
    global $tabs;
    
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
    $active_category = isset($_GET['category']) ? $_GET['category'] : 'minimal';
    
    // Clear cache and load required files
    if ($active_tab === 'recommended') {
        wpa_superstar_clear_plugin_cache();
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        wp_enqueue_script('plugin-install');
        wp_enqueue_script('updates');
        add_thickbox();
    } elseif ($active_tab === 'theme') {
        wpa_superstar_clear_theme_cache();
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        wp_enqueue_script('theme-install');
        wp_enqueue_script('updates');
        add_thickbox();
    }
    ?>
    <div class="wrap wpa-superstar-wrap">
        <div class="wpa-superstar-header">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="wpa-superstar-header-actions">
                <span class="wpa-superstar-version">Version <?php echo esc_html(WPA_SUPERSTAR_VERSION); ?></span>
                <a href="https://www.wpallstars.com/docs/superstar-plugin/" target="_blank" class="button button-secondary">
                    <?php esc_html_e('Documentation', 'wpa-superstar'); ?>
                </a>
            </div>
        </div>
        
        <div class="wpa-settings-container">
            <div class="wpa-superstar-nav">
                <h2 class="nav-tab-wrapper">
                    <a href="?page=wpa-superstar&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('General', 'wpa-superstar'); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=workflow" class="nav-tab <?php echo $active_tab == 'workflow' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Workflow', 'wpa-superstar'); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Advanced', 'wpa-superstar'); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=recommended" class="nav-tab <?php echo $active_tab == 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Free Plugins', 'wpa-superstar'); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=theme" class="nav-tab <?php echo $active_tab == 'theme' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Theme', 'wpa-superstar'); ?>
                    </a>
                </h2>
            </div>

            <div class="wpa-settings-content">
                <?php if ($active_tab == 'workflow'): ?>
                    <form action="options.php" method="post">
                        <?php
                        settings_fields('wpa_superstar_workflow');
                        do_settings_sections('wpa_superstar_workflow');
                        submit_button();
                        ?>
                    </form>

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
                                    action: 'wpa_get_theme',
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
                                        action: 'wpa_activate_theme',
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
                        <a href="?page=wpa-superstar&tab=recommended&category=minimal" 
                           class="button <?php echo $active_category == 'minimal' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Minimal', 'wpa-superstar'); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=advanced" 
                           class="button <?php echo $active_category == 'advanced' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Advanced', 'wpa-superstar'); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=ecommerce" 
                           class="button <?php echo $active_category == 'ecommerce' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Ecommerce', 'wpa-superstar'); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=lms" 
                           class="button <?php echo $active_category == 'lms' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('LMS', 'wpa-superstar'); ?>
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
                                    action: 'wpa_get_plugins',
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

                <?php elseif ($active_tab == 'general'): ?>
                <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_lazy_load">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_lazy_load"
                                       name="wpa_superstar_lazy_load" 
                                       value="1" 
                                       <?php checked(get_option('wpa_superstar_lazy_load', 1)); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable lazy loading for images', 'wpa-superstar'); ?>
                    </label>
                        <p class="description">
                            <?php esc_html_e('Improves page load time by loading images only when they enter the viewport.', 'wpa-superstar'); ?>
                        </p>
                </div>

                <?php elseif ($active_tab == 'advanced'): ?>
                <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_minify_css">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_minify_css"
                                       name="wpa_superstar_minify_css" 
                                       value="1" 
                                       <?php checked(get_option('wpa_superstar_minify_css', 0)); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable CSS minification', 'wpa-superstar'); ?>
                    </label>
                        <p class="description">
                            <?php esc_html_e('Minifies CSS files to reduce file size and improve load times.', 'wpa-superstar'); ?>
                        </p>
                </div>

                <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_minify_js">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_minify_js"
                                       name="wpa_superstar_minify_js" 
                                       value="1" 
                                       <?php checked(get_option('wpa_superstar_minify_js', 0)); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable JS minification', 'wpa-superstar'); ?>
                    </label>
                        <p class="description">
                            <?php esc_html_e('Minifies JavaScript files to reduce file size and improve load times.', 'wpa-superstar'); ?>
                        </p>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// Add Workflow section
function wpa_superstar_workflow_section() {
    // Register Workflow settings
    register_setting('wpa_superstar_workflow', 'wpa_superstar_workflow_options');

    // Add Workflow section without title or description
    add_settings_section(
        'wpa_superstar_workflow_section',
        '',
        '__return_empty_string',
        'wpa_superstar_workflow'
    );

    // Add Auto Upload Images setting
    add_settings_field(
        'auto_upload_images',
        '',
        'wpa_superstar_auto_upload_images_callback',
        'wpa_superstar_workflow',
        'wpa_superstar_workflow_section'
    );
}

function wpa_superstar_auto_upload_images_callback() {
    $options = get_option('wpa_superstar_workflow_options', array(
        'auto_upload_images' => false
    ));
    ?>
    <div class="wpa-superstar-toggle">
        <label for="wpa_superstar_auto_upload_images">
            <div class="wpa-toggle-switch">
                <input type="checkbox" 
                       id="wpa_superstar_auto_upload_images"
                       name="wpa_superstar_workflow_options[auto_upload_images]" 
                       value="1"
                       <?php checked($options['auto_upload_images'], true); ?> 
                />
                <span class="wpa-toggle-slider"></span>
            </div>
            <?php esc_html_e('Enable Auto Upload Images', 'wpa-superstar'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Import images that have external URLs into your Media Library when saving. Consider disabling during large data imports with many external image URLs.', 'wpa-superstar'); ?>
        </p>
    </div>
    <?php
}

// Add Workflow section
add_action('admin_init', 'wpa_superstar_workflow_section');