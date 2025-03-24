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

// Include tools data
require_once dirname(__FILE__) . '/data/tools.php';


// Include hosting providers data
require_once dirname(__FILE__) . '/data/hosting-providers.php';

// Include recommended plugins data
require_once dirname(__FILE__) . '/data/recommended-plugins.php';

// Include the Plugin Manager class
require_once dirname(__FILE__) . '/includes/class-plugin-manager.php';

// Include the Pro Plugins Manager class
require_once dirname(__FILE__) . '/includes/class-pro-plugins-manager.php';

// Initialize the managers
WP_Allstars_Plugin_Manager::init();
WP_Allstars_Pro_Plugins_Manager::init();


// Remove the old plugins API filter since we're handling everything in the AJAX endpoint
remove_filter('plugins_api_result', 'wp_allstars_plugins_api_result');

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
    // Debug information
    error_log('Theme activation AJAX request received: ' . print_r($_POST, true));
    
    // Check nonce with the correct action name
    if (!check_ajax_referer('wp-allstars-nonce', '_wpnonce', false)) {
        error_log('Theme activation failed: Invalid nonce');
        wp_send_json_error('Invalid security token sent.');
        return;
    }
    
    if (!current_user_can('switch_themes')) {
        error_log('Theme activation failed: Permission denied');
        wp_send_json_error('Permission denied');
        return;
    }
    
    $theme = isset($_POST['theme']) ? sanitize_text_field($_POST['theme']) : '';
    if (empty($theme)) {
        error_log('Theme activation failed: No theme specified');
        wp_send_json_error('No theme specified');
        return;
    }
    
    // Get the theme object
    $theme_obj = wp_get_theme($theme);
    if (!$theme_obj->exists()) {
        error_log('Theme activation failed: Theme does not exist - ' . $theme);
        wp_send_json_error('Theme does not exist');
        return;
    }
    
    if ($theme_obj->errors()) {
        error_log('Theme activation failed: Theme has errors - ' . print_r($theme_obj->errors(), true));
        wp_send_json_error('Theme has errors');
        return;
    }
    
    // Check if theme is already active
    $current_theme = wp_get_theme();
    if ($current_theme->get_stylesheet() === $theme) {
        error_log('Theme is already active: ' . $theme);
        wp_send_json_success(array(
            'message' => 'Theme is already active',
            'customize_url' => admin_url('customize.php')
        ));
        return;
    }
    
    // Switch the theme
    error_log('Switching to theme: ' . $theme);
    switch_theme($theme);
    
    // Check if the switch was successful
    $active_theme = wp_get_theme();
    if ($active_theme->get_stylesheet() === $theme) {
        error_log('Theme activated successfully: ' . $theme);
        wp_send_json_success(array(
            'message' => 'Theme activated successfully',
            'customize_url' => admin_url('customize.php')
        ));
    } else {
        error_log('Failed to activate theme: ' . $theme . ', active theme is: ' . $active_theme->get_stylesheet());
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
        WP_Allstars_Plugin_Manager::clear_plugin_cache();
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
                <span class="wp-allstars-version"><?php echo esc_html(WP_ALLSTARS_VERSION); ?></span>
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
                        <div class="wpa-pro-plugins">
                            <?php
                            $hosting_providers = wp_allstars_get_hosting_providers();
                            // Sort providers alphabetically by name
                            uasort($hosting_providers, function($a, $b) {
                                return strcasecmp($a['name'], $b['name']);
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
                        <?php WP_Allstars_Pro_Plugins_Manager::display_tab_content(); ?>
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
                <?php elseif ($active_tab == 'theme'): ?>
                    <div class="tab-content" id="theme">
                        <div id="wpa-theme-list" class="wpa-theme-container">
                            <!-- Theme content will be loaded via AJAX -->
                            <div class="wp-allstars-loading-overlay">
                                <span class="spinner is-active"></span>
                                <p>Loading theme data...</p>
                            </div>
                        </div>
                        <script>
                        jQuery(document).ready(function($) {
                            // Get the theme data
                            var $container = $('#wpa-theme-list');
                            var $loadingOverlay = $('<div class="wp-allstars-loading-overlay"><span class="spinner is-active"></span></div>');
                            
                            // Show loading overlay
                            $container.css('position', 'relative').append($loadingOverlay);
                            
                            // AJAX request to get theme
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'wp_allstars_get_themes',
                                    _wpnonce: wpAllstars.nonce
                                },
                                success: function(response) {
                                    // Remove loading overlay
                                    $loadingOverlay.remove();
                                    
                                    if (response.success) {
                                        // Append theme HTML
                                        $container.html(response.data);
                                        
                                        // Initialize theme action buttons
                                        initThemeHandlers();
                                    } else {
                                        // Show error message
                                        $container.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    // Remove loading overlay
                                    $loadingOverlay.remove();
                                    
                                    // Show error message
                                    $container.html('<div class="notice notice-error"><p>Failed to load theme. Please try again. Error: ' + error + '</p></div>');
                                    console.error('AJAX Error:', xhr.responseText);
                                }
                            });
                            
                            // Initialize theme handlers
                            window.initThemeHandlers = function() {
                                // Activate theme
                                $('.activate-now').click(function(e) {
                                    e.preventDefault();
                                    
                                    var $button = $(this);
                                    var slug = $button.data('slug');
                                    var name = $button.data('name');
                                    var nonce = $button.data('nonce');
                                    
                                    $button.text('Activating...').addClass('updating-message').attr('disabled', true);
                                    
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'wp_allstars_activate_theme',
                                            theme: slug,
                                            _wpnonce: nonce
                                        },
                                        success: function(response) {
                                            if (response.success) {
                                                $button.removeClass('updating-message').addClass('updated-message').text('Activated');
                                                setTimeout(function() {
                                                    window.location.reload();
                                                }, 1000);
                                            } else {
                                                $button.removeClass('updating-message').text('Error');
                                                alert('Error: ' + response.data);
                                            }
                                        },
                                        error: function(xhr, status, error) {
                                            $button.removeClass('updating-message').text('Error');
                                            alert('Error: ' + error);
                                        }
                                    });
                                });
                            };
                        });
                        </script>
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
                                return strcasecmp($a['name'], $b['name']);
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