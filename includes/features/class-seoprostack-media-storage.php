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
    const BUDGET = 25;

    /** Local-address calls must bypass output filters. @var int */
    private static $local = 0;

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
            $config['region'] = substr($host, -26) === '.r2.cloudflarestorage.com' ? 'auto' : 'us-east-1';
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
        add_action('seoprostack_media_files_changed', array(__CLASS__, 'queue'));
        add_action('seoprostack_media_replaced', array(__CLASS__, 'queue'));
        add_action(self::HOOK, array(__CLASS__, 'run_batch'));
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
        add_filter('wp_calculate_image_srcset', array(__CLASS__, 'srcset'), 20, 5);
        add_filter('wp_content_img_tag', array(__CLASS__, 'content_image'), 10, 3);
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
        return !self::$local && !is_admin() && !$edit && self::ready();
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
        delete_post_meta($id, self::DONE);
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
            add_option(self::JOB . 'delete_' . hash('sha256', $record['signature'] . $key), $job, '', false);
        }
        self::schedule(10);
    }

    /**
     * Read records before core deletes attachment meta, also while switched off.
     *
     * @param int $id Attachment ID.
     */
    public static function deleted($id) {
        $record = get_post_meta($id, self::META, true);
        if (is_array($record) && !empty($record['files']) && isset($record['signature'])) {
            self::queue_delete($record, array_keys($record['files']));
        }
        delete_option(self::JOB . 'retry_' . (int) $id);
    }

    /**
     * Indexed option-name lookup, never read all jobs into memory.
     *
     * @param int $limit Maximum rows.
     * @return string[]
     */
    private static function jobs($limit = 20) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded lookup through option_name's unique index.
        return $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT %d", $wpdb->esc_like(self::JOB) . '%', (int) $limit));
    }

    /** Run uploads and deletes off the page, waiting for watermark jobs. */
    public static function run_batch() {
        if (!self::ready()) {
            return;
        }
        self::schedule(60);
        $watermark = SEOProStack_Watermark_Images::job();
        if ($watermark && empty($watermark['ended'])) {
            return;
        }
        // add_option is atomic across requests; a crashed batch's lease expires.
        $lease = (int) get_option(self::LOCK, 0);
        if ($lease && $lease < time()) {
            delete_option(self::LOCK);
        }
        if (!add_option(self::LOCK, time() + 300, '', false)) {
            return;
        }
        $start = microtime(true);
        try {
            self::process_jobs($start);
            foreach (self::waiting(10) as $id) {
                if (!self::more_time($start, self::BUDGET)) {
                    break;
                }
                self::upload_attachment($id);
            }
        } finally {
            delete_option(self::LOCK);
        }
        if (!self::waiting(1) && !self::jobs(1)) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * Retry uploads and remote deletes; old connections' jobs are kept, not sent.
     *
     * @param float $start Batch start.
     */
    private static function process_jobs($start) {
        $config = self::config();
        foreach (self::jobs() as $name) {
            if (!self::more_time($start, self::BUDGET)) {
                return;
            }
            $job = get_option($name);
            if (!is_array($job) || $job['signature'] !== $config['signature'] || $job['next'] > time()) {
                continue;
            }
            if ('retry' === $job['kind']) {
                delete_option($name);
                self::upload_attachment((int) $job['id']);
                continue;
            }
            $result = self::client()->delete($job['key']);
            if (is_wp_error($result)) {
                $job['next'] = time() + HOUR_IN_SECONDS;
                update_option($name, $job, false);
                update_option(self::ERROR, $result->get_error_message(), false);
            } else {
                delete_option($name);
            }
        }
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
            if (!empty($nextgen['files'][$name]['webp']) && $nextgen['files'][$name]['webp'] > 0) {
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
        $old = get_post_meta($id, self::META, true);
        $old = is_array($old) ? $old : array();
        $attempt = isset($old['attempts']) ? (int) $old['attempts'] + 1 : 1;
        // Tried first: even a fatal error moves the queue forward and retries later.
        update_post_meta($id, self::DONE, $config['signature']);
        $job = array('kind' => 'retry', 'id' => $id, 'signature' => $config['signature'], 'next' => time() + HOUR_IN_SECONDS);
        update_option(self::JOB . 'retry_' . $id, $job, false);
        $record = array('signature' => $config['signature'], 'files' => array(), 'state' => 'failed', 'attempts' => $attempt, 'skipped' => 0);
        // Keep the previous inventory for deletion/recovery, but never serve a partial upload.
        $pending = $old;
        $pending['state'] = 'failed';
        $pending['attempts'] = $attempt;
        update_post_meta($id, self::META, $pending);
        clearstatcache();
        $files = self::files($id);
        foreach ($files as $key => $info) {
            if ($info['size'] > self::size_cap()) {
                ++$record['skipped'];
                continue;
            }
            $previous = isset($old['files'][$key]) ? $old['files'][$key] : array();
            $same = isset($old['signature']) && $old['signature'] === $config['signature'] && isset($previous['size'], $previous['mtime']) && $previous['size'] === $info['size'] && $previous['mtime'] === $info['mtime'];
            if (!$same) {
                /** Filter the public object cache policy. @param string $policy Cache-Control. */
                $policy = (string) apply_filters('seoprostack_media_storage_cache_control', 'public, max-age=31536000, immutable');
                $result = self::client()->put_file($key, $info['path'], $info['type'], $policy);
                if (is_wp_error($result)) {
                    $pending['error'] = $result->get_error_message();
                    update_post_meta($id, self::META, $pending);
                    update_option(self::ERROR, $pending['error'], false);
                    $job['next'] = time() + ($attempt < 2 ? HOUR_IN_SECONDS : ($attempt < 3 ? 6 : 24) * HOUR_IN_SECONDS);
                    update_option(self::JOB . 'retry_' . $id, $job, false);
                    return false;
                }
            }
            unset($info['path'], $info['type']);
            $record['files'][$key] = $info;
        }
        $record['state'] = $record['files'] ? 'uploaded' : 'skipped';
        $record['attempts'] = 0;
        if (!$files) {
            ++$record['skipped'];
        }
        // Metadata or copies changed during PUT: do not publish this stale inventory.
        if (get_post_meta($id, self::DONE, true) !== $config['signature'] || !get_post($id)) {
            self::queue_delete($record, array_keys($record['files']));
            return false;
        }
        if (isset($old['signature']) && $old['signature'] === $config['signature'] && !empty($old['files'])) {
            self::queue_delete($old, array_keys(array_diff_key($old['files'], $record['files'])));
        }
        update_post_meta($id, self::META, $record);
        delete_option(self::JOB . 'retry_' . $id);
        return true;
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
        return self::serving() && $id ? strtr($tag, self::map($id)) : $tag;
    }

    /**
     * Restore this site's cloud addresses before content importers run.
     *
     * @param array $data Slashed post data.
     * @return array
     */
    public static function save_local($data) {
        $config = self::config();
        $root = SEOProStack_Nextgen_Images::main_uploads();
        $uploads = wp_upload_dir(null, false);
        if ($config['missing'] || !$root) {
            return $data;
        }
        $site_path = ltrim(substr(wp_normalize_path($uploads['basedir']), strlen($root['dir'])), '/');
        $remote = $config['url'] . '/' . implode('/', array_map('rawurlencode', explode('/', $config['prefix'] . ($site_path ? $site_path . '/' : ''))));
        foreach (array('post_content', 'post_excerpt') as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $text = wp_unslash($data[$field]);
            $text = str_replace(str_replace('/', '\/', $remote), $remote, $text);
            $text = preg_replace_callback('#' . preg_quote($remote, '#') . '([^\s<>"\'\\\\]+)#', function ($match) use ($uploads) {
                $path = preg_replace('/\?v=[a-f0-9]+$/', '', $match[1]);
                $path = preg_replace('/\.(jpe?g|png|webp)\.webp$/i', '.$1', $path);
                if (false !== strpos(rawurldecode($path), '..')) {
                    return $match[0];
                }
                return trailingslashit($uploads['baseurl']) . rawurldecode($path);
            }, $text);
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
        $counts = array('uploaded' => 0, 'waiting' => 0, 'skipped' => 0, 'failed' => 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit status query, no visitor page.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.meta_value, d.meta_value AS done FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s LEFT JOIN {$wpdb->postmeta} d ON p.ID = d.post_id AND d.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s", self::META, self::DONE, 'image/%'));
        foreach ($rows as $row) {
            $record = maybe_unserialize($row->meta_value);
            $state = $row->done === self::config()['signature'] && is_array($record) && isset($record['state']) ? $record['state'] : 'waiting';
            if (isset($counts[$state])) {
                ++$counts[$state];
            }
        }
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
        $result = $client->put($key, 'SEO Pro Stack storage probe', 'text/plain', 'no-store');
        if (!is_wp_error($result)) {
            $head = $client->head($key);
            $result = is_wp_error($head) ? $head : (26 === $head['size'] ? true : new WP_Error('storage_probe', __('The probe size did not match.', 'seoprostack')));
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
        if (isset($assoc['retry-failed'])) {
            foreach (self::jobs(100) as $name) {
                $job = get_option($name);
                if (is_array($job) && 'retry' === $job['kind']) {
                    self::queue((int) $job['id']);
                }
            }
        }
        // Use the same lock, budget and watermark guard as cron.
        $limit = isset($assoc['limit']) ? max(1, min(1000, (int) $assoc['limit'])) : 10;
        $start = microtime(true);
        do {
            $before = count(self::waiting($limit));
            self::run_batch();
            $limit -= min(10, $before);
        } while ($before && $limit > 0 && self::more_time($start, 60));
        self::schedule();
        WP_CLI::success('Batch finished; remaining pictures continue in the background.');
    }
}
