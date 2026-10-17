<?php
/**
 * Background copies of images to S3-compatible storage. Local files stay,
 * unless Remove local copies after upload is on: then confirmed uploads'
 * files are deleted here and brought back before anything reads them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Media_Storage extends SEOProStack_Feature {
    const KEY = 'media_storage';
    const META = '_seoprostack_storage';
    const DONE = '_seoprostack_storage_done';
    const HOOK = 'seoprostack_media_storage_batch';
    const JOB = 'seoprostack_storage_job_';
    const LOCK = 'seoprostack_storage_lock';
    const TEST = 'seoprostack_storage_test';
    const ERROR = 'seoprostack_storage_error';
    const STATE = '_seoprostack_storage_state';
    const SKIPPED = '_seoprostack_storage_skipped';
    const GENERATION = '_seoprostack_storage_generation';
    const CURSOR = 'seoprostack_storage_cursor';
    const COUNTS = 'seoprostack_storage_counts';
    const URL_INDEX = 'seoprostack_storage_url_';
    const REMOVE = 'media_storage_remove_local';
    /** Files whose local copies were deleted: signature and key => file. */
    const REMOVED = '_seoprostack_storage_removed';
    /** Removal pass: the signature when done, "kept:" and it to keep files here. */
    const LOCAL = '_seoprostack_storage_local';
    /** This site has pictures without local files (autoloaded: read on every page). */
    const FLAG = 'seoprostack_storage_removed';
    /** Removing and restoring pause until this time after the service failed. */
    const LOCAL_WAIT = 'seoprostack_storage_local_wait';
    const BUDGET = 25;

    /** Local-address calls must bypass output filters. @var int */
    private static $local = 0;
    /** Nested REST requests' edit contexts. @var bool[] */
    private static $rest = array();
    /** Nested REST requests that may generate image sizes. @var bool[] */
    private static $rest_sizes = array();
    /** Connection read once per request: constants cannot change. @var array<string,mixed>|null */
    private static $config = null;
    /** Main site's uploads folder; false until read. @var array{dir:string,url:string}|null|false */
    private static $root = false;
    /** Per site: whether the switch is on and whether files were removed. @var array<int,array{ready:bool,removed:bool}> */
    private static $serving = array();
    /** Whether the public address served an object during this request. @var bool */
    private static $public = false;
    /** Output address maps per site and attachment for this request. @var array<string,array<string,string>> */
    private static $maps = array();

    /** @return array<string,array> */
    public static function settings() {
        return array(
            self::KEY => array(
                'type' => 'bool', 'default' => false, 'tab' => 'media',
                'label' => __('Media in cloud storage', 'seoprostack'),
                'description' => __('Copy pictures to S3-compatible storage in the background and serve uploaded pictures from its public address. Files stay on this server too, unless Remove local copies after upload is on. Needs the SEOPROSTACK_MEDIA_STORAGE constants in wp-config.php. Purge page caches after switching on or off.', 'seoprostack'),
                // The constants, progress and Test connection (panel_status())
                // show above the child setting.
                'panel' => true,
            ),
            self::REMOVE => array(
                'type' => 'bool', 'default' => false, 'parent' => self::KEY,
                'label' => __('Remove local copies after upload', 'seoprostack'),
                'description' => __('Frees disk space: once the bucket confirms a picture’s files, they are deleted from this server and shown from the bucket, in wp-admin too. Editing, regenerating, watermarking or converting a picture brings its files back first. Switching this or Media in cloud storage off brings every file back in the background, while pictures keep showing from the bucket. Before deactivating SEO Pro Stack, run wp seoprostack media-storage restore --all.', 'seoprostack'),
            ),
        );
    }

    /**
     * Read connection constants without persisting or exposing their values.
     *
     * @return array
     */
    public static function config() {
        if (null !== self::$config) {
            return self::$config;
        }
        $config = array('missing' => array());
        foreach (array('endpoint', 'region', 'bucket', 'key_id', 'secret', 'url', 'prefix') as $key) {
            $name = 'SEOPROSTACK_MEDIA_STORAGE_' . strtoupper($key);
            $value = defined($name) ? constant($name) : '';
            $config[$key] = is_string($value) ? $value : '';
            if ('' === $config[$key] && !in_array($key, array('region', 'prefix'), true)) {
                $config['missing'][] = $name;
            }
        }
        $config['endpoint'] = rtrim($config['endpoint'], '/');
        $config['url'] = rtrim($config['url'], '/');
        $config['prefix'] = trim($config['prefix'], '/');
        if ('' !== $config['prefix']) {
            $config['prefix'] .= '/';
        }
        if ('' === $config['region']) {
            $host = (string) wp_parse_url($config['endpoint'], PHP_URL_HOST);
            $suffix = '.r2.cloudflarestorage.com';
            $config['region'] = substr($host, -strlen($suffix)) === $suffix ? 'auto' : 'us-east-1';
        }
        foreach (array('endpoint', 'url') as $key) {
            $parts = wp_parse_url($config[$key]);
            if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) || !in_array($parts['scheme'], array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                $config['missing'][] = 'SEOPROSTACK_MEDIA_STORAGE_' . strtoupper($key);
            }
        }
        if (preg_match('#(^|/)\.\.?(/|$)#', $config['prefix']) || preg_match('/[\r\n]/', $config['bucket'] . $config['region'] . $config['key_id'])) {
            $config['missing'][] = 'SEOPROSTACK_MEDIA_STORAGE_PREFIX / BUCKET / REGION / KEY_ID';
        }
        $config['missing'] = array_unique($config['missing']);
        $config['signature'] = hash('sha256', $config['endpoint'] . '\n' . $config['bucket'] . '\n' . $config['prefix']);
        self::$config = $config;
        return $config;
    }

    /** @return bool */
    private static function ready() {
        return self::enabled() && !self::config()['missing'];
    }

    /**
     * The main site's uploads folder, read once per request: on a subsite
     * each read switches to the main site, too costly for every image address.
     *
     * @return array{dir:string,url:string}|null
     */
    private static function root() {
        if (false === self::$root) {
            self::$root = SEOProStack_Nextgen_Images::main_uploads();
        }
        return self::$root;
    }

    /**
     * Drop this request's cached addresses after an attachment's record changes.
     *
     * @param int $id Attachment ID.
     */
    private static function forget($id) {
        unset(self::$maps[get_current_blog_id() . ':' . (int) $id]);
    }

    /** Register local hooks even when off; remote work always checks ready(). */
    public static function boot() {
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 2);
        add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        add_action('admin_post_seoprostack_storage_test', array(__CLASS__, 'handle_test'));
        if (is_admin() && self::enabled()) {
            // Only sites that use the feature see its line on every picture.
            add_filter('attachment_fields_to_edit', array(__CLASS__, 'attachment_field'), 20, 2);
        }
        add_action('delete_attachment', array(__CLASS__, 'deleted'));
        add_filter('wp_update_attachment_metadata', array(__CLASS__, 'metadata_updated'), 100, 2);
        add_action('added_post_meta', array(__CLASS__, 'metadata_committed'), 10, 4);
        add_action('updated_post_meta', array(__CLASS__, 'metadata_committed'), 10, 4);
        add_action('seoprostack_media_files_changed', array(__CLASS__, 'queue'));
        add_action('seoprostack_media_replaced', array(__CLASS__, 'queue'));
        add_action(self::HOOK, array(__CLASS__, 'cron_batch'));
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack media-storage', array(__CLASS__, 'cli'));
        }
        if (self::config()['missing']) {
            return;
        }
        add_filter('wp_insert_post_data', array(__CLASS__, 'save_local'), 5);
        // Files must be here before an editor or size generator reads them.
        add_filter('load_image_to_edit_path', array(__CLASS__, 'edit_path'), 5, 3);
        add_filter('wp_get_original_image_path', array(__CLASS__, 'original_path'), 5, 2);
        // Pictures without local files keep their bucket addresses even
        // while switched off, until the batch brings the files back.
        if (!self::ready() && !get_option(self::FLAG)) {
            return;
        }
        add_filter('wp_get_attachment_url', array(__CLASS__, 'attachment_url'), 20, 2);
        add_filter('wp_get_original_image_url', array(__CLASS__, 'original_url'), 20, 2);
        add_filter('image_downsize', array(__CLASS__, 'downsize'), 20, 3);
        add_filter('wp_calculate_image_srcset_meta', array(__CLASS__, 'srcset_meta'), 20, 3);
        add_filter('wp_calculate_image_srcset', array(__CLASS__, 'srcset'), 20, 5);
        add_filter('wp_content_img_tag', array(__CLASS__, 'content_image'), 10, 3);
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'rest_before'), 10, 3);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'rest_after'), 10, 3);
        if (get_option(self::FLAG)) {
            // Editors show removed files from the bucket; saving keeps local addresses.
            add_action('rest_api_init', array(__CLASS__, 'editor_hooks'));
            add_filter('the_editor_content', array(__CLASS__, 'editor_text'), 20);
        }
    }

    /**
     * Core local URL, safe for saved content and file readers.
     *
     * @param int $id Attachment ID.
     * @param string|int[] $size Image size; full returns the attachment URL.
     * @return string|false
     */
    public static function local_url($id, $size = 'full') {
        ++self::$local;
        try {
            return 'full' === $size ? wp_get_attachment_url($id) : wp_get_attachment_image_url($id, $size);
        } finally {
            --self::$local;
        }
    }

    /**
     * Which output addresses may change on this request: "all" uploaded
     * pictures (switched on, outside wp-admin and edit contexts), only the
     * "removed" ones whose local files are gone (wp-admin, edit contexts,
     * or switched off while files come back), or none ('').
     *
     * @return string
     */
    private static function serving() {
        if (self::$local || self::config()['missing']) {
            return '';
        }
        // Asked for every image address: read the site's state once.
        $blog = get_current_blog_id();
        if (!isset(self::$serving[$blog])) {
            self::$serving[$blog] = array('ready' => self::ready(), 'removed' => (bool) get_option(self::FLAG));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- context only, no mutation.
        $edit = defined('REST_REQUEST') && REST_REQUEST && isset($_REQUEST['context']) && 'edit' === $_REQUEST['context'];
        if (self::$serving[$blog]['ready'] && !is_admin() && !$edit && !in_array(true, self::$rest, true)) {
            return 'all';
        }
        // Saved content still gets local addresses (save_local()).
        return self::$serving[$blog]['removed'] ? 'removed' : '';
    }

    /**
     * Whether this attachment's addresses change on this request.
     *
     * @param int $id Attachment ID.
     * @return bool
     */
    private static function serves($id) {
        $mode = self::serving();
        return 'all' === $mode || ('removed' === $mode && self::removed($id));
    }

    /**
     * Whether some of an attachment's local files were removed.
     *
     * @param int $id Attachment ID.
     * @return bool
     */
    public static function removed($id) {
        $removed = get_post_meta((int) $id, self::REMOVED, true);
        return is_array($removed) && !empty($removed['files']);
    }

    /**
     * Removed files recorded for this connection.
     *
     * @param int $id Attachment ID.
     * @return array<string,array>
     */
    private static function removed_files($id) {
        $removed = get_post_meta((int) $id, self::REMOVED, true);
        if (!is_array($removed) || empty($removed['files']) || !is_array($removed['files']) || !isset($removed['signature']) || $removed['signature'] !== self::config()['signature']) {
            return array();
        }
        return $removed['files'];
    }

    /**
     * Bring an attachment's removed files back before code reads them.
     * A plain no-op when nothing was removed.
     *
     * @param int $id Attachment ID.
     * @return true|WP_Error
     */
    public static function ensure_local($id) {
        return self::removed($id) ? self::restore_attachment($id) : true;
    }

    /**
     * Forget removed files that are about to be deleted anyway (Replace
     * media files), so the next upload does not bring the old ones back.
     *
     * @param int $id Attachment ID.
     */
    public static function forget_removed($id) {
        delete_post_meta((int) $id, self::REMOVED);
        self::forget($id);
        delete_transient(self::COUNTS);
    }

    /**
     * The image editor's file: bring removed files back first, then give
     * the local path core would have used (never a bucket address).
     *
     * @param string|false $path Path or URL core chose.
     * @param int $id Attachment ID.
     * @param string|int[] $size Size.
     * @return string|false
     */
    public static function edit_path($path, $id, $size) {
        if (!self::removed($id) || is_wp_error(self::restore_attachment($id))) {
            return $path;
        }
        $file = get_attached_file($id);
        if (!$file || !is_file($file)) {
            return $path;
        }
        if ('full' !== $size) {
            $data = image_get_intermediate_size($id, $size);
            if ($data && !empty($data['file'])) {
                return path_join(dirname($file), $data['file']);
            }
        }
        return $file;
    }

    /**
     * Size generators (wp media regenerate, the REST post-process and edit
     * steps, Regenerate Thumbnails, the upload size step, background jobs)
     * read the original path: bring files back first. Code that only names
     * the file (the media grid, REST media responses and updates, other
     * WP-CLI commands) is left alone, so reading never brings files back.
     *
     * @param string|false $path Original path.
     * @param int $id Attachment ID.
     * @return string|false
     */
    public static function original_path($path, $id) {
        if (!self::removed($id)) {
            return $path;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared with a fixed action only; core checks the nonce.
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $processing = self::cli_media() || wp_doing_cron()
            || in_array(true, self::$rest_sizes, true)
            || (wp_doing_ajax() && 'media-create-image-subsizes' === $action);
        if ($processing) {
            self::restore_attachment($id);
        }
        return $path;
    }

    /**
     * Whether this is a wp media command (regenerate, fix-orientation),
     * which reads original files.
     *
     * @return bool
     */
    private static function cli_media() {
        if (!defined('WP_CLI') || !WP_CLI || !isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) {
            return false;
        }
        // The command is the first argument after global options (--url=…) and aliases (@prod).
        foreach (array_slice($GLOBALS['argv'], 1) as $arg) {
            if (is_string($arg) && '' !== $arg && '-' !== $arg[0] && '@' !== $arg[0]) {
                return 'media' === $arg;
            }
        }
        return false;
    }

    /**
     * Observe the parsed REST context, including JSON/internal requests.
     *
     * @param mixed $response Current response.
     * @param array $handler Route handler.
     * @param WP_REST_Request $request Parsed request.
     * @return mixed
     */
    public static function rest_before($response, $handler, $request) {
        self::$rest[] = 'edit' === $request->get_param('context');
        // Writes may make sizes (core's /edit and /post-process, Regenerate
        // Thumbnails); creating or updating an attachment only names its file.
        self::$rest_sizes[] = 'GET' !== $request->get_method() && 'HEAD' !== $request->get_method() && !preg_match('#^/wp/v2/media(?:/\d+)?/?$#', (string) $request->get_route());
        return $response;
    }

    /**
     * Restore the parent context after a REST callback.
     *
     * @param mixed $response Current response.
     * @param array $handler Route handler.
     * @param WP_REST_Request $request Parsed request.
     * @return mixed
     */
    public static function rest_after($response, $handler, $request) {
        array_pop(self::$rest);
        array_pop(self::$rest_sizes);
        return $response;
    }

    /**
     * Invalidate the done marker; a later batch reads the committed metadata.
     *
     * @param int $id Attachment ID.
     */
    public static function queue($id) {
        if (0 !== strpos((string) get_post_mime_type($id), 'image/')) {
            return;
        }
        if (!self::ready() && !get_post_meta($id, self::META, true)) {
            return;
        }
        update_post_meta($id, self::GENERATION, wp_generate_uuid4());
        delete_post_meta($id, self::DONE);
        self::forget($id);
        delete_transient(self::COUNTS);
        // An old retry must not delay a new edit.
        delete_option(self::JOB . 'retry_' . (int) $id);
        if (self::ready()) {
            self::schedule(10);
        }
    }

    /**
     * Queue after every metadata writer.
     *
     * @param array $data Metadata.
     * @param int $id Attachment ID.
     * @return array
     */
    public static function metadata_updated($data, $id) {
        self::queue($id);
        return $data;
    }

    /**
     * Also invalidate after the database write, not only its pre-save filter.
     *
     * @param int $meta_id Meta row ID.
     * @param int $id Attachment ID.
     * @param string $key Meta key.
     * @param mixed $value Saved value.
     */
    public static function metadata_committed($meta_id, $id, $key, $value) {
        if ('_wp_attachment_metadata' === $key) {
            self::queue($id);
        }
    }

    /**
     * Make sure a bounded batch runs within $delay seconds. A later wake-up
     * (an idle batch sleeping until a back-off ends) is brought forward, so
     * new work never waits for it.
     *
     * @param int $delay Seconds.
     */
    public static function schedule($delay = 0) {
        if (!self::ready() && !self::restoring()) {
            return;
        }
        $when = time() + max(0, (int) $delay);
        $next = wp_next_scheduled(self::HOOK);
        if ($next && $next <= $when) {
            return;
        }
        if ($next) {
            wp_unschedule_event($next, self::HOOK);
        }
        wp_schedule_single_event($when, self::HOOK);
    }

    /**
     * React to the site's switch.
     *
     * @param string $key Setting.
     * @param mixed $value Saved value.
     */
    public static function setting_saved($key, $value) {
        if (self::KEY !== $key && self::REMOVE !== $key) {
            return;
        }
        self::$serving = array();
        self::$maps = array();
        if (self::REMOVE === $key && $value && !self::config()['missing']) {
            // Switched on again: files kept here earlier may go now.
            delete_metadata('post', 0, self::LOCAL, 'kept:' . self::config()['signature'], true);
            delete_option(self::LOCAL_WAIT);
            delete_transient(self::COUNTS);
        }
        if (self::ready() || self::restoring()) {
            self::schedule();
        } else {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /** @return bool Whether uploaded pictures' local copies are removed. */
    private static function removing() {
        return self::ready() && (bool) SEOProStack_Settings::get(self::REMOVE);
    }

    /** @return bool Whether removed files are brought back (removal is off). */
    private static function restoring() {
        return !self::config()['missing'] && (bool) get_option(self::FLAG) && !self::removing();
    }

    /**
     * Uploaded pictures whose local copies have not been looked at, newest first.
     *
     * @phpstan-impure Reads the queue, which other requests change.
     * @param int $limit Most IDs.
     * @return int[]
     */
    private static function removable($limit) {
        global $wpdb;
        $signature = self::config()['signature'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded queue lookup, as waiting().
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value = %s INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'uploaded' LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = %s WHERE p.post_type = 'attachment' AND (l.meta_id IS NULL OR l.meta_value NOT IN (%s, %s)) ORDER BY p.ID DESC LIMIT %d",
            self::DONE, $signature, self::STATE, self::LOCAL, $signature, 'kept:' . $signature, max(1, (int) $limit)
        )));
    }

    /**
     * Attachments with removed files, newest first.
     *
     * @phpstan-impure Reads the queue, which other requests change.
     * @param int $limit Most IDs.
     * @param int $before Only IDs below this one (paging).
     * @return int[]
     */
    private static function restorable($limit, $before = PHP_INT_MAX) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded lookup through the meta_key index.
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id < %d ORDER BY post_id DESC LIMIT %d",
            self::REMOVED, (int) $before, max(1, (int) $limit)
        )));
    }

    /**
     * Backfill newest attachments first, through the post ID index.
     *
     * @phpstan-impure Reads the queue, which other requests change.
     * @param int $limit Batch size.
     * @return int[]
     */
    public static function waiting($limit) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded queue lookup, matching the nextgen queue.
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND m.meta_id IS NULL ORDER BY p.ID DESC LIMIT %d",
            self::DONE, self::config()['signature'], 'image/%', max(1, (int) $limit)
        )));
    }

    /**
     * Non-autoloaded individual rows prevent concurrent deletions losing keys.
     *
     * @param array $record Stored remote record.
     * @param string[] $keys Object keys.
     */
    private static function queue_delete($record, $keys) {
        foreach ($keys as $key) {
            $job = array('kind' => 'delete', 'key' => $key, 'signature' => $record['signature'], 'next' => 0);
            add_option(self::delete_job_name($record['signature'], $key), $job, '', false);
        }
        self::schedule(10);
    }

    /**
     * Put the destination in the indexed name so archived jobs cannot delay cleanup.
     *
     * @param string $signature Destination fingerprint.
     * @param string $key Object key.
     * @return string
     */
    private static function delete_job_name($signature, $key) {
        return self::JOB . 'delete_' . $signature . '_' . hash('sha256', $key);
    }

    /**
     * Read records before core deletes attachment meta, also while switched off.
     *
     * @param int $id Attachment ID.
     */
    public static function deleted($id) {
        $record = get_post_meta($id, self::META, true);
        if (is_array($record) && !empty($record['files']) && isset($record['signature'])) {
            self::queue_delete($record, array_map('strval', array_keys($record['files'])));
        }
        delete_option(self::JOB . 'retry_' . (int) $id);
        self::forget($id);
        delete_transient(self::COUNTS);
    }

    /**
     * Indexed option-name lookup, never read all jobs into memory.
     *
     * @param int $limit Maximum rows.
     * @param string $after Cursor through the option_name index.
     * @param string $kind Optional kind prefix.
     * @return string[]
     */
    private static function jobs($limit = 20, $after = '', $kind = '') {
        global $wpdb;
        if ($kind) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded lookup through option_name's unique index.
            return $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT %d", $wpdb->esc_like(self::JOB . $kind) . '%', $after, (int) $limit));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded lookup through option_name's unique index.
        return $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_name > %s ORDER BY option_name LIMIT %d", $wpdb->esc_like(self::JOB . 'delete_' . self::config()['signature'] . '_') . '%', $wpdb->esc_like(self::JOB . 'retry_') . '%', $after, (int) $limit));
    }

    /** Cron actions do not return the CLI's attempt count. */
    public static function cron_batch() {
        self::run_batch();
    }

    /**
     * Run uploads and deletes off the page, waiting for watermark jobs.
     *
     * @param int $limit Most upload attempts.
     * @return int Upload attempts.
     */
    public static function run_batch($limit = 10) {
        $ready = self::ready();
        // Switched off, the batch only brings removed files back.
        if (!$ready && !self::restoring()) {
            return 0;
        }
        self::schedule(60);
        $watermark = SEOProStack_Watermark_Images::job();
        if ($ready && $watermark && empty($watermark['ended'])) {
            return 0;
        }
        $lease = self::lease();
        if ('' === $lease) {
            return 0;
        }
        $start = microtime(true);
        $limit = max(1, min(10, (int) $limit));
        $attempts = 0;
        try {
            if ($ready) {
                $attempts = self::process_jobs($start, $limit);
                $ids = $attempts < $limit ? self::waiting($limit - $attempts) : array();
                foreach ($ids as $id) {
                    if (!self::more_time($start, self::BUDGET)) {
                        break;
                    }
                    self::upload_attachment($id, $start);
                    ++$attempts;
                }
            }
            self::local_copies($start, $limit);
        } finally {
            self::release($lease);
        }
        self::reschedule();
        return $attempts;
    }

    /**
     * Remove uploaded pictures' local copies, or bring removed files back
     * when removal is off. Pauses for an hour after the service fails.
     *
     * @param float $start Batch start.
     * @param int $limit Most attachments.
     */
    private static function local_copies($start, $limit) {
        if ((int) get_option(self::LOCAL_WAIT, 0) > time()) {
            return;
        }
        $removing = self::removing();
        if (!$removing && !self::restoring()) {
            return;
        }
        // WebP copies are made first, so they are uploaded before files go.
        if ($removing && SEOProStack_Nextgen_Images::enabled() && SEOProStack_Nextgen_Images::waiting(1)) {
            return;
        }
        foreach ($removing ? self::removable($limit) : self::restorable($limit) as $id) {
            if (!self::more_time($start, self::BUDGET)) {
                return;
            }
            $result = $removing ? self::remove_local($id) : self::restore_attachment($id);
            if (is_wp_error($result)) {
                update_option(self::ERROR, $result->get_error_message(), false);
                update_option(self::LOCAL_WAIT, time() + HOUR_IN_SECONDS, false);
                return;
            }
        }
        if (!$removing && !self::restorable(1)) {
            // Every file is back: addresses follow the switch again.
            delete_option(self::FLAG);
            self::$serving = array();
            self::$maps = array();
        }
    }

    /**
     * Whether removing or restoring has work it may do now.
     *
     * @phpstan-impure Reads queues, which other requests change.
     * @return bool
     */
    private static function local_due() {
        if ((int) get_option(self::LOCAL_WAIT, 0) > time()) {
            return false;
        }
        if (self::removing()) {
            return (bool) self::removable(1);
        }
        return self::restoring() && self::restorable(1);
    }

    /**
     * Read past the object cache: a request elsewhere may have changed them.
     *
     * @param int $id Attachment ID.
     */
    private static function fresh($id) {
        wp_cache_delete((int) $id, 'post_meta');
    }

    /**
     * Whether the public address serves objects, checked once per request
     * on the first file about to be removed: a bucket the API can read but
     * visitors cannot would otherwise leave pictures broken.
     *
     * @param string $key Object key.
     * @param string $version Version query.
     * @return true|WP_Error
     */
    private static function public_ok($key, $version) {
        if (self::$public) {
            return true;
        }
        $url = self::config()['url'] . '/' . implode('/', array_map('rawurlencode', explode('/', $key))) . '?v=' . rawurlencode($version);
        // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- background batch, never a page view.
        $response = wp_remote_head($url, array('timeout' => 10, 'redirection' => 2));
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if (200 !== $status) {
            /* translators: %d: HTTP status code, 0 when there was no answer */
            return new WP_Error('storage_public', sprintf(__('The public storage address did not serve a picture (HTTP %d), so local copies were kept. Check SEOPROSTACK_MEDIA_STORAGE_URL.', 'seoprostack'), $status));
        }
        self::$public = true;
        return true;
    }

    /**
     * Delete the local copies of one uploaded attachment's recorded files.
     * Each goes only when the record matches this connection, the local
     * file is still the uploaded one (size and SHA-256), the bucket answers
     * HTTP 200 with the recorded size, and nothing changed meanwhile.
     * Files the record does not list (Watermark pictures' unmarked
     * originals, AVIF copies, oversized files) are never touched.
     *
     * @param int $id Attachment ID.
     * @return true|WP_Error WP_Error when the service could not confirm: try later.
     */
    private static function remove_local($id) {
        $config = self::config();
        $kept = 'kept:' . $config['signature'];
        self::fresh($id);
        $record = get_post_meta($id, self::META, true);
        $generation = get_post_meta($id, self::GENERATION, true);
        $root = self::root();
        $base = $root ? realpath($root['dir']) : false;
        if (!$base || !is_array($record) || empty($record['files']) || 'uploaded' !== $record['state'] || $record['signature'] !== $config['signature'] || get_post_meta($id, self::DONE, true) !== $config['signature']) {
            return true;
        }
        $removed = get_post_meta($id, self::REMOVED, true);
        if (is_array($removed) && !empty($removed['files']) && (!isset($removed['signature']) || $removed['signature'] !== $config['signature'])) {
            // Files removed through another connection: only that one brings them back.
            return true;
        }
        if (!is_array($removed) || !isset($removed['files']) || !is_array($removed['files'])) {
            $removed = array('files' => array());
        }
        // The public address too, so pages keep showing them after a connection change.
        $removed['signature'] = $config['signature'];
        $removed['url'] = $config['url'];
        // Every local file is checked before any goes, so a picture is removed
        // whole or kept whole.
        $files = array();
        foreach ($record['files'] as $key => $info) {
            $relative = isset($info['relative']) ? (string) $info['relative'] : '';
            $path = $root['dir'] . '/' . $relative;
            clearstatcache();
            $real = realpath($path);
            if ('' === $relative || $config['prefix'] . $relative !== $key || false !== strpos($relative, '../') || false !== strpos($relative, 'seoprostack-originals-') || !$real || 0 !== strpos(wp_normalize_path($real), wp_normalize_path($base) . '/') || !is_file($path)) {
                // Already removed, or not a file this feature may touch.
                continue;
            }
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file changed or deleted meanwhile is kept or skipped below.
            $digest = @hash_file('sha256', $path);
            if (empty($info['confirmed']) || empty($info['digest']) || (int) filesize($path) !== (int) $info['size'] || !is_string($digest) || !hash_equals((string) $info['digest'], $digest)) {
                // Changed since its upload: keep it; the change queues a new upload.
                update_post_meta($id, self::LOCAL, $kept);
                return true;
            }
            $files[$key] = array($relative, $path);
        }
        $client = self::client();
        foreach ($files as $key => $local) {
            list($relative, $path) = $local;
            $info = $record['files'][$key];
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- re-checked just before deleting: a file changed meanwhile is kept.
            $digest = @hash_file('sha256', $path);
            if (!is_string($digest) || !hash_equals((string) $info['digest'], $digest)) {
                update_post_meta($id, self::LOCAL, $kept);
                return true;
            }
            $head = $client->head($key);
            if (is_wp_error($head)) {
                return $head;
            }
            if (200 !== $head['status'] || (int) $info['size'] !== $head['size']) {
                update_post_meta($id, self::LOCAL, $kept);
                update_option(self::ERROR, __('A stored picture did not match its local file, so its local copies were kept.', 'seoprostack'), false);
                return true;
            }
            $public = self::public_ok($key, (string) $info['version']);
            if (is_wp_error($public)) {
                return $public;
            }
            // Last look: an edit, a restore or a new upload since the record
            // was read keeps the files.
            self::fresh($id);
            if (get_post_meta($id, self::DONE, true) !== $config['signature'] || get_post_meta($id, self::GENERATION, true) !== $generation || get_post_meta($id, self::LOCAL, true) === $kept) {
                return true;
            }
            // Recorded before deleting, so the file is always brought back.
            $removed['files'][$key] = array('relative' => $relative, 'size' => (int) $info['size'], 'mtime' => (int) $info['mtime'], 'digest' => (string) $info['digest'], 'version' => (string) $info['version']);
            update_post_meta($id, self::REMOVED, $removed);
            update_option(self::FLAG, 1, true);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- wp_delete_file() runs the wp_delete_file filter, where WebP and AVIF images deletes the file's copies (AVIF copies are not in the bucket); only this recorded file goes. A failure keeps the file, which restore then finds.
            @unlink($path);
        }
        update_post_meta($id, self::LOCAL, $config['signature']);
        self::forget($id);
        delete_transient(self::COUNTS);
        return true;
    }

    /**
     * Bring back an attachment's removed files from the bucket, byte for
     * byte (size and SHA-256 must match the record), with their recorded
     * modified times, so WebP copies stay newer than their files. A file
     * already on this server is never overwritten. The attachment then
     * keeps its files here until it changes, or until removal is switched
     * on again.
     *
     * @param int $id Attachment ID.
     * @return true|WP_Error
     */
    public static function restore_attachment($id) {
        $id = (int) $id;
        self::fresh($id);
        $removed = get_post_meta($id, self::REMOVED, true);
        if (!is_array($removed) || empty($removed['files']) || !is_array($removed['files'])) {
            return true;
        }
        $config = self::config();
        if ($config['missing']) {
            return new WP_Error('storage_config', __('Set the SEOPROSTACK_MEDIA_STORAGE constants to bring files back.', 'seoprostack'));
        }
        if (!isset($removed['signature']) || $removed['signature'] !== $config['signature']) {
            return new WP_Error('storage_signature', __('These files were removed while another storage connection was set. Set that connection again to bring them back.', 'seoprostack'));
        }
        $root = self::root();
        $base = $root ? realpath($root['dir']) : false;
        if (!$base) {
            return new WP_Error('storage_uploads', __('The uploads folder could not be found.', 'seoprostack'));
        }
        // Removal must not take these files again while they are needed here.
        update_post_meta($id, self::LOCAL, 'kept:' . $config['signature']);
        $client = null;
        foreach ($removed['files'] as $key => $info) {
            $relative = isset($info['relative']) ? (string) $info['relative'] : '';
            $path = $root['dir'] . '/' . $relative;
            if ('' === $relative || $config['prefix'] . $relative !== $key || false !== strpos($relative, '..') || !isset($info['size'], $info['digest'], $info['mtime'])) {
                return new WP_Error('storage_restore_record', __('A removed file’s record is not valid, so it was not brought back.', 'seoprostack'));
            }
            clearstatcache();
            if (!is_file($path)) {
                $dir = dirname($path);
                $real = wp_mkdir_p($dir) ? realpath($dir) : false;
                if (!$real || 0 !== strpos(wp_normalize_path($real) . '/', wp_normalize_path($base) . '/')) {
                    return new WP_Error('storage_write', __('A picture could not be written back to the uploads folder.', 'seoprostack'));
                }
                if ((int) $info['size'] > self::size_cap()) {
                    return new WP_Error('storage_memory', __('A picture is too large to bring back with the PHP memory available.', 'seoprostack'));
                }
                $client = $client ? $client : self::client();
                $body = $client->get((string) $key);
                if (is_wp_error($body)) {
                    return $body;
                }
                if (strlen($body) !== (int) $info['size'] || !hash_equals((string) $info['digest'], hash('sha256', $body))) {
                    return new WP_Error('storage_restore_mismatch', __('A stored picture did not match its record, so it was not brought back.', 'seoprostack'));
                }
                $temp = $path . '.' . wp_generate_password(8, false, false) . '.tmp';
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a temporary file beside the target, renamed into place; failure is handled.
                $written = @file_put_contents($temp, $body);
                clearstatcache();
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- same folder; never over a file that appeared meanwhile.
                if (strlen($body) !== $written || is_file($path) || !@rename($temp, $path)) {
                    if (is_file($temp)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- this request's own temporary file.
                        @unlink($temp);
                    }
                    if (!is_file($path)) {
                        return new WP_Error('storage_write', __('A picture could not be written back to the uploads folder.', 'seoprostack'));
                    }
                } else {
                    // Permissions as wp_handle_upload() sets them, and the recorded time.
                    $stat = stat($dir);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- best effort, as core does.
                    @chmod($path, $stat ? $stat['mode'] & 0000666 : 0644);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
                    @touch($path, (int) $info['mtime']);
                }
            }
            // Back, or another file already there (never overwritten): local now.
            unset($removed['files'][$key]);
            if ($removed['files']) {
                update_post_meta($id, self::REMOVED, $removed);
            } else {
                delete_post_meta($id, self::REMOVED);
            }
        }
        self::forget($id);
        delete_transient(self::COUNTS);
        return true;
    }

    /**
     * Take the batch lease: a row in the options table that only one request
     * can insert (INSERT IGNORE, as WP_Upgrader::create_lock() does;
     * add_option() checks then upserts, so two requests can both "add" it).
     * A lease past its expiry was left by a batch that stopped, and is taken
     * over. The value is the expiry and a value unique to this batch, so a
     * batch deletes only the lease it read or holds.
     *
     * @return string The lease this batch holds; empty when another batch runs.
     */
    private static function lease() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read past the options cache: the lease changes under it.
        $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK));
        if ('' !== $held && (int) strtok($held, ' ') < time()) {
            self::release($held);
        }
        $value = (time() + 300) . ' ' . wp_generate_uuid4();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic insert is the lease; the options API cannot do it.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value)) ? $value : '';
    }

    /**
     * Delete the batch lease if it still has the given value.
     *
     * @param string $value Lease value.
     */
    private static function release($value) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete; the lease row is never cached (see lease()).
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $value));
    }

    /**
     * Keep the next batch only while work is due: sleep until the first
     * backed-off job instead of waking every minute, and stop when idle.
     */
    private static function reschedule() {
        if (self::due_now()) {
            return;
        }
        $due = self::next_due();
        if (null === $due) {
            // Nothing waiting, retrying or left to delete: an old error no
            // longer applies, so the panel and status stop showing it.
            delete_option(self::ERROR);
        }
        if (null !== $due && $due <= time() + 60) {
            return;
        }
        wp_clear_scheduled_hook(self::HOOK);
        // Work queued between those checks and the clear saw the old event
        // and did not schedule its own: look again after clearing.
        $due = self::due_now() ? time() : self::next_due();
        if (null !== $due) {
            self::schedule(max(10, $due - time()));
        }
    }

    /**
     * Whether uploads, removals or restores are waiting now.
     *
     * @phpstan-impure Reads queues, which other requests change.
     * @return bool
     */
    private static function due_now() {
        return (self::ready() && self::waiting(1)) || self::local_due();
    }

    /**
     * When the first job for this connection is due; null when there is none.
     * Reads at most 500 small rows, only when a batch ends; more counts as due.
     *
     * @phpstan-impure Reads jobs, which other requests change.
     * @return int|null
     */
    private static function next_due() {
        global $wpdb;
        $signature = self::config()['signature'];
        // Switched off, upload and delete jobs wait: only restores run.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded lookup through option_name's unique index.
        $values = self::ready() ? $wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name LIMIT 501", $wpdb->esc_like(self::JOB . 'delete_' . $signature . '_') . '%', $wpdb->esc_like(self::JOB . 'retry_') . '%')) : array();
        if (count($values) > 500) {
            return time();
        }
        $due = null;
        // Removing or restoring paused after a failure: wake when it ends.
        $wait = (int) get_option(self::LOCAL_WAIT, 0);
        if ($wait > time() && (self::removing() || self::restoring())) {
            $due = $wait;
        }
        foreach ($values as $value) {
            $job = maybe_unserialize($value);
            if (is_array($job) && isset($job['signature'], $job['next']) && $job['signature'] === $signature) {
                $due = null === $due ? (int) $job['next'] : min($due, (int) $job['next']);
            }
        }
        return $due;
    }

    /**
     * Retry uploads and remote deletes; old connections' jobs are kept, not sent.
     *
     * @param float $start Batch start.
     * @param int $limit Most retry uploads.
     * @return int Upload attempts.
     */
    private static function process_jobs($start, $limit) {
        $config = self::config();
        $attempts = 0;
        $names = self::jobs(20, (string) get_option(self::CURSOR, ''));
        if (!$names) {
            $names = self::jobs();
        }
        foreach ($names as $name) {
            if (!self::more_time($start, self::BUDGET)) {
                return $attempts;
            }
            $job = get_option($name);
            update_option(self::CURSOR, $name, false);
            if (!is_array($job) || $job['signature'] !== $config['signature'] || $job['next'] > time()) {
                continue;
            }
            if ('retry' === $job['kind']) {
                if ($attempts >= $limit) {
                    continue;
                }
                delete_option($name);
                self::upload_attachment((int) $job['id'], $start);
                ++$attempts;
                continue;
            }
            $result = self::client()->delete($job['key']);
            if (is_wp_error($result)) {
                $job['next'] = time() + HOUR_IN_SECONDS;
                update_option($name, $job, false);
                update_option(self::ERROR, $result->get_error_message(), false);
            } else {
                delete_option($name);
                delete_option(self::URL_INDEX . hash('sha256', $job['signature'] . $job['key']));
            }
        }
        return $attempts;
    }

    /** @return SEOProStack_S3_Client */
    private static function client() {
        require_once dirname(__DIR__) . '/class-seoprostack-s3-client.php';
        $config = self::config();
        return new SEOProStack_S3_Client($config['endpoint'], $config['region'], $config['bucket'], $config['key_id'], $config['secret']);
    }

    /**
     * Single PUTs retain payloads in memory. Reserve space for PHP and HTTP copies.
     *
     * @return int Maximum file bytes.
     */
    private static function size_cap() {
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $free = $limit > 0 ? max(0, $limit - memory_get_usage(true) - 16 * MB_IN_BYTES) : 64 * MB_IN_BYTES;
        return min(16 * MB_IN_BYTES, (int) ($free / 4));
    }

    /**
     * Collect only attachment files and registered WebP copies, within uploads.
     *
     * @param int $id Attachment ID.
     * @return array<string,array>
     */
    private static function files($id) {
        $file = get_attached_file($id);
        $root = self::root();
        if (!$file || !$root) {
            return array();
        }
        $meta = wp_get_attachment_metadata($id);
        $names = array(wp_basename($file));
        if (is_array($meta)) {
            if (!empty($meta['original_image'])) {
                $names[] = wp_basename($meta['original_image']);
            }
            foreach (isset($meta['sizes']) ? $meta['sizes'] : array() as $size) {
                if (!empty($size['file'])) {
                    $names[] = wp_basename($size['file']);
                }
            }
        }
        $nextgen = get_post_meta($id, '_seoprostack_nextgen', true);
        $files = array();
        $config = self::config();
        foreach (array_unique($names) as $name) {
            $paths = array(dirname($file) . '/' . $name);
            if (!empty($nextgen['files'][$name]['webp']) && $nextgen['files'][$name]['webp'] > 0 && is_file($paths[0]) && is_file($paths[0] . '.webp') && filemtime($paths[0] . '.webp') >= filemtime($paths[0])) {
                $paths[] = dirname($file) . '/' . $name . '.webp';
            }
            foreach ($paths as $path) {
                $real = realpath($path);
                $base = realpath($root['dir']);
                if (!$real || !$base || 0 !== strpos(wp_normalize_path($real), wp_normalize_path($base) . '/') || !is_file($path)) {
                    continue;
                }
                $relative = substr(wp_normalize_path($path), strlen($root['dir']) + 1);
                if (false !== strpos($relative, '../') || false !== strpos($relative, 'seoprostack-originals-')) {
                    continue;
                }
                $type = wp_check_filetype($path);
                if (empty($type['type']) || 0 !== strpos($type['type'], 'image/')) {
                    continue;
                }
                $bytes = (int) filesize($path);
                $mtime = (int) filemtime($path);
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- concurrent deletion produces an empty digest and a handled read failure.
                $digest = $bytes <= self::size_cap() && is_readable($path) ? @hash_file('sha256', $path) : '';
                $digest = is_string($digest) ? $digest : '';
                $files[$config['prefix'] . $relative] = array('path' => $path, 'relative' => $relative, 'size' => $bytes, 'mtime' => $mtime, 'type' => $type['type'], 'digest' => $digest, 'version' => $digest);
            }
        }
        return $files;
    }

    /**
     * Upload the whole attachment atomically; unchanged files make no request.
     *
     * @param int $id Attachment ID.
     * @param float|null $start When the batch began (microtime), so a batch keeps one time budget.
     * @return bool Whether all eligible files were copied.
     */
    public static function upload_attachment($id, $start = null) {
        if (!self::ready() || 0 !== strpos((string) get_post_mime_type($id), 'image/')) {
            return false;
        }
        self::forget($id);
        $config = self::config();
        $generation = get_post_meta($id, self::GENERATION, true);
        $old = get_post_meta($id, self::META, true);
        $old = is_array($old) ? $old : array();
        $removed = get_post_meta($id, self::REMOVED, true);
        if (is_array($removed) && !empty($removed['files']) && (!isset($removed['signature']) || $removed['signature'] !== $config['signature'])) {
            // The bucket objects are these files' only copies: never delete
            // or relabel them. Restoring needs the old connection again.
            update_post_meta($id, self::DONE, $config['signature']);
            update_post_meta($id, self::STATE, 'failed');
            update_option(self::ERROR, __('Some pictures’ local files were removed while another storage connection was set. Set that connection again and run wp seoprostack media-storage restore --all.', 'seoprostack'), false);
            delete_transient(self::COUNTS);
            return false;
        }
        if (isset($old['signature']) && $old['signature'] !== $config['signature']) {
            // Never relabel another destination's inventory as this connection.
            self::queue_delete($old, array_map('strval', array_keys(isset($old['files']) ? $old['files'] : array())));
            $old = array();
        }
        $attempt = isset($old['attempts']) ? (int) $old['attempts'] + 1 : 1;
        // Tried first: even a fatal error moves the queue forward and retries later.
        update_post_meta($id, self::DONE, $config['signature']);
        $job = array('kind' => 'retry', 'id' => $id, 'signature' => $config['signature'], 'next' => time() + HOUR_IN_SECONDS);
        update_option(self::JOB . 'retry_' . $id, $job, false);
        if (self::removed($id)) {
            // A changed picture is collected from local files: bring removed
            // ones back first, or the new inventory would drop (and delete)
            // their only copies. Pages keep their bucket addresses meanwhile.
            $restored = self::restore_attachment($id);
            if (is_wp_error($restored)) {
                update_option(self::ERROR, $restored->get_error_message(), false);
                return false;
            }
        }
        $record = array('signature' => $config['signature'], 'files' => array(), 'state' => 'failed', 'attempts' => $attempt, 'skipped' => 0);
        // Keep the previous inventory for deletion/recovery, but never serve a partial upload.
        $pending = $old ? $old : $record;
        $pending['state'] = 'failed';
        $pending['attempts'] = $attempt;
        update_post_meta($id, self::META, $pending);
        update_post_meta($id, self::STATE, 'failed');
        delete_transient(self::COUNTS);
        clearstatcache();
        $files = self::files($id);
        $start = null === $start ? microtime(true) : (float) $start;
        foreach ($files as $key => $info) {
            if (!self::more_time($start, self::BUDGET)) {
                $job['next'] = time() + 60;
                update_option(self::JOB . 'retry_' . $id, $job, false);
                return false;
            }
            if ($info['size'] > self::size_cap()) {
                ++$record['skipped'];
                continue;
            }
            $previous = isset($old['files'][$key]) ? $old['files'][$key] : array();
            $same = isset($old['signature']) && $old['signature'] === $config['signature'] && !empty($previous['confirmed']) && !empty($info['digest']) && isset($previous['digest']) && $previous['digest'] === $info['digest'];
            if (!$same) {
                // Persist the attempted key before PUT: deletion or a fatal error
                // must not lose an object whose server accepted the request.
                $attempted = $info;
                unset($attempted['path'], $attempted['type']);
                $attempted['confirmed'] = false;
                $pending['files'][$key] = $attempted;
                update_post_meta($id, self::META, $pending);
                /** Filter the public object cache policy. @param string $policy Cache-Control. */
                $policy = (string) apply_filters('seoprostack_media_storage_cache_control', 'public, max-age=31536000, immutable');
                $result = $info['digest'] ? self::client()->put_file($key, $info['path'], $info['type'], $policy, $info['digest']) : new WP_Error('storage_read', __('The local picture could not be read.', 'seoprostack'));
                if (is_wp_error($result)) {
                    $pending['error'] = $result->get_error_message();
                    if (self::attachment_exists($id)) {
                        update_post_meta($id, self::META, $pending);
                    } else {
                        self::queue_delete($pending, array_map('strval', array_keys($pending['files'])));
                        delete_option(self::JOB . 'retry_' . $id);
                        return false;
                    }
                    update_option(self::ERROR, $pending['error'], false);
                    $job['next'] = time() + ($attempt < 2 ? HOUR_IN_SECONDS : ($attempt < 3 ? 6 : 24) * HOUR_IN_SECONDS);
                    update_option(self::JOB . 'retry_' . $id, $job, false);
                    return false;
                }
            }
            unset($info['path'], $info['type']);
            $info['confirmed'] = true;
            $record['files'][$key] = $info;
            $pending['signature'] = $config['signature'];
            $pending['files'][$key] = $info;
            if (self::attachment_exists($id)) {
                update_post_meta($id, self::META, $pending);
            } else {
                self::queue_delete($pending, array_map('strval', array_keys($pending['files'])));
                delete_option(self::JOB . 'retry_' . $id);
                return false;
            }
            // A key brought back by an edit must not be removed by an old cleanup job.
            delete_option(self::delete_job_name($config['signature'], $key));
        }
        $record['state'] = $record['files'] ? 'uploaded' : 'skipped';
        $record['attempts'] = 0;
        if (!$files) {
            ++$record['skipped'];
        }
        // Metadata or copies changed during PUT: do not publish this stale inventory.
        if (!self::attachment_exists($id)) {
            self::queue_delete($pending, array_map('strval', array_keys($pending['files'])));
            delete_option(self::JOB . 'retry_' . $id);
            return false;
        }
        if (get_post_meta($id, self::DONE, true) !== $config['signature'] || get_post_meta($id, self::GENERATION, true) !== $generation) {
            // A live edit needs another pass, not deletion of its confirmed files.
            return false;
        }
        if (isset($old['signature']) && $old['signature'] === $config['signature'] && !empty($old['files'])) {
            self::queue_delete($old, array_keys(array_diff_key($old['files'], $record['files'])));
        }
        update_post_meta($id, self::META, $record);
        update_post_meta($id, self::STATE, $record['state']);
        update_post_meta($id, self::SKIPPED, $record['skipped'] > 0 ? 1 : 0);
        delete_transient(self::COUNTS);
        foreach (array_keys($record['files']) as $key) {
            update_option(self::URL_INDEX . hash('sha256', $config['signature'] . $key), $id, false);
        }
        delete_option(self::JOB . 'retry_' . $id);
        // A new inventory: Remove local copies looks at it again.
        delete_post_meta($id, self::LOCAL);
        self::forget($id);
        return true;
    }

    /**
     * Refresh caches after network I/O so concurrent edits/deletions are visible.
     *
     * @param int $id Attachment ID.
     * @return bool
     */
    private static function attachment_exists($id) {
        wp_cache_delete($id, 'posts');
        wp_cache_delete($id, 'post_meta');
        return null !== get_post($id);
    }

    /**
     * Local to public URL map, built once per request for each attachment:
     * one picture's src, size and srcset addresses all ask for it.
     *
     * @param int $id Attachment ID.
     * @return array<string,string>
     */
    private static function map($id) {
        // Pages and wp-admin can differ (build_map()), and a request can be both.
        $cache = get_current_blog_id() . ':' . (int) $id . ':' . self::serving();
        if (!isset(self::$maps[$cache])) {
            if (count(self::$maps) >= 500) {
                // Bound memory in long WP-CLI runs over whole libraries.
                self::$maps = array();
            }
            self::$maps[$cache] = self::build_map($id);
        }
        return self::$maps[$cache];
    }

    /**
     * Local to public URL map: a completed inventory on this connection,
     * plus every removed file, whose bucket object is its only copy (also
     * while a changed picture waits for its next upload).
     *
     * @param int $id Attachment ID.
     * @return array<string,string>
     */
    private static function build_map($id) {
        $record = get_post_meta($id, self::META, true);
        $config = self::config();
        $complete = is_array($record) && !empty($record['files']) && 'uploaded' === $record['state'] && $record['signature'] === $config['signature'] && get_post_meta($id, self::DONE, true) === $config['signature'];
        $gone = self::removed_files($id);
        $gone_url = $config['url'];
        if (!$gone && 'all' === self::serving()) {
            // Removed through another connection: pages show them from the
            // address they were removed under. Not wp-admin or editors, where
            // saving can put back only this connection's addresses.
            $removed = get_post_meta((int) $id, self::REMOVED, true);
            if (is_array($removed) && !empty($removed['files']) && is_array($removed['files']) && !empty($removed['url']) && is_string($removed['url'])) {
                $gone = $removed['files'];
                $gone_url = untrailingslashit($removed['url']);
            }
        }
        $files = $gone + ($complete ? $record['files'] : array());
        if (!$files) {
            return array();
        }
        $root = self::root();
        $uploads = wp_upload_dir(null, false);
        if (!$root || 0 !== strpos(wp_normalize_path($uploads['basedir']), $root['dir'])) {
            return array();
        }
        $site_path = ltrim(substr(wp_normalize_path($uploads['basedir']), strlen($root['dir'])), '/');
        $map = array();
        foreach ($files as $key => $info) {
            $relative = $info['relative'];
            if ($site_path && 0 !== strpos($relative, $site_path . '/')) {
                continue;
            }
            $local = trailingslashit($uploads['baseurl']) . ($site_path ? substr($relative, strlen($site_path) + 1) : $relative);
            $target = isset($files[$key . '.webp']) ? $key . '.webp' : $key;
            $map[$local] = (isset($gone[$target]) ? $gone_url : $config['url']) . '/' . implode('/', array_map('rawurlencode', explode('/', $target))) . '?v=' . $files[$target]['version'];
            $encoded = trailingslashit($uploads['baseurl']) . implode('/', array_map('rawurlencode', explode('/', $site_path ? substr($relative, strlen($site_path) + 1) : $relative)));
            $map[$encoded] = $map[$local];
        }
        return $map;
    }

    /**
     * Map a single output URL.
     *
     * @param string $url Local URL.
     * @param int $id Attachment ID.
     * @return string
     */
    public static function attachment_url($url, $id) {
        if (!self::serves($id)) {
            return $url;
        }
        $map = self::map($id);
        return isset($map[$url]) ? $map[$url] : $url;
    }

    /**
     * Register editor_response() for every post type editors load over REST.
     */
    public static function editor_hooks() {
        foreach (get_post_types(array('show_in_rest' => true)) as $type) {
            add_filter('rest_prepare_' . $type, array(__CLASS__, 'editor_response'), 20, 3);
        }
    }

    /**
     * The block editor loads saved content (edit context, raw), whose local
     * addresses of removed files would show broken pictures.
     *
     * @param WP_REST_Response $response Response.
     * @param WP_Post $post Post.
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function editor_response($response, $post, $request) {
        if ('edit' !== $request->get_param('context') || !($response instanceof WP_REST_Response)) {
            return $response;
        }
        $data = $response->get_data();
        $changed = false;
        foreach (array('content', 'excerpt') as $field) {
            if (isset($data[$field]['raw']) && is_string($data[$field]['raw'])) {
                $text = self::editor_text($data[$field]['raw']);
                $changed = $changed || $text !== $data[$field]['raw'];
                $data[$field]['raw'] = $text;
            }
        }
        if ($changed) {
            $response->set_data($data);
        }
        return $response;
    }

    /**
     * Point an editor's local addresses of removed files at the bucket,
     * only for attachments the content names (wp-image-ID, "id":ID). Saving
     * puts local addresses back (save_local()), so saved content stays local.
     *
     * @param string $text Content as the editor loads it.
     * @return string
     */
    public static function editor_text($text) {
        if (!is_string($text) || '' === $text || !get_option(self::FLAG) || self::config()['missing'] || !preg_match_all('/(?:wp-image-|"id":)(\d+)/', $text, $found)) {
            return $text;
        }
        $map = array();
        foreach (array_unique(array_map('intval', $found[1])) as $id) {
            if ($id > 0 && self::removed_files($id)) {
                $map += self::map($id);
            }
        }
        return $map ? strtr($text, $map) : $text;
    }

    /**
     * Let core choose a local size before mapping it (never derive from a WebP URL).
     *
     * @param array|false $out Previous downsize result.
     * @param int $id Attachment ID.
     * @param string|int[] $size Size.
     * @return array|false
     */
    public static function downsize($out, $id, $size) {
        if ($out || !self::serves($id)) {
            return $out;
        }
        ++self::$local;
        try {
            $image = image_downsize($id, $size);
        } finally {
            --self::$local;
        }
        if ($image) {
            $image[0] = self::attachment_url($image[0], $id);
        }
        return $image;
    }

    /**
     * Do not derive a kept-original address from a remote size or WebP address.
     * An oversized or unconfirmed original must keep its own local address.
     *
     * @param string|false $url Previous original URL.
     * @param int $id Attachment ID.
     * @return string|false
     */
    public static function original_url($url, $id) {
        if (!self::serves($id)) {
            return $url;
        }
        ++self::$local;
        try {
            $original = wp_get_original_image_url($id);
        } finally {
            --self::$local;
        }
        return $original ? self::attachment_url($original, $id) : $original;
    }

    /**
     * Match encoded public filenames before core checks the source against metadata.
     * This copy is only used for srcset calculation, never saved to the database.
     *
     * @param array $meta Image metadata.
     * @param int[] $size Dimensions.
     * @param string $src Selected source.
     * @return array
     */
    public static function srcset_meta($meta, $size, $src) {
        if ('' === self::serving() || 0 !== strpos($src, self::config()['url'] . '/')) {
            return $meta;
        }
        if (isset($meta['file']) && is_string($meta['file'])) {
            $meta['file'] = implode('/', array_map('rawurlencode', explode('/', $meta['file'])));
        }
        if (isset($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $name => $entry) {
                if (is_array($entry) && isset($entry['file']) && is_string($entry['file'])) {
                    $meta['sizes'][$name]['file'] = rawurlencode($entry['file']);
                }
            }
        }
        return $meta;
    }

    /**
     * Rewrite core's already-selected srcset candidates.
     *
     * @param array|false $sources Candidates, or disabled by an earlier filter.
     * @param int[] $size Dimensions.
     * @param string $src Selected source.
     * @param array $meta Metadata.
     * @param int $id Attachment ID.
     * @return array|false
     */
    public static function srcset($sources, $size, $src, $meta, $id) {
        if (!is_array($sources)) {
            return $sources;
        }
        foreach ($sources as &$source) {
            $source['url'] = self::attachment_url($source['url'], $id);
        }
        unset($source);
        return $sources;
    }

    /**
     * Core identifies wp-image-ID tags; leave unidentified images alone.
     *
     * @param string $tag Image HTML.
     * @param string $context Output context.
     * @param int $id Attachment ID.
     * @return string
     */
    public static function content_image($tag, $context, $id) {
        if (!$id || !self::serves($id)) {
            return $tag;
        }
        $map = self::map($id);
        // Attribute boundaries stop photo.jpg matching photo.jpg.webp or a query.
        return preg_replace_callback('/\b(src|srcset)=("|\')(.*?)\2/i', function ($match) use ($map) {
            $value = html_entity_decode($match[3], ENT_QUOTES, 'UTF-8');
            if ('src' === strtolower($match[1])) {
                $value = isset($map[$value]) ? $map[$value] : $value;
            } else {
                $value = preg_replace_callback('/(^|,\s*)(\S+)(\s+\d+[wx])/', function ($candidate) use ($map) {
                    return $candidate[1] . (isset($map[$candidate[2]]) ? $map[$candidate[2]] : $candidate[2]) . $candidate[3];
                }, $value) ?? $value;
            }
            return $match[1] . '=' . $match[2] . esc_attr($value) . $match[2];
        }, $tag) ?? $tag;
    }

    /**
     * Restore this site's cloud addresses before content importers run.
     *
     * @param array $data Slashed post data.
     * @return array
     */
    public static function save_local($data) {
        $config = self::config();
        if ($config['missing']) {
            return $data;
        }
        $remote = $config['url'] . '/';
        // Only standalone addresses: one inside another service's URL (a
        // proxy's ?url=, an archive's path) belongs to that service and stays.
        // Block attributes write a quote or > before an address as \u0022,
        // \u0027 or \u003e. Parentheses end an address, as in CSS url().
        $pattern = '#(?:(?<![\w.~+%:/?\#@&=-])|(?<=\\\\u0022)|(?<=\\\\u0027)|(?<=\\\\u003e))(?:' . preg_quote($remote, '#') . '|' . preg_quote(str_replace('/', '\/', $remote), '#') . ')((?:[^\s<>"\'\\\\()]|\\\\/)+)#';
        foreach (array('post_content', 'post_excerpt') as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $text = wp_unslash($data[$field]);
            $text = preg_replace_callback($pattern, function ($match) use ($config) {
                $path = str_replace('\/', '/', $match[1]);
                $key = rawurldecode(preg_replace('/\?v=[a-f0-9]+$/', '', $path) ?? $path);
                $id = (int) get_option(self::URL_INDEX . hash('sha256', $config['signature'] . $key), 0);
                $record = $id ? get_post_meta($id, self::META, true) : null;
                if (!$id || !get_post($id) || !is_array($record) || $record['signature'] !== $config['signature'] || empty($record['files'][$key]['confirmed'])) {
                    return $match[0];
                }
                // Reverse only the exact inventory, even when the switch is off.
                $relative = $record['files'][$key]['relative'];
                if (substr($relative, -5) === '.webp' && isset($record['files'][substr($key, 0, -5)])) {
                    $relative = substr($relative, 0, -5);
                }
                $root = self::root();
                $uploads = wp_upload_dir(null, false);
                if (!$root) {
                    return $match[0];
                }
                $site_path = ltrim(substr(wp_normalize_path($uploads['basedir']), strlen($root['dir'])), '/');
                if ($site_path && 0 !== strpos($relative, $site_path . '/')) {
                    return $match[0];
                }
                $local = trailingslashit($uploads['baseurl']) . ($site_path ? substr($relative, strlen($site_path) + 1) : $relative);
                return false !== strpos($match[0], '\/') ? str_replace('/', '\/', $local) : $local;
            }, $text) ?? $text;
            $data[$field] = wp_slash($text);
        }
        return $data;
    }

    /**
     * Cached counts for the card and CLI.
     *
     * @return array<string,int>
     */
    public static function counts() {
        global $wpdb;
        $cached = get_transient(self::COUNTS);
        if (is_array($cached) && $cached['signature'] === self::config()['signature']) {
            return $cached['counts'];
        }
        $counts = array('uploaded' => 0, 'waiting' => 0, 'skipped' => 0, 'failed' => 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit status query, no visitor page.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT CASE WHEN p.post_mime_type NOT LIKE %s THEN 'skipped' WHEN d.meta_value = %s THEN COALESCE(m.meta_value, 'failed') ELSE 'waiting' END AS state, COUNT(*) AS total, SUM(COALESCE(s.meta_value, 0)) AS partial FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s LEFT JOIN {$wpdb->postmeta} d ON p.ID = d.post_id AND d.meta_key = %s LEFT JOIN {$wpdb->postmeta} s ON p.ID = s.post_id AND s.meta_key = %s WHERE p.post_type = 'attachment' GROUP BY state", 'image/%', self::config()['signature'], self::STATE, self::DONE, self::SKIPPED));
        foreach ($rows as $row) {
            if (isset($counts[$row->state])) {
                $counts[$row->state] = (int) $row->total;
            }
        }
        foreach ($rows as $row) {
            if ('uploaded' === $row->state) {
                $counts['skipped'] += (int) $row->partial;
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- explicit status query through the meta_key index.
        $counts['local copies removed'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::REMOVED));
        set_transient(self::COUNTS, array('signature' => self::config()['signature'], 'counts' => $counts), 5 * MINUTE_IN_SECONDS);
        return $counts;
    }

    /**
     * Explicit PUT/HEAD/DELETE probe. Every test sends requests, so a
     * corrected constant is tested at once; the result kept for the panel is
     * a safe status, not connection data.
     *
     * @return true|WP_Error
     */
    public static function test_connection() {
        if (!self::ready()) {
            return new WP_Error('storage_off', __('Switch on Media in cloud storage and set its constants first.', 'seoprostack'));
        }
        $config = self::config();
        $client = self::client();
        $key = $config['prefix'] . 'seoprostack-probe-' . get_current_blog_id() . '-' . wp_generate_uuid4();
        $body = 'SEO Pro Stack storage probe';
        $result = $client->put($key, $body, 'text/plain', 'no-store');
        if (!is_wp_error($result)) {
            $head = $client->head($key);
            $result = is_wp_error($head) ? $head : (strlen($body) === $head['size'] ? true : new WP_Error('storage_probe', __('The probe size did not match.', 'seoprostack')));
        }
        $deleted = $client->delete($key);
        if (is_wp_error($deleted)) {
            self::queue_delete(array('signature' => $config['signature']), array($key));
            $result = $deleted;
        }
        set_transient(self::TEST, array('connection' => self::test_id(), 'ok' => !is_wp_error($result), 'message' => is_wp_error($result) ? $result->get_error_message() : __('Connection test passed.', 'seoprostack')), 5 * MINUTE_IN_SECONDS);
        return $result;
    }

    /**
     * Which connection a kept test result belongs to: the destination and
     * the signing inputs, so changing the key, secret or region hides an old
     * result. A keyed hash, so the secret itself is never stored.
     *
     * @return string
     */
    private static function test_id() {
        $config = self::config();
        return hash_hmac('sha256', $config['signature'] . "\n" . $config['region'] . "\n" . $config['key_id'], $config['secret']);
    }

    /** Capability and nonce checked explicit admin probe. */
    public static function handle_test() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot manage these settings.', 'seoprostack'));
        }
        check_admin_referer('seoprostack_storage_test');
        self::test_connection();
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /**
     * Configuration names, cached probe and progress, never connection values.
     *
     * @param string $key Setting key.
     * @param array $field Schema entry.
     */
    public static function panel_status($key, $field = array()) {
        if (self::KEY !== $key) {
            return;
        }
        $config = self::config();
        echo '<div class="sps-panel-note">';
        echo '<p>' . esc_html($config['missing'] ? __('Missing or invalid constants: ', 'seoprostack') . implode(', ', $config['missing']) : __('The connection constants are set.', 'seoprostack')) . '</p>';
        echo '<ul>';
        foreach (array('ENDPOINT', 'REGION', 'BUCKET', 'KEY_ID', 'SECRET', 'URL', 'PREFIX') as $suffix) {
            $name = 'SEOPROSTACK_MEDIA_STORAGE_' . $suffix;
            $set = defined($name) && is_string(constant($name)) && '' !== constant($name);
            $status = $set ? __('Set', 'seoprostack') : (in_array($suffix, array('REGION', 'PREFIX'), true) ? __('Using the default', 'seoprostack') : __('Not set', 'seoprostack'));
            echo '<li><code>' . esc_html($name) . '</code>: ' . esc_html($status) . '</li>';
        }
        echo '</ul>';
        if (is_ssl() && 'https' !== wp_parse_url($config['url'], PHP_URL_SCHEME)) {
            echo '<p>' . esc_html__('Use an HTTPS public storage address to avoid mixed content.', 'seoprostack') . '</p>';
        }
        $removed = (bool) get_option(self::FLAG);
        if ($removed && $config['missing']) {
            echo '<p>' . esc_html__('Some pictures have no local files. Set the constants again, or add the missing-file rule from the read me, so they keep showing.', 'seoprostack') . '</p>';
        }
        if ($removed && self::restoring()) {
            echo '<p>' . esc_html__('Bringing removed files back in the background; until then, those pictures show from the bucket.', 'seoprostack') . '</p>';
        }
        if (self::ready() || self::restoring()) {
            $counts = self::counts();
            foreach ($counts as $state => $count) {
                echo '<p>' . esc_html($state . ': ' . number_format_i18n($count)) . '</p>';
            }
            if ($counts['waiting'] > 0 || ($removed && self::restoring())) {
                // Recover a lost wake-up without waking an idle queue on every view.
                self::schedule(60);
            }
        }
        if (self::ready()) {
            $test = get_transient(self::TEST);
            if (is_array($test) && isset($test['connection'], $test['message']) && is_string($test['connection']) && hash_equals(self::test_id(), $test['connection'])) {
                echo '<p>' . esc_html($test['message']) . '</p>';
            }
            echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=seoprostack_storage_test'), 'seoprostack_storage_test')) . '">' . esc_html__('Test connection', 'seoprostack') . '</a></p>';
        }
        $error = get_option(self::ERROR, '');
        if (is_string($error) && $error) {
            echo '<p>' . esc_html($error) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Attachment details line.
     *
     * @param array $fields Fields.
     * @param WP_Post $post Attachment.
     * @return array
     */
    public static function attachment_field($fields, $post) {
        if (0 !== strpos((string) $post->post_mime_type, 'image/')) {
            return $fields;
        }
        $record = get_post_meta($post->ID, self::META, true);
        $state = is_array($record) && isset($record['state']) ? $record['state'] : 'waiting';
        if (get_post_meta($post->ID, self::DONE, true) !== self::config()['signature']) {
            $state = 'waiting';
        }
        $labels = array('uploaded' => __('Uploaded; local files kept.', 'seoprostack'), 'waiting' => __('Waiting to upload.', 'seoprostack'), 'failed' => __('Upload failed; local files are served.', 'seoprostack'), 'skipped' => __('Kept on this server.', 'seoprostack'));
        $state = self::ready() ? (isset($labels[$state]) ? $labels[$state] : $labels['waiting']) : __('Off or not configured; local files are served.', 'seoprostack');
        if (self::removed($post->ID)) {
            $state = __('Uploaded; local files removed, shown from cloud storage.', 'seoprostack');
        }
        $fields['seoprostack_storage'] = array('label' => __('Media in cloud storage', 'seoprostack'), 'input' => 'html', 'html' => esc_html($state));
        return $fields;
    }

    /**
     * Inspect, test, upload a bounded batch, or bring removed files back.
     *
     * ## OPTIONS
     *
     * <action>
     * : status, test, upload or restore.
     *
     * [--limit=<n>]
     * : Most attachments to upload (default 10).
     *
     * [--retry-failed]
     * : Retry failed attachments and remote deletions without waiting for
     * back-off, and look again at local copies kept after a failed check.
     *
     * [--attachment=<id>]
     * : restore: bring back one attachment's removed files.
     *
     * [--all]
     * : restore: bring back every removed file on this site.
     *
     * @param string[] $args Positional arguments.
     * @param array $assoc Named arguments.
     */
    public static function cli($args, $assoc) {
        $action = isset($args[0]) ? $args[0] : 'status';
        if ('status' === $action) {
            WP_CLI::log(self::ready() ? 'Configured and on.' : 'Off or missing constants.');
            if (self::config()['missing']) {
                WP_CLI::log('Missing or invalid: ' . implode(', ', self::config()['missing']));
            } else {
                WP_CLI::log(self::removing() ? 'Remove local copies after upload: on.' : 'Remove local copies after upload: off.');
            }
            foreach (self::counts() as $state => $count) {
                WP_CLI::log($state . ': ' . $count);
            }
            WP_CLI::log((string) get_option(self::ERROR, ''));
            return;
        }
        if ('restore' === $action) {
            self::cli_restore($assoc);
            return;
        }
        if (!self::ready()) {
            WP_CLI::error('Switch on Media in cloud storage and set its constants first.');
        }
        if ('test' === $action) {
            $result = self::test_connection();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success('Connection test passed.');
            return;
        }
        if ('upload' !== $action) {
            WP_CLI::error('Use status, test, upload or restore.');
        }
        $limit = isset($assoc['limit']) ? max(1, min(1000, (int) $assoc['limit'])) : 10;
        if (isset($assoc['retry-failed'])) {
            foreach (self::jobs($limit, '', 'retry_') as $name) {
                $job = get_option($name);
                if (is_array($job) && 'retry' === $job['kind'] && $job['signature'] === self::config()['signature']) {
                    self::queue((int) $job['id']);
                }
            }
            // This connection's failed remote deletions are due now, too, so
            // fixed credentials also clear them (and the last error) at once.
            // Each is one small request, so all of them, a page at a time.
            $after = '';
            do {
                $names = self::jobs(200, $after, 'delete_' . self::config()['signature'] . '_');
                foreach ($names as $name) {
                    $after = $name;
                    $job = get_option($name);
                    if (is_array($job) && isset($job['next']) && $job['next'] > time()) {
                        $job['next'] = 0;
                        update_option($name, $job, false);
                    }
                }
            } while ($names);
            delete_option(self::CURSOR);
            // Local copies kept after a failed check are looked at again.
            delete_metadata('post', 0, self::LOCAL, 'kept:' . self::config()['signature'], true);
            delete_option(self::LOCAL_WAIT);
            delete_transient(self::COUNTS);
        }
        // Use the same lock, budget and watermark guard as cron.
        $start = microtime(true);
        do {
            $attempts = self::run_batch($limit);
            $limit -= $attempts;
        } while ($attempts && $limit > 0 && self::more_time($start, 60));
        self::schedule();
        WP_CLI::success('Batch finished; remaining pictures continue in the background.');
    }

    /**
     * Bring removed files back now, holding the batch lease a page at a
     * time so a batch never removes a file while it comes back.
     *
     * @param array $assoc Named arguments.
     */
    private static function cli_restore($assoc) {
        if (self::config()['missing']) {
            WP_CLI::error('Set the SEOPROSTACK_MEDIA_STORAGE constants first: removed files come back from that bucket.');
        }
        $one = 0;
        if (isset($assoc['attachment'])) {
            $one = (int) $assoc['attachment'];
            if ($one <= 0 || 'attachment' !== get_post_type($one)) {
                WP_CLI::error('No attachment with that ID.');
            }
        } elseif (!isset($assoc['all'])) {
            WP_CLI::error('Use --attachment=<id> or --all.');
        }
        $restored = 0;
        $failed = 0;
        $before = PHP_INT_MAX;
        do {
            $ids = $one ? array($one) : self::restorable(20, $before);
            $lease = '';
            for ($try = 0; '' === $lease && $try < 30; ++$try) {
                $lease = self::lease();
                if ('' === $lease) {
                    sleep(2);
                }
            }
            if ('' === $lease) {
                WP_CLI::error('A storage batch is still running. Try again in a few minutes.');
            }
            try {
                foreach ($ids as $id) {
                    $before = $id;
                    if (!self::removed($id)) {
                        continue;
                    }
                    $result = self::restore_attachment($id);
                    if (is_wp_error($result)) {
                        ++$failed;
                        WP_CLI::warning(sprintf('%d: %s', $id, $result->get_error_message()));
                    } else {
                        ++$restored;
                    }
                }
            } finally {
                self::release($lease);
            }
        } while (!$one && $ids);
        if (!self::restorable(1)) {
            delete_option(self::FLAG);
        }
        if (self::removing()) {
            WP_CLI::warning('Remove local copies after upload is on: restored pictures stay on this server until they change. Switch it off to keep every file here.');
        }
        $message = sprintf('Brought back the files of %d attachment(s); %d failed.', $restored, $failed);
        if ($failed) {
            WP_CLI::error($message);
        }
        WP_CLI::success($message);
    }
}
