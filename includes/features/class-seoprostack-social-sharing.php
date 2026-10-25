<?php
/**
 * Social sharing.
 *
 * Share links (Facebook, X, LinkedIn, Pinterest, Reddit, WhatsApp, Email
 * and the site's own platforms) and Copy page link before, after or
 * before and after the content of chosen post types (each type may have
 * its own place) and on WooCommerce products, drawn with the Brand icons
 * set as inline SVG in round buttons that take the brand colours or a
 * Kadence palette colour, so they follow its light and dark modes. A small
 * inline style, and an inline script only for pop-up windows and copying:
 * no files, no tracking and no request to another site until someone
 * shares.
 *
 * Replaces Kadence Simple Share with the options its sites use. Its
 * settings (`kt_share`) are imported once, its per-post choice
 * (`_kt_share_control`: default, show or hide) is read and set in the
 * editor's Dev tab, and its `[kadence_simple_share]` shortcode keeps
 * working once it is deactivated.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.78
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Social_Sharing extends SEOProStack_Feature {

    const KEY = 'social_sharing';

    /** Plugins this replaces: folder => name. */
    const REPLACES = array('kadence-simple-share' => 'Kadence Simple Share');

    /** Post meta for the per-post choice, shared with Kadence Simple Share. */
    const META = '_kt_share_control';

    /** Style and script handle. */
    const HANDLE = 'seoprostack-social-sharing';

    /** Editor script handle. */
    const EDITOR = 'seoprostack-social-sharing-editor';

    /** Kadence Simple Share's shortcode. */
    const SHORTCODE = 'kadence_simple_share';

    /** Setting: the site's own platforms, one a line. */
    const CUSTOM = 'social_sharing_custom';

    /** Setting: each post type's own place (before-{type}, after-{type}). */
    const TYPE_PLACES = 'social_sharing_type_places';

    /**
     * Defaults before 1.3.83 that settings version 33 replaces with the
     * current ones where Social sharing is off (migrate()).
     */
    const OLD_DEFAULTS = array(
        'social_sharing_networks' => array('facebook', 'x', 'copy'),
        'social_sharing_types'    => array('post'),
        'social_sharing_style'    => 'filled',
        'social_sharing_size'     => 'normal',
    );

    /**
     * Your own platforms added by the save being made (settings_changed()),
     * for setting_saved() to tick under Share on.
     *
     * @var string[]
     */
    private static $added = array();

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
                'tab'         => 'content',
                'label'       => __('Social sharing', 'seoprostack'),
                'description' => __('Share buttons for Facebook, X, LinkedIn, Pinterest, Reddit, WhatsApp, Email and platforms of your own, and Copy page link, before or after (or both) the content of the post types below, and on WooCommerce products. Brand icons in round buttons, in the brand colours or a colour from the Kadence palette, so they follow its dark mode. No tracking, no files and nothing from other sites until someone shares.', 'seoprostack'),
                'replaces'    => self::REPLACES,
            ),
            'social_sharing_networks' => array(
                'type'    => 'multi',
                // In the chosen order, as Kadence Simple Share kept it.
                'ordered' => true,
                'default' => array('facebook', 'x', 'linkedin', 'whatsapp', 'reddit', 'pinterest', 'email', 'copy'),
                'parent'  => self::KEY,
                'label'   => __('Share on', 'seoprostack'),
                'options' => array(__CLASS__, 'network_options'),
            ),
            self::CUSTOM => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Your own platforms', 'seoprostack'),
                'description' => __('One a line: a name, a Brand icon (a brand’s name; leave it out to use the name’s icon) and the platform’s share address, which needs {url} where the page’s address goes and may have {title} for its title, separated by |. Reload the page after saving: each new one is ticked at the end of Share on, to move or untick there.', 'seoprostack'),
                'placeholder' => __('Name | Brand icon | https://… share address with {url} and {title}', 'seoprostack'),
                'reload'      => true,
            ),
            'social_sharing_types' => array(
                'type'    => 'multi',
                'open'    => true,
                // Questions and Businesses: SEO Pro Directory's types.
                'default' => array('post', 'spd_question', 'product', 'business'),
                'parent'  => self::KEY,
                'label'   => __('Show on', 'seoprostack'),
                'description' => __('Products show them in WooCommerce’s sharing place, under Add to cart. Each post can also show or hide them (Share buttons, in the editor’s Dev tab).', 'seoprostack'),
                'options' => array('SEOProStack_Duplicate_Posts', 'post_type_options'),
                // Place on each post type lists these.
                'reload'  => true,
            ),
            'social_sharing_place' => array(
                'type'        => 'select',
                'default'     => 'after',
                'parent'      => self::KEY,
                'label'       => __('Place', 'seoprostack'),
                'description' => __('For every post type above, unless it has its own place below.', 'seoprostack'),
                'options'     => array(__CLASS__, 'place_options'),
            ),
            self::TYPE_PLACES => array(
                'type'        => 'multi',
                // Keeps the choices of types whose plugin is not loaded.
                'open'        => true,
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Place on each post type', 'seoprostack'),
                'description' => __('Optional. Tick before, after or both for a post type to put its buttons there instead of the Place above. Lists the post types under Show on, other than products, once the page is reloaded.', 'seoprostack'),
                'options'     => array(__CLASS__, 'type_place_options'),
            ),
            'social_sharing_style' => array(
                'type'    => 'select',
                'default' => 'outline',
                'parent'  => self::KEY,
                'label'   => __('Style', 'seoprostack'),
                'options' => array(__CLASS__, 'style_options'),
            ),
            'social_sharing_size' => array(
                'type'    => 'select',
                'default' => 'large',
                'parent'  => self::KEY,
                'label'   => __('Size', 'seoprostack'),
                'options' => array(__CLASS__, 'size_options'),
            ),
            'social_sharing_colour' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Colour', 'seoprostack'),
                'description' => __('Brand colours, or one colour from the Kadence theme’s palette so the buttons follow its dark mode. Black and white logos (X), Email and Copy page link always take the text colour.', 'seoprostack'),
                'options'     => array(__CLASS__, 'colour_options'),
            ),
            'social_sharing_text' => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Text before the buttons', 'seoprostack'),
                'description' => __('Such as “Share via:”. Leave empty for none.', 'seoprostack'),
            ),
            'social_sharing_tooltip' => array(
                'type'    => 'select',
                'default' => 'top',
                'parent'  => self::KEY,
                'label'   => __('Tooltips', 'seoprostack'),
                'options' => array(__CLASS__, 'tooltip_options'),
            ),
            'social_sharing_window' => array(
                'type'    => 'select',
                'default' => 'popup',
                'parent'  => self::KEY,
                'label'   => __('Open sharing in', 'seoprostack'),
                'options' => array(__CLASS__, 'window_options'),
            ),
        );
    }

    /**
     * Networks: key => [name, Brand icons slug (its colour), share address
     * pattern, Brand icons slug of the mark drawn]. Addresses take {url}
     * and {title}, already encoded; {media} for Pinterest's picture. The
     * marks are the letter or symbol alone (Font Awesome's), as Kadence
     * Simple Share drew them, since the round button is already the
     * badge; Simple Icons' own are badges themselves. Email and Copy page
     * link have no brand icon; Copy page link's address is the page's own
     * ({link}), so it still opens the page without the script.
     *
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    private static function networks() {
        return array(
            'facebook'  => array('Facebook', 'facebook', 'https://www.facebook.com/sharer.php?u={url}', 'facebookf'),
            'x'         => array('X', 'x', 'https://twitter.com/intent/tweet?url={url}&text={title}', 'xtwitter'),
            'linkedin'  => array('LinkedIn', 'linkedin', 'https://www.linkedin.com/shareArticle?mini=true&url={url}', 'linkedinin'),
            'pinterest' => array('Pinterest', 'pinterest', 'https://pinterest.com/pin/create/bookmarklet/?is_video=false&url={url}&media={media}&description={title}', 'pinterestp'),
            'reddit'    => array('Reddit', 'reddit', 'https://reddit.com/submit?url={url}&title={title}', 'redditalien'),
            'whatsapp'  => array('WhatsApp', 'whatsapp', 'https://wa.me/?text={title}%20{url}', 'whatsapp'),
            'email'     => array(__('Email', 'seoprostack'), '', 'mailto:?subject={title}&body={url}', ''),
            'copy'      => array(__('Copy page link', 'seoprostack'), '', '{link}', ''),
        );
    }

    /**
     * The site's own platforms (Your own platforms), in the networks()
     * shape, keyed custom-{name} (custom_platform()). Repeated names are
     * skipped.
     *
     * @param mixed $lines The setting's value; null to read it.
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    private static function custom_networks($lines = null) {
        if (null === $lines) {
            $lines = self::setting(self::CUSTOM);
        }
        $networks = array();
        foreach (preg_split('/[\r\n]+/', is_scalar($lines) ? (string) $lines : '') ?: array() as $line) {
            $platform = self::custom_platform($line);
            if ($platform && !isset($networks[$platform[0]])) {
                $networks[$platform[0]] = $platform[1];
            }
        }
        return $networks;
    }

    /**
     * One line of Your own platforms: "name | Brand icon | address" or
     * "name | address" (the icon found from the name). The address must be
     * http(s) and hold {url}, so it shares the page. The brand found gives
     * the colour and the mark; without one, the button shows a share symbol
     * in the text colour.
     *
     * @param string $line Line.
     * @return array{0:string,1:array{0:string,1:string,2:string,3:string}}|null Key and network, or null.
     */
    private static function custom_platform($line) {
        $parts = array_map('trim', explode('|', (string) $line, 3));
        if (count($parts) < 2) {
            return null;
        }
        $name    = $parts[0];
        $icon    = 3 === count($parts) ? $parts[1] : '';
        $address = 3 === count($parts) ? $parts[2] : $parts[1];
        if ('' === $name || false === strpos($address, '{url}') || !preg_match('#^https?://\S+$#i', $address)) {
            return null;
        }
        $id = sanitize_key(str_replace(' ', '-', remove_accents($name)));
        // Names in other scripts (such as 微博) have no Latin key.
        $key = 'custom-' . ('' !== $id ? $id : substr(md5($name), 0, 8)); // NOSONAR: a key for a name, not security.
        return array($key, array($name, SEOProStack_Brand_Icons::find('' !== $icon ? $icon : $name), $address, ''));
    }

    /**
     * A setting as the screen being shown sets it: the network's value on
     * the network screen (multisite), else this site's.
     *
     * @param string $key Setting key.
     * @return mixed
     */
    private static function setting($key) {
        return is_network_admin() ? SEOProStack_Network_Settings::value($key) : SEOProStack_Settings::get($key);
    }

    /**
     * Network choices, in the order they show: the built-in ones, then the
     * site's own platforms.
     *
     * @return array<string,string>
     */
    public static function network_options() {
        $options = array();
        foreach (self::networks() + self::custom_networks() as $key => $network) {
            $options[$key] = $network[0];
        }
        return $options;
    }

    /**
     * Place choices.
     *
     * @return array<string,string>
     */
    public static function place_options() {
        return array(
            'before' => __('Before the content', 'seoprostack'),
            'after'  => __('After the content', 'seoprostack'),
            'both'   => __('Before and after the content', 'seoprostack'),
        );
    }

    /**
     * Place on each post type: before and after for each registered type
     * under Show on, other than products (WooCommerce's sharing place),
     * and each type with a place chosen, so it can be unticked. The field
     * is open, so a type's choices are kept while its plugin is not loaded.
     *
     * @return array<string,string> before-{type} or after-{type} => label.
     */
    public static function type_place_options() {
        $types = (array) self::setting('social_sharing_types');
        foreach ((array) self::setting(self::TYPE_PLACES) as $value) {
            $types[] = preg_replace('/^(?:before|after)-/', '', is_scalar($value) ? (string) $value : '');
        }
        $options = array();
        foreach (array_unique(array_map('strval', array_filter($types, 'is_scalar'))) as $type) {
            $object = '' !== $type && 'product' !== $type ? get_post_type_object($type) : null;
            if (!$object) {
                continue;
            }
            $name = (string) $object->labels->name;
            /* translators: %s: a post type, such as Posts. */
            $options['before-' . $type] = sprintf(__('%s: before the content', 'seoprostack'), $name);
            /* translators: %s: a post type, such as Posts. */
            $options['after-' . $type] = sprintf(__('%s: after the content', 'seoprostack'), $name);
        }
        return $options;
    }

    /**
     * Where a post type shows the buttons: its own place (Place on each
     * post type), else Place.
     *
     * @param string $type Post type.
     * @return string[] before, after or both, in that order.
     */
    public static function places($type) {
        $own = array();
        foreach (array('before', 'after') as $place) {
            if (in_array($place . '-' . $type, (array) SEOProStack_Settings::get(self::TYPE_PLACES), true)) {
                $own[] = $place;
            }
        }
        if ($own) {
            return $own;
        }
        $place = (string) SEOProStack_Settings::get('social_sharing_place');
        return 'both' === $place ? array('before', 'after') : array('before' === $place ? 'before' : 'after');
    }

    /**
     * Style choices.
     *
     * @return array<string,string>
     */
    public static function style_options() {
        return array(
            'filled'  => __('Filled circles', 'seoprostack'),
            'outline' => __('Outlined circles, filled on hover', 'seoprostack'),
        );
    }

    /**
     * Size choices.
     *
     * @return array<string,string>
     */
    public static function size_options() {
        return array(
            'normal' => __('Normal', 'seoprostack'),
            'large'  => __('Large', 'seoprostack'),
        );
    }

    /**
     * Colour choices: brand colours, or a Kadence palette colour.
     *
     * @return array<string,string>
     */
    public static function colour_options() {
        $options = SEOProStack_Post_Reactions::colour_options();
        $options[''] = __('Brand colours', 'seoprostack');
        return $options;
    }

    /**
     * Tooltip choices.
     *
     * @return array<string,string>
     */
    public static function tooltip_options() {
        return array(
            'top'    => __('Above the buttons', 'seoprostack'),
            'bottom' => __('Below the buttons', 'seoprostack'),
            ''       => __('None', 'seoprostack'),
        );
    }

    /**
     * Window choices.
     *
     * @return array<string,string>
     */
    public static function window_options() {
        return array(
            'popup' => __('A pop-up window', 'seoprostack'),
            'tab'   => __('A new tab', 'seoprostack'),
        );
    }

    /**
     * Switch on while Kadence Simple Share is active, with its settings.
     * Settings version 31 also takes its network order where 1.3.78
     * imported its networks in this feature's order (keep_order()),
     * version 32 adds Copy page link after the stored networks, and
     * version 33 gives sites where it is off the new defaults (new_defaults()).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if ((int) $from_version < 31) {
            $options = self::keep_order($options);
        }
        if ((int) $from_version < 32 && !empty($options['social_sharing_networks']) && is_array($options['social_sharing_networks']) && !in_array('copy', $options['social_sharing_networks'], true)) {
            $options['social_sharing_networks'][] = 'copy';
        }
        if ((int) $from_version > 0 && (int) $from_version < 33) {
            $options = self::new_defaults($options);
        }
        if (!isset(self::active_plugins()['kadence-simple-share'])) {
            return $options;
        }
        $options = self::import_setting($options, self::KEY, true);
        $kt      = get_option('kt_share');
        if (!is_array($kt)) {
            return $options;
        }
        foreach (self::import_values($kt) as $key => $value) {
            $options = self::import_setting($options, $key, $value);
        }
        return $options;
    }

    /**
     * Put stored networks in Kadence Simple Share's order when they are
     * the same networks as its settings (still there after it is
     * deactivated), as 1.3.78 stored them in this feature's order. A
     * different choice was made here, so it is left alone.
     *
     * @param array $options Stored settings.
     * @return array
     */
    private static function keep_order(array $options) {
        $kt = get_option('kt_share');
        if (empty($options['social_sharing_networks']) || !is_array($options['social_sharing_networks']) || !is_array($kt)) {
            return $options;
        }
        $theirs = self::import_values($kt)['social_sharing_networks'];
        $ours   = array_map('strval', $options['social_sharing_networks']);
        if (is_array($theirs) && count($theirs) === count($ours) && !array_diff($theirs, $ours)) {
            $options['social_sharing_networks'] = $theirs;
        }
        return $options;
    }

    /**
     * Where Social sharing is off and its networks, post types, style and
     * size all still hold 1.3.82's defaults (OLD_DEFAULTS; one not stored
     * has never been chosen), they take the new ones, so switching it on
     * starts with them. Sites using it, or with any of these chosen, keep
     * theirs.
     *
     * @param array $options Stored settings.
     * @return array
     */
    private static function new_defaults(array $options) {
        if (!empty($options[self::KEY])) {
            return $options;
        }
        foreach (self::OLD_DEFAULTS as $key => $old) {
            if (isset($options[$key]) && $old !== (is_array($old) ? array_values(array_map('strval', (array) $options[$key])) : (string) $options[$key])) {
                return $options;
            }
        }
        $settings = self::settings();
        foreach (array_keys(self::OLD_DEFAULTS) as $key) {
            $options[$key] = $settings[$key]['default'];
        }
        return $options;
    }

    /**
     * Kadence Simple Share's settings as this feature's.
     *
     * @param array $kt Its `kt_share` option.
     * @return array<string,mixed> Setting key => value (null: not set).
     */
    public static function import_values(array $kt) {
        $yes = static function ($key) use ($kt) {
            return isset($kt[$key]) && 1 === (int) $kt[$key];
        };

        $networks = null;
        if (isset($kt['enabled_sharing']['enabled']) && is_array($kt['enabled_sharing']['enabled'])) {
            $networks = array();
            foreach (array_keys($kt['enabled_sharing']['enabled']) as $network) {
                $network    = 'twitter' === $network ? 'x' : (string) $network;
                $networks[] = $network;
            }
            // In its order (array_intersect() keeps the first array's).
            $networks = array_values(array_unique(array_intersect($networks, array_keys(self::networks()))));
        }

        $types = array();
        foreach (array('post' => 'enable_posts', 'page' => 'enable_pages', 'product' => 'enable_products', 'portfolio' => 'enable_portfolio') as $type => $key) {
            if ($yes($key)) {
                $types[] = $type;
            }
        }

        $style   = isset($kt['sharing_style']) ? (string) $kt['sharing_style'] : '';
        $tooltip = $yes('enable_tooltip') ? (isset($kt['tooltip_align']) && 'top' === $kt['tooltip_align'] ? 'top' : 'bottom') : '';

        return array(
            'social_sharing_networks' => $networks,
            'social_sharing_types'    => $types,
            // Before and both stay; after and the fixed bars go after.
            'social_sharing_place'    => isset($kt['sharing_location']) && in_array($kt['sharing_location'], array('before', 'both'), true) ? (string) $kt['sharing_location'] : 'after',
            // Its styles 3 to 5 are outlines; 1 and 2 are filled.
            'social_sharing_style'    => in_array($style, array('style_03', 'style_04', 'style_05'), true) ? 'outline' : 'filled',
            'social_sharing_size'     => isset($kt['sharing_size']) && 'large' === $kt['sharing_size'] ? 'large' : 'normal',
            'social_sharing_text'     => isset($kt['sharing_text']) ? trim(wp_strip_all_tags((string) $kt['sharing_text'])) : null,
            'social_sharing_tooltip'  => $tooltip,
            'social_sharing_window'   => isset($kt['sharing_link_exit']) && 'popup' !== $kt['sharing_link_exit'] ? 'tab' : 'popup',
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('init', array(__CLASS__, 'register_meta'));
        // After Kadence Simple Share would have added its own.
        add_action('init', array(__CLASS__, 'register_shortcode'), 20);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_filter('the_content', array(__CLASS__, 'content'), 100);
        add_action('woocommerce_share', array(__CLASS__, 'product'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor'));
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'));
    }

    /**
     * Note the platforms a save adds to Your own platforms.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        $before      = self::custom_networks(isset($old[self::CUSTOM]) ? $old[self::CUSTOM] : '');
        $after       = self::custom_networks(isset($new[self::CUSTOM]) ? $new[self::CUSTOM] : '');
        self::$added = array_values(array_diff(array_keys($after), array_keys($before)));
    }

    /**
     * After Your own platforms is saved, once its save has finished (its
     * lock is released): tick the platforms just added, at the end of Share
     * on, and drop those taken off the list (the sanitizer keeps only
     * networks there are).
     *
     * @param string $key Setting saved.
     */
    public static function setting_saved($key) {
        if (self::CUSTOM !== $key) {
            return;
        }
        $added       = self::$added;
        self::$added = array();
        $chosen = array_values(array_map('strval', (array) SEOProStack_Settings::get('social_sharing_networks')));
        $known  = array_map('strval', array_keys(self::network_options()));
        // In the chosen order, then the new ones (array_intersect() keeps the first array's order).
        $kept = array_values(array_intersect(array_unique(array_merge($chosen, $added)), $known));
        if ($kept !== $chosen) {
            SEOProStack_Settings::set('social_sharing_networks', $kept);
        }
    }

    /**
     * The per-post choice, for the editor.
     */
    public static function register_meta() {
        register_post_meta('', self::META, array(
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'show_in_rest'      => true,
            'sanitize_callback' => array(__CLASS__, 'sanitize_control'),
            'auth_callback'     => static function ($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', (int) $post_id);
            },
        ));
    }

    /**
     * Keep only a known per-post choice.
     *
     * @param mixed $value Value.
     * @return string
     */
    public static function sanitize_control($value) {
        $value = is_scalar($value) ? (string) $value : '';
        return in_array($value, array('show', 'hide'), true) ? $value : '';
    }

    /**
     * Kadence Simple Share's shortcode, once it is gone.
     */
    public static function register_shortcode() {
        if (!shortcode_exists(self::SHORTCODE)) {
            add_shortcode(self::SHORTCODE, array(__CLASS__, 'shortcode'));
        }
    }

    /**
     * [kadence_simple_share]: the buttons for the current post.
     *
     * @return string
     */
    public static function shortcode() {
        $post = get_post();
        return $post ? self::buttons($post, 'shortcode') : '';
    }

    /**
     * Whether a post shows the buttons by itself: its own choice, else its
     * post type's.
     *
     * @param WP_Post $post Post.
     * @return bool
     */
    public static function shows(WP_Post $post) {
        $choice = (string) get_post_meta($post->ID, self::META, true);
        if ('hide' === $choice) {
            return false;
        }
        if ('show' === $choice) {
            return true;
        }
        return in_array($post->post_type, (array) SEOProStack_Settings::get('social_sharing_types'), true);
    }

    /**
     * The post on its own page, when it shows the buttons.
     *
     * @return WP_Post|null
     */
    private static function current() {
        if (!is_singular() || is_feed() || is_embed()) {
            return null;
        }
        $post = get_queried_object();
        return $post instanceof WP_Post && 'publish' === $post->post_status && '' === $post->post_password && self::shows($post) ? $post : null;
    }

    /**
     * Whether Kadence Simple Share is loaded on this request. It keeps
     * showing its own buttons and editor panel until it is deactivated, so
     * pages never get two rows of buttons and two controls never save the
     * same choice; these take over, with its imported settings, once it is
     * gone (or skipped on a request by Load plugins only where needed).
     *
     * @return bool
     */
    private static function replaced_loaded() {
        return class_exists('Kadence_Simple_Share', false);
    }

    /**
     * The style (and, for pop-ups, the script) on pages that show the
     * buttons or use the shortcode.
     */
    public static function enqueue() {
        if (self::replaced_loaded()) {
            return;
        }
        $post = self::current();
        if (!$post) {
            $post = is_singular() ? get_queried_object() : null;
            if (!$post instanceof WP_Post || !has_shortcode($post->post_content, self::SHORTCODE)) {
                return;
            }
        }
        self::assets();
    }

    /**
     * Register and add the inline style and script.
     */
    private static function assets() {
        if (!wp_style_is(self::HANDLE, 'registered')) {
            wp_register_style(self::HANDLE, false, array(), SEOPROSTACK_VERSION);
            wp_add_inline_style(self::HANDLE, self::css());
        }
        wp_enqueue_style(self::HANDLE);
        $popup = 'popup' === SEOProStack_Settings::get('social_sharing_window');
        $copy  = in_array('copy', (array) SEOProStack_Settings::get('social_sharing_networks'), true);
        if (!$popup && !$copy) {
            return;
        }
        if (!wp_script_is(self::HANDLE, 'registered')) {
            wp_register_script(self::HANDLE, false, array(), SEOPROSTACK_VERSION, true);
            // Share addresses open in a small window; Email and modified clicks go as normal.
            $js = $popup ? 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-sps-popup]");if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button){return;}var w=600,h=520,p=window.open(a.href,"sps-share","width="+w+",height="+h+",left="+Math.max(0,(screen.width-w)/2)+",top="+Math.max(0,(screen.height-h)/2));if(p){p.opener=null;e.preventDefault();}});' : '';
            if ($copy) {
                // Copy page link copies the page's address and says so for two seconds (tooltip and
                // status for screen readers); without the Clipboard API (plain http) it selects a hidden
                // copy, and as a last resort shows the address to copy. Modified clicks open the page.
                $js .= 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-sps-copy]");if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button){return;}e.preventDefault();var u=a.href,s=a.parentNode.querySelector(".sps-share__status"),said=a.getAttribute("data-sps-copied");a.spsLabel=a.spsLabel||a.getAttribute("aria-label");'
                    . 'function done(){a.setAttribute("aria-label",said);a.classList.add("sps-share--copied");if(s){s.textContent=said;}clearTimeout(a.spsTimer);a.spsTimer=setTimeout(function(){a.setAttribute("aria-label",a.spsLabel);a.classList.remove("sps-share--copied");if(s){s.textContent="";}},2000);}'
                    . 'function old(){var t=document.createElement("textarea"),ok=false;t.value=u;t.setAttribute("readonly","");t.style.position="fixed";t.style.opacity="0";document.body.appendChild(t);t.select();try{ok=document.execCommand("copy");}catch(x){}document.body.removeChild(t);if(ok){done();}else{window.prompt(a.spsLabel,u);}}'
                    . 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(u).then(done,old);}else{old();}});';
            }
            wp_add_inline_script(self::HANDLE, $js);
        }
        wp_enqueue_script(self::HANDLE);
    }

    /**
     * The buttons' CSS. Colours come from custom properties on each link:
     * --sps-share-brand (the brand, or the chosen palette colour) and the
     * text colour for black and white logos.
     *
     * @return string
     */
    private static function css() {
        $colour = (string) SEOProStack_Settings::get('social_sharing_colour');
        // Normal (32px) buttons are 12px apart, each with a 44px touch target (::before)
        // that reaches half the gap, so a finger finds one without touching the next.
        $css    = '.sps-share{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0 0 var(--global-md-spacing,1.5rem);clear:both}'
            . '.sps-share--large{gap:8px}'
            . '.sps-share--after{margin:var(--global-md-spacing,1.5rem) 0 0}'
            . '.sps-share--woocommerce{margin:1em 0 0}'
            . '.sps-share__text{margin-inline-end:4px}'
            . '.sps-share a{--sps-share-size:32px;position:relative;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:var(--sps-share-size);height:var(--sps-share-size);border-radius:50%;text-decoration:none;line-height:0;transition:background-color .2s,color .2s,border-color .2s,opacity .2s}'
            . '.sps-share a::before{content:"";position:absolute;top:-6px;right:-6px;bottom:-6px;left:-6px;border-radius:50%}'
            . '.sps-share--large a{--sps-share-size:48px}'
            . '.sps-share--large a::before{content:none}'
            . '.sps-share a svg{width:50%;height:50%;fill:currentColor}'
            // Filled: the colour behind a white logo; black and white logos and Email invert the text colour.
            . '.sps-share--filled a{background:var(--sps-share-brand);color:#fff;border:0}'
            . '.sps-share--filled a.sps-share--mono{background:currentColor;color:inherit}'
            . '.sps-share--filled a.sps-share--mono svg{fill:var(--global-palette9,#fff)}'
            . '.sps-share--filled a:hover,.sps-share--filled a:focus-visible{opacity:.85}'
            // Outline: the text colour (or the chosen one), filled with the brand colour on hover.
            // 2px: a 1px ring looks uneven on some screens, thicker at the top, bottom and sides.
            . '.sps-share--outline a{background:transparent;color:inherit;border:2px solid currentColor}'
            . '.sps-share--outline a:hover,.sps-share--outline a:focus-visible{background:var(--sps-share-brand);border-color:var(--sps-share-brand);color:#fff}'
            // Black and white logos fill with the text colour, so the logo takes palette9 as in the filled style.
            . '.sps-share--outline a.sps-share--mono:hover,.sps-share--outline a.sps-share--mono:focus-visible{background:currentColor;border-color:currentColor;color:inherit}'
            . '.sps-share--outline a.sps-share--mono:hover svg,.sps-share--outline a.sps-share--mono:focus-visible svg{fill:var(--global-palette9,#fff)}'
            . '.sps-share a:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
            // Tooltips from the link's name. "Link copied" shows even without tooltips or a pointer.
            . '.sps-share[data-tip] a::after,.sps-share a[data-sps-copy]::after{content:attr(aria-label);position:absolute;left:50%;transform:translateX(-50%);z-index:10;padding:4px 8px;border-radius:4px;background:var(--global-palette3,#1a202c);color:var(--global-palette9,#fff);font-size:12px;line-height:1.4;white-space:nowrap;pointer-events:none;opacity:0;visibility:hidden;transition:opacity .15s}'
            . '.sps-share[data-tip=top] a::after,.sps-share:not([data-tip]) a[data-sps-copy]::after{bottom:calc(100% + 6px)}'
            . '.sps-share[data-tip=bottom] a::after{top:calc(100% + 6px)}'
            . '.sps-share[data-tip] a:hover::after,.sps-share[data-tip] a:focus-visible::after,.sps-share a.sps-share--copied::after{opacity:1;visibility:visible}'
            . '.sps-share__status{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}'
            . '@media (prefers-reduced-motion:reduce){.sps-share a,.sps-share a::after{transition:none}}';
        if ('' !== $colour && array_key_exists($colour, self::colour_options())) {
            // One palette colour for every brand; the outline style uses it for the ring and logo too.
            $css .= '.sps-share a{--sps-share-brand:var(--global-' . $colour . ',currentColor)!important}'
                . '.sps-share--filled a.sps-share--mono{background:var(--sps-share-brand)}'
                . '.sps-share--filled a svg,.sps-share--filled a.sps-share--mono svg{fill:' . self::logo_on($colour) . '}'
                . '.sps-share--outline a{color:var(--sps-share-brand)}'
                . '.sps-share--outline a:hover,.sps-share--outline a:focus-visible,.sps-share--outline a.sps-share--mono:hover,.sps-share--outline a.sps-share--mono:focus-visible{background:var(--sps-share-brand);border-color:var(--sps-share-brand)}'
                . '.sps-share--outline a:hover svg,.sps-share--outline a:focus-visible svg{fill:' . self::logo_on($colour) . '!important}';
        }
        return $css;
    }

    /**
     * The logo colour on a filled button of a palette colour, so it
     * contrasts in Kadence's light and dark modes: text colours (3 to 6)
     * swap with the background, so the logo takes the background; on
     * background colours (7 to 9) it takes the strongest text; accents and
     * notices keep their colour in both modes, so the logo is white.
     *
     * @param string $colour Palette key, such as palette1.
     * @return string CSS colour.
     */
    private static function logo_on($colour) {
        $n = (int) substr($colour, 7);
        if ($n >= 3 && $n <= 6) {
            return 'var(--global-palette9,#fff)';
        }
        if ($n >= 7 && $n <= 9) {
            return 'var(--global-palette3,#1a202c)';
        }
        return '#fff';
    }

    /**
     * The buttons before, after or before and after the post's content, on
     * its own page, where its post type puts them (places()).
     *
     * @param string $content Content.
     * @return string
     */
    public static function content($content) {
        if (self::replaced_loaded() || !in_the_loop() || !is_main_query() || doing_filter('get_the_excerpt')) {
            return $content;
        }
        $post = self::current();
        // Products show them where WooCommerce puts sharing (product()).
        if (!$post || get_the_ID() !== $post->ID || 'product' === $post->post_type || has_shortcode($post->post_content, self::SHORTCODE)) {
            return $content;
        }
        foreach (self::places($post->post_type) as $place) {
            $html    = self::buttons($post, $place);
            $content = 'before' === $place ? $html . $content : $content . $html;
        }
        return $content;
    }

    /**
     * The buttons in WooCommerce's sharing place on a product page.
     */
    public static function product() {
        $post = self::replaced_loaded() ? null : self::current();
        if ($post && 'product' === $post->post_type) {
            echo self::buttons($post, 'woocommerce'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in buttons().
        }
    }

    /**
     * The share buttons for a post.
     *
     * @param WP_Post $post  Post.
     * @param string  $where before, after, woocommerce or shortcode (a class).
     * @return string
     */
    public static function buttons(WP_Post $post, $where = 'after') {
        $chosen = (array) SEOProStack_Settings::get('social_sharing_networks');
        $all    = self::networks() + self::custom_networks(SEOProStack_Settings::get(self::CUSTOM));
        $url    = (string) get_permalink($post);
        if (!$chosen || '' === $url) {
            return '';
        }
        self::assets();

        $title = html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES, 'UTF-8');
        $media = has_post_thumbnail($post) ? (string) wp_get_attachment_image_url((int) get_post_thumbnail_id($post), 'full') : '';
        $codes = array('{url}' => rawurlencode($url), '{title}' => rawurlencode($title), '{media}' => rawurlencode($media), '{link}' => $url);
        $style = 'outline' === SEOProStack_Settings::get('social_sharing_style') ? 'outline' : 'filled';
        $size  = 'large' === SEOProStack_Settings::get('social_sharing_size') ? 'large' : 'normal';
        $tip   = (string) SEOProStack_Settings::get('social_sharing_tooltip');
        $popup = 'popup' === SEOProStack_Settings::get('social_sharing_window');
        $text  = trim((string) SEOProStack_Settings::get('social_sharing_text'));

        $links  = '';
        $copies = false;
        foreach ($chosen as $key) {
            if (!isset($all[$key])) {
                continue;
            }
            list($name, $slug, $pattern, $mark) = $all[$key];
            $href = strtr($pattern, $codes);
            if ('pinterest' === $key && '' === $media) {
                $href = str_replace('&media=', '', $href);
            }
            $brand = '' !== $slug ? SEOProStack_Brand_Icons::icon($slug) : null;
            $color = $brand ? SEOProStack_Brand_Icons::brand_color($brand['hex']) : 'currentColor';
            // The mark alone, or the brand's own icon if the mark is missing.
            $icon  = '' !== $mark ? SEOProStack_Brand_Icons::icon($mark) : null;
            $icon  = $icon ? $icon : $brand;
            $mono  = 'currentColor' === $color;
            $copy  = 'copy' === $key;
            if ($copy) {
                $label = __('Copy page link', 'seoprostack');
            } elseif ('email' === $key) {
                $label = __('Share by email', 'seoprostack');
            } else {
                /* translators: %s: a social network, such as Facebook. */
                $label = sprintf(__('Share on %s', 'seoprostack'), $name);
            }
            $mail    = 0 === strpos($href, 'mailto:');
            $copies  = $copies || $copy;
            $attrs   = ' target="_blank" rel="noopener nofollow"' . ($popup ? ' data-sps-popup' : '');
            if ($mail) {
                $attrs = '';
            } elseif ($copy) {
                // The page itself, so it still opens (or can be copied from the menu) without the script.
                $attrs = ' data-sps-copy data-sps-copied="' . esc_attr__('Link copied', 'seoprostack') . '"';
            }

            // sps-no-external-icon: no External link icon after the logo.
            $links .= '<a class="sps-share__' . esc_attr($key) . ' sps-no-external-icon' . ($mono ? ' sps-share--mono' : '') . '" href="' . esc_url($href, array('http', 'https', 'mailto')) . '"'
                . ($mono ? '' : ' style="--sps-share-brand:' . esc_attr($color) . '"')
                . ' aria-label="' . esc_attr($label) . '"'
                . $attrs
                . '>' . self::svg($icon, $copy ? 'link' : ('email' === $key ? 'email' : 'share')) . '</a>';
        }
        if ('' === $links) {
            return '';
        }
        $class = 'sps-share sps-share--' . $style . ' sps-share--' . sanitize_html_class($where) . ('large' === $size ? ' sps-share--large' : '');
        return '<div class="' . esc_attr($class) . '"' . (in_array($tip, array('top', 'bottom'), true) ? ' data-tip="' . esc_attr($tip) . '"' : '') . ' role="group" aria-label="' . esc_attr__('Share', 'seoprostack') . '">'
            . ('' !== $text ? '<span class="sps-share__text">' . esc_html($text) . '</span>' : '')
            . $links
            . ($copies ? '<span class="sps-share__status" role="status"></span>' : '')
            . '</div>';
    }

    /**
     * An icon as SVG, hidden from screen readers: the link has the name.
     * Without a brand icon: an envelope (Email), a chain link (Copy page
     * link) or three joined dots (your own platforms), all drawn for this
     * plugin.
     *
     * @param array|null $icon     Icon from SEOProStack_Brand_Icons::icon().
     * @param string     $fallback email, link or share, without a brand icon.
     * @return string
     */
    private static function svg($icon, $fallback = 'email') {
        if (!$icon && 'link' === $fallback) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 7H7a5 5 0 0 0 0 10h3v-2H7a3 3 0 0 1 0-6h3zm4 0h3a5 5 0 0 1 0 10h-3v-2h3a3 3 0 0 0 0-6h-3zm-6 4h8v2H8z"/></svg>';
        }
        if (!$icon && 'share' === $fallback) {
            // Separate paths, so where the dots and lines overlap stays filled.
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/><path d="M3 12a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/><path d="M15 19a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/><path d="M6.5 12.86 18.5 5.86 17.5 4.14 5.5 11.14z"/><path d="M5.5 12.86 17.5 19.86 18.5 18.14 6.5 11.14z"/></svg>';
        }
        if (!$icon) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 5.5A1.5 1.5 0 0 1 3.5 4h17A1.5 1.5 0 0 1 22 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-17A1.5 1.5 0 0 1 2 18.5zm2.2.5L12 12.1 19.8 6zM20 7.9l-7.4 5.8a1 1 0 0 1-1.2 0L4 7.9V18h16z"/></svg>';
        }
        $svg = '<svg viewBox="' . esc_attr($icon['viewBox']) . '" aria-hidden="true" focusable="false">';
        foreach ($icon['paths'] as $d) {
            $svg .= '<path d="' . esc_attr($d) . '"/>';
        }
        return $svg . '</svg>';
    }

    /**
     * Share buttons: Default, Show or Hide, for each post in the editor.
     */
    public static function editor() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        // The meta is in the REST API only for types with custom fields.
        if (self::replaced_loaded() || !$screen || empty($screen->post_type) || !is_post_type_viewable($screen->post_type) || !post_type_supports($screen->post_type, 'custom-fields')) {
            return;
        }
        $types = (array) SEOProStack_Settings::get('social_sharing_types');
        // wp-edit-post: before WordPress 6.6, PluginPostStatusInfo is only there.
        wp_enqueue_script(self::EDITOR, SEOPROSTACK_URL . 'admin/js/seoprostack-social-sharing.js', array('wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-editor', 'wp-edit-post', 'wp-i18n'), SEOPROSTACK_VERSION, true);
        wp_localize_script(self::EDITOR, 'seoprostackSocialSharing', array(
            'meta'    => self::META,
            'label'   => __('Share buttons', 'seoprostack'),
            'options' => array(
                array('value' => '', 'label' => in_array($screen->post_type, $types, true) ? __('Default (shown)', 'seoprostack') : __('Default (hidden)', 'seoprostack')),
                array('value' => 'show', 'label' => __('Show', 'seoprostack')),
                array('value' => 'hide', 'label' => __('Hide', 'seoprostack')),
            ),
        ));
    }
}
