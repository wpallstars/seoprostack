<?php
/**
 * Hide admin bar items.
 *
 * Removes chosen WordPress items from the admin bar (such as Comments and
 * + New), for everyone, in wp-admin and on the site. The account menu
 * (with Log Out) and the admin menu button on phones are never offered,
 * so nobody is locked out of them.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Bar_Hide extends SEOProStack_Feature {

    const KEY = 'admin_bar_hide';

    /** WordPress items to hide. */
    const ITEMS_KEY = 'admin_bar_hide_items';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: a tidier bar out of the box.
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Hide admin bar items', 'seoprostack'),
                'description' => __('Remove WordPress items you do not use from the admin bar, such as Comments and + New, for everyone. Works in wp-admin and on the site.', 'seoprostack'),
                // The bar on this page was drawn before the change.
                'reload'      => true,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('comments', 'new-content'),
                'parent'      => self::KEY,
                'label'       => __('Hide', 'seoprostack'),
                'description' => __('Some items appear only on certain screens. The account menu, with Log Out, always stays.', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
                'reload'      => true,
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // After plugins have added and changed items, before the More menu moves any.
        add_action('wp_before_admin_bar_render', array(__CLASS__, 'hide'), 99000);
    }

    /**
     * Items that can be hidden: choice => node IDs.
     *
     * @return array<string,string[]>
     */
    private static function items() {
        return array(
            'wp-logo'         => array('wp-logo'),
            'my-sites'        => array('my-sites'),
            'site-name'       => array('site-name'),
            'site-editor'     => array('site-editor'),
            'customize'       => array('customize'),
            'updates'         => array('updates'),
            'command-palette' => array('command-palette'),
            'comments'        => array('comments'),
            'new-content'     => array('new-content'),
            'edit'            => array('edit'),
            'view'            => array('view', 'preview', 'archive'),
            'get-shortlink'   => array('get-shortlink'),
            'search'          => array('search'),
        );
    }

    /**
     * Choices, in bar order.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        return array(
            'wp-logo'         => __('WordPress logo menu', 'seoprostack'),
            'my-sites'        => __('My Sites (multisite)', 'seoprostack'),
            'site-name'       => __('Site name (Visit site and Dashboard links)', 'seoprostack'),
            'site-editor'     => __('Edit site (block themes)', 'seoprostack'),
            'customize'       => __('Customise', 'seoprostack'),
            'updates'         => __('Updates', 'seoprostack'),
            'command-palette' => __('Command palette (⌘K or Ctrl+K)', 'seoprostack'),
            'comments'        => __('Comments', 'seoprostack'),
            'new-content'     => __('+ New', 'seoprostack'),
            'edit'            => __('Edit (on the site)', 'seoprostack'),
            'view'            => __('View and Preview (in wp-admin)', 'seoprostack'),
            'get-shortlink'   => __('Shortlink', 'seoprostack'),
            'search'          => __('Search (on the site)', 'seoprostack'),
        );
    }

    /**
     * Remove the chosen items.
     */
    public static function hide() {
        global $wp_admin_bar;
        if (!($wp_admin_bar instanceof WP_Admin_Bar)) {
            return;
        }
        $map = self::items();
        foreach ((array) SEOProStack_Settings::get(self::ITEMS_KEY) as $choice) {
            $choice = (string) $choice;
            if (!isset($map[$choice])) {
                continue;
            }
            foreach ($map[$choice] as $id) {
                // Their submenus are left without a parent, and WordPress does not show them.
                $wp_admin_bar->remove_node($id);
            }
        }
    }
}
