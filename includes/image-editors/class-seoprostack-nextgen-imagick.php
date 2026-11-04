<?php
/**
 * Imagick image editor that applies SEO Pro Stack's AVIF encoder speed.
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

class SEOProStack_Nextgen_Imagick extends WP_Image_Editor_Imagick {

    /**
     * Save an image, setting libheif's AVIF speed after the output format is
     * known. WordPress only does this when the source was already AVIF.
     *
     * @param Imagick      $image     Image to save.
     * @param string|null  $filename  Destination file.
     * @param string|null  $mime_type Destination MIME type.
     * @return array|WP_Error
     */
    protected function _save($image, $filename = null, $mime_type = null) {
        list(, , $output_mime) = $this->get_output_format($filename, $mime_type);
        if ('image/avif' === $output_mime) {
            try {
                $this->image->setOption('heic:speed', (string) SEOProStack_Nextgen_Images::avif_speed());
            } catch (Exception $e) {
                return new WP_Error('image_save_error', $e->getMessage(), $filename);
            }
        }
        return parent::_save($image, $filename, $mime_type);
    }
}
