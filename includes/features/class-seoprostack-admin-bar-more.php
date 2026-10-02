<?php
/**
 * More menu in the admin bar.
 *
 * Moves the items that plugins and themes add on the left of the admin bar
 * into one "…" menu, last on the left: after WordPress's own items (+ New,
 * Edit and the like) and any kept items, so the bar stays on one line
 * instead of wrapping over the page.
 * "…" opens like any admin bar menu, on hover; the moved items keep their
 * own submenus.
 * The right of the bar (account menu, notices bell, Plugins menu) is not
 * changed.
 *
 * Items are told apart by the code that added them, noted by a small
 * admin bar subclass (SEOProStack_Admin_Bar_Sources). Chosen plugins (and
 * themes or must-use plugins) keep their items on the bar. If another
 * plugin has replaced the admin bar class, WordPress's own items are
 * recognised by ID and every other item moves.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Bar_More extends SEOProStack_Feature {

    const KEY = 'admin_bar_more';

    /** Sources whose items stay on the bar. */
    const KEEP_KEY = 'admin_bar_more_keep';

    /** Option: sources seen adding items to the left of the bar. */
    const SEEN = 'seoprostack_admin_bar_items';

    /** Admin bar node ID of the menu. */
    const NODE = 'seoprostack-more';

    /**
     * WordPress's own top-level items on the left, used when the admin bar
     * class is not ours and sources are unknown.
     */
    const CORE_ITEMS = array(
        'menu-toggle', 'wp-logo', 'my-sites', 'site-name', 'site-editor', 'customize',
        'updates', 'command-palette', 'comments', 'new-content', 'edit', 'view',
        'preview', 'archive', 'get-shortlink',
    );

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
                'label'       => __('More menu in the admin bar', 'seoprostack'),
                'description' => __('Put the items plugins and themes add to the left of the admin bar in one … menu, last on that side, so the bar stays on one line and never covers the page. Point at … to open it. Works in wp-admin and on the site.', 'seoprostack'),
                // The bar on this page was drawn before the change.
                'reload'      => true,
            ),
            self::KEEP_KEY => array(
                'type'        => 'multi',
                'default'     => array(self::own_source()),
                'parent'      => self::KEY,
                'label'       => __('Keep on the bar', 'seoprostack'),
                'description' => __('Items from these stay on the bar. Those that have added items to it are listed first.', 'seoprostack'),
                'options'     => array(__CLASS__, 'source_options'),
                // Keep choices for plugins that are switched off for now.
                'open'        => true,
                'reload'      => true,
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // Late, so a plugin that replaces the class keeps its own.
        add_filter('wp_admin_bar_class', array(__CLASS__, 'bar_class'), PHP_INT_MAX);
        // After plugins that add items while the bar renders.
        add_action('wp_before_admin_bar_render', array(__CLASS__, 'move'), 100000);
        add_action('admin_bar_init', array(__CLASS__, 'assets'));
    }

    /**
     * Use the admin bar subclass that notes who added each item, unless
     * another plugin uses its own class.
     *
     * @param string $class Admin bar class.
     * @return string
     */
    public static function bar_class($class) {
        if ('WP_Admin_Bar' !== $class) {
            return $class;
        }
        if (!class_exists('SEOProStack_Admin_Bar_Sources', false)) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-admin-bar-sources.php';
        }
        return 'SEOProStack_Admin_Bar_Sources';
    }

    /**
     * Source key of SEO Pro Stack itself (its folder name).
     *
     * @return string
     */
    private static function own_source() {
        return basename(dirname(SEOPROSTACK_FILE));
    }

    /**
     * Move items into the menu.
     */
    public static function move() {
        global $wp_admin_bar;
        if (!($wp_admin_bar instanceof WP_Admin_Bar)) {
            return;
        }
        $nodes = $wp_admin_bar->get_nodes();
        if (!$nodes || isset($nodes[self::NODE])) {
            return;
        }

        $known   = $wp_admin_bar instanceof SEOProStack_Admin_Bar_Sources;
        $sources = $known ? $wp_admin_bar->seoprostack_sources() : array();
        $keep    = array_flip(array_map('strval', (array) SEOProStack_Settings::get(self::KEEP_KEY)));
        $move    = array();
        $seen    = array();
        foreach ($nodes as $id => $node) {
            if (!empty($node->group) || ($node->parent && 'root' !== $node->parent && 'root-default' !== $node->parent)) {
                continue; // Groups (such as the right of the bar) and items inside menus.
            }
            if ($known) {
                $source = isset($sources[$id]) ? $sources[$id] : '';
                if ('core' === $source) {
                    continue;
                }
                if ('' !== $source) {
                    $seen[$source] = true;
                    if (isset($keep[$source])) {
                        continue;
                    }
                }
            } elseif (in_array($id, self::CORE_ITEMS, true)) {
                continue;
            }
            $move[] = $id;
        }
        if ($seen) {
            self::remember($seen);
        }

        /**
         * Filter the IDs of the admin bar items moved into the More menu.
         *
         * @param string[]     $move Top-level item IDs, in bar order.
         * @param WP_Admin_Bar $bar  Admin bar.
         */
        $move = array_values(array_filter((array) apply_filters('seoprostack_admin_bar_more_items', $move, $wp_admin_bar), function ($id) use ($nodes) {
            return is_string($id) && isset($nodes[$id]);
        }));
        if (!$move) {
            return;
        }

        $label = __('More', 'seoprostack');
        // No tooltip, like core's menus: it would cover the open menu. Screen
        // readers read the hidden text, and the menu is labelled by menu_title.
        // No link: it is an ordinary menu, so core's admin bar script opens it
        // on hover, Enter or a tap and closes it with Escape. tabindex 0 makes
        // it reachable from the keyboard.
        $wp_admin_bar->add_node(array(
            'id'    => self::NODE,
            'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span>',
            'href'  => false,
            'meta'  => array(
                'menu_title' => $label,
                'tabindex'   => 0,
            ),
        ));
        // Nodes keep their place in the list, so moved items stay in bar order.
        // Their own menus point at their IDs and move with them.
        foreach ($move as $id) {
            $args  = array(
                'id'     => $id,
                'parent' => self::NODE,
            );
            $title = (string) $nodes[$id]->title;
            $name  = isset($sources[$id]) && '' !== $sources[$id] && !self::has_words($title) ? self::source_name($sources[$id]) : null;
            if (null !== $name) {
                // An icon or numbers alone say little in a list: add who it is from.
                $args['title'] = $title . '<span class="sps-more-name">' . esc_html($name) . '</span>';
            }
            $wp_admin_bar->add_node($args);
        }
    }

    /**
     * Whether an item title shows a word of three or more letters
     * (ignoring text only for screen readers).
     *
     * @param string $title Item title HTML.
     * @return bool
     */
    private static function has_words($title) {
        $title = preg_replace('#<(span|div)[^>]*class=["\'][^"\']*screen-reader-text[^"\']*["\'][^>]*>.*?</\1>#is', ' ', $title);
        $text  = html_entity_decode(wp_strip_all_tags((string) $title), ENT_QUOTES, 'UTF-8');
        return (bool) preg_match('/\p{L}{3,}/u', $text);
    }

    /**
     * Name of a plugin, theme or must-use plugin source, or null if it is
     * not installed or active.
     *
     * @param string $source Source key.
     * @return string|null
     */
    private static function source_name($source) {
        if (0 === strpos($source, 'theme--')) {
            $theme = wp_get_theme(substr($source, 7));
            return $theme->exists() ? (string) $theme->get('Name') : null;
        }
        if (0 === strpos($source, 'mu--')) {
            $name = substr($source, 4);
            if (!function_exists('get_mu_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $mu = get_mu_plugins();
            if (!empty($mu[$name . '.php']['Name'])) {
                return wp_strip_all_tags($mu[$name . '.php']['Name']);
            }
            return is_dir(WPMU_PLUGIN_DIR . '/' . $name) ? $name : null;
        }
        $plugins = self::active_plugin_names();
        return isset($plugins[$source]) ? $plugins[$source] : null;
    }

    /**
     * Active plugins, source key => name. Single-file plugins (such as
     * hello.php) are known by their file name without ".php".
     *
     * @return array<string,string>
     */
    private static function active_plugin_names() {
        $names  = SEOProStack_Plugin_Toggle::plugin_names();
        $active = array();
        foreach (SEOProStack_Feature::active_plugins() as $slug => $file) {
            $active[$slug] = isset($names[$file]) ? $names[$file] : $slug;
        }
        foreach (SEOProStack_Plugin_Loader::stored_active_plugins() as $file) {
            $file = (string) $file;
            if (false === strpos($file, '/')) {
                $active[preg_replace('/\.php$/', '', $file)] = isset($names[$file]) ? $names[$file] : $file;
            }
        }
        return $active;
    }

    /**
     * Note sources that add items, for the settings list. Writes only when
     * a new one appears.
     *
     * @param array<string,bool> $seen Source keys.
     */
    private static function remember(array $seen) {
        if (!current_user_can('manage_options')) {
            return;
        }
        $stored = get_option(self::SEEN, array());
        $stored = is_array($stored) ? $stored : array();
        $new    = array_diff_key($seen, $stored);
        if ($new) {
            update_option(self::SEEN, $stored + $new, true);
        }
    }

    /**
     * Choices for "Keep on the bar": plugins, themes and must-use plugins
     * seen adding items first, then the other active plugins.
     *
     * @return array<string,string> Source key => name.
     */
    public static function source_options() {
        $active = self::active_plugin_names();
        $seen   = get_option(self::SEEN, array());
        $in     = array();
        foreach (is_array($seen) ? array_keys($seen) : array() as $source) {
            $source = (string) $source;
            $name   = self::source_name($source);
            if (null === $name) {
                continue; // Deactivated or deleted.
            }
            if (0 === strpos($source, 'theme--')) {
                /* translators: %s: theme name */
                $name = sprintf(__('%s theme', 'seoprostack'), $name);
            } elseif (0 === strpos($source, 'mu--')) {
                /* translators: %s: must-use plugin name */
                $name = sprintf(__('%s must-use plugin', 'seoprostack'), $name);
            }
            /* translators: %s: plugin or theme name */
            $in[$source] = sprintf(__('%s (adds items)', 'seoprostack'), $name);
        }
        natcasesort($in);
        $rest = array_diff_key($active, $in);
        natcasesort($rest);
        return $in + $rest;
    }

    /**
     * Styles and a screen reader fix, added to the admin bar's own assets.
     * Opening and closing is core's, as for every admin bar menu.
     */
    public static function assets() {
        $m = '#wpadminbar #wp-admin-bar-' . self::NODE;
        $css = "{$m}>.ab-item .ab-icon{margin-right:0}"
            . "{$m}>.ab-item .ab-icon:before{content:\"\\f11c\";top:2px}"
            . "{$m}>.ab-item{cursor:default}"
            . "{$m}>.ab-sub-wrapper{min-width:12rem}"
            // Moved items are built for the bar: let titles and icons size to the row.
            . "{$m} .ab-submenu>li>.ab-item{height:auto;min-height:26px;white-space:nowrap}"
            // Items laid out as a spread-out flex row for the bar (TranslatePress's
            // Translate Site) would spread across the wider menu: keep them left.
            . "{$m} .ab-submenu>li>.ab-item{justify-content:flex-start}"
            // Core floats bar icons (.ab-icon, .ab-item:before) and plugins do the
            // same. On the bar each item is one line, but a menu row can be shorter
            // than its icon, and the float then pushes the next rows to the right
            // (Rank Math SEO after AutomatorWP). Each row holds its own floats.
            . "{$m} .ab-submenu>li>.ab-item:after{content:\"\";display:table;clear:both}"
            . "{$m} .ab-submenu .ab-icon,{$m} .ab-submenu .ab-item:before{padding:3px 0;margin-right:6px}"
            . "{$m} .ab-submenu img{max-height:20px;vertical-align:middle}"
            // Some plugins nest an .ab-item in their title (Yoast SEO's logo); size it to its content.
            . "{$m} .ab-submenu .ab-item .ab-item{min-width:0;padding:0 6px 0 0}"
            . "{$m} .sps-more-name{margin-left:.5em;opacity:.8;font-size:inherit}"
            // Phones and tablets: core hides plugin items there; the menu shows them.
            . "@media screen and (max-width:782px){"
            . "#wpadminbar li#wp-admin-bar-" . self::NODE . "{display:block;position:static}"
            . "{$m}.hover li{display:list-item}"
            . "{$m}>.ab-sub-wrapper{max-height:calc(100vh - 46px);overflow-y:auto;overscroll-behavior:contain}"
            . "{$m} .ab-submenu .ab-label{position:static;width:auto;height:auto;margin:0;clip-path:none;overflow:visible}"
            . "{$m} .ab-submenu .ab-icon,{$m} .ab-submenu .ab-item:before{width:auto;height:auto;font-size:20px!important;line-height:1!important;text-indent:0}"
            // Their own submenus open in place, since there is no hover.
            . "{$m} .ab-submenu .ab-sub-wrapper{display:block;position:static;margin:0;box-shadow:none;padding-left:16px}"
            . "{$m} .menupop>.ab-item:before,{$m} .wp-admin-bar-arrow{display:none}"
            . "}";
        wp_add_inline_style('admin-bar', $css);

        // Core sets aria-expanded on the first link inside an opened menu.
        // "…" is not a link, so that would mark a moved item open instead.
        // Keep every toggle in the menu in step with its own open state.
        $js = '(function(id){'
            . 'function sync(m){var t=m.querySelectorAll(".menupop>.ab-item"),i,a;'
            . 'for(i=0;i<t.length;i++){a=t[i];if(a.parentNode===m||a.hasAttribute("aria-expanded")){'
            . 'var v=a.parentNode.classList.contains("hover")?"true":"false";if(a.getAttribute("aria-expanded")!==v){a.setAttribute("aria-expanded",v);}}}}'
            . 'function watch(){var m=document.getElementById("wp-admin-bar-"+id);if(!m){return;}sync(m);'
            . 'if(window.MutationObserver){new MutationObserver(function(){sync(m);}).observe(m,{attributes:true,attributeFilter:["class"],subtree:true});}}'
            . 'if("loading"===document.readyState){document.addEventListener("DOMContentLoaded",watch);}else{watch();}'
            // Escape on a moved item with its own submenu only closes that
            // submenu in core; when it is already closed, close "…" instead.
            . 'document.addEventListener("keydown",function(e){if("Escape"!==e.key&&27!==e.which){return;}'
            . 'var m=document.getElementById("wp-admin-bar-"+id),w=m?m.querySelector(".ab-sub-wrapper"):null;'
            . 'if(!w||!m.classList.contains("hover")||!e.target.closest||!w.contains(e.target)){return;}'
            . 'var p=e.target.closest(".menupop");if(!p||p===m||p.classList.contains("hover")){return;}'
            . 'e.stopPropagation();m.classList.remove("hover");var t=m.querySelector(".ab-item");if(t){t.focus();}},true);'
            . '})(' . wp_json_encode(self::NODE) . ');';
        wp_add_inline_script('admin-bar', $js);
    }
}
