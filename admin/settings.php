<?php
/**
 * Admin settings page
 */

// Add menu item
function wpa_superstar_admin_menu() {
    add_options_page(
        'WPA Superstar Settings',
        'WPA Superstar',
        'manage_options',
        'wpa-superstar',
        'wpa_superstar_settings_page'
    );
}
add_action('admin_menu', 'wpa_superstar_admin_menu');

// Register settings
function wpa_superstar_register_settings() {
    register_setting('wpa-superstar-settings', 'wpa_superstar_lazy_load');
    register_setting('wpa-superstar-settings', 'wpa_superstar_minify_css');
    register_setting('wpa-superstar-settings', 'wpa_superstar_minify_js');
}
add_action('admin_init', 'wpa_superstar_register_settings');

// AJAX handler for settings
function wpa_superstar_update_option() {
    check_ajax_referer('wpa-superstar-nonce', 'nonce');
    $option = sanitize_text_field($_POST['option']);
    $value = intval($_POST['value']);
    update_option($option, $value);
    wp_send_json_success('Option updated');
}
add_action('wp_ajax_wpa_superstar_update_option', 'wpa_superstar_update_option');

// Define recommended plugins
function wpa_superstar_get_recommended_plugins() {
    return array(
        'minimal' => array(
            'advanced-custom-fields',
            'admin-menu-editor',
            'antispam-bee',
            'burst-statistics',
            'freesoul-deactivate-plugins',
            'plugin-toggle',
            'pretty-links',
            'seo-by-rank-math',
            'turnstile'
        ),
        'advanced' => array(
            'fluent-crm',
            'fluentform',
            'fluent-smtp',
            'fluent-support'
        ),
        'ecommerce' => array(
            'woocommerce',
            'client-booking'
        ),
        'lms' => array(
            // Add LMS plugins when needed
        )
    );
}

// Filter plugins API to show only our recommended plugins
function wpa_superstar_plugins_api_result($res, $action, $args) {
    if ($action !== 'query_plugins') {
        return $res;
    }

    if (!empty($_GET['page']) && $_GET['page'] === 'wpa-superstar') {
        $category = isset($_GET['category']) ? sanitize_key($_GET['category']) : 'minimal';
        $recommended_plugins = wpa_superstar_get_recommended_plugins();
        
        if (isset($recommended_plugins[$category])) {
            // Get plugin data for our recommended plugins
            $args = (object) array(
                'per_page' => 100,
                'fields' => array(
                    'last_updated' => true,
                    'active_installs' => true,
                    'downloaded' => true,
                    'icons' => true,
                ),
                'locale' => get_user_locale(),
                'slugs' => $recommended_plugins[$category]
            );
            
            $query = plugins_api('query_plugins', $args);
            
            if (!is_wp_error($query)) {
                return $query;
            }
        }
    }
    
    return $res;
}
add_filter('plugins_api_result', 'wpa_superstar_plugins_api_result', 10, 3);

// Settings page HTML
function wpa_superstar_settings_page() {
    global $tabs;
    
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'recommended';
    $active_category = isset($_GET['category']) ? $_GET['category'] : 'minimal';
    
    // Ensure required files are loaded
    if ($active_tab === 'recommended') {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        wp_enqueue_script('plugin-install');
        wp_enqueue_script('updates');
        add_thickbox();
    }
    ?>
    <div class="wrap wpa-superstar-wrap">
        <div class="wpa-superstar-header">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="wpa-superstar-header-actions">
                <span class="wpa-superstar-version">Version <?php echo esc_html(WPA_SUPERSTAR_VERSION); ?></span>
                <a href="https://www.wpallstars.com/docs/superstar-plugin/" target="_blank" class="button button-secondary">
                    <?php esc_html_e('Documentation', 'wpa-superstar'); ?>
                </a>
            </div>
        </div>
        
        <div class="wpa-settings-container">
            <div class="wpa-superstar-nav">
                <h2 class="nav-tab-wrapper">
                    <a href="?page=wpa-superstar&tab=recommended" class="nav-tab <?php echo $active_tab == 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Free Plugins', 'wpa-superstar'); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('General', 'wpa-superstar'); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e('Advanced', 'wpa-superstar'); ?>
                    </a>
                </h2>
            </div>

            <div class="wpa-settings-content">
                <?php if ($active_tab == 'recommended'): ?>
                    <div class="wpa-plugin-filters">
                        <a href="?page=wpa-superstar&tab=recommended&category=minimal" 
                           class="button <?php echo $active_category == 'minimal' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Minimal', 'wpa-superstar'); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=advanced" 
                           class="button <?php echo $active_category == 'advanced' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Advanced', 'wpa-superstar'); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=ecommerce" 
                           class="button <?php echo $active_category == 'ecommerce' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Ecommerce', 'wpa-superstar'); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=lms" 
                           class="button <?php echo $active_category == 'lms' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('LMS', 'wpa-superstar'); ?>
                        </a>
                    </div>

                    <div class="wp-list-table-container">
                        <?php
                        $wp_list_table = _get_list_table('WP_Plugin_Install_List_Table');
                        $wp_list_table->prepare_items();
                        $wp_list_table->display();
                        ?>
                    </div>

                <?php elseif ($active_tab == 'general'): ?>
                    <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_lazy_load">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_lazy_load"
                                       name="wpa_superstar_lazy_load" 
                                       value="1" 
                                       <?php checked(get_option('wpa_superstar_lazy_load', 1)); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable lazy loading for images', 'wpa-superstar'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Improves page load time by loading images only when they enter the viewport.', 'wpa-superstar'); ?>
                        </p>
                    </div>

                <?php elseif ($active_tab == 'advanced'): ?>
                    <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_minify_css">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_minify_css"
                                       name="wpa_superstar_minify_css" 
                                       value="1" 
                                       <?php checked(get_option('wpa_superstar_minify_css', 0)); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable CSS minification', 'wpa-superstar'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Minifies CSS files to reduce file size and improve load times.', 'wpa-superstar'); ?>
                        </p>
                    </div>

                    <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_minify_js">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_minify_js"
                                       name="wpa_superstar_minify_js" 
                                       value="1" 
                                       <?php checked(get_option('wpa_superstar_minify_js', 0)); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e('Enable JS minification', 'wpa-superstar'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Minifies JavaScript files to reduce file size and improve load times.', 'wpa-superstar'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}