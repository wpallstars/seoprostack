<?php
/**
 * Background copies of images to S3-compatible storage. Local files stay.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
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
    const BUDGET = 25;

    /** Local-address calls must bypass output filters. @var int */
    private static $local = 0;
    /** Nested REST requests' edit contexts. @var bool[] */
    private static $rest = array();

    /** @return array<string,array> */
    public static function settings() {
        return array(self::KEY => array(
            'type' => 'bool', 'default' => false, 'tab' => 'media',
            'label' => __('Media in cloud storage', 'seoprostack'),
            'description' => __('Copy pictures to S3-compatible storage in the background and serve uploaded pictures from its public address. Files stay on this server too. Needs the SEOPROSTACK_MEDIA_STORAGE constants in wp-config.php. Purge page caches after switching on or off.', 'seoprostack'),
        ));
    }

    /**
     * Read connection constants without persisting or exposing their values.
     *
     * @return array
     */
    public static function config() {
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
        return $config;
    }

    /** @return bool */
    private static function ready() {
        return self::enabled() && !self::config()['missing'];
    }

    /** Register local hooks even when off; remote work always checks ready(). */
    public static function boot() {
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 2);
        add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        add_action('admin_post_seoprostack_storage_test', array(__CLASS__, 'handle_test'));
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'attachment_field'), 20, 2);
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
        if (!self::config()['missing']) {
            add_filter('wp_insert_post_data', array(__CLASS__, 'save_local'), 5);
        }
        if (!self::ready()) {
            return;
        }
        add_filter('wp_get_attachment_url', array(__CLASS__, 'attachment_url'), 20, 2);
        add_filter('image_downsize', array(__CLASS__, 'downsize'), 20, 3);
        add_filter('wp_calculate_image_srcset_meta', array(__CLASS__, 'srcset_meta'), 20, 3);
        add_filter('wp_calculate_image_srcset', array(__CLASS__, 'srcset'), 20, 5);
        add_filter('wp_content_img_tag', array(__CLASS__, 'content_image'), 10, 3);
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'rest_before'), 10, 3);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'rest_after'), 10, 3);
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
     * Whether output addresses may change on this request.
     *
     * @return bool
     */
    private static function serving() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- context only, no mutation.
        $edit = defined('REST_REQUEST') && REST_REQUEST && isset($_REQUEST['context']) && 'edit' === $_REQUEST['context'];
        return !self::$local && !is_admin() && !$edit && !in_array(true, self::$rest, true) && self::ready();
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
     * Schedule a bounded batch.
     *
     * @param int $delay Seconds.
     */
    public static function schedule($delay = 0) {
        if (self::ready() && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + (int) $delay, self::HOOK);
        }
    }

    /**
     * React to the site's switch.
     *
     * @param string $key Setting.
     * @param mixed $value Saved value.
     */
    public static function setting_saved($key, $value) {
        if (self::KEY !== $key) {
            return;
        }
        if (self::ready()) {
            self::schedule();
        } else {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * Backfill newest attachments first, through the post ID index.
     *
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
        if (!self::ready()) {
            return 0;
        }
        self::schedule(60);
        $watermark = SEOProStack_Watermark_Images::job();
        if ($watermark && empty($watermark['ended'])) {
            return 0;
        }
        // add_option is atomic across requests; a crashed batch's lease expires.
        $lease = (int) get_option(self::LOCK, 0);
        if ($lease && $lease < time()) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete prevents two stale-lock recoveries releasing a new lease.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, (string) $lease));
            wp_cache_delete(self::LOCK, 'options');
        }
        if (!add_option(self::LOCK, time() + 300, '', false)) {
            return 0;
        }
        $start = microtime(true);
        $limit = max(1, min(10, (int) $limit));
        $attempts = 0;
        try {
            $attempts = self::process_jobs($start, $limit);
            $ids = $attempts < $limit ? self::waiting($limit - $attempts) : array();
            foreach ($ids as $id) {
                if (!self::more_time($start, self::BUDGET)) {
                    break;
                }
                self::upload_attachment($id);
                ++$attempts;
            }
        } finally {
            delete_option(self::LOCK);
        }
        if (!self::waiting(1) && !self::jobs(1)) {
            wp_clear_scheduled_hook(self::HOOK);
        }
        return $attempts;
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
                self::upload_attachment((int) $job['id']);
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
        $root = SEOProStack_Nextgen_Images::main_uploads();
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
                $files[$config['prefix'] . $relative] = array('path' => $path, 'relative' => $relative, 'size' => $bytes, 'mtime' => $mtime, 'type' => $type['type'], 'version' => substr(hash('sha256', $bytes . ':' . $mtime), 0, 12));
            }
        }
        return $files;
    }

    /**
     * Upload the whole attachment atomically; unchanged files make no request.
     *
     * @param int $id Attachment ID.
     * @return bool Whether all eligible files were copied.
     */
    public static function upload_attachment($id) {
        if (!self::ready() || 0 !== strpos((string) get_post_mime_type($id), 'image/')) {
            return false;
        }
        $config = self::config();
        $generation = get_post_meta($id, self::GENERATION, true);
        $old = get_post_meta($id, self::META, true);
        $old = is_array($old) ? $old : array();
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
        $start = microtime(true);
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
            $same = isset($old['signature']) && $old['signature'] === $config['signature'] && !empty($previous['confirmed']) && isset($previous['size'], $previous['mtime']) && $previous['size'] === $info['size'] && $previous['mtime'] === $info['mtime'];
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
                $result = self::client()->put_file($key, $info['path'], $info['type'], $policy);
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
     * Local to public URL map, only for a completed inventory on this connection.
     *
     * @param int $id Attachment ID.
     * @return array<string,string>
     */
    private static function map($id) {
        $record = get_post_meta($id, self::META, true);
        $config = self::config();
        if (!is_array($record) || empty($record['files']) || 'uploaded' !== $record['state'] || $record['signature'] !== $config['signature'] || get_post_meta($id, self::DONE, true) !== $config['signature']) {
            return array();
        }
        $root = SEOProStack_Nextgen_Images::main_uploads();
        $uploads = wp_upload_dir(null, false);
        if (!$root || 0 !== strpos(wp_normalize_path($uploads['basedir']), $root['dir'])) {
            return array();
        }
        $site_path = ltrim(substr(wp_normalize_path($uploads['basedir']), strlen($root['dir'])), '/');
        $map = array();
        foreach ($record['files'] as $key => $info) {
            $relative = $info['relative'];
            if ($site_path && 0 !== strpos($relative, $site_path . '/')) {
                continue;
            }
            $local = trailingslashit($uploads['baseurl']) . ($site_path ? substr($relative, strlen($site_path) + 1) : $relative);
            $target = isset($record['files'][$key . '.webp']) ? $key . '.webp' : $key;
            $map[$local] = $config['url'] . '/' . implode('/', array_map('rawurlencode', explode('/', $target))) . '?v=' . $record['files'][$target]['version'];
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
        if (!self::serving()) {
            return $url;
        }
        $map = self::map($id);
        return isset($map[$url]) ? $map[$url] : $url;
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
        if ($out || !self::serving()) {
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
     * Match encoded public filenames before core checks the source against metadata.
     * This copy is only used for srcset calculation, never saved to the database.
     *
     * @param array $meta Image metadata.
     * @param int[] $size Dimensions.
     * @param string $src Selected source.
     * @return array
     */
    public static function srcset_meta($meta, $size, $src) {
        if (!self::serving() || 0 !== strpos($src, self::config()['url'] . '/')) {
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
     * @param array $sources Candidates.
     * @param int[] $size Dimensions.
     * @param string $src Selected source.
     * @param array $meta Metadata.
     * @param int $id Attachment ID.
     * @return array
     */
    public static function srcset($sources, $size, $src, $meta, $id) {
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
        if (!self::serving() || !$id) {
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
        $pattern = '#(?:' . preg_quote($remote, '#') . '|' . preg_quote(str_replace('/', '\/', $remote), '#') . ')((?:[^\s<>"\'\\\\]|\\\\/)+)#';
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
                $root = SEOProStack_Nextgen_Images::main_uploads();
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
        set_transient(self::COUNTS, array('signature' => self::config()['signature'], 'counts' => $counts), 5 * MINUTE_IN_SECONDS);
        return $counts;
    }

    /**
     * Explicit PUT/HEAD/DELETE probe; cache only a safe status, not connection data.
     *
     * @return true|WP_Error
     */
    public static function test_connection() {
        if (!self::ready()) {
            return new WP_Error('storage_off', __('Switch on Media in cloud storage and set its constants first.', 'seoprostack'));
        }
        $config = self::config();
        $cached = get_transient(self::TEST);
        if (is_array($cached) && $cached['signature'] === $config['signature']) {
            return $cached['ok'] ? true : new WP_Error('storage_test', $cached['message']);
        }
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
        set_transient(self::TEST, array('signature' => $config['signature'], 'ok' => !is_wp_error($result), 'message' => is_wp_error($result) ? $result->get_error_message() : __('Connection test passed.', 'seoprostack')), 5 * MINUTE_IN_SECONDS);
        return $result;
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
        if (self::ready()) {
            foreach (self::counts() as $state => $count) {
                echo '<p>' . esc_html($state . ': ' . number_format_i18n($count)) . '</p>';
            }
            self::schedule();
            $test = get_transient(self::TEST);
            if (is_array($test) && $test['signature'] === $config['signature']) {
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
        $fields['seoprostack_storage'] = array('label' => __('Media in cloud storage', 'seoprostack'), 'input' => 'html', 'html' => esc_html($state));
        return $fields;
    }

    /**
     * Inspect, test or upload a bounded batch.
     *
     * ## OPTIONS
     *
     * <action>
     * : status, test or upload.
     *
     * [--limit=<n>]
     * : Most attachments to upload (default 10).
     *
     * [--retry-failed]
     * : Retry failed attachments without waiting for back-off.
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
            }
            foreach (self::counts() as $state => $count) {
                WP_CLI::log($state . ': ' . $count);
            }
            WP_CLI::log((string) get_option(self::ERROR, ''));
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
            WP_CLI::error('Use status, test or upload.');
        }
        $limit = isset($assoc['limit']) ? max(1, min(1000, (int) $assoc['limit'])) : 10;
        if (isset($assoc['retry-failed'])) {
            foreach (self::jobs($limit, '', 'retry_') as $name) {
                $job = get_option($name);
                if (is_array($job) && 'retry' === $job['kind'] && $job['signature'] === self::config()['signature']) {
                    self::queue((int) $job['id']);
                }
            }
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
}
