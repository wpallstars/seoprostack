<?php
/**
 * WP ALLSTARS Access Manager
 * 
 * Handles access control features like admin bar and dashboard access
 *
 * @package WP_ALLSTARS
 * @since 0.2.5
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Allstars_Access_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        
        // Add hooks for admin bar and dashboard access control
        add_action('init', array(__CLASS__, 'setup_access_control'));
        
        // Add AJAX handlers
        add_action('wp_ajax_wp_allstars_update_access_setting', array(__CLASS__, 'handle_access_setting_update'));
    }
    
    /**
     * Register settings for access control
     */
    public static function register_settings() {
        register_setting('wp_allstars_access', 'wp_allstars_hide_admin_bar_roles');
        register_setting('wp_allstars_access', 'wp_allstars_restrict_dashboard_roles');
    }
    
    /**
     * Enqueue scripts for the access control settings
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueue_scripts($hook) {
        if ('settings_page_wp-allstars' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'wp-allstars-admin',
            plugins_url('css/wp-allstars-admin.css', dirname(__FILE__)),
            array(),
            WP_ALLSTARS_VERSION
        );
        
        // Add inline JS for handling settings updates
        $access_js = '
        jQuery(document).ready(function($) {
            // Handle main toggle switches
            $("#wp_allstars_hide_admin_bar, #wp_allstars_restrict_dashboard").on("change", function() {
                var $this = $(this);
                var setting = $this.attr("id");
                var value = $this.is(":checked");
                
                // Clear any existing notifications
                $(".wp-setting-notification").remove();
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "wp_allstars_update_access_setting",
                        setting: setting,
                        value: value,
                        nonce: "' . wp_create_nonce('wp-allstars-nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            showSavedNotification($this);
                            
                            // Update UI based on toggle state
                            var $container = $this.closest(".wp-allstars-toggle");
                            var $settingsArea = $container.find(".wp-allstars-toggle-settings");
                            var $header = $container.find(".wp-allstars-toggle-header");
                            
                            if (value) {
                                // Set default roles (subscriber, customer) as checked when enabled
                                $settingsArea.find("input[value=\'subscriber\'], input[value=\'customer\']").prop("checked", true);
                                // Expand the section if it was toggled on
                                if ($header.attr("aria-expanded") === "false") {
                                    $header.attr("aria-expanded", "true");
                                    $settingsArea.slideDown(200);
                                }
                            } else {
                                // Clear all role checkboxes when disabled
                                $settingsArea.find("input[type=checkbox]").prop("checked", false);
                                // Optionally collapse the section if it was toggled off
                                // Uncomment this if you want the section to collapse when disabled
                                /*
                                if ($header.attr("aria-expanded") === "true") {
                                    $header.attr("aria-expanded", "false");
                                    $settingsArea.slideUp(200);
                                }
                                */
                            }
                        } else {
                            showErrorNotification($this);
                            // Revert the toggle to its previous state
                            $this.prop("checked", !value);
                        }
                    },
                    error: function() {
                        showErrorNotification($this);
                        // Revert the toggle to its previous state
                        $this.prop("checked", !value);
                    }
                });
            });
            
            // Handle role checkbox changes
            $(".wp-allstars-role-checkbox input").on("change", function() {
                var $this = $(this);
                var $container = $this.closest(".wp-allstars-role-checkboxes");
                var setting = $container.find("input").first().attr("name");
                var selectedRoles = [];
                
                // Clear any existing notifications
                $(".wp-setting-notification").remove();
                
                $container.find("input:checked").each(function() {
                    selectedRoles.push($(this).val());
                });
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "wp_allstars_update_access_setting",
                        setting: setting,
                        value: selectedRoles,
                        nonce: "' . wp_create_nonce('wp-allstars-nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            // Find the main toggle for this section
                            var $mainToggle = $this.closest(".wp-allstars-toggle").find(".wp-toggle-switch input");
                            
                            // Update the main toggle based on role selection
                            if (selectedRoles.length > 0) {
                                $mainToggle.prop("checked", true);
                                showSavedNotification($mainToggle);
                            } else {
                                $mainToggle.prop("checked", false);
                                showSavedNotification($mainToggle);
                            }
                        } else {
                            showErrorNotification($this);
                            // Revert the checkbox to its previous state
                            $this.prop("checked", !$this.prop("checked"));
                        }
                    },
                    error: function() {
                        showErrorNotification($this);
                        // Revert the checkbox to its previous state
                        $this.prop("checked", !$this.prop("checked"));
                    }
                });
            });
            
            function showSavedNotification($element) {
                // Find the nearest toggle header for notification placement
                var $toggleHeader = $element.closest(".wp-allstars-toggle").find(".wp-allstars-toggle-header");
                var $notification = $("<span>").addClass("wp-setting-notification success").text("Saved");
                
                // Remove any existing notifications
                $toggleHeader.find(".wp-setting-notification").remove();
                
                // Add the notification
                $toggleHeader.find("label").append($notification);
                
                // Remove notification after delay
                setTimeout(function() {
                    $notification.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 2000);
            }
            
            function showErrorNotification($element) {
                // Find the nearest toggle header for notification placement
                var $toggleHeader = $element.closest(".wp-allstars-toggle").find(".wp-allstars-toggle-header");
                var $notification = $("<span>").addClass("wp-setting-notification error").text("Error Saving");
                
                // Remove any existing notifications
                $toggleHeader.find(".wp-setting-notification").remove();
                
                // Add the notification
                $toggleHeader.find("label").append($notification);
                
                // Remove notification after delay
                setTimeout(function() {
                    $notification.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 2000);
            }
            
            // Toggle expandable settings panels
            $(".wp-allstars-toggle-header").on("click", function() {
                var $this = $(this);
                var $settings = $this.closest(".wp-allstars-toggle").find(".wp-allstars-toggle-settings");
                var isExpanded = $this.attr("aria-expanded") === "true";
                
                // Toggle aria-expanded attribute
                $this.attr("aria-expanded", !isExpanded);
                
                // Toggle settings visibility
                $settings.slideToggle(200);
            });
        });
        ';
        
        wp_add_inline_script('wp-allstars-admin', $access_js);
    }
    
    /**
     * Handle AJAX updates for access settings
     */
    public static function handle_access_setting_update() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp-allstars-nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Get and validate setting
        $setting = isset($_POST['setting']) ? sanitize_text_field($_POST['setting']) : '';
        $value = isset($_POST['value']) ? $_POST['value'] : '';
        
        if (empty($setting)) {
            wp_send_json_error('Invalid setting');
            return;
        }
        
        // Handle different setting types
        $result = false;
        switch ($setting) {
            case 'wp_allstars_hide_admin_bar':
                // When the main toggle is changed, update the roles option
                $default_roles = array('guest', 'subscriber', 'customer');
                $result = update_option('wp_allstars_hide_admin_bar_roles', $value ? $default_roles : array());
                break;
                
            case 'wp_allstars_restrict_dashboard':
                // When the main toggle is changed, update the roles option
                $default_roles = array('guest', 'subscriber', 'customer');
                $result = update_option('wp_allstars_restrict_dashboard_roles', $value ? $default_roles : array());
                break;
                
            case 'wp_allstars_hide_admin_bar_roles[]':
                // For role checkboxes, update the complete array
                if (is_array($value)) {
                    $value = array_map('sanitize_text_field', $value);
                    $result = update_option('wp_allstars_hide_admin_bar_roles', $value);
                }
                break;
                
            case 'wp_allstars_restrict_dashboard_roles[]':
                // For role checkboxes, update the complete array
                if (is_array($value)) {
                    $value = array_map('sanitize_text_field', $value);
                    $result = update_option('wp_allstars_restrict_dashboard_roles', $value);
                }
                break;
                
            default:
                wp_send_json_error('Invalid setting name');
                return;
        }
        
        if ($result) {
            wp_send_json_success(array('message' => 'Setting updated successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save setting'));
        }
    }
    
    /**
     * Set up access control hooks
     */
    public static function setup_access_control() {
        // Only run if the feature is enabled
        if (!get_option('wp_allstars_hide_admin_bar_roles') && !get_option('wp_allstars_restrict_dashboard_roles')) {
            return;
        }
        
        // Get current user
        $user = wp_get_current_user();
        if (!$user->exists()) {
            return;
        }
        
        // Get user roles
        $user_roles = $user->roles;
        
        // Get restricted roles from settings
        $hide_admin_bar_roles = get_option('wp_allstars_hide_admin_bar_roles', array());
        $restrict_dashboard_roles = get_option('wp_allstars_restrict_dashboard_roles', array());
        
        // Check if user's role is in restricted roles
        $should_hide_admin_bar = array_intersect($user_roles, $hide_admin_bar_roles);
        $should_restrict_dashboard = array_intersect($user_roles, $restrict_dashboard_roles);
        
        // Hide admin bar if needed
        if (!empty($should_hide_admin_bar)) {
            add_filter('show_admin_bar', '__return_false');
        }
        
        // Restrict dashboard access if needed
        if (!empty($should_restrict_dashboard) && is_admin() && !wp_doing_ajax()) {
            // Allow access to profile page
            if (isset($_GET['page']) && $_GET['page'] === 'profile.php') {
                return;
            }
            
            // Redirect to home page
            wp_redirect(home_url());
            exit;
        }
    }
    
    /**
     * Display the access control settings in the advanced tab
     */
    public static function display_access_settings() {
        // Get current settings
        $hide_admin_bar_roles = get_option('wp_allstars_hide_admin_bar_roles', array('guest', 'subscriber', 'customer'));
        $restrict_dashboard_roles = get_option('wp_allstars_restrict_dashboard_roles', array('guest', 'subscriber', 'customer'));
        
        // Get all available roles
        $roles = wp_roles()->get_names();
        
        ?>
        <!-- Admin Bar Control -->
        <div class="wp-allstars-toggle">
            <div class="wp-allstars-toggle-header" aria-expanded="false">
                <div class="wp-allstars-toggle-main">
                    <div class="wp-allstars-toggle-left">
                        <div class="wp-toggle-switch">
                            <input type="checkbox" 
                                   id="wp_allstars_hide_admin_bar"
                                   name="wp_allstars_hide_admin_bar" 
                                   value="1"
                                   <?php checked(!empty($hide_admin_bar_roles)); ?> 
                            />
                            <span class="wp-toggle-slider"></span>
                        </div>
                        <label for="wp_allstars_hide_admin_bar">
                            <?php esc_html_e('Admin Bar: Remove for these User Roles', 'wp-allstars'); ?>
                        </label>
                    </div>
                </div>
                <p class="wp-setting-description">
                    <?php esc_html_e('Remove the Admin Bar from showing for logged-in Users that have consumer Roles, where Admin is not relevant to.', 'wp-allstars'); ?>
                </p>
            </div>
            <div class="wp-allstars-toggle-settings">
                <div class="wp-allstars-setting-row">
                    <label><?php esc_html_e('Select User Roles', 'wp-allstars'); ?></label>
                    <div class="wp-allstars-role-checkboxes">
                        <?php foreach ($roles as $role_key => $role_name): ?>
                            <label class="wp-allstars-role-checkbox">
                                <input type="checkbox" 
                                       name="wp_allstars_hide_admin_bar_roles[]" 
                                       value="<?php echo esc_attr($role_key); ?>"
                                       <?php checked(in_array($role_key, $hide_admin_bar_roles)); ?> 
                                />
                                <?php echo esc_html($role_name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Access Control -->
        <div class="wp-allstars-toggle">
            <div class="wp-allstars-toggle-header" aria-expanded="false">
                <div class="wp-allstars-toggle-main">
                    <div class="wp-allstars-toggle-left">
                        <div class="wp-toggle-switch">
                            <input type="checkbox" 
                                   id="wp_allstars_restrict_dashboard"
                                   name="wp_allstars_restrict_dashboard" 
                                   value="1"
                                   <?php checked(!empty($restrict_dashboard_roles)); ?> 
                            />
                            <span class="wp-toggle-slider"></span>
                        </div>
                        <label for="wp_allstars_restrict_dashboard">
                            <?php esc_html_e('Dashboard: Prevent access for these User Roles', 'wp-allstars'); ?>
                        </label>
                    </div>
                </div>
                <p class="wp-setting-description">
                    <?php esc_html_e('Prevent the Admin Dashboard from being accessed by consumer Roles, where WP Admin is not relevant.', 'wp-allstars'); ?>
                </p>
            </div>
            <div class="wp-allstars-toggle-settings">
                <div class="wp-allstars-setting-row">
                    <label><?php esc_html_e('Select User Roles', 'wp-allstars'); ?></label>
                    <div class="wp-allstars-role-checkboxes">
                        <?php foreach ($roles as $role_key => $role_name): ?>
                            <label class="wp-allstars-role-checkbox">
                                <input type="checkbox" 
                                       name="wp_allstars_restrict_dashboard_roles[]" 
                                       value="<?php echo esc_attr($role_key); ?>"
                                       <?php checked(in_array($role_key, $restrict_dashboard_roles)); ?> 
                                />
                                <?php echo esc_html($role_name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
} 