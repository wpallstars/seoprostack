<?php
/**
 * Fixes for other plugins.
 *
 * Works around bugs in other plugins that slow sites down, through those
 * plugins' own hooks. Nothing is stored and no other plugin's settings are
 * changed: switching this off brings each bug back as it was.
 *
 * Lasso Lite (Simple URLs) 159 saves the reply of lasso.link's
 * server-lite/getinfo, {"site_id": "…"}, as its site ID instead of the
 * 32-character ID inside it. Its own check then rejects the ID, so on every
 * admin request by an administrator (admin-ajax included) it asks lasso.link
 * again, which takes about 400 ms, and its one-time install report never
 * goes. Its `lasso_lite_estimate_earning_site_id` filter gets the ID from
 * the saved reply. Lasso Lite then sends its install report once and stops
 * asking; its other reports (each setup step once, a weekly earnings
 * estimate in the background) run as its makers intended.
 *
 * Tutor LMS Pro's updater (checked with 4.1.0) reads the version, download
 * address and tested WordPress version from any update reply with status
 * 200, even one whose details are empty, as the "no update" reply of the
 * copies sold by GPL resellers is: six PHP warnings and deprecation notices
 * on every update check, in the debug log and WP-CLI output. Such a reply,
 * from the server or from a filter on `pre_http_request`, is completed with
 * the installed version and no download address, so the updater sees no
 * update, as the reply meant. Status stays 200 (its licence check before
 * updating reads it), and replies that offer an update are left alone.
 *
 * Freesoul Deactivate Plugins 2.6.9 and its PRO add-on (1.3.0.0, which
 * needs the free plugin) leave pieces behind when switched off. Deactivating
 * one left the other active, and PRO's own deactivation hook is registered
 * for a file that is not the plugin's, so it never runs. The free plugin
 * deletes its must-use file (mu-plugins/eos-deactivate-plugins.php) only on
 * single sites, and only when its own code runs during the deactivation; a
 * file left behind keeps skipping plugins by Freesoul's saved rules on single
 * sites and shows an error on every admin screen. Deactivating either one,
 * from any screen or WP-CLI, now deactivates both in the same place (this
 * site, or the network), and once neither is active anywhere the must-use
 * file is deleted, if it is Freesoul's. Activating the free plugin puts the
 * file back.
 *
 * Readabler 2.0.18 (and other Merkulove plugins built on the same "Unity"
 * code) asks its server for product information on every load of the
 * Plugins screen, waiting up to 60 seconds, even while it has the answer
 * cached for a day. When the request fails (a timeout, the server down, or
 * HTTP Requests Manager blocking it), it adds an admin notice that throws an
 * exception outside its own error handling, so the Plugins screen stops
 * with "There has been a critical error on this website". The request is
 * now skipped while its cached answer is there, and the throwing notice is
 * removed before it runs (Readabler already logs the failure itself).
 *
 * Tutor LMS 4.1.0 names its foreign keys the same on every site
 * (fk_tutor_order_item_order_id and eight more), but MySQL and MariaDB need
 * each name once per database. On a network, or sites sharing a database,
 * only the first site gets its order, cart and coupon tables; on the others
 * Tutor's installer fails quietly, and its 3.8.0 upgrade then fails on every
 * admin screen ("Foreign key constraint is incorrectly formed") because
 * tutor_order_items is missing. Its cart and coupon tables also point at
 * {prefix}users, which subsites do not have. When Tutor creates a table, a
 * name another table already has gets the site's table prefix, and the
 * users table is the network's. On an admin screen of a site where Tutor is
 * installed but tutor_order_items is missing, Tutor's own installer is run
 * once (a failure waits a day before the next try).
 *
 * Tutor LMS Pro 4.0.9's updater deletes the update_plugins transient and runs
 * wp_update_plugins() on every load of the Plugins screen, so that screen
 * waits for every plugin's update and licence servers each time (seconds on
 * a site with many premium plugins). Its closure is removed, and WordPress's
 * own check on that screen, at most once an hour, stays.
 *
 * Comment Goblin 1.2.0 asks its server for update details on every read of
 * the update_plugins transient (many times on each admin screen), and keeps
 * the answer for a day only when the request succeeds. While its server
 * fails (as commentgoblin.com did, with a certificate for another name), each
 * read waits for that failure. After a failure, its update check is answered
 * at once with the "request failed" error it already handles, for 12 hours
 * (a site transient, so for every site of a network).
 *
 * Action Scheduler (in WooCommerce, Rank Math, FluentCRM and others) runs
 * queued actions in a request it starts itself and does not wait for, and
 * WordPress starts cron (wp-cron.php) the same way. A LiteSpeed server stops
 * PHP as soon as the caller hangs up, unless the request has sent output
 * already, so ignore_user_abort() does not help: the run stops partway,
 * Action Scheduler marks its actions failed after 300 seconds, and a query
 * stopped in the middle leaves "Commands out of sync" database errors for
 * every query at shutdown. LiteSpeed's noabort variable keeps those two
 * requests running; LiteSpeed Cache sets it for its own background request
 * the same way. On LiteSpeed servers the rules go in a block at the top of
 * the site's .htaccess (before WordPress's rules, which end rule processing
 * for existing files on a network), on a network following the main site's
 * switch, and the block is removed when this is switched off or SEO Pro
 * Stack is deactivated. This is the one fix that writes a file.
 *
 * MainWP Child (checked with 6.2.1) prints the Branding extension's "Global
 * footer" text on every front-end page, at wp_footer after the theme's
 * footer and outside any element, so it shows unstyled below the site's
 * footer (as "Email support@… for assistance." did on agency sites). Its
 * branding_global_footer callback is removed on the front end; the same
 * text set as "Dashboard footer" still shows in the admin footer, and
 * MainWP's saved settings are left alone.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_Fixes extends SEOProStack_Feature {

    const KEY = 'plugin_fixes';

    /** Freesoul Deactivate Plugins: PRO first, as it needs the free plugin. */
    const FDP = array(
        'freesoul-deactivate-plugins-pro/freesoul-deactivate-plugins-pro.php',
        'freesoul-deactivate-plugins/freesoul-deactivate-plugins.php',
    );

    /** Freesoul's must-use file, in WPMU_PLUGIN_DIR. */
    const FDP_MU = 'eos-deactivate-plugins.php';

    /** Sites checked for Freesoul on a network; more than this counts as in use. */
    const FDP_MAX_SITES = 1000;

    /**
     * Where Freesoul was deactivated in this request: 'site' and/or 'network'.
     *
     * @var array<string, bool>
     */
    private static $fdp_scopes = array();

    /** Whether the shutdown step is running (it deactivates too). */
    private static $fdp_finishing = false;

    /** Merkulove "Unity" classes: {Plugin} is the plugin's own namespace part. */
    const UNITY = '/^Merkulove\\\\(\w+)\\\\Unity\\\\(UnityActions|EnvatoItem)$/';

    /** After a failed Tutor table repair, wait this long (a transient) before trying again. */
    const TUTOR_RETRY = 'seoprostack_tutor_tables_retry';

    /** Tutor LMS Pro's updater class, whose current_screen closure forces update checks. */
    const TUTOR_PRO_UPDATER = 'TutorPRO\ThemeumUpdater\Update';

    /** After Comment Goblin's update server fails, answer from here (a site transient) until it runs out. */
    const CG_FAILED = 'seoprostack_comment_goblin_failed';

    /** MainWP Child's branding class, whose wp_footer callback prints the Global footer. */
    const MAINWP_BRANDING = 'MainWP\Child\MainWP_Child_Branding';

    /** .htaccess marker of the noabort rules. */
    const NOABORT_MARKER = 'SEO Pro Stack background requests';

    /** What the noabort block holds now (a hash, '' for none), as a site option. */
    const NOABORT_SYNCED = 'seoprostack_noabort_rules';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: each fix only undoes a bug.
                'default'     => true,
                'tab'         => 'plugins',
                'label'       => __('Fixes for other plugins', 'seoprostack'),
                'description' => __('Works around bugs in other plugins that slow your site down, without changing their settings. Lasso Lite (Simple URLs) stops contacting its server on every admin screen. Deactivating Freesoul Deactivate Plugins or its PRO add-on deactivates both and removes the must-use file it leaves behind. Readabler no longer contacts its server on every Plugins screen load, or stops that screen with a critical error when it cannot. Tutor LMS Pro stops adding warnings to the debug log when there is no update. Tutor LMS gets its order, cart and coupon tables on every site of a network. Tutor LMS Pro no longer makes the Plugins screen check every plugin for updates on each load. Comment Goblin no longer waits for its update server on every admin screen while that server fails. MainWP Child no longer prints its Branding "Global footer" text, unstyled, below every front-end page. On LiteSpeed servers, scheduled tasks (WordPress cron and Action Scheduler, used by WooCommerce and others) finish instead of stopping partway, through a few lines at the top of .htaccess. Turn this off if a fix causes a problem.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks. Each filter belongs to the plugin it fixes, or checks
     * the request address first, and costs nothing when that plugin is not
     * active.
     */
    public static function boot() {
        if (is_admin()) {
            // Also when switched off, to remove the block.
            add_action('admin_init', array(__CLASS__, 'noabort_maybe_sync'));
        }
        if (!self::enabled()) {
            return;
        }
        add_filter('lasso_lite_estimate_earning_site_id', array(__CLASS__, 'lasso_site_id'));
        add_action('deactivated_plugin', array(__CLASS__, 'fdp_deactivated'), 10, 2);
        add_action('deleted_plugin', array(__CLASS__, 'fdp_deleted'), 10, 2);
        // A file left by an earlier deactivation, or by removing the plugin
        // folders by hand.
        add_action('load-plugins.php', array(__CLASS__, 'fdp_remove_mu'));
        // Before Merkulove's own callbacks, which use priority 10.
        add_action('load-plugins.php', array(__CLASS__, 'unity_skip_request'), 0);
        add_action('admin_notices', array(__CLASS__, 'unity_drop_throwing_notice'), 0);
        // Replies from the server, and replies a filter made up instead
        // (WordPress returns those without the http_response filter).
        add_filter('http_response', array(__CLASS__, 'tutor_pro_no_update'), 10, 3);
        add_filter('pre_http_request', array(__CLASS__, 'tutor_pro_no_update'), PHP_INT_MAX, 3);
        add_filter('dbdelta_create_queries', array(__CLASS__, 'tutor_create_queries'));
        // Before Tutor's upgrader, which uses priority 10.
        add_action('admin_init', array(__CLASS__, 'tutor_repair_tables'), 5);
        // Before Tutor LMS Pro's closure, which uses priority 10.
        add_action('current_screen', array(__CLASS__, 'tutor_pro_no_forced_check'), 0);
        add_filter('pre_http_request', array(__CLASS__, 'cg_skip_failed'), 10, 3);
        add_action('http_api_debug', array(__CLASS__, 'cg_note_failure'), 10, 5);
        // Before MainWP Child's callback, which uses priority 15.
        add_action('wp_footer', array(__CLASS__, 'mainwp_no_front_end_footer'), 0);
    }

    /**
     * Remove MainWP Child's Branding "Global footer" from the front end,
     * where it is printed after the theme's footer, outside any element.
     */
    public static function mainwp_no_front_end_footer() {
        global $wp_filter;
        if (!isset($wp_filter['wp_footer'])) {
            return;
        }
        foreach ($wp_filter['wp_footer']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && is_object($function[0]) && 'branding_global_footer' === $function[1]
                    && is_a($function[0], self::MAINWP_BRANDING)) {
                    remove_action('wp_footer', $function, $priority);
                }
            }
        }
    }

    /**
     * Remove Tutor LMS Pro's current_screen closure, which deletes the
     * update_plugins transient and runs wp_update_plugins() on every load of
     * the Plugins screen. WordPress's own check on that screen (at most once
     * an hour) stays.
     */
    public static function tutor_pro_no_forced_check() {
        global $wp_filter;
        if (!isset($wp_filter['current_screen'])) {
            return;
        }
        foreach ($wp_filter['current_screen']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (!$callback['function'] instanceof Closure) {
                    continue;
                }
                $scope = (new ReflectionFunction($callback['function']))->getClosureScopeClass();
                if ($scope && self::TUTOR_PRO_UPDATER === $scope->getName()) {
                    remove_action('current_screen', $callback['function'], $priority);
                }
            }
        }
    }

    /**
     * Whether a request is Comment Goblin's update check.
     *
     * @param mixed $url Request address.
     * @return bool
     */
    private static function is_cg_update_check($url) {
        return defined('CG_API_BASE') && is_string(CG_API_BASE) && is_string($url)
            && 0 === strpos($url, CG_API_BASE . 'plugin-details');
    }

    /**
     * While Comment Goblin's update server is known to fail, answer its
     * update check with the error it already handles, without waiting.
     *
     * @param false|array|WP_Error $pre  Answer from an earlier filter.
     * @param array                $args Request arguments.
     * @param string               $url  Request address.
     * @return false|array|WP_Error
     */
    public static function cg_skip_failed($pre, $args, $url) {
        if (false !== $pre || !self::is_cg_update_check($url) || !get_site_transient(self::CG_FAILED)) {
            return $pre;
        }
        return new WP_Error(
            'http_request_failed',
            __('Comment Goblin’s update server failed recently. SEO Pro Stack waits 12 hours before asking it again.', 'seoprostack'),
            array('seoprostack' => 'comment_goblin')
        );
    }

    /**
     * Note a failed Comment Goblin update check, which Comment Goblin does
     * not keep, so it is not asked again on every read of update_plugins.
     * Answers from pre_http_request filters, this fix's included, do not
     * reach http_api_debug.
     *
     * @param array|WP_Error $response Answer.
     * @param string         $context  "response".
     * @param string         $class    Transport.
     * @param array          $args     Request arguments.
     * @param string         $url      Request address.
     */
    public static function cg_note_failure($response, $context, $class, $args, $url) {
        if ('response' !== $context || !self::is_cg_update_check($url)) {
            return;
        }
        if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response) && '' !== wp_remote_retrieve_body($response)) {
            return;
        }
        set_site_transient(self::CG_FAILED, 1, 12 * HOUR_IN_SECONDS);
    }

    /**
     * Tutor LMS's CREATE TABLE statements: foreign key names another table
     * already has get this site's table prefix, and the users table is the
     * network's.
     *
     * @param array $queries CREATE TABLE statements by table name.
     * @return array
     */
    public static function tutor_create_queries($queries) {
        global $wpdb;
        $own   = $wpdb->prefix . 'tutor_';
        $taken = null;
        foreach ($queries as $table => $query) {
            if (!is_string($table) || !is_string($query) || 0 !== strpos($table, $own)) {
                continue;
            }
            if ($wpdb->users !== $wpdb->prefix . 'users') {
                $query = (string) preg_replace('/(REFERENCES\s+`?)' . preg_quote($wpdb->prefix . 'users', '/') . '(`?\s*\()/i', '${1}' . $wpdb->users . '${2}', $query);
            }
            if (preg_match_all('/CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY/i', $query, $names)) {
                if (null === $taken) {
                    $taken = array();
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- names of existing foreign keys, read only while a table is being created.
                    foreach ((array) $wpdb->get_results('SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()') as $row) {
                        $taken[strtolower($row->CONSTRAINT_NAME)] = $row->TABLE_NAME;
                    }
                }
                foreach ($names[1] as $name) {
                    $holder = isset($taken[strtolower($name)]) ? $taken[strtolower($name)] : null;
                    if (null !== $holder && 0 !== strcasecmp($holder, $table) && 0 !== stripos($name, $wpdb->prefix)) {
                        $query = (string) preg_replace('/(CONSTRAINT\s+`?)' . preg_quote($name, '/') . '\b/i', '${1}' . $wpdb->prefix . $name, $query);
                    }
                }
            }
            $queries[$table] = $query;
        }
        return $queries;
    }

    /**
     * Admin screens of a site where Tutor LMS is installed but its order
     * tables are missing: run Tutor's own installer, which now succeeds.
     */
    public static function tutor_repair_tables() {
        global $wpdb;
        if (!is_callable(array('TUTOR\Tutor', 'create_database')) || !get_option('tutor_version') || get_transient(self::TUTOR_RETRY)) {
            return;
        }
        if (self::tutor_has_order_items()) {
            return;
        }
        $suppressed = $wpdb->suppress_errors(true);
        call_user_func(array('TUTOR\Tutor', 'create_database'));
        $wpdb->suppress_errors($suppressed);
        if (!self::tutor_has_order_items()) {
            set_transient(self::TUTOR_RETRY, 1, DAY_IN_SECONDS);
        }
    }

    /**
     * Whether this site has Tutor's tutor_order_items table (asks the
     * database each time).
     *
     * @phpstan-impure
     * @return bool
     */
    private static function tutor_has_order_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_order_items';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- whether Tutor's table exists.
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    /**
     * Tutor LMS Pro's "no update" reply, completed with the installed
     * version so its updater does not read missing details.
     *
     * @param mixed  $response HTTP response (false from pre_http_request when no filter answered).
     * @param array  $args     Request arguments.
     * @param string $url      Request address.
     * @return mixed
     */
    public static function tutor_pro_no_update($response, $args, $url) {
        if (!is_array($response) || !isset($response['body']) || !is_string($response['body'])
            || !defined('TUTOR_PRO_VERSION') || !is_string($url)
            || !preg_match('#/themeum-products/v1/(plugin-update-status|check-update)/?$#', $url)) {
            return $response;
        }
        $body = isset($args['body']) ? $args['body'] : array();
        if (is_string($body)) {
            parse_str($body, $body);
        }
        if (!is_array($body) || !isset($body['product_slug']) || 'tutor-pro' !== strtolower((string) $body['product_slug'])) {
            return $response;
        }
        $data = json_decode($response['body']);
        if (!is_object($data) || !isset($data->status) || 200 !== (int) $data->status
            || !isset($data->body_response) || isset($data->body_response->version)
            || !(is_object($data->body_response) || array() === $data->body_response)) {
            return $response;
        }
        $data->body_response = (object) array_merge(
            array(
                'plugin_name'       => 'Tutor LMS Pro',
                'version'           => (string) TUTOR_PRO_VERSION,
                'download_url'      => '',
                'tested_wp_version' => '',
                'updated_at'        => '',
                'change_log'        => '',
            ),
            (array) $data->body_response
        );
        $response['body'] = (string) wp_json_encode($data);
        return $response;
    }

    /**
     * Plugins screen: skip a Merkulove plugin's product information request
     * while its cached answer (kept for a day) is there.
     */
    public static function unity_skip_request() {
        global $wp_filter;
        if (!isset($wp_filter['load-plugins.php'])) {
            return;
        }
        foreach ($wp_filter['load-plugins.php']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (!is_array($function) || !is_object($function[0]) || 'load_plugins' !== $function[1]
                    || !preg_match(self::UNITY, get_class($function[0]), $match) || 'UnityActions' !== $match[2]) {
                    continue;
                }
                $item = 'Merkulove\\' . $match[1] . '\\Unity\\EnvatoItem';
                if (is_callable(array($item, 'plugin_info')) && call_user_func(array($item, 'plugin_info'))) {
                    remove_action('load-plugins.php', $function, $priority);
                }
            }
        }
    }

    /**
     * Remove the admin notice a Merkulove plugin adds when its product
     * information request fails: it throws an exception, which stops the
     * screen. The plugin logs the failure itself.
     */
    public static function unity_drop_throwing_notice() {
        global $wp_filter;
        if (!isset($wp_filter['admin_notices'])) {
            return;
        }
        foreach ($wp_filter['admin_notices']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (!$callback['function'] instanceof Closure) {
                    continue;
                }
                $scope = (new ReflectionFunction($callback['function']))->getClosureScopeClass();
                if ($scope && preg_match(self::UNITY, $scope->getName(), $match) && 'EnvatoItem' === $match[2]) {
                    remove_action('admin_notices', $callback['function'], $priority);
                }
            }
        }
    }

    /**
     * Freesoul or its PRO add-on was deactivated. Core saves the list of
     * active plugins after this hook, so the other one is deactivated at
     * the end of the request.
     *
     * @param string $file         Plugin file.
     * @param bool   $network_wide Deactivated for the whole network.
     */
    public static function fdp_deactivated($file, $network_wide = false) {
        if (self::$fdp_finishing || !in_array($file, self::FDP, true)) {
            return;
        }
        if (!self::$fdp_scopes) {
            add_action('shutdown', array(__CLASS__, 'fdp_finish'), 0);
        }
        self::$fdp_scopes[$network_wide ? 'network' : 'site'] = true;
    }

    /**
     * End of a request that deactivated Freesoul or PRO: deactivate both in
     * the same place, then remove the must-use file if nothing uses it.
     */
    public static function fdp_finish() {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        self::$fdp_finishing = true;
        if (!empty(self::$fdp_scopes['network']) && is_multisite()) {
            $left = array_values(array_filter(self::FDP, 'is_plugin_active_for_network'));
            if ($left) {
                deactivate_plugins($left, false, true);
            }
        }
        if (!empty(self::$fdp_scopes['site'])) {
            self::load_plugin_state();
            $left = array_values(array_intersect(self::FDP, SEOProStack_Replaced_Plugins::stored_plugins()));
            if ($left) {
                deactivate_plugins($left);
            }
            // Freesoul guards saves of the list, so make sure they are saved.
            foreach (self::FDP as $file) {
                SEOProStack_Replaced_Plugins::save_plugin_state($file, false);
            }
        }
        self::$fdp_finishing = false;
        self::$fdp_scopes    = array();
        self::fdp_remove_mu();
    }

    /**
     * Freesoul or PRO deleted: remove the must-use file if nothing uses it.
     *
     * @param string $file    Plugin file.
     * @param bool   $deleted Whether the files were deleted.
     */
    public static function fdp_deleted($file, $deleted) {
        if ($deleted && in_array($file, self::FDP, true)) {
            self::fdp_remove_mu();
        }
    }

    /**
     * Delete Freesoul's must-use file when neither Freesoul plugin is active
     * anywhere on the install. Only a file that says it is Freesoul's.
     */
    public static function fdp_remove_mu() {
        $path = WPMU_PLUGIN_DIR . '/' . self::FDP_MU;
        if (!is_file($path) || (!current_user_can('activate_plugins') && !(defined('WP_CLI') && WP_CLI))) {
            return;
        }
        $head = (string) file_get_contents($path, false, null, 0, 1024); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file's header.
        if (false === stripos($head, 'freesoul deactivate plugins') || self::fdp_in_use()) {
            return;
        }
        wp_delete_file($path);
    }

    /**
     * Whether Freesoul or PRO is active on this site, for the network, or on
     * any site of the network, as stored (not as filtered for this request).
     *
     * @return bool
     */
    private static function fdp_in_use() {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        self::load_plugin_state();
        if (!is_multisite()) {
            return (bool) array_intersect(self::FDP, SEOProStack_Replaced_Plugins::stored_plugins());
        }
        if (array_filter(self::FDP, 'is_plugin_active_for_network')) {
            return true;
        }
        $sites = get_sites(array('fields' => 'ids', 'number' => self::FDP_MAX_SITES + 1));
        if (count($sites) > self::FDP_MAX_SITES) {
            return true;
        }
        foreach ($sites as $site_id) {
            switch_to_blog($site_id);
            $used = (bool) array_intersect(self::FDP, SEOProStack_Replaced_Plugins::stored_plugins());
            restore_current_blog();
            if ($used) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reading and saving the stored list of active plugins past other
     * plugins' filters (admin requests load it already; WP-CLI does not).
     */
    private static function load_plugin_state() {
        if (!class_exists('SEOProStack_Replaced_Plugins')) {
            require_once SEOPROSTACK_DIR . 'admin/includes/class-replaced-plugins.php';
        }
    }

    /**
     * Rules that keep LiteSpeed running WordPress cron and Action
     * Scheduler's async request after their caller hangs up. The path
     * pattern also matches a network's subfolder sites.
     *
     * @return string[]
     */
    public static function noabort_rules() {
        return array(
            '<IfModule LiteSpeed>',
            'RewriteEngine On',
            'RewriteRule (^|/)wp-cron\.php$ - [E=noabort:1]',
            'RewriteCond %{QUERY_STRING} (^|&)action=as_async_request_queue_runner(&|$)',
            'RewriteRule (^|/)wp-admin/admin-ajax\.php$ - [E=noabort:1]',
            '</IfModule>',
        );
    }

    /**
     * Keep the noabort block in step with the switch and the server, from
     * the main site's admin screens (a network shares one .htaccess). Reads
     * one site option when nothing changed.
     */
    public static function noabort_maybe_sync() {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        $rules = (self::enabled() && SEOProStack_Litespeed::is_server()) ? self::noabort_rules() : array();
        $want  = $rules ? md5(implode("\n", $rules)) : '';
        if ((string) get_site_option(self::NOABORT_SYNCED, '') === $want) {
            return;
        }
        // Retry on a later admin screen while the file cannot be written.
        if (self::noabort_write($rules)) {
            if ('' === $want) {
                delete_site_option(self::NOABORT_SYNCED);
            } else {
                update_site_option(self::NOABORT_SYNCED, $want);
            }
        }
    }

    /**
     * The site's root .htaccess (the network's on multisite).
     *
     * @return string
     */
    private static function noabort_file() {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return get_home_path() . '.htaccess';
    }

    /**
     * Write the block at the top of .htaccess, or remove it.
     *
     * @param string[] $rules Rules; empty removes the block.
     * @return bool Whether the file is as asked now.
     */
    private static function noabort_write(array $rules) {
        $file   = self::noabort_file();
        $exists = is_file($file);
        if (!$rules && !$exists) {
            return true;
        }
        $contents = $exists ? (string) file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        $begin    = '# BEGIN ' . self::NOABORT_MARKER;
        $end      = '# END ' . self::NOABORT_MARKER;
        $quoted   = preg_quote(self::NOABORT_MARKER, '/');
        $cleaned  = preg_replace('/# BEGIN ' . $quoted . '\r?\n.*?# END ' . $quoted . '[^\n]*(\n|$)\s*/s', '', $contents);
        if (null === $cleaned) {
            return false;
        }
        $block = $rules ? $begin . "\n" . implode("\n", $rules) . "\n" . $end . "\n\n" : '';
        $new   = $block . ltrim($cleaned, "\r\n");
        if ($new === $contents) {
            return true;
        }
        if (!($exists ? wp_is_writable($file) : wp_is_writable(dirname($file)))) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- as insert_with_markers(), which can only append a new block.
        return false !== file_put_contents($file, $new, LOCK_EX);
    }

    /**
     * Plugin deactivated: remove the noabort block, unless only a network's
     * subsite deactivated it (the network shares the file).
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        if (!is_multisite() || $network_wide || is_main_site()) {
            if (self::noabort_write(array())) {
                delete_site_option(self::NOABORT_SYNCED);
            }
        }
    }

    /**
     * Lasso Lite's site ID, taken from the saved server reply when Lasso
     * Lite saved the reply instead of the ID.
     *
     * @param mixed $site_id Site ID as Lasso Lite read it ('' when unusable).
     * @return mixed
     */
    public static function lasso_site_id($site_id) {
        if ('' !== $site_id) {
            return $site_id;
        }
        $settings = get_option('lassolite_settings');
        $saved    = is_array($settings) && isset($settings['site_id']) ? $settings['site_id'] : null;
        if (is_object($saved)) {
            $saved = get_object_vars($saved);
        }
        if (is_array($saved) && isset($saved['site_id']) && is_string($saved['site_id'])
            && preg_match('/^[a-f0-9]{32}$/i', $saved['site_id'])) {
            return strtolower($saved['site_id']);
        }
        return $site_id;
    }
}
