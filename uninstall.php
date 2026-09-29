<?php
/**
 * Remove WP Allstars options and caches on uninstall (every site on multisite).
 *
 * Imported media (and its `_wp_allstars_source_url` meta) is left in place
 * because posts reference it.
 *
 * @package WP_ALLSTARS
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete WP Allstars options and transients for the current site.
 */
function wp_allstars_uninstall_site() {
    global $wpdb;

    $options = array(
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

    foreach ($options as $option) {
        delete_option($option);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our transients.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_wp_allstars_') . '%',
            $wpdb->esc_like('_transient_timeout_wp_allstars_') . '%'
        )
    );
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $wp_allstars_site_id) {
        switch_to_blog($wp_allstars_site_id);
        wp_allstars_uninstall_site();
        restore_current_blog();
    }
} else {
    wp_allstars_uninstall_site();
}
