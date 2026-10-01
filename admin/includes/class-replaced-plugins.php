<?php
/**
 * Plugins screen: plugins SEO Pro Stack can replace.
 *
 * Lists installed plugins that a setting's `replaces` names:
 * - active, with every setting that replaces it on: the plugin can go, with a
 *   Deactivate link (the settings wait until it is deactivated);
 * - active, with a replacing setting off: SEO Pro Stack can do this job, with
 *   a link to the settings (only for people who can change them);
 * - installed but inactive, with every replacing setting on: no longer
 *   needed, with a Delete link (single sites; on multisite a plugin may be
 *   active on another site).
 *
 * Shown on the Plugins screen to people who can activate plugins. "Hide"
 * hides the plugins listed at the time for that person; a plugin that needs
 * a different step later shows again.
 *
 * @package SEOProStack
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Replaced_Plugins {

    /** admin-post action, also the nonce action. */
    const HIDE = 'seoprostack_hide_replaced_plugins';

    /** User meta: list of "slug:step" items the person hid. */
    const HIDDEN = 'seoprostack_replaced_plugins_hidden';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('load-plugins.php', array(__CLASS__, 'load_screen'));
        add_action('admin_post_' . self::HIDE, array(__CLASS__, 'hide'));
    }

    /**
     * Plugins screen: add the notice.
     */
    public static function load_screen() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        add_action(is_network_admin() ? 'network_admin_notices' : 'admin_notices', array(__CLASS__, 'notice'));
    }

    /**
     * Replaced plugins, keyed by folder name, with the settings that replace
     * them.
     *
     * @return array<string,array{name:string,settings:array<string,string>}> slug => name and setting key => label.
     */
    private static function replaced() {
        $replaced = array();
        foreach (SEOProStack_Settings::schema() as $key => $field) {
            if (empty($field['replaces']) || !is_array($field['replaces']) || !empty($field['parent'])) {
                continue;
            }
            foreach ($field['replaces'] as $slug => $name) {
                if (!isset($replaced[$slug])) {
                    $replaced[$slug] = array('name' => (string) $name, 'settings' => array());
                }
                $replaced[$slug]['settings'][$key] = isset($field['label']) ? (string) $field['label'] : (string) $key;
            }
        }
        return $replaced;
    }

    /**
     * What to show: one item per installed replaced plugin that needs a step.
     *
     * @return array<int,array{slug:string,file:string,name:string,step:string,settings:array<string,string>}>
     */
    private static function items() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = array();
        foreach (array_keys(get_plugins()) as $file) {
            $slug = dirname($file);
            if ('.' !== $slug) {
                $installed[$slug] = $file;
            }
        }

        $network = is_multisite() && is_network_admin();
        // Active where this screen can deactivate it: network-wide in the
        // network admin, on this site otherwise. Stored lists, so plugins
        // skipped on this screen still count.
        $here = $network
            ? array_keys((array) get_site_option('active_sitewide_plugins', array()))
            : SEOProStack_Plugin_Loader::stored_active_plugins();
        $all  = SEOProStack_Feature::active_plugins();

        $items = array();
        foreach (self::replaced() as $slug => $plugin) {
            if (!isset($installed[$slug])) {
                continue;
            }
            $file   = $installed[$slug];
            $all_on = true;
            foreach (array_keys($plugin['settings']) as $key) {
                $all_on = $all_on && (bool) SEOProStack_Settings::get($key);
            }

            if (in_array($file, $here, true)) {
                $step = $all_on ? 'deactivate' : 'switch_on';
            } elseif (!isset($all[$slug]) && !is_multisite() && $all_on) {
                $step = 'delete';
            } else {
                continue;
            }
            if ('switch_on' === $step && !SEOProStack_Settings::can_change()) {
                continue;
            }
            $items[] = array(
                'slug'     => (string) $slug,
                'file'     => $file,
                'name'     => $plugin['name'],
                'step'     => $step,
                'settings' => $plugin['settings'],
            );
        }

        $hidden = (array) get_user_meta(get_current_user_id(), self::HIDDEN, true);
        return array_values(array_filter($items, function ($item) use ($hidden) {
            return !in_array($item['slug'] . ':' . $item['step'], $hidden, true);
        }));
    }

    /**
     * The notice.
     */
    public static function notice() {
        $items = self::items();
        if (!$items) {
            return;
        }
        $rows = array();
        foreach ($items as $item) {
            $rows[] = self::row($item);
        }
        $keys = array_map(function ($item) {
            return $item['slug'] . ':' . $item['step'];
        }, $items);
        $hide = wp_nonce_url(add_query_arg(array('action' => self::HIDE, 'items' => implode(',', $keys)), admin_url('admin-post.php')), self::HIDE);
        ?>
        <div class="notice notice-info sps-replaced-plugins">
            <p><strong><?php esc_html_e('SEO Pro Stack can do the job of these plugins:', 'seoprostack'); ?></strong></p>
            <ul class="ul-disc">
                <?php foreach ($rows as $row) : ?>
                    <li><?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in row(). ?></li>
                <?php endforeach; ?>
            </ul>
            <p><a href="<?php echo esc_url($hide); ?>"><?php esc_html_e('Hide', 'seoprostack'); ?></a></p>
        </div>
        <?php
    }

    /**
     * One line of the notice.
     *
     * @param array $item Item from items().
     * @return string HTML.
     */
    private static function row(array $item) {
        $labels = implode(', ', array_map(function ($label) {
            return '‘' . $label . '’';
        }, $item['settings']));
        $name   = '<strong>' . esc_html($item['name']) . '</strong>';
        $base   = self_admin_url('plugins.php');

        if ('deactivate' === $item['step']) {
            /* translators: 1: plugin name, 2: SEO Pro Stack setting names */
            $text = sprintf(esc_html__('%1$s: %2$s is on and takes over once you deactivate it. Then you can delete it.', 'seoprostack'), $name, esc_html($labels));
            if (current_user_can('deactivate_plugin', $item['file'])) {
                $url   = wp_nonce_url(add_query_arg(array('action' => 'deactivate', 'plugin' => rawurlencode($item['file'])), $base), 'deactivate-plugin_' . $item['file']);
                /* translators: %s: plugin name */
                $text .= sprintf(' <a href="%1$s">%2$s</a>', esc_url($url), esc_html(sprintf(__('Deactivate %s', 'seoprostack'), $item['name'])));
            }
            return $text;
        }

        if ('delete' === $item['step']) {
            /* translators: 1: plugin name, 2: SEO Pro Stack setting names */
            $text = sprintf(esc_html__('%1$s is inactive and no longer needed: %2$s does its job.', 'seoprostack'), $name, esc_html($labels));
            if (current_user_can('delete_plugins')) {
                $url   = wp_nonce_url(add_query_arg(array('action' => 'delete-selected', 'checked[]' => $item['file'], 'plugin_status' => 'all'), $base), 'bulk-plugins');
                /* translators: %s: plugin name */
                $text .= sprintf(' <a href="%1$s">%2$s</a>', esc_url($url), esc_html(sprintf(__('Delete %s', 'seoprostack'), $item['name'])));
            }
            return $text;
        }

        /* translators: 1: plugin name, 2: SEO Pro Stack setting names */
        $text = sprintf(esc_html__('%1$s: switch on %2$s, then deactivate and delete it.', 'seoprostack'), $name, esc_html($labels));
        $url  = SEOProStack_Admin_Manager::tab_url(SEOProStack_Admin_Manager::SEARCH, array('s' => $item['name']));
        return $text . sprintf(' <a href="%1$s">%2$s</a>', esc_url($url), esc_html__('Show the setting', 'seoprostack'));
    }

    /**
     * admin-post: hide the listed items for this person.
     */
    public static function hide() {
        check_admin_referer(self::HIDE);
        if (!current_user_can('activate_plugins')) {
            wp_die(esc_html__('You are not allowed to manage plugins on this site.', 'seoprostack'), '', array('response' => 403));
        }
        $items  = isset($_GET['items']) ? explode(',', sanitize_text_field(wp_unslash($_GET['items']))) : array();
        $items  = array_filter($items, function ($item) {
            return (bool) preg_match('/^[A-Za-z0-9._-]+:(deactivate|switch_on|delete)$/', $item);
        });
        $hidden = (array) get_user_meta(get_current_user_id(), self::HIDDEN, true);
        update_user_meta(get_current_user_id(), self::HIDDEN, array_values(array_unique(array_filter(array_merge($hidden, $items)))));

        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : self_admin_url('plugins.php'));
        exit;
    }
}
