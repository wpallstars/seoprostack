<?php
/**
 * Site templates: images shipped beneath site-templates/{slug}/images/.
 *
 * Imported attachments carry _seoprostack_template_key. Only an untouched
 * placeholder advances to a new image, as a new attachment; undo detaches
 * ownership but keeps the media so links never break.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Site_Template_Media {

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $key Image key.
     * @param array                         $image Manifest image.
     * @return true|WP_Error
     */
    public static function apply($run, $key, $image) {
        $old = $run->record['images'][$key] ?? null;
        $status = $old ? self::kept($old, $key, $image) : '';
        if ('' !== $status) {
            $run->report('images/' . $key, $status);
            return true;
        }
        $file = SEOPROSTACK_DIR . 'site-templates/' . $run->record['slug'] . '/images/' . $image['filename'];
        if (!is_readable($file) || hash_file('sha256', $file) !== $image['sha256']) {
            return new WP_Error('template_image', 'Missing image or SHA-256 mismatch: ' . $key);
        }
        $run->report('images/' . $key, $old ? 'updated' : 'added');
        return $run->dry ? true : self::import_owned($run, $key, $file, $image);
    }

    /**
     * Journal, import and own a new attachment.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $key Image key.
     * @param string                        $file Verified shipped file.
     * @param array                         $image Manifest image.
     * @return true|WP_Error
     */
    private static function import_owned($run, $key, $file, $image) {
        $run->journal('retained_media', $key, null, null);
        $id = self::import($file, $image['filename']);
        if (is_wp_error($id)) {
            return $id;
        }
        update_post_meta($id, SEOProStack_Site_Templates::META, 'images/' . $key);
        $imported = get_attached_file($id);
        if (!$imported || !is_readable($imported)) {
            return new WP_Error('template_image', 'Imported image has no readable attachment.');
        }
        $run->record['images'][$key] = array('id' => $id, 'sha256' => hash_file('sha256', $imported), 'source_sha256' => $image['sha256'], 'placeholder' => !empty($image['placeholder']));
        $run->save();
        return true;
    }

    /**
     * Replace {{image:key}} tokens with the imported attachment URLs.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $markup Markup with local image tokens.
     * @return string|null Null when any image is unavailable.
     */
    public static function tokens($run, $markup) {
        $missing = false;
        $out = preg_replace_callback('/\{\{image:([a-z0-9_-]+)\}\}/', function ($match) use ($run, &$missing) {
            $id = $run->record['images'][$match[1]]['id'] ?? 0;
            $url = $id ? wp_get_attachment_url($id) : false;
            if (!$url) {
                $missing = true;
            }
            return $url ? esc_url($url) : '';
        }, $markup);
        return $missing ? null : $out;
    }

    /**
     * Why an owned image stays as it is, or '' when it may advance.
     *
     * @param array  $old Ownership record.
     * @param string $key Image key.
     * @param array  $image Manifest image.
     * @return string
     */
    private static function kept($old, $key, $image) {
        if (!self::still_ours(get_post($old['id']), $key)) {
            return 'removed-on-site';
        }
        $file = get_attached_file($old['id']);
        $readable = $file && is_readable($file);
        $same = $readable && hash_file('sha256', $file) === $old['sha256'];
        if (!$same || empty($old['placeholder']) || ($old['source_sha256'] ?? $old['sha256']) === $image['sha256']) {
            return $same ? 'same' : ($readable ? 'edited-on-site' : 'removed-on-site');
        }
        return '';
    }

    /** @param array $item Manifest image. @return string The problem with its filename or SHA-256, or ''. */
    public static function item_error($item) {
        $filename = $item['filename'] ?? '';
        $sha = $item['sha256'] ?? null;
        $file_ok = is_string($filename) && basename($filename) === $filename && preg_match('/\.(png|jpe?g|webp|gif|avif)$/iD', $filename);
        $sha_ok = is_string($sha) && preg_match('/^[a-f0-9]{64}$/D', $sha);
        return $file_ok && $sha_ok ? '' : 'Invalid image filename or SHA-256.';
    }

    /** @param WP_Post|null $attachment Owned attachment. @param string $key Image key. @return bool */
    private static function still_ours($attachment, $key) {
        return $attachment && 'attachment' === $attachment->post_type && 'trash' !== $attachment->post_status && get_post_meta($attachment->ID, SEOProStack_Site_Templates::META, true) === 'images/' . $key;
    }

    /** @param string $file Shipped file. @param string $filename Name. @return int|WP_Error Attachment. */
    private static function import($file, $filename) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $temp = wp_tempnam($filename);
        if (!$temp || !copy($file, $temp)) {
            return new WP_Error('template_image', 'Unable to prepare image import.');
        }
        $id = media_handle_sideload(array('name' => $filename, 'tmp_name' => $temp), 0);
        if (is_wp_error($id)) {
            wp_delete_file($temp);
        }
        return $id;
    }
}
