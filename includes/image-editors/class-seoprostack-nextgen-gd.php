<?php
/**
 * GD image editor that applies SEO Pro Stack's AVIF encoder speed.
 *
 * Loaded only while WebP and AVIF images makes a copy, after WordPress has
 * loaded its image editor classes.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.146
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Nextgen_GD extends WP_Image_Editor_GD {

    /**
     * Save AVIF with GD's fourth imageavif() speed argument.
     *
     * @param resource|GdImage $image     Image to save.
     * @param string|null      $filename  Destination file.
     * @param string|null      $mime_type Destination MIME type.
     * @return array|WP_Error
     */
    protected function _save($image, $filename = null, $mime_type = null) {
        list($filename, $extension, $mime_type) = $this->get_output_format($filename, $mime_type);
        if ('image/avif' !== $mime_type) {
            return parent::_save($image, $filename, $mime_type);
        }
        if (!$filename) {
            $filename = $this->generate_filename(null, null, $extension);
        }
        if (function_exists('imageinterlace')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
            imageinterlace($image, apply_filters('image_save_progressive', false, $mime_type));
        }
        if (!function_exists('imageavif') || !$this->make_image($filename, 'imageavif', array($image, $filename, $this->get_quality(), SEOProStack_Nextgen_Images::avif_speed()))) {
            return new WP_Error('image_save_error', __('Image Editor Save Failed', 'seoprostack'));
        }
        $stat  = stat(dirname($filename));
        $perms = $stat['mode'] & 0000666;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WordPress's own GD save behavior.
        chmod($filename, $perms);
        return array(
            'path'      => $filename,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
            'file'      => wp_basename(apply_filters('image_make_intermediate_size', $filename)),
            'width'     => $this->size['width'],
            'height'    => $this->size['height'],
            'mime-type' => $mime_type,
            'filesize'  => wp_filesize($filename),
        );
    }
}
