<?php
/**
 * WP ALLSTARS Plugin Manager
 *
 * Handles all plugin-related functionality including:
 * - Plugin data caching
 * - AJAX handlers for plugin data
 * - Plugin card generation
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Plugin_Manager {
    /**
     * Initialize the class
     */
    public static function init() {
        // Add AJAX handlers
        add_action('wp_ajax_wp_allstars_get_plugins', [self::class, 'ajax_get_plugins']);
        
        // Plugin cache clearing
        add_action('upgrader_process_complete', [self::class, 'clear_plugin_cache'], 10, 0);
        add_action('activated_plugin', [self::class, 'clear_plugin_cache']);
        add_action('deactivated_plugin', [self::class, 'clear_plugin_cache']);
        add_action('deleted_plugin', [self::class, 'clear_plugin_cache']);
        add_action('update_option_active_plugins', [self::class, 'clear_plugin_cache']);
    }
    
    /**
     * Get cached plugin data for a category
     *
     * @param string $category The plugin category
     * @return mixed The cached plugin data or false if no cache
     */
    public static function get_cached_plugins($category) {
        $cache_key = 'wp_allstars_plugins_' . $category;
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        return false;
    }
    
    /**
     * Set cached plugin data for a category
     *
     * @param string $category The plugin category
     * @param mixed $data The data to cache
     */
    public static function set_cached_plugins($category, $data) {
        $cache_key = 'wp_allstars_plugins_' . $category;
        set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
    }
    
    /**
     * AJAX handler for getting plugin data
     */
    public static function ajax_get_plugins() {
        // Check nonce with the correct action name
        if (!check_ajax_referer('wp-allstars-nonce', '_wpnonce', false)) {
            wp_send_json_error('Invalid security token sent.');
            return;
        }
        
        if (!current_user_can('install_plugins')) {
            wp_die(-1);
        }
    
        $category = isset($_POST['category']) ? sanitize_key($_POST['category']) : 'minimal';
        
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        
        // Get our recommended plugins for this category
        $recommended_plugins = wp_allstars_get_recommended_plugins();
        if (!isset($recommended_plugins[$category])) {
            wp_send_json_error('Invalid category: ' . $category);
            return;
        }
        
        // Try to get cached data first
        $cached_data = self::get_cached_plugins($category);
        if ($cached_data !== false) {
            error_log('Using cached data for category: ' . $category);
            try {
                // Generate plugin cards HTML
                $html = self::generate_plugin_cards($cached_data->plugins);
                wp_send_json_success($html);
                return;
            } catch (Exception $e) {
                error_log('Error displaying cached plugins: ' . $e->getMessage());
                // Fall through to fetch fresh data
            }
        }
        
        error_log('Fetching fresh data for category: ' . $category);
        error_log('Plugins to fetch: ' . implode(', ', $recommended_plugins[$category]));
        
        try {
            $plugins = array();
            
            // Only fetch plugins that are in our recommended list for this category
            foreach ($recommended_plugins[$category] as $slug) {
                try {
                    error_log('Fetching plugin data for: ' . $slug);
                    $plugin_data = plugins_api('plugin_information', array(
                        'slug' => $slug,
                        'fields' => array(
                            'short_description' => true,
                            'sections' => false,
                            'requires' => true,
                            'rating' => true,
                            'ratings' => false,
                            'downloaded' => true,
                            'last_updated' => true,
                            'added' => false,
                            'tags' => false,
                            'compatibility' => false,
                            'homepage' => true,
                            'versions' => false,
                            'donate_link' => false,
                            'reviews' => false,
                            'banners' => false,
                            'icons' => true,
                            'active_installs' => true,
                            'group' => false,
                            'contributors' => false,
                        )
                    ));
                    
                    if (is_wp_error($plugin_data)) {
                        error_log('Error fetching plugin data for ' . $slug . ': ' . $plugin_data->get_error_message());
                    } else {
                        $plugins[] = $plugin_data;
                        error_log('Successfully fetched data for: ' . $slug);
                    }
                } catch (Exception $e) {
                    error_log('Exception fetching plugin data for ' . $slug . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            if (empty($plugins)) {
                wp_send_json_error('No plugin data could be retrieved for category: ' . $category);
                return;
            }
            
            error_log('Total plugins fetched: ' . count($plugins));
            
            // Create response object
            $res = (object) array(
                'info' => array(
                    'page' => 1,
                    'pages' => 1,
                    'results' => count($plugins),
                ),
                'plugins' => $plugins
            );
            
            // Cache the results
            self::set_cached_plugins($category, $res);
            
            // Generate plugin cards HTML
            $html = self::generate_plugin_cards($plugins);
            wp_send_json_success($html);
            
        } catch (Exception $e) {
            error_log('Failed to fetch plugin data: ' . $e->getMessage());
            wp_send_json_error('Failed to fetch plugin data: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate HTML for plugin cards
     *
     * @param array $plugins Array of plugin data
     * @return string HTML for the plugin cards
     */
    public static function generate_plugin_cards($plugins) {
        if (empty($plugins)) {
            return '<div class="notice notice-error"><p>No plugins found.</p></div>';
        }
        
        ob_start();
        ?>
        <div class="wp-list-table widefat plugin-install">
            <div id="the-list">
                <?php foreach ($plugins as $plugin): ?>
                    <div class="plugin-card plugin-card-<?php echo esc_attr($plugin->slug); ?>">
                        <div class="plugin-card-top">
                            <div class="name column-name">
                                <h3>
                                    <?php echo esc_html($plugin->name); ?>
                                </h3>
                            </div>
                            <div class="action-links">
                                <ul class="plugin-action-buttons">
                                    <?php
                                    $status = install_plugin_install_status($plugin);
                                    switch ($status['status']) {
                                        case 'install':
                                            echo '<li><a class="button button-primary install-now" data-slug="' . esc_attr($plugin->slug) . '" href="' . esc_url($status['url']) . '" aria-label="' . esc_attr(sprintf(__('Install %s now'), $plugin->name)) . '">' . __('Install Now') . '</a></li>';
                                            break;
                                        case 'update_available':
                                            echo '<li><a class="button button-primary update-now" data-plugin="' . esc_attr($status['file']) . '" data-slug="' . esc_attr($plugin->slug) . '" href="' . esc_url($status['url']) . '" aria-label="' . esc_attr(sprintf(__('Update %s now'), $plugin->name)) . '">' . __('Update Now') . '</a></li>';
                                            break;
                                        case 'latest_installed':
                                        case 'newer_installed':
                                            if (is_plugin_active($status['file'])) {
                                                echo '<li><button type="button" class="button button-disabled" disabled="disabled">' . __('Active') . '</button></li>';
                                            } else {
                                                echo '<li><a class="button activate-now" href="' . esc_url(wp_nonce_url('plugins.php?action=activate&amp;plugin=' . $status['file'], 'activate-plugin_' . $status['file'])) . '" aria-label="' . esc_attr(sprintf(__('Activate %s'), $plugin->name)) . '">' . __('Activate') . '</a></li>';
                                            }
                                            break;
                                    }
                                    
                                    // Add PRO button if available
                                    self::add_pro_button($plugin);
                                    ?>
                                </ul>
                            </div>
                            <div class="desc column-description">
                                <p><?php echo esc_html($plugin->short_description); ?></p>
                                <p class="authors">
                                    <cite><?php printf(__('By %s'), $plugin->author); ?></cite>
                                </p>
                            </div>
                        </div>
                        <div class="plugin-card-bottom">
                            <div class="vers column-rating">
                                <?php wp_star_rating(array('rating' => $plugin->rating, 'type' => 'percent', 'number' => $plugin->num_ratings)); ?>
                                <span class="num-ratings" aria-hidden="true">(<?php echo number_format_i18n($plugin->num_ratings); ?>)</span>
                            </div>
                            <div class="column-updated">
                                <strong><?php _e('Last Updated:'); ?></strong> 
                                <?php printf(__('%s ago'), human_time_diff(strtotime($plugin->last_updated))); ?>
                            </div>
                            <div class="column-downloaded">
                                <?php 
                                if ($plugin->active_installs >= 1000000) {
                                    $active_installs_millions = floor($plugin->active_installs / 1000000);
                                    $active_installs_text = sprintf(
                                        _n('%s+ Million Active Installations', '%s+ Million Active Installations', $active_installs_millions),
                                        number_format_i18n($active_installs_millions)
                                    );
                                } elseif (0 == $plugin->active_installs) {
                                    $active_installs_text = _x('Less Than 10 Active Installations', 'Active plugin installations');
                                } else {
                                    $active_installs_text = sprintf(
                                        _n('%s+ Active Installation', '%s+ Active Installations', $plugin->active_installs),
                                        number_format_i18n($plugin->active_installs)
                                    );
                                }
                                /* translators: %s: number of active installations */
                                echo esc_html($active_installs_text);
                                ?>
                            </div>
                            <div class="column-compatibility">
                                <?php
                                $version = get_bloginfo('version');
                                if (!empty($plugin->tested) && version_compare($version, $plugin->tested, '>')) {
                                    echo '<span class="compatibility-untested">' . __('Untested with your version of WordPress') . '</span>';
                                } elseif (!empty($plugin->requires) && version_compare($version, $plugin->requires, '<')) {
                                    echo '<span class="compatibility-incompatible">' . __('Incompatible with your version of WordPress') . '</span>';
                                } else {
                                    echo '<span class="compatibility-compatible">' . __('Compatible with your version of WordPress') . '</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Add PRO button to plugin cards if a pro version exists
     *
     * @param object $plugin The plugin object
     */
    public static function add_pro_button($plugin) {
        $pro_plugins = wp_allstars_get_pro_plugins_config();
        
        if (isset($pro_plugins[$plugin->slug])) {
            $pro_plugin = $pro_plugins[$plugin->slug];
            $pro_url = self::get_pro_plugin_url($pro_plugin);
            
            if (!empty($pro_url)) {
                echo '<li><a href="' . esc_url($pro_url) . '" target="_blank" class="button button-primary" style="background-color: #2ecc71; border-color: #27ae60;">' . esc_html__('PRO', 'wp-allstars') . '</a></li>';
            }
        }
    }
    
    /**
     * Get the URL for a pro plugin from its config
     *
     * @param array $pro_plugin The pro plugin configuration array
     * @return string The URL for the pro plugin
     */
    public static function get_pro_plugin_url($pro_plugin) {
        // First check if there's a button_group defined
        if (isset($pro_plugin['button_group']) && is_array($pro_plugin['button_group'])) {
            foreach ($pro_plugin['button_group'] as $button) {
                // Return the URL for the primary button if available
                if (isset($button['primary']) && $button['primary'] && !empty($button['url'])) {
                    return $button['url'];
                }
            }
            
            // If no primary button found, return the first button URL
            if (!empty($pro_plugin['button_group'][0]['url'])) {
                return $pro_plugin['button_group'][0]['url'];
            }
        }
        
        // Fall back to the main URL if available
        if (!empty($pro_plugin['url'])) {
            return $pro_plugin['url'];
        }
        
        return '';
    }
    
    /**
     * Clear plugin cache when plugins are updated, activated, or deactivated
     */
    public static function clear_plugin_cache() {
        $recommended_plugins = wp_allstars_get_recommended_plugins();
        foreach (array_keys($recommended_plugins) as $category) {
            delete_transient('wp_allstars_plugins_' . $category);
        }
    }
}

// Initialize the class
WP_Allstars_Plugin_Manager::init();
