<?php
/**
 * Readable list columns.
 *
 * WordPress lays out list tables (Posts, Pages, Users, Media and plugins'
 * lists) with fixed column widths, and its title column takes what the
 * other columns leave. Every plugin that adds a column with a width of its
 * own (SEO, privacy scans, page views, post type, sticky) takes from the
 * title, until it is a letter wide and the title runs down the page.
 *
 * When the main column of a list gets narrower than a fifth of the table,
 * or any column is squeezed to nothing, this gives the main column a
 * quarter and narrows the others towards the narrowest they can be without
 * breaking words; if that is not enough, the main column gives up some of
 * its quarter (down to 120 px) first.
 * Lists with room to spare are left as they are, and so are phone-sized
 * screens, where WordPress stacks the columns. It checks again when columns
 * are switched on or off in Screen Options and when the window changes size.
 *
 * @package SEOProStack
 * @since 0.5.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_List_Columns extends SEOProStack_Feature {

    const KEY = 'list_columns';

    /** Script handle. */
    const HANDLE = 'seoprostack-list-columns';

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
                'label'       => __('Readable list columns', 'seoprostack'),
                'description' => __('Keep the title column of post, page, user and other lists wide enough to read when plugins add columns of their own. The other columns get narrower instead.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /**
     * The script, on screens with a list table. It does nothing elsewhere.
     */
    public static function assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->base, array('post', 'dashboard', 'customize', 'site-editor', 'widgets'), true)) {
            return;
        }
        $file = 'admin/js/seoprostack-list-columns.js';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $file, array(), $ver, true);
    }
}
