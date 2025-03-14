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
            'turnstile'
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
        wp_send_json_error('Invalid category');
    }
    
    // Try to get cached data first
    $cached_data = wpa_superstar_get_cached_plugins($category);
    if ($cached_data !== false) {
        // Setup the list table with cached data
        $GLOBALS['tab'] = 'plugin-install';
        $_REQUEST['tab'] = 'plugin-install';
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
    
    // If no cache, get fresh data
    try {
        $plugins = array();
        foreach ($recommended_plugins[$category] as $slug) {
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
            
            if (!is_wp_error($plugin_data)) {
                $plugins[] = $plugin_data;
            }
        }
        
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
        wp_send_json_error('Failed to fetch plugin data');
    }
}

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

// Settings page HTML
function wpa_superstar_settings_page() {
    global $tabs;
    
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
    $active_category = isset($_GET['category']) ? $_GET['category'] : 'minimal';
    
    // Ensure required files are loaded
    if ($active_tab === 'recommended') {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        wp_enqueue_script('plugin-install');
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
                    <a href="?page=wpa-superstar&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Advanced', 'wpa-superstar'); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=recommended" class="nav-tab <?php echo $active_tab == 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Free Plugins', 'wpa-superstar'); ?>
                    </a>
                </h2>
            </div>

            <div class="wpa-settings-content">
                <?php if ($active_tab == 'recommended'): ?>
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
                                    }
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
                        
                        // Load plugins on page load
                        loadPlugins();
                        
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