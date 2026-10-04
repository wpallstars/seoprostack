<?php
/**
 * Magic login links.
 *
 * Adds "Email me a login link" to wp-login.php. The link signs the user in
 * once, within a few minutes. Passwords keep working.
 *
 * Design (kept to core APIs so nothing else in the admin is touched):
 * - Only runs on wp-login.php: the `lost_password_html_link` filter adds the
 *   link and `login_form_{action}` renders our screens with core's
 *   login_header()/login_footer().
 * - Tokens are random, stored only as an HMAC in user meta, single use,
 *   short lived, and one per user (a new request replaces the old link).
 * - Opening the emailed link shows a confirm button; the login happens on
 *   POST so mail scanners that prefetch links cannot use them up.
 * - Requests always get the same response whether or not the account exists,
 *   and are rate limited per IP address and per user.
 * - Login uses wp_set_auth_cookie() and fires `wp_login` and `login_redirect`
 *   like core, so two-factor, audit and redirect plugins keep working.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Magic_Login extends SEOProStack_Feature {

    const KEY = 'magic_login';

    /** wp-login.php action. */
    const ACTION = 'seoprostack_magic_link';

    /** User meta holding the pending token hash. */
    const META = '_seoprostack_magic_login';

    /** Requests allowed per IP address per window. */
    const IP_LIMIT = 5;

    /** Rate limit window in seconds. */
    const WINDOW = 900;

    /** Minimum seconds between emails to the same user. */
    const USER_COOLDOWN = 60;

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
                'label'       => __('Magic login links', 'seoprostack'),
                'description' => __('Adds “Email me a login link” to the login screen. The link works once and expires after a few minutes. Passwords keep working.', 'seoprostack'),
                // pixolette's CodeCanyon plugin; listed so the Plugins screen finds it on sites that have it.
                'replaces'    => array('wp-magic-link-login' => 'WP Magic Link Login'),
            ),
            'magic_login_expiry' => array(
                'type'        => 'int',
                'default'     => 10,
                'min'         => 5,
                'max'         => 60,
                'unit'        => __('minutes', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Link expires after', 'seoprostack'),
                'description' => __('Between 5 and 60 minutes.', 'seoprostack'),
            ),
            'magic_login_users' => array(
                'type'        => 'select',
                'default'     => 'all',
                'parent'      => self::KEY,
                'label'       => __('Who can use it', 'seoprostack'),
                'description' => __('Administrators can be required to use their password.', 'seoprostack'),
                'options'     => array(
                    'all'       => __('Everyone', 'seoprostack'),
                    'no_admins' => __('Everyone except administrators', 'seoprostack'),
                ),
            ),
            'magic_login_remember' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Remember me', 'seoprostack'),
                'description' => __('Keep people signed in for 14 days, like ticking “Remember Me”.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks when enabled.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('lost_password_html_link', array(__CLASS__, 'add_login_link'));
        add_action('login_form_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    /**
     * Append the magic link option to the login screen's links.
     *
     * @param string $html Lost password link HTML.
     * @return string
     */
    public static function add_login_link($html) {
        $url = self::url();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- passing through a redirect target only.
        if (!empty($_REQUEST['redirect_to'])) {
            $url = add_query_arg('redirect_to', rawurlencode(self::redirect_from_request()), $url);
        }
        return $html . ' <span class="seoprostack-magic-sep" aria-hidden="true">|</span> ' . sprintf(
            '<a class="seoprostack-magic-link" href="%s">%s</a>',
            esc_url($url),
            esc_html__('Email me a login link', 'seoprostack')
        );
    }

    /**
     * wp-login.php?action=seoprostack_magic_link
     */
    public static function handle() {
        if (is_user_logged_in()) {
            wp_safe_redirect(admin_url());
            exit;
        }

        nocache_headers();
        header('Referrer-Policy: no-referrer'); // Keep tokens out of Referer headers.

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        // phpcs:disable WordPress.Security.NonceVerification -- nonces are checked in the POST handlers below.
        $has_token = isset($_REQUEST['uid'], $_REQUEST['token']);
        // phpcs:enable

        if ($has_token) {
            if ('POST' === $method) {
                self::handle_confirm();
            } else {
                self::render_confirm();
            }
        } elseif ('POST' === $method) {
            self::handle_request();
        } else {
            self::render_request_form();
        }
        exit;
    }

    /* --------------------------------------------------------------------- */
    /* Request a link                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Render the "email me a link" form.
     *
     * @param WP_Error|null $errors  Errors to show.
     * @param string        $message Notice HTML.
     */
    private static function render_request_form($errors = null, $message = '') {
        $redirect = self::redirect_from_request();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- refilling the field after a failed submit.
        $login = isset($_POST['user_login']) ? sanitize_text_field(wp_unslash($_POST['user_login'])) : '';

        if (!$message) {
            $message = '<p class="message">' . esc_html__('Enter your username or email address and we will email you a link to log in.', 'seoprostack') . '</p>';
        }

        login_header(__('Email me a login link', 'seoprostack'), $message, $errors);
        ?>
        <form name="seoprostackmagicform" id="seoprostackmagicform" action="<?php echo esc_url(self::url()); ?>" method="post">
            <p>
                <label for="user_login"><?php esc_html_e('Username or Email Address', 'seoprostack'); ?></label>
                <input type="text" name="user_login" id="user_login" class="input" value="<?php echo esc_attr($login); ?>" size="20" autocapitalize="off" autocomplete="username" required="required" />
            </p>
            <?php wp_nonce_field(self::ACTION . '_request', '_seoprostack_nonce'); ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>" />
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Email me a login link', 'seoprostack'); ?>" />
            </p>
        </form>
        <p id="nav">
            <a href="<?php echo esc_url(wp_login_url($redirect)); ?>"><?php esc_html_e('Log in with a password', 'seoprostack'); ?></a>
        </p>
        <?php
        login_footer('user_login');
    }

    /**
     * Handle the request form: always respond the same way.
     */
    private static function handle_request() {
        $nonce = isset($_POST['_seoprostack_nonce']) ? sanitize_text_field(wp_unslash($_POST['_seoprostack_nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::ACTION . '_request')) {
            self::render_request_form(new WP_Error('seoprostack_nonce', __('The form expired. Please try again.', 'seoprostack')));
            return;
        }

        $login = isset($_POST['user_login']) ? sanitize_text_field(wp_unslash($_POST['user_login'])) : '';
        if ('' === trim($login)) {
            self::render_request_form(new WP_Error('seoprostack_empty', __('Please enter a username or email address.', 'seoprostack')));
            return;
        }

        if (!self::within_ip_limit()) {
            self::render_request_form(new WP_Error('seoprostack_limit', __('Too many requests. Please wait a few minutes and try again.', 'seoprostack')));
            return;
        }

        $user = is_email($login) ? get_user_by('email', $login) : get_user_by('login', $login);
        if ($user instanceof WP_User && self::user_allowed($user)) {
            self::send_link($user, self::redirect_from_request());
        }

        $message = '<p class="message">' . esc_html__('If that account can use login links, we have emailed one to its address. Check your inbox and spam folder.', 'seoprostack') . '</p>';
        login_header(__('Check your email', 'seoprostack'), $message);
        ?>
        <p id="nav"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to log in', 'seoprostack'); ?></a></p>
        <?php
        login_footer();
    }

    /**
     * Create a token and email the link.
     *
     * @param WP_User $user     User.
     * @param string  $redirect Validated redirect target.
     * @return bool Whether an email was sent.
     */
    private static function send_link(WP_User $user, $redirect) {
        $pending = get_user_meta($user->ID, self::META, true);
        if (is_array($pending) && !empty($pending['created']) && time() - (int) $pending['created'] < self::USER_COOLDOWN) {
            return false; // One email per minute per user.
        }

        $token   = wp_generate_password(43, false, false);
        $minutes = (int) SEOProStack_Settings::get('magic_login_expiry');
        update_user_meta($user->ID, self::META, array(
            'hash'     => self::hash($token, $user->ID),
            'created'  => time(),
            'expires'  => time() + $minutes * MINUTE_IN_SECONDS,
            'redirect' => $redirect,
        ));

        $link = add_query_arg(array('uid' => $user->ID, 'token' => $token), self::url());
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Your login link', 'seoprostack'), $site);
        $body    = sprintf(
            /* translators: 1: site name, 2: login link, 3: minutes until expiry */
            __("Someone asked to log in to %1\$s with this email address.\n\nTo log in, open this link and press “Log in”:\n%2\$s\n\nThe link works once and expires in %3\$d minutes.\n\nIf this wasn't you, ignore this email. Your account is safe and your password has not changed.", 'seoprostack'),
            $site,
            $link,
            $minutes
        );

        /**
         * Filter the magic login email.
         *
         * @param array   $email { to, subject, message, headers }.
         * @param WP_User $user  Recipient.
         * @param string  $link  Login link.
         */
        $email = apply_filters('seoprostack_magic_login_email', array(
            'to'      => $user->user_email,
            'subject' => $subject,
            'message' => $body,
            'headers' => array(),
        ), $user, $link);

        $sent = wp_mail($email['to'], $email['subject'], $email['message'], $email['headers']);

        /**
         * Fires after a magic login link is emailed.
         *
         * @param WP_User $user User.
         * @param bool    $sent Whether wp_mail() reported success.
         */
        do_action('seoprostack_magic_login_link_sent', $user, $sent);

        return (bool) $sent;
    }

    /* --------------------------------------------------------------------- */
    /* Use a link                                                             */
    /* --------------------------------------------------------------------- */

    /**
     * Show the confirm button for an emailed link.
     */
    private static function render_confirm() {
        list($user, $token) = self::link_from_request();
        $error              = self::verify($user, $token);
        if (is_wp_error($error)) {
            self::render_link_error($error);
            return;
        }

        /* translators: %s: site name */
        $message = '<p class="message">' . esc_html(sprintf(__('Press the button to log in to %s.', 'seoprostack'), get_bloginfo('name'))) . '</p>';
        login_header(__('Log in', 'seoprostack'), $message);
        ?>
        <form name="seoprostackmagicconfirm" id="seoprostackmagicconfirm" action="<?php echo esc_url(self::url()); ?>" method="post">
            <input type="hidden" name="uid" value="<?php echo esc_attr((string) $user->ID); ?>" />
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>" />
            <?php wp_nonce_field(self::ACTION . '_confirm', '_seoprostack_nonce'); ?>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Log in', 'seoprostack'); ?>" />
            </p>
        </form>
        <?php
        login_footer();
    }

    /**
     * Log the user in after the confirm button is pressed.
     */
    private static function handle_confirm() {
        $nonce = isset($_POST['_seoprostack_nonce']) ? sanitize_text_field(wp_unslash($_POST['_seoprostack_nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::ACTION . '_confirm')) {
            self::render_link_error(new WP_Error('seoprostack_nonce', __('The page expired. Please open the link from your email again.', 'seoprostack')));
            return;
        }
        if (!self::within_ip_limit()) {
            self::render_link_error(new WP_Error('seoprostack_limit', __('Too many attempts. Please wait a few minutes and try again.', 'seoprostack')));
            return;
        }

        list($user, $token) = self::link_from_request();
        $error              = self::verify($user, $token);
        if (is_wp_error($error)) {
            self::render_link_error($error);
            return;
        }

        $pending = get_user_meta($user->ID, self::META, true);
        delete_user_meta($user->ID, self::META); // Single use.

        $remember = (bool) SEOProStack_Settings::get('magic_login_remember');
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember, is_ssl());

        // Core hooks fired on purpose, exactly as wp_signon() and wp-login.php
        // do, so security, audit and redirect plugins treat this as a login.
        /** This action is documented in wp-includes/user.php */
        do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        $requested = (is_array($pending) && !empty($pending['redirect'])) ? $pending['redirect'] : '';
        $redirect  = $requested ? $requested : admin_url();
        /** This filter is documented in wp-login.php */
        $redirect = apply_filters('login_redirect', $redirect, $requested, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        // Same fallbacks as wp-login.php for users who cannot use the dashboard.
        if (empty($redirect) || admin_url() === $redirect) {
            if (is_multisite() && !get_active_blog_for_user($user->ID) && !is_super_admin($user->ID)) {
                $redirect = user_admin_url();
            } elseif (!$user->has_cap('edit_posts')) {
                $redirect = $user->has_cap('read') ? admin_url('profile.php') : home_url();
            }
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Show an invalid/expired link message.
     *
     * @param WP_Error $error Error.
     */
    private static function render_link_error(WP_Error $error) {
        login_header(__('Login link', 'seoprostack'), '', $error);
        ?>
        <p id="nav">
            <a href="<?php echo esc_url(self::url()); ?>"><?php esc_html_e('Email me a new link', 'seoprostack'); ?></a>
            <span aria-hidden="true">|</span>
            <a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Log in with a password', 'seoprostack'); ?></a>
        </p>
        <?php
        login_footer();
    }

    /**
     * Check a user/token pair without consuming it.
     *
     * @param WP_User|false $user  User.
     * @param string        $token Token from the link.
     * @return true|WP_Error
     */
    private static function verify($user, $token) {
        $invalid = new WP_Error('seoprostack_invalid', __('This login link is invalid or has already been used.', 'seoprostack'));

        if (!$user instanceof WP_User || '' === $token || !self::user_allowed($user)) {
            return $invalid;
        }

        $pending = get_user_meta($user->ID, self::META, true);
        if (!is_array($pending) || empty($pending['hash']) || !hash_equals($pending['hash'], self::hash($token, $user->ID))) {
            return $invalid;
        }

        if (empty($pending['expires']) || time() > (int) $pending['expires']) {
            delete_user_meta($user->ID, self::META);
            return new WP_Error('seoprostack_expired', __('This login link has expired.', 'seoprostack'));
        }

        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * URL of our wp-login.php screen.
     *
     * @return string
     */
    private static function url() {
        return add_query_arg('action', self::ACTION, wp_login_url());
    }

    /**
     * User and token from the current request.
     *
     * @return array{0:WP_User|false,1:string}
     */
    private static function link_from_request() {
        // phpcs:disable WordPress.Security.NonceVerification -- the token itself is the credential; POST paths verify a nonce first.
        $uid   = isset($_REQUEST['uid']) ? absint(wp_unslash($_REQUEST['uid'])) : 0;
        $token = isset($_REQUEST['token']) ? preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash($_REQUEST['token']))) : '';
        // phpcs:enable
        return array($uid ? get_user_by('id', $uid) : false, (string) $token);
    }

    /**
     * Validated redirect target from the request.
     *
     * @return string
     */
    private static function redirect_from_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated below; used as a redirect target only.
        $raw = isset($_REQUEST['redirect_to']) ? sanitize_url(wp_unslash($_REQUEST['redirect_to'])) : '';
        return $raw ? wp_validate_redirect($raw, '') : '';
    }

    /**
     * Whether a user may use login links.
     *
     * @param WP_User $user User.
     * @return bool
     */
    private static function user_allowed(WP_User $user) {
        $allowed = true;
        if ('no_admins' === SEOProStack_Settings::get('magic_login_users') && ($user->has_cap('manage_options') || is_super_admin($user->ID))) {
            $allowed = false;
        }
        if (is_multisite() && is_user_spammy($user)) {
            $allowed = false;
        }

        /**
         * Filter whether a user may log in with a magic link.
         *
         * @param bool    $allowed Whether allowed.
         * @param WP_User $user    User.
         */
        return (bool) apply_filters('seoprostack_magic_login_allowed', $allowed, $user);
    }

    /**
     * Token hash bound to the user and site salts.
     *
     * @param string $token   Token.
     * @param int    $user_id User ID.
     * @return string
     */
    private static function hash($token, $user_id) {
        return hash_hmac('sha256', $user_id . '|' . $token, wp_salt('auth'));
    }

    /**
     * Count a request against the per-IP limit.
     *
     * @return bool False when the limit is reached.
     */
    private static function within_ip_limit() {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $key = 'seoprostack_ml_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 32);

        $count = (int) get_transient($key);
        if ($count >= (int) apply_filters('seoprostack_magic_login_ip_limit', self::IP_LIMIT)) {
            return false;
        }
        set_transient($key, $count + 1, self::WINDOW);
        return true;
    }
}
