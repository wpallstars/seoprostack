<?php
/**
 * SEO Pro Stack plugin directory data.
 *
 * Fetches wordpress.org data for the curated free plugins, caches it per
 * category and renders cards (Plugins → Add New markup) and list rows.
 *
 * Every card and row carries the same state buttons, rendered by
 * state_buttons() here and again after each change, so the screen never
 * leaves for the Plugins screen:
 * - not installed: Install Now (core `install-plugin` AJAX via wp.updates)
 * - inactive: Activate and Uninstall (core `delete-plugin` AJAX)
 * - active: Deactivate
 * Activate and Deactivate use this class's own AJAX action, because core's
 * `activate-plugin` AJAX needs WordPress 6.5 and core has none to deactivate.
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_Manager {

    /** Transient prefix; bump the version to invalidate old caches. */
    const CACHE_PREFIX = 'seoprostack_plugins_v3_';

    /** Category slug for the list of every recommended plugin. */
    const ALL = 'all';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('wp_ajax_seoprostack_get_plugins', array(__CLASS__, 'ajax_get_plugins'));
        add_action('wp_ajax_seoprostack_plugin_action', array(__CLASS__, 'ajax_plugin_action'));
    }

    /**
     * Category slug => label.
     *
     * @return array<string,string>
     */
    public static function get_category_labels() {
        $labels = array(
            'minimal'     => __('Minimal', 'seoprostack'),
            'admin'       => __('Admin', 'seoprostack'),
            'affiliates'  => __('Affiliates', 'seoprostack'),
            'ai'          => __('AI', 'seoprostack'),
            'cms'         => __('CMS', 'seoprostack'),
            'compliance'  => __('Compliance', 'seoprostack'),
            'crm'         => __('CRM', 'seoprostack'),
            'ecommerce'   => __('eCommerce', 'seoprostack'),
            'events'      => __('Events', 'seoprostack'),
            'lms'         => __('LMS', 'seoprostack'),
            'media'       => __('Media', 'seoprostack'),
            'members'     => __('Members', 'seoprostack'),
            'seo'         => __('SEO', 'seoprostack'),
            'setup'       => __('Setup', 'seoprostack'),
            'social'      => __('Social', 'seoprostack'),
            'speed'       => __('Speed', 'seoprostack'),
            'translation' => __('Translation', 'seoprostack'),
            'advanced'    => __('Advanced', 'seoprostack'),
            'debug'       => __('Debug', 'seoprostack'),
        );

        $categories = array_keys(seoprostack_get_free_plugins());
        $ordered    = array();
        foreach ($labels as $slug => $label) {
            if (in_array($slug, $categories, true)) {
                $ordered[$slug] = $label;
            }
        }
        foreach ($categories as $slug) {
            if (!isset($ordered[$slug])) {
                $ordered[$slug] = ucwords(str_replace(array('-', '_'), ' ', $slug));
            }
        }

        return $ordered;
    }

    /**
     * Every recommended slug, once.
     *
     * @return string[]
     */
    private static function curated_slugs() {
        $slugs = array();
        foreach (seoprostack_get_free_plugins() as $list) {
            foreach ($list as $slug) {
                $slugs[$slug] = true;
            }
        }
        return array_keys($slugs);
    }

    /**
     * AJAX: plugin cards (or list rows) for one category.
     */
    public static function ajax_get_plugins() {
        check_ajax_referer(SEOProStack_Settings::NONCE, 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_send_json_error(array('message' => __('You are not allowed to install plugins on this site.', 'seoprostack')), 403);
        }

        $category   = isset($_POST['category']) ? sanitize_key(wp_unslash($_POST['category'])) : 'minimal';
        $view       = isset($_POST['view']) && 'rows' === sanitize_key(wp_unslash($_POST['view'])) ? 'rows' : 'cards';
        $categories = seoprostack_get_free_plugins();
        if (!isset($categories[$category])) {
            wp_send_json_error(array('message' => __('Unknown category.', 'seoprostack')), 400);
        }

        $cache_key = self::cache_key($category, $categories[$category]);
        $plugins   = get_transient($cache_key);
        if (!is_array($plugins)) {
            $complete = true;
            $plugins  = self::fetch_plugins($categories[$category], $complete);
            // Don't pin a partial list from a network hiccup for 12 hours.
            if ($plugins && $complete) {
                set_transient($cache_key, $plugins, 12 * HOUR_IN_SECONDS);
            }
        }

        if (!$plugins) {
            wp_send_json_error(array('message' => __('Plugin information could not be retrieved from WordPress.org.', 'seoprostack')), 502);
        }

        $html = 'rows' === $view ? self::generate_plugin_rows($plugins, $category) : self::generate_plugin_cards($plugins);
        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX: activate, deactivate, or report the state of recommended plugins.
     *
     * Only slugs from admin/data/free-plugins.php are accepted, so this can
     * never switch off SEO Pro Stack or any plugin the site added itself.
     * Install and Uninstall go through core's own AJAX actions instead.
     */
    public static function ajax_plugin_action() {
        check_ajax_referer(SEOProStack_Settings::NONCE, 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_send_json_error(array('message' => __('You are not allowed to manage plugins on this site.', 'seoprostack')), 403);
        }

        self::load_admin_includes();

        $do      = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $curated = self::curated_slugs();

        if ('state' === $do) {
            $asked  = isset($_POST['slugs']) ? array_map('sanitize_key', (array) wp_unslash($_POST['slugs'])) : array();
            $states = array();
            foreach (array_intersect(array_unique($asked), $curated) as $slug) {
                $states[] = self::state_response($slug);
            }
            wp_send_json_success(array('states' => $states));
        }

        $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
        if (!in_array($slug, $curated, true)) {
            wp_send_json_error(array('message' => __('This plugin is not on the recommended list.', 'seoprostack')), 400);
        }

        $file = self::installed_file($slug);
        if (!$file) {
            self::send_action_error($slug, __('This plugin is not installed.', 'seoprostack'));
        }

        if ('activate' === $do) {
            if (!current_user_can('activate_plugin', $file)) {
                self::send_action_error($slug, __('You are not allowed to activate this plugin.', 'seoprostack'), 403);
            }
            if (!is_plugin_active($file)) {
                // Activation may print output or warnings; keep the JSON clean.
                ob_start();
                $result = activate_plugin($file);
                ob_end_clean();
                // Unexpected output still activates the plugin, as on the Plugins screen.
                if (is_wp_error($result) && 'unexpected_output' !== $result->get_error_code()) {
                    self::send_action_error($slug, wp_strip_all_tags($result->get_error_message()));
                }
            }
        } elseif ('deactivate' === $do) {
            if (!current_user_can('deactivate_plugin', $file)) {
                self::send_action_error($slug, __('You are not allowed to deactivate this plugin.', 'seoprostack'), 403);
            }
            if (is_multisite() && is_plugin_active_for_network($file)) {
                self::send_action_error($slug, __('This plugin is active for the whole network. Deactivate it in Network Admin → Plugins.', 'seoprostack'));
            }
            // WordPress 6.5+: plugins can require others.
            if (class_exists('WP_Plugin_Dependencies') && method_exists('WP_Plugin_Dependencies', 'has_active_dependents') && WP_Plugin_Dependencies::has_active_dependents($file)) {
                self::send_action_error($slug, __('Other active plugins need this one. Deactivate them first.', 'seoprostack'));
            }
            ob_start();
            deactivate_plugins($file);
            ob_end_clean();
        } else {
            wp_send_json_error(array('message' => __('Unknown action.', 'seoprostack')), 400);
        }

        wp_send_json_success(array('state' => self::state_response($slug)));
    }

    /**
     * Send an action error with the plugin's current state, so the screen
     * shows what is actually true.
     *
     * @param string $slug    Plugin slug.
     * @param string $message Error message.
     * @param int    $code    HTTP status.
     */
    private static function send_action_error($slug, $message, $code = 200) {
        wp_send_json_error(array(
            'message' => $message,
            'state'   => self::state_response($slug),
        ), $code);
    }

    /**
     * Admin functions the AJAX handlers and renderers need.
     */
    private static function load_admin_includes() {
        if (!function_exists('get_plugins') || !function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
    }

    /**
     * Fetch plugin information from wordpress.org.
     *
     * Known-closed slugs skip the API. Slugs the API reports as closed or
     * missing become "unavailable" stubs rather than silently vanishing.
     *
     * @param string[] $slugs    Plugin slugs.
     * @param bool     $complete Set to false when a request failed for another reason.
     * @return object[]
     */
    private static function fetch_plugins(array $slugs, &$complete = true) {
        self::load_admin_includes();

        $removed  = seoprostack_get_removed_plugins();
        $plugins  = array();
        $complete = true;
        foreach (array_unique($slugs) as $slug) {
            if (isset($removed[$slug])) {
                $plugins[] = self::removed_stub($slug, $removed[$slug]);
                continue;
            }

            $info = plugins_api('plugin_information', array(
                'slug'   => $slug,
                'fields' => array(
                    'short_description' => true,
                    'sections'          => false,
                    'icons'             => true,
                    'active_installs'   => true,
                    'last_updated'      => true,
                    'rating'            => true,
                    'num_ratings'       => true,
                    'requires'          => true,
                    'requires_php'      => true,
                    'tested'            => true,
                    'banners'           => false,
                    'reviews'           => false,
                    'versions'          => false,
                    'contributors'      => false,
                    'donate_link'       => false,
                    'tags'              => false,
                ),
            ));

            if (!is_wp_error($info) && !empty($info->slug)) {
                $plugins[] = $info;
            } elseif (is_wp_error($info) && in_array($info->get_error_message(), array('closed', 'Plugin not found.'), true)) {
                $plugins[] = self::removed_stub($slug, array());
            } else {
                $complete = false;
            }
        }

        return $plugins;
    }

    /**
     * Card data for a plugin that is no longer on wordpress.org.
     *
     * @param string $slug wordpress.org slug.
     * @param array  $data Entry from seoprostack_get_removed_plugins(), or empty.
     * @return object
     */
    private static function removed_stub($slug, array $data) {
        return (object) array(
            'slug'              => $slug,
            'name'              => !empty($data['name']) ? $data['name'] : ucwords(str_replace('-', ' ', $slug)),
            'short_description' => isset($data['description']) ? $data['description'] : '',
            'removed'           => true,
            'closed'            => isset($data['closed']) ? $data['closed'] : '',
            'reason'            => isset($data['reason']) ? $data['reason'] : '',
            'replacement'       => isset($data['replacement']) ? $data['replacement'] : '',
        );
    }

    /**
     * Cached wordpress.org data for one slug, from any category's cache.
     *
     * The state endpoint has no API response to hand; this reuses the cards'
     * cache for the name and compatibility, and the removed list otherwise.
     *
     * @param string $slug Plugin slug.
     * @return object
     */
    private static function info_for($slug) {
        foreach (seoprostack_get_free_plugins() as $category => $slugs) {
            if (!in_array($slug, $slugs, true)) {
                continue;
            }
            $cached = get_transient(self::cache_key($category, $slugs));
            if (!is_array($cached)) {
                continue;
            }
            foreach ($cached as $plugin) {
                $plugin = (object) $plugin;
                if (isset($plugin->slug) && $plugin->slug === $slug) {
                    return $plugin;
                }
            }
        }

        $removed = seoprostack_get_removed_plugins();
        if (isset($removed[$slug])) {
            return self::removed_stub($slug, $removed[$slug]);
        }

        $file = self::installed_file($slug);
        $name = '';
        if ($file) {
            $all  = get_plugins();
            $name = isset($all[$file]['Name']) ? $all[$file]['Name'] : '';
        }
        return (object) array(
            'slug' => $slug,
            'name' => $name ? $name : ucwords(str_replace('-', ' ', $slug)),
        );
    }

    /**
     * Installed plugin file for a slug, if any.
     *
     * @param string $slug Plugin directory slug.
     * @return string Plugin file relative to the plugins directory, or ''.
     */
    private static function installed_file($slug) {
        foreach (array_keys(get_plugins()) as $file) {
            if (0 === strpos($file, $slug . '/')) {
                return $file;
            }
        }
        return '';
    }

    /**
     * A plugin's state on this site.
     *
     * Status: active, network-active, inactive, not-installed, or, when not
     * installed, incompatible (needs a newer WordPress or PHP) or unavailable
     * (closed on WordPress.org).
     *
     * @param string      $slug   Plugin slug.
     * @param object|null $plugin Plugin info or stub, to tell why it cannot be installed.
     * @return array{status:string,file:string,update:bool}
     */
    private static function plugin_state($slug, $plugin = null) {
        $file  = self::installed_file($slug);
        $state = array(
            'status' => 'not-installed',
            'file'   => $file,
            'update' => false,
        );
        if (!$file) {
            // Needs a newer WordPress or PHP: nothing to install here.
            if ($plugin && empty($plugin->removed)) {
                $requires_wp  = isset($plugin->requires) ? $plugin->requires : '';
                $requires_php = isset($plugin->requires_php) ? $plugin->requires_php : '';
                if (!is_wp_version_compatible($requires_wp) || !is_php_version_compatible($requires_php)) {
                    $state['status'] = 'incompatible';
                }
            } elseif ($plugin) {
                $state['status'] = 'unavailable';
            }
            return $state;
        }

        if (is_multisite() && is_plugin_active_for_network($file)) {
            $state['status'] = 'network-active';
        } elseif (is_plugin_active($file)) {
            $state['status'] = 'active';
        } else {
            $state['status'] = 'inactive';
        }

        $updates         = get_site_transient('update_plugins');
        $state['update'] = is_object($updates) && isset($updates->response[$file]);

        return $state;
    }

    /**
     * Short status text for list rows.
     *
     * @param array $state From plugin_state().
     * @return string
     */
    private static function status_label(array $state) {
        switch ($state['status']) {
            case 'active':
                return _x('Active', 'plugin', 'seoprostack');
            case 'network-active':
                return _x('Network active', 'plugin', 'seoprostack');
            case 'inactive':
                return _x('Inactive', 'plugin', 'seoprostack');
            case 'incompatible':
                return __('Needs a newer WordPress or PHP', 'seoprostack');
            case 'unavailable':
                return __('Unavailable', 'seoprostack');
        }
        return __('Not installed', 'seoprostack');
    }

    /**
     * Whether a bulk action could change this plugin, so it can be selected.
     *
     * @param array $state From plugin_state().
     * @return bool
     */
    private static function selectable(array $state) {
        return !in_array($state['status'], array('incompatible', 'unavailable', 'network-active'), true);
    }

    /**
     * State, buttons and label for one slug, as the JS applies them.
     *
     * @param string $slug Plugin slug.
     * @return array
     */
    private static function state_response($slug) {
        $plugin = self::info_for($slug);
        $state  = self::plugin_state($slug, $plugin);
        $name   = wp_strip_all_tags($plugin->name);

        return array(
            'slug'   => $slug,
            'status' => $state['status'],
            'file'   => $state['file'],
            'label'  => self::status_label($state),
            'usable' => self::selectable($state),
            'html'   => self::state_buttons($plugin, $state, $name),
        );
    }

    /**
     * Data attributes the JS reads from a card or row.
     *
     * @param object $plugin Plugin info or stub.
     * @param array  $state  From plugin_state().
     * @param string $name   Plain-text name.
     * @return string Escaped attributes.
     */
    private static function item_attributes($plugin, array $state, $name) {
        return sprintf(
            'data-sps-plugin="%1$s" data-status="%2$s" data-file="%3$s" data-name="%4$s"',
            esc_attr($plugin->slug),
            esc_attr($state['status']),
            esc_attr($state['file']),
            esc_attr($name)
        );
    }

    /**
     * Card for a plugin WordPress.org no longer serves: no install, clear status.
     *
     * @param object $plugin Stub from removed_stub().
     */
    private static function removed_card($plugin) {
        $state = self::plugin_state($plugin->slug, $plugin);
        $name  = wp_strip_all_tags($plugin->name);

        if ($plugin->closed) {
            $status = sprintf(
                /* translators: %s: closure date */
                __('Removed from WordPress.org on %s.', 'seoprostack'),
                date_i18n(get_option('date_format'), strtotime($plugin->closed))
            );
        } else {
            $status = __('Not currently available from WordPress.org.', 'seoprostack');
        }
        $pro_url = self::get_pro_url_for_free_slug($plugin->slug);
        ?>
        <div class="plugin-card plugin-card-<?php echo esc_attr(sanitize_html_class($plugin->slug)); ?> sps-plugin-removed" <?php echo self::item_attributes($plugin, $state, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>>
            <div class="plugin-card-top">
                <div class="name column-name">
                    <h3><?php echo esc_html($plugin->name); ?> <span class="sps-removed-icon dashicons dashicons-warning" aria-hidden="true"></span></h3>
                </div>
                <div class="action-links">
                    <ul class="plugin-action-buttons">
                        <?php echo self::state_buttons($plugin, $state, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
                        <?php if ($pro_url) : ?>
                            <li><a class="button" href="<?php echo esc_url($pro_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Vendor Site', 'seoprostack'); ?><span class="screen-reader-text"> <?php echo esc_html(sprintf(/* translators: %s: plugin name */ __('for %s (opens in a new tab)', 'seoprostack'), $plugin->name)); ?></span></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="desc column-description">
                    <?php if ($plugin->short_description) : ?>
                        <p><?php echo esc_html($plugin->short_description); ?></p>
                    <?php endif; ?>
                    <?php if ($plugin->replacement) : ?>
                        <p><em><?php echo esc_html($plugin->replacement); ?></em></p>
                    <?php endif; ?>
                    <p class="sps-plugin-message" data-sps-plugin-message role="alert" hidden></p>
                </div>
            </div>
            <div class="plugin-card-bottom">
                <p class="sps-removed-status">
                    <strong><?php echo esc_html($status); ?></strong>
                    <?php echo esc_html($plugin->reason); ?>
                    <?php if ($state['file']) : ?>
                        <?php esc_html_e('It will not receive updates; plan a replacement.', 'seoprostack'); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Best icon URL from plugin info.
     *
     * @param object $plugin Plugin info.
     * @return string
     */
    private static function icon_url($plugin) {
        $icons = isset($plugin->icons) ? (array) $plugin->icons : array();
        foreach (array('svg', '2x', '1x', 'default') as $size) {
            if (!empty($icons[$size])) {
                return $icons[$size];
            }
        }
        return '';
    }

    /**
     * Core's plugin details modal URL.
     *
     * @param string $slug Plugin slug.
     * @return string
     */
    private static function details_url($slug) {
        return self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . $slug . '&TB_iframe=true&width=600&height=550');
    }

    /**
     * Cards in core Plugins → Add New markup.
     *
     * @param object[] $plugins Plugin info objects.
     * @return string HTML.
     */
    public static function generate_plugin_cards(array $plugins) {
        self::load_admin_includes();

        $allowed_author = array('a' => array('href' => array()));
        $wp_version     = get_bloginfo('version');

        ob_start();
        foreach ($plugins as $plugin) {
            $plugin = (object) $plugin;
            if (!empty($plugin->removed)) {
                self::removed_card($plugin);
                continue;
            }
            $name    = wp_strip_all_tags($plugin->name);
            $details = self::details_url($plugin->slug);
            $icon    = self::icon_url($plugin);
            $state   = self::plugin_state($plugin->slug, $plugin);
            $pro_url = self::get_pro_url_for_free_slug($plugin->slug);
            ?>
            <div class="plugin-card plugin-card-<?php echo esc_attr(sanitize_html_class($plugin->slug)); ?>" <?php echo self::item_attributes($plugin, $state, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>>
                <div class="plugin-card-top">
                    <div class="name column-name">
                        <h3>
                            <a href="<?php echo esc_url($details); ?>" class="thickbox open-plugin-details-modal">
                                <?php echo esc_html($name); ?>
                                <?php if ($icon) : ?>
                                    <img src="<?php echo esc_url($icon); ?>" class="plugin-icon" alt="" loading="lazy" />
                                <?php endif; ?>
                            </a>
                        </h3>
                    </div>
                    <div class="action-links">
                        <ul class="plugin-action-buttons">
                            <?php echo self::state_buttons($plugin, $state, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
                            <?php if ($pro_url) : ?>
                                <li><?php echo self::pro_link($pro_url, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></li>
                            <?php endif; ?>
                            <li>
                                <a href="<?php echo esc_url($details); ?>" class="thickbox open-plugin-details-modal" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: plugin name */ __('More information about %s', 'seoprostack'), $name)); ?>" data-title="<?php echo esc_attr($name); ?>">
                                    <?php esc_html_e('More Details', 'seoprostack'); ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="desc column-description">
                        <p><?php echo esc_html(isset($plugin->short_description) ? $plugin->short_description : ''); ?></p>
                        <?php if (!empty($plugin->author)) : ?>
                            <p class="authors"><cite><?php echo wp_kses(sprintf(/* translators: %s: author */ __('By %s', 'seoprostack'), $plugin->author), $allowed_author); ?></cite></p>
                        <?php endif; ?>
                        <p class="sps-plugin-message" data-sps-plugin-message role="alert" hidden></p>
                    </div>
                </div>
                <div class="plugin-card-bottom">
                    <div class="vers column-rating">
                        <?php
                        if (function_exists('wp_star_rating')) {
                            wp_star_rating(array(
                                'rating' => isset($plugin->rating) ? $plugin->rating : 0,
                                'type'   => 'percent',
                                'number' => isset($plugin->num_ratings) ? $plugin->num_ratings : 0,
                            ));
                        }
                        ?>
                        <span class="num-ratings" aria-hidden="true">(<?php echo esc_html(number_format_i18n(isset($plugin->num_ratings) ? $plugin->num_ratings : 0)); ?>)</span>
                    </div>
                    <div class="column-updated">
                        <?php if (!empty($plugin->last_updated)) : ?>
                            <strong><?php esc_html_e('Last Updated:', 'seoprostack'); ?></strong>
                            <?php echo esc_html(sprintf(/* translators: %s: time since */ __('%s ago', 'seoprostack'), human_time_diff(strtotime($plugin->last_updated)))); ?>
                        <?php endif; ?>
                    </div>
                    <div class="column-downloaded"><?php echo esc_html(self::installs_text(isset($plugin->active_installs) ? (int) $plugin->active_installs : 0)); ?></div>
                    <div class="column-compatibility">
                        <?php
                        if (!empty($plugin->requires) && version_compare($wp_version, $plugin->requires, '<')) {
                            echo '<span class="compatibility-incompatible">' . esc_html__('Incompatible with your version of WordPress', 'seoprostack') . '</span>';
                        } elseif (!empty($plugin->tested) && version_compare(substr($wp_version, 0, strlen($plugin->tested)), $plugin->tested, '>')) {
                            echo '<span class="compatibility-untested">' . esc_html__('Untested with your version of WordPress', 'seoprostack') . '</span>';
                        } else {
                            echo '<span class="compatibility-compatible">' . wp_kses(__('<strong>Compatible</strong> with your version of WordPress', 'seoprostack'), array('strong' => array())) . '</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Compact table rows for the All list, one per plugin.
     *
     * @param object[] $plugins  Plugin info objects.
     * @param string   $category Category slug, for unique checkbox ids.
     * @return string HTML.
     */
    public static function generate_plugin_rows(array $plugins, $category) {
        self::load_admin_includes();

        ob_start();
        foreach ($plugins as $plugin) {
            $plugin  = (object) $plugin;
            $name    = wp_strip_all_tags($plugin->name);
            $state   = self::plugin_state($plugin->slug, $plugin);
            $icon    = empty($plugin->removed) ? self::icon_url($plugin) : '';
            $id      = 'sps-plugin-' . $category . '-' . $plugin->slug;
            $usable  = self::selectable($state);
            $desc    = isset($plugin->short_description) ? $plugin->short_description : '';
            $pro_url = self::get_pro_url_for_free_slug($plugin->slug);
            ?>
            <tr class="sps-plugin-row plugin-card-<?php echo esc_attr(sanitize_html_class($plugin->slug)); ?>" <?php echo self::item_attributes($plugin, $state, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>>
                <th scope="row" class="check-column">
                    <label class="screen-reader-text" for="<?php echo esc_attr($id); ?>"><?php echo esc_html(sprintf(/* translators: %s: plugin name */ __('Select %s', 'seoprostack'), $name)); ?></label>
                    <input type="checkbox" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($plugin->slug); ?>" data-sps-plugin-check <?php disabled(!$usable); ?> />
                </th>
                <td class="sps-plugin-row__name">
                    <span class="sps-plugin-row__icon" aria-hidden="true">
                        <?php if ($icon) : ?>
                            <img src="<?php echo esc_url($icon); ?>" alt="" width="32" height="32" loading="lazy" />
                        <?php else : ?>
                            <span class="dashicons <?php echo empty($plugin->removed) ? 'dashicons-admin-plugins' : 'dashicons-warning'; ?>"></span>
                        <?php endif; ?>
                    </span>
                    <span class="sps-plugin-row__text">
                        <?php if (empty($plugin->removed)) : ?>
                            <a class="sps-plugin-row__title thickbox open-plugin-details-modal" href="<?php echo esc_url(self::details_url($plugin->slug)); ?>" data-title="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></a>
                        <?php else : ?>
                            <strong class="sps-plugin-row__title"><?php echo esc_html($name); ?></strong>
                        <?php endif; ?>
                        <?php if ($desc) : ?>
                            <span class="sps-plugin-row__desc"><?php echo esc_html($desc); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($plugin->removed) && !empty($plugin->replacement)) : ?>
                            <em class="sps-plugin-row__desc"><?php echo esc_html($plugin->replacement); ?></em>
                        <?php endif; ?>
                    </span>
                </td>
                <td class="sps-plugin-row__status">
                    <span data-sps-plugin-status><?php echo esc_html(self::status_label($state)); ?></span>
                    <span class="sps-plugin-message" data-sps-plugin-message role="alert" hidden></span>
                </td>
                <td class="sps-plugin-row__actions">
                    <ul class="plugin-action-buttons">
                        <?php echo self::state_buttons($plugin, $state, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
                        <?php if ($pro_url) : ?>
                            <li><?php echo self::pro_link($pro_url, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></li>
                        <?php endif; ?>
                    </ul>
                </td>
            </tr>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * One state button list item.
     *
     * @param string $html Escaped button markup.
     * @return string
     */
    private static function state_item($html) {
        return '<li class="sps-state">' . $html . '</li>';
    }

    /**
     * A disabled button showing a state that cannot be changed here.
     *
     * @param string $text Button text.
     * @return string
     */
    private static function disabled_button($text) {
        return self::state_item('<button type="button" class="button button-disabled" disabled="disabled">' . esc_html($text) . '</button>');
    }

    /**
     * A button the JS runs in place.
     *
     * @param string $action  install|activate|deactivate|uninstall.
     * @param string $classes Extra classes.
     * @param string $text    Button text.
     * @param string $label   Accessible label naming the plugin.
     * @param object $plugin  Plugin info.
     * @param array  $state   From plugin_state().
     * @return string
     */
    private static function action_button($action, $classes, $text, $label, $plugin, array $state) {
        return self::state_item(sprintf(
            '<button type="button" class="button %1$s" data-sps-plugin-action="%2$s" data-slug="%3$s" data-plugin="%4$s" aria-label="%5$s">%6$s</button>',
            esc_attr($classes),
            esc_attr($action),
            esc_attr($plugin->slug),
            esc_attr($state['file']),
            esc_attr($label),
            esc_html($text)
        ));
    }

    /**
     * Install / Update / Activate / Deactivate / Uninstall buttons for a state.
     *
     * @param object $plugin Plugin info or stub.
     * @param array  $state  From plugin_state().
     * @param string $name   Plain-text name.
     * @return string HTML list items.
     */
    private static function state_buttons($plugin, array $state, $name) {
        $file = $state['file'];
        $html = '';

        if ('unavailable' === $state['status']) {
            return self::disabled_button(__('Unavailable', 'seoprostack'));
        }
        if ('incompatible' === $state['status']) {
            return self::disabled_button(_x('Cannot Install', 'plugin', 'seoprostack'));
        }
        if ('not-installed' === $state['status']) {
            return self::action_button(
                'install',
                '',
                _x('Install Now', 'plugin', 'seoprostack'),
                sprintf(/* translators: %s: plugin name */ _x('Install %s now', 'plugin', 'seoprostack'), $name),
                $plugin,
                $state
            );
        }

        // Core's updates script handles .update-now inside #plugin-filter.
        if ($state['update'] && current_user_can('update_plugins')) {
            $html .= self::state_item(sprintf(
                '<a class="update-now button aria-button-if-js" data-plugin="%1$s" data-slug="%2$s" href="%3$s" aria-label="%4$s" data-name="%5$s">%6$s</a>',
                esc_attr($file),
                esc_attr($plugin->slug),
                esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($file)), 'upgrade-plugin_' . $file)),
                esc_attr(sprintf(/* translators: %s: plugin name */ __('Update %s now', 'seoprostack'), $name)),
                esc_attr($name),
                esc_html__('Update Now', 'seoprostack')
            ));
        }

        switch ($state['status']) {
            case 'network-active':
                $html .= self::disabled_button(_x('Network Active', 'plugin', 'seoprostack'));
                break;

            case 'active':
                if (current_user_can('deactivate_plugin', $file)) {
                    $html .= self::action_button(
                        'deactivate',
                        '',
                        __('Deactivate', 'seoprostack'),
                        sprintf(/* translators: %s: plugin name */ _x('Deactivate %s', 'plugin', 'seoprostack'), $name),
                        $plugin,
                        $state
                    );
                } else {
                    $html .= self::disabled_button(_x('Active', 'plugin', 'seoprostack'));
                }
                break;

            default: // inactive
                if (current_user_can('activate_plugin', $file)) {
                    $html .= self::action_button(
                        'activate',
                        'button-primary',
                        __('Activate', 'seoprostack'),
                        sprintf(/* translators: %s: plugin name */ _x('Activate %s', 'plugin', 'seoprostack'), $name),
                        $plugin,
                        $state
                    );
                } else {
                    $html .= self::disabled_button(_x('Installed', 'plugin', 'seoprostack'));
                }
                if (current_user_can('delete_plugins')) {
                    $html .= self::action_button(
                        'uninstall',
                        'sps-uninstall',
                        __('Uninstall', 'seoprostack'),
                        sprintf(/* translators: %s: plugin name */ _x('Uninstall %s', 'plugin', 'seoprostack'), $name),
                        $plugin,
                        $state
                    );
                }
                break;
        }

        return $html;
    }

    /**
     * Go Pro link.
     *
     * @param string $url  Vendor URL.
     * @param string $name Plain-text name.
     * @return string
     */
    private static function pro_link($url, $name) {
        return sprintf(
            '<a class="button sps-go-pro" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
            esc_url($url),
            esc_html__('Go Pro', 'seoprostack'),
            esc_html(sprintf(/* translators: %s: plugin name */ __('for %s (opens in a new tab)', 'seoprostack'), $name))
        );
    }

    /**
     * Active installs text (core wording).
     *
     * @param int $installs Active installs.
     * @return string
     */
    private static function installs_text($installs) {
        if ($installs >= 1000000) {
            $millions = (int) floor($installs / 1000000);
            /* translators: %s: number of millions */
            return sprintf(_nx('%s+ Million Active Installation', '%s+ Million Active Installations', $millions, 'Active plugin installations', 'seoprostack'), number_format_i18n($millions));
        }
        if (0 === $installs) {
            return _x('Less Than 10 Active Installations', 'Active plugin installations', 'seoprostack');
        }
        /* translators: %s: number of installs */
        return sprintf(_n('%s+ Active Installation', '%s+ Active Installations', $installs, 'seoprostack'), number_format_i18n($installs));
    }

    /**
     * Pro upgrade URL for a wordpress.org slug (matches pro data by free_slug or key).
     *
     * @param string $free_slug wordpress.org slug.
     * @return string
     */
    public static function get_pro_url_for_free_slug($free_slug) {
        foreach (seoprostack_get_pro_plugins() as $key => $pro) {
            // free_slug may be one slug or a list of related free plugins.
            $matches = (isset($pro['free_slug']) && in_array($free_slug, (array) $pro['free_slug'], true)) || $key === $free_slug;
            if ($matches) {
                return self::get_pro_plugin_url($pro);
            }
        }
        return '';
    }

    /**
     * Primary URL from a pro item.
     *
     * @param array $pro_plugin Pro item.
     * @return string
     */
    public static function get_pro_plugin_url($pro_plugin) {
        if (!empty($pro_plugin['button_group']) && is_array($pro_plugin['button_group'])) {
            foreach ($pro_plugin['button_group'] as $button) {
                if (!empty($button['primary']) && !empty($button['url'])) {
                    return $button['url'];
                }
            }
            if (!empty($pro_plugin['button_group'][0]['url'])) {
                return $pro_plugin['button_group'][0]['url'];
            }
        }
        return !empty($pro_plugin['url']) ? $pro_plugin['url'] : '';
    }

    /**
     * Transient name for a category's cards.
     *
     * The name includes a hash of the category's slugs, so adding or
     * removing a plugin in admin/data/free-plugins.php shows at once
     * instead of after the cache expires.
     *
     * @param string   $category Category slug.
     * @param string[] $slugs    Plugin slugs in the category.
     * @return string
     */
    private static function cache_key($category, array $slugs) {
        return self::CACHE_PREFIX . $category . '_' . substr(md5(implode(',', $slugs)), 0, 8);
    }

    /**
     * Delete cached category data.
     */
    public static function clear_plugin_cache() {
        foreach (seoprostack_get_free_plugins() as $category => $slugs) {
            delete_transient(self::cache_key($category, $slugs));
        }
    }
}
