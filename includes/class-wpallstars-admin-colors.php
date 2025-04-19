<?php
/**
 * WPALLSTARS Admin Colors Feature
 *
 * Manages the admin color scheme override, allowing users to toggle between
 * the default WordPress scheme and a custom 'modern' scheme provided by the plugin.
 *
 * @package WPALLSTARS
 * @subpackage Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class WPALLSTARS_Admin_Colors
 *
 * Handles the registration, application, and AJAX updates for the admin color scheme setting.
 */
class WPALLSTARS_Admin_Colors {

    /**
     * Option key stored in wp_options table for the toggle setting.
     * Uses 'wpallstars_options' array with a specific key for better organization.
     *
     * @var string
     */
    private $options_key = 'wpallstars_options';

    /**
     * Specific key within the options array for the color scheme setting.
     *
     * @var string
     */
    private $color_scheme_option_key = 'admin_color_scheme_enabled'; // Matches setting registration

    /**
     * The slug/key for the custom 'modern' color scheme.
     * Assumes this scheme is registered elsewhere (e.g., via wp_admin_css_color).
     *
     * @var string
     */
    private $modern_scheme_slug = 'modern'; // Ensure this matches registration

    /**
     * The slug/key for the default WordPress color scheme ('fresh').
     *
     * @var string
     */
    private $default_scheme_slug = 'fresh';

    /**
     * Nonce action name for security checks in AJAX handler.
     *
     * @var string
     */
    private $ajax_nonce_action = 'wpallstars_update_color_scheme_nonce'; // More specific nonce

    /**
     * Initialize the class and set up WordPress hooks.
     */
    public function __construct() {
        // Hook into admin initialization to potentially apply the scheme.
        add_action('admin_init', array($this, 'apply_user_admin_color_scheme'), 10); // Priority 10 is standard

        // Hook into AJAX action for updating the color scheme setting.
        add_action('wp_ajax_wpallstars_update_admin_color_scheme', array($this, 'handle_ajax_color_scheme_update'));

        // Hook to enqueue scripts specifically for the color scheme toggle functionality.
        // Note: This assumes the toggle appears on WP Allstars admin pages.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_color_scripts'));
    }

    /**
     * Enqueue JavaScript and localize data for the color scheme toggle.
     *
     * Only enqueues scripts on admin pages related to WP Allstars.
     *
     * @param string $hook_suffix The hook suffix of the current admin page.
     */
    public function enqueue_admin_color_scripts($hook_suffix) {
        // Use a more robust check for WP Allstars pages (using the registered page hook)
         $wpallstars_pages = [
            'toplevel_page_wpallstars-settings', // Example for top-level page
            'wpallstars_page_wpallstars-settings-network', // Example for network admin
            // Add other WP Allstars page slugs as needed
        ];
        // If using Admin_Manager, get the registered page hook slug.
        // For now, keep the simpler check, but ideally use the actual hook.
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'wpallstars') === false) {
             return; // Exit if not a WP Allstars page.
        }


        // Enqueue the specific JS file for color handling.
        wp_enqueue_script(
            'wpallstars-admin-colors-script', // More specific handle
            WPALLSTARS_URL . 'admin/js/wpallstars-admin-colors.js', // Use constant for URL
            array('jquery'),
            WPALLSTARS_VERSION, // Use constant for version
            true // Load in footer
        );

        // Localize data needed by the script.
        wp_localize_script(
            'wpallstars-admin-colors-script', // Must match the script handle
            'wpallstarsAdminColorsData', // JavaScript object name
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce($this->ajax_nonce_action),
                'ajax_action' => 'wpallstars_update_admin_color_scheme', // The action hook name
                'l10n' => [ // Localization strings
                    'saving' => __('Saving...', WPALLSTARS_TEXT_DOMAIN),
                    'saved' => __('Saved', WPALLSTARS_TEXT_DOMAIN),
                    'error' => __('Error', WPALLSTARS_TEXT_DOMAIN),
                ]
            )
        );
    }

    /**
     * Apply the selected admin color scheme for the current user upon admin initialization.
     *
     * Checks if the user has the capability and if the setting is enabled.
     */
    public function apply_user_admin_color_scheme() {
        // Ensure the current user has the capability to change schemes (usually 'manage_options' or similar).
        if (!current_user_can('manage_options')) { // Adjust capability if needed
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return; // Should not happen in admin_init, but good practice.
        }

        // Check if the modern scheme override is enabled in settings.
        $scheme_to_apply = $this->is_modern_color_scheme_enabled()
            ? $this->modern_scheme_slug
            : $this->default_scheme_slug;

        // Get the user's currently saved color scheme preference.
        $current_user_scheme = get_user_meta($user_id, 'admin_color', true);

        // Only update the user's meta if the desired scheme differs from their current one.
        // This prevents unnecessary database writes on every admin page load.
        if ($current_user_scheme !== $scheme_to_apply) {
            $this->update_user_color_scheme_preference($user_id, $scheme_to_apply);
        }
    }

    /**
     * Update a user's color scheme preference in their user meta.
     *
     * @param int $user_id The ID of the user to update.
     * @param string $scheme_slug The slug of the color scheme to set (e.g., 'modern', 'fresh').
     */
    private function update_user_color_scheme_preference($user_id, $scheme_slug) {
        // WordPress handles validation internally, but ensure the scheme exists if crucial.
        // global $_wp_admin_css_colors;
        // if (!isset($_wp_admin_css_colors[$scheme_slug])) {
        //     $scheme_slug = $this->default_scheme_slug; // Fallback to default
        // }

        // Update the 'admin_color' user meta field.
        update_user_meta($user_id, 'admin_color', $scheme_slug);
    }

    /**
     * Handle the AJAX request to enable/disable the modern color scheme override.
     *
     * Verifies nonce, checks user capabilities, updates the option,
     * updates the current user's scheme immediately, and sends a JSON response.
     */
    public function handle_ajax_color_scheme_update() {
        // 1. Verify Nonce for security.
        check_ajax_referer($this->ajax_nonce_action, 'nonce'); // Dies on failure

        // 2. Check User Capabilities.
        if (!current_user_can('manage_options')) { // Ensure user can change this setting
            wp_send_json_error(array('message' => __('Insufficient permissions.', WPALLSTARS_TEXT_DOMAIN)), 403); // 403 Forbidden
        }

        // 3. Sanitize and Validate Input.
        // Expecting 'enabled' to be 'true' or 'false' (as strings from JS).
        $is_enabled_input = isset($_POST['enabled']) ? sanitize_text_field($_POST['enabled']) : 'false';
        $is_enabled = ($is_enabled_input === 'true'); // Convert string 'true' to boolean true

        // 4. Update the Option in the Database.
        $options = get_option($this->options_key, []);
        $options[$this->color_scheme_option_key] = $is_enabled ? 1 : 0; // Store as 1 or 0
        $update_success = update_option($this->options_key, $options);

        // 5. Update Current User's Scheme Immediately for instant feedback.
        $user_id = get_current_user_id();
        $scheme_to_set = $is_enabled ? $this->modern_scheme_slug : $this->default_scheme_slug;
        $this->update_user_color_scheme_preference($user_id, $scheme_to_set);

        // 6. Send JSON Response.
        if ($update_success) {
            wp_send_json_success(array(
                'message' => $is_enabled
                    ? __('Modern admin color scheme enabled.', WPALLSTARS_TEXT_DOMAIN)
                    : __('Default admin color scheme restored.', WPALLSTARS_TEXT_DOMAIN),
                'new_state' => $is_enabled, // Send back the new state
            ));
        } else {
            // Option update might have failed, or the value was unchanged.
             // Check if the value was actually unchanged.
             $current_db_options = get_option($this->options_key, []);
             $current_db_value = isset($current_db_options[$this->color_scheme_option_key]) ? (bool)$current_db_options[$this->color_scheme_option_key] : false;
             if ($current_db_value === $is_enabled) {
                  wp_send_json_success(array(
                     'message' => __('Setting unchanged.', WPALLSTARS_TEXT_DOMAIN),
                     'new_state' => $is_enabled,
                     'unchanged' => true
                  ));
             } else {
                wp_send_json_error(array('message' => __('Failed to save setting.', WPALLSTARS_TEXT_DOMAIN)), 500); // 500 Internal Server Error
             }
        }
    }

    /**
     * Check if the modern color scheme override is enabled in the plugin settings.
     *
     * @return bool True if enabled, false otherwise.
     */
    public function is_modern_color_scheme_enabled() {
        $options = get_option($this->options_key, []);
        // Check if the specific key exists and is set to 1 (or true). Default to false if not set.
        return isset($options[$this->color_scheme_option_key]) && $options[$this->color_scheme_option_key] == 1;
    }
}