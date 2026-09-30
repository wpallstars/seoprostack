<?php
/**
 * Turn off WordPress notification emails.
 *
 * Each email is stopped with the core filter or action that sends it, so
 * nothing else about the flow changes (a password still changes, a comment
 * is still held for moderation). Replaces "Manage Notification E-mails";
 * its settings are imported once.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Notification_Emails extends SEOProStack_Feature {

    const KEY = 'notification_emails';

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
                'label'       => __('Notification emails', 'seoprostack'),
                'description' => __('Stop routine WordPress emails you do not need, such as auto-update reports. Only the emails you tick are stopped.', 'seoprostack'),
                'replaces'    => array('manage-notification-emails' => 'Manage Notification E-mails'),
            ),
            'notification_emails_off' => array(
                'type'        => 'multi',
                'default'     => array('auto_plugin_update', 'auto_theme_update'),
                'parent'      => self::KEY,
                'label'       => __('Do not send', 'seoprostack'),
                'description' => __('Password reset links are always sent to people who are not administrators.', 'seoprostack'),
                'options'     => array(__CLASS__, 'email_options'),
            ),
        );
    }

    /**
     * Email choices.
     *
     * @return array<string,string>
     */
    public static function email_options() {
        return array(
            'new_user_to_admin'        => __('New user registered (to admin)', 'seoprostack'),
            'new_user_to_user'         => __('Welcome email with login details (to new user)', 'seoprostack'),
            'password_changed_admin'   => __('A user reset their password (to admin)', 'seoprostack'),
            'password_changed_user'    => __('Your password was changed (to user)', 'seoprostack'),
            'email_changed_user'       => __('Your email address was changed (to user)', 'seoprostack'),
            'password_reset_admins'    => __('Password reset link for administrators', 'seoprostack'),
            'comment_to_author'        => __('New comment on your post (to post author)', 'seoprostack'),
            'comment_to_moderator'     => __('Comment awaiting moderation (to moderators)', 'seoprostack'),
            'auto_core_update'         => __('WordPress auto-update report', 'seoprostack'),
            'auto_plugin_update'       => __('Plugin auto-update report', 'seoprostack'),
            'auto_theme_update'        => __('Theme auto-update report', 'seoprostack'),
        );
    }

    /**
     * Import Manage Notification E-mails settings. Its settings form stores
     * "1" for each email that is sent and leaves unticked emails out.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $famne = get_option('famne_options');
        if (!is_array($famne) || !$famne) {
            return $options;
        }

        $map = array(
            'wp_new_user_notification_to_admin'   => 'new_user_to_admin',
            'wp_new_user_notification_to_user'    => 'new_user_to_user',
            'wp_password_change_notification'     => 'password_changed_admin',
            'send_password_change_email'          => 'password_changed_user',
            'send_email_change_email'             => 'email_changed_user',
            'send_password_admin_forgotten_email' => 'password_reset_admins',
            'wp_notify_postauthor'                => 'comment_to_author',
            'wp_notify_moderator'                 => 'comment_to_moderator',
            'auto_core_update_send_email'         => 'auto_core_update',
            'auto_plugin_update_send_email'       => 'auto_plugin_update',
            'auto_theme_update_send_email'        => 'auto_theme_update',
        );

        $off = array();
        foreach ($map as $theirs => $ours) {
            if (empty($famne[$theirs])) {
                $off[] = $ours;
            }
        }

        $options = self::import_setting($options, self::KEY, $off ? true : null);
        return self::import_setting($options, 'notification_emails_off', $off ? $off : null);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        $off = array_flip((array) SEOProStack_Settings::get('notification_emails_off'));
        $no  = '__return_false';

        if (isset($off['new_user_to_admin'])) {
            add_filter('wp_send_new_user_notification_to_admin', $no);
        }
        if (isset($off['new_user_to_user'])) {
            add_filter('wp_send_new_user_notification_to_user', $no);
        }
        if (isset($off['password_changed_admin'])) {
            remove_action('after_password_reset', 'wp_password_change_notification');
        }
        if (isset($off['password_changed_user'])) {
            add_filter('send_password_change_email', $no);
        }
        if (isset($off['email_changed_user'])) {
            add_filter('send_email_change_email', $no);
        }
        if (isset($off['password_reset_admins'])) {
            add_filter('send_retrieve_password_email', array(__CLASS__, 'filter_reset_email'), 10, 3);
        }
        if (isset($off['comment_to_author'])) {
            add_filter('notify_post_author', $no);
        }
        if (isset($off['comment_to_moderator'])) {
            add_filter('notify_moderator', $no);
        }
        if (isset($off['auto_core_update'])) {
            // Failed updates that need attention are still reported.
            add_filter('auto_core_update_send_email', array(__CLASS__, 'filter_core_update_email'), 10, 2);
        }
        if (isset($off['auto_plugin_update'])) {
            add_filter('auto_plugin_update_send_email', $no);
        }
        if (isset($off['auto_theme_update'])) {
            add_filter('auto_theme_update_send_email', $no);
        }
    }

    /**
     * Stop password reset emails for administrators only.
     *
     * @param bool    $send       Whether to send.
     * @param string  $user_login Username.
     * @param WP_User $user_data  User.
     * @return bool
     */
    public static function filter_reset_email($send, $user_login, $user_data) {
        if ($user_data instanceof WP_User && (user_can($user_data, 'manage_options') || is_super_admin($user_data->ID))) {
            return false;
        }
        return $send;
    }

    /**
     * Stop core auto-update success emails; keep failure reports.
     *
     * @param bool   $send Whether to send.
     * @param string $type success, fail or critical.
     * @return bool
     */
    public static function filter_core_update_email($send, $type) {
        return 'success' === $type ? false : $send;
    }
}
