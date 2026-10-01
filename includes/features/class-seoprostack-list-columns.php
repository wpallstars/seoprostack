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
 * It also tidies the columns themselves: it removes columns that few people
 * need (chosen in the settings; Admin Columns can still add them back, and
 * still lists them), puts Author and Date last on lists of posts, pages and
 * other content, and keeps Rank Math's SEO Details wide enough that its
 * lines do not wrap.
 *
 * @package SEOProStack
 * @since 0.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_List_Columns extends SEOProStack_Feature {

    const KEY = 'list_columns';

    /** Script and style handle. */
    const HANDLE = 'seoprostack-list-columns';

    /** Class the script puts on a list once fitted (also in the script). */
    const FITTED = 'seoprostack-fitted';

    /** Setting: columns to remove. */
    const REMOVE_KEY = 'list_columns_remove';

    /** Setting: Author and Date last. */
    const LAST_KEY = 'list_columns_author_date_last';

    /**
     * Before Admin Columns (199 saves the columns it offers, 200 applies a
     * layout someone chose there), so its choices win.
     */
    const PRIORITY = 190;

    /** Width of Rank Math's SEO Details column: its longest line, "Schema: Article (BlogPosting)". */
    const SEO_DETAILS_WIDTH = '16em';

    /** Columns that go last, in this order. */
    const LAST = array('author', 'date');

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
            self::REMOVE_KEY => array(
                'type'        => 'multi',
                'default'     => array_keys(self::removable_columns()),
                'parent'      => self::KEY,
                'label'       => __('Columns to remove', 'seoprostack'),
                'description' => __('Removed from every list. Admin Columns can still add them back.', 'seoprostack'),
                'options'     => array(__CLASS__, 'removable_columns'),
            ),
            self::LAST_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Author and date last', 'seoprostack'),
                'description' => __('On lists of posts, pages, media and other content, Author and Date are always the last two columns.', 'seoprostack'),
            ),
        );
    }

    /**
     * Columns that can be removed: column key => what it is.
     *
     * @return array<string,string>
     */
    public static function removable_columns() {
        return array(
            'cmplz_scan' => __('Complianz Website Scan (Complianz)', 'seoprostack'),
            'post_type'  => __('Type (Post Type Switcher)', 'seoprostack'),
            'pageviews'  => __('Pageviews (Burst Statistics)', 'seoprostack'),
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
        // Also on AJAX (Quick Edit redraws a row with the screen's columns).
        add_action('current_screen', array(__CLASS__, 'screen'));
    }

    /**
     * On list screens, tidy the columns as the list asks for them.
     *
     * @param WP_Screen $screen Current screen.
     */
    public static function screen($screen) {
        if (!($screen instanceof WP_Screen) || !in_array($screen->base, array('edit', 'upload', 'edit-tags', 'users'), true)) {
            return;
        }
        add_filter('manage_' . $screen->id . '_columns', array(__CLASS__, 'columns'), self::PRIORITY);
    }

    /**
     * Remove the chosen columns and put Author and Date last.
     *
     * @param mixed $columns Column key => heading.
     * @return mixed
     */
    public static function columns($columns) {
        if (!is_array($columns) || !$columns) {
            return $columns;
        }
        // Admin Columns reads the list's columns with this argument, to offer
        // them all in its column editor: leave them in.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reading whether the argument is there.
        if (empty($_GET['save-default-headings'])) {
            foreach ((array) SEOProStack_Settings::get(self::REMOVE_KEY) as $key) {
                unset($columns[$key]);
            }
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->base, array('edit', 'upload'), true) && SEOProStack_Settings::get(self::LAST_KEY)) {
            foreach (self::LAST as $key) {
                if (isset($columns[$key])) {
                    $heading = $columns[$key];
                    unset($columns[$key]);
                    $columns[$key] = $heading;
                }
            }
        }
        return $columns;
    }

    /**
     * The script, on screens with a list table. It does nothing elsewhere.
     *
     * It loads in the page head so that it can fit each list before the
     * browser paints it with squeezed columns. Until then the list is
     * hidden; if the script never gets to it, the list shows after two
     * seconds anyway. Without JavaScript (no `js` class on the body) the
     * list shows as WordPress lays it out.
     */
    public static function assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->base, array('post', 'dashboard', 'customize', 'site-editor', 'widgets'), true)) {
            return;
        }
        $file = 'admin/js/seoprostack-list-columns.js';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $file, array(), $ver, false);

        wp_register_style(self::HANDLE, false, array(), $ver);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(
            self::HANDLE,
            'body.js table.wp-list-table.fixed:not(.' . self::FITTED . '){visibility:hidden;animation:seoprostack-list-show 0s 2s forwards}'
            . '@keyframes seoprostack-list-show{to{visibility:visible}}'
            // Rank Math's SEO Details: each line (score, keyword, schema,
            // links) on one line, and room for the longest of them.
            . '.wp-list-table .column-rank_math_seo_details{width:' . self::SEO_DETAILS_WIDTH . '}'
            . '.wp-list-table td.column-rank_math_seo_details .rank-math-column-display{white-space:nowrap}'
        );
    }
}
