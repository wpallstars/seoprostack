<?php
/**
 * Tidy the login screen.
 *
 * The login screen shows the WordPress logo, linked to wordpress.org, and a
 * language menu. This links the logo to the site with the site's name, or
 * hides it, and hides the language menu. Core filters only; nothing is
 * stored.
 *
 * Replaces part of Disable Bloat; its matching switches are imported once.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Login_Screen extends SEOProStack_Feature {

    const KEY = 'login_screen';

    /** What to change. */
    const ITEMS_KEY = 'login_screen_items';

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
                'label'       => __('Tidy the login screen', 'seoprostack'),
                'description' => __('Link the logo on the login screen to your site instead of WordPress.org, or hide it, and hide the language menu.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('logo_link', 'logo_title', 'hide_logo'),
                'parent'      => self::KEY,
                'label'       => __('Change', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
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
            'logo_link'  => __('Logo links to your site', 'seoprostack'),
            'logo_title' => __('Logo is named after your site (for screen readers)', 'seoprostack'),
            'hide_logo'  => __('Hide the WordPress logo (the name stays for screen readers)', 'seoprostack'),
            'language'   => __('Hide the language menu', 'seoprostack'),
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

        if (isset($items['logo_link'])) {
            add_filter('login_headerurl', array(__CLASS__, 'logo_link'), 99);
        }
        if (isset($items['logo_title'])) {
            add_filter('login_headertext', array(__CLASS__, 'logo_title'), 99);
        }
        if (isset($items['hide_logo'])) {
            add_action('login_head', array(__CLASS__, 'hide_logo'));
        }
        if (isset($items['language'])) {
            add_filter('login_display_language_dropdown', '__return_false', 99);
        }
    }

    /**
     * Logo link: the site's home page.
     *
     * @return string
     */
    public static function logo_link() {
        return home_url('/');
    }

    /**
     * Logo text: the site's name.
     *
     * @return string
     */
    public static function logo_title() {
        return get_bloginfo('name', 'display');
    }

    /**
     * Hide the logo picture, keeping the link's text for screen readers.
     */
    public static function hide_logo() {
        echo '<style id="seoprostack-login-logo">.login h1 a{clip:rect(1px,1px,1px,1px);clip-path:inset(50%);height:1px;width:1px;margin:-1px;overflow:hidden;position:absolute;padding:0;}</style>' . "\n";
    }
}
