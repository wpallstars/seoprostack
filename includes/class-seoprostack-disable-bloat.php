<?php
/**
 * Disable Bloat: shared data for the features that replace it.
 *
 * Disable Bloat (free and PRO) stores each switch as its own `wcbloat_*`
 * option holding "yes". Tidy WooCommerce admin, Lighter WooCommerce pages,
 * Remove WordPress extras, Tidy the login screen, Tidy admin screens and
 * Simpler block editor each import their share, and Hide admin bar items
 * and Dashboard and sidebar widgets import the rest.
 *
 * Settings are imported only while Disable Bloat is active: sites that
 * deactivated it chose to stop those changes, and its options stay behind
 * after deactivation. Its options are only read.
 *
 * Disable Bloat does more than SEO Pro Stack (some of it, such as turning
 * off updates, on purpose). While a switch SEO Pro Stack does not cover is
 * on, the Plugins screen says so instead of suggesting deactivating it
 * (uncovered()).
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Disable_Bloat {

    /** Plugin folders => names, for the settings' `replaces`. */
    const PLUGINS = array(
        'disable-dashboard-for-woocommerce'     => 'Disable Bloat',
        'disable-dashboard-for-woocommerce-pro' => 'Disable Bloat PRO',
    );

    /**
     * Switches SEO Pro Stack covers: option (without `wcbloat_`) =>
     * [feature switch, list setting, choice in that list].
     */
    const MAP = array(
        // Turn off unused remote access.
        'xml_rpc_disable'                  => array('hardening', 'hardening_items', 'xmlrpc'),
        'app_passwords_disable'            => array('hardening', 'hardening_items', 'app_passwords'),
        // Tidy WooCommerce admin.
        'wc_marketplace'                   => array('woo_tidy', 'woo_tidy_items', 'suggestions'),
        'remove_addon_submenu'             => array('woo_tidy', 'woo_tidy_items', 'extensions'),
        'wc_helper_disable'                => array('woo_tidy', 'woo_tidy_items', 'connect'),
        'hide_woo_mobile_footer_text'      => array('woo_tidy', 'woo_tidy_items', 'app_email'),
        // Lighter WooCommerce pages.
        'wc_scripts_disable'               => array('woo_light', 'woo_light_items', 'scripts'),
        'wc_fragmentation_disable'         => array('woo_light', 'woo_light_items', 'fragments'),
        'wc_stripe_scripts_disable'        => array('woo_light', 'woo_light_items', 'stripe'),
        // Remove WordPress extras.
        'remove_emoji_scripts'             => array('wp_extras', 'wp_extras_items', 'emoji'),
        'wp_meta_generator_disable'        => array('wp_extras', 'wp_extras_items', 'generator'),
        'remove_feed_generator_tag'        => array('wp_extras', 'wp_extras_items', 'generator'),
        'disable_rsd_link'                 => array('wp_extras', 'wp_extras_items', 'rsd'),
        'disable_wlw_link'                 => array('wp_extras', 'wp_extras_items', 'wlw'),
        'remove_shortlink'                 => array('wp_extras', 'wp_extras_items', 'shortlink'),
        'load_comment_scripts_when_needed' => array('wp_extras', 'wp_extras_items', 'comment_reply'),
        'disable_dashicons'                => array('wp_extras', 'wp_extras_items', 'dashicons'),
        'disable_jquery_migrate'           => array('wp_extras', 'wp_extras_items', 'jquery_migrate'),
        'prevent_linking_url_comments'     => array('wp_extras', 'wp_extras_items', 'comment_links'),
        'remove_rss_links'                 => array('wp_extras', 'wp_extras_items', 'feed_links'),
        'disable_all_feeds'                => array('wp_extras', 'wp_extras_items', 'feeds'),
        // Tidy the login screen.
        'wp_logo_url_disable'              => array('login_screen', 'login_screen_items', 'logo_link'),
        'wp_logo_title'                    => array('login_screen', 'login_screen_items', 'logo_title'),
        'hide_wp_logo_on_login_page'       => array('login_screen', 'login_screen_items', 'hide_logo'),
        'wp_language_select_disable'       => array('login_screen', 'login_screen_items', 'language'),
        // Tidy admin screens.
        'wp_footer_disable'                => array('admin_tidy', 'admin_tidy_items', 'footer'),
        'wp_update_nag_disable'            => array('admin_tidy', 'admin_tidy_items', 'update_nag'),
        'file_editor_disable'              => array('admin_tidy', 'admin_tidy_items', 'file_editor'),
        // Simpler block editor.
        'autoclose_welcome_guide'          => array('editor_tidy', 'editor_tidy_items', 'welcome_guide'),
        'disable_block_directory'          => array('editor_tidy', 'editor_tidy_items', 'block_directory'),
        'disable_default_block_patterns'   => array('editor_tidy', 'editor_tidy_items', 'core_patterns'),
        'disable_template_editor'          => array('editor_tidy', 'editor_tidy_items', 'template_editor'),
        'disable_fullscreen_editor_mode'   => array('editor_tidy', 'editor_tidy_items', 'fullscreen'),
        // Hide admin bar items.
        'w_logo_disable'                   => array('admin_bar_hide', 'admin_bar_hide_items', 'wp-logo'),
        // Dashboard and sidebar widgets.
        'wc_status_meta_box_disable'       => array('hide_dashboard_widgets', 'hidden_dashboard_widgets', 'woocommerce_dashboard_status'),
        'elementor_widget_disable'         => array('hide_dashboard_widgets', 'hidden_dashboard_widgets', 'e-dashboard-overview'),
        'yoast_widget_disable'             => array('hide_dashboard_widgets', 'hidden_dashboard_widgets', 'wpseo-dashboard-overview'),
        'wc_widgets_disable'               => array('disable_sidebar_widgets', 'disabled_sidebar_widgets', 'WC_Widget_Products'),
    );

    /**
     * Register hooks.
     */
    public static function init() {
        add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'extras'), 10, 2);
    }

    /**
     * Plugins screen: what Disable Bloat does here that SEO Pro Stack does not.
     *
     * @param string[] $extras Plain names.
     * @param string   $slug   Plugin folder.
     * @return string[]
     */
    public static function extras($extras, $slug) {
        if (!isset(self::PLUGINS[$slug])) {
            return $extras;
        }
        return array_merge((array) $extras, self::uncovered());
    }

    /**
     * Whether Disable Bloat is active on this site or network-wide.
     *
     * @return bool
     */
    public static function active() {
        return (bool) array_intersect_key(self::PLUGINS, SEOProStack_Feature::active_plugins());
    }

    /**
     * Whether a switch is on in Disable Bloat.
     *
     * @param string $option Option name without `wcbloat_`.
     * @return bool
     */
    public static function on($option) {
        return 'yes' === get_option('wcbloat_' . $option);
    }

    /**
     * Choices for a list setting switched on in Disable Bloat, while it is
     * active.
     *
     * @param string $setting List setting, such as `woo_tidy_items`.
     * @return string[] Choices, in map order, without repeats.
     */
    public static function choices($setting) {
        if (!self::active()) {
            return array();
        }
        $choices = array();
        foreach (self::MAP as $option => $target) {
            if ($target[1] === $setting && self::on($option)) {
                $choices[] = $target[2];
            }
        }
        return array_values(array_unique($choices));
    }

    /**
     * A list option of Disable Bloat (such as the WordPress widgets PRO
     * removes), while it is active.
     *
     * @param string $option Option name without `wcbloat_`.
     * @return string[]
     */
    public static function list_option($option) {
        if (!self::active()) {
            return array();
        }
        $value = get_option('wcbloat_' . $option);
        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : array();
    }

    /**
     * What Disable Bloat does on this site that SEO Pro Stack, as set up,
     * does not: its switches that are on, other than those whose SEO Pro
     * Stack setting and choice are on too.
     *
     * @return string[] Plain names of the switches.
     */
    public static function uncovered() {
        $names     = self::names();
        $uncovered = array();
        // Its switches as of version 4.0; each is one autoloaded option.
        foreach (array_unique(array_merge(array_keys(self::MAP), array_keys($names))) as $option) {
            if (!self::on($option)) {
                continue;
            }
            if (isset(self::MAP[$option])) {
                list($switch, $setting, $choice) = self::MAP[$option];
                if (SEOProStack_Settings::get($switch) && in_array($choice, (array) SEOProStack_Settings::get($setting), true)) {
                    continue;
                }
            }
            $uncovered[] = isset($names[$option]) ? $names[$option] : $option;
        }
        if ('yes' === get_option('disable_admin_dashboard_setup_widget')
            && !(SEOProStack_Settings::get('hide_dashboard_widgets') && in_array('wc_admin_dashboard_setup', (array) SEOProStack_Settings::get('hidden_dashboard_widgets'), true))) {
            $uncovered[] = $names['dashboard_setup'];
        }
        if (self::list_option('wp_dashboard_widgets_disable') && !SEOProStack_Settings::get('hide_dashboard_widgets')) {
            $uncovered[] = $names['wp_dashboard_widgets_disable'];
        }
        if (self::list_option('wp_sidebar_widgets_disable') && !SEOProStack_Settings::get('disable_sidebar_widgets')) {
            $uncovered[] = $names['wp_sidebar_widgets_disable'];
        }
        return array_values(array_unique($uncovered));
    }

    /**
     * Plain names of Disable Bloat's switches, as its screens word them.
     *
     * @return array<string,string>
     */
    private static function names() {
        return array(
            'admin_disable'                  => __('Disable WooCommerce Admin', 'seoprostack'),
            'admin_disable_features'         => __('WooCommerce Admin features', 'seoprostack'),
            'marketing_disable'              => __('Disable WooCommerce Marketing Hub', 'seoprostack'),
            'hide_payment_providers'         => __('Hide payment providers', 'seoprostack'),
            'woo_merchant_email_notifications' => __('WooCommerce merchant emails', 'seoprostack'),
            'wc_blocks_backend_disable'      => __('Disable WooCommerce blocks in the editor', 'seoprostack'),
            'wc_blocks_frontend_disable'     => __('Disable WooCommerce block styles', 'seoprostack'),
            'password_meter_disable'         => __('Disable the password strength meter', 'seoprostack'),
            'remove_dns_prefetch'            => __('Remove resource hints', 'seoprostack'),
            'disable_wp_embed'               => __('Disable embeds', 'seoprostack'),
            'themes_auto_update_disable'     => __('Disable theme auto-updates', 'seoprostack'),
            'plugins_auto_update_disable'    => __('Disable plugin auto-updates', 'seoprostack'),
            'wp_core_update_disable'         => __('Disable WordPress auto-updates', 'seoprostack'),
            'post_revisions_disable'         => __('Disable post revisions', 'seoprostack'),
            'app_passwords_disable'          => __('Disable application passwords', 'seoprostack'),
            'remove_script_style_ver'        => __('Remove version numbers from scripts and styles', 'seoprostack'),
            'xml_rpc_disable'                => __('Disable XML-RPC', 'seoprostack'),
            'wp_heartbeat_disable'           => __('Heartbeat', 'seoprostack'),
            'wp_rest_api_disable'            => __('Limit the REST API', 'seoprostack'),
            'disable_gutenberg'              => __('Disable the block editor', 'seoprostack'),
            'disable_widget_block_editor'    => __('Disable the block widget editor', 'seoprostack'),
            'autosave_disable'               => __('Autosave', 'seoprostack'),
            'jetpack_installation_disable'   => __('Jetpack installation prompts', 'seoprostack'),
            'jetpack_disable'                => __('Jetpack promotions', 'seoprostack'),
            'jetpack_blaze_disable'          => __('Jetpack Blaze', 'seoprostack'),
            'wc_skyverge_disable'            => __('SkyVerge dashboard', 'seoprostack'),
            'yoast_premium_disable'          => __('Yoast Premium promotions', 'seoprostack'),
            'yoast_admin_bar_disable'        => __('Yoast admin bar menu', 'seoprostack'),
            'yoast_html_comments_disable'    => __('Yoast HTML comments', 'seoprostack'),
            'cf7_disable'                    => __('Contact Form 7 scripts', 'seoprostack'),
            'updraftplus_menubar_disable'    => __('UpdraftPlus admin bar menu', 'seoprostack'),
            'acf_hide_menu'                  => __('ACF menu', 'seoprostack'),
            'wpml_remove_meta'               => __('WPML generator tag', 'seoprostack'),
            'wpdesk_disable_dashboard_widget' => __('WP Desk Dashboard box', 'seoprostack'),
            'elementor_google_fonts'         => __('Elementor Google Fonts', 'seoprostack'),
            'flexible_shipping_remove_menu'  => __('Flexible Shipping menu', 'seoprostack'),
            'dashboard_setup'                => __('WooCommerce setup Dashboard box', 'seoprostack'),
            'wp_dashboard_widgets_disable'   => __('WordPress Dashboard boxes', 'seoprostack'),
            'wp_sidebar_widgets_disable'     => __('WordPress widgets', 'seoprostack'),
        );
    }
}
