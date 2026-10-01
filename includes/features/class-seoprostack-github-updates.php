<?php
/**
 * Updates from GitHub, through Git Updater.
 *
 * Only in builds made from GitHub releases. The WordPress.org build leaves
 * this file out (see SEOProStack::$optional_features), because plugins
 * hosted there may not install or update code from anywhere else.
 *
 * - The plugin header names the GitHub repository for Git Updater, which
 *   then offers each GitHub release as a normal update. SEO Pro Stack never
 *   checks for updates or changes WordPress's update data itself.
 * - Without Git Updater, people who can install plugins get a notice on the
 *   Dashboard, Plugins, Updates and SEO Pro Stack screens, with a button that
 *   installs and activates its latest release from GitHub. Free Plugins lists
 *   it too. The notice is not a setting: it shows until Git Updater is active
 *   or the person dismisses it.
 * - Once SEO Pro Stack is on WordPress.org, Git Updater takes its updates
 *   from there. "Early updates from GitHub" keeps the site on GitHub
 *   releases, which come out first.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Github_Updates extends SEOProStack_Feature {

    const KEY = 'github_early_updates';

    /** Git Updater's repository and plugin folder. */
    const REPO = 'afragen/git-updater';
    const SLUG = 'git-updater';

    /** PHP version Git Updater needs (its "Requires PHP" header). */
    const REQUIRES_PHP = '8.0';

    /** admin-post actions, also used as nonce actions. */
    const INSTALL = 'seoprostack_install_git_updater';
    const DISMISS = 'seoprostack_dismiss_git_updater';

    /** User meta: when the person dismissed the notice. */
    const DISMISSED = 'seoprostack_git_updater_dismissed';

    /** Transient prefix (plus user ID): result of the last install. */
    const RESULT = 'seoprostack_git_updater_result_';

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
                'tab'         => 'maintenance',
                'label'       => __('Early updates from GitHub', 'seoprostack'),
                'description' => __('Get each new version of SEO Pro Stack from GitHub as soon as it is released, instead of waiting for WordPress.org. Needs the free Git Updater plugin. Until SEO Pro Stack is on WordPress.org, all its updates come from GitHub.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Git Updater reads this wherever it checks for updates (admin, cron, WP-CLI).
        if (self::enabled()) {
            add_filter('gu_override_dot_org', array(__CLASS__, 'override_dot_org'));
        }

        if (!is_admin()) {
            return;
        }
        add_filter('seoprostack_free_plugins', array(__CLASS__, 'free_plugins'));
        add_filter('seoprostack_external_plugins', array(__CLASS__, 'external_plugins'));
        add_action('admin_post_' . self::INSTALL, array(__CLASS__, 'install'));
        add_action('admin_post_' . self::DISMISS, array(__CLASS__, 'dismiss'));
        // On multisite only super admins install plugins, and Git Updater
        // must be network-activated.
        add_action(is_multisite() ? 'network_admin_notices' : 'admin_notices', array(__CLASS__, 'notice'));
    }

    /**
     * Keep this plugin on Git Updater's GitHub releases once it is also on
     * WordPress.org.
     *
     * @param mixed $plugins Plugin files Git Updater updates instead of WordPress.org.
     * @return array
     */
    public static function override_dot_org($plugins) {
        $plugins   = (array) $plugins;
        $plugins[] = plugin_basename(SEOPROSTACK_FILE);
        return array_values(array_unique($plugins));
    }

    /**
     * Git Updater's plugin file, if installed.
     *
     * @return string Plugin file relative to the plugins folder, or ''.
     */
    public static function installed_file() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            if (self::SLUG . '/git-updater.php' === $file || (isset($data['Name']) && 'Git Updater' === $data['Name'])) {
                return $file;
            }
        }
        return '';
    }

    /**
     * Whether Git Updater is installed and active (network-wide on multisite).
     *
     * @param string $file Plugin file from installed_file().
     * @return bool
     */
    private static function is_active($file) {
        if ('' === $file) {
            return false;
        }
        return is_multisite() ? is_plugin_active_for_network($file) : is_plugin_active($file);
    }

    /**
     * Whether the current person may install and activate Git Updater.
     *
     * @return bool
     */
    private static function can_install() {
        return current_user_can('install_plugins') && current_user_can(is_multisite() ? 'manage_network_plugins' : 'activate_plugins');
    }

    /**
     * Whether this server's PHP can run Git Updater.
     *
     * @return bool
     */
    private static function php_ok() {
        return version_compare(PHP_VERSION, self::REQUIRES_PHP, '>=');
    }

    /**
     * Nonce-protected admin-post URL.
     *
     * @param string $action INSTALL or DISMISS.
     * @return string
     */
    private static function action_url($action) {
        return wp_nonce_url(add_query_arg('action', $action, admin_url('admin-post.php')), $action);
    }

    /**
     * Core's activation link for Git Updater (network-wide on multisite).
     *
     * @param string $file Plugin file.
     * @return string
     */
    public static function activate_url($file) {
        $base = is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php');
        return wp_nonce_url(add_query_arg(array('action' => 'activate', 'plugin' => rawurlencode($file)), $base), 'activate-plugin_' . $file);
    }

    /**
     * Screens that show the notice.
     *
     * @return string[]
     */
    private static function notice_screens() {
        return array(
            'dashboard',
            'dashboard-network',
            'plugins',
            'plugins-network',
            'update-core',
            'update-core-network',
            'settings_page_seoprostack',
        );
    }

    /**
     * Notice: the result of an install, or a prompt to install Git Updater.
     */
    public static function notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, self::notice_screens(), true) || !self::can_install()) {
            return;
        }

        $user   = get_current_user_id();
        $result = get_transient(self::RESULT . $user);
        if (false !== $result) {
            delete_transient(self::RESULT . $user);
            self::result_notice((string) $result);
            return;
        }

        $file = self::installed_file();
        if (self::is_active($file) || get_user_meta($user, self::DISMISSED, true)) {
            return;
        }
        ?>
        <div class="notice notice-info sps-git-updater-notice">
            <p>
                <strong><?php esc_html_e('SEO Pro Stack gets its updates from GitHub.', 'seoprostack'); ?></strong>
                <?php esc_html_e('Install the free Git Updater plugin to see them on the Updates screen, like any other update.', 'seoprostack'); ?>
            </p>
            <?php if (!$file && !self::php_ok()) : ?>
                <p>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: PHP version Git Updater needs, 2: this server's PHP version */
                        __('Git Updater needs PHP %1$s or later; this site runs PHP %2$s. Until PHP is updated, install new versions of SEO Pro Stack by uploading them from GitHub.', 'seoprostack'),
                        self::REQUIRES_PHP,
                        PHP_VERSION
                    ));
                    ?>
                </p>
            <?php endif; ?>
            <p>
                <?php if ($file) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(self::activate_url($file)); ?>"><?php esc_html_e('Activate Git Updater', 'seoprostack'); ?></a>
                <?php elseif (self::php_ok()) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(self::action_url(self::INSTALL)); ?>"><?php esc_html_e('Install and activate Git Updater', 'seoprostack'); ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url(self::action_url(self::DISMISS)); ?>"><?php esc_html_e('Dismiss', 'seoprostack'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Say how an install went.
     *
     * @param string $result 'ok' or an error message.
     */
    private static function result_notice($result) {
        if ('ok' === $result) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Git Updater is installed and active. SEO Pro Stack updates from GitHub will show on the Updates screen.', 'seoprostack'));
            return;
        }
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong><?php esc_html_e('Git Updater could not be installed.', 'seoprostack'); ?></strong>
                <?php echo esc_html($result); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: link to Git Updater's releases on GitHub */
                    esc_html__('You can download it from %s and upload it from Plugins → Add New.', 'seoprostack'),
                    '<a href="' . esc_url('https://github.com/' . self::REPO . '/releases/latest') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('its GitHub releases', 'seoprostack') . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * admin-post: install (if needed) and activate Git Updater.
     */
    public static function install() {
        check_admin_referer(self::INSTALL);
        if (!self::can_install()) {
            wp_die(esc_html__('You are not allowed to install plugins on this site.', 'seoprostack'), '', array('response' => 403));
        }

        $file = self::installed_file();
        if ('' === $file) {
            $installed = self::install_latest();
            if (is_wp_error($installed)) {
                self::finish($installed);
            }
            $file = $installed;
        }

        $activated = activate_plugin($file, '', is_multisite());
        self::finish(is_wp_error($activated) ? $activated : true);
    }

    /**
     * Download and install Git Updater's latest GitHub release.
     *
     * @return string|WP_Error Plugin file, or the reason it failed.
     */
    private static function install_latest() {
        if (!self::php_ok()) {
            return new WP_Error('seoprostack_git_updater_php', sprintf(
                /* translators: 1: PHP version Git Updater needs, 2: this server's PHP version */
                __('Git Updater needs PHP %1$s or later; this site runs PHP %2$s.', 'seoprostack'),
                self::REQUIRES_PHP,
                PHP_VERSION
            ));
        }

        $package = self::package_url();
        if (is_wp_error($package)) {
            return $package;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $skin      = new WP_Ajax_Upgrader_Skin();
        $upgrader  = new Plugin_Upgrader($skin);
        $installed = $upgrader->install($package);

        if (is_wp_error($installed)) {
            return $installed;
        }
        if (is_wp_error($skin->result)) {
            return $skin->result;
        }
        if ($skin->get_errors()->has_errors()) {
            return new WP_Error('seoprostack_git_updater_install', $skin->get_error_messages());
        }
        if (!$installed) {
            return new WP_Error('seoprostack_git_updater_install', __('WordPress could not write to the plugins folder.', 'seoprostack'));
        }

        $file = $upgrader->plugin_info();
        if (!$file) {
            return new WP_Error('seoprostack_git_updater_install', __('The download did not contain a plugin.', 'seoprostack'));
        }
        return $file;
    }

    /**
     * Download address of Git Updater's latest release.
     *
     * @return string|WP_Error
     */
    private static function package_url() {
        $response = wp_safe_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/vnd.github+json'),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            return new WP_Error('seoprostack_git_updater_github', __('GitHub did not answer. Please try again in a few minutes.', 'seoprostack'));
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        $assets  = is_array($release) && isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();
        $prefix  = 'https://github.com/' . self::REPO . '/releases/download/';
        foreach ($assets as $asset) {
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            $url  = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
            if (preg_match('/^git-updater-[0-9][0-9.]*\.zip$/', $name) && 0 === strpos($url, $prefix)) {
                return $url;
            }
        }
        return new WP_Error('seoprostack_git_updater_github', __('The latest Git Updater release has no download.', 'seoprostack'));
    }

    /**
     * Remember the result for the next screen and go back.
     *
     * @param true|WP_Error $result Result.
     */
    private static function finish($result) {
        set_transient(self::RESULT . get_current_user_id(), is_wp_error($result) ? $result->get_error_message() : 'ok', 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::back_url());
        exit;
    }

    /**
     * admin-post: hide the notice for this person.
     */
    public static function dismiss() {
        check_admin_referer(self::DISMISS);
        if (!self::can_install()) {
            wp_die(esc_html__('You are not allowed to install plugins on this site.', 'seoprostack'), '', array('response' => 403));
        }
        update_user_meta(get_current_user_id(), self::DISMISSED, time());
        wp_safe_redirect(self::back_url());
        exit;
    }

    /**
     * The screen the person came from, or the Plugins screen.
     *
     * @return string
     */
    private static function back_url() {
        $referer = wp_get_referer();
        if ($referer) {
            return $referer;
        }
        return is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php');
    }

    /**
     * Free Plugins: list Git Updater first in Minimal, the tab that opens first.
     *
     * @param array $categories Category => slugs.
     * @return array
     */
    public static function free_plugins($categories) {
        $categories = (array) $categories;
        if (isset($categories['minimal']) && !in_array(self::SLUG, (array) $categories['minimal'], true)) {
            array_unshift($categories['minimal'], self::SLUG);
        }
        return $categories;
    }

    /**
     * Free Plugins: card data for Git Updater, which is not on WordPress.org.
     *
     * @param array $plugins Slug => card data.
     * @return array
     */
    public static function external_plugins($plugins) {
        $plugins             = (array) $plugins;
        $plugins[self::SLUG] = array(
            'name'         => 'Git Updater',
            'description'  => __('Updates plugins and themes from GitHub, GitLab, Bitbucket and Gitea, like those from WordPress.org. SEO Pro Stack uses it for updates from GitHub.', 'seoprostack'),
            'author'       => 'Andy Fragen',
            'url'          => 'https://git-updater.com/',
            'file'         => self::installed_file(),
            'network'      => is_multisite(),
            'install_url'  => self::can_install() ? self::action_url(self::INSTALL) : '',
            'requires_php' => self::REQUIRES_PHP,
            'source'       => __('Not on WordPress.org. Install Now gets the latest release from GitHub and activates it.', 'seoprostack'),
        );
        return $plugins;
    }
}
