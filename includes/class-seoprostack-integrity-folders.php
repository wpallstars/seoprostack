<?php
/**
 * File and account integrity checks: PHP files where they should not be, in
 * wp-admin/ and wp-includes/, and in the uploads folder. Folders are walked
 * one at a time, so a part that runs out of time carries on where it stopped.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.56
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Integrity_Folders {

    /** Files and folders looked at in the uploads folder per check. */
    const MAX_UPLOAD_ENTRIES = 300000;

    /**
     * PHP files in wp-admin/ and wp-includes/ that WordPress does not have.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    public static function step_core_extra(array &$state, $start) {
        $sums = SEOProStack_Integrity_Wporg::core_sums();
        if (null === $sums) {
            return true;
        }
        return self::walk($state, $start, untrailingslashit(ABSPATH), array('wp-admin', 'wp-includes'), $sums);
    }

    /**
     * PHP files anywhere in the uploads folder.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param float               $start When this part began.
     * @return bool Whether this step is done.
     */
    public static function step_uploads(array &$state, $start) {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir']) || !is_dir($uploads['basedir'])) {
            return true;
        }
        return self::walk($state, $start, untrailingslashit($uploads['basedir']), array(''), null);
    }

    /**
     * Walk folders, from where the last part stopped, until done or out of
     * time.
     *
     * @param array<string,mixed>                   $state Check in progress.
     * @param float                                 $start When this part began.
     * @param string                                $base  Folder the paths are relative to.
     * @param array<int,string>                     $roots Folders to start from.
     * @param array<string,array<int,string>>|null  $sums  Core checksums (core folders), or null (uploads).
     * @return bool Whether this step is done.
     */
    private static function walk(array &$state, $start, $base, array $roots, $sums) {
        $queue = is_array($state['pos']) ? $state['pos'] : $roots;
        $first = true;
        while ($queue) {
            if (!$first && !SEOProStack_Feature::more_time($start, SEOProStack_Integrity_Checks::BUDGET)) {
                $state['pos'] = $queue;
                return false;
            }
            $first = false;
            $rel   = (string) array_shift($queue);
            $more  = null === $sums ? self::visit_uploads($state, $base, $rel, $queue) : self::visit_core($state, $base, $rel, $queue, $sums);
            if (!$more) {
                return true;
            }
        }
        return true;
    }

    /**
     * One core folder: queue its folders, note PHP files WordPress does not have.
     *
     * @param array<string,mixed>             $state Check in progress.
     * @param string                          $base  ABSPATH, untrailed.
     * @param string                          $rel   Folder.
     * @param array<int,string>               $queue Folders still to walk.
     * @param array<string,array<int,string>> $sums  Core checksums.
     * @return bool Always true: carry on.
     */
    private static function visit_core(array &$state, $base, $rel, array &$queue, array $sums) {
        foreach (SEOProStack_Integrity_Files::entries($base . '/' . $rel) as $name => $is_dir) {
            $path = $rel . '/' . $name;
            if ($is_dir) {
                $queue[] = $path;
            } elseif (SEOProStack_Integrity_Files::is_php((string) $name) && !isset($sums[$path])) {
                SEOProStack_Integrity_Scan::found_path($state, 'core', $path);
            }
        }
        return true;
    }

    /**
     * One uploads folder: queue its folders, note PHP files.
     *
     * @param array<string,mixed> $state Check in progress.
     * @param string              $base  Uploads folder, untrailed.
     * @param string              $rel   Folder ('' for the top).
     * @param array<int,string>   $queue Folders still to walk.
     * @return bool False when the limit is reached: the step stops there.
     */
    private static function visit_uploads(array &$state, $base, $rel, array &$queue) {
        foreach (SEOProStack_Integrity_Files::entries('' === $rel ? $base : $base . '/' . $rel) as $name => $is_dir) {
            if (++$state['seen'] > self::MAX_UPLOAD_ENTRIES) {
                /* translators: %s: number of files and folders */
                SEOProStack_Integrity_Scan::unchecked($state, 'tamper', sprintf(__('The uploads folder was checked only as far as %s files and folders.', 'seoprostack'), number_format_i18n(self::MAX_UPLOAD_ENTRIES)));
                return false;
            }
            $path = '' === $rel ? (string) $name : $rel . '/' . $name;
            if ($is_dir) {
                $queue[] = $path;
            } elseif (self::executable($base . '/' . $path, (string) $name)) {
                SEOProStack_Integrity_Scan::found_path($state, 'tamper', 'uploads/' . $path);
            }
        }
        return true;
    }

    /**
     * Whether an uploaded file is PHP: by extension, or a .cache file with
     * PHP in it. Files with nothing but comments or exit (the "silence is
     * golden" index.php plugins add) do not count.
     *
     * @param string $file Path.
     * @param string $name File name.
     * @return bool
     */
    private static function executable($file, $name) {
        if ('cache' === strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            $head = (string) file_get_contents($file, false, null, 0, 4096); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file's start.
            return false !== stripos($head, '<?php') || false !== strpos($head, '<?=');
        }
        return SEOProStack_Integrity_Files::is_php($name) && !self::harmless($file);
    }

    /**
     * Whether a small PHP file holds only comments, whitespace and exit.
     *
     * @param string $file Path.
     * @return bool
     */
    private static function harmless($file) {
        $size = filesize($file);
        if (false === $size || $size > 2048 || !function_exists('token_get_all')) {
            return false;
        }
        foreach (token_get_all((string) file_get_contents($file)) as $token) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local file, read, never run.
            if (!self::quiet($token)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a token does nothing: an open or close tag, a comment,
     * whitespace, exit, or the punctuation around exit.
     *
     * @param array{0:int,1:string,2:int}|string $token Token.
     * @return bool
     */
    private static function quiet($token) {
        if (!is_array($token)) {
            return in_array($token, array(';', '(', ')'), true);
        }
        return in_array($token[0], array(T_OPEN_TAG, T_CLOSE_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_EXIT), true)
            || (T_INLINE_HTML === $token[0] && '' === trim($token[1]));
    }
}
