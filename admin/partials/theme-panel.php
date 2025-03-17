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
            if ($installed_theme->exists()): ?>
                <button type="button" class="button button-primary activate-now" data-slug="kadence" data-nonce="<?php echo esc_attr(wp_create_nonce('wp-allstars-nonce')); ?>">
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
        <a class="button button-primary" href="https://www.kadencewp.com/kadence-theme/" target="_blank">
            <?php esc_html_e('Go Pro'); ?>
        </a>
    </div>
</div>
