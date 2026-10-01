<?php
/**
 * Quiet Freemius prompts.
 *
 * Freemius is a sales and licensing kit that some plugins bundle (WP Sheet
 * Editor and its add-ons, Git Updater). Each copy nags: an opt-in notice on
 * every admin screen, Opt In, Upgrade and Add-Ons links on the Plugins
 * screen, trial and affiliate offers, a redirect to its opt-in page when the
 * plugin is activated, and a "why are you deactivating?" survey, printed in
 * full on the Plugins screen for every such plugin.
 *
 * Freemius cannot be unloaded: the plugins call it to check licences and
 * build their menus. This only turns the prompts off, through Freemius's own
 * per-plugin filters (fs_{tag}_{plugin}) and by removing the survey and
 * opt-out dialogs it hooks for plugins that are not opted in. Nothing is
 * stored: no plugin is opted in or out, and switching this off brings every
 * prompt back.
 *
 * Kept: licence activation, the Account, Contact Us and Support pages,
 * licence and payment notices, the Opt Out link of plugins that are opted
 * in, and the opt-in page that a plugin shows in place of its own until you
 * choose "Skip" once (that choice is the plugin's to store).
 *
 * @package SEOProStack
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Freemius_Quiet extends SEOProStack_Feature {

    const KEY = 'freemius_quiet';

    /** Freemius menu entries that only sell: Upgrade (pricing), Add-Ons, Affiliation. */
    const HIDDEN_PAGES = array('pricing', 'addons', 'affiliation');

    /**
     * Freemius instances already filtered, by spl_object_hash.
     *
     * @var array<string,true>
     */
    private static $done = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: fewer nags out of the box.
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Quiet Freemius prompts', 'seoprostack'),
                'description' => __('Plugins that use Freemius, such as WP Sheet Editor, stop asking you to opt in, offering upgrades, add-ons and trials, and asking why you deactivate them. Licences, accounts and support pages stay, and their settings are not changed.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        // admin_menu (network_admin_menu in the network admin) runs before
        // admin_init and builds Freemius's menu entries; admin_init
        // (priority 10) adds its links, notices and redirect. Each runs
        // filter(), so instances made late are caught.
        add_action('admin_menu', array(__CLASS__, 'filter'), 0);
        add_action('network_admin_menu', array(__CLASS__, 'filter'), 0);
        add_action('admin_init', array(__CLASS__, 'filter'), 0);
        if (!wp_doing_ajax()) {
            // After Freemius's own admin_init hooks have added the dialogs.
            add_action('admin_init', array(__CLASS__, 'unhook'), PHP_INT_MAX);
        }
    }

    /**
     * Freemius instances on this request.
     *
     * @return Freemius[]
     */
    private static function instances() {
        if (!class_exists('Freemius', false) || !method_exists('Freemius', '_get_all_instances')) {
            return array();
        }
        return array_filter((array) Freemius::_get_all_instances(), function ($fs) {
            return $fs instanceof Freemius && method_exists($fs, 'add_filter');
        });
    }

    /**
     * Whether a plugin is opted in and sharing data, so its Opt Out link and
     * dialog are worth keeping.
     *
     * @param Freemius $fs Instance.
     * @return bool
     */
    private static function opted_in($fs) {
        return method_exists($fs, 'is_registered') && $fs->is_registered()
            && method_exists($fs, 'is_tracking_allowed') && $fs->is_tracking_allowed();
    }

    /**
     * Add Freemius's own per-plugin filters that turn the prompts off.
     */
    public static function filter() {
        foreach (self::instances() as $fs) {
            $id = spl_object_hash($fs);
            if (isset(self::$done[$id])) {
                continue;
            }
            self::$done[$id] = true;

            $fs->add_filter('redirect_on_activation', '__return_false');
            $fs->add_filter('show_deactivation_feedback_form', '__return_false');
            $fs->add_filter('show_trial', '__return_false');
            $fs->add_filter('show_affiliate_program_notice', '__return_false');
            // Also hides the Upgrade and Add-Ons links on the Plugins screen.
            $fs->add_filter('is_submenu_visible', array(__CLASS__, 'submenu_visible'), 10, 2);
            $fs->add_filter('show_admin_notice', function ($show, $msg) use ($fs) {
                return self::notice_visible($show, $msg, $fs);
            }, 10, 2);
        }
    }

    /**
     * Hide Freemius menu entries that only sell.
     *
     * @param bool   $visible Whether the entry shows.
     * @param string $page    Freemius page id.
     * @return bool
     */
    public static function submenu_visible($visible, $page = '') {
        return in_array($page, self::HIDDEN_PAGES, true) ? false : $visible;
    }

    /**
     * Hide opt-in nags and promotions. Licence, payment and error notices
     * stay, and so does "Complete activation now" for plugins that only work
     * with a licence.
     *
     * @param bool     $show Whether the notice shows.
     * @param array    $msg  Notice: id, type, message, …
     * @param Freemius $fs   Instance it belongs to.
     * @return bool
     */
    public static function notice_visible($show, $msg, $fs) {
        $msg  = (array) $msg;
        $id   = isset($msg['id']) ? (string) $msg['id'] : '';
        $type = isset($msg['type']) ? (string) $msg['type'] : '';
        if ('connect_account' === $id || 'promotion' === $type) {
            return false;
        }
        // "You are just one step away": the opt-in nag of new installs.
        if ('update-nag' === $type && !(method_exists($fs, 'is_only_premium') && $fs->is_only_premium())) {
            return false;
        }
        return $show;
    }

    /**
     * Remove the dialogs Freemius prints in the Plugins screen's footer: the
     * deactivation survey of plugins that are not opted in (so they have no
     * subscription to cancel), and the opt-in dialog behind the Opt In link,
     * which is removed too.
     */
    public static function unhook() {
        foreach (self::instances() as $fs) {
            if (method_exists($fs, 'is_registered') && !$fs->is_registered()) {
                remove_action('admin_footer', array($fs, '_add_deactivation_feedback_dialog_box'));
            }
            if (!self::opted_in($fs) && method_exists($fs, 'get_plugin_basename')) {
                remove_action('admin_footer', array($fs, '_add_optout_dialog'));
                // Freemius adds its links on the plugin's own filter, which
                // runs after the general plugin_action_links one.
                $file = $fs->get_plugin_basename();
                add_filter('plugin_action_links_' . $file, array(__CLASS__, 'action_links'), 20, 2);
                add_filter('network_admin_plugin_action_links_' . $file, array(__CLASS__, 'action_links'), 20, 2);
            }
        }
    }

    /**
     * Remove the Opt In link from plugins that are not opted in. Freemius
     * adds its links at priority 10.
     *
     * @param array  $links Action links.
     * @param string $file  Plugin file.
     * @return array
     */
    public static function action_links($links, $file) {
        foreach (self::instances() as $fs) {
            if (!method_exists($fs, 'get_plugin_basename') || $fs->get_plugin_basename() !== $file || self::opted_in($fs)) {
                continue;
            }
            unset($links['opt-in-or-opt-out ' . $fs->get_slug()]);
        }
        return $links;
    }
}
