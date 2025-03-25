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
    }
    
    /**
     * Set up access control hooks
     */
    public static function setup_access_control() {
        // Get current user
        $user = wp_get_current_user();
        if (!$user->exists()) {
            return;
        }
        
        // Get user roles
        $user_roles = $user->roles;
        
        // Get restricted roles from settings
        $hide_admin_bar_roles = get_option('wp_allstars_hide_admin_bar_roles', array('guest', 'subscriber', 'customer'));
        $restrict_dashboard_roles = get_option('wp_allstars_restrict_dashboard_roles', array('guest', 'subscriber', 'customer'));
        
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