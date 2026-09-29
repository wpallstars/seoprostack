<?php
/**
 * Theme card template.
 *
 * Variables: $theme_data (themes_api object), $author (string).
 *
 * @package WP_ALLSTARS
 */

if (!defined('ABSPATH')) {
    exit;
}

$wp_allstars_slug      = WP_Allstars_Theme_Manager::SLUG;
$wp_allstars_installed = wp_get_theme($wp_allstars_slug);
$wp_allstars_is_active = get_stylesheet() === $wp_allstars_slug;
$wp_allstars_links     = array(
    array('text' => __('Starter templates', 'wp-allstars'), 'url' => 'https://www.kadencewp.com/kadence-theme/starter-templates/'),
    array('text' => __('Kadence AI', 'wp-allstars'), 'url' => 'https://www.kadencewp.com/wordpress-solutions/kadence-ai/'),
    array('text' => __('Marketplace', 'wp-allstars'), 'url' => 'https://www.kadencewp.com/kadence-theme/marketplace/'),
    array('text' => __('Pricing', 'wp-allstars'), 'url' => 'https://www.kadencewp.com/pricing/'),
);
?>
<article class="wpa-card wpa-theme-card">
    <div class="wpa-theme-card__media">
        <img src="<?php echo esc_url($theme_data->screenshot_url); ?>" alt="<?php echo esc_attr(sprintf(/* translators: %s: theme name */ __('%s screenshot', 'wp-allstars'), $theme_data->name)); ?>" loading="lazy" />
    </div>
    <div class="wpa-theme-card__body">
        <h3 class="wpa-theme-card__title">
            <?php echo esc_html($theme_data->name); ?>
            <?php if ($wp_allstars_is_active) : ?>
                <span class="wpa-badge wpa-badge--success"><?php esc_html_e('Active', 'wp-allstars'); ?></span>
            <?php elseif ($wp_allstars_installed->exists()) : ?>
                <span class="wpa-badge"><?php esc_html_e('Installed', 'wp-allstars'); ?></span>
            <?php endif; ?>
        </h3>
        <?php if ($author) : ?>
            <p class="wpa-theme-card__meta"><?php echo esc_html(sprintf(/* translators: %s: author */ __('By %s', 'wp-allstars'), $author)); ?>
                <?php if (!empty($theme_data->version)) : ?>
                    · <?php echo esc_html(sprintf(/* translators: %s: version */ __('Version %s', 'wp-allstars'), $theme_data->version)); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="wpa-theme-card__actions">
            <?php if ($wp_allstars_is_active) : ?>
                <a class="button button-primary" href="<?php echo esc_url(admin_url(wp_is_block_theme() ? 'site-editor.php' : 'customize.php')); ?>"><?php esc_html_e('Customize', 'wp-allstars'); ?></a>
            <?php elseif ($wp_allstars_installed->exists() && current_user_can('switch_themes')) : ?>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('themes.php?action=activate&stylesheet=' . rawurlencode($wp_allstars_slug)), 'switch-theme_' . $wp_allstars_slug)); ?>"><?php esc_html_e('Activate', 'wp-allstars'); ?></a>
            <?php elseif (current_user_can('install_themes')) : ?>
                <a class="button button-primary wpa-theme-install"
                   data-slug="<?php echo esc_attr($wp_allstars_slug); ?>"
                   href="<?php echo esc_url(wp_nonce_url(self_admin_url('update.php?action=install-theme&theme=' . rawurlencode($wp_allstars_slug)), 'install-theme_' . $wp_allstars_slug)); ?>">
                    <?php esc_html_e('Install', 'wp-allstars'); ?>
                </a>
            <?php endif; ?>

            <?php if (!empty($theme_data->preview_url)) : ?>
                <a class="button" href="<?php echo esc_url($theme_data->preview_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Preview', 'wp-allstars'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'wp-allstars'); ?></span></a>
            <?php endif; ?>
        </div>

        <ul class="wpa-theme-card__links">
            <?php foreach ($wp_allstars_links as $wp_allstars_link) : ?>
                <li><a href="<?php echo esc_url($wp_allstars_link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($wp_allstars_link['text']); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'wp-allstars'); ?></span></a></li>
            <?php endforeach; ?>
            <li><a href="https://www.kadencewp.com/kadence-theme/" target="_blank" rel="noopener noreferrer"><strong><?php esc_html_e('Kadence Pro', 'wp-allstars'); ?></strong><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'wp-allstars'); ?></span></a></li>
        </ul>
    </div>
</article>
