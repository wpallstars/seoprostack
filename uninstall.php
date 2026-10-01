<?php
/**
 * Remove SEO Pro Stack options, caches, pending login links, uploaded profile
 * pictures, generated avatars, WebP/AVIF copies of pictures, short links and
 * the plugin-loading must-use file on uninstall (every site on multisite).
 *
 * Imported media (and its `_seoprostack_source_url` / legacy
 * `_wp_allstars_source_url` meta) is left in place because posts reference it.
 * Screenshots also stay in the Media Library; their `_seoprostack_screenshot*`
 * meta is removed.
 * Watermarked pictures stay marked, and the folder of unmarked originals
 * (uploads/seoprostack-originals-*) is kept.
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
    $options[] = 'seoprostack_nextgen_synced';
    $options[] = 'seoprostack_watermark_dir';
    $options[] = 'seoprostack_cpt_base_taken';
    $options[] = 'seoprostack_short_links';
    $options[] = 'seoprostack_plugin_map';
    $options[] = 'seoprostack_plugin_menu';
    $options[] = 'seoprostack_admin_bar_items';
    foreach ($options as $option) {
        delete_option($option);
    }

    // Short links and their categories. Pretty Links' own links, if any
    // were imported, are untouched.
    register_taxonomy('sps_short_link_cat', 'sps_short_link');
    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup, in batches.
        $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sps_short_link' LIMIT 200");
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
    } while ($ids);
    $terms = get_terms(array('taxonomy' => 'sps_short_link_cat', 'hide_empty' => false, 'fields' => 'ids'));
    foreach (is_array($terms) ? $terms : array() as $term_id) {
        wp_delete_term((int) $term_id, 'sps_short_link_cat');
    }

    // WebP and AVIF copies (photo.jpg.webp, photo.jpg.avif) of every picture
    // and size. Deactivation already removed the rules that served them.
    wp_unschedule_hook('seoprostack_nextgen_batch');
    seoprostack_uninstall_nextgen_copies();
    delete_post_meta_by_key('_seoprostack_nextgen');
    delete_post_meta_by_key('_seoprostack_nextgen_done');

    // Watermarks stay on pictures. The unmarked originals are the site
    // owner's pictures, so their folder (uploads/seoprostack-originals-*)
    // is kept; only the records linking them to pictures go.
    delete_post_meta_by_key('_seoprostack_watermark');

    // Screenshots stay in the Media Library because posts may use them;
    // only the records that matched them to pages go.
    wp_unschedule_hook('seoprostack_screenshot');
    foreach (array('_seoprostack_screenshot', '_seoprostack_screenshot_url', '_seoprostack_screenshot_taken') as $meta_key) {
        delete_post_meta_by_key($meta_key);
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

/**
 * Delete the WebP and AVIF copies made next to the current site's pictures.
 * Files that are Media Library items themselves are kept.
 */
function seoprostack_uninstall_nextgen_copies() {
    global $wpdb;

    $last = 0;
    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup, in batches.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND ID > %d ORDER BY ID LIMIT 200",
            $last
        ));
        foreach ($ids as $id) {
            $last = (int) $id;
            $file = get_attached_file($last);
            if (!$file) {
                continue;
            }
            $meta  = wp_get_attachment_metadata($last);
            $names = array(wp_basename($file));
            if (is_array($meta)) {
                foreach (isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array() as $size) {
                    if (!empty($size['file'])) {
                        $names[] = wp_basename($size['file']);
                    }
                }
                if (!empty($meta['original_image'])) {
                    $names[] = wp_basename($meta['original_image']);
                }
            }
            foreach (array_unique($names) as $name) {
                foreach (array('webp', 'avif') as $format) {
                    $copy = dirname($file) . '/' . $name . '.' . $format;
                    if (!is_file($copy)) {
                        continue;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only when such a file exists.
                    $item = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", _wp_relative_upload_path($copy)));
                    if (!$item) {
                        wp_delete_file($copy);
                    }
                }
            }
        }
        wp_cache_flush_runtime();
    } while ($ids);

    seoprostack_uninstall_orphan_copies();
}

/**
 * Delete copies whose picture is gone: pictures deleted while the plugin was
 * inactive leave theirs behind. Only photo.jpg.webp-style files whose
 * photo.jpg no longer exists, and that are not Media Library items.
 */
function seoprostack_uninstall_orphan_copies() {
    global $wpdb;

    $uploads = wp_get_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir']) || !is_dir($uploads['basedir'])) {
        return;
    }
    $base = untrailingslashit(wp_normalize_path($uploads['basedir']));
    // Other sites' folders are below the main site's; each site sweeps its own.
    $skip = is_multisite() && is_main_site() ? array($base . '/sites') : array();

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        function ($current) use ($skip) {
            return !$current->isDir() || !in_array(wp_normalize_path($current->getPathname()), $skip, true);
        }
    );
    $files = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    try {
        foreach ($files as $file) {
            $name = $file->getFilename();
            if (!preg_match('/\.(jpe?g|png|webp)\.(webp|avif)$/i', $name)) {
                continue;
            }
            $copy = wp_normalize_path($file->getPathname());
            if (is_file(substr($copy, 0, strrpos($copy, '.')))) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only for orphan-looking files.
            $item = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", ltrim(substr($copy, strlen($base)), '/')));
            if (!$item) {
                wp_delete_file($copy);
            }
        }
    } catch (Exception $e) {
        // An unreadable folder: leave what is left.
        return;
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
delete_site_option('seoprostack_nextgen_rules');

// The must-use file of "Load plugins only where needed", if it is ours.
$seoprostack_loader = WPMU_PLUGIN_DIR . '/seoprostack-plugin-loading.php';
if (is_file($seoprostack_loader)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
    $seoprostack_fs = new WP_Filesystem_Direct(null);
    if (false !== strpos((string) $seoprostack_fs->get_contents($seoprostack_loader), 'seoprostack-plugin-loading')) {
        $seoprostack_fs->delete($seoprostack_loader);
    }
}
