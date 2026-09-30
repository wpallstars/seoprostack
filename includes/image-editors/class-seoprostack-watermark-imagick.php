<?php
/**
 * Imagick image editor that can lay a watermark over the picture.
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

class SEOProStack_Watermark_Imagick extends WP_Image_Editor_Imagick {

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
        try {
            $mark = new Imagick();
            $mark->readImage($mark_file);
            $mark->setIteratorIndex(0);
            $mark->resizeImage(max(1, (int) $width), max(1, (int) $height), Imagick::FILTER_LANCZOS, 1);
            if ($mark->getImageColorspace() !== $this->image->getImageColorspace()) {
                $mark->transformImageColorspace($this->image->getImageColorspace());
            }
            if (!$mark->getImageAlphaChannel()) {
                $mark->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            }
            $opacity = max(0.0, min(1.0, (float) $opacity));
            if ($opacity < 1.0) {
                $mark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
            }
            $this->image->compositeImage($mark, Imagick::COMPOSITE_OVER, (int) $x, (int) $y);
            $mark->clear();
        } catch (Exception $e) {
            return new WP_Error('watermark_imagick', $e->getMessage());
        }
        return true;
    }
}
