<?php
/**
 * Allstars plugin directory data.
 *
 * Fetches wordpress.org data for the curated free plugins, caches it per
 * category and renders cards with the same markup as Plugins → Add New so
 * core's `updates` script provides in-place install/update/activate.
 *
 * @package Allstars
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Allstars_Plugin_Manager {

    /** Transient prefix; bump the version to invalidate old caches. */
    const CACHE_PREFIX = 'allstars_plugins_v2_';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('wp_ajax_allstars_get_plugins', array(__CLASS__, 'ajax_get_plugins'));
    }

    /**
     * Category slug => label.
     *
     * @return array<string,string>
     */
    public static function get_category_labels() {
        $labels = array(
            'minimal'     => __('Minimal', 'allstars'),
            'admin'       => __('Admin', 'allstars'),
            'affiliates'  => __('Affiliates', 'allstars'),
            'ai'          => __('AI', 'allstars'),
            'cms'         => __('CMS', 'allstars'),
            'compliance'  => __('Compliance', 'allstars'),
            'crm'         => __('CRM', 'allstars'),
            'ecommerce'   => __('eCommerce', 'allstars'),
            'events'      => __('Events', 'allstars'),
            'lms'         => __('LMS', 'allstars'),
            'media'       => __('Media', 'allstars'),
            'members'     => __('Members', 'allstars'),
            'seo'         => __('SEO', 'allstars'),
            'setup'       => __('Setup', 'allstars'),
            'social'      => __('Social', 'allstars'),
            'speed'       => __('Speed', 'allstars'),
            'translation' => __('Translation', 'allstars'),
            'advanced'    => __('Advanced', 'allstars'),
            'debug'       => __('Debug', 'allstars'),
        );

        $categories = array_keys(allstars_get_free_plugins());
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
     * AJAX: plugin cards for one category.
     */
    public static function ajax_get_plugins() {
        check_ajax_referer(Allstars_Settings::NONCE, 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_send_json_error(array('message' => __('You are not allowed to install plugins on this site.', 'allstars')), 403);
        }

        $category   = isset($_POST['category']) ? sanitize_key(wp_unslash($_POST['category'])) : 'minimal';
        $categories = allstars_get_free_plugins();
        if (!isset($categories[$category])) {
            wp_send_json_error(array('message' => __('Unknown category.', 'allstars')), 400);
        }

        $plugins = get_transient(self::CACHE_PREFIX . $category);
        if (!is_array($plugins)) {
            $plugins = self::fetch_plugins($categories[$category]);
            if ($plugins) {
                set_transient(self::CACHE_PREFIX . $category, $plugins, 12 * HOUR_IN_SECONDS);
            }
        }

        if (!$plugins) {
            wp_send_json_error(array('message' => __('Plugin information could not be retrieved from WordPress.org.', 'allstars')), 502);
        }

        wp_send_json_success(array('html' => self::generate_plugin_cards($plugins)));
    }

    /**
     * Fetch plugin information from wordpress.org.
     *
     * @param string[] $slugs Plugin slugs.
     * @return object[]
     */
    private static function fetch_plugins(array $slugs) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $plugins = array();
        foreach (array_unique($slugs) as $slug) {
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
            }
        }

        return $plugins;
    }

    /**
     * Cards in core Plugins → Add New markup.
     *
     * @param object[] $plugins Plugin info objects.
     * @return string HTML.
     */
    public static function generate_plugin_cards(array $plugins) {
        if (!function_exists('install_plugin_install_status')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $allowed_author = array('a' => array('href' => array()));
        $wp_version     = get_bloginfo('version');

        ob_start();
        foreach ($plugins as $plugin) {
            $plugin  = (object) $plugin;
            $name    = wp_strip_all_tags($plugin->name);
            $details = self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . $plugin->slug . '&TB_iframe=true&width=600&height=550');
            $icon    = '';
            if (!empty($plugin->icons['svg'])) {
                $icon = $plugin->icons['svg'];
            } elseif (!empty($plugin->icons['2x'])) {
                $icon = $plugin->icons['2x'];
            } elseif (!empty($plugin->icons['1x'])) {
                $icon = $plugin->icons['1x'];
            } elseif (!empty($plugin->icons['default'])) {
                $icon = $plugin->icons['default'];
            }
            ?>
            <div class="plugin-card plugin-card-<?php echo esc_attr(sanitize_html_class($plugin->slug)); ?>">
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
                            <?php echo self::action_buttons($plugin, $name); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
                            <li>
                                <a href="<?php echo esc_url($details); ?>" class="thickbox open-plugin-details-modal" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: plugin name */ __('More information about %s', 'allstars'), $name)); ?>" data-title="<?php echo esc_attr($name); ?>">
                                    <?php esc_html_e('More Details', 'allstars'); ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="desc column-description">
                        <p><?php echo esc_html(isset($plugin->short_description) ? $plugin->short_description : ''); ?></p>
                        <?php if (!empty($plugin->author)) : ?>
                            <p class="authors"><cite><?php echo wp_kses(sprintf(/* translators: %s: author */ __('By %s', 'allstars'), $plugin->author), $allowed_author); ?></cite></p>
                        <?php endif; ?>
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
                            <strong><?php esc_html_e('Last Updated:', 'allstars'); ?></strong>
                            <?php echo esc_html(sprintf(/* translators: %s: time since */ __('%s ago', 'allstars'), human_time_diff(strtotime($plugin->last_updated)))); ?>
                        <?php endif; ?>
                    </div>
                    <div class="column-downloaded"><?php echo esc_html(self::installs_text(isset($plugin->active_installs) ? (int) $plugin->active_installs : 0)); ?></div>
                    <div class="column-compatibility">
                        <?php
                        if (!empty($plugin->requires) && version_compare($wp_version, $plugin->requires, '<')) {
                            echo '<span class="compatibility-incompatible">' . esc_html__('Incompatible with your version of WordPress', 'allstars') . '</span>';
                        } elseif (!empty($plugin->tested) && version_compare(substr($wp_version, 0, strlen($plugin->tested)), $plugin->tested, '>')) {
                            echo '<span class="compatibility-untested">' . esc_html__('Untested with your version of WordPress', 'allstars') . '</span>';
                        } else {
                            echo '<span class="compatibility-compatible">' . wp_kses(__('<strong>Compatible</strong> with your version of WordPress', 'allstars'), array('strong' => array())) . '</span>';
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
     * Install/Update/Activate/Active buttons plus an optional Go Pro link.
     *
     * @param object $plugin Plugin info.
     * @param string $name   Plain-text name.
     * @return string HTML list items.
     */
    private static function action_buttons($plugin, $name) {
        $html   = '';
        $status = install_plugin_install_status($plugin);

        switch ($status['status']) {
            case 'install':
                if (!empty($status['url'])) {
                    $html .= sprintf(
                        '<li><a class="install-now button" data-slug="%1$s" href="%2$s" aria-label="%3$s" data-name="%4$s">%5$s</a></li>',
                        esc_attr($plugin->slug),
                        esc_url($status['url']),
                        esc_attr(sprintf(/* translators: %s: plugin name */ _x('Install %s now', 'plugin', 'allstars'), $name)),
                        esc_attr($name),
                        esc_html_x('Install Now', 'plugin', 'allstars')
                    );
                }
                break;

            case 'update_available':
                if (!empty($status['url'])) {
                    $html .= sprintf(
                        '<li><a class="update-now button aria-button-if-js" data-plugin="%1$s" data-slug="%2$s" href="%3$s" aria-label="%4$s" data-name="%5$s">%6$s</a></li>',
                        esc_attr($status['file']),
                        esc_attr($plugin->slug),
                        esc_url($status['url']),
                        esc_attr(sprintf(/* translators: %s: plugin name */ __('Update %s now', 'allstars'), $name)),
                        esc_attr($name),
                        esc_html__('Update Now', 'allstars')
                    );
                }
                break;

            case 'latest_installed':
            case 'newer_installed':
                if (is_plugin_active($status['file'])) {
                    $html .= '<li><button type="button" class="button button-disabled" disabled="disabled">' . esc_html_x('Active', 'plugin', 'allstars') . '</button></li>';
                } elseif (current_user_can('activate_plugin', $status['file'])) {
                    $url   = wp_nonce_url(self_admin_url('plugins.php?action=activate&plugin=' . rawurlencode($status['file'])), 'activate-plugin_' . $status['file']);
                    $html .= sprintf(
                        '<li><a href="%1$s" class="button button-primary activate-now" aria-label="%2$s">%3$s</a></li>',
                        esc_url($url),
                        esc_attr(sprintf(/* translators: %s: plugin name */ _x('Activate %s', 'plugin', 'allstars'), $name)),
                        esc_html__('Activate', 'allstars')
                    );
                } else {
                    $html .= '<li><button type="button" class="button button-disabled" disabled="disabled">' . esc_html_x('Installed', 'plugin', 'allstars') . '</button></li>';
                }
                break;
        }

        $pro_url = self::get_pro_url_for_free_slug($plugin->slug);
        if ($pro_url) {
            $html .= sprintf(
                '<li><a class="button wpa-go-pro" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a></li>',
                esc_url($pro_url),
                esc_html__('Go Pro', 'allstars'),
                esc_html(sprintf(/* translators: %s: plugin name */ __('for %s (opens in a new tab)', 'allstars'), $name))
            );
        }

        return $html;
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
            return sprintf(_nx('%s+ Million Active Installation', '%s+ Million Active Installations', $millions, 'Active plugin installations', 'allstars'), number_format_i18n($millions));
        }
        if (0 === $installs) {
            return _x('Less Than 10 Active Installations', 'Active plugin installations', 'allstars');
        }
        /* translators: %s: number of installs */
        return sprintf(_n('%s+ Active Installation', '%s+ Active Installations', $installs, 'allstars'), number_format_i18n($installs));
    }

    /**
     * Pro upgrade URL for a wordpress.org slug (matches pro data by free_slug or key).
     *
     * @param string $free_slug wordpress.org slug.
     * @return string
     */
    public static function get_pro_url_for_free_slug($free_slug) {
        foreach (allstars_get_pro_plugins() as $key => $pro) {
            $matches = (isset($pro['free_slug']) && $pro['free_slug'] === $free_slug) || $key === $free_slug;
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
     * Delete cached category data.
     */
    public static function clear_plugin_cache() {
        foreach (array_keys(allstars_get_free_plugins()) as $category) {
            delete_transient(self::CACHE_PREFIX . $category);
        }
    }
}
