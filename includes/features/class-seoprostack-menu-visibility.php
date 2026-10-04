<?php
/**
 * Menu item visibility.
 *
 * Each item in Appearance → Menus gets a "Who sees this" choice: everyone,
 * logged-in people, logged-out visitors, chosen roles, or everyone except
 * chosen roles. Hidden items, and the items below them, are left out of
 * menus on the site. Kadence and other classic themes build their header and
 * footer menus from these items; Kadence's navigation block has its own
 * visibility settings.
 *
 * Replaces Nav Menu Roles. Its rules (`_nav_menu_role`, with
 * `_nav_menu_role_display_mode`) are read as they are until an item is
 * saved here, and are never changed.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Menu_Visibility extends SEOProStack_Feature {

    const KEY = 'menu_visibility';

    /** Menu item meta: array( 'show' => in|out|roles|not_roles|everyone, 'roles' => string[] ). */
    const META = '_seoprostack_menu_visibility';

    /** Nonce action. */
    const NONCE = 'seoprostack_menu_visibility';

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
                'label'       => __('Menu item visibility', 'seoprostack'),
                'description' => __('Show a menu item only to logged-in people, logged-out visitors or chosen roles. Choose “Who sees this” on each item in Appearance → Menus.', 'seoprostack'),
                'replaces'    => array('nav-menu-roles' => 'Nav Menu Roles'),
            ),
        );
    }

    /**
     * Switch on where Nav Menu Roles has rules. The rules themselves are
     * read in place (see rule()).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off check during the settings upgrade.
        $used = $wpdb->get_var("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_nav_menu_role' AND meta_value <> '' LIMIT 1");
        return $used ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        if (is_admin()) {
            add_action('wp_nav_menu_item_custom_fields', array(__CLASS__, 'fields'), 10, 2);
            add_action('wp_update_nav_menu_item', array(__CLASS__, 'save'), 10, 2);
            add_action('admin_footer-nav-menus.php', array(__CLASS__, 'script'));
            return;
        }
        add_filter('wp_get_nav_menu_items', array(__CLASS__, 'filter_items'), 20);
    }

    /**
     * The rule for a menu item, ours first, then Nav Menu Roles'.
     *
     * @param int $item_id Menu item ID.
     * @return array{show:string,roles:string[]}
     */
    public static function rule($item_id) {
        $rule = get_post_meta($item_id, self::META, true);
        if (is_array($rule) && isset($rule['show'])) {
            return array(
                'show'  => (string) $rule['show'],
                'roles' => isset($rule['roles']) ? array_values(array_map('strval', (array) $rule['roles'])) : array(),
            );
        }

        $theirs = get_post_meta($item_id, '_nav_menu_role', true);
        $hide   = 'hide' === get_post_meta($item_id, '_nav_menu_role_display_mode', true);
        if ('in' === $theirs) {
            return array('show' => $hide ? 'out' : 'in', 'roles' => array());
        }
        if ('out' === $theirs) {
            return array('show' => $hide ? 'in' : 'out', 'roles' => array());
        }
        if (is_array($theirs) && $theirs) {
            return array('show' => $hide ? 'not_roles' : 'roles', 'roles' => array_values(array_map('strval', $theirs)));
        }
        return array('show' => 'everyone', 'roles' => array());
    }

    /**
     * Whether the visitor counts as logged in on this site.
     *
     * @return bool
     */
    private static function logged_in() {
        if (!is_user_logged_in()) {
            return false;
        }
        return !is_multisite() || is_user_member_of_blog() || is_super_admin();
    }

    /**
     * Whether the current visitor has one of the roles (or capabilities, as
     * Nav Menu Roles allowed).
     *
     * @param string[] $roles Roles.
     * @return bool
     */
    private static function has_role(array $roles) {
        if (!self::logged_in()) {
            return false;
        }
        foreach ($roles as $role) {
            if ('' !== $role && current_user_can($role)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current visitor sees an item.
     *
     * @param WP_Post|object $item Menu item.
     * @return bool
     */
    public static function visible($item) {
        $rule = self::rule(isset($item->ID) ? (int) $item->ID : 0);
        switch ($rule['show']) {
            case 'in':
                $visible = self::logged_in();
                break;
            case 'out':
                $visible = !self::logged_in();
                break;
            case 'roles':
                $visible = self::has_role($rule['roles']);
                break;
            case 'not_roles':
                $visible = !self::has_role($rule['roles']);
                break;
            default:
                $visible = true;
        }
        /**
         * Whether the current visitor sees a menu item.
         *
         * @param bool   $visible Visible.
         * @param object $item    Menu item.
         * @param array  $rule    The item's rule (show, roles).
         */
        return (bool) apply_filters('seoprostack_menu_item_visible', $visible, $item, $rule);
    }

    /**
     * Leave out hidden items and everything below them.
     *
     * @param array $items Menu items.
     * @return array
     */
    public static function filter_items($items) {
        if (!is_array($items) || !$items) {
            return $items;
        }
        $hidden = array();
        foreach ($items as $item) {
            if (isset($item->ID) && !self::visible($item)) {
                $hidden[(int) $item->ID] = true;
            }
        }
        if (!$hidden) {
            return $items;
        }
        // Children can come before their parent in the list; repeat until no
        // more are found.
        do {
            $found = false;
            foreach ($items as $item) {
                $parent = isset($item->menu_item_parent) ? (int) $item->menu_item_parent : 0;
                if ($parent && isset($item->ID, $hidden[$parent]) && !isset($hidden[(int) $item->ID])) {
                    $hidden[(int) $item->ID] = true;
                    $found                   = true;
                }
            }
        } while ($found);

        return array_values(array_filter($items, function ($item) use ($hidden) {
            return !isset($hidden[(int) $item->ID]);
        }));
    }

    /**
     * Role names.
     *
     * @return array<string,string>
     */
    private static function roles() {
        return self::role_options();
    }

    /**
     * Fields on each menu item.
     *
     * @param int    $item_id Menu item ID.
     * @param object $item    Menu item.
     */
    public static function fields($item_id, $item) {
        $rule  = self::rule((int) $item_id);
        $id    = 'seoprostack-menu-show-' . (int) $item_id;
        $shows = array(
            'everyone'  => __('Everyone', 'seoprostack'),
            'in'        => __('Logged-in people', 'seoprostack'),
            'out'       => __('Logged-out visitors', 'seoprostack'),
            'roles'     => __('Only these roles', 'seoprostack'),
            'not_roles' => __('Everyone except these roles', 'seoprostack'),
        );
        $roles = self::roles();
        // Keep imported roles or capabilities that are not roles here.
        foreach ($rule['roles'] as $role) {
            if (!isset($roles[$role])) {
                $roles[$role] = $role;
            }
        }
        $with_roles = in_array($rule['show'], array('roles', 'not_roles'), true);
        ?>
        <fieldset class="field-seoprostack-visibility description description-wide">
            <input type="hidden" name="seoprostack_menu_visibility_nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE)); ?>" />
            <label for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Who sees this', 'seoprostack'); ?></label><br />
            <select id="<?php echo esc_attr($id); ?>" class="widefat seoprostack-menu-show" name="seoprostack_menu_show[<?php echo (int) $item_id; ?>]">
                <?php foreach ($shows as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($rule['show'], $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="seoprostack-menu-roles" <?php echo $with_roles ? '' : 'hidden'; ?>>
                <?php foreach ($roles as $role => $name) : ?>
                    <label style="display:inline-block;margin:6px 12px 0 0;">
                        <input type="checkbox" name="seoprostack_menu_roles[<?php echo (int) $item_id; ?>][]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $rule['roles'], true)); ?> />
                        <?php echo esc_html($name); ?>
                    </label>
                <?php endforeach; ?>
            </span>
        </fieldset>
        <?php
    }

    /**
     * Show the roles only when they apply.
     */
    public static function script() {
        ?>
        <script>
        (function () {
            function sync(select) {
                var roles = select.parentNode.querySelector('.seoprostack-menu-roles');
                if (roles) { roles.hidden = select.value !== 'roles' && select.value !== 'not_roles'; }
            }
            document.addEventListener('change', function (e) {
                if (e.target.classList && e.target.classList.contains('seoprostack-menu-show')) { sync(e.target); }
            });
        })();
        </script>
        <?php
    }

    /**
     * Save an item's rule from Appearance → Menus.
     *
     * @param int $menu_id Menu ID.
     * @param int $item_id Menu item ID.
     */
    public static function save($menu_id, $item_id) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below before anything is saved.
        if (!isset($_POST['seoprostack_menu_show']) || !is_array($_POST['seoprostack_menu_show']) || !isset($_POST['seoprostack_menu_show'][$item_id])) {
            return;
        }
        $nonce = isset($_POST['seoprostack_menu_visibility_nonce']) ? sanitize_text_field(wp_unslash($_POST['seoprostack_menu_visibility_nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE) || !current_user_can('edit_theme_options')) {
            return;
        }
        $show  = sanitize_key(wp_unslash($_POST['seoprostack_menu_show'][$item_id]));
        // Core sends the menu form both as fields and as JSON (nav-menu-data),
        // which can repeat ticked values.
        $roles = isset($_POST['seoprostack_menu_roles'][$item_id]) ? array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) wp_unslash($_POST['seoprostack_menu_roles'][$item_id]))))) : array();
        // phpcs:enable

        if (!in_array($show, array('everyone', 'in', 'out', 'roles', 'not_roles'), true)) {
            $show = 'everyone';
        }
        if (in_array($show, array('roles', 'not_roles'), true) && !$roles) {
            // No roles ticked: only these roles = nobody is not meant; treat as everyone.
            $show = 'everyone';
        }
        if (!in_array($show, array('roles', 'not_roles'), true)) {
            $roles = array();
        }

        // "Everyone" needs no record, unless Nav Menu Roles has one we must outrank.
        $theirs = get_post_meta($item_id, '_nav_menu_role', true);
        if ('everyone' === $show && empty($theirs)) {
            delete_post_meta($item_id, self::META);
            return;
        }
        update_post_meta($item_id, self::META, array('show' => $show, 'roles' => $roles));
    }
}
