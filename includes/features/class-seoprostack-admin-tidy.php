<?php
/**
 * Tidy admin screens.
 *
 * Small WordPress extras in wp-admin: the "Thank you for creating with
 * WordPress" footer, the core update notice for people who cannot update,
 * and the theme and plugin file editors. Core hooks and constants only;
 * nothing is stored.
 *
 * Replaces part of Disable Bloat; its matching switches are imported once.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Tidy extends SEOProStack_Feature {

    const KEY = 'admin_tidy';

    /** What to remove. */
    const ITEMS_KEY = 'admin_tidy_items';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Tidy admin screens', 'seoprostack'),
                'description' => __('Remove WordPress text and tools from wp-admin that most people never need. Applies to everyone.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
                'reload'      => true,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('footer', 'update_nag'),
                'parent'      => self::KEY,
                'label'       => __('Remove', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
                'reload'      => true,
            ),
        );
    }

    /**
     * Choices.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        return array(
            'footer'      => __('“Thank you for creating with WordPress” at the bottom of each screen', 'seoprostack'),
            'update_nag'  => __('“WordPress X is available” for people who cannot update WordPress', 'seoprostack'),
            'file_editor' => __('Theme and plugin file editors, for everyone (developers too)', 'seoprostack'),
        );
    }

    /**
     * Import Disable Bloat's matching switches.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $choices = SEOProStack_Disable_Bloat::choices(self::ITEMS_KEY);
        if ($choices) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::ITEMS_KEY, $choices);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));

        if (isset($items['file_editor'])) {
            add_filter('map_meta_cap', array(__CLASS__, 'no_file_editor'), 10, 2);
        }
        if (!is_admin()) {
            return;
        }
        if (isset($items['footer'])) {
            add_filter('admin_footer_text', '__return_empty_string', 99);
        }
        if (isset($items['update_nag'])) {
            add_action('admin_init', array(__CLASS__, 'update_nag'));
        }
    }

    /**
     * Refuse the theme and plugin file editors to everyone, as core does
     * when `DISALLOW_FILE_EDIT` is set.
     *
     * @param string[] $caps Capabilities the check needs.
     * @param string   $cap  Capability checked.
     * @return string[]
     */
    public static function no_file_editor($caps, $cap) {
        if (in_array($cap, array('edit_files', 'edit_plugins', 'edit_themes'), true)) {
            return array('do_not_allow');
        }
        return $caps;
    }

    /**
     * Remove core's update notice for people who cannot update WordPress.
     */
    public static function update_nag() {
        if (!current_user_can('update_core')) {
            remove_action('admin_notices', 'update_nag', 3);
            remove_action('network_admin_notices', 'update_nag', 3);
        }
    }
}
