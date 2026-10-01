<?php
/**
 * Tidy the dashboard.
 *
 * Lays out the Dashboard the same way on every site, from rules in
 * admin/data/dashboard.php: which column each widget goes in and in what
 * order, which widgets nobody sees, which only developers see, and which
 * statistics only people who can publish see. People who cannot edit posts
 * see no widgets, and the Welcome panel is hidden.
 *
 * The order is applied through the per-person "meta-box-order_dashboard"
 * option that do_meta_boxes() reads, so WordPress itself moves the boxes.
 * Unless people may rearrange, saved arrangements are ignored (not deleted)
 * and boxes cannot be dragged.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Dashboard_Layout extends SEOProStack_Feature {

    const KEY = 'dashboard_layout';

    /** Let people rearrange boxes. */
    const REARRANGE_KEY = 'dashboard_layout_rearrange';

    /** Dashboard columns, in order. */
    const COLUMNS = array('normal', 'side', 'column3', 'column4');

    /**
     * Order for this request: column => comma-separated IDs, or null.
     *
     * @var array<string,string>|null
     */
    private static $order = null;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY           => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Tidy the dashboard', 'seoprostack'),
                'description' => __('The same Dashboard on every site: writing and activity first, then forms and email, then visitors, SEO and the site. Hides the Welcome panel, WordPress news and plugin promotions. Site Health shows to developers only, statistics to people who can publish, and subscribers and customers see no boxes.', 'seoprostack'),
            ),
            self::REARRANGE_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Let people rearrange boxes', 'seoprostack'),
                'description' => __('Boxes can be dragged, and each person’s own arrangement is kept. Off: everyone sees the same layout.', 'seoprostack'),
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
        // After Hide dashboard widgets (9999) and anything plugins add late.
        add_action('wp_dashboard_setup', array(__CLASS__, 'setup'), PHP_INT_MAX - 1);
    }

    /**
     * Rules, from admin/data/dashboard.php.
     *
     * @return array{columns:array<string,string[]>,hidden:string[],developers:string[],reports:string[]}
     */
    public static function rules() {
        static $rules = null;
        if (null === $rules) {
            $data = include SEOPROSTACK_DIR . 'admin/data/dashboard.php';
            /**
             * Filter how Tidy the dashboard lays out widgets.
             *
             * @param array $rules columns (column => widget IDs), hidden,
             *                     developers and reports (widget IDs).
             */
            $data  = apply_filters('seoprostack_dashboard_layout', is_array($data) ? $data : array());
            $rules = array();
            foreach (array('columns', 'hidden', 'developers', 'reports') as $key) {
                $rules[$key] = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : array();
            }
        }
        return $rules;
    }

    /**
     * Whether the current person is a developer.
     *
     * @return bool
     */
    private static function developer() {
        if (class_exists('SEOProStack_Admin_Menu') && SEOProStack_Admin_Menu::enabled()) {
            return SEOProStack_Admin_Menu::is_developer();
        }
        return is_multisite() ? is_super_admin() : current_user_can('manage_options');
    }

    /**
     * Remove widgets this person should not see and work out the order.
     */
    public static function setup() {
        global $wp_meta_boxes;

        remove_action('welcome_panel', 'wp_welcome_panel');

        $present = self::present();
        if (!$present) {
            return;
        }
        $rules  = self::rules();
        $remove = array_flip($rules['hidden']);
        if (!current_user_can('edit_posts')) {
            $remove = $present;
        }
        if (!self::developer()) {
            $remove += array_flip($rules['developers']);
        }
        if (!current_user_can('publish_posts')) {
            $remove += array_flip($rules['reports']);
        }
        foreach (array_keys(array_intersect_key($present, $remove)) as $id) {
            foreach (self::COLUMNS as $context) {
                remove_meta_box($id, 'dashboard', $context);
            }
            unset($present[$id]);
        }
        if (empty($wp_meta_boxes['dashboard']) || !$present) {
            return;
        }

        $order = array_fill_keys(self::COLUMNS, array());
        foreach ($rules['columns'] as $context => $ids) {
            if (!isset($order[$context])) {
                continue;
            }
            foreach ((array) $ids as $id) {
                if (isset($present[$id])) {
                    $order[$context][] = $id;
                    unset($present[$id]);
                }
            }
        }
        // The rest stay in their own column, below the listed ones. Listing
        // them too keeps "high" priority boxes from jumping above the order.
        foreach ($present as $id => $context) {
            $order[isset($order[$context]) ? $context : 'normal'][] = $id;
        }
        self::$order = array_map(function ($ids) {
            return implode(',', $ids);
        }, $order);

        add_filter('get_user_option_meta-box-order_dashboard', array(__CLASS__, 'order'));
        if (!SEOProStack_Settings::get(self::REARRANGE_KEY)) {
            // wp_dashboard_setup runs before the page header is printed.
            add_action('admin_head', array(__CLASS__, 'lock_style'));
            add_action('admin_print_footer_scripts', array(__CLASS__, 'lock'), 20);
        }
    }

    /**
     * Widgets registered on the Dashboard, as ID => column, in the order
     * WordPress would show them.
     *
     * @return array<string,string>
     */
    private static function present() {
        global $wp_meta_boxes;

        $present = array();
        if (empty($wp_meta_boxes['dashboard']) || !is_array($wp_meta_boxes['dashboard'])) {
            return $present;
        }
        foreach ($wp_meta_boxes['dashboard'] as $context => $priorities) {
            foreach (array('high', 'sorted', 'core', 'default', 'low') as $priority) {
                if (empty($priorities[$priority])) {
                    continue;
                }
                foreach ((array) $priorities[$priority] as $id => $box) {
                    if (is_array($box) && !isset($present[(string) $id])) {
                        $present[(string) $id] = (string) $context;
                    }
                }
            }
        }
        return $present;
    }

    /**
     * The order do_meta_boxes() applies. A saved arrangement wins only when
     * people may rearrange.
     *
     * @param mixed $saved Saved order.
     * @return mixed
     */
    public static function order($saved) {
        if (null === self::$order || (SEOProStack_Settings::get(self::REARRANGE_KEY) && is_array($saved) && $saved)) {
            return $saved;
        }
        return self::$order;
    }

    /**
     * Whether this is the Dashboard screen.
     *
     * @return bool
     */
    private static function on_dashboard() {
        return function_exists('get_current_screen') && get_current_screen() && 'dashboard' === get_current_screen()->id;
    }

    /**
     * Hide the arrow buttons and the empty drop areas, in the page head so
     * nothing moves while the page loads. Core's script marks empty columns
     * once the page is ready, and core gives them a 250px drop area, which
     * pushed the boxes below down.
     */
    public static function lock_style() {
        if (!self::on_dashboard()) {
            return;
        }
        echo '<style id="seoprostack-dashboard-layout">#dashboard-widgets .handle-order-higher,#dashboard-widgets .handle-order-lower{display:none}#dashboard-widgets .postbox .hndle{cursor:default}#dashboard-widgets .postbox-container .meta-box-sortables.empty-container{border:0;outline:0;height:0;min-height:0}#dashboard-widgets .postbox-container .meta-box-sortables.empty-container:after{content:none;display:none}</style>' . "\n";
    }

    /**
     * Stop boxes being dragged.
     */
    public static function lock() {
        if (!self::on_dashboard()) {
            return;
        }
        // Dragging starts on mousedown on a box's header (touch is turned into
        // mouse events). Stopping it on the way down works whenever core sets
        // up dragging; clicks still open and close boxes.
        echo '<script id="seoprostack-dashboard-layout-js">(function(){var w=document.getElementById("dashboard-widgets");if(w){w.addEventListener("mousedown",function(e){if(e.target.closest&&e.target.closest(".postbox-header,.hndle")&&!e.target.closest("button")){e.stopPropagation();}},true);}})();</script>' . "\n";
    }
}
