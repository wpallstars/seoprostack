<?php
/**
 * Plugin presets on the Plugins screen.
 *
 * For each plugin SEO Pro Stack has a preset for (presets/{folder}.json, see
 * SEOProStack_Presets), the Plugins screen says whether its settings match
 * and offers, after a confirmation:
 * - Apply preset: set SEO Pro Stack's preferred settings;
 * - Reset to defaults: set the plugin's own defaults for the same settings;
 * - Undo: put back the settings from before the last apply or reset.
 * Apply and Reset are also bulk actions. Licence keys, API keys, passwords
 * and similar are never stored or changed.
 *
 * WP-CLI (`wp seoprostack presets`) lists, compares, applies, resets, undoes
 * and exports presets whether or not this setting is on.
 *
 * Plugins with starter data (starters/{folder}.json, see
 * SEOProStack_Starters) also offer Add starter data (example lists, tags,
 * fields or boards the site does not have yet) and Remove starter data
 * (what SEO Pro Stack added, while unused). WP-CLI: `wp seoprostack starters`.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_Presets extends SEOProStack_Feature {

    const KEY = 'plugin_presets';

    /** admin-post action. */
    const ACTION = 'seoprostack_plugin_preset';

    /** Bulk actions on the Plugins screen. */
    const BULK_APPLY = 'seoprostack-preset-apply';
    const BULK_RESET = 'seoprostack-preset-reset';

    /** Query arg carrying the result back to the Plugins screen. */
    const RESULT = 'seoprostack_preset';

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
                'tab'         => 'plugins',
                'label'       => __('Plugin presets', 'seoprostack'),
                'description' => __('On the Plugins screen, see whether a plugin’s settings match SEO Pro Stack’s choice, apply them, reset them to the plugin’s defaults or undo the last change. For FluentCRM and Fluent Boards, add example lists, tags, fields and boards to start from. Each asks first. Licence keys, API keys and passwords are never stored or changed.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-presets.php';
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-presets-cli.php';
            WP_CLI::add_command('seoprostack presets', 'SEOProStack_Presets_CLI');
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-starters.php';
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-starters-cli.php';
            WP_CLI::add_command('seoprostack starters', 'SEOProStack_Starters_CLI');
        }
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('load-plugins.php', array(__CLASS__, 'load_screen'));
    }

    /**
     * Whether the current person may change other plugins' settings here.
     *
     * @return bool
     */
    private static function allowed() {
        return current_user_can('manage_options') && current_user_can('activate_plugins') && !is_network_admin();
    }

    /**
     * Hook the Plugins screen.
     */
    public static function load_screen() {
        if (!self::allowed()) {
            return;
        }
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-presets.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-starters.php';
        if (!SEOProStack_Presets::all() && !SEOProStack_Starters::all()) {
            return;
        }
        add_filter('plugin_action_links', array(__CLASS__, 'action_links'), 20, 2);
        add_filter('plugin_row_meta', array(__CLASS__, 'row_meta'), 20, 2);
        add_filter('bulk_actions-plugins', array(__CLASS__, 'bulk_actions'));
        add_filter('handle_bulk_actions-plugins', array(__CLASS__, 'handle_bulk'), 10, 3);
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_print_footer_scripts', array(__CLASS__, 'script'));
        add_action('admin_head', array(__CLASS__, 'style'));
    }

    /**
     * Address of an action for one plugin.
     *
     * @param string $do   apply, reset or undo.
     * @param string $slug Plugin folder.
     * @return string
     */
    private static function action_url($do, $slug) {
        return wp_nonce_url(
            add_query_arg(array('action' => self::ACTION, 'do' => $do, 'plugin' => rawurlencode($slug)), admin_url('admin-post.php')),
            self::ACTION . '_' . $do . '_' . $slug
        );
    }

    /**
     * Apply, Reset and Undo links in a plugin's row.
     *
     * @param string[] $links Action links.
     * @param string   $file  Plugin file.
     * @return string[]
     */
    public static function action_links($links, $file) {
        $slug   = SEOProStack_Presets::slug_of($file);
        $links  = self::starter_links($links, $slug);
        $preset = SEOProStack_Presets::get($slug);
        if (!$preset) {
            return $links;
        }
        $name    = '' !== $preset['name'] ? $preset['name'] : $slug;
        $differs = count(SEOProStack_Presets::differences($slug));
        if ($differs) {
            $links['seoprostack-preset-apply'] = sprintf(
                '<a href="%1$s" data-sps-confirm="%2$s">%3$s</a>',
                esc_url(self::action_url('apply', $slug)),
                /* translators: 1: number of settings, 2: plugin name */
                esc_attr(sprintf(_n('Change %1$d setting of %2$s to SEO Pro Stack’s preset? You can undo this.', 'Change %1$d settings of %2$s to SEO Pro Stack’s preset? You can undo this.', $differs, 'seoprostack'), $differs, $name)),
                esc_html__('Apply preset', 'seoprostack')
            );
        }
        if ($preset['defaults'] && count(SEOProStack_Presets::differences($slug, 'defaults'))) {
            $links['seoprostack-preset-reset'] = sprintf(
                '<a href="%1$s" data-sps-confirm="%2$s">%3$s</a>',
                esc_url(self::action_url('reset', $slug)),
                /* translators: %s: plugin name */
                esc_attr(sprintf(__('Reset the preset’s settings of %s to the plugin’s defaults? Its other settings stay as they are. You can undo this.', 'seoprostack'), $name)),
                esc_html__('Reset to defaults', 'seoprostack')
            );
        }
        $undo = SEOProStack_Presets::undo_info($slug);
        if ($undo) {
            $links['seoprostack-preset-undo'] = sprintf(
                '<a href="%1$s" data-sps-confirm="%2$s">%3$s</a>',
                esc_url(self::action_url('undo', $slug)),
                /* translators: 1: plugin name, 2: date and time */
                esc_attr(sprintf(__('Put back the settings %1$s had before %2$s?', 'seoprostack'), $name, wp_date(get_option('date_format') . ' ' . get_option('time_format'), $undo['time']))),
                'defaults' === $undo['set'] ? esc_html__('Undo reset', 'seoprostack') : esc_html__('Undo preset', 'seoprostack')
            );
        }
        return $links;
    }

    /**
     * Preset status in a plugin's description, with the settings that differ.
     *
     * @param string[] $meta Row meta.
     * @param string   $file Plugin file.
     * @return string[]
     */
    public static function row_meta($meta, $file) {
        $slug   = SEOProStack_Presets::slug_of($file);
        $meta   = self::starter_meta($meta, $slug);
        $preset = SEOProStack_Presets::get($slug);
        if (!$preset) {
            return $meta;
        }
        $diffs = SEOProStack_Presets::differences($slug);
        if (!$diffs) {
            $meta[] = '<span class="sps-preset is-match">' . esc_html__('Preset: settings match', 'seoprostack') . '</span>';
            return $meta;
        }
        $items = '';
        foreach ($diffs as $path => $values) {
            $items .= sprintf(
                '<li><code>%1$s</code>: %2$s → %3$s</li>',
                esc_html($path),
                esc_html(self::show($values[0])),
                esc_html(self::show($values[1]))
            );
        }
        $notes = '' !== $preset['notes'] ? '<p>' . esc_html($preset['notes']) . '</p>' : '';
        if ('' !== $preset['tested']) {
            /* translators: %s: plugin version */
            $notes .= '<p>' . esc_html(sprintf(__('Chosen with version %s.', 'seoprostack'), $preset['tested'])) . '</p>';
        }
        $meta[] = sprintf(
            '<details class="sps-preset"><summary>%1$s</summary><ul>%2$s</ul>%3$s</details>',
            /* translators: %d: number of settings */
            esc_html(sprintf(_n('Preset: %d setting differs', 'Preset: %d settings differ', count($diffs), 'seoprostack'), count($diffs))),
            $items,
            $notes
        );
        return $meta;
    }

    /**
     * Add and Remove starter data links in an active plugin's row.
     *
     * @param string[] $links Action links.
     * @param string   $slug  Plugin folder.
     * @return string[]
     */
    private static function starter_links($links, $slug) {
        $starter = SEOProStack_Starters::get($slug);
        if (!$starter || !SEOProStack_Starters::ready($slug)) {
            return $links;
        }
        $missing = SEOProStack_Starters::missing_count($slug);
        if ($missing) {
            $links['seoprostack-starter-add'] = sprintf(
                '<a href="%1$s" data-sps-confirm="%2$s">%3$s</a>',
                esc_url(self::action_url('starter-add', $slug)),
                /* translators: 1: number of items, 2: plugin name */
                esc_attr(sprintf(_n('Add %1$d example item to %2$s? Nothing already there is changed. You can remove what was added while it is unused.', 'Add %1$d example items to %2$s? Nothing already there is changed. You can remove what was added while it is unused.', $missing, 'seoprostack'), $missing, $starter['name'])),
                esc_html__('Add starter data', 'seoprostack')
            );
        }
        if (SEOProStack_Starters::added_count($slug)) {
            $links['seoprostack-starter-remove'] = sprintf(
                '<a href="%1$s" data-sps-confirm="%2$s">%3$s</a>',
                esc_url(self::action_url('starter-remove', $slug)),
                /* translators: %s: plugin name */
                esc_attr(sprintf(__('Remove the starter data SEO Pro Stack added to %s? Lists and tags with contacts, fields with values, boards with tasks and changed settings stay.', 'seoprostack'), $starter['name'])),
                esc_html__('Remove starter data', 'seoprostack')
            );
        }
        return $links;
    }

    /**
     * Starter data status in an active plugin's description.
     *
     * @param string[] $meta Row meta.
     * @param string   $slug Plugin folder.
     * @return string[]
     */
    private static function starter_meta($meta, $slug) {
        $starter = SEOProStack_Starters::get($slug);
        if (!$starter || !SEOProStack_Starters::ready($slug)) {
            return $meta;
        }
        $missing = SEOProStack_Starters::missing($slug);
        $notes   = '' !== $starter['notes'] ? '<p>' . esc_html($starter['notes']) . '</p>' : '';
        if (!$missing) {
            $meta[] = sprintf('<details class="sps-preset is-match"><summary>%1$s</summary>%2$s</details>', esc_html__('Starter data: all there', 'seoprostack'), $notes);
            return $meta;
        }
        $items = '';
        $count = 0;
        foreach ($missing as $names) {
            foreach ($names as $name) {
                $items .= '<li>' . esc_html($name) . '</li>';
                ++$count;
            }
        }
        $meta[] = sprintf(
            '<details class="sps-preset"><summary>%1$s</summary><ul>%2$s</ul>%3$s</details>',
            /* translators: %d: number of items */
            esc_html(sprintf(_n('Starter data: %d item to add', 'Starter data: %d items to add', $count, 'seoprostack'), $count)),
            $items,
            $notes
        );
        return $meta;
    }

    /**
     * A setting's value as short text.
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function show($value) {
        if (null === $value) {
            return __('not set', 'seoprostack');
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $text = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
        if ('' === $text) {
            return __('empty', 'seoprostack');
        }
        return strlen($text) > 80 ? substr($text, 0, 77) . '…' : $text;
    }

    /**
     * Bulk actions.
     *
     * @param array $actions Bulk actions.
     * @return array
     */
    public static function bulk_actions($actions) {
        $actions[self::BULK_APPLY] = __('Apply presets', 'seoprostack');
        $actions[self::BULK_RESET] = __('Reset presets to defaults', 'seoprostack');
        return $actions;
    }

    /**
     * Run a bulk action. Core has checked the bulk-plugins nonce.
     *
     * @param string   $sendback Where to go next.
     * @param string   $action   Bulk action.
     * @param string[] $files    Ticked plugin files.
     * @return string
     */
    public static function handle_bulk($sendback, $action, $files) {
        if (!in_array($action, array(self::BULK_APPLY, self::BULK_RESET), true) || !self::allowed()) {
            return $sendback;
        }
        $set     = self::BULK_APPLY === $action ? 'options' : 'defaults';
        $plugins = 0;
        $changed = 0;
        foreach ((array) $files as $file) {
            $result = SEOProStack_Presets::write(SEOProStack_Presets::slug_of(sanitize_text_field((string) $file)), $set);
            if (is_int($result)) {
                $plugins += $result ? 1 : 0;
                $changed += $result;
            }
        }
        return self::result_url($sendback, 'options' === $set ? 'apply' : 'reset', $plugins, $changed, '');
    }

    /**
     * Run Apply, Reset or Undo for one plugin.
     */
    public static function handle() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below with the action and plugin in the nonce.
        $do   = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
        $slug = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
        // phpcs:enable
        if (!in_array($do, array('apply', 'reset', 'undo', 'starter-add', 'starter-remove'), true) || '' === $slug) {
            wp_die(esc_html__('Unknown preset action.', 'seoprostack'), '', array('response' => 400));
        }
        check_admin_referer(self::ACTION . '_' . $do . '_' . $slug);
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to change plugin settings.', 'seoprostack'), '', array('response' => 403));
        }
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-presets.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-starters.php';
        $back = self_admin_url('plugins.php');
        if ('starter-add' === $do || 'starter-remove' === $do) {
            $result = 'starter-add' === $do ? SEOProStack_Starters::add($slug) : SEOProStack_Starters::remove($slug);
            if (is_wp_error($result)) {
                wp_safe_redirect(add_query_arg(self::RESULT, rawurlencode($do . ':error:' . $result->get_error_code()), $back));
                exit;
            }
            // starter-add:0:added:slug, starter-remove:kept:removed:slug.
            $first  = is_array($result) ? count($result['kept']) : 0;
            $second = is_array($result) ? $result['removed'] : (int) $result;
            wp_safe_redirect(add_query_arg(self::RESULT, rawurlencode(implode(':', array($do, $first, $second, $slug))), remove_query_arg(self::RESULT, $back)));
            exit;
        }
        if ('undo' === $do) {
            $result = SEOProStack_Presets::undo($slug);
        } else {
            $result = SEOProStack_Presets::write($slug, 'apply' === $do ? 'options' : 'defaults');
        }
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(self::RESULT, rawurlencode($do . ':error:' . $result->get_error_code()), $back));
            exit;
        }
        wp_safe_redirect(self::result_url($back, $do, $result ? 1 : 0, (int) $result, $slug));
        exit;
    }

    /**
     * Plugins screen address with the result.
     *
     * @param string $url     Address.
     * @param string $do      apply, reset or undo.
     * @param int    $plugins Plugins changed.
     * @param int    $changed Settings changed.
     * @param string $slug    Plugin folder, for single actions.
     * @return string
     */
    private static function result_url($url, $do, $plugins, $changed, $slug) {
        $url = remove_query_arg(array(self::RESULT, 'activate', 'deactivate', 'activate-multi', 'deactivate-multi'), $url ? $url : self_admin_url('plugins.php'));
        return add_query_arg(self::RESULT, rawurlencode(implode(':', array($do, (int) $plugins, (int) $changed, $slug))), $url);
    }

    /**
     * Let core drop the result from the address bar.
     *
     * @param string[] $args Query args.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::RESULT;
        return $args;
    }

    /**
     * Say what happened.
     */
    public static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $raw = isset($_GET[self::RESULT]) ? sanitize_text_field(wp_unslash($_GET[self::RESULT])) : '';
        if ('' === $raw) {
            return;
        }
        $parts = explode(':', $raw, 4);
        $do    = $parts[0];
        if (isset($parts[1]) && 'error' === $parts[1]) {
            $messages = array(
                'seoprostack_no_preset'        => __('There is no preset for this plugin.', 'seoprostack'),
                'seoprostack_no_defaults'      => __('This preset does not list the plugin’s defaults.', 'seoprostack'),
                'seoprostack_no_undo'          => __('There is nothing to undo for this plugin.', 'seoprostack'),
                'seoprostack_no_starter'       => __('There is no starter data for this plugin.', 'seoprostack'),
                'seoprostack_starter_inactive' => __('Activate the plugin first.', 'seoprostack'),
                'seoprostack_nothing_added'    => __('SEO Pro Stack has not added anything to this plugin.', 'seoprostack'),
                'seoprostack_starter_failed'   => __('The plugin could not save the starter data. Check that it is up to date.', 'seoprostack'),
            );
            $code = isset($parts[2]) ? $parts[2] : '';
            printf('<div class="notice notice-error is-dismissible sps-keep"><p>%s</p></div>', esc_html(isset($messages[$code]) ? $messages[$code] : __('The preset could not be changed.', 'seoprostack')));
            return;
        }
        $plugins = isset($parts[1]) ? (int) $parts[1] : 0;
        $changed = isset($parts[2]) ? (int) $parts[2] : 0;
        $slug    = isset($parts[3]) ? $parts[3] : '';
        $preset  = '' !== $slug ? SEOProStack_Presets::get($slug) : null;
        $name    = $preset && '' !== $preset['name'] ? $preset['name'] : $slug;

        if ('starter-add' === $do || 'starter-remove' === $do) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-starters.php';
            $starter = SEOProStack_Starters::get($slug);
            $name    = $starter ? $starter['name'] : $slug;
            if ('starter-add' === $do) {
                $text = $changed
                    /* translators: 1: number of items, 2: plugin name */
                    ? sprintf(_n('Added %1$d example item to %2$s.', 'Added %1$d example items to %2$s.', $changed, 'seoprostack'), $changed, $name)
                    /* translators: %s: plugin name */
                    : sprintf(__('%s already has every starter item. Nothing changed.', 'seoprostack'), $name);
            } else {
                /* translators: 1: number of items, 2: plugin name */
                $text = sprintf(_n('Removed %1$d starter item from %2$s.', 'Removed %1$d starter items from %2$s.', $changed, 'seoprostack'), $changed, $name);
                if ($plugins) {
                    /* translators: %d: number of items */
                    $text .= ' ' . sprintf(_n('%d is in use or was changed, so it stays.', '%d are in use or were changed, so they stay.', $plugins, 'seoprostack'), $plugins);
                }
            }
            printf('<div class="notice notice-success is-dismissible sps-keep"><p>%s</p></div>', esc_html($text));
            return;
        }

        if ('undo' === $do) {
            /* translators: %s: plugin name */
            $text = sprintf(__('%s’s settings are back as they were.', 'seoprostack'), $name);
        } elseif (!$changed) {
            $text = 'apply' === $do ? __('Settings already match the preset. Nothing changed.', 'seoprostack') : __('Settings are already at the defaults. Nothing changed.', 'seoprostack');
        } elseif ('' !== $slug) {
            $text = 'apply' === $do
                /* translators: 1: number of settings, 2: plugin name */
                ? sprintf(_n('Changed %1$d setting of %2$s to the preset. Undo is in its row.', 'Changed %1$d settings of %2$s to the preset. Undo is in its row.', $changed, 'seoprostack'), $changed, $name)
                /* translators: 1: number of settings, 2: plugin name */
                : sprintf(_n('Reset %1$d setting of %2$s to its default. Undo is in its row.', 'Reset %1$d settings of %2$s to their defaults. Undo is in its row.', $changed, 'seoprostack'), $changed, $name);
        } else {
            $text = 'apply' === $do
                /* translators: %d: number of plugins */
                ? sprintf(_n('Applied the preset to %d plugin. Undo is in its row.', 'Applied presets to %d plugins. Undo is in each row.', $plugins, 'seoprostack'), $plugins)
                /* translators: %d: number of plugins */
                : sprintf(_n('Reset %d plugin to its defaults. Undo is in its row.', 'Reset %d plugins to their defaults. Undo is in each row.', $plugins, 'seoprostack'), $plugins);
        }
        // sps-keep: a message about what you just did stays on the page with Hide admin notices.
        printf('<div class="notice notice-success is-dismissible sps-keep"><p>%s</p></div>', esc_html($text));
    }

    /**
     * Styles.
     */
    public static function style() {
        ?>
        <style>
            .sps-preset summary { cursor: pointer; display: inline; }
            .sps-preset[open] { display: block; margin-top: 4px; }
            .sps-preset ul { margin: 4px 0 4px 1.5em; list-style: disc; }
            .sps-preset li { margin: 0; }
            .sps-preset p { margin: 4px 0; }
            .sps-preset.is-match { color: #646970; }
        </style>
        <?php
    }

    /**
     * Ask before Apply, Reset and Undo, and before the bulk actions.
     */
    public static function script() {
        $i18n = array(
            /* translators: %d: number of plugins */
            'apply' => __('Apply SEO Pro Stack’s presets to %d plugins? Only plugins with a preset change. You can undo each one.', 'seoprostack'),
            /* translators: %d: number of plugins */
            'reset' => __('Reset the preset settings of %d plugins to their defaults? Only plugins with a preset change. You can undo each one.', 'seoprostack'),
        );
        $bulk = array('apply' => self::BULK_APPLY, 'reset' => self::BULK_RESET);
        ?>
        <script>
        (function (t, bulk) {
            document.addEventListener('click', function (e) {
                var a = e.target.closest && e.target.closest('a[data-sps-confirm]');
                if (a && !window.confirm(a.getAttribute('data-sps-confirm'))) { e.preventDefault(); }
            });
            var form = document.getElementById('bulk-action-form');
            if (!form) { return; }
            form.addEventListener('submit', function (e) {
                var top = form.querySelector('#bulk-action-selector-top');
                var bottom = form.querySelector('#bulk-action-selector-bottom');
                var action = top && '-1' !== top.value ? top.value : (bottom ? bottom.value : '');
                var key = action === bulk.apply ? 'apply' : (action === bulk.reset ? 'reset' : '');
                if (!key) { return; }
                var count = form.querySelectorAll('input[name="checked[]"]:checked').length;
                if (count && !window.confirm(t[key].replace('%d', count))) { e.preventDefault(); }
            });
        })(<?php echo wp_json_encode($i18n); ?>, <?php echo wp_json_encode($bulk); ?>);
        </script>
        <?php
    }
}
