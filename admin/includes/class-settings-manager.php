<?php
/**
 * Settings Manager Class
 * 
 * Handles the display and management of plugin settings tabs (General and Advanced).
 * Leverages the WordPress Settings API.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class WPALLSTARS_Settings_Manager
 * Manages WP Allstars plugin settings using the WordPress Settings API.
 */
class WPALLSTARS_Settings_Manager {

    /**
     * Option group name.
     * @var string
     */
    private static $option_group = 'wpallstars_settings_group';

    /**
     * Option name stored in the database.
     * @var string
     */
    private static $option_name = 'wpallstars_options';

    /**
     * Initialize the class
     */
    public static function init() {
        // Register settings using admin_init hook
        add_action('admin_init', array(self::class, 'register_settings'));
        
        // Enqueue scripts and styles needed for settings page
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_scripts'));
    }
    
    /**
     * Register plugin settings, sections, and fields.
     */
    public static function register_settings() {
        // Register the single option array to store all settings
        register_setting(
            self::$option_group,          // Option group
            self::$option_name,           // Option name
            array(self::class, 'sanitize_settings') // Sanitize callback
        );

        // --- General Settings Section --- 
        add_settings_section(
            'wpallstars_general_settings_section', // ID
            __('General Settings', WPALLSTARS_TEXT_DOMAIN), // Title
            null, // Callback function (optional description)
            'wpallstars_general_settings_page' // Page slug where this section will be shown
        );

        // Field: Admin Color Scheme
        add_settings_field(
            'admin_color_scheme', // Field ID (matches key in options array)
            __('Modern Admin Colors', WPALLSTARS_TEXT_DOMAIN), // Title
            array(self::class, 'render_admin_color_field'), // Callback to render the field
            'wpallstars_general_settings_page', // Page
            'wpallstars_general_settings_section' // Section
        );

        // Field: Simple Toggle Example (Keeping for now, can be removed later)
        add_settings_field(
            'simple_toggle_example', // Field ID
            __('Example: Simple Toggle', WPALLSTARS_TEXT_DOMAIN), // Title
            array(self::class, 'render_simple_toggle_field'), // Callback
            'wpallstars_general_settings_page', // Page
            'wpallstars_general_settings_section' // Section
        );

        // --- Advanced Settings Section --- 
        add_settings_section(
            'wpallstars_advanced_settings_section', // ID
            __('Advanced Settings', WPALLSTARS_TEXT_DOMAIN), // Title
            null, // Callback
            'wpallstars_advanced_settings_page' // Page slug
        );
        
        // Field: Auto Upload Images
        add_settings_field(
            'auto_upload_enabled', // Field ID (matching key used in WPALLSTARS_Auto_Upload)
            __('Auto Upload External Images', WPALLSTARS_TEXT_DOMAIN), // Title
            array(self::class, 'render_auto_upload_field'), // Callback
            'wpallstars_advanced_settings_page', // Page
            'wpallstars_advanced_settings_section' // Section
        );

        // Field: Sync Guard Enabled
        add_settings_field(
            'sync_guard_enabled', // Field ID (matching key used in WPALLSTARS_Sync_Guard)
            __('Enable Sync Guard', WPALLSTARS_TEXT_DOMAIN), // Title
            array(self::class, 'render_sync_guard_field'), // Callback
            'wpallstars_advanced_settings_page', // Page
            'wpallstars_advanced_settings_section' // Section
        );

        // Add more fields for other settings as needed (e.g., Sync Guard Mode, Debug Mode)
        // ... add_settings_field(...) ...

    }
    
    /**
     * Sanitize settings array before saving.
     *
     * @param array $input The input array from the settings form.
     * @return array The sanitized array to be saved.
     */
    public static function sanitize_settings($input) {
        $sanitized_input = array();
        $options = get_option(self::$option_name, array()); // Get existing options

        // Ensure $input is an array
        if (!is_array($input)) {
            $input = array();
        }

        // Sanitize: Admin Color Scheme (checkbox - 1 or 0)
        $sanitized_input['admin_color_scheme'] = isset($input['admin_color_scheme']) ? 1 : 0;

        // Sanitize: Simple Toggle Example (checkbox - 1 or 0)
        $sanitized_input['simple_toggle_example'] = isset($input['simple_toggle_example']) ? 1 : 0;

        // Sanitize: Auto Upload Enabled (checkbox - 1 or 0)
        $sanitized_input['auto_upload_enabled'] = isset($input['auto_upload_enabled']) ? 1 : 0;
        
        // Sanitize: Sync Guard Enabled (checkbox - 1 or 0)
        $sanitized_input['sync_guard_enabled'] = isset($input['sync_guard_enabled']) ? 1 : 0;
        
        // Example: Sanitize a text field (if we add one later)
        // if (isset($input['api_key'])) {
        //     $sanitized_input['api_key'] = sanitize_text_field($input['api_key']);
        // } else {
        //     $sanitized_input['api_key'] = $options['api_key'] ?? ''; // Keep existing if not set
        // }

        // Merge with existing options to preserve settings not on the current form
        // This might not be necessary if all settings are always present or handled by defaults.
        // Consider if default values are sufficient or if merging is required.
        // $sanitized_input = array_merge($options, $sanitized_input); 

        // Add settings saved notice (WordPress handles this automatically on successful save)
        // add_settings_error(...); // No need for this here anymore

        return $sanitized_input;
    }

    /**
     * Enqueue scripts and styles needed for settings page.
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_scripts($hook) {
        // Define the specific hook for the WPAllstars settings page
        $settings_page_hook = 'wpallstars_page_wpallstars-settings';

        // Only enqueue assets on the WPAllstars settings page
        if ($settings_page_hook !== $hook) {
            return;
        }

        // Enqueue the color picker if needed (example)
        // wp_enqueue_style('wp-color-picker');
        // wp_enqueue_script('wp-color-picker');

        // Enqueue specific CSS for the settings page if necessary
        // Example:
        // wp_enqueue_style(
        //     'wpallstars-settings-styles',
        //     WPALLSTARS_URL . 'admin/css/wpallstars-settings.css',
        //     array(),
        //     WPALLSTARS_VERSION
        // );

        // If there was specific JS for settings toggles/interactions (removed inline JS previously)
        // it should be enqueued here from a dedicated file.
        // Example:
        // wp_enqueue_script(
        //     'wpallstars-settings-script',
        //     WPALLSTARS_URL . 'admin/js/wpallstars-settings.js',
        //     array('jquery', 'wp-color-picker'), // Add dependencies
        //     WPALLSTARS_VERSION,
        //     true // Load in footer
        // );
        
        // Localize data specifically for the settings script if needed
        // wp_localize_script('wpallstars-settings-script', 'wpallstarsSettings', array(
        //     'someSettingNonce' => wp_create_nonce('wpallstars-setting-action-nonce'),
        //     // Add other data...
        // ));
    }
    
    /**
     * Renders the form structure for the General Settings Tab.
     * Called by the Admin Manager when displaying the settings page.
     */
    public static function display_general_tab() {
        ?>
        <form method="post" action="options.php">
            <?php
            // Output security fields for the registered setting group
            settings_fields(self::$option_group); // Use the option group name
            
            // Output setting sections and fields for the 'general' page
            do_settings_sections('wpallstars_general_settings_page'); // Use the page slug
            
            // Output save settings button
            submit_button(__('Save General Settings', WPALLSTARS_TEXT_DOMAIN));
            ?>
        </form>
        <?php
    }
    
    /**
     * Renders the form structure for the Advanced Settings Tab.
     * Called by the Admin Manager when displaying the settings page.
     */
    public static function display_advanced_tab() {
        ?>
         <form method="post" action="options.php">
            <?php
            // Output security fields for the registered setting group
            settings_fields(self::$option_group); // Use the option group name
            
            // Output setting sections and fields for the 'advanced' page
            do_settings_sections('wpallstars_advanced_settings_page'); // Use the page slug
            
            // Output save settings button
            submit_button(__('Save Advanced Settings', WPALLSTARS_TEXT_DOMAIN));
            ?>
        </form>
        <?php
    }

    // --- Field Rendering Callbacks --- //

    /**
     * Get the current value for a specific setting key.
     *
     * @param string $key The key within the wpallstars_options array.
     * @param mixed $default Default value if the key is not set.
     * @return mixed The setting value.
     */
    private static function get_setting_value($key, $default = '') {
        $options = get_option(self::$option_name, array());
        return $options[$key] ?? $default;
    }

    /**
     * Render the Admin Color Scheme toggle field.
     */
    public static function render_admin_color_field() {
        $options = get_option(self::$option_name, array());
        $setting_key = 'admin_color_scheme'; // Key in the options array
        $value = isset($options[$setting_key]) ? $options[$setting_key] : 'default'; // Default value
        $field_id = self::$option_name . '_' . $setting_key; // Unique ID for label 'for' attribute

        // Define color scheme options (example)
        $color_schemes = array(
            'default' => __('Default', WPALLSTARS_TEXT_DOMAIN),
            'light' => __('Light', WPALLSTARS_TEXT_DOMAIN),
            'blue' => __('Blue', WPALLSTARS_TEXT_DOMAIN),
            'coffee' => __('Coffee', WPALLSTARS_TEXT_DOMAIN),
            'ectoplasm' => __('Ectoplasm', WPALLSTARS_TEXT_DOMAIN),
            'midnight' => __('Midnight', WPALLSTARS_TEXT_DOMAIN),
            'ocean' => __('Ocean', WPALLSTARS_TEXT_DOMAIN),
            'sunrise' => __('Sunrise', WPALLSTARS_TEXT_DOMAIN),
            'modern' => __('Modern (WPAllstars)', WPALLSTARS_TEXT_DOMAIN),
        );

        ?>
        <select 
            id="<?php echo esc_attr($field_id); ?>" 
            name="<?php echo esc_attr(self::$option_name . '[' . $setting_key . ']'); ?>" 
            class="wpallstars-ajax-save" 
            data-setting-key="<?php echo esc_attr($setting_key); ?>"
        >
            <?php foreach ($color_schemes as $scheme_slug => $scheme_name) : ?>
                <option value="<?php echo esc_attr($scheme_slug); ?>" <?php selected($value, $scheme_slug); ?>>
                    <?php echo esc_html($scheme_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="wpallstars-ajax-feedback"></span> <!-- Feedback area for AJAX -->
        <p class="description">
            <?php esc_html_e('Select an admin color scheme. Changes are saved instantly.', WPALLSTARS_TEXT_DOMAIN); ?>
        </p>
        <?php
    }

    /**
     * Render the Simple Toggle example field.
     */
    public static function render_simple_toggle_field() {
        $key = 'simple_toggle_example';
        $value = self::get_setting_value($key, 0);
        ?>
         <div class="wp-setting-row">
            <div class="wp-setting-header">
                <div class="wp-setting-main">
                    <div class="wp-setting-left">
                        <div class="wp-toggle-switch">
                            <input type="checkbox" 
                                   id="<?php echo esc_attr(self::$option_name . '_' . $key); ?>" 
                                   name="<?php echo esc_attr(self::$option_name . '[' . $key . ']'); ?>" 
                                   value="1"
                                   <?php checked($value, 1); ?> 
                            />
                            <span class="wp-toggle-slider"></span>
                        </div>
                         <label for="<?php echo esc_attr(self::$option_name . '_' . $key); ?>" class="wp-setting-label">
                             <?php // Label is provided by add_settings_field ?>
                        </label>
                    </div>
                </div>
                <p class="wp-setting-description description">
                    <?php esc_html_e('This is an example of a simple toggle setting without an expandable panel. Currently for demonstration purposes only.', WPALLSTARS_TEXT_DOMAIN); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Auto Upload toggle field.
     */
    public static function render_auto_upload_field() {
        $options = get_option(self::$option_name, array());
        $setting_key = 'auto_upload_enabled'; // Key in the options array
        $value = isset($options[$setting_key]) ? (bool) $options[$setting_key] : false; // Default to false
        $field_id = self::$option_name . '_' . $setting_key; // Unique ID for label 'for' attribute
        ?>
        <label for="<?php echo esc_attr($field_id); ?>">
            <input 
                type="checkbox" 
                id="<?php echo esc_attr($field_id); ?>" 
                name="<?php echo esc_attr(self::$option_name . '[' . $setting_key . ']'); ?>" 
                value="1" 
                class="wpallstars-ajax-save" 
                data-setting-key="<?php echo esc_attr($setting_key); ?>" 
                <?php checked($value); ?>
            >
            <?php esc_html_e('Automatically upload external images referenced in post content on save.', WPALLSTARS_TEXT_DOMAIN); ?>
        </label>
        <span class="wpallstars-ajax-feedback"></span> <!-- Feedback area for AJAX -->
        <p class="description">
            <?php esc_html_e('When enabled, images from external URLs will be downloaded to the media library and their URLs updated in the post.', WPALLSTARS_TEXT_DOMAIN); ?><br>
            <em><?php esc_html_e('Note: This happens instantly when the checkbox is changed.', WPALLSTARS_TEXT_DOMAIN); ?></em>
        </p>
        <?php
    }

    /**
     * Render the Sync Guard toggle field.
     */
    public static function render_sync_guard_field() {
        $key = 'sync_guard_enabled';
        $value = self::get_setting_value($key, 0);
        ?>
        <div class="wp-allstars-toggle"> 
            <div class="wp-allstars-toggle-header" aria-expanded="false"> 
                <div class="wp-allstars-toggle-main">
                    <div class="wp-allstars-toggle-left">
                        <div class="wp-toggle-switch">
                            <input type="checkbox" 
                                   id="<?php echo esc_attr(self::$option_name . '_' . $key); ?>" 
                                   name="<?php echo esc_attr(self::$option_name . '[' . $key . ']'); ?>" 
                                   value="1"
                                   <?php checked($value, 1); ?> 
                            />
                            <span class="wp-toggle-slider"></span>
                        </div>
                        <label for="<?php echo esc_attr(self::$option_name . '_' . $key); ?>">
                            <?php // Label provided by add_settings_field ?>
                        </label>
                    </div>
                </div>
                <p class="wp-setting-description description">
                    <?php esc_html_e('Protect against data loss during simultaneous edits or synchronization.', WPALLSTARS_TEXT_DOMAIN); ?>
                </p>
            </div>
             <div class="wp-allstars-toggle-settings" style="display: none;"> 
                 <div class="wp-allstars-setting-row">
                     <p class="description">
                         <?php esc_html_e('When enabled, Sync Guard uses timestamps or locking mechanisms (depending on mode) to prevent multiple users or processes from overwriting each other\s changes, especially during sync operations like cloud storage or Git.', WPALLSTARS_TEXT_DOMAIN); ?>
                     </p>
                     <?php /* Add sub-settings for Sync Guard mode here */ ?>
                 </div>
             </div>
        </div>
        <?php
    }

    /* Removed the custom save_settings method as it's handled by the Settings API */
    // public static function save_settings() { ... } 

}

// --- Initialization --- 
// Assuming WPALLSTARS_Admin_Manager calls WPALLSTARS_Settings_Manager::init()
// If not, uncomment the line below, but it's better practice to centralize initialization.
// WPALLSTARS_Settings_Manager::init();
