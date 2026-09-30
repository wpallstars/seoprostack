<?php
/**
 * GD image editor that can lay a watermark over the picture.
 *
 * Loaded by SEOProStack_Watermark_Images only when a picture is marked, after
 * WordPress's image editor classes.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Watermark_GD extends WP_Image_Editor_GD {

    /**
     * Lay a picture over this one.
     *
     * @param string $mark_file Watermark file.
     * @param int    $width     Watermark width on the picture.
     * @param int    $height    Watermark height on the picture.
     * @param int    $x         Left edge.
     * @param int    $y         Top edge.
     * @param float  $opacity   0 to 1.
     * @return true|WP_Error
     */
    public function stamp($mark_file, $width, $height, $x, $y, $opacity) {
        $data = file_get_contents($mark_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        $mark = $data ? @imagecreatefromstring($data) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is handled.
        if (!$mark) {
            return new WP_Error('watermark_mark', __('The watermark picture could not be read.', 'seoprostack'));
        }
        if (!imageistruecolor($mark)) {
            imagepalettetotruecolor($mark);
        }

        $width  = max(1, (int) $width);
        $height = max(1, (int) $height);
        $scaled = wp_imagecreatetruecolor($width, $height);
        imagealphablending($scaled, false);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $mark, 0, 0, 0, 0, $width, $height, imagesx($mark), imagesy($mark));

        $opacity = max(0.0, min(1.0, (float) $opacity));
        if ($opacity < 1.0) {
            // Scale each pixel's own transparency, so soft edges stay soft.
            for ($py = 0; $py < $height; $py++) {
                for ($px = 0; $px < $width; $px++) {
                    $colour = imagecolorat($scaled, $px, $py);
                    $alpha  = ($colour >> 24) & 0x7F;
                    $alpha  = 127 - (int) round((127 - $alpha) * $opacity);
                    imagesetpixel($scaled, $px, $py, ($colour & 0xFFFFFF) | ($alpha << 24));
                }
            }
        }

        if (!imageistruecolor($this->image)) {
            imagepalettetotruecolor($this->image);
        }
        imagealphablending($this->image, true);
        imagecopy($this->image, $scaled, (int) $x, (int) $y, 0, 0, $width, $height);
        // As WordPress loads it: keep transparency when saving.
        imagealphablending($this->image, false);
        imagesavealpha($this->image, true);
        return true;
    }
}
