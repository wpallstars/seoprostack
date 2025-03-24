<?php
/**
 * WP ALLSTARS Admin Manager
 * 
 * Handles admin-related functionality including menu registration, 
 * script enqueueing, and admin page rendering.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Allstars_Admin_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // Register hooks - we'll add more as we refactor each function
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('wp_ajax_wp_allstars_update_option', array(__CLASS__, 'update_option'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));
    }
    
    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook The current admin page hook
     */
    public static function enqueue_admin_scripts($hook) {
        if ('settings_page_wp-allstars' !== $hook) {
            return;
        }

        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', dirname(__FILE__)));
        wp_enqueue_script('wp-allstars-admin', plugins_url('js/wp-allstars-admin.js', dirname(__FILE__)), array('jquery'), WP_ALLSTARS_VERSION, true);
        
        // Localize the script with new data
        wp_localize_script('wp-allstars-admin', 'wpAllstars', array(
            'nonce' => wp_create_nonce('wp-allstars-nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }
    
    /**
     * Register plugin settings
     */
    public static function register_settings() {
        // Removed minification settings
        // This is a placeholder for future settings registration
    }
    
    /**
     * AJAX handler for updating options
     */
    public static function update_option() {
        check_ajax_referer('wp-allstars-nonce', 'nonce');
        $option = sanitize_text_field($_POST['option']);
        $value = intval($_POST['value']);
        update_option($option, $value);
        wp_send_json_success('Option updated');
    }
    
    /**
     * Register the admin menu item
     */
    public static function register_admin_menu() {
        add_options_page(
            'WP ALLSTARS Settings',
            'WP ALLSTARS',
            'manage_options',
            'wp-allstars',
            'wp_allstars_settings_page'
        );
    }
    
    /**
     * Render the settings page
     */
    public static function render_settings_page() {
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
            wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', dirname(__FILE__)));
            wp_enqueue_style('wp-allstars-plugins', plugins_url('css/wp-allstars-plugins.css', dirname(__FILE__)));
            
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
        }
        ?>
        <div class="wrap wp-allstars-wrap">
            <div class="wp-allstars-header">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <div class="wp-allstars-header-actions">
                    <span class="wp-allstars-version"><?php echo esc_html(WP_ALLSTARS_VERSION); ?></span>
                    <a href="https://www.wpallstars.com/" target="_blank" class="button button-secondary">
                        <?php esc_html_e('Visit Website', 'wp-allstars'); ?>
                    </a>
                </div>
            </div>
            
            <div class="wp-allstars-tabs-wrapper">
                <h2 class="nav-tab-wrapper">
                    <a href="?page=wp-allstars&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('General', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=advanced" class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Advanced', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=pro" class="nav-tab <?php echo $active_tab === 'pro' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Pro', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=theme" class="nav-tab <?php echo $active_tab === 'theme' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Theme', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=recommended" class="nav-tab <?php echo $active_tab === 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Recommended Plugins', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=hosting" class="nav-tab <?php echo $active_tab === 'hosting' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Hosting', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Tools', 'wp-allstars'); ?>
                    </a>
                </h2>
                
                <div class="wp-allstars-tab-content">
                    <?php
                    // Each tab's content is handled by its respective manager class
                    switch ($active_tab) {
                        case 'general':
                            WP_Allstars_Settings_Manager::render_general_tab();
                            break;
                            
                        case 'advanced':
                            WP_Allstars_Settings_Manager::render_advanced_tab();
                            break;
                            
                        case 'pro':
                            WP_Allstars_Pro_Plugins_Manager::render_tab();
                            break;
                            
                        case 'theme':
                            WP_Allstars_Theme_Manager::render_tab();
                            break;
                            
                        case 'recommended':
                            WP_Allstars_Recommended_Plugins_Manager::render_tab($active_category);
                            break;
                            
                        case 'hosting':
                            WP_Allstars_Hosting_Manager::render_tab();
                            break;
                            
                        case 'tools':
                            WP_Allstars_Tools_Manager::render_tab();
                            break;
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
}
