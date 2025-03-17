<?php
/**
 * Theme panel template for WP Allstars
 *
 * @package WP_Allstars
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="theme-card">
    <div class="theme-image">
        <img src="<?php echo esc_url($theme_data->screenshot_url); ?>" alt="<?php echo esc_attr($theme_data->name); ?>">
    </div>
    <div class="theme-info">
        <h3 class="theme-name"><?php echo esc_html($theme_data->name); ?></h3>
        <p class="theme-author"><?php echo esc_html(sprintf(__('By %s'), $author)); ?></p>
    </div>
    <div class="theme-actions">
        <?php if (current_user_can('install_themes')): ?>
            <?php 
            $installed_theme = wp_get_theme('kadence');
            $current_theme = wp_get_theme();
            $is_active = ($current_theme->get_stylesheet() === 'kadence');
            $nonce = wp_create_nonce('wp-allstars-nonce');
            
            if ($is_active): ?>
                <button type="button" class="button button-disabled" disabled="disabled">
                    <?php esc_html_e('Active'); ?>
                </button>
            <?php elseif ($installed_theme->exists()): ?>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('themes.php?action=activate&stylesheet=kadence'), 'switch-theme_kadence')); ?>" 
                   class="button button-primary activate-now" 
                   data-slug="kadence" 
                   data-name="Kadence"
                   data-nonce="<?php echo esc_attr($nonce); ?>">
                    <?php esc_html_e('Activate'); ?>
                </a>
            <?php else: ?>
                <?php 
                // Use the standard WordPress theme installation URL format
                $install_url = wp_nonce_url(
                    admin_url('update.php?action=install-theme&theme=kadence'),
                    'install-theme_kadence'
                );
                ?>
                <a href="<?php echo esc_url($install_url); ?>" 
                   class="button button-primary install-now" 
                   data-slug="kadence" 
                   data-name="Kadence">
                    <?php esc_html_e('Install'); ?>
                </a>
            <?php endif; ?>
            <script>
            jQuery(document).ready(function($) {
                // Make sure theme handlers are initialized
                if (typeof initThemeHandlers === 'function') {
                    console.log('Initializing theme handlers from template');
                    initThemeHandlers();
                }
            });
            </script>
        <?php endif; ?>
        <a class="button button-secondary preview install-theme-preview" href="<?php echo esc_url($theme_data->preview_url); ?>" target="_blank">
            <?php esc_html_e('Preview'); ?>
        </a>
        <a class="button button-primary" href="https://www.kadencewp.com/kadence-theme/" target="_blank">
            <?php esc_html_e('Go Pro'); ?>
        </a>
    </div>
</div>
