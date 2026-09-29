<?php
/**
 * WP Allstars settings store.
 *
 * Every plugin setting lives in a single `wp_allstars_options` array and is
 * described by a schema entry (type, default, UI metadata). Features read
 * values with WP_Allstars_Settings::get(); the admin UI renders cards from
 * the same schema; the AJAX endpoint and the Settings API sanitize through
 * it. Add settings with the `wp_allstars_settings_schema` filter.
 *
 * @package WP_ALLSTARS
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Settings {

    /** Option that stores every setting. */
    const OPTION = 'wp_allstars_options';

    /** Settings API group. */
    const GROUP = 'wp_allstars_settings';

    /** Nonce action shared by admin AJAX requests. */
    const NONCE = 'wp_allstars_admin';

    /** Stored schema version, used for one-off migrations. */
    const DB_VERSION_OPTION = 'wp_allstars_db_version';
    const DB_VERSION = 1;

    /**
     * Request-level cache of the resolved schema.
     *
     * @var array|null
     */
    private static $schema = null;

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_migrate'), 5);
        add_action('admin_init', array(__CLASS__, 'register_setting'));
        add_action('wp_ajax_wp_allstars_save_setting', array(__CLASS__, 'ajax_save'));
    }

    /**
     * Setting definitions.
     *
     * Keys:
     * - type:        bool | int | text | domains
     * - default:     default value
     * - tab:         admin tab slug (top-level settings only)
     * - parent:      parent setting key (renders inside the parent's panel)
     * - label, description, placeholder, min, max, unit, tokens: UI metadata
     *
     * @return array<string,array>
     */
    public static function schema() {
        if (null !== self::$schema) {
            return self::$schema;
        }

        $schema = array(
            'modern_admin_colors' => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'general',
                'label'       => __('Modern admin colours', 'wp-allstars'),
                'description' => __('Use the WordPress “Modern” admin colour scheme for everyone. Your profile is set to Modern when on and back to the WordPress default when off; other users keep their own choice.', 'wp-allstars'),
            ),
            'auto_upload_images' => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'workflow',
                'label'       => __('Auto upload images', 'wp-allstars'),
                'description' => __('When a post is saved, copy external images into the Media Library and point the content at the local copy.', 'wp-allstars'),
            ),
            'auto_upload_max_width' => array(
                'type'        => 'int',
                'default'     => 2560,
                'min'         => 0,
                'max'         => 10000,
                'unit'        => 'px',
                'parent'      => 'auto_upload_images',
                'label'       => __('Maximum width', 'wp-allstars'),
                'description' => __('Larger images are scaled down before upload. 0 keeps the original size.', 'wp-allstars'),
            ),
            'auto_upload_max_height' => array(
                'type'        => 'int',
                'default'     => 2560,
                'min'         => 0,
                'max'         => 10000,
                'unit'        => 'px',
                'parent'      => 'auto_upload_images',
                'label'       => __('Maximum height', 'wp-allstars'),
                'description' => __('Larger images are scaled down before upload. 0 keeps the original size.', 'wp-allstars'),
            ),
            'auto_upload_exclude_domains' => array(
                'type'        => 'domains',
                'default'     => '',
                'parent'      => 'auto_upload_images',
                'label'       => __('Excluded domains', 'wp-allstars'),
                'description' => __('One domain per line. Images from these domains (and their subdomains) stay external.', 'wp-allstars'),
                'placeholder' => "cdn.example.com\nimages.example.org",
            ),
            'auto_upload_filename_pattern' => array(
                'type'        => 'text',
                'default'     => '%filename%',
                'parent'      => 'auto_upload_images',
                'label'       => __('File name pattern', 'wp-allstars'),
                'description' => __('Name given to uploaded files.', 'wp-allstars'),
                'tokens'      => array('%filename%', '%post_id%', '%postname%', '%post_title%', '%timestamp%', '%date%', '%year%', '%month%', '%day%'),
            ),
            'auto_upload_alt_pattern' => array(
                'type'        => 'text',
                'default'     => '%post_title%',
                'parent'      => 'auto_upload_images',
                'label'       => __('Alt text pattern', 'wp-allstars'),
                'description' => __('Used when an image has no alt text. Leave empty to keep images without alt text unchanged.', 'wp-allstars'),
                'tokens'      => array('%filename%', '%post_id%', '%postname%', '%post_title%'),
            ),
        );

        /**
         * Filter the settings schema to add or adjust settings.
         *
         * @param array $schema Setting definitions keyed by setting key.
         */
        self::$schema = (array) apply_filters('wp_allstars_settings_schema', $schema);

        return self::$schema;
    }

    /**
     * Default values for every setting.
     *
     * @return array
     */
    public static function defaults() {
        $defaults = array();
        foreach (self::schema() as $key => $field) {
            $defaults[$key] = isset($field['default']) ? $field['default'] : null;
        }
        return $defaults;
    }

    /**
     * All settings merged over defaults.
     *
     * @return array
     */
    public static function all() {
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return array_merge(self::defaults(), array_intersect_key($stored, self::schema()));
    }

    /**
     * Read one setting.
     *
     * @param string $key Setting key.
     * @return mixed Value, or null for unknown keys.
     */
    public static function get($key) {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : null;
    }

    /**
     * Update one setting after sanitizing it.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Raw value.
     * @return mixed|WP_Error Sanitized value, or error for unknown keys.
     */
    public static function set($key, $value) {
        $schema = self::schema();
        if (!isset($schema[$key])) {
            return new WP_Error('wp_allstars_unknown_setting', __('Unknown setting.', 'wp-allstars'));
        }

        $options       = self::all();
        $options[$key] = self::sanitize_value($value, $schema[$key]);
        update_option(self::OPTION, $options);

        return $options[$key];
    }

    /**
     * Top-level settings for a tab.
     *
     * @param string $tab Tab slug.
     * @return array
     */
    public static function fields_for_tab($tab) {
        return array_filter(self::schema(), function ($field) use ($tab) {
            return empty($field['parent']) && isset($field['tab']) && $field['tab'] === $tab;
        });
    }

    /**
     * Child settings rendered in a parent's panel.
     *
     * @param string $parent Parent setting key.
     * @return array
     */
    public static function children_of($parent) {
        return array_filter(self::schema(), function ($field) use ($parent) {
            return isset($field['parent']) && $field['parent'] === $parent;
        });
    }

    /**
     * Sanitize a value according to its schema entry.
     *
     * @param mixed $value Raw value.
     * @param array $field Schema entry.
     * @return mixed
     */
    public static function sanitize_value($value, array $field) {
        $type = isset($field['type']) ? $field['type'] : 'text';

        switch ($type) {
            case 'bool':
                return rest_sanitize_boolean($value);

            case 'int':
                $value = is_numeric($value) ? (int) $value : (int) $field['default'];
                if (isset($field['min'])) {
                    $value = max((int) $field['min'], $value);
                }
                if (isset($field['max'])) {
                    $value = min((int) $field['max'], $value);
                }
                return $value;

            case 'domains':
                return implode("\n", self::parse_domains($value));

            case 'text':
            default:
                // Callers pass unslashed input; keep this idempotent because
                // update_option() re-runs sanitize_all() on the stored array.
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * Normalise a newline/comma separated domain list to bare lowercase hosts.
     *
     * @param mixed $value Raw list.
     * @return string[]
     */
    public static function parse_domains($value) {
        $lines   = preg_split('/[\r\n,]+/', (string) $value);
        $domains = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $host = wp_parse_url(false === strpos($line, '://') ? 'http://' . $line : $line, PHP_URL_HOST);
            $host = $host ? strtolower(preg_replace('/^www\./i', '', $host)) : '';
            if ('' !== $host && preg_match('/^[a-z0-9.-]+$/', $host)) {
                $domains[] = $host;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Sanitize the whole array when saved through options.php or update_option().
     *
     * @param mixed $input Raw option value.
     * @return array
     */
    public static function sanitize_all($input) {
        $input  = is_array($input) ? $input : array();
        $schema = self::schema();
        $clean  = self::all();

        foreach ($input as $key => $value) {
            if (isset($schema[$key])) {
                $clean[$key] = self::sanitize_value($value, $schema[$key]);
            }
        }

        return $clean;
    }

    /**
     * Register the option with the Settings API.
     */
    public static function register_setting() {
        register_setting(self::GROUP, self::OPTION, array(
            'type'              => 'object',
            'sanitize_callback' => array(__CLASS__, 'sanitize_all'),
            'default'           => self::defaults(),
            'show_in_rest'      => false,
        ));
    }

    /**
     * AJAX: save a single setting (used by instant-save controls).
     */
    public static function ajax_save() {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to change these settings.', 'wp-allstars')), 403);
        }

        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per schema in set().
        $raw = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

        $value = self::set($key, $raw);
        if (is_wp_error($value)) {
            wp_send_json_error(array('message' => $value->get_error_message()), 400);
        }

        /**
         * Fires after a setting is saved from the admin UI.
         *
         * @param string $key   Setting key.
         * @param mixed  $value Sanitized value.
         */
        do_action('wp_allstars_setting_saved', $key, $value);

        wp_send_json_success(array(
            'key'     => $key,
            'value'   => $value,
            'message' => __('Saved', 'wp-allstars'),
        ));
    }

    /**
     * Copy pre-0.3.0 individual options into the settings array once.
     *
     * Legacy options are left in place so a downgrade keeps working;
     * uninstall.php removes them.
     */
    public static function maybe_migrate() {
        if ((int) get_option(self::DB_VERSION_OPTION, 0) >= self::DB_VERSION) {
            return;
        }

        $options = get_option(self::OPTION, array());
        $options = is_array($options) ? $options : array();

        $legacy_map = array(
            'wp_allstars_admin_color_scheme' => 'modern_admin_colors',
            'wp_allstars_auto_upload_images' => 'auto_upload_images',
            'wp_allstars_max_width'          => 'auto_upload_max_width',
            'wp_allstars_max_height'         => 'auto_upload_max_height',
            'wp_allstars_exclude_urls'       => 'auto_upload_exclude_domains',
            'wp_allstars_image_name_pattern' => 'auto_upload_filename_pattern',
            'wp_allstars_image_alt_pattern'  => 'auto_upload_alt_pattern',
        );

        $schema = self::schema();
        foreach ($legacy_map as $legacy => $key) {
            $legacy_value = get_option($legacy, null);
            if (null !== $legacy_value && '' !== $legacy_value && !array_key_exists($key, $options)) {
                $options[$key] = self::sanitize_value($legacy_value, $schema[$key]);
            }
        }

        update_option(self::OPTION, array_merge(self::defaults(), $options));
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }
}
