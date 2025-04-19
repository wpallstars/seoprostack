<?php
/**
 * WPALLSTARS Settings Manager
 *
 * Manages the plugin's settings using the WordPress Settings API.
 *
 * @package WPALLSTARS
 * @subpackage Admin
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WPALLSTARS_Settings_Manager {

    /**
     * Option group name.
     * Used for register_setting() and settings_fields().
     * @var string
     */
    public static $option_group = 'wpallstars_settings_group';

    /**
     * Option name stored in the wp_options table.
     * @var string
     */
    public static $option_name = 'wpallstars_options';

    /**
     * Initialize the settings manager.
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }

    /**
     * Register plugin settings, sections, and fields using the Settings API.
     */
    public static function register_settings() {
        // Register the single option array
        register_setting(
            self::$option_group,      // Option group
            self::$option_name,       // Option name
            array(__CLASS__, 'sanitize_settings') // Sanitization callback
        );

        // --- General Settings Section ---
        add_settings_section(
            'wpallstars_general_settings_section', // Section ID
            __('General Settings', WPALLSTARS_TEXT_DOMAIN), // Title
            null, // Callback function (optional)
            'wpallstars_general_settings_page' // Page slug where this section appears
        );

        add_settings_field(
            'admin_color_scheme', // Field ID (should match key in $options array)
            __('Modern Admin Colors', WPALLSTARS_TEXT_DOMAIN), // Title
            array(__CLASS__, 'render_admin_color_field'), // Callback function to render the field
            'wpallstars_general_settings_page', // Page
            'wpallstars_general_settings_section' // Section
        );

        add_settings_field(
            'simple_setting', // Field ID
            __('Example: Simple Toggle', WPALLSTARS_TEXT_DOMAIN), // Title
            array(__CLASS__, 'render_simple_setting_field'), // Callback
            'wpallstars_general_settings_page', // Page
            'wpallstars_general_settings_section' // Section
        );

        // --- Advanced Settings Section ---
        add_settings_section(
            'wpallstars_advanced_settings_section', // Section ID
            __('Advanced Settings', WPALLSTARS_TEXT_DOMAIN), // Title
            null, // Callback
            'wpallstars_advanced_settings_page' // Page slug
        );

        add_settings_field(
            'auto_upload_images', // Field ID
            __('Example: Expandable Panel', WPALLSTARS_TEXT_DOMAIN), // Title
            array(__CLASS__, 'render_auto_upload_images_field'), // Callback
            'wpallstars_advanced_settings_page', // Page
            'wpallstars_advanced_settings_section' // Section
        );

        add_settings_field(
            'example_text', // Field ID
            __('Example Text Field', WPALLSTARS_TEXT_DOMAIN), // Title
            array(__CLASS__, 'render_example_text_field'), // Callback
            'wpallstars_advanced_settings_page', // Page
            'wpallstars_advanced_settings_section' // Section
        );

        // Add more fields and sections as needed...
    }

    /**
     * Sanitize the settings array before saving.
     *
     * @param array $input The input array from the settings form.
     * @return array Sanitized array of options.
     */
    public static function sanitize_settings($input) {
        $sanitized_options = array();
        $current_options = get_option(self::$option_name, array());

        // Sanitize each expected setting
        if (isset($input['admin_color_scheme'])) {
            // Assuming it's a class name/slug
            $sanitized_options['admin_color_scheme'] = sanitize_html_class($input['admin_color_scheme']);
        } else {
            $sanitized_options['admin_color_scheme'] = isset($current_options['admin_color_scheme']) ? $current_options['admin_color_scheme'] : 'default';
        }

        if (isset($input['simple_setting'])) {
            $sanitized_options['simple_setting'] = true; // Checkbox value is '1' if checked
        } else {
            $sanitized_options['simple_setting'] = false; // Not present if unchecked
        }

        if (isset($input['auto_upload_images'])) {
            $sanitized_options['auto_upload_images'] = true; // Checkbox value is '1' if checked
        } else {
            $sanitized_options['auto_upload_images'] = false; // Not present if unchecked
        }

        if (isset($input['example_text'])) {
            $sanitized_options['example_text'] = sanitize_text_field($input['example_text']);
        } else {
            $sanitized_options['example_text'] = isset($current_options['example_text']) ? $current_options['example_text'] : '';
        }

        // Add sanitization for other fields...

        // You can add settings errors here if validation fails
        // Example:
        // if ($sanitized_options['max_image_width'] < 100) {
        //     add_settings_error(
        //         'max_image_width',
        //         'max_image_width_error',
        //         __('Max image width must be at least 100px.', WPALLSTARS_TEXT_DOMAIN),
        //         'error'
        //     );
        //     // Revert to old value or a default if error
        //     $sanitized_options['max_image_width'] = isset($current_options['max_image_width']) ? $current_options['max_image_width'] : 1920;
        // }

        return $sanitized_options;
    }

    /**
     * Enqueue scripts and styles needed for the settings page.
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_scripts($hook) {
        // Use the original hook based on add_options_page slug ('wpallstars')
        // The hook is typically 'settings_page_{menu_slug}' for pages added under Settings
        $settings_page_hook = 'settings_page_wpallstars'; 

        if ($settings_page_hook !== $hook) {
            return;
        }

        // Enqueue specific CSS/JS if needed for settings page elements (e.g., color picker)
        // wp_enqueue_style('wp-color-picker');
        // wp_enqueue_script('wp-color-picker-alpha', plugins_url('path/to/wp-color-picker-alpha.min.js', __FILE__), array('wp-color-picker'), null, true);
    }

    /**
     * Display the content for the General Settings tab.
     */
    public static function display_general_tab() {
        ?>
        <form method="post" action="options.php">
            <?php
            // Output necessary hidden fields for the settings API
            settings_fields(self::$option_group);
            
            // Output the settings sections and fields for this page
            do_settings_sections('wpallstars_general_settings_page');
            
            // Add the submit button
            submit_button(__('Save General Settings', WPALLSTARS_TEXT_DOMAIN));
            ?>
        </form>
        <?php
    }

    /**
     * Display the content for the Advanced Settings tab.
     */
    public static function display_advanced_tab() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields(self::$option_group); // Same option group
            do_settings_sections('wpallstars_advanced_settings_page');
            submit_button(__('Save Advanced Settings', WPALLSTARS_TEXT_DOMAIN));
            ?>
        </form>
        <?php
    }

    /**
     * Helper function to get a specific setting's value.
     *
     * @param string $key The key of the setting within the options array.
     * @param mixed $default Optional default value if the key isn't set.
     * @return mixed The value of the setting or the default.
     */
    public static function get_setting_value($key, $default = null) {
        $options = get_option(self::$option_name, array());
        return isset($options[$key]) ? $options[$key] : $default;
    }

    // --- Field Rendering Callbacks ---

    /**
     * Render the 'Modern Admin Colors' setting field (Example: Select Dropdown)
     */
    public static function render_admin_color_field() {
        $setting_key = 'admin_color_scheme';
        $value = self::get_setting_value($setting_key, 'default'); // Default value
        $field_id = self::$option_name . '_' . $setting_key;

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
        >
            <?php foreach ($color_schemes as $scheme_slug => $scheme_name) : ?>
                <option value="<?php echo esc_attr($scheme_slug); ?>" <?php selected($value, $scheme_slug); ?>>
                    <?php echo esc_html($scheme_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Select an admin color scheme.', WPALLSTARS_TEXT_DOMAIN); ?>
        </p>
        <?php
    }

    /**
     * Render the 'Example: Simple Toggle' setting field (Example: Checkbox)
     */
    public static function render_simple_setting_field() {
        $setting_key = 'simple_setting';
        $value = (bool) self::get_setting_value($setting_key, false); // Default to false
        $field_id = self::$option_name . '_' . $setting_key;
        ?>
        <label for="<?php echo esc_attr($field_id); ?>">
            <input 
                type="checkbox" 
                id="<?php echo esc_attr($field_id); ?>" 
                name="<?php echo esc_attr(self::$option_name . '[' . $setting_key . ']'); ?>" 
                value="1" 
                <?php checked($value); ?>
            >
            <?php esc_html_e('This is an example of a simple toggle setting without an expandable panel. Currently for demonstration purposes only.', WPALLSTARS_TEXT_DOMAIN); ?>
        </label>
        <p class="description">
            <?php esc_html_e('This setting does not have any actual functionality.', WPALLSTARS_TEXT_DOMAIN); ?>
        </p>
        <?php
    }

    /**
     * Render the 'Example: Expandable Panel' setting field (Example: Checkbox)
     */
    public static function render_auto_upload_images_field() {
        $setting_key = 'auto_upload_images';
        $value = (bool) self::get_setting_value($setting_key, false); // Default to false
        $field_id = self::$option_name . '_' . $setting_key;
        ?>
        <label for="<?php echo esc_attr($field_id); ?>">
            <input 
                type="checkbox" 
                id="<?php echo esc_attr($field_id); ?>" 
                name="<?php echo esc_attr(self::$option_name . '[' . $setting_key . ']'); ?>" 
                value="1" 
                <?php checked($value); ?>
            >
            <?php esc_html_e('This is an example of an expandable panel setting. Currently for demonstration purposes only - no actual functionality.', WPALLSTARS_TEXT_DOMAIN); ?>
        </label>
        <p class="description">
            <?php esc_html_e('This setting does not have any actual functionality.', WPALLSTARS_TEXT_DOMAIN); ?>
        </p>
        <?php
    }

    /**
     * Render the 'Example Text Field' setting field (Example: Text Input)
     */
    public static function render_example_text_field() {
        $setting_key = 'example_text';
        $value = self::get_setting_value($setting_key, ''); // Default value
        $field_id = self::$option_name . '_' . $setting_key;
        ?>
        <input 
            type="text" 
            id="<?php echo esc_attr($field_id); ?>" 
            name="<?php echo esc_attr(self::$option_name . '[' . $setting_key . ']'); ?>" 
            value="<?php echo esc_attr($value); ?>" 
        >
        <p class="description">
            <?php esc_html_e('This is an example text field for demonstration purposes.', WPALLSTARS_TEXT_DOMAIN); ?>
        </p>
        <?php
    }
    
    // Add rendering callbacks for other fields...

}
