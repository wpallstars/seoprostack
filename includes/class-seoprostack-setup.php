<?php
/**
 * What makes this plugin SEO Pro Stack: its features, settings tabs,
 * links, settings history and the helpers only it needs.
 *
 * The other files in includes/ and admin/ that the starter plugin also has
 * (the feature registry, settings store, base feature and admin screen)
 * read this class and differ from the starter's only in names. Keep
 * anything that only SEO Pro Stack needs here, in features or in its own
 * files loaded from here.
 *
 * @package SEOProStack
 * @since 0.10.2
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Setup {

    /**
     * Built-in features, in the order their cards appear within each tab.
     * Each lives in includes/features/class-{lowercase-hyphenated-name}.php.
     */
    const FEATURES = array(
        'SEOProStack_Admin_Colors',
        'SEOProStack_Admin_Page_Fade',
        'SEOProStack_Admin_Access',
        'SEOProStack_Hardening',
        'SEOProStack_Admin_Menu',
        'SEOProStack_Widget_Control',
        'SEOProStack_Dashboard_Layout',
        'SEOProStack_Admin_Tidy',
        'SEOProStack_Woo_Tidy',
        'SEOProStack_Login_Screen',
        'SEOProStack_Notification_Emails',
        'SEOProStack_Admin_Notices',
        'SEOProStack_Freemius_Quiet',
        'SEOProStack_Admin_Bar_More',
        'SEOProStack_Admin_Bar_Hide',
        'SEOProStack_List_Columns',
        'SEOProStack_Avatar_Privacy',
        'SEOProStack_Magic_Login',
        'SEOProStack_Duplicate_Posts',
        'SEOProStack_Post_Versions',
        'SEOProStack_Revisions',
        'SEOProStack_Preview_Links',
        'SEOProStack_Sticky_Posts',
        'SEOProStack_Bulk_Select_All',
        'SEOProStack_Post_Type_Switch',
        'SEOProStack_Hand_Order',
        'SEOProStack_Term_Tools',
        'SEOProStack_Menu_Visibility',
        'SEOProStack_Restrict_Content',
        'SEOProStack_Field_Search',
        'SEOProStack_Editor_Tidy',
        'SEOProStack_Translatepress_Colours',
        'SEOProStack_Auto_Upload',
        'SEOProStack_Paste_Media',
        'SEOProStack_Svg_Uploads',
        'SEOProStack_Resize_Uploads',
        'SEOProStack_Replace_Media',
        'SEOProStack_Nextgen_Images',
        'SEOProStack_Watermark_Images',
        'SEOProStack_Post_Scheduler',
        'SEOProStack_Iframe_Block',
        'SEOProStack_Screenshots',
        'SEOProStack_Link_Card_Block',
        'SEOProStack_Wikipedia_Previews',
        'SEOProStack_Word_Import',
        'SEOProStack_Post_Reactions',
        'SEOProStack_Brand_Icons',
        'SEOProStack_Spectra_Blocks',
        'SEOProStack_Short_Links',
        'SEOProStack_Remove_Cpt_Base',
        'SEOProStack_Gone_Urls',
        'SEOProStack_Old_Slugs',
        'SEOProStack_Maintenance',
        'SEOProStack_Preload_Pages',
        'SEOProStack_Delay_Scripts',
        'SEOProStack_Delayed_Analytics',
        'SEOProStack_Wp_Extras',
        'SEOProStack_Heartbeat',
        'SEOProStack_Database_Cleanup',
        'SEOProStack_Woo_Light',
        'SEOProStack_Kadence_Library',
        'SEOProStack_Plugin_Loading',
        'SEOProStack_Plugin_Toggle',
        'SEOProStack_Plugin_Sizes',
        'SEOProStack_Plugin_Presets',
        'SEOProStack_Database_Keys',
        'SEOProStack_Licence_Calls',
        'SEOProStack_Plugin_Fixes',
        'SEOProStack_Hosting_Needs',
        'SEOProStack_Plugin_References',
        'SEOProStack_Agency_Orders',
        'SEOProStack_Agency_Dashboard',
    );

    /**
     * Features that some builds leave out, loaded only when their file is
     * present. The WordPress.org build drops GitHub updates: plugins hosted
     * there may not install or update code from anywhere else.
     */
    const OPTIONAL_FEATURES = array(
        'SEOProStack_Github_Updates',
    );

    /**
     * Settings version. SEOProStack_Settings::maybe_migrate() runs
     * migrate() below and every feature's migrate() once per version.
     *
     * v1: copy pre-0.3.0 individual `wp_allstars_*` options into the array.
     * v2: the plugin was renamed from "WP Allstars" to "SEO Pro Stack"; copy the
     *     development `wp_allstars_options` array into `seoprostack_options`.
     * v3: features import settings from the plugins they replace
     *     (SEOProStack_Feature::migrate()).
     * v4: re-run feature imports for development builds at v3, after
     *     notification, duplicate and later replacement features were added.
     * v5: import The Paste settings (Paste into the Media Library) and
     *     Avatar Privacy's switch and profile pictures (Avatars without
     *     Gravatar), after 0.3.1 shipped at v4. Also Safe SVG (SVG uploads),
     *     Imsanity (Resize large uploads) and Enable Media Replace (Replace
     *     media files), CompressX (WebP and AVIF images; its resize limit
     *     goes to Resize large uploads), Easy Watermark's image
     *     watermark (Watermark pictures), Remove CPT base's post types
     *     (Short addresses for custom post types), Pretty Links' defaults
     *     for new links (Short links; its links are imported separately)
     *     and Browser Shots' switch (Website screenshots; it has no settings).
     *     Spectra block replacements switch on while Spectra is active and
     *     its blocks are in use.
     * v6: import Disable Bloat's switches, while it is active, after 0.7.0
     *     shipped at v5 (SEOProStack_Disable_Bloat): Tidy WooCommerce admin,
     *     Lighter WooCommerce pages, Remove WordPress extras, Tidy the login
     *     screen, Tidy admin screens and Simpler block editor, plus its
     *     W logo (Hide admin bar items), widgets and Dashboard boxes
     *     (Dashboard and sidebar widgets), Heartbeat (Fewer Heartbeat
     *     requests) and post revisions (Limit post revisions).
     * v7: import Hostinger Tools' and Disable Bloat's XML-RPC and application
     *     password switches (Turn off unused remote access) and Hostinger
     *     Tools' maintenance mode switch (Maintenance mode), leaving stored
     *     SEO Pro Stack choices and the other plugins' settings untouched.
     * v8: after 0.8.1 shipped at v7: switch on Menu item visibility where
     *     Nav Menu Roles has rules (its rules are read in place), Change
     *     post type and Term tools while Post Type Switcher and Term
     *     Management Tools are active, and import Simple Custom Post
     *     Order's post types, taxonomies and term order (Order by hand).
     * v9: switch on Old post addresses and Search custom fields while
     *     Slugs Manager and ACF: Better Search are active.
     * v10: switch on Link cards and Wikipedia previews while Bookmark Card
     *     and Wikipedia Preview are active.
     * v11: switch on Word documents in the editor while Mammoth .docx
     *     converter is active.
     * v12: switch on Like, save and share while Favorites is active, with
     *     the post types Favorites adds its button to.
     * v13: switch on Brand icons while Popular Brand Icons – Simple Icons is
     *     active.
     * v14: nothing since 0.10.1. In 0.10.0 it imported Lasso Lite's
     *     nofollow and sponsored defaults for new short links and switched
     *     Short links on while Lasso Lite was active with links; both only
     *     filled unset keys and are harmless now that the two run side by
     *     side, so they are left as they are. Short links no longer replaces
     *     Lasso Lite, which does more than links.
     * v15: import WP-Optimize's scheduled cleanup choices (Clean the
     *     database weekly), switched on only where LiteSpeed Cache runs on a
     *     LiteSpeed server.
     * v16: switch on Restrict content while Content Control is active, with
     *     its default message.
     */
    const DB_VERSION = 16;

    /**
     * Tab slugs renamed in 0.4.0, old => new. Settings that still use an
     * old slug (for example from the schema filter) land on the new tab,
     * and old admin links open the new tab.
     */
    const RENAMED_TABS = array(
        'general'  => 'admin',
        'workflow' => 'content',
        'advanced' => 'links',
    );

    /**
     * Links in the settings screen header; leave one out for no button.
     *
     * - website: the maker's website
     * - support: where people report problems (the plugin's GitHub issues)
     * - donate:  where people can support the maker
     *
     * @return array<string,string>
     */
    public static function header_links() {
        return array(
            'website' => 'https://www.wpallstars.com/',
            'support' => 'https://github.com/wpallstars/seoprostack/issues',
            'donate'  => 'https://buymeacoffee.com/marcusquinn',
        );
    }

    /**
     * Load SEO Pro Stack's own helpers, before the features.
     */
    public static function load() {
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-admin-bar.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-disable-bloat.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-litespeed.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-wp-optimize.php';
        // Usually loaded already by the must-use file of "Load plugins only
        // where needed"; features ask it which plugins are active.
        if (!class_exists('SEOProStack_Plugin_Loader', false)) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-plugin-loader.php';
        }
    }

    /**
     * Register the helpers' hooks, after the settings store's.
     */
    public static function init() {
        SEOProStack_Admin_Bar::init();
        SEOProStack_Disable_Bloat::init();
        SEOProStack_Litespeed::init();
        SEOProStack_WP_Optimize::init();

        // "Load plugins only where needed" may skip plugins on a request;
        // they still count as active, and saved choices that belong to them
        // stay listed.
        add_filter('seoprostack_stored_active_plugins', array('SEOProStack_Plugin_Loader', 'stored_active_plugins'));
        add_filter('seoprostack_plugins_skipped', array('SEOProStack_Plugin_Loader', 'is_filtered'));
    }

    /**
     * Imports that belong to no feature, run once per DB_VERSION before
     * the features' own: settings from the plugin's earlier names.
     *
     * Old options are left in place so a downgrade keeps working;
     * uninstall.php removes ours. Other plugins' options are never touched.
     *
     * @param array $options      Stored settings (raw, without defaults).
     * @param int   $from_version Stored settings version before this upgrade.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $renamed = get_option('wp_allstars_options', array());
        if (is_array($renamed)) {
            $options += $renamed;
        }

        $legacy_map = array(
            'wp_allstars_admin_color_scheme' => 'modern_admin_colors',
            'wp_allstars_auto_upload_images' => 'auto_upload_images',
            'wp_allstars_max_width'          => 'auto_upload_max_width',
            'wp_allstars_max_height'         => 'auto_upload_max_height',
            'wp_allstars_exclude_urls'       => 'auto_upload_exclude_domains',
            'wp_allstars_image_name_pattern' => 'auto_upload_filename_pattern',
            'wp_allstars_image_alt_pattern'  => 'auto_upload_alt_pattern',
        );

        $schema = SEOProStack_Settings::schema();
        foreach ($legacy_map as $legacy => $key) {
            $legacy_value = get_option($legacy, null);
            if (null !== $legacy_value && '' !== $legacy_value && !array_key_exists($key, $options) && isset($schema[$key])) {
                $options[$key] = SEOProStack_Settings::sanitize_value($legacy_value, $schema[$key]);
            }
        }

        return $options;
    }

    /**
     * Settings tabs, in navigation order. A tab shows only when a setting
     * uses it.
     *
     * @return array<string,array{label:string,description:string}>
     */
    public static function settings_tabs() {
        return array(
            'admin' => array(
                'label'       => __('Admin', 'seoprostack'),
                'description' => __('The dashboard, admin bar, logins and emails.', 'seoprostack'),
            ),
            'content' => array(
                'label'       => __('Content', 'seoprostack'),
                'description' => __('Writing, editing and publishing posts.', 'seoprostack'),
            ),
            'media' => array(
                'label'       => __('Media', 'seoprostack'),
                'description' => __('Images and other uploads.', 'seoprostack'),
            ),
            'links' => array(
                'label'       => __('Links', 'seoprostack'),
                'description' => __('Redirects, removed pages and short links.', 'seoprostack'),
            ),
            'speed' => array(
                'label'       => __('Speed', 'seoprostack'),
                'description' => __('Front-end loading for visitors. Logged-in users are not affected, unless you also preload pages in the admin.', 'seoprostack'),
            ),
            'plugins' => array(
                'label'       => __('Plugins', 'seoprostack'),
                'description' => __('The Plugins screen and plugin settings.', 'seoprostack'),
            ),
            'agency' => array(
                'label'       => __('Agency', 'seoprostack'),
                'description' => __('Selling services and apps: orders, client updates and a client dashboard, with Fluent Forms, Fluent Boards, Fluent Support and friends.', 'seoprostack'),
            ),
            'maintenance' => array(
                'label'       => __('Maintenance', 'seoprostack'),
                'description' => __('Updates, repairs and housekeeping.', 'seoprostack'),
            ),
        );
    }

    /**
     * Admin requests: load and start the Discover tabs, the plugin
     * installer and the Agency examples.
     */
    public static function admin() {
        $files = array(
            'admin/data/free-plugins.php',
            'admin/data/pro-plugins.php',
            'admin/data/hosting-providers.php',
            'admin/data/tools.php',
            'admin/includes/class-link-cards.php',
            'admin/includes/class-plugin-manager.php',
            'admin/includes/class-free-plugins-manager.php',
            'admin/includes/class-pro-plugins-manager.php',
            'admin/includes/class-hosting-manager.php',
            'admin/includes/class-tools-manager.php',
            'admin/includes/class-theme-manager.php',
            'admin/includes/class-agency-examples.php',
        );
        foreach ($files as $file) {
            require_once SEOPROSTACK_DIR . $file;
        }

        SEOProStack_Theme_Manager::init();
        SEOProStack_Plugin_Manager::init();
        SEOProStack_Agency_Examples::init();

        // Priority 0, so other code filtering the tabs sees these as before.
        add_filter('seoprostack_admin_tabs', array(__CLASS__, 'discover_tabs'), 0);
        add_filter('seoprostack_admin_script_deps', array(__CLASS__, 'script_deps'), 0, 2);
        add_filter('seoprostack_admin_script_data', array(__CLASS__, 'script_data'), 0, 2);
    }

    /**
     * Discover tabs: themes, plugins, hosting and tools.
     *
     * @param array $tabs Tabs keyed by slug.
     * @return array
     */
    public static function discover_tabs($tabs) {
        return array_merge((array) $tabs, array(
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
        ));
    }

    /**
     * The Free Plugins and Theme tabs install and activate with core's own
     * scripts (as Plugins → Add New does).
     *
     * @param string[] $deps Script dependencies.
     * @param string   $tab  Active tab.
     * @return string[]
     */
    public static function script_deps($deps, $tab) {
        if (in_array($tab, array('recommended', 'theme'), true)) {
            wp_enqueue_script('plugin-install');
            wp_enqueue_script('updates');
            add_thickbox();
            $deps[] = 'updates';
        }
        return $deps;
    }

    /**
     * Data for the admin script: admin colour schemes for the live switch,
     * and the plugin sizes request on the Free Plugins tab.
     *
     * @param array  $data Script data.
     * @param string $tab  Active tab.
     * @return array
     */
    public static function script_data($data, $tab) {
        $data['colorSchemes'] = SEOProStack_Admin_Colors::scheme_urls();
        $data['sizes']        = 'recommended' === $tab && class_exists('SEOProStack_Plugin_Sizes') ? array(
            'action' => SEOProStack_Plugin_Sizes::AJAX,
            'nonce'  => wp_create_nonce(SEOProStack_Plugin_Sizes::AJAX),
        ) : null;
        return $data;
    }
}
