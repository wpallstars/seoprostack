<?php
/**
 * Admin settings page
 */

// Add menu item
function wp_allstars_admin_menu() {
    add_options_page(
        'WP ALLSTARS Settings',
        'WP ALLSTARS',
        'manage_options',
        'wp-allstars',
        'wp_allstars_settings_page'
    );
}
add_action('admin_menu', 'wp_allstars_admin_menu');

// Register settings
function wp_allstars_register_settings() {
    // Removed minification settings
}
add_action('admin_init', 'wp_allstars_register_settings');

// AJAX handler for settings
function wp_allstars_update_option() {
    check_ajax_referer('wp-allstars-nonce', 'nonce');
    $option = sanitize_text_field($_POST['option']);
    $value = intval($_POST['value']);
    update_option($option, $value);
    wp_send_json_success('Option updated');
}
add_action('wp_ajax_wp_allstars_update_option', 'wp_allstars_update_option');

// Define recommended plugins
function wp_allstars_get_recommended_plugins() {
    return array(
        'minimal' => array(
            'antispam-bee',
            'compressx',
            'fluent-smtp',
            'kadence-blocks',
            'simple-cloudflare-turnstile'
        ),
        'admin' => array(
            'admin-bar-dashboard-control',
            'codepress-admin-columns',
            'admin-menu-editor',
            'hide-admin-notices',
            'mainwp-child',
            'mainwp-child-reports',
            'magic-login',
            'manage-notification-emails',
            'plugin-groups',
            'plugin-toggle'
        ),
        'affiliates' => array(
            'pretty-links',
            'simple-urls',
            'slicewp'
        ),
        'ai' => array(
            'ai-engine',
        ),
        'cms' => array(
            'auto-post-scheduler',
            'block-options',
            'bookmark-card',
            'browser-shots',
            'bulk-actions-select-all',
            'bulk-edit-categories-tags',
            'bulk-edit-user-profiles-in-spreadsheet',
            'carbon-copy',
            'code-block-pro',
            'iframe-block',
            'ics-calendar',
            'mammoth-docx-converter',
            'nav-menu-roles',
            'ninja-tables',
            'post-draft-preview',
            'post-type-switcher',
            'simple-custom-post-order',
            'simple-icons',
            'sticky-posts-switch',
            'term-management-tools',
            'the-paste',
            'ultimate-addons-for-gutenberg',
            'wikipedia-preview',
            'wp-sheet-editor-bulk-spreadsheet-editor-for-posts-and-pages'
        ),
        'compliance' => array(
            'avatar-privacy',
            'complianz-gdpr',
            'complianz-terms-conditions',
            'really-simple-ssl'
        ),
        'crm' => array(
            'fluent-boards',
            'fluent-booking',
            'fluent-community',
            'fluent-crm',
            'fluentform',
            'fluentforms-pdf',
            'fluentform-block',
            'fluent-support'
        ),
        'ecommerce' => array(
            'woocommerce',
            'woo-bulk-edit-products',
            'woo-coupons-bulk-editor',
            'woocommerce-gateway-gocardless',
            'kadence-woocommerce-email-designer',
            'pymntpl-paypal-woocommerce',
            'woo-stripe-payment'
        ),
        'lms' => array(
            'fluent-community',
            'masterstudy-lms-learning-management-system',
            'tutor'
        ),
        'media' => array(
            'easy-watermark',
            'enable-media-replace',
            'image-copytrack',
            'imsanity',
            'media-file-renamer',
            'safe-svg'
        ),
        'seo' => array(
            'burst-statistics',
            'pretty-link',
            'seo-by-rank-math',
            'syndication-links',
            'ultimate-410',
            'webmention'
        ),
        'setup' => array(
            'kadence-starter-templates',
            'wordpress-importer'
        ),
        'social' => array(
            'bit-social',
            'easy-video-reviews',
            'social-engine',
            'wp-social-reviews'
        ),
        'speed' => array(
            'disable-wordpress-updates',
            'flying-analytics',
            'flying-pages',
            'flying-scripts',
            'freesoul-deactivate-plugins',
            'index-wp-mysql-for-speed',
            'litespeed-cache',
            'performant-translations',
            'wp-optimize',
            'wp-widget-disable'
        ),
        'translation' => array(
            'hreflang-manager-lite',
            'performant-translations',
            'translatepress-multilingual'
        ),
        'advanced' => array(
            'acf-better-search',
            'advanced-custom-fields',
            'automatorwp',
            'bit-pi',
            'bit-integrations',
            'code-snippets',
            'easy-code-manager',
            'favorites',
            'remove-cpt-base',
            'remove-old-slugspermalinks',
            'yellow-pencil-visual-theme-customizer'
        ),
        'debug' => array(
            'advanced-database-cleaner',
            'debug-log-manager',
            'gotmls',
            'query-monitor',
            'string-locator',
            'user-switching',
            'wp-crontrol'
        )
    );
}

// Add transient caching for plugin data
function wp_allstars_get_cached_plugins($category) {
    $cache_key = 'wp_allstars_plugins_' . $category;
    $cached_data = get_transient($cache_key);
    
    if ($cached_data !== false) {
        return $cached_data;
    }
    
    return false;
}

function wp_allstars_set_cached_plugins($category, $data) {
    $cache_key = 'wp_allstars_plugins_' . $category;
    set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
}

// Add AJAX endpoint for plugin list
function wp_allstars_ajax_get_plugins() {
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
    $cached_data = wp_allstars_get_cached_plugins($category);
    if ($cached_data !== false) {
        error_log('Using cached data for category: ' . $category);
        try {
            // Generate plugin cards HTML
            $html = wp_allstars_generate_plugin_cards($cached_data->plugins);
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
        wp_allstars_set_cached_plugins($category, $res);
        
        // Generate plugin cards HTML
        $html = wp_allstars_generate_plugin_cards($plugins);
        wp_send_json_success($html);
        
    } catch (Exception $e) {
        error_log('Failed to fetch plugin data: ' . $e->getMessage());
        wp_send_json_error('Failed to fetch plugin data: ' . $e->getMessage());
    }
}
add_action('wp_ajax_wp_allstars_get_plugins', 'wp_allstars_ajax_get_plugins');

// Function to generate plugin cards HTML
function wp_allstars_generate_plugin_cards($plugins) {
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
                                        echo '<li><a class="button button-primary update-now" data-slug="' . esc_attr($plugin->slug) . '" href="' . esc_url($status['url']) . '" aria-label="' . esc_attr(sprintf(__('Update %s now'), $plugin->name)) . '">' . __('Update Now') . '</a></li>';
                                        break;
                                    case 'latest_installed':
                                    case 'newer_installed':
                                        if (is_plugin_active($status['file'])) {
                                            echo '<li><button type="button" class="button button-disabled" disabled="disabled">' . __('Active') . '</button></li>';
                                        } else {
                                            echo '<li><a class="button activate-now" href="' . esc_url(wp_nonce_url(admin_url('plugins.php?action=activate&plugin=' . $status['file']), 'activate-plugin_' . $status['file'])) . '" aria-label="' . esc_attr(sprintf(__('Activate %s'), $plugin->name)) . '">' . __('Activate') . '</a></li>';
                                        }
                                        break;
                                }
                                
                                // Add "Go Pro" button if applicable
                                $pro_plugins = wp_allstars_get_pro_plugins_config();
                                foreach ($pro_plugins as $pro_plugin) {
                                    if (isset($pro_plugin['free_slug']) && $pro_plugin['free_slug'] === $plugin->slug) {
                                        // Find the primary button URL in the button_group array
                                        $pro_url = '';
                                        foreach ($pro_plugin['button_group'] as $button) {
                                            if (isset($button['primary']) && $button['primary']) {
                                                $pro_url = $button['url'];
                                                break;
                                            }
                                        }
                                        // If no primary button found, use the first button URL
                                        if (empty($pro_url) && !empty($pro_plugin['button_group'][0]['url'])) {
                                            $pro_url = $pro_plugin['button_group'][0]['url'];
                                        }
                                        echo '<li><a class="button button-primary" href="' . esc_url($pro_url) . '" target="_blank">' . esc_html__('Go Pro', 'wp-allstars') . '</a></li>';
                                        break;
                                    }
                                }
                                
                                // Add "More Details" link
                                echo '<li><a class="more-details thickbox open-plugin-details-modal" href="' . esc_url(admin_url('plugin-install.php?tab=plugin-information&plugin=' . $plugin->slug . '&TB_iframe=true&width=600&height=550')) . '" aria-label="' . esc_attr(sprintf(__('More information about %s'), $plugin->name)) . '">' . __('More Details') . '</a></li>';
                                ?>
                            </ul>
                        </div>
                        <?php if (!empty($plugin->icons) && !empty($plugin->icons['1x'])): ?>
                            <div class="plugin-icon">
                                <img src="<?php echo esc_url($plugin->icons['1x']); ?>" alt="">
                            </div>
                        <?php endif; ?>
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
                            <span class="num-ratings">(<?php echo number_format_i18n($plugin->num_ratings); ?>)</span>
                        </div>
                        <div class="column-updated">
                            <strong><?php _e('Last Updated:'); ?></strong> 
                            <?php printf(__('%s ago'), human_time_diff(strtotime($plugin->last_updated))); ?>
                        </div>
                        <div class="column-downloaded">
                            <?php echo sprintf(_n('%s download', '%s downloads', $plugin->downloaded), number_format_i18n($plugin->downloaded)); ?>
                        </div>
                        <div class="column-compatibility">
                            <?php
                            if (!empty($plugin->tested) && version_compare(substr($GLOBALS['wp_version'], 0, strlen($plugin->tested)), $plugin->tested, '>')) {
                                echo '<span class="compatibility-untested">' . __('Untested with your version of WordPress') . '</span>';
                            } elseif (!empty($plugin->requires) && version_compare($GLOBALS['wp_version'], $plugin->requires, '<')) {
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

// Helper function to add pro button to plugin cards
function wp_allstars_add_pro_button($action_links, $plugin) {
    // Get pro plugins configuration
    $pro_plugins = wp_allstars_get_pro_plugins_config();
    
    // Check if this plugin has a pro version
    foreach ($pro_plugins as $pro_plugin) {
        if (isset($pro_plugin['free_slug']) && $pro_plugin['free_slug'] === $plugin['slug']) {
            $action_links[] = sprintf(
                '<a class="button button-primary" href="%s" target="_blank">%s</a>',
                esc_url(wp_allstars_get_pro_plugin_url($pro_plugin)),
                esc_html__('Go Pro', 'wp-allstars')
            );
            break;
        }
    }
    
    return $action_links;
}

/**
 * Helper function to get the pro plugin URL from the button_group array
 *
 * @param array $pro_plugin The pro plugin configuration array
 * @return string The URL for the pro plugin
 */
function wp_allstars_get_pro_plugin_url($pro_plugin) {
    // Find the primary button URL in the button_group array
    $pro_url = '';
    
    if (!empty($pro_plugin['button_group'])) {
        // First try to find a primary button
        foreach ($pro_plugin['button_group'] as $button) {
            if (isset($button['primary']) && $button['primary']) {
                $pro_url = $button['url'];
                break;
            }
        }
        
        // If no primary button found, use the first button URL
        if (empty($pro_url) && !empty($pro_plugin['button_group'][0]['url'])) {
            $pro_url = $pro_plugin['button_group'][0]['url'];
        }
    }
    
    return $pro_url;
}

// Remove the old plugins API filter since we're handling everything in the AJAX endpoint
remove_filter('plugins_api_result', 'wp_allstars_plugins_api_result');

// Clear plugin cache when plugins are updated, activated, or deactivated
function wp_allstars_clear_plugin_cache() {
    $recommended_plugins = wp_allstars_get_recommended_plugins();
    foreach (array_keys($recommended_plugins) as $category) {
        delete_transient('wp_allstars_plugins_' . $category);
    }
}
add_action('upgrader_process_complete', 'wp_allstars_clear_plugin_cache', 10, 0);
add_action('activated_plugin', 'wp_allstars_clear_plugin_cache');
add_action('deactivated_plugin', 'wp_allstars_clear_plugin_cache');
add_action('deleted_plugin', 'wp_allstars_clear_plugin_cache');
add_action('update_option_active_plugins', 'wp_allstars_clear_plugin_cache');

// Add transient caching for theme data
function wp_allstars_get_cached_theme() {
    $cache_key = 'wp_allstars_theme_kadence';
    return get_transient($cache_key);
}

function wp_allstars_set_cached_theme($data) {
    $cache_key = 'wp_allstars_theme_kadence';
    set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
}

// Add AJAX endpoint for theme
function wp_allstars_ajax_get_themes() {
    error_log('WP ALLSTARS: Theme AJAX handler started');
    
    // Check nonce with the correct action name
    if (!isset($_POST['_wpnonce'])) {
        error_log('WP ALLSTARS: No nonce provided');
        wp_send_json_error('No security token provided.');
        return;
    }
    
    if (!check_ajax_referer('wp-allstars-nonce', '_wpnonce', false)) {
        error_log('WP ALLSTARS: Invalid nonce: ' . sanitize_text_field($_POST['_wpnonce']));
        wp_send_json_error('Invalid security token sent.');
        return;
    }
    
    if (!current_user_can('install_themes')) {
        error_log('WP ALLSTARS: User does not have permission to install themes');
        wp_send_json_error('Permission denied');
        return;
    }
    
    error_log('WP ALLSTARS: Starting theme fetch process');
    
    try {
        error_log('WP ALLSTARS: Fetching theme data for kadence');
        
        // Check if we have cached data first
        $theme_data = wp_allstars_get_cached_theme();
        
        // If no cached data, fetch from API
        if (empty($theme_data)) {
            error_log('WP ALLSTARS: No cached theme data, fetching from API');
            
            // Get theme data with minimal fields
            $theme_data = themes_api('theme_information', array(
                'slug' => 'kadence',
                'fields' => array(
                    'sections' => false,
                    'description' => true,
                    'rating' => true,
                    'ratings' => false,
                    'downloaded' => true,
                    'download_link' => true,
                    'last_updated' => true,
                    'homepage' => true,
                    'tags' => false,
                    'screenshot_url' => true,
                    'version' => true,
                    'requires' => true,
                    'requires_php' => true,
                    'active_installs' => true,
                    'author' => true,
                    'preview_url' => true,
                )
            ));
            
            // Cache the result if successful
            if (!is_wp_error($theme_data)) {
                wp_allstars_set_cached_theme($theme_data);
            }
        } else {
            error_log('WP ALLSTARS: Using cached theme data');
        }
        
        if (is_wp_error($theme_data)) {
            error_log('WP ALLSTARS Theme API Error: ' . $theme_data->get_error_message());
            wp_send_json_error('Theme API Error: ' . $theme_data->get_error_message());
            return;
        }
        
        error_log('WP ALLSTARS: Successfully fetched theme data');
        
        // Format author data
        $author = '';
        if (is_string($theme_data->author)) {
            $author = $theme_data->author;
        } elseif (is_array($theme_data->author)) {
            $author = isset($theme_data->author['display_name']) ? $theme_data->author['display_name'] : '';
        }
        
        error_log('WP ALLSTARS: Theme data retrieved, generating HTML');
        
        // Generate custom HTML for the theme
        ob_start();
        ?>
        <div class="theme-browser">
            <div class="themes wp-clearfix">
                <div class="theme" tabindex="0">
                    <div class="theme-screenshot">
                        <img src="<?php echo esc_url($theme_data->screenshot_url); ?>" alt="">
                    </div>
                    <span class="theme-author"><?php echo esc_html(sprintf(__('By %s'), $author)); ?></span>
                    <h3 class="theme-name"><?php echo esc_html($theme_data->name); ?></h3>
                    <div class="theme-actions">
                        <?php if (current_user_can('install_themes')): ?>
                            <?php 
                            $installed_theme = wp_get_theme('kadence');
                            if ($installed_theme->exists()): ?>
                                <button type="button" class="button button-primary activate-now" data-slug="kadence">
                                    <?php esc_html_e('Activate'); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="button button-primary install-now" data-slug="kadence">
                                    <?php esc_html_e('Install'); ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a class="button button-secondary preview install-theme-preview" href="<?php echo esc_url($theme_data->preview_url); ?>" target="_blank">
                            <?php esc_html_e('Preview'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        
        if (empty($html)) {
            error_log('WP ALLSTARS: Empty HTML generated');
            wp_send_json_error('Failed to generate theme display');
            return;
        }
        
        error_log('WP ALLSTARS: Successfully generated theme display, HTML length: ' . strlen($html));
        wp_send_json_success($html);
        exit; // Ensure we exit after sending the JSON response
        
    } catch (Exception $e) {
        error_log('WP ALLSTARS Theme loading exception: ' . $e->getMessage());
        error_log('WP ALLSTARS Theme loading exception trace: ' . $e->getTraceAsString());
        wp_send_json_error('Theme loading error: ' . $e->getMessage());
    } catch (Error $e) {
        error_log('WP ALLSTARS Theme loading error: ' . $e->getMessage());
        error_log('WP ALLSTARS Theme loading error trace: ' . $e->getTraceAsString());
        wp_send_json_error('Theme loading error: ' . $e->getMessage());
    }
}
add_action('wp_ajax_wp_allstars_get_themes', 'wp_allstars_ajax_get_themes');

// Clear theme cache when themes are updated
function wp_allstars_clear_theme_cache() {
    delete_transient('wp_allstars_theme_kadence');
}
add_action('upgrader_process_complete', 'wp_allstars_clear_theme_cache', 10, 0);
add_action('switch_theme', 'wp_allstars_clear_theme_cache');

// Add AJAX handler for theme activation
function wp_allstars_activate_theme() {
    // Check nonce with the correct action name
    if (!check_ajax_referer('wp-allstars-nonce', '_wpnonce', false)) {
        wp_send_json_error('Invalid security token sent.');
        return;
    }
    
    if (!current_user_can('switch_themes')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    $theme = isset($_POST['theme']) ? sanitize_text_field($_POST['theme']) : '';
    if (empty($theme)) {
        wp_send_json_error('No theme specified');
        return;
    }
    
    // Switch the theme
    switch_theme($theme);
    
    // Check if the switch was successful
    $active_theme = wp_get_theme();
    if ($active_theme->get_stylesheet() === $theme) {
        wp_send_json_success(array(
            'message' => 'Theme activated successfully',
            'customize_url' => admin_url('customize.php')
        ));
    } else {
        wp_send_json_error('Failed to activate theme');
    }
}
add_action('wp_ajax_wp_allstars_activate_theme', 'wp_allstars_activate_theme');

// Register settings page HTML
function wp_allstars_settings_page() {
    global $tabs;
    
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
    $active_category = isset($_GET['category']) ? $_GET['category'] : 'minimal';
    
    // Clear cache and load required files
    if ($active_tab === 'recommended') {
        wp_allstars_clear_plugin_cache();
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        wp_enqueue_script('plugin-install');
        wp_enqueue_script('updates');
        add_thickbox();
        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', __FILE__));
        wp_enqueue_style('wp-allstars-plugins', plugins_url('css/wp-allstars-plugins.css', __FILE__));
        
        // Add inline script to load plugins on page load
        wp_add_inline_script('wp-allstars-admin', '
            jQuery(document).ready(function($) {
                if ($("#wpa-plugin-list").length && $("#wpa-plugin-list").is(":empty")) {
                    var category = "' . esc_js($active_category) . '";
                    var $container = $("#wpa-plugin-list");
                    var $loadingOverlay = $("<div class=\"wp-allstars-loading-overlay\"><span class=\"spinner is-active\"></span></div>");
                    
                    // Show loading overlay
                    $container.css("position", "relative").append($loadingOverlay);
                    
                    // AJAX request to get plugins
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "wp_allstars_get_plugins",
                            category: category,
                            _wpnonce: wpAllstars.nonce
                        },
                        success: function(response) {
                            $loadingOverlay.remove();
                            if (response.success) {
                                $container.html(response.data);
                                // Initialize plugin action buttons
                                if (typeof initPluginActions === "function") {
                                    initPluginActions();
                                }
                                
                                // Spinners have been removed from individual cards
                            } else {
                                $container.html("<div class=\"notice notice-error\"><p>" + response.data + "</p></div>");
                            }
                        },
                        error: function(xhr, status, error) {
                            $loadingOverlay.remove();
                            $container.html("<div class=\"notice notice-error\"><p>Failed to load plugins. Please try again. Error: " + error + "</p></div>");
                            console.error("AJAX Error:", xhr.responseText);
                        }
                    });
                }
            });
        ');
    } elseif ($active_tab === 'theme') {
        wp_allstars_clear_theme_cache();
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        wp_enqueue_script('theme-install');
        wp_enqueue_script('updates');
        add_thickbox();
        wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', __FILE__));
        wp_enqueue_style('wp-allstars-plugins', plugins_url('css/wp-allstars-plugins.css', __FILE__));
        
        // Add inline script to load themes directly - same approach as plugins tab
        wp_add_inline_script('wp-allstars-admin', '
            jQuery(document).ready(function($) {
                if ($("#wpa-theme-list").length && $("#wpa-theme-list").is(":empty")) {
                    var $container = $("#wpa-theme-list");
                    var $loadingOverlay = $("<div class=\"wp-allstars-loading-overlay\"><span class=\"spinner is-active\"></span></div>");
                    
                    // Show loading overlay
                    $container.css("position", "relative").append($loadingOverlay);
                    
                    // AJAX request to get themes
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "wp_allstars_get_themes",
                            _wpnonce: wpAllstars.nonce
                        },
                        success: function(response) {
                            $loadingOverlay.remove();
                            if (response.success) {
                                $container.html(response.data);
                                // Initialize theme action buttons
                                if (typeof initThemeHandlers === "function") {
                                    initThemeHandlers();
                                }
                            } else {
                                $container.html("<div class=\"notice notice-error\"><p>" + response.data + "</p></div>");
                            }
                        },
                        error: function(xhr, status, error) {
                            $loadingOverlay.remove();
                            $container.html("<div class=\"notice notice-error\"><p>Failed to load themes. Please try again. Error: " + error + "</p></div>");
                            console.error("AJAX Error:", xhr.responseText);
                        }
                    });
                }
            });
        ');
    }
    ?>
    <div class="wrap wp-allstars-wrap">
        <div class="wp-allstars-header">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="wp-allstars-header-actions">
                <span class="wp-allstars-version">Version <?php echo esc_html(WP_ALLSTARS_VERSION); ?></span>
                <a href="https://www.wpallstars.com/" target="_blank" class="button button-secondary">
                    <?php esc_html_e('Documentation', 'wp-allstars'); ?>
                </a>
            </div>
        </div>
        
        <div class="wpa-settings-container">
            <div class="wp-allstars-nav">
        <h2 class="nav-tab-wrapper">
                    <a href="?page=wp-allstars&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('General', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Advanced', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=workflow" class="nav-tab <?php echo $active_tab == 'workflow' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Workflow', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=recommended" class="nav-tab <?php echo $active_tab == 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Free Plugins', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=pro" class="nav-tab <?php echo $active_tab == 'pro' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Pro Plugins', 'wp-allstars'); ?>
                    </a>
                    <a href="?page=wp-allstars&tab=theme" class="nav-tab <?php echo $active_tab == 'theme' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Theme', 'wp-allstars'); ?>
                    </a>
        </h2>
            </div>

            <div class="wpa-settings-content">
                <?php if ($active_tab == 'workflow'): ?>
                    <div class="wp-allstars-settings-content tab-content" id="workflow">
                        <div class="wp-allstars-toggle">
                            <div class="wp-allstars-toggle-header" aria-expanded="false">
                                <div class="wp-allstars-toggle-main">
                                    <div class="wp-allstars-toggle-left">
                                        <div class="wp-toggle-switch">
                                            <input type="checkbox" 
                                                   id="wp_allstars_auto_upload_images"
                                                   name="wp_allstars_auto_upload_images" 
                                                   value="1"
                                                   <?php checked(get_option('wp_allstars_auto_upload_images', false)); ?> 
                                            />
                                            <span class="wp-toggle-slider"></span>
                                        </div>
                                        <label for="wp_allstars_auto_upload_images">
                                            <?php esc_html_e('Enable Auto Upload Images', 'wp-allstars'); ?>
                    </label>
                                    </div>
                                    </div>
                                    <p class="wp-setting-description">
                                        <?php esc_html_e('Import images that have external URLs into your Media Library when saving. Consider disabling during large data imports with many external image URLs.', 'wp-allstars'); ?>
                                    </p>
                                </div>
                                <div class="wp-allstars-toggle-settings">
                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_allstars_max_width"><?php esc_html_e('Max Width', 'wp-allstars'); ?></label>
                                        <input type="number" 
                                               id="wp_allstars_max_width"
                                               name="wp_allstars_max_width" 
                                               value="<?php echo esc_attr(get_option('wp_allstars_max_width', 2560)); ?>" 
                                        />
                                        <p class="description"><?php esc_html_e('Maximum width for uploaded images in pixels.', 'wp-allstars'); ?></p>
                                    </div>
                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_allstars_max_height"><?php esc_html_e('Max Height', 'wp-allstars'); ?></label>
                                        <input type="number" 
                                               id="wp_allstars_max_height"
                                               name="wp_allstars_max_height" 
                                               value="<?php echo esc_attr(get_option('wp_allstars_max_height', 2560)); ?>" 
                                        />
                                        <p class="description"><?php esc_html_e('Maximum height for uploaded images in pixels.', 'wp-allstars'); ?></p>
                                    </div>

                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_exclude_urls"><?php esc_html_e('Exclude URLs', 'wp-allstars'); ?></label>
                                        <textarea id="wp_exclude_urls"
                                                  name="wp_exclude_urls"
                                                  rows="3"
                                                  placeholder="example.com&#10;another-domain.com"
                                        ><?php echo esc_textarea(get_option('wp_allstars_exclude_urls', '')); ?></textarea>
                                        <p class="description"><?php esc_html_e('Enter domains to exclude (one per line). Images from these domains will not be imported.', 'wp-allstars'); ?></p>
                                    </div>

                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_image_name"><?php esc_html_e('Image Name Pattern', 'wp-allstars'); ?></label>
                                        <input type="text" 
                                               id="wp_image_name"
                                               name="wp_image_name"
                                               value="<?php echo esc_attr(get_option('wp_allstars_image_name_pattern', '%filename%')); ?>"
                                        />
                                        <p class="description">
                                            <?php esc_html_e('Available patterns:', 'wp-allstars'); ?> %filename%, %post_id%, %postname%, %timestamp%, %date%, %year%, %month%, %day%
                                        </p>
                                    </div>

                                    <div class="wp-allstars-setting-row">
                                        <label for="wp_image_alt"><?php esc_html_e('Image Alt Pattern', 'wp-allstars'); ?></label>
                                        <input type="text" 
                                               id="wp_image_alt"
                                               name="wp_image_alt"
                                               value="<?php echo esc_attr(get_option('wp_allstars_image_alt_pattern', '%filename%')); ?>"
                                        />
                                        <p class="description">
                                            <?php esc_html_e('Available patterns:', 'wp-allstars'); ?> %filename%, %post_title%, %post_id%, %postname%, %timestamp%
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'theme'): ?>
                    <div class="tab-content" id="theme">
                        <div class="wp-list-table-container">
                            <div id="wpa-theme-list"></div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'recommended'): ?>
                    <div class="tab-content" id="recommended">
                        <div id="wpa-plugin-filters" class="wp-filter">
                            <ul class="filter-links">
                                <li><a href="#" data-category="minimal" class="<?php echo $active_category == 'minimal' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Minimal', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="admin" class="<?php echo $active_category == 'admin' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Admin', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="ai" class="<?php echo $active_category == 'ai' ? 'current' : ''; ?>">
                                    <?php esc_html_e('AI', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="cms" class="<?php echo $active_category == 'cms' ? 'current' : ''; ?>">
                                    <?php esc_html_e('CMS', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="compliance" class="<?php echo $active_category == 'compliance' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Compliance', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="crm" class="<?php echo $active_category == 'crm' ? 'current' : ''; ?>">
                                    <?php esc_html_e('CRM', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="ecommerce" class="<?php echo $active_category == 'ecommerce' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Ecommerce', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="lms" class="<?php echo $active_category == 'lms' ? 'current' : ''; ?>">
                                    <?php esc_html_e('LMS', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="media" class="<?php echo $active_category == 'media' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Media', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="seo" class="<?php echo $active_category == 'seo' ? 'current' : ''; ?>">
                                    <?php esc_html_e('SEO', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="setup" class="<?php echo $active_category == 'setup' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Setup', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="social" class="<?php echo $active_category == 'social' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Social', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="speed" class="<?php echo $active_category == 'speed' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Speed', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="translation" class="<?php echo $active_category == 'translation' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Translation', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="advanced" class="<?php echo $active_category == 'advanced' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Advanced', 'wp-allstars'); ?>
                                </a></li>
                                <li><a href="#" data-category="debug" class="<?php echo $active_category == 'debug' ? 'current' : ''; ?>">
                                    <?php esc_html_e('Debug', 'wp-allstars'); ?>
                                </a></li>
                            </ul>
                        </div>

                        <div class="wp-allstars-plugin-browser">
                            <div id="wpa-plugin-list"></div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'pro'): ?>
                    <div class="tab-content" id="pro">
                        <div class="wpa-pro-plugins">
                            <?php
                            $pro_plugins = wp_allstars_get_pro_plugins_config();
                            foreach ($pro_plugins as $plugin) {
                                ?>
                                <div class="wpa-pro-plugin">
                                    <h3><?php echo esc_html($plugin['name']); ?></h3>
                                    <p><?php echo esc_html($plugin['description']); ?></p>
                                    <?php if (isset($plugin['button_group'])): ?>
                                        <div class="button-group">
                                            <?php foreach ($plugin['button_group'] as $button): ?>
                                                <a href="<?php echo esc_url($button['url']); ?>" target="_blank" class="button <?php echo isset($button['primary']) && $button['primary'] ? 'button-primary' : ''; ?>">
                                                    <?php echo esc_html($button['text']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="button-group">
                                            <?php if (!empty($plugin['demo_url'])): ?>
                                                <a href="<?php echo esc_url($plugin['demo_url']); ?>" class="button" target="_blank">
                                                    <?php esc_html_e('View Demo', 'wp-allstars'); ?>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url($plugin['url']); ?>" class="button button-primary" target="_blank">
                                                <?php esc_html_e('Learn More', 'wp-allstars'); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }
                            ?>
                        </div>

                        <style>
                        .wpa-pro-plugins {
                            padding: 20px;
                            display: grid;
                            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
                            gap: 24px;
                            max-width: 1920px;
                            margin: 0 auto;
                        }
                        .wpa-pro-plugin {
                            background: #fff;
                            border: 1px solid #ddd;
                            padding: 24px;
                            border-radius: 8px;
                            display: flex;
                            flex-direction: column;
                            transition: all 0.2s ease;
                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                        }
                        .wpa-pro-plugin:hover {
                            border-color: #2271b1;
                            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                        }
                        .wpa-pro-plugin h3 {
                            margin: 0 0 12px;
                            font-size: 16px;
                            font-weight: 600;
                            color: #1d2327;
                            line-height: 1.4;
                        }
                        .wpa-pro-plugin p {
                            margin: 0 0 16px;
                            color: #50575e;
                            font-size: 14px;
                            line-height: 1.6;
                        }
                        .wpa-pro-plugin .button-group {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 8px;
                            margin-top: auto;
                        }
                        .wpa-pro-plugin .button {
                            text-decoration: none;
                            min-width: 120px;
                            text-align: center;
                            height: 30px;
                            line-height: 28px;
                            padding: 0 12px;
                            font-size: 13px;
                            font-weight: normal;
                            margin: 0;
                            border: 1px solid #0071a1 !important;
                            border-radius: 3px !important;
                            background: #f6f7f7;
                            color: #0071a1;
                            display: inline-block;
                            vertical-align: top;
                            box-shadow: none;
                            cursor: pointer;
                        }
                        .wpa-pro-plugin .button:hover {
                            background: #f0f0f1;
                            border-color: #0071a1;
                            color: #0071a1;
                        }
                        .wpa-pro-plugin .button-primary {
                            background: #0071a1;
                            border-color: #0071a1;
                            color: #fff;
                        }
                        .wpa-pro-plugin .button-primary:hover {
                            background: #005d8c;
                            border-color: #005d8c;
                            color: #fff;
                        }
                        @media screen and (max-width: 960px) {
                            .wpa-pro-plugins {
                                grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
                                gap: 20px;
                                padding: 16px;
                            }
                            .wpa-pro-plugin {
                                padding: 20px;
                            }
                        }
                        @media screen and (max-width: 782px) {
                            .wpa-pro-plugins {
                                grid-template-columns: 1fr;
                                gap: 16px;
                                padding: 12px;
                            }
                            .wpa-pro-plugin {
                                padding: 16px;
                            }
                            .wpa-pro-plugin .button {
                                width: 100%;
                            }
                        }
                        </style>
                    </div>
                <?php elseif ($active_tab == 'general'): ?>
                    <div class="tab-content" id="general">
                        <div class="wp-allstars-settings-section">
                            <div class="wp-allstars-settings-grid">
                                <!-- Example of a simple toggle setting (no panel) -->
                                <div class="wp-setting-row">
                                    <div class="wp-setting-header">
                                        <div class="wp-setting-main">
                                            <div class="wp-setting-left">
                                                <div class="wp-toggle-switch">
                                                    <input type="checkbox" 
                                                           id="wp_allstars_simple_setting"
                                                           name="wp_allstars_simple_setting" 
                                                           value="1"
                                                           <?php checked(get_option('wp_allstars_simple_setting', false)); ?> 
                                                    />
                                                    <span class="wp-toggle-slider"></span>
                                                </div>
                                                <label for="wp_allstars_simple_setting" class="wp-setting-label">
                                                    <?php esc_html_e('Example: Simple Toggle', 'wp-allstars'); ?>
                    </label>
                                    </div>
                                    </div>
                                    <p class="wp-setting-description">
                                        <?php esc_html_e('This is an example of a simple toggle setting without an expandable panel. Currently for demonstration purposes only.', 'wp-allstars'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'advanced'): ?>
                    <div class="tab-content" id="advanced">
                        <div class="wp-allstars-settings-section">
                            <div class="wp-allstars-settings-grid">
                                <!-- Example of an expandable panel setting -->
                                <div class="wp-allstars-toggle">
                                    <div class="wp-allstars-toggle-header" aria-expanded="false">
                                        <div class="wp-allstars-toggle-main">
                                            <div class="wp-allstars-toggle-left">
                                                <div class="wp-toggle-switch">
                                                    <input type="checkbox" 
                                                           id="wp_allstars_auto_upload_images"
                                                           name="wp_allstars_auto_upload_images" 
                                                           value="1"
                                                           <?php checked(get_option('wp_allstars_auto_upload_images', false)); ?> 
                                                    />
                                                    <span class="wp-toggle-slider"></span>
                                                </div>
                                                <label for="wp_allstars_auto_upload_images">
                                                    <?php esc_html_e('Example: Expandable Panel', 'wp-allstars'); ?>
                    </label>
                                            </div>
                                        </div>
                                        <p class="wp-setting-description">
                                            <?php esc_html_e('This is an example of an expandable panel setting. Currently for demonstration purposes only - no actual functionality.', 'wp-allstars'); ?>
                                        </p>
                                    </div>
                                    <div class="wp-allstars-toggle-settings">
                                        <div class="wp-allstars-setting-row">
                                            <label for="example_text"><?php esc_html_e('Example Text Field', 'wp-allstars'); ?></label>
                                            <input type="text" 
                                                   id="example_text"
                                                   name="example_text"
                                                   value="Example value"
                                            />
                                            <p class="description"><?php esc_html_e('This is an example text field for demonstration purposes.', 'wp-allstars'); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// Enqueue admin scripts and styles
function wp_allstars_admin_enqueue_scripts($hook) {
    if ('settings_page_wp-allstars' !== $hook) {
        return;
    }

    wp_enqueue_style('wp-allstars-admin', plugins_url('css/wp-allstars-admin.css', __FILE__));
    wp_enqueue_script('wp-allstars-admin', plugins_url('js/wp-allstars-admin.js', __FILE__), array('jquery'), WP_ALLSTARS_VERSION, true);
    
    // Localize the script with new data
    wp_localize_script('wp-allstars-admin', 'wpAllstars', array(
        'nonce' => wp_create_nonce('wp-allstars-nonce'),
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
}
add_action('admin_enqueue_scripts', 'wp_allstars_admin_enqueue_scripts');