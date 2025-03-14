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
add_action( 'admin_menu', 'wpa_superstar_admin_menu' );

// Register settings
function wpa_superstar_register_settings() {
    register_setting( 'wpa-superstar-settings', 'wpa_superstar_lazy_load' );
    register_setting( 'wpa-superstar-settings', 'wpa_superstar_minify_css' );
    register_setting( 'wpa-superstar-settings', 'wpa_superstar_minify_js' );
}
add_action( 'admin_init', 'wpa_superstar_register_settings' );

// AJAX handler
function wpa_superstar_update_option() {
    check_ajax_referer( 'wpa-superstar-nonce', 'nonce' );
    $option = sanitize_text_field( $_POST['option'] );
    $value = intval( $_POST['value'] );
    update_option( $option, $value );
    wp_send_json_success( 'Option updated' );
}
add_action( 'wp_ajax_wpa_superstar_update_option', 'wpa_superstar_update_option' );

// Define recommended plugins
function wpa_superstar_get_recommended_plugins() {
    return array(
        'utilities' => array(
            'name' => 'Recommended',
            'plugins' => array(
                array(
                    'slug' => 'advanced-custom-fields',
                    'name' => 'Advanced Custom Fields',
                    'description' => 'Customize WordPress with powerful, professional and intuitive fields.',
                    'author' => 'WP Engine',
                    'category' => 'recommended'
                ),
                array(
                    'slug' => 'admin-menu-editor',
                    'name' => 'Admin Menu Editor',
                    'description' => 'Customize the WordPress admin menu.',
                    'author' => 'Janis Elsts',
                    'category' => 'recommended'
                ),
                array(
                    'slug' => 'antispam-bee',
                    'name' => 'Antispam Bee',
                    'description' => 'Antispam plugin with a sophisticated toolset for effective spam protection.',
                    'author' => 'pluginkollektiv',
                    'category' => 'recommended'
                ),
                array(
                    'slug' => 'burst-statistics',
                    'name' => 'Burst Statistics',
                    'description' => 'Privacy-friendly analytics tool.',
                    'author' => 'Really Simple Plugins',
                    'category' => 'recommended'
                )
            )
        ),
        'fluent' => array(
            'name' => 'Advanced',
            'plugins' => array(
                array(
                    'slug' => 'fluent-crm',
                    'name' => 'Fluent CRM',
                    'description' => 'Marketing Automation for WordPress.',
                    'author' => 'WP Fluent',
                    'category' => 'advanced'
                ),
                array(
                    'slug' => 'fluentform',
                    'name' => 'Fluent Forms',
                    'description' => 'Advanced Contact Form Plugin.',
                    'author' => 'WP Fluent',
                    'category' => 'advanced'
                ),
                array(
                    'slug' => 'fluent-smtp',
                    'name' => 'Fluent SMTP',
                    'description' => 'The Most Advanced SMTP & Email Service Integration Plugin.',
                    'author' => 'WP Fluent',
                    'category' => 'advanced'
                ),
                array(
                    'slug' => 'fluent-support',
                    'name' => 'Fluent Support',
                    'description' => 'Customer Support & Helpdesk Plugin.',
                    'author' => 'WP Fluent',
                    'category' => 'advanced'
                )
            )
        ),
        'ecommerce' => array(
            'name' => 'Ecommerce',
            'plugins' => array(
                array(
                    'slug' => 'woocommerce',
                    'name' => 'WooCommerce',
                    'description' => 'An eCommerce toolkit that helps you sell anything.',
                    'author' => 'Automattic',
                    'category' => 'ecommerce'
                ),
                array(
                    'slug' => 'client-booking',
                    'name' => 'Client Bookings',
                    'description' => 'Appointment Scheduling & Booking.',
                    'author' => 'WP Fluent',
                    'category' => 'ecommerce'
                )
            )
        ),
        'lms' => array(
            'name' => 'LMS',
            'plugins' => array(
                // Add LMS plugins here when needed
            )
        )
    );
}

// Settings page HTML
function wpa_superstar_settings_page() {
    $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'recommended';
    $active_category = isset( $_GET['category'] ) ? $_GET['category'] : 'recommended';
    $recommended_plugins = wpa_superstar_get_recommended_plugins();
    ?>
    <div class="wrap wpa-superstar-wrap">
        <div class="wpa-superstar-header">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <div class="wpa-superstar-header-actions">
                <span class="wpa-superstar-version">Version <?php echo esc_html( WPA_SUPERSTAR_VERSION ); ?></span>
                <a href="https://www.wpallstars.com/docs/superstar-plugin/" target="_blank" class="button button-secondary">
                    <?php esc_html_e( 'Documentation', 'wpa-superstar' ); ?>
                </a>
            </div>
        </div>
        
        <div class="wpa-settings-container">
            <div class="wpa-superstar-nav">
                <h2 class="nav-tab-wrapper">
                    <a href="?page=wpa-superstar&tab=recommended" class="nav-tab <?php echo $active_tab == 'recommended' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'Recommended', 'wpa-superstar' ); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'General', 'wpa-superstar' ); ?>
                    </a>
                    <a href="?page=wpa-superstar&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'Advanced', 'wpa-superstar' ); ?>
                    </a>
                </h2>
            </div>

            <div class="wpa-settings-content">
                <?php if ( $active_tab == 'recommended' ) : ?>
                    <div class="wpa-plugin-filters">
                        <a href="?page=wpa-superstar&tab=recommended&category=recommended" 
                           class="button <?php echo $active_category == 'recommended' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e( 'Recommended', 'wpa-superstar' ); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=advanced" 
                           class="button <?php echo $active_category == 'advanced' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e( 'Advanced', 'wpa-superstar' ); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=ecommerce" 
                           class="button <?php echo $active_category == 'ecommerce' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e( 'Ecommerce', 'wpa-superstar' ); ?>
                        </a>
                        <a href="?page=wpa-superstar&tab=recommended&category=lms" 
                           class="button <?php echo $active_category == 'lms' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e( 'LMS', 'wpa-superstar' ); ?>
                        </a>
                    </div>

                    <div class="wpa-plugins-browser">
                        <?php foreach ( $recommended_plugins as $section ) :
                            foreach ( $section['plugins'] as $plugin ) :
                                if ( $plugin['category'] === $active_category ) :
                                    $installed = is_dir( WP_PLUGIN_DIR . '/' . $plugin['slug'] );
                                    $install_url = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'action' => 'install-plugin',
                                                'plugin' => $plugin['slug']
                                            ),
                                            admin_url( 'update.php' )
                                        ),
                                        'install-plugin_' . $plugin['slug']
                                    );
                        ?>
                        <div class="plugin-card">
                            <div class="plugin-card-top">
                                <div class="name column-name">
                                    <h3>
                                        <?php echo esc_html( $plugin['name'] ); ?>
                                        <span class="plugin-author">
                                            <?php printf( esc_html__( 'By %s', 'wpa-superstar' ), esc_html( $plugin['author'] ) ); ?>
                                        </span>
                                    </h3>
                                </div>
                                <div class="desc column-description">
                                    <p><?php echo esc_html( $plugin['description'] ); ?></p>
                                </div>
                                <div class="action-links">
                                    <ul class="plugin-action-buttons">
                                        <li>
                                            <?php if ( $installed ) : ?>
                                                <button class="button button-disabled" disabled><?php esc_html_e( 'Installed', 'wpa-superstar' ); ?></button>
                                            <?php else : ?>
                                                <a href="<?php echo esc_url( $install_url ); ?>" class="button button-primary">
                                                    <?php esc_html_e( 'Install Now', 'wpa-superstar' ); ?>
                                                </a>
                                            <?php endif; ?>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <?php 
                                endif;
                            endforeach;
                        endforeach; 
                        ?>
                    </div>

                <?php elseif ( $active_tab == 'general' ) : ?>
                    <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_lazy_load">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_lazy_load"
                                       name="wpa_superstar_lazy_load" 
                                       value="1" 
                                       <?php checked( get_option( 'wpa_superstar_lazy_load', 1 ) ); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e( 'Enable lazy loading for images', 'wpa-superstar' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Improves page load time by loading images only when they enter the viewport.', 'wpa-superstar' ); ?>
                        </p>
                    </div>
                <?php elseif ( $active_tab == 'advanced' ) : ?>
                    <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_minify_css">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_minify_css"
                                       name="wpa_superstar_minify_css" 
                                       value="1" 
                                       <?php checked( get_option( 'wpa_superstar_minify_css', 0 ) ); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e( 'Enable CSS minification', 'wpa-superstar' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Minifies CSS files to reduce file size and improve load times.', 'wpa-superstar' ); ?>
                        </p>
                    </div>

                    <div class="wpa-superstar-toggle">
                        <label for="wpa_superstar_minify_js">
                            <div class="wpa-toggle-switch">
                                <input type="checkbox" 
                                       id="wpa_superstar_minify_js"
                                       name="wpa_superstar_minify_js" 
                                       value="1" 
                                       <?php checked( get_option( 'wpa_superstar_minify_js', 0 ) ); ?> 
                                />
                                <span class="wpa-toggle-slider"></span>
                            </div>
                            <?php esc_html_e( 'Enable JS minification', 'wpa-superstar' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Minifies JavaScript files to reduce file size and improve load times.', 'wpa-superstar' ); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}