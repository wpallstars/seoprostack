<?php
/**
 * Remove WordPress extras.
 *
 * Leaves out things WordPress adds to every page that most sites never use:
 * the emoji script, tags for old blogging apps, the version number, and
 * optionally Dashicons, jQuery Migrate, feed links or feeds themselves,
 * embed links and the password strength meter.
 * Each is removed with core's own hooks; nothing is stored.
 *
 * Replaces part of Disable Bloat; its matching switches are imported once.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Wp_Extras extends SEOProStack_Feature {

    const KEY = 'wp_extras';

    /** What to remove. */
    const ITEMS_KEY = 'wp_extras_items';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Remove WordPress extras', 'seoprostack'),
                'description' => __('Leave out scripts and tags WordPress adds to every page that most sites never use, such as the emoji script. Applies to everyone.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('emoji', 'generator', 'rsd', 'wlw', 'shortlink', 'comment_reply'),
                'parent'      => self::KEY,
                'label'       => __('Remove', 'seoprostack'),
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
            'emoji'          => __('Emoji script and styles, which load pictures from WordPress.org (browsers show emoji themselves)', 'seoprostack'),
            'generator'      => __('WordPress version in the page head and feeds', 'seoprostack'),
            'rsd'            => __('RSD link, for old blogging apps', 'seoprostack'),
            'wlw'            => __('Windows Live Writer link (WordPress before 6.3)', 'seoprostack'),
            'shortlink'      => __('Shortlink tag and header (?p=123 addresses)', 'seoprostack'),
            'comment_reply'  => __('Comment reply script on pages without comments to reply to', 'seoprostack'),
            'dashicons'      => __('Dashicons icon font, for visitors who do not see the admin bar', 'seoprostack'),
            'jquery_migrate' => __('jQuery Migrate on the site (old plugin and theme code may need it)', 'seoprostack'),
            'comment_links'  => __('Links made from web addresses typed in comments', 'seoprostack'),
            'feed_links'     => __('Feed links in the page head', 'seoprostack'),
            'feeds'          => __('Feeds: send them to the home page', 'seoprostack'),
            'embeds'         => __('Embed links in the page head, which let other sites show your posts as cards (your embeds of other sites still work)', 'seoprostack'),
            'password_meter' => __('Password strength meter on the site, except where people set a password (My account, checkout, lost password)', 'seoprostack'),
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

        if (isset($items['emoji'])) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('embed_head', 'print_emoji_detection_script');
            remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
            remove_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles');
            remove_action('enqueue_embed_scripts', 'wp_enqueue_emoji_styles');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            // Feeds and emails turn emoji into pictures from WordPress.org.
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        }
        if (isset($items['generator'])) {
            remove_action('wp_head', 'wp_generator');
            // Feeds, and anything else that asks for the generator tag.
            add_filter('the_generator', '__return_empty_string', 99);
        }
        if (isset($items['rsd'])) {
            remove_action('wp_head', 'rsd_link');
        }
        if (isset($items['wlw'])) {
            remove_action('wp_head', 'wlwmanifest_link');
        }
        if (isset($items['shortlink'])) {
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }
        if (isset($items['comment_links'])) {
            remove_filter('comment_text', 'make_clickable', 9);
        }
        if (isset($items['feed_links']) || isset($items['feeds'])) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }
        if (isset($items['feeds'])) {
            add_action('template_redirect', array(__CLASS__, 'redirect_feed'), 1);
        }
        if (isset($items['embeds'])) {
            // Only the links: the oEmbed route also serves the editor's
            // previews of other sites, and core loads wp-embed only on
            // pages that embed another WordPress site.
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
        }

        if (is_admin()) {
            return;
        }
        if (isset($items['password_meter'])) {
            add_action('wp_print_scripts', array(__CLASS__, 'password_meter'), 100);
        }
        if (isset($items['comment_reply'])) {
            // Themes enqueue it in wp_enqueue_scripts or while printing comments.
            add_action('wp_enqueue_scripts', array(__CLASS__, 'comment_reply'), 999);
            add_action('wp_footer', array(__CLASS__, 'comment_reply'), 1);
        }
        if (isset($items['dashicons'])) {
            add_action('wp_enqueue_scripts', array(__CLASS__, 'dashicons'), 999);
            add_action('wp_footer', array(__CLASS__, 'dashicons'), 1);
        }
        if (isset($items['jquery_migrate'])) {
            add_action('wp_enqueue_scripts', array(__CLASS__, 'jquery_migrate'), 999);
        }
    }

    /**
     * Send feed requests to the home page.
     */
    public static function redirect_feed() {
        if (is_feed()) {
            wp_safe_redirect(home_url('/'), 301);
            exit;
        }
    }

    /**
     * Dequeue the comment reply script where nobody can reply to a comment.
     */
    public static function comment_reply() {
        if (is_singular() && comments_open() && get_option('thread_comments') && get_comments_number() > 0) {
            return;
        }
        wp_dequeue_script('comment-reply');
    }

    /**
     * Dequeue Dashicons for visitors without the admin bar. A style that
     * needs it still loads it.
     */
    public static function dashicons() {
        if (!is_admin_bar_showing() && !is_customize_preview()) {
            wp_dequeue_style('dashicons');
        }
    }

    /**
     * Dequeue the password strength meter (it loads a large word list)
     * where nobody sets a password. A script that needs it still loads it.
     */
    public static function password_meter() {
        $setting = isset($_GET['action']) && in_array($_GET['action'], array('lostpassword', 'rp', 'resetpass', 'register'), true); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared.
        if ($setting || (function_exists('is_account_page') && is_account_page()) || (function_exists('is_checkout') && is_checkout())) {
            return;
        }
        foreach (array('wc-password-strength-meter', 'password-strength-meter', 'zxcvbn-async') as $handle) {
            wp_dequeue_script($handle);
        }
    }

    /**
     * Load jQuery without jQuery Migrate on the site.
     */
    public static function jquery_migrate() {
        $scripts = wp_scripts();
        if (isset($scripts->registered['jquery']) && is_array($scripts->registered['jquery']->deps)) {
            $scripts->registered['jquery']->deps = array_values(array_diff($scripts->registered['jquery']->deps, array('jquery-migrate')));
        }
    }
}
