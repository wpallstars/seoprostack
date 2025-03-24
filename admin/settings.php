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

// Include manager classes
require_once dirname(__FILE__) . '/includes/class-plugin-manager.php';
require_once dirname(__FILE__) . '/includes/class-pro-plugins-manager.php';
require_once dirname(__FILE__) . '/includes/class-settings-manager.php';
require_once dirname(__FILE__) . '/includes/class-tools-manager.php';
require_once dirname(__FILE__) . '/includes/class-theme-manager.php';
require_once dirname(__FILE__) . '/includes/class-hosting-manager.php';
require_once dirname(__FILE__) . '/includes/class-recommended-plugins-manager.php';

// Initialize the managers
WP_Allstars_Plugin_Manager::init();
WP_Allstars_Pro_Plugins_Manager::init();
WP_Allstars_Settings_Manager::init();
WP_Allstars_Tools_Manager::init();
WP_Allstars_Theme_Manager::init();
WP_Allstars_Hosting_Manager::init();
WP_Allstars_Recommended_Plugins_Manager::init();


// Remove the old plugins API filter since we're handling everything in the AJAX endpoint
remove_filter('plugins_api_result', 'wp_allstars_plugins_api_result');

// Theme functionality has been moved to WP_Allstars_Theme_Manager class

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
    // Theme-specific scripts and styles are now handled in WP_Allstars_Theme_Manager::enqueue_scripts
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
                    <a href="?page=wp-allstars&tab=theme" class="nav-tab <?php echo $active_tab == 'theme' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Theme', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=recommended" class="nav-tab <?php echo $active_tab == 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Free Plugins', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=pro" class="nav-tab <?php echo $active_tab == 'pro' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Pro Plugins', 'wp-allstars'); ?>
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
                        <?php WP_Allstars_Hosting_Manager::display_tab_content(); ?>
                    </div>
                <?php elseif ($active_tab == 'recommended'): ?>
                    <div class="tab-content" id="recommended">
                        <?php WP_Allstars_Recommended_Plugins_Manager::display_tab_content(); ?>
                    </div>
                <?php elseif ($active_tab == 'pro'): ?>
                    <div class="tab-content" id="pro">
                        <?php WP_Allstars_Pro_Plugins_Manager::display_tab_content(); ?>
                    </div>
                <?php elseif ($active_tab == 'general'): ?>
                    <div class="tab-content" id="general">
                        <?php WP_Allstars_Settings_Manager::display_general_tab(); ?>
                    </div>
                <?php elseif ($active_tab == 'advanced'): ?>
                    <div class="tab-content" id="advanced">
                        <?php WP_Allstars_Settings_Manager::display_advanced_tab(); ?>
                    </div>
                <?php elseif ($active_tab == 'theme'): ?>
                    <div class="tab-content" id="theme">
                        <?php WP_Allstars_Theme_Manager::display_tab_content(); ?>
                    </div>
                <?php elseif ($active_tab == 'tools'): ?>
                    <div class="tab-content" id="tools">
                        <?php WP_Allstars_Tools_Manager::display_tab_content(); ?>
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