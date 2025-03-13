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
}
add_action( 'admin_init', 'wpa_superstar_register_settings' );

// Settings page HTML
function wpa_superstar_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'wpa-superstar-settings' ); ?>
            <?php do_settings_sections( 'wpa-superstar-settings' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Lazy Load Images</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpa_superstar_lazy_load" value="1" <?php checked( get_option( 'wpa_superstar_lazy_load', 1 ) ); ?> />
                            Enable lazy loading for images
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}