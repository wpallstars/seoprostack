<?php
/**
 * Theme card template.
 *
 * Variables: $theme_data (themes_api object), $author (string).
 *
 * @package Allstars
 */

if (!defined('ABSPATH')) {
    exit;
}

$allstars_slug      = Allstars_Theme_Manager::SLUG;
$allstars_installed = wp_get_theme($allstars_slug);
$allstars_is_active = get_stylesheet() === $allstars_slug;
$allstars_links     = array(
    // Kadence moved to Liquid Web in 2026; kadencewp.com pages redirect there.
    array('text' => __('Starter templates', 'allstars'), 'url' => 'https://www.liquidweb.com/software/kadence/kadence-template-gallery/'),
    array('text' => __('Kadence Blocks', 'allstars'), 'url' => 'https://www.liquidweb.com/software/kadence/blocks/'),
    array('text' => __('Shop Kit', 'allstars'), 'url' => 'https://www.liquidweb.com/software/kadence/shop-kit/'),
    array('text' => __('Pricing', 'allstars'), 'url' => 'https://www.liquidweb.com/software/kadence/#pricing'),
);
?>
<article class="wpa-card wpa-theme-card">
    <div class="wpa-theme-card__media">
        <img src="<?php echo esc_url($theme_data->screenshot_url); ?>" alt="<?php echo esc_attr(sprintf(/* translators: %s: theme name */ __('%s screenshot', 'allstars'), $theme_data->name)); ?>" loading="lazy" />
    </div>
    <div class="wpa-theme-card__body">
        <h3 class="wpa-theme-card__title">
            <?php echo esc_html($theme_data->name); ?>
            <?php if ($allstars_is_active) : ?>
                <span class="wpa-badge wpa-badge--success"><?php esc_html_e('Active', 'allstars'); ?></span>
            <?php elseif ($allstars_installed->exists()) : ?>
                <span class="wpa-badge"><?php esc_html_e('Installed', 'allstars'); ?></span>
            <?php endif; ?>
        </h3>
        <?php if ($author) : ?>
            <p class="wpa-theme-card__meta"><?php echo esc_html(sprintf(/* translators: %s: author */ __('By %s', 'allstars'), $author)); ?>
                <?php if (!empty($theme_data->version)) : ?>
                    · <?php echo esc_html(sprintf(/* translators: %s: version */ __('Version %s', 'allstars'), $theme_data->version)); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="wpa-theme-card__actions">
            <?php if ($allstars_is_active) : ?>
                <a class="button button-primary" href="<?php echo esc_url(admin_url(wp_is_block_theme() ? 'site-editor.php' : 'customize.php')); ?>"><?php esc_html_e('Customize', 'allstars'); ?></a>
            <?php elseif ($allstars_installed->exists() && current_user_can('switch_themes')) : ?>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('themes.php?action=activate&stylesheet=' . rawurlencode($allstars_slug)), 'switch-theme_' . $allstars_slug)); ?>"><?php esc_html_e('Activate', 'allstars'); ?></a>
            <?php elseif (current_user_can('install_themes')) : ?>
                <a class="button button-primary wpa-theme-install"
                   data-slug="<?php echo esc_attr($allstars_slug); ?>"
                   href="<?php echo esc_url(wp_nonce_url(self_admin_url('update.php?action=install-theme&theme=' . rawurlencode($allstars_slug)), 'install-theme_' . $allstars_slug)); ?>">
                    <?php esc_html_e('Install', 'allstars'); ?>
                </a>
            <?php endif; ?>

            <?php if (!empty($theme_data->preview_url)) : ?>
                <a class="button" href="<?php echo esc_url($theme_data->preview_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Preview', 'allstars'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'allstars'); ?></span></a>
            <?php endif; ?>
        </div>

        <ul class="wpa-theme-card__links">
            <?php foreach ($allstars_links as $allstars_link) : ?>
                <li><a href="<?php echo esc_url($allstars_link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($allstars_link['text']); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'allstars'); ?></span></a></li>
            <?php endforeach; ?>
            <li><a href="https://www.liquidweb.com/software/kadence/theme/" target="_blank" rel="noopener noreferrer"><strong><?php esc_html_e('Kadence Pro', 'allstars'); ?></strong><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'allstars'); ?></span></a></li>
        </ul>
    </div>
</article>
