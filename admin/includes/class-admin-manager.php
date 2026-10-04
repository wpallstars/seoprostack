<?php
/**
 * SEO Pro Stack admin screen.
 *
 * Owns the Settings → SEO Pro Stack page: tab registry, page chrome and the
 * single admin script/stylesheet. Tab content is delegated to the manager
 * classes. Settings tabs and header links come from SEOProStack_Setup; add
 * other tabs with the `seoprostack_admin_tabs` filter.
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
    const HOOK = 'settings_page_' . self::PAGE;

    /** Admin stylesheet and script, relative to the plugin folder. */
    const CSS_FILE = 'admin/css/seoprostack-admin.css';
    const JS_FILE  = 'admin/js/seoprostack-admin.js';

    /**
     * Register hooks (once).
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_filter('plugin_action_links_' . plugin_basename(SEOPROSTACK_FILE), array(__CLASS__, 'plugin_action_links'));
    }

    /** Slug of the search results screen (not shown in the navigation). */
    const SEARCH = 'search';

    /**
     * Settings tabs, in navigation order (SEOProStack_Setup::settings_tabs()).
     *
     * @return array<string,array{label:string,description:string}>
     */
    public static function settings_tabs() {
        return SEOProStack_Setup::settings_tabs();
    }

    /**
     * Registered tabs.
     *
     * @return array<string,array{label:string,group:string,render:callable}>
     */
    public static function get_tabs() {
        $tabs = array();

        // Settings tabs without settings are hidden (they can be filled via
        // the schema filter). With no settings at all, the first tab shows
        // and says so, so a new plugin still has a settings screen.
        $settings_tabs = self::settings_tabs();
        $has_settings  = false;
        foreach (array_keys($settings_tabs) as $slug) {
            if (SEOProStack_Settings::fields_for_tab($slug)) {
                $has_settings = true;
                break;
            }
        }
        foreach ($settings_tabs as $slug => $tab) {
            if ($has_settings && !SEOProStack_Settings::fields_for_tab($slug)) {
                continue;
            }
            if (!$has_settings && $tabs) {
                break;
            }
            $tabs[$slug] = array(
                'label'  => $tab['label'],
                'group'  => 'settings',
                'render' => function () use ($slug, $tab) {
                    SEOProStack_Settings_Manager::render_tab($slug, $tab['label'], $tab['description']);
                },
            );
        }

        $tabs += array(
            'readme' => array(
                'label'  => __('Read Me', 'seoprostack'),
                'group'  => 'about',
                'render' => array('SEOProStack_Readme_Manager', 'display_tab_content'),
            ),
        );

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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if (self::SEARCH === $tab && '' !== self::search_query()) {
            return $tab;
        }

        $tabs = self::get_tabs();
        $tab  = SEOProStack_Settings::resolve_tab($tab);
        if (!isset($tabs[$tab])) {
            $tab = (string) key($tabs);
        }
        return $tab;
    }

    /**
     * Current settings search text.
     *
     * @return string
     */
    public static function search_query() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
        $query = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        return trim(function_exists('mb_substr') ? mb_substr($query, 0, 100) : substr($query, 0, 100));
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
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(add_query_arg('page', self::PAGE, admin_url('options-general.php'))), esc_html__('Settings', 'seoprostack')));
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
        $css = file_exists(SEOPROSTACK_DIR . self::CSS_FILE) ? filemtime(SEOPROSTACK_DIR . self::CSS_FILE) : SEOPROSTACK_VERSION;
        $js  = file_exists(SEOPROSTACK_DIR . self::JS_FILE) ? filemtime(SEOPROSTACK_DIR . self::JS_FILE) : SEOPROSTACK_VERSION;

        wp_enqueue_style('seoprostack-admin', SEOPROSTACK_URL . self::CSS_FILE, array('dashicons'), $css);

        /**
         * Filter the admin script's dependencies; enqueue what a tab needs.
         *
         * @param string[] $deps Script handles.
         * @param string   $tab  Active tab.
         */
        $deps = (array) apply_filters('seoprostack_admin_script_deps', array('jquery', 'wp-a11y', 'wp-i18n'), $tab);

        if (self::shows_media_field($tab)) {
            wp_enqueue_media();
        }

        wp_enqueue_script('seoprostack-admin', SEOPROSTACK_URL . self::JS_FILE, $deps, $js, true);
        wp_set_script_translations('seoprostack-admin', 'seoprostack');

        /**
         * Filter the data the admin script reads (seoprostackAdmin).
         *
         * @param array  $data Script data.
         * @param string $tab  Active tab.
         */
        wp_localize_script('seoprostack-admin', 'seoprostackAdmin', (array) apply_filters('seoprostack_admin_script_data', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(SEOProStack_Settings::NONCE),
            'tab'     => $tab,
            'i18n'    => array(
                'saving'      => __('Saving…', 'seoprostack'),
                'saved'       => __('Saved', 'seoprostack'),
                'saveFailed'  => __('Could not save. Please try again.', 'seoprostack'),
                'chooseImage' => __('Choose a picture', 'seoprostack'),
                'useImage'    => __('Use this picture', 'seoprostack'),
            ),
        ), $tab));

        /**
         * Fires after the admin stylesheet and script are enqueued: enqueue
         * the plugin's own, depending on 'seoprostack-admin'.
         *
         * @param string $tab Active tab.
         */
        do_action('seoprostack_admin_enqueue', $tab);
    }

    /**
     * Whether a tab (or the search results) may show a media field, which
     * needs the media dialog.
     *
     * @param string $tab Tab slug.
     * @return bool
     */
    private static function shows_media_field($tab) {
        $schema = SEOProStack_Settings::schema();
        foreach ($schema as $field) {
            if (!isset($field['type']) || 'media' !== $field['type']) {
                continue;
            }
            if (self::SEARCH === $tab) {
                return true;
            }
            $field_tab = isset($field['tab']) ? $field['tab'] : '';
            if (!$field_tab && isset($field['parent'], $schema[$field['parent']]['tab'])) {
                $field_tab = $schema[$field['parent']]['tab'];
            }
            if ($field_tab === $tab) {
                return true;
            }
        }
        return false;
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
        $links  = SEOProStack_Setup::header_links();
        ?>
        <div class="wrap sps-wrap">
            <header class="sps-header">
                <div class="sps-header__brand">
                    <span class="sps-header__logo dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <h1 class="sps-header__title"><?php esc_html_e('SEO Pro Stack', 'seoprostack'); ?></h1>
                    <span class="sps-badge"><?php echo esc_html('v' . SEOPROSTACK_VERSION); ?></span>
                </div>
                <form class="sps-search" role="search" method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr(self::SEARCH); ?>" />
                    <label class="screen-reader-text" for="sps-search-input"><?php esc_html_e('Search features', 'seoprostack'); ?></label>
                    <span class="sps-search__field">
                        <span class="sps-search__icon dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search"
                               id="sps-search-input"
                               class="sps-search__input"
                               name="s"
                               maxlength="100"
                               value="<?php echo esc_attr(self::search_query()); ?>"
                               placeholder="<?php esc_attr_e('Search features', 'seoprostack'); ?>" />
                    </span>
                    <button type="submit" class="button sps-search__button"><?php esc_html_e('Search', 'seoprostack'); ?></button>
                </form>
                <div class="sps-header__actions">
                    <?php if (!empty($links['website'])) : ?>
                        <a class="button" href="<?php echo esc_url($links['website']); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Visit website', 'seoprostack'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($links['support'])) : ?>
                        <a class="button sps-header__support" href="<?php echo esc_url($links['support']); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-sos" aria-hidden="true"></span>
                            <?php esc_html_e('Report a problem', 'seoprostack'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($links['donate'])) : ?>
                        <a class="button sps-header__support sps-header__donate" href="<?php echo esc_url($links['donate']); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-coffee" aria-hidden="true"></span>
                            <?php esc_html_e('Buy me a coffee', 'seoprostack'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <?php self::render_nav($tabs, $active); ?>

            <?php // Core moves admin notices after this marker instead of into the header. ?>
            <hr class="wp-header-end" />

            <?php settings_errors(); ?>

            <?php // Not <main>: core's #wpbody already carries role="main". ?>
            <div class="sps-main sps-tab-<?php echo esc_attr($active); ?>" id="sps-tab-<?php echo esc_attr($active); ?>">
                <?php
                if (self::SEARCH === $active) {
                    SEOProStack_Settings_Manager::render_search(self::search_query(), $tabs);
                } elseif (isset($tabs[$active]['render']) && is_callable($tabs[$active]['render'])) {
                    call_user_func($tabs[$active]['render']);
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the tab navigation: one labelled group of links per section
     * that has tabs.
     *
     * @param array  $tabs   Tabs (get_tabs()).
     * @param string $active Active tab slug.
     */
    private static function render_nav(array $tabs, $active) {
        $groups = array(
            'settings' => __('Settings', 'seoprostack'),
            'discover' => __('Discover', 'seoprostack'),
            'about'    => __('About', 'seoprostack'),
        );
        ?>
        <nav class="sps-nav" aria-label="<?php esc_attr_e('SEO Pro Stack sections', 'seoprostack'); ?>">
            <?php
            foreach ($groups as $group => $group_label) {
                $group_tabs = array_filter($tabs, function ($tab) use ($group) {
                    return isset($tab['group']) && $tab['group'] === $group;
                });
                if ($group_tabs) {
                    self::render_nav_group($group_label, $group_tabs, $active);
                }
            }
            ?>
        </nav>
        <?php
    }

    /**
     * Render one navigation group. A group of links, not form controls, so
     * role="group" with a label rather than <fieldset>.
     *
     * @param string $label  Group label.
     * @param array  $tabs   The group's tabs.
     * @param string $active Active tab slug.
     */
    private static function render_nav_group($label, array $tabs, $active) {
        ?>
        <div class="sps-nav__group" role="group" aria-label="<?php echo esc_attr($label); ?>">
            <?php foreach ($tabs as $slug => $tab) : ?>
                <a href="<?php echo esc_url(self::tab_url($slug)); ?>"
                   class="sps-nav__tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
                   <?php echo $slug === $active ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
