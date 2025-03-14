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

// Settings page HTML
function wpa_superstar_settings_page() {
    $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';
    ?>
    <div class="wrap wpa-superstar-wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=wpa-superstar&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'General', 'wpa-superstar' ); ?>
            </a>
            <a href="?page=wpa-superstar&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Advanced', 'wpa-superstar' ); ?>
            </a>
        </h2>

        <form method="post" action="options.php">
            <?php settings_fields( 'wpa-superstar-settings' ); ?>
            <?php do_settings_sections( 'wpa-superstar-settings' ); ?>

            <?php if ( $active_tab == 'general' ) : ?>
                <div class="wpa-superstar-toggle">
                    <label for="wpa_superstar_lazy_load">
                        <input type="checkbox" 
                               id="wpa_superstar_lazy_load"
                               name="wpa_superstar_lazy_load" 
                               value="1" 
                               <?php checked( get_option( 'wpa_superstar_lazy_load', 1 ) ); ?> 
                        />
                        <?php esc_html_e( 'Enable lazy loading for images', 'wpa-superstar' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Improves page load time by loading images only when they enter the viewport.', 'wpa-superstar' ); ?>
                    </p>
                </div>
            <?php elseif ( $active_tab == 'advanced' ) : ?>
                <div class="wpa-superstar-toggle">
                    <label for="wpa_superstar_minify_css">
                        <input type="checkbox" 
                               id="wpa_superstar_minify_css"
                               name="wpa_superstar_minify_css" 
                               value="1" 
                               <?php checked( get_option( 'wpa_superstar_minify_css', 0 ) ); ?> 
                        />
                        <?php esc_html_e( 'Enable CSS minification', 'wpa-superstar' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Minifies CSS files to reduce file size and improve load times.', 'wpa-superstar' ); ?>
                    </p>
                </div>

                <div class="wpa-superstar-toggle">
                    <label for="wpa_superstar_minify_js">
                        <input type="checkbox" 
                               id="wpa_superstar_minify_js"
                               name="wpa_superstar_minify_js" 
                               value="1" 
                               <?php checked( get_option( 'wpa_superstar_minify_js', 0 ) ); ?> 
                        />
                        <?php esc_html_e( 'Enable JS minification', 'wpa-superstar' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Minifies JavaScript files to reduce file size and improve load times.', 'wpa-superstar' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php submit_button( __( 'Save Changes', 'wpa-superstar' ) ); ?>
        </form>
    </div>
    <?php
}