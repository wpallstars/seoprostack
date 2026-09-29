<?php
/**
 * Allstars admin screen.
 *
 * Owns the Settings → Allstars page: tab registry, page chrome and the
 * single admin script/stylesheet. Tab content is delegated to the manager
 * classes. Add tabs with the `allstars_admin_tabs` filter.
 *
 * @package Allstars
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Allstars_Admin_Manager {

    /** Menu/page slug. */
    const PAGE = 'allstars';

    /** Hook suffix returned by add_options_page(). */
    const HOOK = 'settings_page_allstars';

    /**
     * Register hooks and initialise tab managers (once).
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_filter('plugin_action_links_' . plugin_basename(ALLSTARS_FILE), array(__CLASS__, 'plugin_action_links'));

        Allstars_Theme_Manager::init();
        Allstars_Plugin_Manager::init();
    }

    /**
     * Registered tabs.
     *
     * @return array<string,array{label:string,group:string,render:callable}>
     */
    public static function get_tabs() {
        $tabs = array(
            'general' => array(
                'label'  => __('General', 'allstars'),
                'group'  => 'settings',
                'render' => array('Allstars_Settings_Manager', 'render_general_tab'),
            ),
            'workflow' => array(
                'label'  => __('Workflow', 'allstars'),
                'group'  => 'settings',
                'render' => array('Allstars_Settings_Manager', 'render_workflow_tab'),
            ),
            'advanced' => array(
                'label'  => __('Advanced', 'allstars'),
                'group'  => 'settings',
                'render' => array('Allstars_Settings_Manager', 'render_advanced_tab'),
            ),
            'theme' => array(
                'label'      => __('Theme', 'allstars'),
                'group'      => 'discover',
                'render'     => array('Allstars_Theme_Manager', 'display_tab_content'),
                'capability' => 'switch_themes',
            ),
            'recommended' => array(
                'label'      => __('Free Plugins', 'allstars'),
                'group'      => 'discover',
                'render'     => array('Allstars_Free_Plugins_Manager', 'display_tab_content'),
                // On multisite only super admins can install plugins.
                'capability' => 'install_plugins',
            ),
            'pro' => array(
                'label'  => __('Pro Plugins', 'allstars'),
                'group'  => 'discover',
                'render' => array('Allstars_Pro_Plugins_Manager', 'display_tab_content'),
            ),
            'hosting' => array(
                'label'  => __('Hosting', 'allstars'),
                'group'  => 'discover',
                'render' => array('Allstars_Hosting_Manager', 'display_tab_content'),
            ),
            'tools' => array(
                'label'  => __('Tools', 'allstars'),
                'group'  => 'discover',
                'render' => array('Allstars_Tools_Manager', 'display_tab_content'),
            ),
            'readme' => array(
                'label'  => __('Read Me', 'allstars'),
                'group'  => 'about',
                'render' => array('Allstars_Readme_Manager', 'display_tab_content'),
            ),
        );

        // Settings tabs without settings are hidden (they can be filled via the schema filter).
        foreach (array('general', 'workflow', 'advanced') as $slug) {
            if (!Allstars_Settings::fields_for_tab($slug)) {
                unset($tabs[$slug]);
            }
        }

        /**
         * Filter the admin tabs.
         *
         * @param array $tabs Tabs keyed by slug: label, group (settings|discover|about),
         *                    render callback, optional capability.
         */
        $tabs = (array) apply_filters('allstars_admin_tabs', $tabs);

        // Hide tabs the current user cannot use.
        return array_filter($tabs, function ($tab) {
            return empty($tab['capability']) || current_user_can($tab['capability']);
        });
    }

    /**
     * Active tab slug, falling back to the first registered tab.
     *
     * @return string
     */
    public static function get_active_tab() {
        $tabs = self::get_tabs();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if (!isset($tabs[$tab])) {
            $tab = (string) key($tabs);
        }
        return $tab;
    }

    /**
     * URL of a tab.
     *
     * @param string $tab  Tab slug.
     * @param array  $args Extra query args.
     * @return string
     */
    public static function tab_url($tab, array $args = array()) {
        return add_query_arg(array_merge(array('page' => self::PAGE, 'tab' => $tab), $args), admin_url('options-general.php'));
    }

    /**
     * Add the Settings link on the Plugins screen.
     *
     * @param array $links Action links.
     * @return array
     */
    public static function plugin_action_links($links) {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::tab_url('general')), esc_html__('Settings', 'allstars')));
        return $links;
    }

    /**
     * Register Settings → Allstars.
     */
    public static function register_admin_menu() {
        add_options_page(
            __('Allstars', 'allstars'),
            __('Allstars', 'allstars'),
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'render_settings_page')
        );
    }

    /**
     * Enqueue the admin stylesheet and script on our screen only.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets($hook) {
        if (self::HOOK !== $hook) {
            return;
        }

        $tab = self::get_active_tab();
        $css = file_exists(ALLSTARS_DIR . 'admin/css/allstars-admin.css') ? filemtime(ALLSTARS_DIR . 'admin/css/allstars-admin.css') : ALLSTARS_VERSION;
        $js  = file_exists(ALLSTARS_DIR . 'admin/js/allstars-admin.js') ? filemtime(ALLSTARS_DIR . 'admin/js/allstars-admin.js') : ALLSTARS_VERSION;

        wp_enqueue_style('allstars-admin', ALLSTARS_URL . 'admin/css/allstars-admin.css', array('dashicons'), $css);

        $deps = array('jquery', 'wp-a11y', 'wp-i18n');
        if (in_array($tab, array('recommended', 'theme'), true)) {
            // Core install/activate flows (same behaviour as Plugins → Add New).
            wp_enqueue_script('plugin-install');
            wp_enqueue_script('updates');
            add_thickbox();
            $deps[] = 'updates';
        }

        wp_enqueue_script('allstars-admin', ALLSTARS_URL . 'admin/js/allstars-admin.js', $deps, $js, true);
        wp_set_script_translations('allstars-admin', 'allstars');

        wp_localize_script('allstars-admin', 'allstarsAdmin', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(Allstars_Settings::NONCE),
            'tab'          => $tab,
            'colorSchemes' => Allstars_Admin_Colors::scheme_urls(),
            'i18n'         => array(
                'saving'     => __('Saving…', 'allstars'),
                'saved'      => __('Saved', 'allstars'),
                'saveFailed' => __('Could not save. Please try again.', 'allstars'),
                'loadFailed' => __('Could not load this list. Please reload the page.', 'allstars'),
                'noMatches'  => __('No matches.', 'allstars'),
                'activated'  => __('Activated', 'allstars'),
            ),
        ));
    }

    /**
     * Render the page chrome and the active tab.
     */
    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tabs   = self::get_tabs();
        $active = self::get_active_tab();
        $groups = array(
            'settings' => __('Settings', 'allstars'),
            'discover' => __('Discover', 'allstars'),
            'about'    => __('About', 'allstars'),
        );
        ?>
        <div class="wrap wpa-wrap">
            <header class="wpa-header">
                <div class="wpa-header__brand">
                    <span class="wpa-header__logo dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <h1 class="wpa-header__title"><?php esc_html_e('Allstars', 'allstars'); ?></h1>
                    <span class="wpa-badge"><?php echo esc_html('v' . ALLSTARS_VERSION); ?></span>
                </div>
                <div class="wpa-header__actions">
                    <a class="button" href="https://www.wpallstars.com/" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Visit website', 'allstars'); ?>
                        <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'allstars'); ?></span>
                    </a>
                </div>
            </header>

            <nav class="wpa-nav" aria-label="<?php esc_attr_e('Allstars sections', 'allstars'); ?>">
                <?php foreach ($groups as $group => $group_label) : ?>
                    <?php
                    $group_tabs = array_filter($tabs, function ($tab) use ($group) {
                        return isset($tab['group']) && $tab['group'] === $group;
                    });
                    if (!$group_tabs) {
                        continue;
                    }
                    ?>
                    <div class="wpa-nav__group" role="group" aria-label="<?php echo esc_attr($group_label); ?>">
                        <?php foreach ($group_tabs as $slug => $tab) : ?>
                            <a href="<?php echo esc_url(self::tab_url($slug)); ?>"
                               class="wpa-nav__tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
                               <?php echo $slug === $active ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html($tab['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </nav>

            <?php // Core moves admin notices after this marker instead of into the header. ?>
            <hr class="wp-header-end" />

            <?php settings_errors(); ?>

            <?php // Not <main>: core's #wpbody already carries role="main". ?>
            <div class="wpa-main wpa-tab-<?php echo esc_attr($active); ?>" id="wpa-tab-<?php echo esc_attr($active); ?>">
                <?php
                if (isset($tabs[$active]['render']) && is_callable($tabs[$active]['render'])) {
                    call_user_func($tabs[$active]['render']);
                }
                ?>
            </div>
        </div>
        <?php
    }
}
