<?php
/**
 * Theme Manager Class
 * 
 * Handles the display and management of recommended themes.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_Allstars_Theme_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // Register AJAX handlers
        add_action('wp_ajax_wp_allstars_get_themes', array(self::class, 'ajax_get_themes'));
        add_action('wp_ajax_wp_allstars_activate_theme', array(self::class, 'activate_theme'));
        
        // Cache clearing hooks
        add_action('upgrader_process_complete', array(self::class, 'clear_theme_cache'), 10, 0);
        add_action('switch_theme', array(self::class, 'clear_theme_cache'));
        
        // Enqueue assets when needed
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_scripts'));
    }
    
    /**
     * Enqueue scripts and styles for the theme tab
     */
    public static function enqueue_scripts($hook) {
        if (strpos($hook, 'wp-allstars') === false) {
            return;
        }
        
        // Only load on theme tab
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'theme') {
            return;
        }
        
        // Required for theme installation
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        wp_enqueue_script('theme-install');
        wp_enqueue_script('updates');
        add_thickbox();
        
        // Styles
        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', dirname(__FILE__)));
        wp_enqueue_style('wp-allstars-plugins', plugins_url('css/wp-allstars-plugins.css', dirname(__FILE__)));
        
        // Enqueue the main admin script before adding inline script
        wp_enqueue_script('wp-allstars-admin', plugins_url('js/wp-allstars-admin.js', dirname(__FILE__)), array('jquery'), '1.0.0', true);
        
        // Localize the script with nonce data
        wp_localize_script('wp-allstars-admin', 'wpAllstars', array(
            'nonce' => wp_create_nonce('wp-allstars-nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
        
        // Add inline script for theme loading and interaction
        wp_add_inline_script('wp-allstars-admin', self::get_theme_scripts());
    }
    
    /**
     * Get theme scripts for inline inclusion
     */
    private static function get_theme_scripts() {
        return '
            jQuery(document).ready(function($) {
                if ($("#wpa-theme-list").length) {
                    var $container = $("#wpa-theme-list");
                    var $loadingOverlay = $container.find(".wp-allstars-loading-overlay");
                    
                    // AJAX request to get themes
                    $.ajax({
                        url: wpAllstars.ajaxurl,
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
                
                // Initialize theme handlers
                window.initThemeHandlers = function() {
                    // Activate theme
                    $(".activate-now").click(function(e) {
                        e.preventDefault();
                        
                        var $button = $(this);
                        var slug = $button.data("slug");
                        var name = $button.data("name");
                        var nonce = $button.data("nonce");
                        
                        $button.text("Activating...").addClass("updating-message").attr("disabled", true);
                        
                        $.ajax({
                            url: ajaxurl,
                            type: "POST",
                            data: {
                                action: "wp_allstars_activate_theme",
                                theme: slug,
                                _wpnonce: nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    $button.removeClass("updating-message").addClass("updated-message").text("Activated");
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 1000);
                                } else {
                                    $button.removeClass("updating-message").text("Error");
                                    alert("Error: " + response.data);
                                }
                            },
                            error: function(xhr, status, error) {
                                $button.removeClass("updating-message").text("Error");
                                alert("Error: " + error);
                            }
                        });
                    });
                };
            });
        ';
    }
    
    /**
     * Display the theme tab content
     */
    public static function display_tab_content() {
        ?>
        <div class="wp-allstars-settings-content tab-content" id="theme">
            <div id="wpa-theme-list" class="wpa-theme-container">
                <!-- Theme content will be loaded via AJAX -->
                <div class="wp-allstars-loading-overlay">
                    <span class="spinner is-active"></span>
                    <p>Loading theme data...</p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get cached theme
     */
    public static function get_cached_theme() {
        $cache_key = 'wp_allstars_theme_kadence';
        return get_transient($cache_key);
    }
    
    /**
     * Set cached theme
     */
    public static function set_cached_theme($data) {
        $cache_key = 'wp_allstars_theme_kadence';
        set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
    }
    
    /**
     * Clear theme cache
     */
    public static function clear_theme_cache() {
        delete_transient('wp_allstars_theme_kadence');
    }
    
    /**
     * AJAX endpoint for getting themes
     */
    public static function ajax_get_themes() {
        error_log('WP ALLSTARS: Theme AJAX handler started');
        
        // Check nonce with the correct action name
        if (!isset($_POST['_wpnonce'])) {
            error_log('WP ALLSTARS: No nonce provided');
            wp_send_json_error('No security token provided.');
            return;
        }
        
        // Use verify_nonce instead of check_ajax_referer for more reliable verification
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wp-allstars-nonce')) {
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
            $theme_data = self::get_cached_theme();
            
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
                    self::set_cached_theme($theme_data);
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
            include(plugin_dir_path(dirname(__FILE__)) . 'partials/theme-panel.php');
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
    
    /**
     * AJAX handler for theme activation
     */
    public static function activate_theme() {
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
        
        // Set the new theme
        switch_theme($theme);
        error_log('Theme activation success - ' . $theme);
        wp_send_json_success();
    }
}
