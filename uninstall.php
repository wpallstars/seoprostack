<?php
/**
 * Remove SEO Pro Stack options, caches, pending login links, uploaded profile
 * pictures, generated avatars, WebP/AVIF copies of pictures, short links and
 * SEO Pro Stack's must-use files on uninstall (every site on multisite).
 *
 * Imported media and Link card pictures (and their `_seoprostack_source_url`
 * / legacy `_wp_allstars_source_url` meta) are left in place because posts
 * reference them.
 * Screenshots also stay in the Media Library; their `_seoprostack_screenshot*`
 * meta is removed.
 * Watermarked pictures stay marked, and the folder of unmarked originals
 * (uploads/seoprostack-originals-*) is kept.
 * Scheduled posts stay scheduled; WordPress publishes them as normal.
 * Sticky items stay in core's sticky list, and posts ordered by hand keep
 * their menu_order. Other plugins' settings that
 * features imported from are never touched.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt (the starter's parts), SEOPROSTACK-ATTRIBUTION.txt
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

    // Restore exact autoload flags before removing the undo records.
    require_once __DIR__ . '/includes/class-seoprostack-feature.php';
    require_once __DIR__ . '/includes/features/class-seoprostack-autoload-options.php';
    SEOProStack_Autoload_Options::stop();
    delete_option(SEOProStack_Autoload_Options::RESET);

    // Add database keys: remove the keys it added to this site's tables
    // (named sps_…); other keys stay. Then its log goes with the options.
    require_once __DIR__ . '/includes/features/class-seoprostack-database-keys.php';
    require_once __DIR__ . '/includes/features/class-seoprostack-added-keys.php';
    SEOProStack_Added_Keys::remove_all();

    $options = array(
        'seoprostack_options',
        // The settings save lock, if a save stopped before releasing it.
        'seoprostack_options_lock',
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
    $options[] = 'seoprostack_maintenance_token';
    $options[] = 'seoprostack_short_links';
    $options[] = 'seoprostack_short_links_presets';
    $options[] = 'seoprostack_restricted_terms';
    $options[] = 'seoprostack_restrict_imported';
    $options[] = 'seoprostack_plugin_map';
    $options[] = 'seoprostack_plugin_menu';
    $options[] = 'seoprostack_plugin_screens';
    $options[] = 'seoprostack_plugin_front';
    $options[] = 'seoprostack_plugin_front_lock';
    $options[] = 'seoprostack_plugin_front_revision';
    $options[] = 'seoprostack_plugin_front_failed';
    $options[] = 'seoprostack_plugin_front_migrated';
    $options[] = 'seoprostack_plugin_front_forgets';
    $options[] = 'seoprostack_access_roles';
    $options[] = 'seoprostack_admin_bar_items';
    $options[] = 'seoprostack_admin_menu';
    $options[] = 'seoprostack_writer_types';
    $options[] = 'seoprostack_hosting_memory';
    $options[] = 'seoprostack_hosting_code';
    $options[] = 'seoprostack_hosting_traffic';
    $options[] = 'seoprostack_hosting_writes';
    $options[] = 'seoprostack_hosting_object_cache';
    $options[] = 'seoprostack_hosting_object_cache_lock';
    // Plugin sizes: the last Measure page time. Its token and each page's
    // findings are seoprostack_cost_* transients, removed with the others below.
    $options[] = 'seoprostack_plugin_cost';
    wp_cache_delete('hosting_probe', 'seoprostack');
    // Whether the site runs on a LiteSpeed server, for WP-CLI.
    $options[] = 'seoprostack_litespeed_server';
    // Ask before licence checks: choices and times. Kept answers are
    // seoprostack_lc_* transients, removed with the others below.
    $options[] = 'seoprostack_licence_calls';
    // Calls to other sites: counts and blocks.
    $options[] = 'seoprostack_outbound_calls';
    $options[] = 'seoprostack_outbound_blocks';
    // Plugin presets' undo copies. Settings presets changed in other plugins
    // are those plugins' settings now, and stay.
    $options[] = 'seoprostack_plugin_presets_undo';
    // Restore statements only: uninstall never changes database indexes.
    $options[] = 'seoprostack_database_keys_log';
    // Add database keys: its log (the keys it added were removed above).
    $options[] = SEOProStack_Added_Keys::OPTION;
    // Record of starter data added to other plugins. The lists, tags, fields
    // and boards themselves are that plugin's data now, and stay.
    $options[] = 'seoprostack_starters_added';
    // Old post addresses: when recording and clearing started.
    $options[] = 'seoprostack_old_slugs_since';
    $options[] = 'seoprostack_old_slugs_swept';
    // Brand icons: shapes of the icons used in Kadence blocks.
    $options[] = 'seoprostack_kadence_brand_icons';
    // Clean the database weekly: the last cleanup's counts.
    $options[] = 'seoprostack_database_cleanup_last';
    // Clean the database weekly: scheduled tasks seen with no code, and the
    // ones it removed (they stay removed).
    $options[] = 'seoprostack_database_cleanup_cron_seen';
    $options[] = 'seoprostack_database_cleanup_cron_removed';
    $options[] = 'seoprostack_deferred_counts_queue';
    // Linking caches and anonymous daily click totals. Approved links are
    // ordinary post content and remain; Link Whisper and Rank Math stay untouched.
    $options[] = 'seoprostack_link_index';
    $options[] = 'seoprostack_link_tables';
    foreach (array('seoprostack_links', 'seoprostack_link_health', 'seoprostack_link_clicks') as $suffix) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall removes only fixed plugin-owned table names.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $suffix));
    }
    foreach (array('_seoprostack_link_scan', '_seoprostack_link_map', '_seoprostack_link_undo') as $meta_key) {
        delete_post_meta_by_key($meta_key);
    }
    foreach (array('seoprostack_link_batch', 'seoprostack_link_click_prune', 'seoprostack_link_click_prune_more') as $hook) {
        wp_unschedule_hook($hook);
    }
    foreach ($options as $option) {
        delete_option($option);
    }
    wp_unschedule_hook('seoprostack_database_cleanup');
    wp_unschedule_hook('seoprostack_database_cleanup_more');
    wp_unschedule_hook('seoprostack_deferred_counts');

    // Copied content and excerpts stay; only the sync fingerprint is ours.
    delete_post_meta_by_key('_seoprostack_field_content_hash');

    // Order flow: links between form entries, tasks and conversations. The
    // entries, tasks, conversations and their log lines are that plugin's
    // data, and stay.
    $seoprostack_has_table = function ($table) use ($wpdb) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off check for another plugin's table.
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    };
    if ($seoprostack_has_table($wpdb->prefix . 'fluentform_submission_meta')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup in Fluent Forms' table.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fluentform_submission_meta WHERE meta_key IN (%s, %s)", '_seoprostack_order', 'seoprostack_client'));
    }
    if ($seoprostack_has_table($wpdb->prefix . 'fbs_task_metas')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup in Fluent Boards' table.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fbs_task_metas WHERE `key` IN (%s, %s)", 'seoprostack_order', 'seoprostack_client'));
    }

    // Notices kept for each person from plugins that some screens skip.
    delete_metadata('user', 0, $wpdb->get_blog_prefix() . 'seoprostack_stored_notices', '', true);

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
    // Background batch of watermarks.
    wp_unschedule_hook('seoprostack_watermark_batch');
    delete_option('seoprostack_watermark_job');

    // Screenshots stay in the Media Library because posts may use them;
    // only the records that matched them to pages go.
    wp_unschedule_hook('seoprostack_screenshot');
    foreach (array('_seoprostack_screenshot', '_seoprostack_screenshot_url', '_seoprostack_screenshot_taken') as $meta_key) {
        delete_post_meta_by_key($meta_key);
    }

    // Menu item visibility rules: those items show to everyone again (or
    // follow Nav Menu Roles' rules, if it is still installed).
    delete_post_meta_by_key('_seoprostack_menu_visibility');
    // Restrict content rules: those posts and categories show to everyone
    // again. Block rules are part of each post's content and do nothing
    // without SEO Pro Stack.
    delete_post_meta_by_key('_seoprostack_restrict');
    delete_metadata('term', 0, '_seoprostack_restrict', '', true);
    // Hand order of terms, and old term addresses kept for redirects. Posts
    // keep their order in core's menu_order (the pages' Order field).
    delete_metadata('term', 0, '_seoprostack_order', '', true);
    delete_metadata('term', 0, '_seoprostack_old_term', '', true);
    // When old post addresses last redirected someone. The old addresses
    // themselves are core's (_wp_old_slug) and keep redirecting.
    delete_post_meta_by_key('_seoprostack_old_slug_used');
    // Like totals, and what logged-in people liked and saved. Favorites'
    // own counts and favourites, if any, are untouched. Visitors' lists are
    // in their browsers only.
    delete_post_meta_by_key('_seoprostack_likes');
    delete_metadata('user', 0, $wpdb->get_blog_prefix() . 'seoprostack_saved', '', true);
    delete_metadata('user', 0, $wpdb->get_blog_prefix() . 'seoprostack_liked', '', true);

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
            if (is_file(substr($copy, 0, (int) strrpos($copy, '.')))) {
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
// Hidden "SEO Pro Stack can do the job of these plugins" lines.
delete_metadata('user', 0, 'seoprostack_replaced_plugins_hidden', '', true);
// Dismissed "Install Git Updater" notices (GitHub builds).
delete_metadata('user', 0, 'seoprostack_git_updater_dismissed', '', true);
// "Ask me again" in Ask before licence checks.
delete_metadata('user', 0, 'seoprostack_licence_later', '', true);
// Dashboard boxes Tidy the dashboard has unticked once in Screen Options
// (the boxes stay unticked: that is WordPress's own setting).
delete_metadata('user', 0, 'seoprostack_dashboard_start_hidden', '', true);
// Hidden "network-activated plugins" notices (multisite).
delete_metadata('user', 0, 'seoprostack_network_plugins_hidden', '', true);
// Plugins moved from the network to each site, kept for Undo. The plugins
// stay activated on each site.
delete_site_option('seoprostack_network_moved');

// Plugin caches, network-wide because plugins are shared by every site. On
// single sites these calls remove the ordinary option and transient.
delete_site_option('seoprostack_plugin_sizes');
delete_site_transient('seoprostack_plugin_names');
delete_site_option('seoprostack_nextgen_rules');
// Latest GitHub releases (the shared GitHub updater, and its cache from
// before it was shared). Only a cache: another plugin's copy asks again.
delete_site_transient('wpallstars_github_releases');
delete_site_transient('seoprostack_github_releases');
// Fixes for other plugins: Comment Goblin's update server failed recently.
delete_site_transient('seoprostack_comment_goblin_failed');
// Fixes for other plugins: what the LiteSpeed noabort block holds (the
// block itself is removed on deactivation).
delete_site_option('seoprostack_noabort_rules');
// Turn off unused remote access: the log and backup files block, which
// deactivation removes unless a file could not be written then.
$seoprostack_files = (array) get_site_option('seoprostack_hardening_files', array());
foreach (isset($seoprostack_files['targets']) && is_array($seoprostack_files['targets']) ? $seoprostack_files['targets'] : array() as $seoprostack_htaccess) {
    if (!is_string($seoprostack_htaccess) || '.htaccess' !== basename($seoprostack_htaccess) || !is_file($seoprostack_htaccess) || !wp_is_writable($seoprostack_htaccess)) {
        continue;
    }
    $seoprostack_contents = (string) file_get_contents($seoprostack_htaccess); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
    $seoprostack_cleaned  = preg_replace('/# BEGIN SEO Pro Stack log and backup files\r?\n.*?# END SEO Pro Stack log and backup files[^\n]*(\n|$)\s*/s', '', $seoprostack_contents);
    if (null !== $seoprostack_cleaned && $seoprostack_cleaned !== $seoprostack_contents) {
        if ('' === trim($seoprostack_cleaned)) {
            wp_delete_file($seoprostack_htaccess);
        } else {
            file_put_contents($seoprostack_htaccess, $seoprostack_cleaned, LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- as insert_with_markers().
        }
    }
}
delete_site_option('seoprostack_hardening_files');

// The must-use files of "Load plugins only where needed" and of a Measure
// page time that did not finish (Plugin sizes), if they are ours.
$seoprostack_mu_files = array(
    'seoprostack-plugin-loading.php'   => 'seoprostack-plugin-loading',
    '000-seoprostack-plugin-cost.php' => 'SEO Pro Stack page time profiler',
);
foreach ($seoprostack_mu_files as $seoprostack_mu_file => $seoprostack_marker) {
    $seoprostack_mu_file = WPMU_PLUGIN_DIR . '/' . $seoprostack_mu_file;
    if (!is_file($seoprostack_mu_file)) {
        continue;
    }
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
    $seoprostack_fs = new WP_Filesystem_Direct(null);
    if (false !== strpos((string) $seoprostack_fs->get_contents($seoprostack_mu_file), $seoprostack_marker)) {
        $seoprostack_fs->delete($seoprostack_mu_file);
    }
}
