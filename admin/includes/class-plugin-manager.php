<?php
/**
 * SEO Pro Stack plugin directory data.
 *
 * Fetches wordpress.org data for the curated free plugins, caches it per
 * category and renders cards with the same markup as Plugins → Add New so
 * core's `updates` script provides in-place install/update/activate.
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

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('wp_ajax_seoprostack_get_plugins', array(__CLASS__, 'ajax_get_plugins'));
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
     * AJAX: plugin cards for one category.
     */
    public static function ajax_get_plugins() {
        check_ajax_referer(SEOProStack_Settings::NONCE, 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_send_json_error(array('message' => __('You are not allowed to install plugins on this site.', 'seoprostack')), 403);
        }

        $category   = isset($_POST['category']) ? sanitize_key(wp_unslash($_POST['category'])) : 'minimal';
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

        wp_send_json_success(array('html' => self::generate_plugin_cards($plugins)));
    }

    /**
     * Recommended plugins that are not on WordPress.org.
     *
     * @return array<string,array> Slug => name, description, author, url
     *     (home page), file (installed plugin file or ''), network (activate
     *     network-wide), install_url (installs and activates; '' hides the
     *     button), requires_php and source (where it comes from).
     */
    public static function external_plugins() {
        /**
         * Filter the recommended plugins that are not on WordPress.org.
         * List their slugs with `seoprostack_free_plugins`.
         *
         * @param array<string,array> $plugins Slug => card data.
         */
        return (array) apply_filters('seoprostack_external_plugins', array());
    }

    /**
     * Fetch plugin information from wordpress.org.
     *
     * Known-closed slugs skip the API. Slugs the API reports as closed or
     * missing become "unavailable" stubs rather than silently vanishing.
     * Plugins from elsewhere get a stub; their card is filled in when shown,
     * since it carries nonces and the install state.
     *
     * @param string[] $slugs    Plugin slugs.
     * @param bool     $complete Set to false when a request failed for another reason.
     * @return object[]
     */
    private static function fetch_plugins(array $slugs, &$complete = true) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $removed  = seoprostack_get_removed_plugins();
        $external = self::external_plugins();
        $plugins  = array();
        $complete = true;
        foreach (array_unique($slugs) as $slug) {
            if (isset($external[$slug])) {
                $plugins[] = (object) array('slug' => $slug, 'external' => true);
                continue;
            }
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
     * Card for a plugin WordPress.org no longer serves: no install, clear status.
     *
     * @param object $plugin Stub from removed_stub().
     */
    private static function removed_card($plugin) {
        $file = self::installed_file($plugin->slug);
        if ($file && is_plugin_active($file)) {
            $state = _x('Active', 'plugin', 'seoprostack');
        } elseif ($file) {
            $state = _x('Installed', 'plugin', 'seoprostack');
        } else {
            $state = __('Unavailable', 'seoprostack');
        }

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
        <div class="plugin-card plugin-card-<?php echo esc_attr(sanitize_html_class($plugin->slug)); ?> sps-plugin-removed">
            <div class="plugin-card-top">
                <div class="name column-name">
                    <h3><?php echo esc_html($plugin->name); ?> <span class="sps-removed-icon dashicons dashicons-warning" aria-hidden="true"></span></h3>
                </div>
                <div class="action-links">
                    <ul class="plugin-action-buttons">
                        <li><button type="button" class="button button-disabled" disabled="disabled"><?php echo esc_html($state); ?></button></li>
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
                </div>
            </div>
            <div class="plugin-card-bottom">
                <p class="sps-removed-status">
                    <strong><?php echo esc_html($status); ?></strong>
                    <?php echo esc_html($plugin->reason); ?>
                    <?php if ($file) : ?>
                        <?php esc_html_e('It will not receive updates; plan a replacement.', 'seoprostack'); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Card for a plugin from outside WordPress.org (see external_plugins()).
     * Its buttons are plain links: core's install script only handles
     * WordPress.org slugs.
     *
     * @param string $slug Slug.
     * @param array  $data Card data.
     */
    private static function external_card($slug, array $data) {
        $name    = isset($data['name']) ? wp_strip_all_tags((string) $data['name']) : $slug;
        $file    = isset($data['file']) ? (string) $data['file'] : '';
        $network = !empty($data['network']);
        $url     = isset($data['url']) ? (string) $data['url'] : '';
        $php     = isset($data['requires_php']) ? (string) $data['requires_php'] : '';

        $active = $file && ($network ? is_plugin_active_for_network($file) : is_plugin_active($file));
        if ($active) {
            $button = '<button type="button" class="button button-disabled" disabled="disabled">' . esc_html_x('Active', 'plugin', 'seoprostack') . '</button>';
        } elseif ($file && current_user_can($network ? 'manage_network_plugins' : 'activate_plugin', $file)) {
            $base   = $network ? network_admin_url('plugins.php') : admin_url('plugins.php');
            $link   = wp_nonce_url(add_query_arg(array('action' => 'activate', 'plugin' => rawurlencode($file)), $base), 'activate-plugin_' . $file);
            $button = sprintf(
                '<a href="%1$s" class="button button-primary" aria-label="%2$s">%3$s</a>',
                esc_url($link),
                esc_attr(sprintf(/* translators: %s: plugin name */ _x('Activate %s', 'plugin', 'seoprostack'), $name)),
                $network ? esc_html__('Network Activate', 'seoprostack') : esc_html__('Activate', 'seoprostack')
            );
        } elseif ($file) {
            $button = '<button type="button" class="button button-disabled" disabled="disabled">' . esc_html_x('Installed', 'plugin', 'seoprostack') . '</button>';
        } elseif ($php && !is_php_version_compatible($php)) {
            $button = '<button type="button" class="button button-disabled" disabled="disabled">' . esc_html__('Cannot Install', 'seoprostack') . '</button>';
        } elseif (!empty($data['install_url'])) {
            $button = sprintf(
                '<a class="button" href="%1$s" aria-label="%2$s">%3$s</a>',
                esc_url((string) $data['install_url']),
                esc_attr(sprintf(/* translators: %s: plugin name */ _x('Install %s now', 'plugin', 'seoprostack'), $name)),
                esc_html_x('Install Now', 'plugin', 'seoprostack')
            );
        } else {
            $button = '';
        }
        ?>
        <div class="plugin-card plugin-card-<?php echo esc_attr(sanitize_html_class($slug)); ?> sps-plugin-external">
            <div class="plugin-card-top">
                <div class="name column-name">
                    <h3>
                        <?php if ($url) : ?>
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($name); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span></a>
                        <?php else : ?>
                            <?php echo esc_html($name); ?>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="action-links">
                    <ul class="plugin-action-buttons">
                        <?php if ($button) : ?>
                            <li><?php echo $button; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></li>
                        <?php endif; ?>
                        <?php if ($url) : ?>
                            <li><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('More Details', 'seoprostack'); ?><span class="screen-reader-text"> <?php echo esc_html(sprintf(/* translators: %s: plugin name */ __('for %s (opens in a new tab)', 'seoprostack'), $name)); ?></span></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="desc column-description">
                    <?php if (!empty($data['description'])) : ?>
                        <p><?php echo esc_html((string) $data['description']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($data['author'])) : ?>
                        <p class="authors"><cite><?php echo esc_html(sprintf(/* translators: %s: author */ __('By %s', 'seoprostack'), (string) $data['author'])); ?></cite></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="plugin-card-bottom">
                <p class="sps-external-source">
                    <?php if (!empty($data['source'])) : ?>
                        <?php echo esc_html((string) $data['source']); ?>
                    <?php endif; ?>
                    <?php if (!$file && $php && !is_php_version_compatible($php)) : ?>
                        <strong>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: PHP version the plugin needs, 2: this server's PHP version */
                                __('Needs PHP %1$s or later; this site runs PHP %2$s.', 'seoprostack'),
                                $php,
                                PHP_VERSION
                            ));
                            ?>
                        </strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php
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

        $external = self::external_plugins();

        ob_start();
        foreach ($plugins as $plugin) {
            $plugin = (object) $plugin;
            if (!empty($plugin->external)) {
                // Stubs cached while a build listed the plugin are skipped once it no longer does.
                if (isset($external[$plugin->slug])) {
                    self::external_card($plugin->slug, (array) $external[$plugin->slug]);
                }
                continue;
            }
            if (!empty($plugin->removed)) {
                self::removed_card($plugin);
                continue;
            }
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
                        esc_attr(sprintf(/* translators: %s: plugin name */ _x('Install %s now', 'plugin', 'seoprostack'), $name)),
                        esc_attr($name),
                        esc_html_x('Install Now', 'plugin', 'seoprostack')
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
                        esc_attr(sprintf(/* translators: %s: plugin name */ __('Update %s now', 'seoprostack'), $name)),
                        esc_attr($name),
                        esc_html__('Update Now', 'seoprostack')
                    );
                }
                break;

            case 'latest_installed':
            case 'newer_installed':
                if (is_plugin_active($status['file'])) {
                    $html .= '<li><button type="button" class="button button-disabled" disabled="disabled">' . esc_html_x('Active', 'plugin', 'seoprostack') . '</button></li>';
                } elseif (current_user_can('activate_plugin', $status['file'])) {
                    $url   = wp_nonce_url(self_admin_url('plugins.php?action=activate&plugin=' . rawurlencode($status['file'])), 'activate-plugin_' . $status['file']);
                    $html .= sprintf(
                        '<li><a href="%1$s" class="button button-primary activate-now" aria-label="%2$s">%3$s</a></li>',
                        esc_url($url),
                        esc_attr(sprintf(/* translators: %s: plugin name */ _x('Activate %s', 'plugin', 'seoprostack'), $name)),
                        esc_html__('Activate', 'seoprostack')
                    );
                } else {
                    $html .= '<li><button type="button" class="button button-disabled" disabled="disabled">' . esc_html_x('Installed', 'plugin', 'seoprostack') . '</button></li>';
                }
                break;
        }

        $pro_url = self::get_pro_url_for_free_slug($plugin->slug);
        if ($pro_url) {
            $html .= sprintf(
                '<li><a class="button sps-go-pro" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a></li>',
                esc_url($pro_url),
                esc_html__('Go Pro', 'seoprostack'),
                esc_html(sprintf(/* translators: %s: plugin name */ __('for %s (opens in a new tab)', 'seoprostack'), $name))
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
