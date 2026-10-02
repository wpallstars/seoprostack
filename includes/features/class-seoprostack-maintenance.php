<?php
/**
 * A temporary maintenance page, with revocable visitor bypass links.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Maintenance extends SEOProStack_Feature {

    const KEY = 'maintenance';
    const TOKEN = 'seoprostack_maintenance_token';
    const COOKIE = 'seoprostack_maintenance_bypass';
    const ACTION = 'seoprostack_maintenance_new_link';

    /** Declare the switch and its child settings. */
    public static function settings() {
        return array(
            self::KEY => array(
                'type' => 'bool',
                'default' => false,
                'tab' => 'maintenance',
                'label' => __('Maintenance mode', 'seoprostack'),
                'description' => __('Show visitors a temporary maintenance message. Administrators can still use the site. Clear any page cache when switching on or off.', 'seoprostack'),
                'replaces' => array('hostinger' => 'Hostinger Tools'),
                'reload' => true,
            ),
            'maintenance_message' => array(
                'type' => 'text',
                'default' => __('We are updating this site. Please check back soon.', 'seoprostack'),
                'parent' => self::KEY,
                'label' => __('Message', 'seoprostack'),
                'description' => __('Plain text shown below the site name.', 'seoprostack'),
            ),
            'maintenance_bypass' => array(
                'type' => 'bool',
                'default' => true,
                'parent' => self::KEY,
                'label' => __('Allow the bypass link', 'seoprostack'),
                'description' => __('Anyone with the link can view the site for 24 hours. New link invalidates the old link and its cookies. The link appears after switching maintenance mode on and reloading.', 'seoprostack'),
            ),
        );
    }

    /** Register metadata even when off; request handlers only when enabled. */
    public static function boot() {
        add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'extras'), 10, 2);
        if (!self::enabled()) {
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'maybe_maintenance'), -1);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'rest_maintenance'), 10, 3);
        add_action('admin_bar_menu', array(__CLASS__, 'notice'), 90);
        add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'new_link'));
    }

    /** Capability needed to view the site without a bypass link. */
    private static function allowed() {
        return current_user_can((string) apply_filters('seoprostack_maintenance_capability', 'manage_options'));
    }

    /** Whether this request is a background task rather than a visitor. */
    private static function background() {
        return (defined('WP_CLI') && WP_CLI) || wp_doing_cron() || wp_doing_ajax() || is_admin();
    }

    /** A cookie is valid only for this site's current token. */
    private static function bypassed() {
        $token = get_option(self::TOKEN, '');
        if (!SEOProStack_Settings::get('maintenance_bypass') || !is_string($token) || '' === $token) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a bearer link, not an admin action.
        $link = isset($_GET['seoprostack-bypass']) && is_string($_GET['seoprostack-bypass']) ? sanitize_text_field(wp_unslash($_GET['seoprostack-bypass'])) : '';
        if ('' !== $link && hash_equals($token, $link)) {
            $value = wp_hash($token, 'seoprostack_maintenance');
            setcookie(self::COOKIE, $value, array(
                'expires' => time() + DAY_IN_SECONDS,
                'path' => wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            // Do not leave the bearer token in links or the page's address.
            wp_safe_redirect(remove_query_arg('seoprostack-bypass'), 302);
            exit;
        }
        $cookie = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        return '' !== $cookie && hash_equals(wp_hash($token, 'seoprostack_maintenance'), $cookie);
    }

    /** Block public pages, never login, administration, robots or sitemaps. */
    public static function maybe_maintenance() {
        if (self::background() || self::allowed() || is_robots() || get_query_var('sitemap') || get_query_var('sitemap-stylesheet')) {
            return;
        }
        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(sanitize_url(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) : '';
        // Includes core, Yoast and Rank Math sitemap indexes and child maps.
        if (is_string($path) && preg_match('#/(?:[^/]*sitemap[^/]*\.(?:xml|xsl)|wp-login\.php)$#i', $path)) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        if (self::bypassed()) {
            return;
        }
        status_header(503);
        header('Retry-After: 3600');
        header('X-Robots-Tag: noindex', true);
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title><?php echo esc_html(get_bloginfo('name')); ?></title>
        </head>
        <body>
            <h1><?php echo esc_html(get_bloginfo('name')); ?></h1>
            <p><?php echo esc_html(SEOProStack_Settings::get('maintenance_message')); ?></p>
        </body>
        </html>
        <?php
        exit;
    }

    /** Leave authenticated REST requests alone, including non-admin users. */
    public static function rest_maintenance($result, $server, $request) {
        if (self::background() || is_user_logged_in()) {
            return $result;
        }
        nocache_headers();
        if (self::bypassed()) {
            return $result;
        }
        header('Retry-After: 3600');
        header('X-Robots-Tag: noindex', true);
        return new WP_Error('seoprostack_maintenance', SEOProStack_Settings::get('maintenance_message'), array('status' => 503));
    }

    /** A persistent reminder for people allowed to view the site. */
    public static function notice($bar) {
        if (self::allowed()) {
            $bar->add_node(array(
                'id' => 'seoprostack-maintenance',
                'title' => __('Maintenance mode is on', 'seoprostack'),
                'href' => self::settings_url(),
            ));
        }
    }

    /** Setting address works on front-end requests too. */
    private static function settings_url() {
        return admin_url('options-general.php?page=seoprostack&tab=maintenance');
    }

    /** Show the shareable link in the switch's child settings panel. */
    public static function panel($key) {
        if (self::KEY !== $key || !SEOProStack_Settings::can_change()) {
            return;
        }
        $token = get_option(self::TOKEN, '');
        if (!is_string($token) || '' === $token) {
            // add_option avoids overwriting a link generated by another request.
            add_option(self::TOKEN, wp_generate_password(48, false, false), '', false);
            $token = get_option(self::TOKEN, '');
        }
        if (!is_string($token) || '' === $token) {
            return;
        }
        $url = add_query_arg('seoprostack-bypass', $token, home_url('/'));
        echo '<div class="sps-panel-note"><p>' . esc_html__('Bypass link', 'seoprostack') . '</p><p><code>' . esc_html($url) . '</code></p>';
        printf('<p><a class="button" href="%1$s">%2$s</a></p></div>', esc_url(wp_nonce_url(add_query_arg('action', self::ACTION, admin_url('admin-post.php')), self::ACTION)), esc_html__('New link', 'seoprostack'));
    }

    /** Rotate only with settings permission and a valid nonce. */
    public static function new_link() {
        if (!SEOProStack_Settings::can_change()) {
            wp_die(esc_html__('You cannot change these settings.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        update_option(self::TOKEN, wp_generate_password(48, false, false), false);
        wp_safe_redirect(self::settings_url());
        exit;
    }

    /** Import only the maintenance switch, never reuse Hostinger's bearer code. */
    public static function migrate(array $options, $from_version) {
        $hostinger = get_option('hostinger_tools', array());
        if (is_array($hostinger) && array_key_exists('maintenance_mode', $hostinger)) {
            $options = self::import_setting($options, self::KEY, !empty($hostinger['maintenance_mode']));
        }
        return $options;
    }

    /** Name Hostinger's active jobs not covered by maintenance mode. */
    public static function extras($extras, $slug) {
        if ('hostinger' !== $slug) {
            return $extras;
        }
        $settings = get_option('hostinger_tools', array());
        $jobs = array(
            'disable_xml_rpc' => __('Disable XML-RPC', 'seoprostack'),
            'disable_authentication_password' => __('Disable application passwords', 'seoprostack'),
            'force_https' => __('Force HTTPS', 'seoprostack'),
            'force_www' => __('Force WWW', 'seoprostack'),
            'enable_llms_txt' => __('Generate llms.txt', 'seoprostack'),
            'optin_mcp' => __('Hostinger AI tools', 'seoprostack'),
        );
        foreach ($jobs as $key => $label) {
            if (is_array($settings) && !empty($settings[$key])) {
                $extras[] = $label;
            }
        }
        return $extras;
    }
}
