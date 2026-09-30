<?php
/**
 * Replace media files.
 *
 * Upload a new file for an existing Media Library item. The item keeps its
 * ID, title, alt text, caption and every place it is used; links to the old
 * file (and its smaller sizes) in posts are changed to the new file.
 *
 * - "Keep the file name": the new file takes the old name and address (same
 *   type only), so links from other sites keep working.
 * - "Use the new file's name": the new file keeps its own name, in the old
 *   file's folder.
 *
 * Links are updated in post content and excerpts, and in custom fields that
 * hold text, JSON (page builders) or serialized arrays, for posts that are
 * published, scheduled, drafts, pending or private.
 *
 * The new file goes through the normal upload checks, so file type limits,
 * SVG cleaning and "Resize large uploads" apply.
 *
 * Replaces "Enable Media Replace" and imports its last-used choices.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Replace_Media extends SEOProStack_Feature {

    const KEY = 'replace_media';

    const PAGE = 'seoprostack-replace-media';

    const ACTION = 'seoprostack_replace_media';

    const FIELD = 'seoprostack_file';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'media',
                'label'       => __('Replace media files', 'seoprostack'),
                'description' => __('Adds “Replace file” to Media Library items. Upload a new file and the item keeps its title, alt text and every place it is used; links in posts are changed to the new file.', 'seoprostack'),
                'replaces'    => array('enable-media-replace' => 'Enable Media Replace'),
            ),
            'replace_media_name' => array(
                'type'        => 'select',
                'default'     => 'keep',
                'parent'      => self::KEY,
                'label'       => __('File name', 'seoprostack'),
                'description' => __('Chosen first on the Replace file screen.', 'seoprostack'),
                'options'     => array(
                    'keep' => __('Keep the file name', 'seoprostack'),
                    'new'  => __('Use the new file’s name', 'seoprostack'),
                ),
            ),
            'replace_media_date' => array(
                'type'        => 'select',
                'default'     => 'keep',
                'parent'      => self::KEY,
                'label'       => __('Upload date', 'seoprostack'),
                'description' => __('Chosen first on the Replace file screen.', 'seoprostack'),
                'options'     => array(
                    'keep' => __('Keep the upload date', 'seoprostack'),
                    'now'  => __('Change it to now', 'seoprostack'),
                ),
            ),
        );
    }

    /**
     * Import Enable Media Replace's last-used choices, and switch on while it
     * is active.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (isset(self::active_plugins()['enable-media-replace'])) {
            $options = self::import_setting($options, self::KEY, true);
        }
        $emr = get_option('enable_media_replace');
        if (is_array($emr)) {
            if (isset($emr['replace_type'])) {
                $options = self::import_setting($options, 'replace_media_name', 'replace' === $emr['replace_type'] ? 'keep' : 'new');
            }
            if (isset($emr['timestamp_replace'])) {
                // 1: set the date to now; 2: keep it; 3: a custom date.
                $options = self::import_setting($options, 'replace_media_date', 1 === (int) $emr['timestamp_replace'] ? 'now' : 'keep');
            }
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_head', array(__CLASS__, 'hide_menu'));
        add_filter('media_row_actions', array(__CLASS__, 'row_action'), 10, 2);
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'modal_link'), 10, 2);
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Whether the current user may replace an attachment's file.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function can_replace($attachment_id) {
        $post = get_post($attachment_id);
        return $post && 'attachment' === $post->post_type && current_user_can('upload_files') && current_user_can('edit_post', $post->ID);
    }

    /**
     * Address of the Replace file screen.
     *
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    public static function url($attachment_id) {
        return add_query_arg(array('page' => self::PAGE, 'attachment' => (int) $attachment_id), admin_url('upload.php'));
    }

    /* --------------------------------------------------------------------- */
    /* Links                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Screen under Media, hidden from the menu.
     */
    public static function menu() {
        add_submenu_page('upload.php', __('Replace file', 'seoprostack'), __('Replace file', 'seoprostack'), 'upload_files', self::PAGE, array(__CLASS__, 'render'));
    }

    /**
     * Hide the screen from the menu after the page title has been worked out.
     */
    public static function hide_menu() {
        remove_submenu_page('upload.php', self::PAGE);
    }

    /**
     * Row action in Media → Library (list view).
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    Attachment.
     * @return array
     */
    public static function row_action($actions, $post) {
        if (self::can_replace($post->ID)) {
            $actions['seoprostack_replace'] = sprintf(
                '<a href="%1$s" aria-label="%2$s">%3$s</a>',
                esc_url(self::url($post->ID)),
                /* translators: %s: attachment title */
                esc_attr(sprintf(__('Replace the file of “%s”', 'seoprostack'), get_the_title($post))),
                esc_html__('Replace file', 'seoprostack')
            );
        }
        return $actions;
    }

    /**
     * Link in the attachment details (media modal and edit screen). Opens a
     * new tab so a post being edited is not left.
     *
     * @param array   $fields Fields.
     * @param WP_Post $post   Attachment.
     * @return array
     */
    public static function modal_link($fields, $post) {
        if ($post && self::can_replace($post->ID)) {
            $fields['seoprostack_replace'] = array(
                'label' => __('File', 'seoprostack'),
                'input' => 'html',
                'html'  => sprintf(
                    '<a class="button" href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a>',
                    esc_url(self::url($post->ID)),
                    esc_html__('Replace file', 'seoprostack'),
                    /* translators: accessibility text */
                    esc_html__('(opens in a new tab)', 'seoprostack')
                ),
            );
        }
        return $fields;
    }

    /* --------------------------------------------------------------------- */
    /* Screen                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Replace file screen.
     */
    public static function render() {
        $id = isset($_GET['attachment']) ? absint(wp_unslash($_GET['attachment'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- form display; the action checks a nonce.
        if (!$id || !self::can_replace($id)) {
            wp_die(esc_html__('Sorry, you are not allowed to edit this item.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
        $post  = get_post($id);
        $file  = get_attached_file($id);
        $url   = wp_get_attachment_url($id);
        $meta  = wp_get_attachment_metadata($id);
        $name  = SEOProStack_Settings::get('replace_media_name');
        $date  = SEOProStack_Settings::get('replace_media_date');
        $error = isset($_GET['sps_replace_error']) ? sanitize_text_field(wp_unslash($_GET['sps_replace_error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $size  = $file && is_file($file) ? size_format((int) filesize($file)) : '';

        $details = array(wp_basename($file ? $file : (string) $url), $post->post_mime_type);
        if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) {
            /* translators: 1: width, 2: height */
            $details[] = sprintf(__('%1$d × %2$d pixels', 'seoprostack'), $meta['width'], $meta['height']);
        }
        if ($size) {
            $details[] = $size;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(sprintf(/* translators: %s: attachment title */ __('Replace file: %s', 'seoprostack'), get_the_title($post))); ?></h1>
            <?php if ($error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <div style="display:flex;gap:16px;align-items:center;margin:16px 0">
                <?php echo wp_get_attachment_image($id, array(120, 120), true, array('style' => 'max-width:120px;height:auto')); ?>
                <p><strong><?php esc_html_e('Current file', 'seoprostack'); ?></strong><br><?php echo esc_html(implode(' · ', $details)); ?><br>
                    <a href="<?php echo esc_url((string) $url); ?>" target="_blank" rel="noopener"><?php esc_html_e('View file', 'seoprostack'); ?></a>
                    · <a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php esc_html_e('Edit details', 'seoprostack'); ?></a></p>
            </div>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <input type="hidden" name="attachment" value="<?php echo esc_attr((string) $id); ?>">
                <?php wp_nonce_field(self::ACTION . '_' . $id); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::FIELD); ?>"><?php esc_html_e('New file', 'seoprostack'); ?></label></th>
                        <td>
                            <input type="file" id="<?php echo esc_attr(self::FIELD); ?>" name="<?php echo esc_attr(self::FIELD); ?>" required>
                            <p class="description"><?php
                                /* translators: %s: size, like 64 MB */
                                echo esc_html(sprintf(__('Largest file: %s.', 'seoprostack'), size_format(wp_max_upload_size())));
                            ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('File name', 'seoprostack'); ?></th>
                        <td><fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('File name', 'seoprostack'); ?></legend>
                            <label><input type="radio" name="name" value="keep" <?php checked('keep', $name); ?>> <?php esc_html_e('Keep the file name', 'seoprostack'); ?></label>
                            <p class="description" style="margin:0 0 8px 24px"><?php esc_html_e('The file keeps its address, so links from other sites still work. The new file must be the same type. Browsers may show the old file until their cache clears.', 'seoprostack'); ?></p>
                            <label><input type="radio" name="name" value="new" <?php checked('new', $name); ?>> <?php esc_html_e('Use the new file’s name', 'seoprostack'); ?></label>
                            <p class="description" style="margin:0 0 0 24px"><?php esc_html_e('Any type of file. Links from other sites to the old file will stop working.', 'seoprostack'); ?></p>
                        </fieldset></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Upload date', 'seoprostack'); ?></th>
                        <td><fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Upload date', 'seoprostack'); ?></legend>
                            <label><input type="radio" name="date" value="keep" <?php checked('keep', $date); ?>> <?php esc_html_e('Keep the upload date', 'seoprostack'); ?></label><br>
                            <label><input type="radio" name="date" value="now" <?php checked('now', $date); ?>> <?php esc_html_e('Change it to now', 'seoprostack'); ?></label>
                        </fieldset></td>
                    </tr>
                </table>
                <p><?php esc_html_e('The old file and its smaller sizes are deleted. Links to them in posts are changed to the new file.', 'seoprostack'); ?></p>
                <?php submit_button(__('Replace file', 'seoprostack')); ?>
            </form>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------- */
    /* Replacing                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * admin-post.php?action=seoprostack_replace_media
     */
    public static function handle() {
        $id = isset($_POST['attachment']) ? absint(wp_unslash($_POST['attachment'])) : 0;
        check_admin_referer(self::ACTION . '_' . $id);
        if (!$id || !self::can_replace($id)) {
            wp_die(esc_html__('Sorry, you are not allowed to edit this item.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
        $name = isset($_POST['name']) && 'new' === $_POST['name'] ? 'new' : 'keep';
        $date = isset($_POST['date']) && 'now' === $_POST['date'] ? 'now' : 'keep';

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by wp_handle_upload().
        $upload = isset($_FILES[self::FIELD]) && is_array($_FILES[self::FIELD]) ? $_FILES[self::FIELD] : null;
        $result = $upload ? self::replace($id, $upload, $name, 'now' === $date) : new WP_Error('replace_no_file', __('Choose a file to upload.', 'seoprostack'));

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('sps_replace_error', rawurlencode($result->get_error_message()), self::url($id)));
            exit;
        }
        wp_safe_redirect(add_query_arg(array('post' => $id, 'action' => 'edit', 'sps_replaced' => $result), admin_url('post.php')));
        exit;
    }

    /**
     * Replace an attachment's file.
     *
     * @param int    $attachment_id Attachment ID.
     * @param array  $upload        Entry from $_FILES.
     * @param string $name          keep or new.
     * @param bool   $date_now      Set the upload date to now.
     * @return int|WP_Error Number of posts whose links were updated.
     */
    public static function replace($attachment_id, array $upload, $name = 'keep', $date_now = false) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = (int) $attachment_id;
        $old_file      = get_attached_file($attachment_id, true);
        $uploads       = wp_get_upload_dir();
        if (!$old_file || !empty($uploads['error']) || !self::in_uploads($old_file)) {
            return new WP_Error('replace_old_file', __('This item’s file is not in the uploads folder, so it cannot be replaced.', 'seoprostack'));
        }
        $old_meta   = wp_get_attachment_metadata($attachment_id);
        $old_meta   = is_array($old_meta) ? $old_meta : array();
        $old_backup = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        $old_urls   = self::file_urls($attachment_id, $old_file, $old_meta, $old_backup);

        if ('keep' === $name) {
            $old_ext = strtolower(pathinfo($old_file, PATHINFO_EXTENSION));
            $new_ext = strtolower(pathinfo(isset($upload['name']) ? (string) $upload['name'] : '', PATHINFO_EXTENSION));
            if (!self::same_type($old_file, isset($upload['name']) ? (string) $upload['name'] : '')) {
                return new WP_Error('replace_type', sprintf(
                    /* translators: 1: file extension, like JPG, 2: file extension */
                    __('To keep the file name, the new file must be a %1$s file, not %2$s. Choose “Use the new file’s name” instead.', 'seoprostack'),
                    strtoupper($old_ext),
                    $new_ext ? strtoupper($new_ext) : __('this type', 'seoprostack')
                ));
            }
        }

        // Upload into the old file's folder, through every normal check.
        $dir    = dirname($old_file);
        $folder = function ($upload_dir) use ($dir, $uploads) {
            $subdir               = substr(wp_normalize_path($dir), strlen(wp_normalize_path($uploads['basedir'])));
            $upload_dir['path']   = $dir;
            $upload_dir['url']    = $uploads['baseurl'] . $subdir;
            $upload_dir['subdir'] = $subdir;
            return $upload_dir;
        };
        add_filter('upload_dir', $folder, 99);
        $new = wp_handle_upload($upload, array('test_form' => false));
        remove_filter('upload_dir', $folder, 99);
        if (!is_array($new) || !empty($new['error']) || empty($new['file'])) {
            return new WP_Error('replace_upload', is_array($new) && !empty($new['error']) ? $new['error'] : __('The file could not be uploaded.', 'seoprostack'));
        }
        $new_file = $new['file'];

        // Delete the old file, its sizes and any edited copies, then put the
        // new file in place.
        wp_delete_attachment_files($attachment_id, $old_meta, is_array($old_backup) ? $old_backup : array(), $old_file);
        delete_post_meta($attachment_id, '_wp_attachment_backup_sizes');
        // (Resize large uploads may have saved a BMP as JPEG: keep that name.)
        if ('keep' === $name && $new_file !== $old_file && self::same_type($old_file, $new_file)) {
            clearstatcache();
            if (!is_file($old_file) && @rename($new_file, $old_file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same folder.
                $new_file = $old_file;
            }
        }

        update_attached_file($attachment_id, $new_file);
        $fields = array('post_mime_type' => $new['type'], 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', true));
        if ($date_now) {
            $fields['post_date']     = $fields['post_modified'];
            $fields['post_date_gmt'] = $fields['post_modified_gmt'];
        }
        global $wpdb;
        $wpdb->update($wpdb->posts, $fields, array('ID' => $attachment_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- wp_update_post() would re-filter the description.
        clean_post_cache($attachment_id);

        // Sizes: remove the old list first so WordPress makes every size.
        delete_post_meta($attachment_id, '_wp_attachment_metadata');
        $new_meta = wp_generate_attachment_metadata($attachment_id, $new_file);
        if (is_array($new_meta)) {
            wp_update_attachment_metadata($attachment_id, $new_meta);
        }
        $new_file = get_attached_file($attachment_id, true);
        $new_meta = is_array($new_meta) ? $new_meta : array();

        $posts = self::update_links($old_urls, self::file_urls($attachment_id, $new_file, $new_meta, array()));
        delete_transient('dirsize_cache');

        /**
         * After an attachment's file is replaced. Use it to purge caches.
         *
         * @param int    $attachment_id Attachment ID.
         * @param string $old_file      Old file path (now deleted, unless the name was kept).
         * @param string $new_file      New file path.
         * @param int[]  $posts         IDs of posts whose links were updated.
         */
        do_action('seoprostack_media_replaced', $attachment_id, $old_file, $new_file, $posts);

        return count($posts);
    }

    /**
     * Whether two file names have the same type of extension.
     *
     * @param string $a File name or path.
     * @param string $b File name or path.
     * @return bool
     */
    private static function same_type($a, $b) {
        $a    = strtolower(pathinfo($a, PATHINFO_EXTENSION));
        $b    = strtolower(pathinfo($b, PATHINFO_EXTENSION));
        $jpeg = array('jpg', 'jpeg', 'jpe');
        return '' !== $a && ($a === $b || (in_array($a, $jpeg, true) && in_array($b, $jpeg, true)));
    }

    /**
     * Whether a path is inside the uploads folder.
     *
     * @param string $file File path.
     * @return bool
     */
    private static function in_uploads($file) {
        $uploads = wp_get_upload_dir();
        $base    = trailingslashit(wp_normalize_path($uploads['basedir']));
        $file    = wp_normalize_path($file);
        return 0 === strpos($file, $base) && false === strpos(substr($file, strlen($base)), '../');
    }

    /**
     * Address paths of an attachment's files, from the uploads folder's path
     * on the site (like /wp-content/uploads/2024/10/photo.jpg), keyed by size
     * name ('' for the file, 'original' for the kept original). These match
     * full and root-relative links, over http or https.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file          Attached file path.
     * @param array  $meta          Metadata.
     * @param mixed  $backup        Backup sizes from image edits.
     * @return array<string,string>
     */
    private static function file_urls($attachment_id, $file, array $meta, $backup) {
        $uploads = wp_get_upload_dir();
        $base    = trailingslashit(wp_normalize_path($uploads['basedir']));
        $prefix  = untrailingslashit((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH)) . '/';
        $main    = ltrim(substr(wp_normalize_path($file), strlen($base)), '/');
        $dir     = dirname($main);
        $dir     = $prefix . ('.' === $dir ? '' : $dir . '/');
        $main    = $prefix . $main;

        $paths = array('' => $main);
        if (!empty($meta['original_image'])) {
            $paths['original'] = $dir . wp_basename($meta['original_image']);
        }
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size => $data) {
                if (!empty($data['file'])) {
                    $paths['size:' . $size] = $dir . wp_basename($data['file']);
                }
            }
        }
        if (is_array($backup)) {
            foreach ($backup as $key => $data) {
                if (!empty($data['file'])) {
                    $paths['backup:' . $key] = $dir . wp_basename($data['file']);
                }
            }
        }
        return $paths;
    }

    /**
     * Change links to the old files into links to the new ones: each size to
     * the same size, or to the new file when the new file has no such size.
     *
     * @param array<string,string> $old Old relative paths.
     * @param array<string,string> $new New relative paths.
     * @return int[] IDs of posts updated.
     */
    private static function update_links(array $old, array $new) {
        global $wpdb;

        $map = array();
        foreach ($old as $key => $path) {
            $target = isset($new[$key]) ? $new[$key] : $new[''];
            if ($path !== $target) {
                $map[$path] = $target;
            }
        }
        // Sizes that are identical in name now point at new pictures already.
        if (!$map) {
            return array();
        }
        // Paths written in JSON (page builders) escape their slashes.
        $replace = array();
        foreach ($map as $from => $to) {
            $replace[$from]                            = $to;
            $replace[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
        }

        $likes = array();
        foreach (array_keys($replace) as $from) {
            $likes[] = '%' . $wpdb->esc_like($from) . '%';
        }
        $statuses = "'publish', 'future', 'draft', 'pending', 'private'";
        $updated  = array();

        // Post content and excerpts.
        $where = implode(' OR ', array_fill(0, count($likes), 'post_content LIKE %s OR post_excerpt LIKE %s'));
        $args  = array();
        foreach ($likes as $like) {
            $args[] = $like;
            $args[] = $like;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- placeholders built above.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status IN ($statuses) AND ($where)", $args));
        foreach ((array) $rows as $row) {
            $content = strtr($row->post_content, $replace);
            $excerpt = strtr($row->post_excerpt, $replace);
            if ($content !== $row->post_content || $excerpt !== $row->post_excerpt) {
                $wpdb->update($wpdb->posts, array('post_content' => $content, 'post_excerpt' => $excerpt), array('ID' => $row->ID)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a link change, not an edit: no revision or content filters.
                $updated[(int) $row->ID] = true;
            }
        }

        // Custom fields.
        $where = implode(' OR ', array_fill(0, count($likes), 'm.meta_value LIKE %s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- placeholders built above.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type NOT IN ('revision', 'attachment') AND p.post_status IN ($statuses) AND ($where)", $likes));
        foreach ((array) $rows as $row) {
            $value = self::replace_value($row->meta_value, $replace);
            if (null !== $value && $value !== $row->meta_value) {
                $wpdb->update($wpdb->postmeta, array('meta_value' => $value), array('meta_id' => $row->meta_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- by meta_id.
                wp_cache_delete((int) $row->post_id, 'post_meta');
                $updated[(int) $row->post_id] = true;
            }
        }

        foreach (array_keys($updated) as $post_id) {
            clean_post_cache($post_id);
        }
        return array_keys($updated);
    }

    /**
     * Replace paths in a custom field value. Serialized values are unpacked
     * so their lengths stay right; values holding objects are left alone.
     *
     * @param string               $value   Stored value.
     * @param array<string,string> $replace Paths.
     * @return string|null Null when the value cannot be changed safely.
     */
    private static function replace_value($value, array $replace) {
        if (!is_serialized($value)) {
            return strtr($value, $replace);
        }
        if (preg_match('/(^|[;{])[OC]:\d+:"/', $value)) {
            return null;
        }
        $data = @unserialize($value, array('allowed_classes' => false)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- no classes allowed.
        if (false === $data && 'b:0;' !== $value) {
            return null;
        }
        $walk = function ($item) use (&$walk, $replace) {
            if (is_string($item)) {
                return strtr($item, $replace);
            }
            if (is_array($item)) {
                return array_map($walk, $item);
            }
            return $item;
        };
        return serialize($walk($data)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stored as it was.
    }

    /* --------------------------------------------------------------------- */
    /* Notice                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Result notice on the attachment edit screen.
     */
    public static function notice() {
        if (!isset($_GET['sps_replaced'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
            return;
        }
        $count = absint(wp_unslash($_GET['sps_replaced'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $text  = __('File replaced.', 'seoprostack');
        if ($count) {
            /* translators: %d: number of posts */
            $text .= ' ' . sprintf(_n('Links updated in %d post.', 'Links updated in %d posts.', $count, 'seoprostack'), $count);
        }
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
    }

    /**
     * Drop the notice arguments from the address after showing them.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        return array_merge($args, array('sps_replaced', 'sps_replace_error'));
    }
}
