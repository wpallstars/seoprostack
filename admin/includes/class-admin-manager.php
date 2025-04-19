<?php
/**
 * WPALLSTARS Admin Manager
 *
 * Manages the overall admin interface for the WPALLSTARS plugin, including menus, scripts, styles, and AJAX handlers.
 * Uses WPALLSTARS for class names/package/strings and wpallstars for slugs/handles/actions/nonces/options/textdomain/js vars/css classes.
 *
 * @package WPALLSTARS
 * @subpackage Admin
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPALLSTARS_Admin_Manager {
    
    /**
     * Initialize the class and register hooks
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('wp_ajax_wpallstars_update_option', array(__CLASS__, 'update_option'));
        // remove settings registration from here, it's handled by Settings_Manager
        // add_action('admin_init', array(__CLASS__, 'register_settings')); 
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));
        
        // Initialize all manager classes
        WPALLSTARS_Settings_Manager::init();
        WPALLSTARS_Theme_Manager::init();
        WPALLSTARS_Workflow_Manager::init();
        WPALLSTARS_Pro_Plugins_Manager::init();
        WPALLSTARS_Tools_Manager::init();
        WPALLSTARS_Hosting_Manager::init();
        WPALLSTARS_Free_Plugins_Manager::init();
        WPALLSTARS_Readme_Manager::init(); // Ensure Readme Manager is initialized
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook The current admin page hook
     */
    public static function enqueue_admin_scripts($hook) {
        // Correct hook for the settings submenu page
        $settings_page_hook = 'wpallstars_page_wpallstars-settings';

        // Only load specific assets on our settings page
        if ($settings_page_hook !== $hook) {
            // Maybe load global assets on all wpallstars pages?
            // if (strpos($hook, 'wpallstars') === false) return;
            return; // Exit if not the settings page for now
        }

        // Enqueue main admin stylesheet
        wp_enqueue_style(
            'wpallstars-admin', // Handle for the main admin CSS
            WPALLSTARS_URL . 'admin/css/wpallstars-admin.css', // Use constant defined in main plugin file
            array(),
            WPALLSTARS_VERSION // Use constant defined in main plugin file
        );

        // Enqueue main admin JavaScript (likely contains toggle functionality)
        wp_enqueue_script(
            'wpallstars-admin', // Handle for the main admin JS
            WPALLSTARS_URL . 'admin/js/wpallstars-admin.js', // Use constant for URL
            array('jquery'), // Dependencies
            WPALLSTARS_VERSION, // Use constant for version
            true // Load in footer
        );

        // Localize script with data for AJAX and other JS needs
        wp_localize_script('wpallstars-admin', 'wpallstars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpallstars-nonce'), // Generic nonce for general actions
            'update_option_nonce' => wp_create_nonce('wpallstars_update_option'), // Specific nonce for updating options via AJAX (if still needed)
            'l10n' => array( // Localization strings for JS
                'updating' => __('Updating...', WPALLSTARS_TEXT_DOMAIN),
                'error' => __('Error', WPALLSTARS_TEXT_DOMAIN),
                'success' => __('Success', WPALLSTARS_TEXT_DOMAIN),
            ),
            // Add any other data needed by wpallstars-admin.js
        ));

        // Settings Manager handles its own enqueuing via its init hook
        // WPALLSTARS_Settings_Manager::enqueue_settings_scripts($hook); // Removed
        
        // Other managers can still enqueue specific assets if needed
        // Example:
        // WPALLSTARS_Theme_Manager::enqueue_theme_scripts($hook); 
        // WPALLSTARS_Free_Plugins_Manager::enqueue_free_plugins_scripts($hook); 

        // Combine inline styles from various managers
        $inline_styles = ''; // Initialize
        // Uncomment and adjust class/method names as needed
        // $inline_styles .= WPALLSTARS_Pro_Plugins_Manager::get_inline_styles();
        // $inline_styles .= WPALLSTARS_Hosting_Manager::get_inline_styles();
        // $inline_styles .= WPALLSTARS_Tools_Manager::get_inline_styles();
        if (!empty($inline_styles)) {
            wp_add_inline_style('wpallstars-admin', $inline_styles);
        }

        // Combine inline scripts from various managers (use with caution)
        $inline_scripts = ''; // Initialize
        // $inline_scripts .= WPALLSTARS_Theme_Manager::get_theme_scripts(); 
        // $inline_scripts .= WPALLSTARS_Free_Plugins_Manager::get_plugin_scripts(); 
        // $inline_scripts .= WPALLSTARS_Settings_Manager::get_settings_scripts(); // Removed
        if (!empty($inline_scripts)) {
            wp_add_inline_script('wpallstars-admin', $inline_scripts);
        }
    }
    
    /**
     * Register core plugin settings - REMOVED
     * Settings registration is now handled by WPALLSTARS_Settings_Manager::register_settings
     */
    // public static function register_settings() { ... } 

    /**
     * AJAX handler for updating options (Generic handler, specific validation can be added)
     * Note: This is likely NOT used for the main settings form save action anymore,
     * which is handled by options.php via the Settings API.
     * Keep it if it's used for dynamic updates elsewhere.
     * Handles saving *individual* setting keys within the 'wpallstars_options' array via AJAX.
     */
    public static function update_option() {
        // Check nonce
        check_ajax_referer('wpallstars_update_option', 'nonce');

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', WPALLSTARS_TEXT_DOMAIN));
            return;
        }

        // Get setting key and value from POST data
        $setting_key = isset($_POST['setting_key']) ? sanitize_key($_POST['setting_key']) : '';
        // Don't sanitize value yet, do it based on the key type later
        $setting_value = isset($_POST['setting_value']) ? wp_unslash($_POST['setting_value']) : null;

        if (empty($setting_key) || is_null($setting_value)) {
            wp_send_json_error(__('Setting key or value is missing.', WPALLSTARS_TEXT_DOMAIN));
            return;
        }

        // --- Security & Validation --- 
        // Whitelist setting KEYS within 'wpallstars_options' that can be updated via this AJAX handler
        $allowed_options = array(
            'admin_color_scheme', // Example: Key for admin color scheme
            'enable_auto_upload', // Example: Key for auto-upload toggle
            'max_image_width',    // Example: Key for max image width
            // Add other keys registered in Settings_Manager that should be AJAX-updatable
        );

        if (!in_array($setting_key, $allowed_options, true)) {
            wp_send_json_error(__('Invalid setting key specified for AJAX update.', WPALLSTARS_TEXT_DOMAIN));
            return;
        }

        // Sanitize the value based on the specific setting key
        $sanitized_value = '';
        switch ($setting_key) {
            case 'admin_color_scheme':
                // Assuming it's a class name or identifier
                $sanitized_value = sanitize_html_class($setting_value);
                // If it was intended to be a hex color, use sanitize_hex_color($setting_value);
                break;
            case 'enable_auto_upload':
                // Boolean toggle (common JS values are 'true'/'false' strings)
                $sanitized_value = rest_sanitize_boolean($setting_value);
                break;
            case 'max_image_width':
                // Positive integer
                $sanitized_value = absint($setting_value);
                break;
            // Add cases for other allowed keys
            default:
                // Should not happen due to whitelist check, but as a fallback:
                $sanitized_value = sanitize_text_field($setting_value);
                break;
        }

        // Get the existing options array
        $options = get_option(WPALLSTARS_Settings_Manager::$option_name, array());

        // Store the old value for comparison
        $old_value = isset($options[$setting_key]) ? $options[$setting_key] : null;

        // Update the specific key in the options array
        $options[$setting_key] = $sanitized_value;

        // Save the entire options array back
        // update_option handles serialization and DB storage
        $result = update_option(WPALLSTARS_Settings_Manager::$option_name, $options);

        if ($result) {
            wp_send_json_success(__('Option updated successfully.', WPALLSTARS_TEXT_DOMAIN));
        } else {
            // Option might not have changed, or error occurred
            // Check if the value is the same as the old value (update_option returns false if value hasn't changed)
            if ($old_value === $sanitized_value) {
                wp_send_json_success(__('Option unchanged.', WPALLSTARS_TEXT_DOMAIN));
            } else {
                wp_send_json_error(__('Failed to update option.', WPALLSTARS_TEXT_DOMAIN));
            }
        }
    }

    /**
     * Register the admin menu item
     */
    public static function register_admin_menu() {
        add_menu_page(
            __('WP Allstars', WPALLSTARS_TEXT_DOMAIN),
            __('WP Allstars', WPALLSTARS_TEXT_DOMAIN),
            'manage_options',
            'wpallstars-dashboard', // Main dashboard slug
            array(__CLASS__, 'render_settings_page'), // Default to settings page for now
            'dashicons-star-filled',
            85
        );

        // Add actual settings submenu
        add_submenu_page(
            'wpallstars-dashboard', // Parent slug
            __('Settings', WPALLSTARS_TEXT_DOMAIN), // Page title
            __('Settings', WPALLSTARS_TEXT_DOMAIN), // Menu title
            'manage_options', // Capability
            'wpallstars-settings', // Settings slug << THIS IS THE SLUG TO USE
            array(__CLASS__, 'render_settings_page') // Callback function
        );

        // Add other submenus here using their respective managers or callbacks
    }

    /**
     * Render the settings page container and handle tab navigation.
     * Content for each tab is rendered by the respective manager class.
     */
    public static function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', WPALLSTARS_TEXT_DOMAIN));
        }

        // Whitelist of valid tabs
        $valid_tabs = array(
            'general'     => __('General', WPALLSTARS_TEXT_DOMAIN),
            'advanced'    => __('Advanced', WPALLSTARS_TEXT_DOMAIN),
            'workflow'    => __('Workflow', WPALLSTARS_TEXT_DOMAIN),
            'theme'       => __('Theme', WPALLSTARS_TEXT_DOMAIN),
            'recommended' => __('Free Plugins', WPALLSTARS_TEXT_DOMAIN),
            'pro'         => __('Pro Plugins', WPALLSTARS_TEXT_DOMAIN),
            'hosting'     => __('Hosting', WPALLSTARS_TEXT_DOMAIN),
            'tools'       => __('Tools', WPALLSTARS_TEXT_DOMAIN),
            'readme'      => __('Read Me', WPALLSTARS_TEXT_DOMAIN),
        );

        // Get current tab from URL, default to 'general'
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        // Fallback to 'general' if the tab is not in the whitelist
        if (!array_key_exists($current_tab, $valid_tabs)) {
            $current_tab = 'general';
        }

        ?>
        <div class="wrap wpallstars-wrap wpallstars-settings-wrap">
            <h1><?php esc_html_e('WP Allstars Settings', WPALLSTARS_TEXT_DOMAIN); ?></h1>

            <?php settings_errors(); // Display any settings errors registered by the Settings API ?>

            <div class="wpallstars-settings-container">
                <h2 class="nav-tab-wrapper">
                    <?php
                    // Define tabs with their labels (already defined in $valid_tabs)
                    $tabs = $valid_tabs;

                    // Output tab navigation links using the correct settings page slug
                    foreach ($tabs as $tab_slug => $tab_label) {
                        $tab_url = add_query_arg(array('page' => 'wpallstars-settings', 'tab' => $tab_slug), admin_url('admin.php'));
                        $class = ($current_tab === $tab_slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
                        echo '<a href="' . esc_url($tab_url) . '" class="' . esc_attr($class) . '">' . esc_html($tab_label) . '</a>';
                    }
                    ?>
                </h2>

                <div class="wpallstars-tab-content-container">
                    <?php
                    // Display content based on the active tab by calling the appropriate manager's display method
                    // Using a switch statement for clarity
                    switch ($current_tab) {
                        case 'general':
                            if (class_exists('WPALLSTARS_Settings_Manager')) WPALLSTARS_Settings_Manager::display_general_tab();
                            break;
                        case 'advanced':
                            if (class_exists('WPALLSTARS_Settings_Manager')) WPALLSTARS_Settings_Manager::display_advanced_tab();
                            break;
                        case 'workflow':
                            if (class_exists('WPALLSTARS_Workflow_Manager')) WPALLSTARS_Workflow_Manager::display_tab_content();
                            break;
                        case 'theme':
                            if (class_exists('WPALLSTARS_Theme_Manager')) WPALLSTARS_Theme_Manager::display_tab_content();
                            break;
                        case 'recommended':
                            if (class_exists('WPALLSTARS_Free_Plugins_Manager')) WPALLSTARS_Free_Plugins_Manager::display_tab_content();
                            break;
                        case 'pro':
                            if (class_exists('WPALLSTARS_Pro_Plugins_Manager')) WPALLSTARS_Pro_Plugins_Manager::display_tab_content();
                            break;
                        case 'hosting':
                            if (class_exists('WPALLSTARS_Hosting_Manager')) WPALLSTARS_Hosting_Manager::display_tab_content();
                            break;
                        case 'tools':
                            if (class_exists('WPALLSTARS_Tools_Manager')) WPALLSTARS_Tools_Manager::display_tab_content();
                            break;
                        case 'readme':
                            if (class_exists('WPALLSTARS_Readme_Manager')) WPALLSTARS_Readme_Manager::display_tab_content();
                            break;
                        default:
                            // Should not happen due to whitelist check, but good practice
                            if (class_exists('WPALLSTARS_Settings_Manager')) WPALLSTARS_Settings_Manager::display_general_tab();
                            break;
                    }
                    ?>
                </div><!-- .wpallstars-tab-content-container -->
            </div><!-- .wpallstars-settings-container -->
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Alias for enqueue_admin_scripts to potentially maintain compatibility
     * if other parts of the codebase mistakenly call this.
     *
     * @deprecated Prefer calling enqueue_admin_scripts directly.
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_scripts($hook) {
        self::enqueue_admin_scripts($hook);
    }
}

// Instantiate the Admin Manager when the admin area is loaded
if (is_admin()) {
    // Ensure the class exists before calling init (optional safety check)
    if (class_exists('WPALLSTARS_Admin_Manager')) {
        WPALLSTARS_Admin_Manager::init();
    }
}
