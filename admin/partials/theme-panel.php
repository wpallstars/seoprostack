<?php
/**
 * Theme card template.
 *
 * Variables: $theme_data (themes_api object), $author (string).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}
if (!isset($theme_data, $author) || !is_object($theme_data)) {
    return;
}

$seoprostack_slug      = SEOProStack_Theme_Manager::SLUG;
$seoprostack_name      = isset($theme_data->name) ? (string) $theme_data->name : 'Kadence';
$seoprostack_installed = wp_get_theme($seoprostack_slug);
$seoprostack_is_active = get_stylesheet() === $seoprostack_slug;
// The installed theme's own screenshot comes from this site, so a host or
// security plugin that only allows images from the site itself (an img-src
// Content Security Policy) or a browser blocker cannot stop it. Otherwise use
// wordpress.org's, which themes_api gives without a scheme ("//ts.w.org/…").
$seoprostack_screenshot = $seoprostack_installed->exists() ? $seoprostack_installed->get_screenshot() : '';
if (!$seoprostack_screenshot && !empty($theme_data->screenshot_url)) {
    $seoprostack_screenshot = (string) $theme_data->screenshot_url;
    if (0 === strpos($seoprostack_screenshot, '//')) {
        $seoprostack_screenshot = 'https:' . $seoprostack_screenshot;
    }
}
$seoprostack_links     = array(
    // Kadence moved to Liquid Web in 2026; kadencewp.com pages redirect there.
    array('text' => __('Starter templates', 'seoprostack'), 'url' => 'https://www.liquidweb.com/software/kadence/kadence-template-gallery/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1'),
    array('text' => __('Kadence Blocks', 'seoprostack'), 'url' => 'https://www.liquidweb.com/software/kadence/blocks/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1'),
    array('text' => __('Shop Kit', 'seoprostack'), 'url' => 'https://www.liquidweb.com/software/kadence/shop-kit/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1'),
    array('text' => __('Pricing', 'seoprostack'), 'url' => 'https://www.liquidweb.com/software/kadence/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1#pricing'),
    array('text' => __('Kadence bundles', 'seoprostack'), 'url' => 'https://www.liquidweb.com/software/kadence/?irpid=4858868&utm_medium=affiliate&irgwc=1&afsrc=1'),
);
?>
<article class="sps-card sps-theme-card">
    <div class="sps-theme-card__media">
        <?php if ($seoprostack_screenshot) : ?>
            <img src="<?php echo esc_url($seoprostack_screenshot); ?>" alt="<?php echo esc_attr(sprintf(/* translators: %s: theme name */ __('%s screenshot', 'seoprostack'), $seoprostack_name)); ?>" />
        <?php endif; ?>
    </div>
    <div class="sps-theme-card__body">
        <h3 class="sps-theme-card__title">
            <?php echo esc_html($seoprostack_name); ?>
            <?php if ($seoprostack_is_active) : ?>
                <span class="sps-badge sps-badge--success"><?php esc_html_e('Active', 'seoprostack'); ?></span>
            <?php elseif ($seoprostack_installed->exists()) : ?>
                <span class="sps-badge"><?php esc_html_e('Installed', 'seoprostack'); ?></span>
            <?php endif; ?>
        </h3>
        <?php if ($author) : ?>
            <p class="sps-theme-card__meta"><?php echo esc_html(sprintf(/* translators: %s: author */ __('By %s', 'seoprostack'), $author)); ?>
                <?php if (!empty($theme_data->version)) : ?>
                    · <?php echo esc_html(sprintf(/* translators: %s: version */ __('Version %s', 'seoprostack'), $theme_data->version)); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="sps-theme-card__actions">
            <?php if ($seoprostack_is_active) : ?>
                <a class="button button-primary" href="<?php echo esc_url(admin_url(wp_is_block_theme() ? 'site-editor.php' : 'customize.php')); ?>"><?php esc_html_e('Customize', 'seoprostack'); ?></a>
            <?php elseif ($seoprostack_installed->exists() && current_user_can('switch_themes')) : ?>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('themes.php?action=activate&stylesheet=' . rawurlencode($seoprostack_slug)), 'switch-theme_' . $seoprostack_slug)); ?>"><?php esc_html_e('Activate', 'seoprostack'); ?></a>
            <?php elseif (current_user_can('install_themes')) : ?>
                <a class="button button-primary sps-theme-install"
                   data-slug="<?php echo esc_attr($seoprostack_slug); ?>"
                   href="<?php echo esc_url(wp_nonce_url(self_admin_url('update.php?action=install-theme&theme=' . rawurlencode($seoprostack_slug)), 'install-theme_' . $seoprostack_slug)); ?>">
                    <?php esc_html_e('Install', 'seoprostack'); ?>
                </a>
            <?php endif; ?>

            <?php if (!empty($theme_data->preview_url)) : ?>
                <a class="button" href="<?php echo esc_url($theme_data->preview_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Preview', 'seoprostack'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span></a>
            <?php endif; ?>
        </div>

        <ul class="sps-theme-card__links">
            <?php foreach ($seoprostack_links as $seoprostack_link) : ?>
                <li><a href="<?php echo esc_url($seoprostack_link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($seoprostack_link['text']); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span></a></li>
            <?php endforeach; ?>
            <li><a href="https://www.liquidweb.com/software/kadence/theme/?irpid=4858868&amp;utm_medium=affiliate&amp;irgwc=1&amp;afsrc=1" target="_blank" rel="noopener noreferrer"><strong><?php esc_html_e('Kadence Pro', 'seoprostack'); ?></strong><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span></a></li>
        </ul>
    </div>
</article>
