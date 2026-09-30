<?php
/**
 * SEO Pro Stack admin screen.
 *
 * Owns the Settings → SEO Pro Stack page: tab registry, page chrome and the
 * single admin script/stylesheet. Tab content is delegated to the manager
 * classes. Add tabs with the `seoprostack_admin_tabs` filter.
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Manager {

    /** Menu/page slug. */
    const PAGE = 'seoprostack';

    /** Hook suffix returned by add_options_page(). */
    const HOOK = 'settings_page_seoprostack';

    /**
     * Register hooks and initialise tab managers (once).
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_filter('plugin_action_links_' . plugin_basename(SEOPROSTACK_FILE), array(__CLASS__, 'plugin_action_links'));

        SEOProStack_Theme_Manager::init();
        SEOProStack_Plugin_Manager::init();
    }

    /**
     * Registered tabs.
     *
     * @return array<string,array{label:string,group:string,render:callable}>
     */
    public static function get_tabs() {
        $tabs = array(
            'general' => array(
                'label'  => __('General', 'seoprostack'),
                'group'  => 'settings',
                'render' => array('SEOProStack_Settings_Manager', 'render_general_tab'),
            ),
            'workflow' => array(
                'label'  => __('Workflow', 'seoprostack'),
                'group'  => 'settings',
                'render' => array('SEOProStack_Settings_Manager', 'render_workflow_tab'),
            ),
            'speed' => array(
                'label'  => __('Speed', 'seoprostack'),
                'group'  => 'settings',
                'render' => array('SEOProStack_Settings_Manager', 'render_speed_tab'),
            ),
            'advanced' => array(
                'label'  => __('Advanced', 'seoprostack'),
                'group'  => 'settings',
                'render' => array('SEOProStack_Settings_Manager', 'render_advanced_tab'),
            ),
            'theme' => array(
                'label'      => __('Theme', 'seoprostack'),
                'group'      => 'discover',
                'render'     => array('SEOProStack_Theme_Manager', 'display_tab_content'),
                'capability' => 'switch_themes',
            ),
            'recommended' => array(
                'label'      => __('Free Plugins', 'seoprostack'),
                'group'      => 'discover',
                'render'     => array('SEOProStack_Free_Plugins_Manager', 'display_tab_content'),
                // On multisite only super admins can install plugins.
                'capability' => 'install_plugins',
            ),
            'pro' => array(
                'label'  => __('Pro Plugins', 'seoprostack'),
                'group'  => 'discover',
                'render' => array('SEOProStack_Pro_Plugins_Manager', 'display_tab_content'),
            ),
            'hosting' => array(
                'label'  => __('Hosting', 'seoprostack'),
                'group'  => 'discover',
                'render' => array('SEOProStack_Hosting_Manager', 'display_tab_content'),
            ),
            'tools' => array(
                'label'  => __('Tools', 'seoprostack'),
                'group'  => 'discover',
                'render' => array('SEOProStack_Tools_Manager', 'display_tab_content'),
            ),
            'readme' => array(
                'label'  => __('Read Me', 'seoprostack'),
                'group'  => 'about',
                'render' => array('SEOProStack_Readme_Manager', 'display_tab_content'),
            ),
        );

        // Settings tabs without settings are hidden (they can be filled via the schema filter).
        foreach (array('general', 'workflow', 'speed', 'advanced') as $slug) {
            if (!SEOProStack_Settings::fields_for_tab($slug)) {
                unset($tabs[$slug]);
            }
        }

        /**
         * Filter the admin tabs.
         *
         * @param array $tabs Tabs keyed by slug: label, group (settings|discover|about),
         *                    render callback, optional capability.
         */
        $tabs = (array) apply_filters('seoprostack_admin_tabs', $tabs);

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
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::tab_url('general')), esc_html__('Settings', 'seoprostack')));
        return $links;
    }

    /**
     * Register Settings → SEO Pro Stack.
     */
    public static function register_admin_menu() {
        add_options_page(
            __('SEO Pro Stack', 'seoprostack'),
            __('SEO Pro Stack', 'seoprostack'),
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
        $css = file_exists(SEOPROSTACK_DIR . 'admin/css/seoprostack-admin.css') ? filemtime(SEOPROSTACK_DIR . 'admin/css/seoprostack-admin.css') : SEOPROSTACK_VERSION;
        $js  = file_exists(SEOPROSTACK_DIR . 'admin/js/seoprostack-admin.js') ? filemtime(SEOPROSTACK_DIR . 'admin/js/seoprostack-admin.js') : SEOPROSTACK_VERSION;

        wp_enqueue_style('seoprostack-admin', SEOPROSTACK_URL . 'admin/css/seoprostack-admin.css', array('dashicons'), $css);

        $deps = array('jquery', 'wp-a11y', 'wp-i18n');
        if (in_array($tab, array('recommended', 'theme'), true)) {
            // Core install/activate flows (same behaviour as Plugins → Add New).
            wp_enqueue_script('plugin-install');
            wp_enqueue_script('updates');
            add_thickbox();
            $deps[] = 'updates';
        }

        wp_enqueue_script('seoprostack-admin', SEOPROSTACK_URL . 'admin/js/seoprostack-admin.js', $deps, $js, true);
        wp_set_script_translations('seoprostack-admin', 'seoprostack');

        wp_localize_script('seoprostack-admin', 'seoprostackAdmin', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(SEOProStack_Settings::NONCE),
            'tab'          => $tab,
            'colorSchemes' => SEOProStack_Admin_Colors::scheme_urls(),
            'i18n'         => array(
                'saving'     => __('Saving…', 'seoprostack'),
                'saved'      => __('Saved', 'seoprostack'),
                'saveFailed' => __('Could not save. Please try again.', 'seoprostack'),
                'loadFailed' => __('Could not load this list. Please reload the page.', 'seoprostack'),
                'noMatches'  => __('No matches.', 'seoprostack'),
                'activated'  => __('Activated', 'seoprostack'),
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
            'settings' => __('Settings', 'seoprostack'),
            'discover' => __('Discover', 'seoprostack'),
            'about'    => __('About', 'seoprostack'),
        );
        ?>
        <div class="wrap sps-wrap">
            <header class="sps-header">
                <div class="sps-header__brand">
                    <span class="sps-header__logo dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <h1 class="sps-header__title"><?php esc_html_e('SEO Pro Stack', 'seoprostack'); ?></h1>
                    <span class="sps-badge"><?php echo esc_html('v' . SEOPROSTACK_VERSION); ?></span>
                </div>
                <div class="sps-header__actions">
                    <a class="button" href="https://www.wpallstars.com/" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Visit website', 'seoprostack'); ?>
                        <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span>
                    </a>
                </div>
            </header>

            <nav class="sps-nav" aria-label="<?php esc_attr_e('SEO Pro Stack sections', 'seoprostack'); ?>">
                <?php foreach ($groups as $group => $group_label) : ?>
                    <?php
                    $group_tabs = array_filter($tabs, function ($tab) use ($group) {
                        return isset($tab['group']) && $tab['group'] === $group;
                    });
                    if (!$group_tabs) {
                        continue;
                    }
                    ?>
                    <div class="sps-nav__group" role="group" aria-label="<?php echo esc_attr($group_label); ?>">
                        <?php foreach ($group_tabs as $slug => $tab) : ?>
                            <a href="<?php echo esc_url(self::tab_url($slug)); ?>"
                               class="sps-nav__tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
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
            <div class="sps-main sps-tab-<?php echo esc_attr($active); ?>" id="sps-tab-<?php echo esc_attr($active); ?>">
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
