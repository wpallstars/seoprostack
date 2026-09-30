<?php
/**
 * Remove SEO Pro Stack options, caches, pending login links, uploaded profile
 * pictures and generated avatars on uninstall (every site on multisite).
 *
 * Imported media (and its `_seoprostack_source_url` / legacy
 * `_wp_allstars_source_url` meta) is left in place because posts reference it.
 * Scheduled posts stay scheduled; WordPress publishes them as normal.
 * Sticky items stay in core's sticky list. Other plugins' settings that
 * features imported from are never touched.
 *
 * @package SEOProStack
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete SEO Pro Stack options and transients for the current site.
 */
function seoprostack_uninstall_site() {
    global $wpdb;

    $options = array(
        'seoprostack_options',
        'seoprostack_db_version',
        // Development builds released as "WP Allstars".
        'wp_allstars_options',
        'wp_allstars_db_version',
        // Pre-0.3.0 options.
        'wp_allstars_admin_color_scheme',
        'wp_allstars_simple_setting',
        'wp_allstars_auto_upload_images',
        'wp_allstars_max_width',
        'wp_allstars_max_height',
        'wp_allstars_exclude_urls',
        'wp_allstars_image_name_pattern',
        'wp_allstars_image_alt_pattern',
        'wp_allstars_workflow_options',
        'wp_allstars_hide_admin_bar',
        'wp_allstars_hide_admin_bar_roles',
        'wp_allstars_restrict_dashboard',
        'wp_allstars_restrict_dashboard_roles',
        'wp_allstars_plugins_cached',
    );

    $options[] = 'seoprostack_dashboard_widgets';
    foreach ($options as $option) {
        delete_option($option);
    }

    // Shareable preview links stop working; staged versions and duplicates
    // become ordinary drafts and posts.
    foreach (array('_seoprostack_preview', '_seoprostack_version_of', '_seoprostack_original') as $meta_key) {
        delete_post_meta_by_key($meta_key);
    }
    wp_unschedule_hook('seoprostack_merge_version');

    // Generated avatars, and on the main site uploaded profile pictures.
    $uploads = wp_get_upload_dir();
    if (empty($uploads['error']) && !empty($uploads['basedir']) && is_dir($uploads['basedir'] . '/seoprostack-avatars')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $filesystem = new WP_Filesystem_Direct(null);
        $filesystem->delete($uploads['basedir'] . '/seoprostack-avatars', true, 'd');
    }

    $patterns = array('_transient_seoprostack_', '_transient_timeout_seoprostack_', '_transient_wp_allstars_', '_transient_timeout_wp_allstars_');
    foreach ($patterns as $pattern) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our transients.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($pattern) . '%'));
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $seoprostack_site_id) {
        switch_to_blog($seoprostack_site_id);
        seoprostack_uninstall_site();
        restore_current_blog();
    }
} else {
    seoprostack_uninstall_site();
}

// Unused magic login links and profile pictures (user meta is network-wide).
delete_metadata('user', 0, '_seoprostack_magic_login', '', true);
delete_metadata('user', 0, 'seoprostack_avatar', '', true);

// Plugin caches, network-wide because plugins are shared by every site. On
// single sites these calls remove the ordinary option and transient.
delete_site_option('seoprostack_plugin_sizes');
delete_site_transient('seoprostack_plugin_names');
