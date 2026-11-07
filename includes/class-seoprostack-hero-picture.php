<?php
/**
 * A background picture's files, for the Kadence Blocks row a page opens
 * with (SEOProStack_Kadence_Hero, GH#1221): the picture the row names and
 * its smaller copies of the same proportions that can be served.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.150
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Hero_Picture {

    /**
     * A picture's proportions and the files on offer, narrowest first: its
     * smaller copies of the same proportions, then the picture the row
     * names. Each is width, address, image-set() of its AVIF and WebP
     * copies (or ''), the address to preload when not the same, and that
     * one's MIME type. Addresses are the ones pages get, through Media in
     * cloud storage's mapping when it serves the picture. Null when the
     * address is not one of the attachment's files. Without an ID, the
     * attachment is looked up from the address (url_id()).
     *
     * @param mixed $id  Attachment ID.
     * @param mixed $url Address the row names.
     * @return array{w:int,h:int,c:array<int,array>}|null
     */
    public static function get($id, $url) {
        $id   = is_numeric($id) && (int) $id > 0 ? (int) $id : self::url_id($url);
        $meta = self::meta($id, $url);
        if (!$meta) {
            return null;
        }
        $sizes = (array) ($meta['sizes'] ?? array());
        list($width, $height) = self::named($meta, $sizes, wp_basename(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH))));
        if ($width < 1 || $height < 1) {
            return null;
        }
        $copies   = self::copies($id, $sizes, $width, $height);
        $copies[] = self::file($width, self::served_url($url, $id));
        return array(
            'w' => $width,
            'h' => $height,
            'c' => $copies,
        );
    }

    /**
     * A picture as the sizing scripts take it (SEOProStack_Kadence_Hero,
     * SEOProStack_Kadence_Slider): each file's address written as CSS's
     * url(…), and its empty fields at the end left out. LiteSpeed Cache's
     * WebP replacement rewrites every url() in the page whose picture has a
     * .webp (or .avif) file beside it, on the pages where it rewrites the
     * block's CSS, so a script then sets, and preloads, the same copy that
     * CSS would name (GH#1333). The addresses can go in url() as they are
     * (css_url()).
     *
     * @param array $picture As get() gives it.
     * @return array{w:int,h:int,c:array<int,array>}
     */
    public static function for_script(array $picture) {
        $files = array();
        foreach ($picture['c'] as $file) {
            $entry = array($file[0], 'url(' . $file[1] . ')', $file[2], $file[3], $file[4]);
            while (count($entry) > 2 && '' === end($entry)) {
                array_pop($entry);
            }
            $files[] = $entry;
        }
        return array(
            'w' => $picture['w'],
            'h' => $picture['h'],
            'c' => $files,
        );
    }

    /**
     * The attachment an address names, for a block that saved no ID
     * (GH#1347): Kadence rows from imported templates, or with a picture
     * given by address, have bgImg but no bgImgID. attachment_url_to_postid()
     * matches this site's uploads address of an attachment's own file (or
     * its -scaled one), so other addresses, a CDN's or a size copy's, stay
     * 0 and the picture stays as the block names it. Kept per address for a
     * day in the object cache, 0 too, so each picture is looked up once
     * while the cache holds it.
     *
     * @param mixed $url Address the block names.
     * @return int
     */
    private static function url_id($url) {
        if (!self::css_url($url)) {
            return 0;
        }
        $key = 'hero_url_id_' . substr(hash('sha256', $url), 0, 32);
        $id  = wp_cache_get($key, 'seoprostack');
        if (false === $id) {
            $id = (int) attachment_url_to_postid($url);
            wp_cache_set($key, $id, 'seoprostack', DAY_IN_SECONDS);
        }
        return (int) $id;
    }

    /**
     * An attachment's metadata, when the row's address can go in CSS, the
     * metadata has its file and the address is in its folder; null when
     * not. A missing size is caught by get().
     *
     * @param int   $id  Attachment ID.
     * @param mixed $url Address the row names.
     * @return array|null
     */
    private static function meta($id, $url) {
        $meta = $id > 0 && self::css_url($url) ? wp_get_attachment_metadata($id) : false;
        return is_array($meta) && !empty($meta['file']) && self::in_folder($meta['file'], $url) ? $meta : null;
    }

    /**
     * Whether an address is in the attachment's uploads folder (its year
     * and month, when uploads use them), so a file of the same name in
     * another folder is not taken for it. Only the folder's end is
     * compared: a CDN or cloud storage can serve it under another start.
     *
     * @param string $file Attachment file, relative to uploads.
     * @param string $url  Address the row names.
     * @return bool
     */
    private static function in_folder($file, $url) {
        $dir  = dirname((string) $file);
        $tail = '.' === $dir ? '' : '/' . $dir;
        return '' === $tail || substr(dirname(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH))), -strlen($tail)) === $tail;
    }

    /**
     * Width and height of the file an address names: the attachment's own
     * file or one of its sizes. Zero when neither.
     *
     * @param array  $meta  Attachment metadata.
     * @param array  $sizes Its sizes.
     * @param string $name  File name in the address.
     * @return array{0:int,1:int}
     */
    private static function named(array $meta, array $sizes, $name) {
        if (wp_basename($meta['file']) === $name) {
            return array((int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0));
        }
        foreach ($sizes as $info) {
            if (is_array($info) && isset($info['file'], $info['width'], $info['height']) && $info['file'] === $name) {
                return array((int) $info['width'], (int) $info['height']);
            }
        }
        return array(0, 0);
    }

    /**
     * The smaller copies of the same proportions that can be served,
     * narrowest first, one per width.
     *
     * @param int   $id     Attachment ID.
     * @param array $sizes  Its sizes.
     * @param int   $width  Width of the picture the row names.
     * @param int   $height Its height.
     * @return array<int,array>
     */
    private static function copies($id, array $sizes, $width, $height) {
        $dir    = dirname((string) get_attached_file($id));
        $copies = array();
        foreach ($sizes as $size => $info) {
            $w = self::copy_width($info, $width, $height);
            if ($w < 1 || isset($copies[$w])) {
                continue;
            }
            $src = self::src_url(wp_get_attachment_image_src($id, (string) $size), $w);
            if ('' !== $src && self::there($id, (string) $size, $dir . '/' . $info['file'], $src)) {
                $copies[$w] = self::file($w, $src);
            }
        }
        ksort($copies);
        return array_values($copies);
    }

    /**
     * A size's width, when it is narrower than the picture, has a file and
     * its height is within a pixel of the same proportions; 0 when not
     * (copies() also skips widths below 1).
     *
     * @param mixed $info   The size's metadata.
     * @param int   $width  Width of the picture the row names.
     * @param int   $height Its height.
     * @return int
     */
    private static function copy_width($info, $width, $height) {
        $w = (int) ($info['width'] ?? 0);
        $h = (int) ($info['height'] ?? 0);
        return $w < $width && !empty($info['file']) && abs($h * $width - $w * $height) <= $width ? $w : 0;
    }

    /**
     * The address wp_get_attachment_image_src() gave, when it is of that
     * width and can go in CSS; '' when not.
     *
     * @param mixed $src   Its answer.
     * @param int   $width Width expected.
     * @return string
     */
    private static function src_url($src, $width) {
        // isset() is false on false, the answer for no such size.
        return isset($src[0], $src[1]) && $width === (int) $src[1] && self::css_url($src[0]) ? $src[0] : '';
    }

    /**
     * Whether a size's file can be served: here, or in cloud storage when
     * Media in cloud storage serves it from there.
     *
     * @param int    $id   Attachment ID.
     * @param string $size Size name.
     * @param string $path Local file.
     * @param string $url  Address pages get for it.
     * @return bool
     */
    private static function there($id, $size, $path, $url) {
        if (is_file($path)) {
            return true;
        }
        // Mapped to the bucket only when the object is confirmed there.
        return class_exists('SEOProStack_Media_Storage') && SEOProStack_Media_Storage::local_url($id, $size) !== $url;
    }

    /**
     * The row's own picture from cloud storage too, when it is served from
     * there (Kadence prints the address saved in the content).
     *
     * @param string $url Address the row names.
     * @param int    $id  Attachment ID.
     * @return string
     */
    private static function served_url($url, $id) {
        if (!class_exists('SEOProStack_Media_Storage')) {
            return $url;
        }
        $mapped = SEOProStack_Media_Storage::attachment_url($url, $id);
        return self::css_url($mapped) ? $mapped : $url;
    }

    /**
     * One file on offer: width, address, image-set() of its copies, the
     * address to preload (when not the same) and its MIME type.
     *
     * @param int    $width Width.
     * @param string $url   Address.
     * @return array{0:int,1:string,2:string,3:string,4:string}
     */
    private static function file($width, $url) {
        $set   = '';
        $first = $url;
        $type  = '';
        if (class_exists('SEOProStack_Nextgen_Images')) {
            $set = SEOProStack_Nextgen_Images::background_set($url);
            list($first, $type) = SEOProStack_Nextgen_Images::first_copy($url);
        }
        return array($width, $url, $set, $first === $url ? '' : $first, $type);
    }

    /**
     * Whether an address can go in CSS's url('…') as it is.
     *
     * @param mixed $url Address.
     * @return bool
     */
    private static function css_url($url) {
        return is_string($url) && preg_match('#^(?:https?:)?//#i', $url) && !preg_match('/[\s\'"()<>\\\\]/', $url);
    }
}
