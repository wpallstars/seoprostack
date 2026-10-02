<?php
/**
 * Turn off unused remote access methods with core hooks.
 *
 * Imports the matching Hostinger Tools and Disable Bloat switches, without
 * changing their options. Jetpack and some mobile apps need XML-RPC.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Hardening extends SEOProStack_Feature {

    const KEY = 'hardening';
    const ITEMS_KEY = 'hardening_items';

    /**
     * Settings; the card and both choices are off by default.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Turn off unused remote access', 'seoprostack'),
                'description' => __('Turn off XML-RPC or application passwords when this site does not use them. Jetpack and some mobile apps need XML-RPC. Application passwords let apps connect to this site without your login password.', 'seoprostack'),
                'replaces'    => array('hostinger' => 'Hostinger Tools') + SEOProStack_Disable_Bloat::PLUGINS,
                'reload'      => true,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Turn off', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
                'reload'      => true,
            ),
        );
    }

    /**
     * Independent choices.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        return array(
            'xmlrpc'        => __('Turn off XML-RPC', 'seoprostack'),
            'app_passwords' => __('Turn off application passwords', 'seoprostack'),
        );
    }

    /**
     * Import only enabled switches from active plugins, filling unset keys.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $choices = SEOProStack_Disable_Bloat::choices(self::ITEMS_KEY);
        if (isset(self::active_plugins()['hostinger'])) {
            $theirs = get_option('hostinger_tools', array());
            if (is_array($theirs)) {
                if (!empty($theirs['disable_xml_rpc'])) {
                    $choices[] = 'xmlrpc';
                }
                if (!empty($theirs['disable_authentication_password'])) {
                    $choices[] = 'app_passwords';
                }
            }
        }
        if ($choices) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::ITEMS_KEY, array_values(array_unique($choices)));
        }
        return $options;
    }

    /** Register hooks. */
    public static function boot() {
        // Replacement advice is needed even while the feature waits or is off.
        add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'hostinger_extras'), 10, 2);
        if (!self::enabled()) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));
        if (isset($items['app_passwords'])) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }
        if (isset($items['xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('wp_headers', array(__CLASS__, 'headers'));
            remove_action('wp_head', 'rsd_link');
            // Core defines this before loading WordPress for xmlrpc.php.
            // The filter alone does not block unauthenticated XML-RPC methods.
            if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
                status_header(403);
                nocache_headers();
                exit;
            }
        }
    }

    /**
     * Drop only the pingback header.
     *
     * @param array $headers Response headers.
     * @return array
     */
    public static function headers($headers) {
        foreach ($headers as $name => $value) {
            if (0 === strcasecmp($name, 'X-Pingback')) {
                unset($headers[$name]);
            }
        }
        return $headers;
    }

    /**
     * Do not suggest removing Hostinger's other tools or uncovered switches.
     * The one list for Hostinger Tools: SEOProStack_Maintenance covers its
     * maintenance mode, so that is not named here.
     *
     * @param string[] $extras Other functions still needed.
     * @param string   $slug   Plugin folder.
     * @return string[]
     */
    public static function hostinger_extras($extras, $slug) {
        if ('hostinger' !== $slug) {
            return $extras;
        }
        $theirs = get_option('hostinger_tools', array());
        if (!is_array($theirs)) {
            return $extras;
        }
        $other = array(
            'force_https'      => __('Redirect to HTTPS', 'seoprostack'),
            'force_www'        => __('Redirect to www', 'seoprostack'),
            'enable_llms_txt'  => __('Generate llms.txt', 'seoprostack'),
            'optin_mcp'        => __('Hostinger MCP connection', 'seoprostack'),
        );
        foreach ($other as $option => $name) {
            if (!empty($theirs[$option])) {
                $extras[] = $name;
            }
        }
        $items  = (array) SEOProStack_Settings::get(self::ITEMS_KEY);
        foreach (array('disable_xml_rpc' => 'xmlrpc', 'disable_authentication_password' => 'app_passwords') as $option => $choice) {
            if (!empty($theirs[$option])
                && !(self::switched_on() && in_array($choice, $items, true))) {
                $names    = self::item_options();
                $extras[] = $names[$choice];
            }
        }
        return array_values(array_unique($extras));
    }
}
