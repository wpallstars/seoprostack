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
    /** Background restore failed for this attachment alone: the signature it failed under. */
    const STUCK = '_seoprostack_storage_stuck';
    /** Restore errors that belong to one attachment: the batch moves past it. */
    const STUCK_ERRORS = array('storage_signature', 'storage_memory', 'storage_restore_mismatch', 'storage_restore_record', 'storage_restore_missing');
    const BUDGET = 25;
    /** Safe destination descriptions and archived inventories share uninstall's job namespace. */
    const DESTINATIONS = 'seoprostack_storage_job_destinations';
    /** Claim index: each stored path and key, so the same-path check reads only candidates (GH#981). */
    const CLAIMS = 'seoprostack_media_claims';
    /** Claim index state: schema, the version that built it, and the build cursor. */
    const CLAIMS_STATE = 'seoprostack_storage_job_claims';
    const CLAIMS_SCHEMA = '1';

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
    /** Original leases held until the reader finishes this request. @var array<string,string> */
    private static $original_leases = array();
    /** Attachments whose local files this batch deleted: their posts' cached pages are purged. @var array<int,true> */
    private static $purge = array();

    /** @return array<string,array> */
    public static function settings() {
        return array(
            self::KEY => array(
                'type' => 'bool', 'default' => false, 'tab' => 'media',
                'label' => __('Media in cloud storage', 'seoprostack'),
                'description' => __('Copy pictures, sizes and registered WebP and AVIF copies to S3-compatible storage in the background and serve them from its public address. AVIF is offered in picture mode. Files stay on this server too, unless Remove local copies after upload is on. Needs the SEOPROSTACK_MEDIA_STORAGE constants in wp-config.php. Purge page caches after switching on or off.', 'seoprostack'),
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
        foreach (array('endpoint', 'region', 'bucket', 'key_id', 'secret', 'url', 'prefix', 'originals_bucket') as $key) {
            $name = 'SEOPROSTACK_MEDIA_STORAGE_' . strtoupper($key);
            $value = defined($name) ? constant($name) : '';
            $config[$key] = is_string($value) ? $value : '';
            if ('' === $config[$key] && !in_array($key, array('region', 'prefix', 'originals_bucket'), true)) {
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
        $config['originals_error'] = '';
        if ('' !== $config['originals_bucket'] && ($config['originals_bucket'] === $config['bucket'] || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $config['originals_bucket']))) {
            $config['originals_error'] = __('The originals bucket must be a valid, separate private bucket, not the public media bucket.', 'seoprostack');
        }
        $config['signature'] = hash('sha256', $config['endpoint'] . '\n' . $config['bucket'] . '\n' . $config['prefix']);
        self::$config = $config;
        if (!$config['missing'] && (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
            self::remember_destination($config);
        }
        return $config;
    }

    /**
     * Keep only public connection details. A third destination waits for cleanup;
     * reverting to the previous constants swaps the two descriptions.
     *
     * @param array $config Valid current configuration.
     */
    private static function remember_destination($config) {
        $saved = get_option(self::DESTINATIONS, array());
        if (isset($saved['current']['signature']) && $saved['current']['signature'] === $config['signature']) {
            return;
        }
        $current = array_intersect_key($config, array_flip(array('endpoint', 'region', 'bucket', 'prefix', 'url', 'signature')));
        if (!empty($saved['previous']) && $saved['previous']['signature'] !== $config['signature']) {
            return;
        }
        $previous = isset($saved['current']) ? $saved['current'] : array();
        if (!$saved) {
            $previous = self::recover_destination($config['signature']);
        }
        update_option(self::DESTINATIONS, array('current' => $current, 'previous' => $previous, 'retired' => isset($saved['retired']) ? $saved['retired'] : array()), false);
    }

    /**
     * Upgrade recovery: legacy records did not keep endpoint/bucket descriptions.
     *
     * @param string $signature Current signature.
     * @return array Safe previous description, or none.
     */
    private static function recover_destination($signature) {
        $before = PHP_INT_MAX;
        $candidate = array();
        do {
            $ids = self::inventory_ids(100, $before);
            foreach ($ids as $id) {
                $before = $id;
                $record = self::recover_record_destination($id, $signature);
                if (!$record) {
                    continue;
                }
                if (!$candidate) {
                    $candidate = $record;
                }
                if ($record['signature'] === $candidate['signature'] && '' !== $record['url']) {
                    return $record;
                }
            }
        } while ($ids);
        return $candidate;
    }

    /**
     * Search past newer records with local files for an old removed record's URL.
     *
     * @param int $id Attachment ID.
     * @param string $signature Current signature to exclude.
     * @return array|null
     */
    private static function recover_record_destination($id, $signature) {
        $record = get_post_meta($id, self::META, true);
        if (!is_array($record) || empty($record['signature']) || $record['signature'] === $signature) {
            return null;
        }
        $removed = self::fresh_removed($id);
        return array('signature' => $record['signature'], 'endpoint' => '', 'bucket' => '', 'prefix' => self::inventory_prefix($record['files'] ?? array()), 'url' => $removed['url'] ?? ($record['url'] ?? ''));
    }

    /**
     * Infer only the prefix from a legacy key and relative path, never a host.
     *
     * @param array $files Recorded objects.
     * @return string
     */
    private static function inventory_prefix($files) {
        foreach ($files as $key => $info) {
            if (!empty($info['relative']) && substr($key, -strlen($info['relative'])) === $info['relative']) {
                return substr($key, 0, strlen($key) - strlen($info['relative']));
            }
        }
        return '';
    }

    /**
     * Read one bounded page through the meta_key index.
     *
     * @param int $limit Most records.
     * @param int $before Paging cursor.
     * @return int[]
     */
    private static function inventory_ids($limit = 100, $before = PHP_INT_MAX) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded inventory lookup through meta_key.
        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND post_id < %d ORDER BY post_id DESC LIMIT %d", self::META, self::REMOVED, $before, $limit)));
    }

    /**
     * Stream inventories in bounded pages, without caching the whole library.
     *
     * @return Generator<int,array>
     */
    private static function stored_records() {
        $before = PHP_INT_MAX;
        do {
            $ids = self::inventory_ids(100, $before);
            $records = self::handover_records($ids);
            foreach ($ids as $id) {
                yield $id => $records[$id] ?? array();
            }
            if ($ids) {
                $before = min($ids);
            }
        } while ($ids);
    }

    /**
     * Supply core's missing directory entries, including original and format names.
     * Keep reservations while restoring, even when the remove switch is now off.
     *
     * @param array|null $files Core's optional short-circuited directory listing.
     * @param string $dir Directory being checked.
     * @param string $filename Candidate name (core checks it against the list).
     * @return array|null
     */
    public static function unique_filename_files($files, $dir, $filename) {
        $root = self::root();
        $dir = untrailingslashit(wp_normalize_path($dir));
        if (!$root || (!self::removing() && !get_option(self::FLAG)) || !self::within($dir, $root['dir'])) {
            return $files;
        }
        // Returning an array bypasses scandir(), so include real local files too.
        $names = is_array($files) ? $files : (is_dir($dir) ? scandir($dir) : array());
        $names = is_array($names) ? $names : array();
        $folder = ltrim(substr($dir, strlen($root['dir'])), '/');
        foreach (self::stored_records() as $values) {
            foreach (array(self::META, self::REMOVED) as $meta_key) {
                foreach ($values[$meta_key]['files'] ?? array() as $info) {
                    $relative = $info['relative'] ?? '';
                    if (self::safe_relative($relative) && ('.' === dirname($relative) ? '' : dirname($relative)) === $folder) {
                        $name = wp_basename($relative);
                        $names[] = $name;
                        // Core checks exact names with file_exists(), not this list.
                        // A virtual scaled sibling makes its rendition check reserve
                        // the original too, even for tiny images with no subsizes.
                        $extension = pathinfo($name, PATHINFO_EXTENSION);
                        if ('' !== $extension) {
                            $names[] = pathinfo($name, PATHINFO_FILENAME) . '-scaled.' . $extension;
                        }
                    }
                }
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Refuse every target claimed by another live attachment, even an unconfirmed
     * attempt. Compare relative paths across destinations as well as exact keys.
     *
     * @param int $id Uploading attachment.
     * @param array $files Proposed files.
     * @return bool
     */
    private static function conflicting_files($id, $files) {
        if (!$files) {
            return false;
        }
        $paths = array_column($files, 'relative');
        $paths = array_fill_keys($paths, true);
        $signature = self::config()['signature'];
        // Only the attachments that may claim one of these files are read
        // (GH#981); the comparison below decides, as before.
        $others = self::claim_candidates($id, $files);
        foreach (array_chunk($others, 100) as $chunk) {
            foreach (self::handover_records($chunk) as $other => $values) {
                if ($other !== $id && self::records_claim($values, $paths, $files, $signature)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether another attachment's inventories claim one of the files: the
     * same relative path under any destination, or the same key under this one.
     *
     * @param array $values Inventories by meta key.
     * @param array $paths Proposed relative paths, as keys.
     * @param array $files Proposed files by key.
     * @param string $signature Current destination.
     * @return bool
     */
    private static function records_claim($values, $paths, $files, $signature) {
        foreach (array(self::META, self::REMOVED) as $meta_key) {
            $record = $values[$meta_key] ?? array();
            $same = ($record['signature'] ?? '') === $signature;
            foreach ($record['files'] ?? array() as $key => $info) {
                if (isset($paths[$info['relative'] ?? '']) || ($same && isset($files[$key]))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Claims of files, as 16-byte hashes (hex): each relative path, and each
     * key under its destination's signature. A stored file always claims a
     * path, even an empty one, so a proposed empty path still finds files
     * without one.
     *
     * @param array $files Files by key.
     * @param string $signature Destination of the keys.
     * @param bool $stored Whether these are stored files rather than proposed ones.
     * @return string[]
     */
    private static function claim_hashes($files, $signature, $stored) {
        $hashes = array();
        foreach ($files as $key => $info) {
            if ($stored || (is_array($info) && array_key_exists('relative', $info))) {
                $hashes[self::claim_hash('p:' . (is_array($info) ? (string) ($info['relative'] ?? '') : ''))] = true;
            }
            $hashes[self::claim_hash('k:' . $signature . "\n" . $key)] = true;
        }
        return array_keys($hashes);
    }

    /**
     * An index key, not a secret: the first 16 bytes of the SHA-256, as hex
     * for UNHEX() into the binary(16) column.
     *
     * @param string $claim Path or key claim.
     * @return string 32 hex characters.
     */
    private static function claim_hash($claim) {
        return substr(hash('sha256', $claim), 0, 32);
    }

    /**
     * Other attachments that may claim one of these files: from the claim
     * index once it is complete, otherwise from a byte search of the stored
     * inventories. Both may return attachments that do not conflict, never
     * miss one that does.
     *
     * @param int $id Uploading attachment.
     * @param array $files Proposed files.
     * @return int[]
     */
    private static function claim_candidates($id, $files) {
        global $wpdb;
        $table = self::claims_table(true);
        if ('' !== $table) {
            $hashes = self::claim_hashes($files, self::config()['signature'], false);
            $ids = array();
            foreach (array_chunk($hashes, 200) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), 'UNHEX(%s)'));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- primary-key lookup in the plugin's own table; $in has one placeholder per hash.
                $found = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM %i WHERE claim IN ($in) AND post_id <> %d", array_merge(array($table), $chunk, array($id))));
                if ($wpdb->last_error) {
                    // Such as a dropped table: create and fill it again.
                    self::claims_failed();
                    return self::searched_candidates($id, $files);
                }
                $ids = array_merge($ids, array_map('intval', $found));
            }
            return array_values(array_unique($ids));
        }
        return self::searched_candidates($id, $files);
    }

    /**
     * Search the serialized inventories for the files' paths and keys, a
     * bounded page at a time through the meta_key index, and unserialize
     * nothing. Every stored path or key is serialized whole, followed by '";'.
     *
     * @param int $id Uploading attachment.
     * @param array $files Proposed files.
     * @return int[]
     */
    private static function searched_candidates($id, $files) {
        global $wpdb;
        $needles = self::search_needles($files);
        $ids = array();
        foreach (array(self::META, self::REMOVED) as $meta_key) {
            $after = 0;
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded pages in meta_key index order.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id LIMIT 200", $meta_key, $after));
                foreach ($rows as $row) {
                    $after = (int) $row->meta_id;
                    $other = (int) $row->post_id;
                    if ($other !== $id && !isset($ids[$other]) && self::has_needle($row->meta_value, $needles)) {
                        $ids[$other] = true;
                    }
                }
            } while (200 === count($rows));
        }
        return array_keys($ids);
    }

    /**
     * The byte strings a serialized inventory claiming one of the files
     * contains: each path and each key, serialized whole. A file without a
     * path matches every inventory, so the only needle is then ''.
     *
     * @param array $files Proposed files by key.
     * @return string[]
     */
    private static function search_needles($files) {
        $needles = array();
        foreach ($files as $key => $info) {
            if (is_array($info) && array_key_exists('relative', $info)) {
                $relative = (string) $info['relative'];
                if ('' === $relative) {
                    return array('');
                }
                $needles[$relative . '";'] = true;
            }
            $needles[is_int($key) ? 'i:' . $key . ';' : $key . '";'] = true;
        }
        return array_map('strval', array_keys($needles));
    }

    /**
     * Whether a serialized value contains one of the needles ('' matches all).
     *
     * @param string $value Serialized inventory.
     * @param string[] $needles Byte strings.
     * @return bool
     */
    private static function has_needle($value, $needles) {
        foreach ($needles as $needle) {
            if ('' === $needle || false !== strpos($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The claim index table, once created. Lookups need it complete too: built
     * by this version, as an earlier version may have changed inventories
     * without indexing them.
     *
     * @param bool $complete Whether lookups may trust it.
     * @return string Table name, or empty.
     */
    private static function claims_table($complete = false) {
        global $wpdb;
        $state = get_option(self::CLAIMS_STATE, array());
        if (!is_array($state) || self::CLAIMS_SCHEMA !== ($state['schema'] ?? '') || ($complete && SEOPROSTACK_VERSION !== ($state['built'] ?? ''))) {
            return '';
        }
        return $wpdb->prefix . self::CLAIMS;
    }

    /**
     * Index an attachment's stored files. Rows are only added: a stale row
     * just makes another candidate. A failed write sends lookups back to the
     * search until the index is built again.
     *
     * @param int $id Attachment ID.
     * @param array $records Inventories.
     * @return bool Whether every row is stored.
     */
    private static function index_claims($id, $records) {
        global $wpdb;
        $table = self::claims_table();
        if ('' === $table) {
            return false;
        }
        $hashes = array();
        foreach ($records as $record) {
            if (is_array($record) && !empty($record['files']) && is_array($record['files'])) {
                $hashes = array_merge($hashes, self::claim_hashes($record['files'], (string) ($record['signature'] ?? ''), true));
            }
        }
        foreach (array_chunk(array_unique($hashes), 200) as $chunk) {
            $values = implode(',', array_fill(0, count($chunk), '(UNHEX(%s), %d)'));
            $args = array($table);
            foreach ($chunk as $hash) {
                $args[] = $hash;
                $args[] = (int) $id;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; $values has two placeholders per hash.
            if (false === $wpdb->query($wpdb->prepare("INSERT IGNORE INTO %i (claim, post_id) VALUES $values", $args))) {
                self::claims_failed();
                return false;
            }
        }
        return true;
    }

    /**
     * The claim index failed (a write or lookup error, such as a dropped
     * table): lookups search the inventories, and the index is created and
     * filled again from the start, no sooner than ten minutes from now.
     */
    private static function claims_failed() {
        update_option(self::CLAIMS_STATE, array('retry' => time() + 10 * MINUTE_IN_SECONDS), false);
    }

    /**
     * When the claim index next needs a build step; null once it is complete
     * for this version, or while uploads are off.
     *
     * @phpstan-impure Reads the index state, which batches change.
     * @return int|null
     */
    private static function claims_due() {
        if (!self::ready()) {
            return null;
        }
        $state = get_option(self::CLAIMS_STATE, array());
        $state = is_array($state) ? $state : array();
        if (self::CLAIMS_SCHEMA === ($state['schema'] ?? '') && SEOPROSTACK_VERSION === ($state['built'] ?? '')) {
            return null;
        }
        return max(time(), (int) ($state['retry'] ?? 0));
    }

    /**
     * Create the claim index and fill it from every stored inventory, in
     * bounded steps. Changes during the build are indexed as they are saved.
     *
     * @param float $start Batch start.
     * @param int $budget Seconds for this step.
     */
    private static function build_claims($start, $budget) {
        global $wpdb;
        $state = get_option(self::CLAIMS_STATE, array());
        $state = is_array($state) ? $state : array();
        if ((int) ($state['retry'] ?? 0) > time()) {
            return;
        }
        if (self::CLAIMS_SCHEMA !== ($state['schema'] ?? '')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = $wpdb->prefix . self::CLAIMS;
            dbDelta("CREATE TABLE {$table} (
                claim binary(16) NOT NULL,
                post_id bigint(20) unsigned NOT NULL,
                PRIMARY KEY  (claim,post_id),
                KEY post_id (post_id)
            ) {$wpdb->get_charset_collate()};");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema detection, not content data.
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
                self::claims_failed();
                return;
            }
            $state = array('schema' => self::CLAIMS_SCHEMA, 'built' => '', 'building' => '');
            update_option(self::CLAIMS_STATE, $state, false);
        }
        if (SEOPROSTACK_VERSION === ($state['built'] ?? '')) {
            return;
        }
        if (SEOPROSTACK_VERSION !== ($state['building'] ?? '')) {
            $state = array('schema' => self::CLAIMS_SCHEMA, 'built' => '', 'building' => SEOPROSTACK_VERSION, 'cursor' => PHP_INT_MAX);
        }
        $cursor = (int) ($state['cursor'] ?? PHP_INT_MAX);
        while (self::more_time($start, $budget)) {
            $ids = self::inventory_ids(100, $cursor);
            if (!$ids) {
                $state['built'] = SEOPROSTACK_VERSION;
                break;
            }
            foreach (self::handover_records($ids) as $id => $records) {
                if (!self::index_claims($id, $records)) {
                    return;
                }
            }
            $cursor = min($ids);
        }
        $state['cursor'] = $cursor;
        update_option(self::CLAIMS_STATE, $state, false);
    }

    /**
     * Persist a refusal without scheduling deletion or removing local sources.
     *
     * @param int $id Attachment ID.
     * @param array $record Inventory to preserve.
     * @param bool $missing Repair could not recover every original local file.
     */
    private static function collision_failed($id, $record, $missing = false) {
        $record['state'] = 'failed';
        $record['collision_refused'] = true;
        $record['error'] = $missing ? __('Collision repair renamed this attachment, but original local bytes were missing or did not match their recorded SHA-256. Re-add this picture. Shared bucket objects were left untouched.', 'seoprostack') : __('A stored path belongs to another attachment. Run wp seoprostack media-storage collisions and repair it before retrying. If the original bytes are missing, re-add the picture.', 'seoprostack');
        update_post_meta($id, self::META, $record);
        update_post_meta($id, self::STATE, 'failed');
        update_post_meta($id, self::DONE, self::upload_marker());
        delete_option(self::JOB . 'retry_' . $id);
        update_option(self::ERROR, $record['error'], false);
        delete_transient(self::COUNTS);
        self::forget($id);
    }

    /**
     * Report shared claims. Newest attachment ID is the presumed latest upload;
     * byte ownership still needs verification. Never download a shared object.
     *
     * @param array $assoc CLI switches.
     */
    private static function cli_collisions($assoc) {
        if (isset($assoc['repair']) && !self::ready()) {
            WP_CLI::error('Switch on Media in cloud storage and set its constants before repairing collisions.');
        }
        $lease = isset($assoc['repair']) ? self::cli_lease() : '';
        $start = microtime(true);
        try {
            $seen = array();
            $losers = array();
            $owners = $lease ? get_option(self::JOB . 'collision_owners', array()) : array();
            $count = 0;
            foreach (self::stored_records() as $id => $values) {
                $claims = array();
                $attached = get_post_meta($id, '_wp_attached_file', true);
                if (is_string($attached) && '' !== $attached) {
                    $claims['path:' . $attached] = true;
                }
                foreach (array(self::META, self::REMOVED) as $meta_key) {
                    $record = $values[$meta_key] ?? array();
                    foreach ($record['files'] ?? array() as $key => $info) {
                        $claims['object:' . ($record['signature'] ?? '') . ':' . $key] = true;
                        if (!empty($info['relative'])) {
                            $claims['path:' . $info['relative']] = true;
                        }
                    }
                }
                foreach ($claims as $claim => $unused) {
                    if (isset($seen[$claim])) {
                        ++$count;
                        $losers[$id] = true;
                        $owners[$seen[$claim]][$id] = true;
                        WP_CLI::log(sprintf('%s: attachments %d, %d; latest upload (presumed owner): %d', $claim, $id, $seen[$claim], $seen[$claim]));
                    } else {
                        $seen[$claim] = $id;
                    }
                }
            }
            WP_CLI::log('Shared paths/keys: ' . $count);
            if (!$lease) {
                WP_CLI::log('Read only. Use --repair to rename earlier attachments; no bucket objects will be deleted.');
                return;
            }
            // Save dependencies before any loser drops its shared claims. A later
            // scan cannot rediscover owners whose losers were already renamed.
            update_option(self::JOB . 'collision_owners', $owners, false);
            self::queue_collision_owners($start);
            foreach (array_keys($losers) as $id) {
                if (!self::more_time($start, self::BUDGET)) {
                    WP_CLI::warning('Repair paused at the time budget; repeat collisions --repair.');
                    break;
                }
                WP_CLI::log($id . ': ' . self::repair_collision($id, $start));
            }
            self::queue_collision_owners($start);
        } finally {
            if ($lease) {
                self::release($lease);
            }
        }
    }

    /**
     * Retry failed owners only once all live shared claims have gone. Keep the
     * dependency map across budget pauses, including after the final loser moves.
     *
     * @param float $start Repair batch start.
     */
    private static function queue_collision_owners($start) {
        $owners = get_option(self::JOB . 'collision_owners', array());
        foreach (array_map('intval', array_keys($owners)) as $id) {
            if (!self::more_time($start, self::BUDGET)) {
                break;
            }
            self::fresh($id);
            $record = get_post_meta($id, self::META, true);
            $removed = self::fresh_removed($id);
            $files = (is_array($record) ? ($record['files'] ?? array()) : array()) + ($removed['files'] ?? array());
            // Re-read claims rather than trusting repair's result: an incomplete
            // repair can release them, while a deferred repair leaves them live.
            if (self::conflicting_files($id, $files)) {
                continue;
            }
            if ($files && self::attachment_exists($id) && 'failed' === get_post_meta($id, self::STATE, true)) {
                if (is_array($record)) {
                    unset($record['collision_refused'], $record['error']);
                    update_post_meta($id, self::META, $record);
                }
                self::queue($id);
                WP_CLI::log($id . ': owner queued after shared claims were repaired');
            }
            unset($owners[$id]);
            update_option(self::JOB . 'collision_owners', $owners, false);
        }
        if (!$owners) {
            delete_option(self::JOB . 'collision_owners');
        }
    }

    /**
     * Copy only local bytes matching the earlier inventory. Keep shared source
     * files and objects intact. Missing bytes become failed empty inventories,
     * not pending handover records; retain evidence outside the cleanup file list.
     *
     * @param int $id Earlier attachment.
     * @param float $start Repair batch start.
     * @return string
     */
    private static function repair_collision($id, $start) {
        $root = self::root();
        self::fresh($id);
        $generation = get_post_meta($id, self::GENERATION, true);
        $attached = get_post_meta($id, '_wp_attached_file', true);
        $old = get_post_meta($id, self::META, true);
        $removed = self::fresh_removed($id);
        if (!$root || !is_string($attached) || !self::safe_relative($attached) || !is_array($old)) {
            return 'failed: invalid attachment path or inventory; unchanged';
        }
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : array();
        $nextgen = get_post_meta($id, '_seoprostack_nextgen', true);
        $files = ($old['files'] ?? array()) + ($removed['files'] ?? array());
        if (!is_array($files)) {
            return 'failed: invalid inventory files; unchanged';
        }
        $folder = '.' === dirname($attached) ? '' : dirname($attached) . '/';
        $dir = $root['dir'] . '/' . $folder;
        if (!wp_mkdir_p($dir) || !self::within(realpath($dir), realpath($root['dir']))) {
            return 'failed: uploads directory unavailable; unchanged';
        }
        $names = array(wp_basename($attached));
        if (!empty($meta['original_image'])) {
            $names[] = wp_basename($meta['original_image']);
        }
        foreach ($meta['sizes'] ?? array() as $size) {
            $names[] = wp_basename($size['file']);
        }
        $renamed = array();
        foreach (array_unique($names) as $name) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $renamed[$name] = wp_unique_filename($dir, $stem . '-collision-' . $id . '-' . wp_generate_password(8, false, false) . '.' . $extension);
        }
        $complete = false;
        foreach ($files as $info) {
            if (($info['relative'] ?? '') === $attached) {
                $complete = true;
            }
        }
        foreach ($files as $info) {
            $relative = $info['relative'] ?? '';
            $name = wp_basename($relative);
            $new = $renamed[$name] ?? '';
            foreach (array('webp', 'avif') as $format) {
                if (isset($renamed[substr($name, 0, -5)]) && substr($name, -5) === '.' . $format) {
                    $new = $renamed[substr($name, 0, -5)] . '.' . $format;
                }
            }
            $path = self::safe_relative($relative) ? $root['dir'] . '/' . $relative : '';
            if ('' === $new || !$path || !is_file($path) || !self::within(realpath($path), realpath($root['dir'])) || empty($info['digest']) || !isset($info['size']) || wp_filesize($path) !== (int) $info['size'] || !self::digest_matches($path, $info['digest']) || file_exists($dir . $new) || !copy($path, $dir . $new)) {
                $complete = false;
            }
        }
        $record = array('signature' => self::config()['signature'], 'files' => array(), 'state' => 'failed', 'collision_source' => array('inventory' => $old, 'removed' => $removed));
        self::fresh($id);
        if (!self::more_time($start, self::BUDGET) || !self::attachment_exists($id) || get_post_meta($id, self::GENERATION, true) !== $generation || get_post_meta($id, '_wp_attached_file', true) !== $attached || self::fresh_removed($id) !== $removed || !update_post_meta($id, self::META, $record, $old)) {
            return 'deferred: attachment changed or repair budget expired; repeat collisions --repair';
        }
        if ($removed) {
            delete_post_meta($id, self::REMOVED, $removed);
        }
        update_attached_file($id, $dir . $renamed[wp_basename($attached)]);
        $meta['file'] = $folder . $renamed[wp_basename($attached)];
        if (!empty($meta['original_image'])) {
            $meta['original_image'] = $renamed[wp_basename($meta['original_image'])];
        }
        foreach ($meta['sizes'] ?? array() as $size => $info) {
            $meta['sizes'][$size]['file'] = $renamed[wp_basename($info['file'])];
        }
        if (is_array($nextgen) && isset($nextgen['files'])) {
            $new_files = array();
            foreach ($nextgen['files'] as $name => $formats) {
                if (isset($renamed[$name])) {
                    $new_files[$renamed[$name]] = $formats;
                }
            }
            $nextgen['files'] = $new_files;
            update_post_meta($id, '_seoprostack_nextgen', $nextgen);
        }
        wp_update_attachment_metadata($id, $meta);
        delete_post_meta($id, self::LOCAL);
        if (!$complete) {
            self::collision_failed($id, $record, true);
            return 'failed: renamed; original local bytes missing or mismatched; re-add this picture. Shared objects untouched';
        }
        self::queue($id);
        return self::upload_attachment($id, $start) ? 'repaired and uploaded under unique names; shared objects untouched' : 'renamed; upload failed, local copies kept';
    }

    /**
     * A durable old inventory, retained even after its attachment is relabelled.
     *
     * @param int $id Attachment ID.
     * @param array $record Old inventory.
     */
    private static function archive_inventory($id, $record) {
        if (isset($record['signature']) && !empty($record['files'])) {
            $saved = get_option(self::DESTINATIONS, array());
            $record['url'] = isset($record['url']) ? $record['url'] : (isset($saved['previous']['url']) ? $saved['previous']['url'] : '');
            add_option(self::JOB . 'archive_' . $record['signature'] . '_' . (int) $id, $record, '', false);
        }
    }

    /**
     * Validate a path below uploads without trusting an old prefix.
     *
     * @param string $relative Recorded path.
     * @return bool
     */
    private static function safe_relative($relative) {
        return '' !== $relative && '/' !== $relative[0] && false === strpos($relative, '..') && false === strpos($relative, '\\') && !preg_match('/[\x00-\x1f:]/', $relative);
    }

    /**
     * Fetch old public bytes without credentials, redirects or unbounded bodies.
     *
     * @param string $key Old object key.
     * @param array $info Recorded file.
     * @param array $source Public source description.
     * @return string|WP_Error
     */
    private static function previous_bytes($key, $info, $source) {
        if (empty($source['url']) || empty($info['digest']) || !isset($info['size']) || (int) $info['size'] > self::size_cap()) {
            return new WP_Error('storage_restore_record', __('The previous public address or a bounded file record is missing.', 'seoprostack'));
        }
        $url = rtrim($source['url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- explicit CLI migration or restoration, never rendering.
        $response = wp_safe_remote_get($url, array('timeout' => 20, 'redirection' => 0, 'limit_response_size' => (int) $info['size'] + 1));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return new WP_Error('storage_restore_missing', __('A picture could not be read from the previous public address.', 'seoprostack'));
        }
        $body = wp_remote_retrieve_body($response);
        return strlen($body) === (int) $info['size'] && hash_equals($info['digest'], hash('sha256', $body)) ? $body : new WP_Error('storage_restore_mismatch', __('A previous picture did not match its recorded size and SHA-256.', 'seoprostack'));
    }

    /**
     * Current destination verification, optionally including the byte digest.
     *
     * @param string $key Destination key.
     * @param array $info Recorded file.
     * @param bool $hash Read and hash the object.
     * @return string ok, missing, mismatched or failed.
     */
    private static function verify_object($key, $info, $hash) {
        $client = self::client();
        $result = self::verify_head($client->head($key), $info);
        if ('ok' !== $result) {
            return $result;
        }
        if ($hash && is_wp_error(self::download($key, $info, $client))) {
            return 'mismatched';
        }
        return 'ok';
    }

    /**
     * Distinguish absent objects from transport/permission failures.
     *
     * @param array|WP_Error $head HEAD result.
     * @param array $info Recorded file.
     * @return string
     */
    private static function verify_head($head, $info) {
        if (is_wp_error($head)) {
            $data = $head->get_error_data();
            return is_array($data) && isset($data['status']) && 404 === $data['status'] ? 'missing' : 'failed';
        }
        if (200 !== $head['status'] || !isset($info['size']) || $head['size'] !== (int) $info['size']) {
            return 'mismatched';
        }
        return 'ok';
    }

    /**
     * Verify or copy every object, then publish a whole attachment's new inventory.
     * The lease excludes batch writers; generation and compare-and-swap exclude edits.
     *
     * @param int $id Attachment ID.
     * @param bool $migrate Copy missing objects from the previous public address.
     * @param bool $dry No writes or uploads.
     * @param bool $hash Verify SHA-256 too.
     * @param float $start Batch start.
     * @return string Result counter name.
     */
    private static function handover_attachment($id, $migrate, $dry, $hash, $start) {
        self::fresh($id);
        $old = get_post_meta($id, self::META, true);
        $removed = self::fresh_removed($id);
        if (self::empty_inventory($old, $removed)) {
            return 'skipped';
        }
        if (!is_array($old) || empty($old['files'])) {
            return 'failed';
        }
        if (self::adopted_record($old, $removed)) {
            return 'skipped';
        }
        if (self::conflicting_files($id, $old['files'] + ($removed['files'] ?? array()))) {
            if (!$dry) {
                self::collision_failed($id, $old);
            }
            return 'failed';
        }
        $generation = get_post_meta($id, self::GENERATION, true);
        $source = self::handover_source($old, $removed);
        if (!$source) {
            return 'failed';
        }
        $marker = get_post_meta($id, self::DONE, true);
        if (self::upload_marker() === $marker) {
            // An edit may invalidate DONE while a queue visit is preserving it.
            $marker = isset($old['handover_generation']) && $old['handover_generation'] === $generation ? ($old['handover_done'] ?? '') : '';
        }
        $suffix = substr(self::upload_marker(), strlen(self::config()['signature']));
        $complete = $source['signature'] . $suffix === $marker;
        $options = array('migrate' => $migrate, 'dry' => $dry, 'hash' => $hash || $migrate, 'start' => $start);
        $files = self::handover_files($old['files'] + ($removed['files'] ?? array()), $removed, $source, $options);
        if (is_string($files)) {
            return $files;
        }
        if ($dry) {
            return 'adopted';
        }
        $record = $old;
        $record['files'] = $files;
        return self::publish_handover($id, $old, $record, $generation, $source['signature'], $complete);
    }

    /**
     * Whether an inventory has no current or removed files to hand over.
     *
     * @param mixed $record Inventory value.
     * @param array|null $removed Removed inventory.
     * @return bool
     */
    private static function empty_inventory($record, $removed) {
        return is_array($record) && empty($record['files']) && !$removed;
    }

    /**
     * Whether both inventories have completed handover.
     *
     * @param array $record Inventory.
     * @param array|null $removed Removed inventory.
     * @return bool
     */
    private static function adopted_record($record, $removed) {
        $signature = self::config()['signature'];
        return 'uploaded' === $record['state'] && $record['signature'] === $signature && (!$removed || $removed['signature'] === $signature);
    }

    /**
     * Allow only the recorded previous destination, never a third connection.
     *
     * @param array $old Inventory.
     * @param array|null $removed Removed inventory.
     * @return array|null
     */
    private static function handover_source($old, $removed) {
        $config = self::config();
        $saved = get_option(self::DESTINATIONS, array());
        $source_signature = $removed['signature'] ?? $old['signature'];
        if (empty($saved['current']) || $saved['current']['signature'] !== $config['signature'] || empty($saved['previous']) || $source_signature !== $saved['previous']['signature']) {
            return null;
        }
        return !empty($removed['url']) ? $removed : $saved['previous'];
    }

    /**
     * Build a verified inventory without publishing any partial result.
     *
     * @param array $files Union of inventory and removed files.
     * @param array|null $removed Removed inventory.
     * @param array $source Source description.
     * @param array $options Batch switches and start.
     * @return array|string New files or failure counter.
     */
    private static function handover_files($files, $removed, $source, $options) {
        $verified = array();
        foreach ($files as $key => $info) {
            if (!self::more_time($options['start'], self::BUDGET)) {
                return 'deferred';
            }
            if (!self::handover_file_valid($info, isset($removed['files'][$key]))) {
                return 'mismatched';
            }
            $result = self::handover_object((string) $key, $info, $source, $options);
            if ('ok' !== $result) {
                return $result;
            }
            $info['confirmed'] = true;
            $verified[self::config()['prefix'] . $info['relative']] = $info;
        }
        return $verified;
    }

    /**
     * Removed entries use their own schema (no confirmed field).
     *
     * @param array $info Recorded file.
     * @param bool $removed The file was recorded before local deletion.
     * @return bool
     */
    private static function handover_file_valid($info, $removed) {
        return ($removed || !empty($info['confirmed'])) && !empty($info['digest']) && isset($info['size'], $info['relative']) && self::safe_relative($info['relative']);
    }

    /**
     * Verify a new object, or explicitly copy a missing/mismatched one.
     *
     * @param string $key Old key.
     * @param array $info File record.
     * @param array $source Old destination.
     * @param array $options Batch switches.
     * @return string Result.
     */
    private static function handover_object($key, $info, $source, $options) {
        $target = self::config()['prefix'] . $info['relative'];
        $result = self::verify_object($target, $info, $options['hash']);
        if ($options['migrate'] && !$options['dry'] && in_array($result, array('missing', 'mismatched'), true)) {
            return self::copy_previous_object($key, $target, $info, $source);
        }
        return $result;
    }

    /**
     * Copy only bounded, hash-checked bytes, without restoring a local file.
     *
     * @param string $key Old key.
     * @param string $target New key.
     * @param array $info File record.
     * @param array $source Old destination.
     * @return string
     */
    private static function copy_previous_object($key, $target, $info, $source) {
        $body = self::previous_bytes($key, $info, $source);
        if (is_wp_error($body)) {
            return 'missing';
        }
        $type = wp_check_filetype($info['relative']);
        $put = self::client()->put($target, $body, $type['type'] ?: 'application/octet-stream', 'public, max-age=31536000, immutable');
        return is_wp_error($put) ? 'failed' : self::verify_object($target, $info, true);
    }

    /**
     * Publish only after whole-record verification and an edit/deletion recheck.
     *
     * @param int $id Attachment ID.
     * @param array $old Snapshot.
     * @param array $record Verified replacement.
     * @param mixed $generation Snapshot generation.
     * @param string $source_signature Old signature.
     * @param bool $complete Source inventory already includes the current file variants.
     * @return string
     */
    private static function publish_handover($id, $old, $record, $generation, $source_signature, $complete) {
        $config = self::config();
        self::fresh($id);
        if (!self::attachment_exists($id) || get_post_meta($id, self::GENERATION, true) !== $generation || get_post_meta($id, self::META, true) !== $old) {
            return 'deferred';
        }
        self::archive_inventory($id, $old);
        $record['signature'] = $config['signature'];
        $record['url'] = $config['url'];
        $record['state'] = 'uploaded';
        unset($record['handover_error'], $record['handover_done'], $record['handover_generation']);
        if ($record !== $old && !update_post_meta($id, self::META, $record, $old)) {
            return 'deferred';
        }
        if (!self::relabel_removed($id, $record, $source_signature)) {
            return 'deferred';
        }
        update_post_meta($id, self::DONE, $complete ? self::upload_marker() : $config['signature']);
        update_post_meta($id, self::STATE, 'uploaded');
        update_post_meta($id, self::LOCAL, self::removed($id) ? $config['signature'] : 'kept:' . $config['signature']);
        foreach (array_map('strval', array_keys($record['files'])) as $key) {
            update_option(self::URL_INDEX . hash('sha256', $config['signature'] . $key), $id, false);
            delete_option(self::delete_job_name($config['signature'], $key));
        }
        delete_option(self::JOB . 'retry_' . $id);
        delete_post_meta($id, self::STUCK);
        delete_transient(self::COUNTS);
        self::$maps = array();
        return self::handover_published($id, $generation);
    }

    /**
     * Relabel only removed entries covered by the verified inventory.
     *
     * @param int $id Attachment ID.
     * @param array $record Verified record.
     * @param string $source_signature Old signature.
     * @return bool
     */
    private static function relabel_removed($id, $record, $source_signature) {
        $gone = self::fresh_removed($id);
        if (!$gone || $gone['signature'] !== $source_signature) {
            return true;
        }
        $config = self::config();
        $new = array('signature' => $config['signature'], 'url' => $config['url'], 'files' => array());
        foreach ($gone['files'] as $info) {
            $target = $config['prefix'] . $info['relative'];
            if (!isset($record['files'][$target]) || $record['files'][$target]['digest'] !== $info['digest'] || $record['files'][$target]['size'] !== $info['size']) {
                return false;
            }
            $new['files'][$target] = $info;
        }
        update_post_meta($id, self::REMOVED, $new, $gone);
        return true;
    }

    /**
     * Do not overwrite an edit's DONE invalidation after the initial CAS check.
     *
     * @param int $id Attachment ID.
     * @param mixed $generation Snapshot generation.
     * @return string
     */
    private static function handover_published($id, $generation) {
        self::fresh($id);
        if (get_post_meta($id, self::GENERATION, true) !== $generation) {
            delete_post_meta($id, self::DONE);
            self::schedule(10);
            return 'deferred';
        }
        $remaining = self::fresh_removed($id);
        if ($remaining && $remaining['signature'] !== self::config()['signature']) {
            return 'deferred';
        }
        return 'adopted';
    }

    /**
     * A bounded explicit handover batch, using the existing lease and time budget.
     *
     * @param array $assoc CLI switches.
     * @param bool $migrate Copy before verification.
     */
    private static function cli_handover($assoc, $migrate) {
        $limit = isset($assoc['limit']) ? max(1, min(1000, (int) $assoc['limit'])) : 10;
        $one = isset($assoc['attachment']) ? self::cli_restore_target($assoc) : 0;
        $counts = array('adopted' => 0, 'missing' => 0, 'mismatched' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0);
        $dry = isset($assoc['dry-run']);
        $start = microtime(true);
        $options = array('migrate' => $migrate, 'dry' => $dry, 'hash' => isset($assoc['verify-hash']), 'start' => $start);
        $before = PHP_INT_MAX;
        // Scalar cursors are ignored by the job runner and removed by uninstall.
        $cursor_name = self::JOB . ($migrate ? 'migratecursor' : 'adoptcursor');
        $signature = self::config()['signature'];
        $lease = self::cli_lease();
        try {
            $saved = get_option($cursor_name);
            $cursor = is_string($saved) ? explode(':', $saved) : array();
            if (!$one && !isset($assoc['from-start']) && ($cursor[0] ?? '') === $signature) {
                $before = max(1, (int) ($cursor[1] ?? PHP_INT_MAX));
            }
            do {
                $ids = $one ? array($one) : self::inventory_ids(100, $before);
                if (!self::handover_page($ids, $options, $counts, $limit, $before)) {
                    break;
                }
            } while (!$one && $ids);
            if (!$one) {
                // Revisit deferred/failed records and new uploads on the next pass.
                if (!$ids || !self::inventory_ids(1, $before)) {
                    $before = PHP_INT_MAX;
                }
                if (!$dry) {
                    if (PHP_INT_MAX === $before) {
                        delete_option($cursor_name);
                    } else {
                        update_option($cursor_name, $signature . ':' . $before, false);
                    }
                }
            }
        } finally {
            self::release($lease);
        }
        self::handover_summary($counts, $dry);
        if (!$one) {
            WP_CLI::log('next: ' . (PHP_INT_MAX === $before ? 'start' : 'before ' . $before));
        }
    }

    /**
     * Process one bounded page under the caller's lease.
     *
     * @param int[] $ids Attachment IDs.
     * @param array $options Batch options.
     * @param array $counts Counters.
     * @param int $limit Remaining attempts.
     * @param int $before Paging cursor.
     * @return bool Continue to another page.
     */
    private static function handover_page($ids, $options, &$counts, &$limit, &$before) {
        foreach ($ids as $id) {
            $before = $id;
            $result = self::handover_attachment($id, $options['migrate'], $options['dry'], $options['hash'], $options['start']);
            ++$counts[$result];
            self::handover_error($id, $result, $options['dry']);
            if ('skipped' !== $result) {
                --$limit;
                WP_CLI::log($id . ': ' . $result);
            }
            if ($limit <= 0 || !self::more_time($options['start'], self::BUDGET)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Record a last-known failure without overwriting a concurrent record update.
     *
     * @param int $id Attachment ID.
     * @param string $result Counter name.
     * @param bool $dry No writes.
     */
    private static function handover_error($id, $result, $dry) {
        if ($dry || !in_array($result, array('missing', 'mismatched', 'failed'), true)) {
            return;
        }
        $old = get_post_meta($id, self::META, true);
        if (is_array($old)) {
            $record = $old;
            $record['handover_error'] = $result;
            update_post_meta($id, self::META, $record, $old);
            delete_transient(self::COUNTS);
        }
    }

    /**
     * Print batch counters without implying dry-run changes were saved.
     *
     * @param array $counts Counters.
     * @param bool $dry No writes.
     */
    private static function handover_summary($counts, $dry) {
        WP_CLI::log($dry ? 'Dry run: no objects or records changed.' : 'Handover batch finished; repeat to continue.');
        foreach ($counts as $state => $count) {
            WP_CLI::log($state . ': ' . $count);
        }
    }

    /**
     * Local status only: missing/mismatched are last explicit verification results.
     * No network request is made while rendering the settings card.
     *
     * @return array<string,int>
     */
    private static function handover_counts() {
        $counts = array('adopted' => 0, 'pending' => 0, 'missing' => 0, 'mismatched' => 0);
        $before = PHP_INT_MAX;
        do {
            $ids = self::inventory_ids(100, $before);
            $records = self::handover_records($ids);
            foreach ($ids as $id) {
                $before = $id;
                self::count_handover_record($records[$id] ?? array(), $counts);
            }
        } while ($ids);
        return $counts;
    }

    /**
     * Read only a bounded page of inventory values, without populating or evicting
     * the whole library's post-meta cache. Serialized files need PHP comparisons.
     *
     * @param int[] $ids Attachment IDs.
     * @return array<int,array>
     */
    private static function handover_records($ids) {
        global $wpdb;
        $records = array();
        if (!$ids) {
            return $records;
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $args = array_merge($ids, array(self::META, self::REMOVED));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded IDs and meta keys; $in contains one %d per ID, so the replacement count is dynamic.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN (%s, %s)", $args));
        foreach ($rows as $row) {
            $records[(int) $row->post_id][$row->meta_key] = maybe_unserialize($row->meta_value);
        }
        return $records;
    }

    /**
     * Count an orphan removed record as pending, never as safe to forget.
     *
     * @param array $values Stored inventories.
     * @param array $counts Counters.
     */
    private static function count_handover_record($values, &$counts) {
        $record = $values[self::META] ?? null;
        $removed = $values[self::REMOVED] ?? null;
        $removed = is_array($removed) && !empty($removed['files']) ? $removed : null;
        if (!is_array($record)) {
            ++$counts['pending'];
            return;
        }
        if (empty($record['files']) && !$removed) {
            return;
        }
        if (self::adopted_record($record, $removed)) {
            ++$counts['adopted'];
        } else {
            ++$counts['pending'];
        }
        if (isset($record['handover_error']) && in_array($record['handover_error'], array('missing', 'mismatched'), true)) {
            ++$counts[$record['handover_error']];
        }
    }

    /**
     * Public status labels deliberately omit URLs, paths, signing region and keys.
     *
     * @return string[]
     */
    private static function destination_labels() {
        $saved = get_option(self::DESTINATIONS, array());
        $labels = array();
        foreach (array('current', 'previous') as $name) {
            $item = isset($saved[$name]) ? $saved[$name] : array();
            $labels[] = $name . ': ' . ($item ? (wp_parse_url($item['endpoint'], PHP_URL_HOST) ?: 'unknown host') . ' / ' . ($item['bucket'] ?: 'unknown bucket') . ' / ' . $item['prefix'] : 'none');
        }
        if (self::ready() || self::restoring()) {
            $counts = self::counts();
            $needed = $counts['pending'] + $counts['missing'] + $counts['mismatched'];
            $labels[] = $needed ? 'Previous destination is still needed.' : 'Previous destination is no longer needed by recorded pictures; verify pages before cleanup.';
        }
        return $labels;
    }

    /**
     * Explicit cleanup only, after rechecking all current records byte for byte.
     * Old credentials are separate constants, never saved or sent to the new host.
     *
     * @param array $assoc CLI switches.
     */
    private static function cli_forget_previous($assoc) {
        $lease = self::cli_lease();
        try {
            $result = self::forget_previous($assoc);
        } finally {
            self::release($lease);
        }
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
    }

    /**
     * Cleanup implementation called under the batch lease.
     *
     * @param array $assoc CLI switches.
     * @return WP_Error|null Refusal, or no error.
     */
    private static function forget_previous($assoc) {
        $start = microtime(true);
        $saved = get_option(self::DESTINATIONS, array());
        if (empty($saved['previous'])) {
            WP_CLI::success('No previous destination.');
            return null;
        }
        if ($saved['current']['signature'] !== self::config()['signature']) {
            return new WP_Error('storage_cleanup', 'A third destination cannot replace an unfinished handover. Restore the last connection first.');
        }
        $verified = self::verify_cleanup($start);
        if (is_wp_error($verified)) {
            return $verified;
        }
        if (!$verified) {
            WP_CLI::log('Verification paused at the time budget; repeat forget-previous with the same switches. No old objects were deleted.');
            return null;
        }
        return self::retire_previous($saved, $assoc, $start);
    }

    /**
     * Retire the previous destination only after current inventory verification.
     *
     * @param array $saved Destination descriptions.
     * @param array $assoc CLI switches.
     * @param float $start Lease start.
     * @return WP_Error|null Refusal, or no error.
     */
    private static function retire_previous($saved, $assoc, $start) {
        $client = isset($assoc['delete-objects']) ? self::cleanup_client($saved['previous']) : null;
        if (is_wp_error($client)) {
            return $client;
        }
        $result = self::cleanup_archives($saved['previous']['signature'], $client, $start);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!$result) {
            WP_CLI::log('Cleanup paused at the time budget; repeat forget-previous with the same switches.');
            return null;
        }
        $saved['retired'][$saved['previous']['signature']] = $saved['previous'];
        $saved['previous'] = array();
        update_option(self::DESTINATIONS, $saved, false);
        WP_CLI::success('Previous destination forgotten. Bucket and access keys are never deleted by this command.');
        return null;
    }

    /**
     * Verify the entire current inventory before any old object may be touched.
     *
     * @param float $start Lease start.
     * @return bool|WP_Error False pauses verification without deleting old objects.
     */
    private static function verify_cleanup($start) {
        $before = PHP_INT_MAX;
        do {
            $ids = self::inventory_ids(100, $before);
            foreach ($ids as $id) {
                $before = $id;
                $result = self::verify_cleanup_record($id, $start);
                if (true !== $result) {
                    return $result;
                }
            }
        } while ($ids);
        return true;
    }

    /**
     * A missing, partial or still-previous record blocks all cleanup.
     *
     * @param int $id Attachment ID.
     * @param float $start Lease start.
     * @return bool|WP_Error False pauses at the last verified file.
     */
    private static function verify_cleanup_record($id, $start) {
        self::fresh($id);
        $record = get_post_meta($id, self::META, true);
        $removed = self::fresh_removed($id);
        if (self::empty_inventory($record, $removed)) {
            return true;
        }
        if (!is_array($record) || !self::adopted_record($record, $removed)) {
            return new WP_Error('storage_cleanup', 'Previous destination is still needed: adopt or migrate every record first.');
        }
        $saved = get_option(self::DESTINATIONS, array());
        // One non-autoloaded checkpoint per attachment, reused across handovers.
        // Keep completed checkpoints while deletion pauses, or retries rehash them.
        $name = self::JOB . 'verify_' . $id;
        $generation = get_post_meta($id, self::GENERATION, true);
        $fingerprint = hash('sha256', (string) wp_json_encode(array($saved['current']['signature'] ?? '', $saved['previous']['signature'] ?? '', $generation, $record, $removed)));
        $checkpoint = self::cleanup_checkpoint($id, $fingerprint);
        $result = self::verify_cleanup_files($id, $record['files'], $checkpoint, $start);
        if (true !== $result) {
            return $result;
        }
        self::fresh($id);
        if (get_post_meta($id, self::GENERATION, true) !== $generation || get_post_meta($id, self::META, true) !== $record || self::fresh_removed($id) !== $removed) {
            delete_option($name);
            return false;
        }
        self::clear_handover_error($id, $record);
        return true;
    }

    /**
     * Reuse verification progress only while its inventory fingerprint matches.
     *
     * @param int $id Attachment ID.
     * @param string $fingerprint Current inventory fingerprint.
     * @return array Inventory fingerprint and verified cursor.
     */
    private static function cleanup_checkpoint($id, $fingerprint) {
        $progress = get_option(self::JOB . 'verify_' . $id, array());
        $cursor = isset($progress['fingerprint'], $progress['cursor']) && $progress['fingerprint'] === $fingerprint ? (int) $progress['cursor'] : 0;
        return array('fingerprint' => $fingerprint, 'cursor' => $cursor);
    }

    /**
     * Resume byte verification at the checkpoint for an unchanged inventory.
     *
     * @param int $id Attachment ID.
     * @param array $files Current inventory files.
     * @param array $checkpoint Inventory fingerprint and verified cursor.
     * @param float $start Lease start.
     * @return bool|WP_Error False pauses at the last verified file.
     */
    private static function verify_cleanup_files($id, $files, $checkpoint, $start) {
        $name = self::JOB . 'verify_' . $id;
        $index = 0;
        foreach ($files as $key => $info) {
            if (++$index <= $checkpoint['cursor']) {
                continue;
            }
            if (!self::more_time($start, 120)) {
                return false;
            }
            $result = self::verify_object((string) $key, $info, true);
            if ('ok' !== $result) {
                delete_option($name);
                self::handover_error($id, $result, false);
                return new WP_Error('storage_cleanup', 'A current object is missing or mismatched; previous destination was kept.');
            }
            update_option($name, array('fingerprint' => $checkpoint['fingerprint'], 'cursor' => $index), false);
        }
        return true;
    }

    /**
     * Clear an old diagnostic only after a complete successful verification.
     *
     * @param int $id Attachment ID.
     * @param array $old Verified inventory snapshot.
     */
    private static function clear_handover_error($id, $old) {
        if (isset($old['handover_error'])) {
            $record = $old;
            unset($record['handover_error']);
            update_post_meta($id, self::META, $record, $old);
            delete_transient(self::COUNTS);
        }
    }

    /**
     * Construct an explicitly authorised old-destination client, never reuse new keys.
     * Equal bucket names might be aliases and require provider-side cleanup.
     *
     * @param array $old Previous destination.
     * @return SEOProStack_S3_Client|WP_Error
     */
    private static function cleanup_client($old) {
        if ($old['bucket'] === self::config()['bucket']) {
            return new WP_Error('storage_cleanup', 'Bucket names overlap; use provider cleanup to avoid deleting current objects through an endpoint alias.');
        }
        if (empty($old['endpoint']) || empty($old['bucket']) || !defined('SEOPROSTACK_MEDIA_STORAGE_PREVIOUS_KEY_ID') || !defined('SEOPROSTACK_MEDIA_STORAGE_PREVIOUS_SECRET')) {
            return new WP_Error('storage_cleanup', 'Set the previous key constants in wp-config.php, or use provider cleanup. Unknown old endpoint/bucket descriptions cannot delete objects.');
        }
        require_once dirname(__DIR__) . '/class-seoprostack-s3-client.php';
        return new SEOProStack_S3_Client($old['endpoint'], $old['region'] ?? 'auto', $old['bucket'], constant('SEOPROSTACK_MEDIA_STORAGE_PREVIOUS_KEY_ID'), constant('SEOPROSTACK_MEDIA_STORAGE_PREVIOUS_SECRET'));
    }

    /**
     * Include legacy deferred deletions, but only under this explicit command.
     *
     * @param string $signature Previous destination signature.
     * @param SEOProStack_S3_Client|null $client Null means forget without deletion.
     * @param float $start Lease start.
     * @return bool|WP_Error False pauses with the previous destination still retained.
     */
    private static function cleanup_archives($signature, $client, $start) {
        foreach (array('archive_', 'delete_') as $kind) {
            $after = '';
            do {
                $names = self::jobs(100, $after, $kind . $signature . '_');
                foreach ($names as $name) {
                    $after = $name;
                    $result = self::cleanup_option($name, $client, $start);
                    if (true !== $result) {
                        return $result;
                    }
                }
            } while ($names);
        }
        return true;
    }

    /**
     * Persist each successful deletion before continuing, so retries are idempotent.
     *
     * @param string $name Archived option name.
     * @param SEOProStack_S3_Client|null $client Null means forget without deletion.
     * @param float $start Lease start.
     * @return bool|WP_Error
     */
    private static function cleanup_option($name, $client, $start) {
        $record = get_option($name);
        if (!is_array($record)) {
            return true;
        }
        if (!isset($record['files']) && isset($record['key'])) {
            $record['files'] = array($record['key'] => array());
        }
        foreach (array_keys($record['files']) as $key) {
            if (!self::more_time($start, 180)) {
                return false;
            }
            if ($client && is_wp_error($client->delete((string) $key))) {
                return new WP_Error('storage_cleanup', 'Previous object deletion failed; inventory kept for retry.');
            }
            unset($record['files'][$key]);
            update_option($name, $record, false);
        }
        delete_option($name);
        return true;
    }

    /** @return bool */
    private static function ready() {
        return self::enabled() && !self::config()['missing'];
    }

    /** @return string Completion marker for inventories including AVIF copies. */
    private static function upload_marker() {
        $config = self::config();
        return $config['signature'] . ':avif' . ('' !== $config['originals_bucket'] ? ':originals:' . hash('sha256', $config['originals_bucket']) : '');
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
        foreach (array('', 'all', 'removed', ':original', 'all:original', 'removed:original') as $mode) {
            unset(self::$maps[get_current_blog_id() . ':' . (int) $id . ':' . $mode]);
        }
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
        add_filter('pre_wp_unique_filename_file_list', array(__CLASS__, 'unique_filename_files'), 10, 3);
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
        if (self::ready() && (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
            // Wake idle legacy installations once without changing their object
            // ownership or invalidating the records still serving their pages.
            $destinations = get_option(self::DESTINATIONS, array());
            if (($destinations['inventory_version'] ?? '') !== self::upload_marker()) {
                self::schedule();
                $destinations['inventory_version'] = self::upload_marker();
                update_option(self::DESTINATIONS, $destinations, false);
                delete_transient(self::COUNTS);
            }
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
        // These hooks run only in Rank Math; saved content keeps local URLs.
        add_filter('rank_math/opengraph/facebook/image', array(__CLASS__, 'seo_image'), 20);
        add_filter('rank_math/opengraph/twitter/image', array(__CLASS__, 'seo_image'), 20);
        add_filter('rank_math/json_ld', array(__CLASS__, 'seo_schema'), 20);
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
        if (self::$serving[$blog]['ready'] && !self::editing()) {
            return 'all';
        }
        // Saved content still gets local addresses (save_local()).
        return self::$serving[$blog]['removed'] ? 'removed' : '';
    }

    /**
     * Whether this request is wp-admin or an edit context, whose addresses
     * can be saved back into content.
     *
     * @return bool
     */
    private static function editing() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- context only, no mutation.
        $edit = defined('REST_REQUEST') && REST_REQUEST && isset($_REQUEST['context']) && 'edit' === $_REQUEST['context'];
        return $edit || is_admin() || in_array(true, self::$rest, true);
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
        if (self::removed($id) && self::processing_files()) {
            self::restore_attachment($id);
        }
        return $path;
    }

    /**
     * Whether this request makes or changes image files: wp media commands,
     * cron, REST requests that make sizes, and core's sub-size Ajax.
     *
     * @return bool
     */
    private static function processing_files() {
        return self::cli_media() || wp_doing_cron() || in_array(true, self::$rest_sizes, true) || self::subsize_ajax();
    }

    /**
     * Whether this is core's Ajax step that makes an upload's sizes.
     *
     * @return bool
     */
    private static function subsize_ajax() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared with a fixed action only; core checks the nonce.
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        return wp_doing_ajax() && 'media-create-image-subsizes' === $action;
    }

    /**
     * Whether this is a wp media command (regenerate, fix-orientation),
     * which reads original files.
     *
     * @return bool
     */
    private static function cli_media() {
        if (!defined('WP_CLI') || !WP_CLI) {
            return false;
        }
        // The command is the first argument after global options (--url=…) and aliases (@prod).
        $argv = isset($GLOBALS['argv']) ? (array) $GLOBALS['argv'] : array();
        $words = preg_grep('/^[^-@]/', array_slice($argv, 1));
        return is_array($words) && 'media' === reset($words);
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
        // Never expose a new generation with the previous completion marker.
        delete_post_meta($id, self::DONE);
        update_post_meta($id, self::GENERATION, wp_generate_uuid4());
        // Also invalidate a worker's marker written during the generation change.
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
        } elseif (self::META === $key || self::REMOVED === $key) {
            // Claims are indexed before the saver checks them for collisions.
            self::index_claims($id, array($value));
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
        if (!self::ready() && !self::restoring() && !self::original_jobs_due()) {
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
        update_option(self::JOB . 'originalcursor', '', false);
        update_option(self::JOB . 'originalkeep', wp_generate_uuid4(), false);
        if (self::REMOVE === $key && $value && !self::config()['missing']) {
            // Switched on again: files kept here earlier may go now.
            delete_metadata('post', 0, self::LOCAL, 'kept:' . self::config()['signature'], true);
            delete_option(self::LOCAL_WAIT);
            delete_transient(self::COUNTS);
        }
        if (self::ready() || self::restoring() || self::jobs(1, '', 'original_')) {
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
            self::DONE, self::upload_marker(), self::STATE, self::LOCAL, $signature, 'kept:' . $signature, max(1, (int) $limit)
        )));
    }

    /**
     * Attachments with removed files, newest first.
     *
     * @phpstan-impure Reads the queue, which other requests change.
     * @param int $limit Most IDs.
     * @param int $before Only IDs below this one (paging).
     * @param bool $pending Leave out attachments whose background restore
     *                      failed on this connection (STUCK).
     * @return int[]
     */
    private static function restorable($limit, $before = PHP_INT_MAX, $pending = false) {
        global $wpdb;
        // STUCK holds a signature, never an empty string: '' leaves nothing out.
        $stuck = $pending ? self::config()['signature'] : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded lookup through the meta_key index.
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT r.post_id FROM {$wpdb->postmeta} r LEFT JOIN {$wpdb->postmeta} s ON s.post_id = r.post_id AND s.meta_key = %s AND s.meta_value = %s WHERE r.meta_key = %s AND r.post_id < %d AND s.meta_id IS NULL ORDER BY r.post_id DESC LIMIT %d",
            self::STUCK, $stuck, self::REMOVED, (int) $before, max(1, (int) $limit)
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
        $limit = max(1, (int) $limit);
        $before = PHP_INT_MAX;
        $waiting = array();
        $signature = self::config()['signature'];
        do {
            $remaining = $limit - count($waiting);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded, ID-paged queue lookup, matching the nextgen queue.
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND m.meta_id IS NULL AND p.ID < %d ORDER BY p.ID DESC LIMIT %d",
                self::DONE, self::upload_marker(), 'image/%', $before, $remaining
            )));
            foreach ($ids as $id) {
                $before = $id;
                $job = get_option(self::JOB . 'retry_original_' . $id);
                if (is_array($job) && ($job['signature'] ?? '') === $signature && ($job['next'] ?? 0) > time() && self::needs_original_only($id, (array) get_post_meta($id, self::META, true))) {
                    continue;
                }
                $waiting[] = $id;
            }
            // Page past cooling originals so they cannot starve older uploads.
        } while (count($waiting) < $limit && count($ids) === $remaining);
        return $waiting;
    }

    /**
     * Non-autoloaded individual rows prevent concurrent deletions losing keys.
     *
     * @param array $record Stored remote record.
     * @param string[] $keys Object keys.
     */
    private static function queue_delete($record, $keys) {
        // Changing connections must never create a latent destructive job, even
        // one that would only execute if the old constants were restored later.
        if ($record['signature'] !== self::config()['signature']) {
            return;
        }
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
            self::archive_inventory($id, $record);
            self::queue_delete($record, array_map('strval', array_keys($record['files'])));
        }
        delete_option(self::JOB . 'retry_' . (int) $id);
        delete_option(self::JOB . 'retry_original_' . (int) $id);
        delete_option(self::JOB . 'verify_' . (int) $id);
        $table = self::claims_table();
        if ('' !== $table) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table, through its post_id index.
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE post_id = %d', $table, (int) $id));
        }
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
        // Archived deletion inventories never wake the automatic worker.
        return self::jobs($limit, $after, 'retry_');
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
        if (!$ready && !self::restoring() && !self::original_jobs_due()) {
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
                // A few seconds a batch until the claim index is complete;
                // until then the same-path check searches the inventories.
                self::build_claims($start, 5);
            }
            // Reserve the first half for local copies: a full upload queue must
            // never consume their turn. Unused time remains available to uploads.
            self::local_copies($start, $limit, (int) (self::BUDGET / 2));
            if (self::original_jobs_due()) {
                self::original_jobs($limit, $start);
            }
            $attempts = $ready ? self::upload_batch($start, $limit) : 0;
        } finally {
            self::release($lease);
            self::purge_pages();
        }
        self::reschedule();
        return $attempts;
    }

    /**
     * Purge the cached pages of the pictures that lost their local files in
     * this batch, once each: a page cached before still points at those
     * files (404) until its cache entry expires. The post a picture is
     * attached to is purged, and the picture's own page while the site has
     * attachment pages; never every page, as the batch runs every minute.
     */
    private static function purge_pages() {
        $ids = array();
        // WordPress 6.4+ can turn attachment pages off; older sites have them.
        $pages = (bool) get_option('wp_attachment_pages_enabled', '1');
        foreach (array_keys(self::$purge) as $id) {
            $id = (int) $id;
            if ($pages && 'attachment' === get_post_type($id)) {
                $ids[] = $id;
            }
            // False when the attachment is gone: nothing to purge.
            $parent = wp_get_post_parent_id($id);
            if ($parent) {
                $ids[] = $parent;
            }
        }
        self::$purge = array();
        SEOProStack_Page_Cache::purge_posts($ids);
    }

    /**
     * Run due jobs, then upload waiting attachments, newest first.
     *
     * @param float $start Batch start.
     * @param int $limit Most upload attempts.
     * @return int Upload attempts.
     */
    private static function upload_batch($start, $limit) {
        $attempts = self::process_jobs($start, $limit);
        $ids = $attempts < $limit ? self::waiting($limit - $attempts) : array();
        foreach ($ids as $id) {
            if (!self::more_time($start, self::BUDGET)) {
                break;
            }
            self::upload_attachment($id, $start);
            ++$attempts;
        }
        return $attempts;
    }

    /**
     * Remove uploaded pictures' local copies, or bring removed files back
     * when removal is off. Pauses for an hour after the service fails.
     *
     * @param float $start Batch start.
     * @param int $limit Most attachments.
     * @param int $budget This step's share of the batch, in seconds.
     */
    private static function local_copies($start, $limit, $budget = self::BUDGET) {
        $removing = self::local_mode();
        if (null === $removing) {
            return;
        }
        foreach ($removing ? self::removable($limit) : self::restorable($limit, PHP_INT_MAX, true) as $id) {
            if (!self::more_time($start, $budget) || !self::local_step($id, $removing, $start + $budget)) {
                return;
            }
        }
        // Stuck pictures keep the flag: their files still show from the bucket.
        if (!$removing && !self::restorable(1)) {
            // Every file is back: addresses follow the switch again.
            delete_option(self::FLAG);
            self::$serving = array();
            self::$maps = array();
        }
    }

    /**
     * What the local-copies step does now.
     *
     * @return bool|null True to remove, false to bring files back, null for neither.
     */
    private static function local_mode() {
        if ((int) get_option(self::LOCAL_WAIT, 0) > time()) {
            return null;
        }
        if (self::removing()) {
            // WebP and AVIF copies are made first, before files go.
            return SEOProStack_Nextgen_Images::enabled() && SEOProStack_Nextgen_Images::waiting(1) ? null : true;
        }
        return self::restoring() ? false : null;
    }

    /**
     * Remove or restore one attachment's files. A restore error that
     * belongs to this attachment alone marks it STUCK and the batch goes
     * on; any other failure (service, credentials, uploads folder) pauses
     * this step for an hour. Either way the error shows.
     *
     * @param int $id Attachment ID.
     * @param bool $removing Remove (true) or bring back (false).
     * @param float $deadline End of the reserved turn.
     * @return bool Whether the batch may go on.
     */
    private static function local_step($id, $removing, $deadline) {
        $result = $removing ? self::remove_local($id, $deadline) : self::restore_attachment($id);
        if (!is_wp_error($result)) {
            return true;
        }
        if ('storage_budget' === $result->get_error_code()) {
            return false;
        }
        /* translators: 1: attachment ID, 2: error message */
        update_option(self::ERROR, sprintf(__('Attachment %1$d: %2$s', 'seoprostack'), $id, $result->get_error_message()), false);
        if (!$removing && in_array($result->get_error_code(), self::STUCK_ERRORS, true)) {
            // Tried again by restore --all or --retry-failed, or under another connection.
            update_post_meta($id, self::STUCK, self::config()['signature']);
            return true;
        }
        update_option(self::LOCAL_WAIT, time() + HOUR_IN_SECONDS, false);
        return false;
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
        return self::restoring() && self::restorable(1, PHP_INT_MAX, true);
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
     * originals, unregistered copies, oversized files) are never touched.
     *
     * @param int $id Attachment ID.
     * @param float $deadline End of the reserved turn.
     * @return true|WP_Error WP_Error when the service could not confirm: try later.
     */
    private static function remove_local($id, $deadline) {
        $config = self::config();
        $kept = 'kept:' . $config['signature'];
        self::fresh($id);
        $generation = get_post_meta($id, self::GENERATION, true);
        $record = self::removal_record($id);
        if (!$record || null === self::removed_record($id)) {
            return true;
        }
        // Every local file is checked before any goes, so a picture is
        // removed whole or kept whole.
        $files = self::removal_files($record);
        if (null === $files) {
            // Changed since its upload: keep it; the change queues a new upload.
            update_post_meta($id, self::LOCAL, $kept);
            return true;
        }
        $client = self::client();
        $heads = $client->heads(array_map('strval', array_keys($files)), $deadline, self::concurrency());
        if (microtime(true) >= $deadline) {
            return new WP_Error('storage_budget', __('The storage batch will continue on its next turn.', 'seoprostack'));
        }
        // Confirm the whole group before deletion, including a failure in its
        // last HEAD. Recheck each local digest again immediately before removal.
        foreach ($files as $key => $path) {
            $confirmed = self::confirm_removal($heads[$key], $key, $record['files'][$key], $path);
            if (true !== $confirmed) {
                return self::removal_stopped($id, $confirmed);
            }
        }
        foreach ($files as $key => $path) {
            $info = $record['files'][$key];
            if (!self::digest_matches($path, (string) $info['digest'])) {
                return self::removal_stopped($id, 'changed');
            }
            $entry = array('relative' => (string) $info['relative'], 'size' => (int) $info['size'], 'mtime' => (int) $info['mtime'], 'digest' => (string) $info['digest'], 'version' => (string) $info['version']);
            if (!self::remove_file($id, (string) $key, $entry, $path, $generation)) {
                self::forget($id);
                return true;
            }
        }
        update_post_meta($id, self::LOCAL, $config['signature']);
        self::forget($id);
        delete_transient(self::COUNTS);
        return true;
    }

    /**
     * Delete one confirmed file, recorded first so it can always come back.
     * File readers restore without the batch lease, so a restore may start
     * meanwhile and drop this entry on seeing the file still here: after
     * deleting, a started restore puts the entry back while the file is
     * missing, and the next restore brings it back.
     *
     * @param int $id Attachment ID.
     * @param string $key Object key.
     * @param array $entry Removed file's record.
     * @param string $path Local file (removal_path()).
     * @param mixed $generation Generation when the record was read.
     * @return bool Whether removal may go on to the next file.
     */
    private static function remove_file($id, $key, $entry, $path, $generation) {
        if (self::changed_meanwhile($id, $generation) || !self::record_removed($id, $key, $entry)) {
            return false;
        }
        update_option(self::FLAG, 1, true);
        self::delete_local($path);
        self::$purge[(int) $id] = true;
        if (!self::changed_meanwhile($id, $generation)) {
            return true;
        }
        clearstatcache();
        if (!is_file($path)) {
            self::record_removed($id, $key, $entry);
        }
        return false;
    }

    /**
     * Add one file to the attachment's removed-files list as it is now, so
     * entries another request dropped or added meanwhile are kept as they are.
     *
     * @param int $id Attachment ID.
     * @param string $key Object key.
     * @param array $entry Removed file's record.
     * @return bool False when the list belongs to another connection.
     */
    private static function record_removed($id, $key, $entry) {
        self::fresh($id);
        $removed = self::removed_record($id);
        if (null === $removed) {
            return false;
        }
        $removed['files'][$key] = $entry;
        update_post_meta($id, self::REMOVED, $removed);
        return true;
    }

    /**
     * Last look before a file goes: an edit, a restore or a new upload since
     * the record was read keeps the files.
     *
     * @phpstan-impure Reads meta that other requests change.
     * @param int $id Attachment ID.
     * @param mixed $generation Generation when the record was read.
     * @return bool
     */
    private static function changed_meanwhile($id, $generation) {
        $signature = self::config()['signature'];
        self::fresh($id);
        if (get_post_meta($id, self::DONE, true) !== self::upload_marker() || get_post_meta($id, self::GENERATION, true) !== $generation) {
            return true;
        }
        return get_post_meta($id, self::LOCAL, true) === 'kept:' . $signature;
    }

    /**
     * The attachment's completed record on this connection, when its local
     * copies may be looked at.
     *
     * @param int $id Attachment ID.
     * @return array|null
     */
    private static function removal_record($id) {
        $signature = self::config()['signature'];
        $record = get_post_meta($id, self::META, true);
        $complete = is_array($record) && !empty($record['files']) && 'uploaded' === $record['state'] && $record['signature'] === $signature;
        return $complete && self::root() && get_post_meta($id, self::DONE, true) === self::upload_marker() ? $record : null;
    }

    /**
     * The removed-files list new removals join, stamped with this
     * connection and its public address (so pages keep showing the files
     * after a connection change).
     *
     * @param int $id Attachment ID.
     * @return array|null Null when another connection removed files: only it brings them back.
     */
    private static function removed_record($id) {
        $config = self::config();
        $removed = get_post_meta($id, self::REMOVED, true);
        $files = is_array($removed) && isset($removed['files']) && is_array($removed['files']) ? $removed['files'] : array();
        if ($files && (!isset($removed['signature']) || $removed['signature'] !== $config['signature'])) {
            return null;
        }
        return array('signature' => $config['signature'], 'url' => $config['url'], 'files' => $files);
    }

    /**
     * Local files of a record that may be removed, each still the uploaded
     * file. Files already gone, or not this feature's to touch, are left out.
     *
     * @param array $record Completed record.
     * @return array<string,string>|null Key to path; null when a file changed since its upload.
     */
    private static function removal_files($record) {
        $files = array();
        foreach ($record['files'] as $key => $info) {
            // A local source still needs its format copies for picture mode.
            // Oversized sources may be absent even when their copies uploaded.
            if (preg_match('/\.(?:jpe?g|png|webp)\.(?:webp|avif)$/i', (string) $key) && empty($record['files'][substr((string) $key, 0, -5)]['confirmed'])) {
                continue;
            }
            $path = self::removal_path((string) $key, $info);
            if ('' === $path) {
                continue;
            }
            if (!self::local_matches($path, $info)) {
                return null;
            }
            $files[$key] = $path;
        }
        return $files;
    }

    /**
     * A recorded file's local path, when it is here and this feature may
     * delete it: the record's own key, inside the main uploads folder, and
     * not in Watermark pictures' originals folder.
     *
     * @param string $key Object key.
     * @param array $info Recorded file.
     * @return string Path, or '' to leave it alone.
     */
    private static function removal_path($key, $info) {
        $relative = isset($info['relative']) ? (string) $info['relative'] : '';
        $root = self::root();
        if (!$root || !self::recorded_path($relative, $key) || false !== strpos($relative, 'seoprostack-originals-')) {
            return '';
        }
        $path = $root['dir'] . '/' . $relative;
        clearstatcache();
        return is_file($path) && self::within(realpath($path), realpath($root['dir'])) ? $path : '';
    }

    /**
     * Whether a recorded relative path matches its object key and stays in
     * the uploads folder.
     *
     * @param string $relative Path below the main uploads folder.
     * @param string $key Object key.
     * @return bool
     */
    private static function recorded_path($relative, $key) {
        return '' !== $relative && self::config()['prefix'] . $relative === $key && false === strpos($relative, '..');
    }

    /**
     * Whether a resolved path is inside a folder (or is the folder).
     *
     * @param string|false $real Resolved path.
     * @param string|false $base Resolved folder.
     * @return bool
     */
    private static function within($real, $base) {
        return is_string($real) && is_string($base) && '' !== $base && 0 === strpos(wp_normalize_path($real) . '/', wp_normalize_path($base) . '/');
    }

    /**
     * Whether a local file is still the confirmed upload: size and SHA-256.
     *
     * @param string $path Local path.
     * @param array $info Recorded file.
     * @return bool
     */
    private static function local_matches($path, $info) {
        if (empty($info['confirmed']) || empty($info['digest']) || !isset($info['size']) || wp_filesize($path) !== (int) $info['size']) {
            return false;
        }
        return self::digest_matches($path, (string) $info['digest']);
    }

    /**
     * Whether a readable file has this SHA-256.
     *
     * @param string $path Local path.
     * @param string $digest Expected SHA-256.
     * @return bool
     */
    private static function digest_matches($path, $digest) {
        $actual = is_readable($path) ? hash_file('sha256', $path) : false;
        return is_string($actual) && hash_equals($digest, $actual);
    }

    /**
     * Confirm one file may go: still the uploaded file, the bucket answers
     * HTTP 200 with the recorded size, and the public address serves objects.
     *
     * @param array|WP_Error $head Object confirmation.
     * @param string $key Object key.
     * @param array $info Recorded file.
     * @param string $path Local path.
     * @return true|string|WP_Error True; 'changed' or 'mismatch' to keep the files; WP_Error to try later.
     */
    private static function confirm_removal($head, $key, $info, $path) {
        if (!self::digest_matches($path, (string) $info['digest'])) {
            return 'changed';
        }
        if (is_wp_error($head)) {
            return $head;
        }
        if (200 !== $head['status'] || (int) $info['size'] !== $head['size']) {
            return 'mismatch';
        }
        return self::public_ok($key, (string) $info['version']);
    }

    /**
     * Stop removing an attachment's files: keep them for a mismatch or a
     * change, or hand the service error to the batch.
     *
     * @param int $id Attachment ID.
     * @param string|WP_Error $why confirm_removal()'s answer.
     * @return true|WP_Error
     */
    private static function removal_stopped($id, $why) {
        if (is_wp_error($why)) {
            return $why;
        }
        update_post_meta($id, self::LOCAL, 'kept:' . self::config()['signature']);
        if ('mismatch' === $why) {
            update_option(self::ERROR, __('A stored picture did not match its local file, so its local copies were kept.', 'seoprostack'), false);
        }
        return true;
    }

    /**
     * Delete one recorded, confirmed local file. Not wp_delete_file(): its
     * filter is where WebP and AVIF images deletes a file's copies, and AVIF
     * copies are not in the bucket. A failure keeps the file, which restore
     * then finds.
     *
     * @param string $path Path from removal_path(), inside the uploads folder.
     */
    private static function delete_local($path) {
        if (is_file($path)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- see above; only this recorded file goes.
            unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use -- a recorded upload inside uploads (removal_path()), never request input.
        }
    }

    /**
     * Bring back an attachment's removed files from the bucket, byte for
     * byte (size and SHA-256 must match the record), with their recorded
     * modified times, so WebP copies stay newer than their files. A file
     * already on this server is never overwritten. The attachment then
     * keeps its files here until it changes, or until removal is switched
     * on again.
     *
     * Runs without the batch lease (file readers call it), so a batch may
     * remove one more file meanwhile: the list is read again after each
     * file and only that file's entry goes, and passes repeat while new
     * entries appear. Removal puts back an entry this dropped too early
     * (remove_file()), so a missing file's entry is never lost.
     *
     * @param int $id Attachment ID.
     * @return true|WP_Error
     */
    public static function restore_attachment($id) {
        $id = (int) $id;
        $removed = self::fresh_removed($id);
        if (!$removed) {
            return true;
        }
        $base = self::restore_base($removed);
        if (is_wp_error($base)) {
            return $base;
        }
        // Removal must not take these files again while they are needed here.
        update_post_meta($id, self::LOCAL, 'kept:' . self::config()['signature']);
        $client = null;
        for ($pass = 0; $removed && $pass < 3; ++$pass) {
            $result = self::restore_files($id, $removed['files'], $base, $client);
            if (is_wp_error($result)) {
                return $result;
            }
            $removed = self::fresh_removed($id);
        }
        delete_post_meta($id, self::STUCK);
        self::forget($id);
        delete_transient(self::COUNTS);
        return true;
    }

    /**
     * An attachment's removed-files list, read past the object cache.
     *
     * @phpstan-impure Reads meta that other requests change.
     * @param int $id Attachment ID.
     * @return array|null Null when no file is removed.
     */
    private static function fresh_removed($id) {
        self::fresh($id);
        $removed = get_post_meta($id, self::REMOVED, true);
        return is_array($removed) && !empty($removed['files']) && is_array($removed['files']) ? $removed : null;
    }

    /**
     * Bring back the listed files; each one's entry goes once it is back,
     * or once another file is already there (never overwritten).
     *
     * @param int $id Attachment ID.
     * @param array<string,array> $files Removed files.
     * @param array{dir:string,real:string} $base Main uploads folder (restore_base()).
     * @param SEOProStack_S3_Client|null $client Client, made on first use.
     * @return true|WP_Error
     */
    private static function restore_files($id, $files, $base, &$client) {
        foreach ($files as $key => $info) {
            $result = self::restore_file((string) $key, $info, $base, $client);
            if (is_wp_error($result)) {
                return $result;
            }
            self::drop_removed($id, (string) $key);
        }
        return true;
    }

    /**
     * Take one file off the attachment's removed-files list, as it is now.
     *
     * @param int $id Attachment ID.
     * @param string $key Object key.
     */
    private static function drop_removed($id, $key) {
        $removed = self::fresh_removed($id);
        if (!$removed) {
            return;
        }
        unset($removed['files'][$key]);
        if ($removed['files']) {
            update_post_meta($id, self::REMOVED, $removed);
        } else {
            delete_post_meta($id, self::REMOVED);
        }
    }

    /**
     * The main uploads folder files come back to, when this connection
     * removed them.
     *
     * @param array $removed Removed-files list.
     * @return array{dir:string,real:string}|WP_Error The folder and its resolved path.
     */
    private static function restore_base($removed) {
        $config = self::config();
        if ($config['missing']) {
            return new WP_Error('storage_config', __('Set the SEOPROSTACK_MEDIA_STORAGE constants to bring files back.', 'seoprostack'));
        }
        if (!isset($removed['signature']) || ($removed['signature'] !== $config['signature'] && empty($removed['url']))) {
            return new WP_Error('storage_signature', __('These files were removed while another storage connection was set. Set that connection again to bring them back.', 'seoprostack'));
        }
        $root = self::root();
        $real = $root ? realpath($root['dir']) : false;
        return $root && $real ? array('dir' => $root['dir'], 'real' => $real, 'source' => $removed) : new WP_Error('storage_uploads', __('The uploads folder could not be found.', 'seoprostack'));
    }

    /**
     * Bring back one removed file, unless a file is already there.
     *
     * @param string $key Object key.
     * @param array $info Removed file's record.
     * @param array{dir:string,real:string} $base Main uploads folder (restore_base()).
     * @param SEOProStack_S3_Client|null $client Client, made on first use.
     * @return true|WP_Error
     */
    private static function restore_file($key, $info, $base, &$client) {
        $path = self::restore_target($key, $info, $base);
        if (true === $path || is_wp_error($path)) {
            return $path;
        }
        $body = isset($base['source']) && $base['source']['signature'] !== self::config()['signature'] ? self::previous_bytes($key, $info, $base['source']) : self::download($key, $info, $client);
        if (is_wp_error($body)) {
            return $body;
        }
        return self::write_file($path, $body, (int) $info['mtime']);
    }

    /**
     * Where a removed file comes back to.
     *
     * @param string $key Object key.
     * @param array $info Removed file's record.
     * @param array{dir:string,real:string} $base Main uploads folder (restore_base()).
     * @return string|true|WP_Error Path; true when a file is already there.
     */
    private static function restore_target($key, $info, $base) {
        $relative = isset($info['relative']) ? (string) $info['relative'] : '';
        $source = isset($base['source']) ? $base['source'] : array();
        $valid = isset($source['signature']) && $source['signature'] !== self::config()['signature'] ? self::safe_relative($relative) : self::recorded_path($relative, $key);
        if (!$valid || !isset($info['size'], $info['digest'], $info['mtime'])) {
            return new WP_Error('storage_restore_record', __('A removed file’s record is not valid, so it was not brought back.', 'seoprostack'));
        }
        $path = $base['dir'] . '/' . $relative;
        clearstatcache();
        if (is_file($path)) {
            return true;
        }
        $dir = dirname($path);
        if (!wp_mkdir_p($dir) || !self::within(realpath($dir), $base['real'])) {
            return new WP_Error('storage_write', __('A picture could not be written back to the uploads folder.', 'seoprostack'));
        }
        return $path;
    }

    /**
     * Download a removed file with a signed GET, byte for byte as recorded.
     *
     * @param string $key Object key.
     * @param array $info Removed file's record.
     * @param SEOProStack_S3_Client|null $client Client, made on first use.
     * @return string|WP_Error File contents.
     */
    private static function download($key, $info, &$client) {
        if ((int) $info['size'] > self::size_cap()) {
            return new WP_Error('storage_memory', __('A picture is too large to bring back with the PHP memory available.', 'seoprostack'));
        }
        $client = $client ? $client : self::client();
        $body = $client->get($key);
        if (is_wp_error($body)) {
            return self::download_error($body);
        }
        if (strlen($body) !== (int) $info['size'] || !hash_equals((string) $info['digest'], hash('sha256', $body))) {
            return new WP_Error('storage_restore_mismatch', __('A stored picture did not match its record, so it was not brought back.', 'seoprostack'));
        }
        return $body;
    }

    /**
     * A failed download's error: a missing object (HTTP 404) affects only
     * this picture; anything else is the service's and stays as it is.
     *
     * @param WP_Error $error Client error.
     * @return WP_Error
     */
    private static function download_error($error) {
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status']) && 404 === $data['status']) {
            return new WP_Error('storage_restore_missing', __('A removed picture is missing from the bucket, so it was not brought back.', 'seoprostack'));
        }
        return $error;
    }

    /**
     * Write a file through a temporary file beside it, renamed into place,
     * never over a file that appeared meanwhile; then permissions as
     * wp_handle_upload() sets them, and the recorded modified time (so WebP
     * copies stay newer than their files).
     *
     * @param string $path Target inside the uploads folder.
     * @param string $body Contents.
     * @param int $mtime Modified time.
     * @return true|WP_Error
     */
    private static function write_file($path, $body, $mtime) {
        $temp = $path . '.' . wp_generate_password(8, false, false) . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file beside the target; failure is handled below.
        $written = file_put_contents($temp, $body);
        clearstatcache();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same folder, so the file appears whole.
        $moved = strlen($body) === $written && !is_file($path) && rename($temp, $path);
        if (!$moved) {
            if (is_file($temp)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- this request's own temporary file.
                unlink($temp); // nosemgrep: php.lang.security.unlink-use.unlink-use -- this request's own temporary file, never request input.
            }
            return is_file($path) ? true : new WP_Error('storage_write', __('A picture could not be written back to the uploads folder.', 'seoprostack'));
        }
        $stat = stat(dirname($path));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- as wp_handle_upload() does.
        chmod($path, $stat ? $stat['mode'] & 0000666 : 0644);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- the recorded modified time.
        touch($path, $mtime);
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
     * @param string $name Lock option name.
     * @return string The lease this batch holds; empty when another batch runs.
     */
    private static function lease($name = self::LOCK) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read past the options cache: the lease changes under it.
        $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ('' !== $held && (int) strtok($held, ' ') < time()) {
            self::release($held, $name);
        }
        $value = (time() + 300) . ' ' . wp_generate_uuid4();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic insert is the lease; the options API cannot do it.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value)) ? $value : '';
    }

    /**
     * Delete the batch lease if it still has the given value.
     *
     * @param string $value Lease value.
     * @param string $name Lock option name.
     */
    private static function release($value, $name = self::LOCK) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete; the lease row is never cached (see lease()).
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value));
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
        // Removed files left while restoring, with nothing due: all stuck
        // (local_step()), and the panel points to their error.
        if (null === $due && !(self::restoring() && self::restorable(1))) {
            // Nothing waiting, retrying or left to delete: an old error no
            // longer applies, so the panel and status stop showing it.
            delete_option(self::ERROR);
        }
        $due = self::earliest($due, self::claims_due());
        if (null !== $due && $due <= time() + 60) {
            return;
        }
        wp_clear_scheduled_hook(self::HOOK);
        // Work queued between those checks and the clear saw the old event
        // and did not schedule its own: look again after clearing.
        $due = self::due_now() ? time() : self::earliest(self::next_due(), self::claims_due());
        if (null !== $due) {
            self::schedule(max(10, $due - time()));
        }
    }

    /**
     * The earlier of two times, either of which may be null (none).
     *
     * @param int|null $first Time.
     * @param int|null $second Time.
     * @return int|null
     */
    private static function earliest($first, $second) {
        if (null === $first || null === $second) {
            return null === $first ? $second : $first;
        }
        return min($first, $second);
    }

    /**
     * Whether uploads, removals, restores or a claim index build step are
     * waiting now. The index build keeps the queue going on a quiet site,
     * so its speed-up is not left waiting for the next upload.
     *
     * @phpstan-impure Reads queues, which other requests change.
     * @return bool
     */
    private static function due_now() {
        $claims = self::claims_due();
        return (self::ready() && self::waiting(1)) || self::local_due() || self::original_jobs_due() || (null !== $claims && $claims <= time());
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
        // Switched off, upload jobs wait: only restores run. Deletions are explicit.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded lookup through option_name's unique index.
        $values = self::ready() ? $wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 501", $wpdb->esc_like(self::JOB . 'retry_') . '%')) : array();
        if (count($values) > 500) {
            return time();
        }
        $due = self::local_wake();
        if (self::original_jobs_due()) {
            $due = null === $due ? time() + HOUR_IN_SECONDS : min($due, time() + HOUR_IN_SECONDS);
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
     * When removing or restoring, paused after a failure, may run again.
     *
     * @return int|null Timestamp; null when it is not paused.
     */
    private static function local_wake() {
        $wait = (int) get_option(self::LOCAL_WAIT, 0);
        return $wait > time() && (self::removing() || self::restoring()) ? $wait : null;
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
            if ('original_retry' === $job['kind']) {
                if ($attempts >= $limit) {
                    continue;
                }
                self::retry_original((int) $job['id']);
                ++$attempts;
                continue;
            }
            if ('retry' === $job['kind']) {
                if ($attempts >= $limit) {
                    continue;
                }
                // The attempt replaces this job or removes it on completion.
                // An early return must not strand a failed attachment.
                self::upload_attachment((int) $job['id'], $start);
                ++$attempts;
                continue;
            }
            // Retain legacy delete jobs without executing them. Only the explicit
            // forget-previous --delete-objects command may delete stored media.
        }
        return $attempts;
    }

    /** @return SEOProStack_S3_Client */
    private static function client() {
        require_once dirname(__DIR__) . '/class-seoprostack-s3-client.php';
        $config = self::config();
        return new SEOProStack_S3_Client($config['endpoint'], $config['region'], $config['bucket'], $config['key_id'], $config['secret']);
    }

    /** @return string Identity of the private destination, never credentials. */
    private static function original_signature() {
        $config = self::config();
        return hash('sha256', $config['endpoint'] . '\n' . $config['originals_bucket']);
    }

    /**
     * Serialize readers, uploads and deletion for the lifetime of a request.
     *
     * @param array $record Watermark record.
     * @return true|WP_Error
     */
    private static function original_lock($record) {
        $name = self::JOB . 'originaluse_' . hash('sha256', (string) get_current_blog_id() . ($record['backup'] ?? ''));
        if (isset(self::$original_leases[$name])) {
            return true;
        }
        $lease = self::lease($name);
        if ('' === $lease) {
            return new WP_Error('storage_original_busy', __('The kept original is in use by another request. Try again shortly.', 'seoprostack'));
        }
        self::$original_leases[$name] = $lease;
        $blog = get_current_blog_id();
        register_shutdown_function(static function () use ($record, $lease, $name, $blog) {
            $switched = get_current_blog_id() !== $blog;
            if ($switched) {
                switch_to_blog($blog);
            }
            try {
                self::finish_original($record);
                self::release($lease, $name);
                unset(self::$original_leases[$name]);
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
        });
        return true;
    }

    /**
     * Never read or delete an original through a changed or public destination.
     *
     * @param array $record Watermark record, optionally already remote.
     * @return SEOProStack_S3_Client|WP_Error
     */
    private static function original_client($record) {
        $config = self::config();
        if ($config['missing'] || '' === $config['originals_bucket'] || $config['originals_error'] || (!empty($record['original_signature']) && $record['original_signature'] !== self::original_signature())) {
            return new WP_Error('storage_original_config', __('Set the original private bucket connection again before using its kept originals. It must not be the public media bucket.', 'seoprostack'));
        }
        require_once dirname(__DIR__) . '/class-seoprostack-s3-client.php';
        return new SEOProStack_S3_Client($config['endpoint'], $config['region'], $config['originals_bucket'], $config['key_id'], $config['secret']);
    }

    /**
     * Resolve only a watermark backup inside this site's protected folder.
     *
     * @param array $record Watermark record.
     * @return string|WP_Error
     */
    private static function kept_original_path($record) {
        $relative = $record['backup'] ?? '';
        $uploads = wp_get_upload_dir();
        if (!is_string($relative) || !preg_match('#^seoprostack-originals-[a-z0-9]{16}/#', $relative) || !self::safe_relative($relative) || empty($uploads['basedir']) || !empty($uploads['error'])) {
            return new WP_Error('storage_original_path', __('The kept original’s path is not valid.', 'seoprostack'));
        }
        $base = realpath($uploads['basedir']);
        $path = trailingslashit($uploads['basedir']) . $relative;
        $parent = dirname($path);
        // Do not recreate a missing protection folder without its deny rules.
        $protected = trailingslashit($uploads['basedir']) . strtok($relative, '/');
        if (!$base || !is_file($protected . '/.htaccess') || !wp_mkdir_p($parent) || !self::within(realpath($parent), $base) || is_link($path)) {
            return new WP_Error('storage_original_path', __('The protected originals folder is not available.', 'seoprostack'));
        }
        return $path;
    }

    /**
     * Bring back only the recorded bytes, without touching a mismatched file.
     *
     * @param array $record Watermark record.
     * @return true|WP_Error
     */
    public static function ensure_original_local($record) {
        if (empty($record['remote'])) {
            return true;
        }
        $locked = self::original_lock($record);
        if (is_wp_error($locked)) {
            return $locked;
        }
        $path = self::kept_original_path($record);
        if (is_wp_error($path)) {
            return $path;
        }
        if (!isset($record['size'], $record['digest'], $record['mtime']) || !is_string($record['digest']) || !preg_match('/^[a-f0-9]{64}$/', $record['digest'])) {
            return new WP_Error('storage_restore_record', __('The kept original’s record is incomplete.', 'seoprostack'));
        }
        if (is_file($path)) {
            return self::original_matches($path, $record) ? true : new WP_Error('storage_restore_mismatch', __('The local original does not match its record; it was left unchanged.', 'seoprostack'));
        }
        $client = self::original_client($record);
        if (is_wp_error($client)) {
            return $client;
        }
        $body = self::download($record['backup'], $record, $client);
        if (is_wp_error($body)) {
            return $body;
        }
        $written = self::write_file($path, $body, (int) $record['mtime']);
        if (is_wp_error($written)) {
            return $written;
        }
        return self::original_matches($path, $record) ? true : new WP_Error('storage_restore_mismatch', __('The restored original does not match its record.', 'seoprostack'));
    }

    /**
     * @param string $path Local original.
     * @param array $record Watermark record.
     * @return bool
     */
    private static function original_matches($path, $record) {
        clearstatcache();
        return is_file($path) && isset($record['size'], $record['digest']) && (int) filesize($path) === (int) $record['size'] && hash_file('sha256', $path) === $record['digest'];
    }

    /**
     * Upload separately, after public files. No original enters the URL inventory.
     *
     * @param int $id Attachment ID.
     * @return true|WP_Error
     */
    private static function upload_original($id) {
        $record = SEOProStack_Watermark_Images::record($id);
        if (empty($record['backup']) || ('' === self::config()['originals_bucket'] && empty($record['remote']))) {
            return true;
        }
        if (!empty($record['original_delete_failed'])) {
            return new WP_Error('storage_original_delete', __('Retry the kept original’s failed deletion before marking this picture again.', 'seoprostack'));
        }
        $locked = self::original_lock($record);
        if (is_wp_error($locked)) {
            return $locked;
        }
        $client = self::original_client($record);
        $path = self::kept_original_path($record);
        if (is_wp_error($client) || is_wp_error($path)) {
            return is_wp_error($client) ? $client : $path;
        }
        if (empty($record['remote'])) {
            if (!is_file($path) || filesize($path) > self::size_cap()) {
                return new WP_Error('storage_original_read', __('The kept original is missing or too large to upload safely.', 'seoprostack'));
            }
            $before = $record;
            $record['size'] = (int) filesize($path);
            $record['mtime'] = (int) filemtime($path);
            $record['digest'] = hash_file('sha256', $path);
            $record['original_signature'] = self::original_signature();
            $record['original_attachment'] = (int) $id;
            $record['original_state'] = 'failed';
            // Retain ownership even when PUT succeeds but the response is lost.
            $record['original_attempted'] = true;
            if (!self::save_original_record($id, $before, $record)) {
                return new WP_Error('storage_original_record', __('The kept original’s record could not be saved.', 'seoprostack'));
            }
            $result = $client->put_file($record['backup'], $path, 'application/octet-stream', 'private, no-store', (string) $record['digest']);
            if (is_wp_error($result)) {
                return $result;
            }
            // Signed GET checks the actual bytes, not just a successful PUT.
            $body = self::download($record['backup'], $record, $client);
            if (is_wp_error($body)) {
                return $body;
            }
            unset($body);
            self::fresh($id);
            if (!self::original_record_is($id, $record)) {
                self::delete_original($record);
                return new WP_Error('storage_changed', __('The picture changed during its original’s upload; it will be retried.', 'seoprostack'));
            }
            $before = $record;
            $record['remote'] = true;
            $record['original_state'] = 'uploaded';
            if (!self::save_original_record($id, $before, $record) || !self::attachment_exists($id)) {
                self::delete_original($record);
                return new WP_Error('storage_original_record', __('The kept original’s upload could not be recorded; its local copy was kept.', 'seoprostack'));
            }
        }
        if (!self::remember_original($id, $record)) {
            return new WP_Error('storage_original_record', __('The original has a pending deletion or its recovery inventory could not be saved; its local copy was kept.', 'seoprostack'));
        }
        return self::finish_original($record);
    }

    /**
     * Read through after a write or remote I/O; another request may have edited it.
     *
     * @phpstan-impure
     * @param int $id Attachment ID.
     * @param array $record Expected watermark record.
     * @return bool
     */
    private static function original_record_is($id, $record) {
        self::fresh($id);
        return get_post_meta($id, SEOProStack_Watermark_Images::META, true) === $record;
    }

    /**
     * Compare-and-update only an existing record: never resurrect deleted meta
     * or overwrite a concurrent forget/replace after network I/O.
     *
     * @param int $id Attachment ID.
     * @param array $before Expected record.
     * @param array $after Updated record.
     * @return bool
     */
    private static function save_original_record($id, $before, $after) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic compare-and-update; update_post_meta inserts when a concurrent deletion removes the row.
        $saved = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS BINARY) = %s", maybe_serialize($after), $id, SEOProStack_Watermark_Images::META, maybe_serialize($before)));
        return false !== $saved && self::original_record_is($id, $after);
    }

    /**
     * Reclaim a fetched original only after use, and only after remote confirmation.
     *
     * @param array $record Watermark record.
     * @return true|WP_Error
     */
    public static function finish_original($record) {
        if (empty($record['remote']) || !self::removing()) {
            return true;
        }
        $inventory = get_option(self::JOB . 'original_' . hash('sha256', $record['original_signature'] . $record['backup']), array());
        if (isset($inventory['keep']) && $inventory['keep'] === get_option(self::JOB . 'originalkeep', 'initial')) {
            return true;
        }
        $locked = self::original_lock($record);
        if (is_wp_error($locked)) {
            return $locked;
        }
        $path = self::kept_original_path($record);
        if (is_wp_error($path) || !is_file($path)) {
            return is_wp_error($path) ? $path : true;
        }
        $client = self::original_client($record);
        if (is_wp_error($client)) {
            return $client;
        }
        $body = self::download($record['backup'], $record, $client);
        if (is_wp_error($body)) {
            return $body;
        }
        unset($body);
        if (!self::original_matches($path, $record)) {
            return new WP_Error('storage_restore_mismatch', __('The kept original could not be confirmed; its local copy was kept.', 'seoprostack'));
        }
        self::delete_local($path);
        clearstatcache();
        return !is_file($path) ? true : new WP_Error('storage_original_delete', __('The local original could not be removed.', 'seoprostack'));
    }

    /**
     * Keep a recovery inventory outside attachment meta, which core deletes.
     *
     * @param int $id Attachment ID.
     * @param array $record Watermark record.
     * @return bool
     */
    private static function remember_original($id, $record) {
        global $wpdb;
        $name = self::JOB . 'original_' . hash('sha256', $record['original_signature'] . $record['backup']);
        $job = array('id' => $id, 'record' => $record, 'delete' => false);
        // Insert only: a concurrent deletion tombstone and an explicit keep
        // marker are monotonic and must never be overwritten by an uploader.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic insert; add_option can upsert a concurrent tombstone.
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, maybe_serialize($job)));
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        $saved = get_option($name);
        if (1 === $inserted) {
            update_option(self::JOB . 'originalcursor', '', false);
        }
        self::schedule();
        return is_array($saved) && empty($saved['delete']) && ($saved['record']['digest'] ?? '') === $record['digest'];
    }

    /**
     * Persist deletion before attempting it: a deleted attachment cannot lose it.
     *
     * @param array $record Watermark record.
     * @return true|WP_Error
     */
    public static function delete_original($record) {
        if (empty($record['remote']) && empty($record['original_attempted'])) {
            return true;
        }
        $name = self::JOB . 'original_' . hash('sha256', $record['original_signature'] . $record['backup']);
        update_option($name, array('id' => 0, 'record' => $record, 'delete' => true), false);
        update_option(self::JOB . 'originalcursor', '', false);
        self::schedule();
        if (get_option($name) !== array('id' => 0, 'record' => $record, 'delete' => true)) {
            return new WP_Error('storage_original_record', __('The kept original’s deletion could not be recorded.', 'seoprostack'));
        }
        $locked = self::original_lock($record);
        if (is_wp_error($locked)) {
            return $locked;
        }
        $client = self::original_client($record);
        if (is_wp_error($client)) {
            return $client;
        }
        $result = $client->delete($record['backup']);
        if (!is_wp_error($result)) {
            delete_option($name);
        }
        return $result;
    }

    /** @return bool Whether a bounded inventory traversal remains. */
    private static function original_jobs_due() {
        $config = self::config();
        return !$config['missing'] && '' !== $config['originals_bucket'] && !$config['originals_error'] && self::jobs(1, '', 'original_') && get_option(self::JOB . 'originalcursor', '') !== '~';
    }

    /**
     * Revisit private inventories on setting changes, and retry orphan deletions.
     *
     * @param int $limit Most originals in this turn.
     * @param float $start Batch start time.
     */
    private static function original_jobs($limit, $start) {
        $cursor = (string) get_option(self::JOB . 'originalcursor', '');
        $mode = self::removing() ? 'remove' : 'restore';
        if ($mode !== get_option(self::JOB . 'originalmode')) {
            $cursor = '';
            update_option(self::JOB . 'originalmode', $mode, false);
        }
        if ('~' === $cursor) {
            return;
        }
        $names = self::jobs($limit, $cursor, 'original_');
        $complete = true;
        foreach ($names as $name) {
            if (!self::more_time($start, self::BUDGET)) {
                $complete = false;
                break;
            }
            $job = get_option($name);
            $previous = $cursor;
            $cursor = $name;
            if (!is_array($job) || empty($job['record'])) {
                continue;
            }
            $record = $job['record'];
            if (!empty($job['delete'])) {
                $result = self::delete_original($record);
                $id = (int) ($record['original_attachment'] ?? 0);
                $current = SEOProStack_Watermark_Images::record($id);
                if (!is_wp_error($result) && !empty($current['original_delete_failed']) && $current['backup'] === $record['backup']) {
                    SEOProStack_Watermark_Images::forget($id);
                }
            } elseif (($current = SEOProStack_Watermark_Images::record((int) $job['id'])) && ($current['backup'] ?? '') === $record['backup'] && ($current['digest'] ?? '') === $record['digest'] && !empty($current['remote'])) {
                $result = self::removing() ? self::finish_original($record) : self::ensure_original_local($record);
            } else {
                // Never remove a file for a changed attachment's old inventory.
                continue;
            }
            if (is_wp_error($result)) {
                update_option(self::ERROR, $result->get_error_message(), false);
                $complete = false;
                $cursor = $previous;
                break;
            }
        }
        update_option(self::JOB . 'originalcursor', $complete && count($names) < $limit ? '~' : $cursor, false);
    }

    /**
     * Retry private uploads independently of confirmed public inventories.
     *
     * @param int $id Attachment ID.
     * @return bool Whether the kept original completed successfully.
     */
    private static function retry_original($id) {
        $name = self::JOB . 'retry_original_' . $id;
        if (!self::attachment_exists($id)) {
            delete_option($name);
            return false;
        }
        // Persist before I/O so even an interrupted private upload retries alone.
        update_option($name, array('kind' => 'original_retry', 'id' => $id, 'signature' => self::config()['signature'], 'next' => time() + HOUR_IN_SECONDS), false);
        $result = self::upload_original($id);
        $record = SEOProStack_Watermark_Images::record($id);
        if (!empty($record['backup']) && '' !== self::config()['originals_bucket']) {
            $updated = $record;
            $updated['original_state'] = is_wp_error($result) ? 'failed' : 'uploaded';
            self::save_original_record($id, $record, $updated);
        }
        if (is_wp_error($result)) {
            update_option(self::ERROR, $result->get_error_message(), false);
        } else {
            delete_option($name);
        }
        return !is_wp_error($result);
    }

    /** @return array<string,int> CLI-only paged counts through the meta_key index. */
    private static function original_counts() {
        global $wpdb;
        $counts = array('uploaded' => 0, 'waiting' => 0, 'failed' => 0, 'local removed' => 0);
        $before = PHP_INT_MAX;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded CLI inventory through meta_key.
            $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id < %d ORDER BY post_id DESC LIMIT 100", SEOProStack_Watermark_Images::META, $before));
            foreach ($ids as $id) {
                $before = (int) $id;
                $record = SEOProStack_Watermark_Images::record($before);
                if (empty($record['backup'])) {
                    continue;
                }
                $state = !empty($record['original_delete_failed']) || ($record['original_state'] ?? '') === 'failed' ? 'failed' : (!empty($record['remote']) ? 'uploaded' : 'waiting');
                ++$counts[$state];
                $path = self::kept_original_path($record);
                if (!empty($record['remote']) && !is_wp_error($path) && !is_file($path)) {
                    ++$counts['local removed'];
                }
            }
        } while ($ids);
        $after = '';
        do {
            $names = self::jobs(100, $after, 'original_');
            foreach ($names as $name) {
                $after = $name;
                $job = get_option($name);
                if (is_array($job) && !empty($job['delete'])) {
                    $record = SEOProStack_Watermark_Images::record((int) ($job['record']['original_attachment'] ?? 0));
                    if (empty($record['original_delete_failed'])) {
                        ++$counts['failed'];
                    }
                }
            }
        } while ($names);
        return $counts;
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

    /** @return int Maximum concurrent storage requests. */
    private static function concurrency() {
        /** Filter concurrent storage requests (1–32). @param int $concurrency Maximum in-flight files. */
        return max(1, min(32, (int) apply_filters('seoprostack_media_storage_concurrency', 20)));
    }

    /** @return int Group payload allowance, reserving space for transport copies. */
    private static function group_byte_cap() {
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $free = $limit > 0 ? max(0, $limit - memory_get_usage(true) - 16 * MB_IN_BYTES) : 128 * MB_IN_BYTES;
        /** Filter the group's payload byte cap. @param int $bytes Maximum bytes, default 32 MiB. */
        $bytes = (int) apply_filters('seoprostack_media_storage_group_bytes', 32 * MB_IN_BYTES);
        return max(1, min(max(1, $bytes), (int) ($free / 4)));
    }

    /**
     * Collect attachment files and registered WebP and AVIF copies, within uploads.
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
            foreach (array('webp', 'avif') as $format) {
                if (!empty($nextgen['files'][$name][$format]) && $nextgen['files'][$name][$format] > 0 && is_file($paths[0]) && is_file($paths[0] . '.' . $format) && filemtime($paths[0] . '.' . $format) >= filemtime($paths[0])) {
                    $paths[] = dirname($file) . '/' . $name . '.' . $format;
                }
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
                // WordPress before 6.5 does not recognise AVIF extensions.
                if (substr($path, -5) === '.avif') {
                    $type['type'] = 'image/avif';
                }
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
     * Whether another connection removed this attachment's local files. Its
     * bucket objects are then their only copies: never delete or relabel
     * them; restoring needs the old connection again.
     *
     * @param int $id Attachment ID.
     * @return bool True when the upload must not run (marked failed).
     */
    private static function removed_elsewhere($id) {
        if (!self::removed($id) || self::removed_files($id)) {
            return false;
        }
        update_post_meta($id, self::DONE, self::upload_marker());
        update_post_meta($id, self::STATE, 'failed');
        update_option(self::ERROR, __('Some pictures’ local files were removed while another storage connection was set. Set that connection again and run wp seoprostack media-storage restore --all.', 'seoprostack'), false);
        delete_transient(self::COUNTS);
        return true;
    }

    /**
     * The attachment's previous inventory on this connection. Another
     * destination's is queued for deletion, never relabelled as this one.
     *
     * @param int $id Attachment ID.
     * @return array Empty when there is none.
     */
    private static function own_inventory($id) {
        $old = get_post_meta($id, self::META, true);
        if (!is_array($old)) {
            return array();
        }
        if (isset($old['signature']) && $old['signature'] !== self::config()['signature']) {
            self::archive_inventory($id, $old);
            return array();
        }
        return $old;
    }

    /**
     * A changed picture is collected from local files: bring removed ones
     * back first, or the new inventory would drop (and delete) their only
     * copies. Pages keep their bucket addresses meanwhile.
     *
     * @param int $id Attachment ID.
     * @return bool Whether the upload may go on.
     */
    private static function restore_for_upload($id) {
        $restored = self::removed($id) ? self::restore_attachment($id) : true;
        if (is_wp_error($restored)) {
            update_option(self::ERROR, $restored->get_error_message(), false);
            return false;
        }
        return true;
    }

    /**
     * Only a newly enabled originals bucket may reuse the complete AVIF inventory.
     *
     * @param int $id Attachment ID.
     * @param array $record Public inventory.
     * @return bool
     */
    private static function needs_original_only($id, $record) {
        $config = self::config();
        if ('' === $config['originals_bucket'] || ($record['signature'] ?? '') !== $config['signature'] || ($record['state'] ?? '') !== 'uploaded' || empty($record['files']) || get_post_meta($id, self::DONE, true) !== $config['signature'] . ':avif') {
            return false;
        }
        foreach ($record['files'] as $info) {
            if (empty($info['confirmed'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Upload the whole attachment atomically; unchanged files make no request.
     *
     * @param int $id Attachment ID.
     * @param float|null $start When the batch began (microtime), so a batch keeps one time budget.
     * @return bool Whether all eligible files were copied.
     */
    public static function upload_attachment($id, $start = null) {
        global $wpdb;
        $inventory = get_post_meta($id, self::META, true);
        if (is_array($inventory) && !empty($inventory['files']) && isset($inventory['signature']) && $inventory['signature'] !== self::config()['signature']) {
            // Only explicit adopt/migrate may replace an old inventory. Mark the
            // queue visited, preserving its source marker before overwriting DONE.
            $generation = get_post_meta($id, self::GENERATION, true);
            $marker = get_post_meta($id, self::DONE, true);
            self::fresh($id);
            if (get_post_meta($id, self::GENERATION, true) !== $generation || get_post_meta($id, self::DONE, true) !== $marker) {
                return false;
            }
            if (self::upload_marker() !== $marker) {
                $preserved = $inventory;
                $preserved['handover_done'] = $marker;
                $preserved['handover_generation'] = $generation;
                if ($preserved !== $inventory && !update_post_meta($id, self::META, $preserved, $inventory)) {
                    return false;
                }
                $inventory = $preserved;
            }
            self::archive_inventory($id, $inventory);
            update_post_meta($id, self::DONE, self::upload_marker());
            return false;
        }
        if (!self::ready() || 0 !== strpos((string) get_post_mime_type($id), 'image/') || self::removed_elsewhere($id)) {
            return false;
        }
        self::forget($id);
        $config = self::config();
        $generation = get_post_meta($id, self::GENERATION, true);
        $old = self::own_inventory($id);
        if (self::needs_original_only($id, $old)) {
            $marker = $config['signature'] . ':avif';
            if (!self::retry_original($id) || !self::attachment_exists($id)) {
                return false;
            }
            // Network I/O must not mark a concurrent edit or replacement complete.
            if (get_post_meta($id, self::GENERATION, true) !== $generation || get_post_meta($id, self::META, true) !== $old || get_post_meta($id, self::DONE, true) !== $marker) {
                return false;
            }
            // Update only: update_post_meta would insert after a concurrent queue()
            // deleted DONE, hiding that edit from the pending-upload selection.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic compare-and-update must never resurrect an invalidated marker.
            $saved = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS BINARY) = %s", self::upload_marker(), $id, self::DONE, $marker));
            self::fresh($id);
            delete_transient(self::COUNTS);
            self::forget($id);
            return 1 === $saved;
        }
        $attempt = isset($old['attempts']) ? (int) $old['attempts'] + 1 : 1;
        // Tried first: even a fatal error moves the queue forward and retries later.
        update_post_meta($id, self::DONE, self::upload_marker());
        $job = array('kind' => 'retry', 'id' => $id, 'signature' => $config['signature'], 'next' => time() + HOUR_IN_SECONDS);
        update_option(self::JOB . 'retry_' . $id, $job, false);
        if (!self::restore_for_upload($id)) {
            return false;
        }
        $record = array('signature' => $config['signature'], 'url' => $config['url'], 'files' => array(), 'state' => 'failed', 'attempts' => $attempt, 'skipped' => 0);
        if (isset($old['collision_source'])) {
            $record['collision_source'] = $old['collision_source'];
        }
        // Keep the previous inventory for deletion/recovery, but never serve a partial upload.
        $pending = $old ? $old : $record;
        $pending['state'] = 'failed';
        $pending['attempts'] = $attempt;
        update_post_meta($id, self::META, $pending);
        update_post_meta($id, self::STATE, 'failed');
        delete_transient(self::COUNTS);
        clearstatcache();
        $files = self::files($id);
        if (self::conflicting_files($id, $files)) {
            self::collision_failed($id, $pending);
            return false;
        }
        $start = null === $start ? microtime(true) : (float) $start;
        $concurrency = self::concurrency();
        $byte_cap = self::group_byte_cap();
        $client = self::client(); // Loads the lazily included S3 client before grouping.
        foreach (SEOProStack_S3_Client::file_groups($files, $concurrency, $byte_cap) as $group) {
            if (!self::more_time($start, self::BUDGET)) {
                self::defer_upload($id, $pending, $old, $job);
                return false;
            }
            $changed = array();
            $eligible = array();
            foreach ($group as $key => $info) {
                if ($info['size'] > self::size_cap()) {
                    ++$record['skipped'];
                    continue;
                }
                $eligible[$key] = true;
                $previous = isset($old['files'][$key]) ? $old['files'][$key] : array();
                $same = isset($old['signature']) && $old['signature'] === $config['signature'] && !empty($previous['confirmed']) && !empty($info['digest']) && isset($previous['digest']) && $previous['digest'] === $info['digest'];
                if (!$same) {
                    // Persist the attempted key before PUT: deletion or a fatal error
                    // must not lose an object whose server accepted the request.
                    $attempted = $info;
                    unset($attempted['path'], $attempted['type']);
                    $attempted['confirmed'] = false;
                    $pending['files'][$key] = $attempted;
                    $changed[$key] = $info;
                }
            }
            // Every attempted key is durable before any request in this group.
            $saved = update_post_meta($id, self::META, $pending);
            if (!$saved && get_post_meta($id, self::META, true) !== $pending) {
                // The retry was recorded before building this inventory.
                return false;
            }
            // Recheck after durable claims: a concurrent uploader may have claimed
            // one of these keys since the whole-attachment preflight.
            if (self::conflicting_files($id, $group)) {
                self::collision_failed($id, $pending);
                return false;
            }
            /** Filter the public object cache policy. @param string $policy Cache-Control. */
            $policy = (string) apply_filters('seoprostack_media_storage_cache_control', 'public, max-age=31536000, immutable');
            $results = $client->put_files($changed, $policy, $concurrency, $byte_cap, $start + self::BUDGET);
            $error = null;
            foreach ($group as $key => $info) {
                // Transport allocations must not change eligibility after PUT.
                if (!isset($eligible[$key])) {
                    continue;
                }
                if (isset($results[$key]) && is_wp_error($results[$key])) {
                    // A short budget deferral must not hide a real peer failure.
                    if (!is_wp_error($error) || 'storage_budget' !== $results[$key]->get_error_code()) {
                        $error = $results[$key];
                    }
                    continue;
                }
                unset($info['path'], $info['type']);
                $info['confirmed'] = true;
                $record['files'][$key] = $info;
                $pending['signature'] = $config['signature'];
                $pending['files'][$key] = $info;
                // A key brought back by an edit must not be removed by an old cleanup job.
                delete_option(self::delete_job_name($config['signature'], $key));
            }
            // Record all successful peers even if another request failed, and
            // retain every attempted key if deletion happened during the group.
            if (!self::attachment_exists($id)) {
                self::queue_delete($pending, array_map('strval', array_keys($pending['files'])));
                delete_option(self::JOB . 'retry_' . $id);
                return false;
            }
            update_post_meta($id, self::META, $pending);
            if (is_wp_error($error)) {
                if ('storage_budget' === $error->get_error_code()) {
                    self::defer_upload($id, $pending, $old, $job);
                    return false;
                }
                $pending['error'] = $error->get_error_message();
                update_post_meta($id, self::META, $pending);
                update_option(self::ERROR, $pending['error'], false);
                $job['next'] = time() + ($attempt < 2 ? HOUR_IN_SECONDS : ($attempt < 3 ? 6 : 24) * HOUR_IN_SECONDS);
                update_option(self::JOB . 'retry_' . $id, $job, false);
                return false;
            }
        }
        if (!self::more_time($start, self::BUDGET)) {
            self::defer_upload($id, $pending, $old, $job);
            return false;
        }
        self::retry_original($id);
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
        if (get_post_meta($id, self::DONE, true) !== self::upload_marker() || get_post_meta($id, self::GENERATION, true) !== $generation) {
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
     * Reschedule a deliberate deferral without counting it as a failed attempt.
     * Keep the pending inventory, including every unconfirmed attempted key.
     *
     * @param int $id Attachment ID.
     * @param array $pending Inventory saved before and during this upload.
     * @param array $old Inventory before this upload.
     * @param array $job Retry job.
     * @return void
     */
    private static function defer_upload($id, $pending, $old, $job) {
        $pending['attempts'] = isset($old['attempts']) ? (int) $old['attempts'] : 0;
        // This turn did not fail; keep only a failure from an earlier turn.
        $pending['state'] = isset($old['state']) && 'failed' === $old['state'] ? 'failed' : 'waiting';
        update_post_meta($id, self::META, $pending);
        update_post_meta($id, self::STATE, $pending['state']);
        delete_transient(self::COUNTS);
        $job['next'] = time() + 60;
        update_option(self::JOB . 'retry_' . $id, $job, false);
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
     * @param bool $original Keep each file's uploaded format.
     * @return array<string,string>
     */
    private static function map($id, $original = false) {
        // Pages and wp-admin can differ (build_map()), and a request can be both.
        $cache = get_current_blog_id() . ':' . (int) $id . ':' . self::serving() . ($original ? ':original' : '');
        if (!isset(self::$maps[$cache])) {
            if (count(self::$maps) >= 500) {
                // Bound memory in long WP-CLI runs over whole libraries.
                self::$maps = array();
            }
            self::$maps[$cache] = self::build_map($id, $original);
        }
        return self::$maps[$cache];
    }

    /**
     * Local to public URL map: a completed inventory on this connection,
     * plus every removed file, whose bucket object is its only copy (also
     * while a changed picture waits for its next upload).
     *
     * @param int $id Attachment ID.
     * @param bool $original Keep each file's uploaded format.
     * @return array<string,string>
     */
    private static function build_map($id, $original = false) {
        $config = self::config();
        list($gone, $gone_url) = self::gone_files($id);
        $files = $gone + self::completed_files($id);
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
            $target = !$original && isset($files[$key . '.webp']) ? $key . '.webp' : $key;
            $map[$local] = (isset($gone[$target]) ? $gone_url : $config['url']) . '/' . implode('/', array_map('rawurlencode', explode('/', $target))) . '?v=' . $files[$target]['version'];
            $encoded = trailingslashit($uploads['baseurl']) . implode('/', array_map('rawurlencode', explode('/', $site_path ? substr($relative, strlen($site_path) + 1) : $relative)));
            $map[$encoded] = $map[$local];
        }
        return $map;
    }

    /**
     * Files of a completed inventory on this connection.
     *
     * @param int $id Attachment ID.
     * @return array<string,array> Empty when the inventory is not complete here.
     */
    private static function completed_files($id) {
        $record = get_post_meta($id, self::META, true);
        $signature = self::config()['signature'];
        if (!is_array($record) || empty($record['files']) || 'uploaded' !== $record['state'] || $record['signature'] !== $signature) {
            return array();
        }
        // Private originals do not change public URLs; keep serving while queued.
        // Legacy inventories also stay usable while the queue adds AVIF files.
        return in_array(get_post_meta($id, self::DONE, true), array($signature, $signature . ':avif', self::upload_marker()), true) ? $record['files'] : array();
    }

    /**
     * Removed files and the public address they show from. Files removed
     * through another connection show on pages from the address they were
     * removed under; not in wp-admin or editors, where saving can put back
     * only this connection's addresses.
     *
     * @param int $id Attachment ID.
     * @return array{0:array<string,array>,1:string} Files and public address.
     */
    private static function gone_files($id) {
        $url = self::config()['url'];
        $gone = self::removed_files($id);
        if ($gone) {
            return array($gone, $url);
        }
        $removed = get_post_meta((int) $id, self::REMOVED, true);
        if (!is_array($removed) || empty($removed['files']) || !is_array($removed['files']) || empty($removed['url']) || !is_string($removed['url'])) {
            return array(array(), $url);
        }
        return array($removed['files'], untrailingslashit($removed['url']));
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
     * Resolve a format copy only from an exact, indexed bucket address.
     * No HTTP requests or guessed suffixes: the map names confirmed objects
     * and preserves each copy's own version, including after local removal.
     *
     * @param string $url Image address.
     * @param string $format WebP or AVIF extension.
     * @return string Copy address, or none.
     */
    public static function format_url($url, $format) {
        if (!in_array($format, array('webp', 'avif'), true) || '' === self::serving()) {
            return '';
        }
        $map = self::indexed_map($url);
        foreach ($map as $local => $remote) {
            if ($remote !== $url) {
                continue;
            }
            // The default bucket address can already be a WebP sibling.
            if (substr($local, -5) === '.webp' && isset($map[substr($local, 0, -5)]) && $map[substr($local, 0, -5)] === $url) {
                $local = substr($local, 0, -5);
            }
            if (isset($map[$local . '.' . $format])) {
                return $map[$local . '.' . $format];
            }
        }
        return '';
    }

    /**
     * Find the owning attachment's output map without scanning attachments.
     *
     * @param string $url Public image address.
     * @param bool $original Keep each file's uploaded format.
     * @return array<string,string> Candidate map; callers still match the exact URL.
     */
    private static function indexed_map($url, $original = false) {
        $config = self::config();
        $destinations = get_option(self::DESTINATIONS, array());
        $sources = array($config);
        if (!empty($destinations['previous'])) {
            $sources[] = $destinations['previous'];
        }
        foreach ($sources as $source) {
            $base = $source['url'] . '/';
            if (0 !== strpos($url, $base)) {
                continue;
            }
            $key = rawurldecode(preg_replace('/\?v=[a-f0-9]+$/', '', substr($url, strlen($base))) ?? '');
            $id = (int) get_option(self::URL_INDEX . hash('sha256', $source['signature'] . $key), 0);
            if (!$id || !self::serves($id)) {
                continue;
            }
            return self::map($id, $original);
        }
        return array();
    }

    /**
     * Serve confirmed originals to share previewers and schema consumers.
     * Reuse the rendering map, never guess an object from a bucket URL.
     *
     * @param string $url Rank Math image address.
     * @return string
     */
    public static function seo_image($url) {
        if (!is_string($url) || '' === $url || '' === self::serving()) {
            return $url;
        }
        // Schema references can name the same image with a fragment.
        $parts = explode('#', $url, 2);
        $image = $parts[0];
        $uploads = wp_upload_dir(null, false);
        if (0 === strpos($image, trailingslashit($uploads['baseurl']))) {
            $id = attachment_url_to_postid($image);
            if (!$id) {
                $full = preg_replace('/-\d+x\d+(?=\.[^.]+$)/', '', $image) ?? $image;
                $id = attachment_url_to_postid($full);
            }
            if (!$id || !self::serves($id)) {
                return $url;
            }
            $map = self::map($id, true);
            $local = $image;
        } else {
            $map = self::indexed_map($image, true);
            $local = array_search($image, $map, true);
            if (false === $local) {
                return $url;
            }
        }
        // Strip a generated format suffix only when its original is confirmed.
        $original = preg_replace('/\.(webp|avif)$/', '', $local) ?? $local;
        if (isset($map[$original])) {
            $local = $original;
        }
        return isset($map[$local]) ? $map[$local] . (isset($parts[1]) ? '#' . $parts[1] : '') : $url;
    }

    /**
     * Keep image nodes and their references in step without changing stored data.
     * Exact inventory matching leaves page IDs and external images untouched.
     *
     * @param array $data Rank Math JSON-LD graph or nested node.
     * @return array
     */
    public static function seo_schema($data) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::seo_schema($value);
            } elseif (is_string($value) && in_array($key, array('url', 'contentUrl', '@id', 'image', 'primaryImageOfPage'), true)) {
                $data[$key] = self::seo_image($value);
            }
        }
        return $data;
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
        if (!is_string($text) || !get_option(self::FLAG) || self::config()['missing']) {
            return $text;
        }
        $map = array();
        foreach (self::named_attachments($text) as $id) {
            if (self::removed($id)) {
                $map += self::map($id);
            }
        }
        return $map ? strtr($text, $map) : $text;
    }

    /**
     * Attachment IDs content names (wp-image-ID classes, "id":ID block attributes).
     *
     * @param string $text Content.
     * @return int[]
     */
    private static function named_attachments($text) {
        preg_match_all('/(?:wp-image-|"id":)(\d+)/', $text, $found);
        return array_filter(array_unique(array_map('intval', $found[1])));
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
        if ('' === self::serving()) {
            return $meta;
        }
        $uploads = wp_upload_dir(null, false);
        if (0 === strpos((string) $src, trailingslashit($uploads['baseurl']))) {
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
        $saved = get_option(self::DESTINATIONS, array());
        $destinations = array($config);
        if (!empty($saved['previous']['url'])) {
            $destinations[] = $saved['previous'];
        }
        foreach (isset($saved['retired']) ? $saved['retired'] : array() as $retired) {
            $destinations[] = $retired;
        }
        foreach ($destinations as $destination) {
            if (!empty($destination['url'])) {
                $data = self::reverse_destination($data, $destination);
            }
        }
        return $data;
    }

    /**
     * Reverse an exact indexed address from a current or former destination.
     *
     * @param array $data Slashed post data.
     * @param array $config Safe destination description.
     * @return array
     */
    private static function reverse_destination($data, $config) {
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
                return self::reverse_match($match, $config);
            }, $text) ?? $text;
            $data[$field] = wp_slash($text);
        }
        return $data;
    }

    /**
     * Reverse one exact, indexed URL while retaining JSON slash escaping.
     *
     * @param string[] $match Regex match.
     * @param array $config Public source description.
     * @return string
     */
    private static function reverse_match($match, $config) {
        $path = str_replace('\/', '/', $match[1]);
        $key = rawurldecode(preg_replace('/\?v=[a-f0-9]+$/', '', $path) ?? $path);
        $id = (int) get_option(self::URL_INDEX . hash('sha256', $config['signature'] . $key), 0);
        $relative = self::indexed_relative($id, $key, $config);
        $local = '' !== $relative ? self::site_local_url($relative) : '';
        if ('' === $local) {
            return $match[0];
        }
        return false !== strpos($match[0], '\/') ? str_replace('/', '\/', $local) : $local;
    }

    /**
     * Resolve only confirmed files owned by this attachment's current inventory.
     *
     * @param int $id Indexed attachment ID.
     * @param string $key Indexed key at the source destination.
     * @param array $config Source description.
     * @return string Relative path or none.
     */
    private static function indexed_relative($id, $key, $config) {
        if (!$id || !get_post($id)) {
            return '';
        }
        $record = get_post_meta($id, self::META, true);
        if (!is_array($record)) {
            return '';
        }
        $lookup = $key;
        if ($record['signature'] !== $config['signature']) {
            if (0 !== strpos($key, $config['prefix'])) {
                return '';
            }
            $lookup = self::inventory_prefix($record['files']) . substr($key, strlen($config['prefix']));
        }
        if (empty($record['files'][$lookup]['confirmed'])) {
            return '';
        }
        $relative = $record['files'][$lookup]['relative'];
        if (in_array(substr($relative, -5), array('.webp', '.avif'), true) && isset($record['files'][substr($lookup, 0, -5)])) {
            $relative = substr($relative, 0, -5);
        }
        return $relative;
    }

    /**
     * Reverse only this subsite's paths, never another site's uploads.
     *
     * @param string $relative Main-uploads-relative path.
     * @return string Local URL or none.
     */
    private static function site_local_url($relative) {
        $root = self::root();
        if (!$root) {
            return '';
        }
        $uploads = wp_upload_dir(null, false);
        $site_path = ltrim(substr(wp_normalize_path($uploads['basedir']), strlen($root['dir'])), '/');
        if ($site_path && 0 !== strpos($relative, $site_path . '/')) {
            return '';
        }
        return trailingslashit($uploads['baseurl']) . ($site_path ? substr($relative, strlen($site_path) + 1) : $relative);
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
        $rows = $wpdb->get_results($wpdb->prepare("SELECT CASE WHEN p.post_mime_type NOT LIKE %s THEN 'skipped' WHEN d.meta_value = %s THEN COALESCE(m.meta_value, 'failed') ELSE 'waiting' END AS state, COUNT(*) AS total, SUM(COALESCE(s.meta_value, 0)) AS partial FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s LEFT JOIN {$wpdb->postmeta} d ON p.ID = d.post_id AND d.meta_key = %s LEFT JOIN {$wpdb->postmeta} s ON p.ID = s.post_id AND s.meta_key = %s WHERE p.post_type = 'attachment' GROUP BY state", 'image/%', self::upload_marker(), self::STATE, self::DONE, self::SKIPPED));
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
        $counts += self::handover_counts();
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
        if ($config['originals_error']) {
            echo '<p>' . esc_html($config['originals_error']) . '</p>';
        }
        echo '<ul>';
        foreach (array('ENDPOINT', 'REGION', 'BUCKET', 'KEY_ID', 'SECRET', 'URL', 'PREFIX') as $suffix) {
            $name = 'SEOPROSTACK_MEDIA_STORAGE_' . $suffix;
            $set = defined($name) && is_string(constant($name)) && '' !== constant($name);
            $status = $set ? __('Set', 'seoprostack') : (in_array($suffix, array('REGION', 'PREFIX'), true) ? __('Using the default', 'seoprostack') : __('Not set', 'seoprostack'));
            echo '<li><code>' . esc_html($name) . '</code>: ' . esc_html($status) . '</li>';
        }
        echo '</ul>';
        foreach (self::destination_labels() as $label) {
            echo '<p>' . esc_html($label) . '</p>';
        }
        if (is_ssl() && 'https' !== wp_parse_url($config['url'], PHP_URL_SCHEME)) {
            echo '<p>' . esc_html__('Use an HTTPS public storage address to avoid mixed content.', 'seoprostack') . '</p>';
        }
        self::panel_queue($config);
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
     * Panel notes about removed files, and the queue counts.
     *
     * @param array $config Connection settings (config()).
     */
    private static function panel_queue($config) {
        $restoring = self::panel_removed($config);
        if (!self::ready() && !self::restoring()) {
            return;
        }
        $counts = self::counts();
        foreach ($counts as $state => $count) {
            echo '<p>' . esc_html($state . ': ' . number_format_i18n($count)) . '</p>';
        }
        if ($counts['waiting'] > 0 || $restoring) {
            // Recover a lost wake-up without waking an idle queue on every view.
            self::schedule(60);
        }
    }

    /**
     * Panel notes about removed files.
     *
     * @param array $config Connection settings (config()).
     * @return bool Whether removed files are coming back in the background.
     */
    private static function panel_removed($config) {
        if (!get_option(self::FLAG)) {
            return false;
        }
        if ($config['missing']) {
            echo '<p>' . esc_html__('Some pictures have no local files. Set the constants again, or add the missing-file rule from the read me, so they keep showing.', 'seoprostack') . '</p>';
        }
        if (!self::restoring()) {
            return false;
        }
        if (!self::restorable(1, PHP_INT_MAX, true)) {
            // Only stuck pictures are left: say so instead of waiting on them.
            echo '<p>' . esc_html__('Some removed files could not be brought back (see the error below), so those pictures still show from the bucket. Fix the cause, then run wp seoprostack media-storage restore --all.', 'seoprostack') . '</p>';
            return false;
        }
        echo '<p>' . esc_html__('Bringing removed files back in the background; until then, those pictures show from the bucket.', 'seoprostack') . '</p>';
        return true;
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
        if (get_post_meta($post->ID, self::DONE, true) !== self::upload_marker()) {
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
     * : status, test, upload, restore, adopt, migrate or forget-previous.
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
     * [--dry-run]
     * : adopt/migrate: inspect without uploading or relabelling.
     *
     * [--verify-hash]
     * : adopt: also GET each object and compare its SHA-256.
     *
     * [--from-start]
     * : adopt/migrate: ignore the saved cursor and begin with the newest record.
     *
     * [--delete-objects]
     * : forget-previous: explicitly delete archived previous objects.
     *
     * [--repair]
     * : collisions: rename earlier attachments, copying only verified local bytes.
     *
     * @param string[] $args Positional arguments.
     * @param array $assoc Named arguments.
     */
    public static function cli($args, $assoc) {
        $action = isset($args[0]) ? $args[0] : 'status';
        if ('status' === $action) {
            self::cli_status();
            return;
        }
        if ('restore' === $action) {
            self::cli_restore($assoc);
            return;
        }
        if ('collisions' === $action) {
            self::cli_collisions($assoc);
            return;
        }
        if (!self::ready()) {
            WP_CLI::error('Switch on Media in cloud storage and set its constants first.');
        }
        if ('adopt' === $action || 'migrate' === $action) {
            self::cli_handover($assoc, 'migrate' === $action);
            return;
        }
        if ('forget-previous' === $action) {
            self::cli_forget_previous($assoc);
            return;
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
            WP_CLI::error('Use status, test, upload, restore, adopt, migrate, collisions or forget-previous.');
        }
        $limit = isset($assoc['limit']) ? max(1, min(1000, (int) $assoc['limit'])) : 10;
        if (isset($assoc['retry-failed'])) {
            update_option(self::JOB . 'originalcursor', '', false);
            self::retry_stranded($limit);
            foreach (self::jobs($limit, '', 'retry_') as $name) {
                $job = get_option($name);
                if (is_array($job) && 'retry' === $job['kind'] && $job['signature'] === self::config()['signature']) {
                    self::queue((int) $job['id']);
                } elseif (is_array($job) && 'original_retry' === $job['kind'] && $job['signature'] === self::config()['signature']) {
                    $job['next'] = 0;
                    update_option($name, $job, false);
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
            // Local copies kept after a failed check, and pictures whose
            // restore failed, are looked at again.
            delete_metadata('post', 0, self::LOCAL, 'kept:' . self::config()['signature'], true);
            delete_metadata('post', 0, self::STUCK, '', true);
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
     * Recover failed uploads whose retry job was lost, newest first.
     * Page past refusals without loading the whole media library.
     *
     * @param int $limit Most attachments to requeue.
     */
    private static function retry_stranded($limit) {
        global $wpdb;
        $before = PHP_INT_MAX;
        $queued = 0;
        do {
            // Keep ID order: starting from meta_key would sort all failed rows
            // before LIMIT instead of walking the posts primary-key range.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded ID-paged lookup; meta joins use post_id and jobs use option_name's unique index.
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT STRAIGHT_JOIN p.ID FROM {$wpdb->posts} p FORCE INDEX (PRIMARY) INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'failed' INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value = %s LEFT JOIN {$wpdb->options} j ON j.option_name = CONCAT(%s, p.ID) WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.ID < %d AND j.option_id IS NULL ORDER BY p.ID DESC LIMIT %d",
                self::STATE, self::DONE, self::upload_marker(), self::JOB . 'retry_', 'image/%', $before, $limit - $queued
            )));
            foreach ($ids as $id) {
                $before = $id;
                $record = get_post_meta($id, self::META, true);
                // Refusals deliberately have no retry job: repair them first.
                // Legacy missing-byte repairs have an empty inventory and durable
                // collision_source evidence, independent of the saved error's locale.
                // Other legacy refusals still have live shared inventory claims.
                $legacy_refusal = is_array($record) && empty($record['collision_refused']) && ((isset($record['collision_source']) && empty($record['files'])) || self::conflicting_files($id, $record['files'] ?? array()));
                if ($legacy_refusal) {
                    $record['collision_refused'] = true;
                    update_post_meta($id, self::META, $record);
                }
                if (!empty($record['collision_refused']) || $legacy_refusal || self::removed_elsewhere($id)) {
                    continue;
                }
                self::queue($id);
                ++$queued;
            }
        } while ($ids && $queued < $limit);
    }

    /**
     * Print the connection, Remove local copies and the queue counts.
     */
    private static function cli_status() {
        WP_CLI::log(self::ready() ? 'Configured and on.' : 'Off or missing constants.');
        foreach (self::destination_labels() as $label) {
            WP_CLI::log($label);
        }
        $missing = self::config()['missing'];
        if ($missing) {
            WP_CLI::log('Missing or invalid: ' . implode(', ', $missing));
        } else {
            WP_CLI::log(self::removing() ? 'Remove local copies after upload: on.' : 'Remove local copies after upload: off.');
        }
        foreach (self::counts() as $state => $count) {
            WP_CLI::log($state . ': ' . $count);
        }
        $config = self::config();
        WP_CLI::log('Originals bucket: ' . ($config['originals_bucket'] ?: 'not configured (originals stay local)'));
        WP_CLI::log($config['originals_error']);
        foreach (self::original_counts() as $state => $count) {
            WP_CLI::log('originals ' . $state . ': ' . $count);
        }
        WP_CLI::log((string) get_option(self::ERROR, ''));
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
        $one = self::cli_restore_target($assoc);
        $counts = array('restored' => 0, 'failed' => 0);
        $before = PHP_INT_MAX;
        do {
            $ids = $one ? array($one) : self::restorable(20, $before);
            $lease = self::cli_lease();
            try {
                $before = self::cli_restore_page($ids, $counts, $before);
            } finally {
                self::release($lease);
            }
        } while (!$one && $ids);
        self::cli_restore_originals($one, $counts);
        if (!self::restorable(1)) {
            delete_option(self::FLAG);
        }
        if (self::removing()) {
            WP_CLI::warning('Remove local copies after upload is on: restored pictures stay on this server until they change. Switch it off to keep every file here.');
        }
        $message = sprintf('Brought back the files of %d attachment(s); %d failed.', $counts['restored'], $counts['failed']);
        if ($counts['failed']) {
            WP_CLI::error($message);
        }
        WP_CLI::success($message);
    }

    /**
     * Explicit restore also keeps private originals here until settings change.
     *
     * @param int $one Attachment ID, or zero for all.
     * @param array{restored:int,failed:int} $counts Running counts.
     */
    private static function cli_restore_originals($one, &$counts) {
        $after = '';
        do {
            $names = self::jobs(20, $after, 'original_');
            foreach ($names as $name) {
                $after = $name;
                $job = get_option($name);
                if (!is_array($job) || !empty($job['delete']) || ($one && (int) $job['id'] !== $one)) {
                    continue;
                }
                $record = SEOProStack_Watermark_Images::record((int) $job['id']);
                if (empty($record['remote']) || $record['backup'] !== $job['record']['backup']) {
                    continue;
                }
                $job['keep'] = get_option(self::JOB . 'originalkeep', 'initial');
                update_option($name, $job, false);
                $result = self::ensure_original_local($record);
                if (is_wp_error($result)) {
                    ++$counts['failed'];
                    WP_CLI::warning($result->get_error_message());
                } else {
                    ++$counts['restored'];
                }
            }
        } while ($names);
    }

    /**
     * Which attachment restore works on.
     *
     * @param array $assoc Named arguments.
     * @return int Attachment ID; 0 for --all.
     */
    private static function cli_restore_target($assoc) {
        if (!isset($assoc['attachment'])) {
            if (!isset($assoc['all'])) {
                WP_CLI::error('Use --attachment=<id> or --all.');
            }
            return 0;
        }
        $one = (int) $assoc['attachment'];
        if ($one <= 0 || 'attachment' !== get_post_type($one)) {
            WP_CLI::error('No attachment with that ID.');
        }
        return $one;
    }

    /**
     * Take the batch lease, waiting up to a minute for a running batch.
     *
     * @return string Lease.
     */
    private static function cli_lease() {
        for ($try = 0; $try < 30; ++$try) {
            $lease = self::lease();
            if ('' !== $lease) {
                return $lease;
            }
            sleep(2);
        }
        WP_CLI::error('A storage batch is still running. Try again in a few minutes.');
        return '';
    }

    /**
     * Bring back one page of attachments' files.
     *
     * @param int[] $ids Attachment IDs, newest first.
     * @param array{restored:int,failed:int} $counts Running counts.
     * @param int $before Paging position.
     * @return int Next paging position.
     */
    private static function cli_restore_page($ids, &$counts, $before) {
        foreach ($ids as $id) {
            $before = $id;
            if (!self::removed($id)) {
                continue;
            }
            $result = self::restore_attachment($id);
            if (is_wp_error($result)) {
                ++$counts['failed'];
                WP_CLI::warning(sprintf('%d: %s', $id, $result->get_error_message()));
            } else {
                ++$counts['restored'];
            }
        }
        return $before;
    }
}
