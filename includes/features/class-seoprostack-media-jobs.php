<?php
/**
 * Journaled, bounded media maintenance on the storage worker's lease and hook.
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

class SEOProStack_Media_Jobs {
    // Existing uninstall already removes this namespace and the storage hook.
    const PREFIX = 'seoprostack_storage_job_media_';
    const KINDS = array('remark', 'adopt', 'originals');

    /** Register the command even when storage is off, so status remains readable. */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack media-jobs', array(__CLASS__, 'cli'));
        }
    }

    /** Wake the existing worker; schedule before I/O to survive killed PHP. */
    private static function schedule() {
        if (!wp_next_scheduled(SEOProStack_Media_Storage::HOOK)) {
            wp_schedule_single_event(time() + 60, SEOProStack_Media_Storage::HOOK);
        }
    }

    /**
     * wp seoprostack media-jobs start|status|stop remark|adopt|originals
     *
     * Start resumes a stopped/failed journal; completed jobs start a new scan.
     * Status is read-only. Stop waits for the current lease rather than racing
     * its journal writer; already completed attachments are not rolled back.
     *
     * @param array $args Positional arguments.
     * @param array $assoc Options (none).
     */
    public static function cli($args, $assoc) {
        $op = $args[0] ?? 'status';
        $kinds = isset($args[1]) ? array($args[1]) : self::KINDS;
        if (!in_array($op, array('start', 'status', 'stop'), true) || array_diff($kinds, self::KINDS) || ('status' !== $op && !isset($args[1]))) {
            WP_CLI::error('Use media-jobs start|status|stop remark|adopt|originals.');
            return;
        }
        $lease = 'status' === $op ? '' : SEOProStack_Media_Storage::lease();
        if ('status' !== $op && '' === $lease) {
            WP_CLI::error('A media batch is running; repeat after its bounded turn.');
            return;
        }
        try {
            foreach ($kinds as $kind) {
                $name = self::PREFIX . $kind;
                $job = get_option($name, array());
                if ($job) {
                    $job['failed'] = self::failure_count($kind);
                }
                if ('start' === $op) {
                    if (!$job || 'done' === $job['state']) {
                        $job = array('state' => 'waiting', 'before' => PHP_INT_MAX, 'done' => 0, 'failed' => 0, 'phase' => 'scan', 'pass' => 0, 'retry_cursor' => '', 'inflight' => 0, 'tries' => 0, 'recent' => array(), 'started' => time(), 'destination' => SEOProStack_Media_Storage::config()['signature']);
                        $latest = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true));
                        $job['before'] = $latest ? (int) $latest[0] + 1 : 1;
                        $job['originals_destination'] = SEOProStack_Media_Storage::config()['originals_bucket'];
                        if ('remark' === $kind) {
                            $context = SEOProStack_Watermark_Images::remark_context();
                            if (is_wp_error($context)) {
                                WP_CLI::error($context->get_error_message());
                                return;
                            }
                            $job = array_merge($job, $context);
                        }
                    }
                    $job['state'] = 'waiting';
                    $job['recent'] = array();
                    $job['pass'] = 0;
                    $job['retry_cursor'] = '';
                    update_option($name, $job, false);
                    self::schedule();
                } elseif ('stop' === $op && $job) {
                    $job['state'] = 'stopped';
                    update_option($name, $job, false);
                }
                WP_CLI::log($kind . ': ' . wp_json_encode($job ?: array('state' => 'not started')));
            }
        } finally {
            if ($lease) {
                SEOProStack_Media_Storage::release($lease);
            }
        }
    }

    /**
     * Process one attachment, never advancing the cursor before its result is
     * durable. A killed operation retries in place, then joins the tail queue.
     *
     * @return bool Whether a maintenance job used this worker turn.
     */
    public static function run_batch() {
        foreach (self::KINDS as $kind) {
            $name = self::PREFIX . $kind;
            $job = get_option($name, array());
            if (!$job || 'waiting' !== $job['state']) {
                continue;
            }
            self::schedule();
            $lease = SEOProStack_Media_Storage::lease();
            if (!$lease) {
                return true;
            }
            try {
                // Re-read after acquiring the lease: start/stop uses it too.
                wp_cache_delete($name, 'options');
                $job = get_option($name, array());
                if ($job && 'waiting' === $job['state']) {
                    $start = microtime(true);
                    for ($part = 0; $part < 6 && 'waiting' === $job['state'] && microtime(true) - $start < 20; ++$part) {
                        self::turn($kind, $job);
                        update_option($name, $job, false);
                    }
                }
            } finally {
                SEOProStack_Media_Storage::release($lease);
            }
            return true;
        }
        return false;
    }

    /**
     * Select and journal one bounded step under the shared lease.
     *
     * @param string $kind Kind.
     * @param array $job Journal, updated in place.
     */
    private static function turn($kind, &$job) {
        $prefix = self::PREFIX . $kind . '_failed_';
        $job['failed'] = self::failure_count($kind);
        if ($job['destination'] !== SEOProStack_Media_Storage::config()['signature']) {
            $job['state'] = 'failed';
            $job['error'] = 'Storage destination changed; resume only under the original connection.';
            return;
        }
        if ($job['originals_destination'] !== SEOProStack_Media_Storage::config()['originals_bucket']) {
            $job['state'] = 'failed';
            $job['error'] = 'Private originals destination changed; restore the original connection before resuming.';
            return;
        }
        $id = (int) $job['inflight'];
        if (!$id) {
            $id = self::next_id($kind, $job, $prefix);
            if (!$id) {
                return;
            }
            $job['inflight'] = $id;
            $job['tries'] = 0;
        }
        ++$job['tries'];
        update_option(self::PREFIX . $kind, $job, false);
        if (get_option(self::PREFIX . $kind) !== $job) {
            $job['state'] = 'failed';
            $job['error'] = 'Cannot save the media journal; no files were changed.';
            return;
        }
        $result = $job['tries'] > 3 ? new WP_Error('media_interrupted', 'Repeatedly interrupted operation.') : self::step($kind, $id, $job);
        if (is_wp_error($result)) {
            // Save the refusal before advancing; retries are separate options,
            // not an unbounded array in the journal or an autoloaded option.
            update_option($prefix . $id, $id, false);
            $job['error'] = $result->get_error_message();
        } else {
            delete_option($prefix . $id);
            ++$job['done'];
        }
        // The indexed refusal rows are authoritative even if PHP died between
        // changing one and committing this journal. Counters never go negative.
        $job['failed'] = self::failure_count($kind);
        $specific = array('watermark_never', 'watermark_no_original', 'watermark_small', 'watermark_changed', 'watermark_original_changed', 'watermark_incomplete', 'storage_original_read');
        $job['recent'][] = is_wp_error($result) && !in_array($result->get_error_code(), $specific, true) ? 1 : 0;
        $job['recent'] = array_slice($job['recent'], -20);
        $job['inflight'] = 0;
        if ('scan' === $job['phase']) {
            $job['before'] = $id;
        } else {
            $job['retry_cursor'] = $prefix . $id;
        }
        // Bursts below a complete window never trip the circuit breaker.
        if (20 === count($job['recent']) && array_sum($job['recent']) >= 18) {
            $job['state'] = 'failed';
            $job['error'] = 'Systematic failure (18 of the last 20 operations); ' . ($job['error'] ?? '');
        }
        if (is_wp_error($result) && in_array($result->get_error_code(), array('storage_off', 'storage_originals_config', 'watermark_off'), true)) {
            $job['state'] = 'failed';
        }
    }

    /**
     * Count the indexed refusal queue, not a separately committed counter.
     *
     * @param string $kind Kind.
     * @return int
     */
    private static function failure_count($kind) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own queue's option_name index, never attachment metadata.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::PREFIX . $kind . '_failed_') . '%'));
    }

    /**
     * Indexed, bounded cursors for the library and the refusal tail.
     *
     * @param string $kind Kind.
     * @param array $job Journal.
     * @param string $prefix Refusal option prefix.
     * @return int Attachment ID, or zero after a phase transition.
     */
    private static function next_id($kind, &$job, $prefix) {
        global $wpdb;
        if ('scan' === $job['phase']) {
            $meta = 'adopt' === $kind ? SEOProStack_Media_Storage::META : SEOProStack_Watermark_Images::META;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- descending primary-key cursor; existence uses the post_id index, never meta-value sorting.
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID < %d AND p.post_type = 'attachment' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s) ORDER BY p.ID DESC LIMIT 1", $job['before'], $meta));
            if ($id) {
                return $id;
            }
            $job['phase'] = 'retry';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- option_name's unique index, one refusal per turn.
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT 1", $wpdb->esc_like($prefix) . '%', $job['retry_cursor']));
        if (!$id) {
            if ($job['failed'] && ++$job['pass'] < 3) {
                $job['retry_cursor'] = '';
            } else {
                $job['state'] = $job['failed'] ? 'failed' : 'done';
                $job['ended'] = time();
            }
        }
        return $id;
    }

    /**
     * Perform work only for eligible attachments, preserving existing safety.
     *
     * @param string $kind Kind.
     * @param int $id ID.
     * @param array $job Frozen re-mark context.
     * @return true|WP_Error
     */
    private static function step($kind, $id, $job) {
        if ('attachment' !== get_post_type($id)) {
            return true;
        }
        if ('remark' === $kind) {
            $uploads = wp_get_upload_dir();
            $free = disk_free_space($uploads['basedir']);
            if (false === $free || $free < 128 * MB_IN_BYTES) {
                return new WP_Error('media_disk', 'Re-marking requires at least 128 MiB free in uploads; local copies were kept.');
            }
            $result = SEOProStack_Watermark_Images::remark_step($id, $job);
            if (true !== $result) {
                return is_wp_error($result) ? $result : true;
            }
        }
        return SEOProStack_Media_Storage::media_job_step($kind, $id);
    }
}
