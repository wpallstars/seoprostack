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

    /** Uploaded PHP files up to this size are read whole and checked token by token. */
    const SMALL_FILE = 2048;

    /** Largest exit-guarded file (such as a plugin's log) read to the end; larger ones are reported. */
    const MAX_GUARDED_FILE = 8388608;

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
     * PHP in it. Files that cannot run code do not count: nothing but
     * comments or exit (the "silence is golden" index.php plugins add), a
     * "403 Forbidden" stub, or a log that exits on its first line
     * (harmless()).
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
     * Whether an uploaded PHP file cannot run code: a small file holding only
     * comments, whitespace and exit, or a "403 Forbidden" stub; or a file of
     * any size up to MAX_GUARDED_FILE that exits on its first line and has no
     * other PHP open tag (the logs Code Profiler Pro and MC4WP write).
     *
     * @param string $file Path.
     * @return bool
     */
    private static function harmless($file) {
        $size = filesize($file);
        if (false === $size || $size > self::MAX_GUARDED_FILE || !function_exists('token_get_all')) {
            return false;
        }
        // One byte more than a small file, so a file that grew since filesize() is not taken as small.
        $head = file_get_contents($file, false, null, 0, self::SMALL_FILE + 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file's start, read, never run.
        if (false === $head) {
            return false; // Unreadable here (such as another user's 0600 file) but perhaps not to the web server: report it.
        }
        if (strlen($head) <= self::SMALL_FILE && (self::only_quiet($head) || self::forbidden_stub($head))) {
            return true;
        }
        $end = self::guard_end($head);
        return null !== $end && !self::open_tag_after($file, $end);
    }

    /**
     * Whether code holds only quiet tokens.
     *
     * @param string $code Code.
     * @return bool
     */
    private static function only_quiet($code) {
        foreach (token_get_all($code) as $token) {
            if (!self::quiet($token)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Where an exit guard at the start of a file ends: quiet tokens, then
     * exit with nothing, a number or a plain string in brackets, then a
     * close tag.
     *
     * @param string $head The file's start.
     * @return int|null Bytes up to the end of the close tag; null when the
     *                  file does not start that way.
     */
    private static function guard_end($head) {
        $offset = 0;
        $exit   = false;
        $prev   = null;
        foreach (token_get_all($head) as $token) {
            $offset += strlen(is_array($token) ? $token[1] : $token);
            if (!$exit) {
                if (!self::quiet($token)) {
                    return null;
                }
                $exit = is_array($token) && T_EXIT === $token[0];
                continue;
            }
            if (is_array($token) && T_CLOSE_TAG === $token[0]) {
                return $offset;
            }
            if (!self::exit_token($token, $prev)) {
                return null;
            }
        }
        return null;
    }

    /**
     * Whether a token after exit, before the close tag, only ends the exit:
     * brackets straight after exit, a number or plain string in them, `;`,
     * whitespace or a comment.
     *
     * @param array{0:int,1:string,2:int}|string $token Token.
     * @param int|string|null                    $prev  The last token that was not whitespace or a comment.
     * @return bool
     */
    private static function exit_token($token, &$prev) {
        if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            return true;
        }
        $ok   = '(' === $token ? null === $prev : (in_array($token, array(')', ';'), true) || (is_array($token) && (T_LNUMBER === $token[0] || self::plain($token))));
        $prev = is_array($token) ? $token[0] : $token;
        return $ok;
    }

    /**
     * Whether a small file only sends headers and exits, as the
     * "403 Forbidden" index.php some security plugins add: header() calls
     * built from $_SERVER values and plain strings, then exit. Brackets may
     * follow only header and exit, and square brackets only $_SERVER, so
     * nothing else can be called.
     *
     * @param string $code Code.
     * @return bool
     */
    private static function forbidden_stub($code) {
        $exit = false;
        $prev = null;
        foreach (token_get_all($code) as $token) {
            if (self::quiet($token) && !in_array($token, array('(', ')', ';'), true) && !(is_array($token) && T_EXIT === $token[0])) {
                continue;
            }
            if (!self::stub_token($token, $prev)) {
                return false;
            }
            $exit = $exit || (is_array($token) && T_EXIT === $token[0]);
            $prev = '[' === $prev ? 'key' : (is_array($token) ? (T_STRING === $token[0] ? strtolower($token[1]) : $token[0]) : $token);
        }
        return $exit;
    }

    /**
     * Whether a token may appear in a "403 Forbidden" stub. $_SERVER may
     * be read only as $_SERVER['SERVER_PROTOCOL']: other keys, such as
     * QUERY_STRING, come from the request and could send a redirect.
     *
     * @param array{0:int,1:string,2:int}|string $token Token.
     * @param int|string|null                    $prev  The last token that was not whitespace or a comment
     *                                                  (a function name in lower case; 'key' after $_SERVER's key).
     * @return bool
     */
    private static function stub_token($token, $prev) {
        if (T_VARIABLE === $prev) {
            return '[' === $token;
        }
        if ('[' === $prev) {
            return is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && 'SERVER_PROTOCOL' === trim($token[1], '\'"');
        }
        if ('key' === $prev) {
            return ']' === $token;
        }
        if ('(' === $token) {
            return 'header' === $prev || T_EXIT === $prev;
        }
        if ('[' === $token) {
            return false; // Only straight after $_SERVER, above.
        }
        if (!is_array($token)) {
            return in_array($token, array(']', ')', '.', ',', ';'), true);
        }
        switch ($token[0]) {
            case T_EXIT:
            case T_LNUMBER:
                return true;
            case T_STRING:
                return 'header' === strtolower($token[1]);
            case T_VARIABLE:
                return '$_SERVER' === $token[1];
            default:
                return self::plain($token);
        }
    }

    /**
     * Whether a token is a string literal that cannot redirect or add
     * markup when sent, even joined to others: only letters, digits, spaces
     * and `. , ! ? _ / -`, in single or double quotes (an optional b
     * prefix allowed). With no colon no header name can be formed (such
     * as 'Loc' . 'ation: …'), and with no backslash, `<` or quote there is
     * no escape or markup (such as "\x3c", which is `<`).
     *
     * @param array{0:int,1:string,2:int} $token Token.
     * @return bool
     */
    private static function plain(array $token) {
        return T_CONSTANT_ENCAPSED_STRING === $token[0]
            && 1 === preg_match('/^[bB]?(?:\'[A-Za-z0-9 .,!?_\/-]*\'|"[A-Za-z0-9 .,!?_\/-]*")$/', $token[1]);
    }

    /**
     * Whether a file has `<?` anywhere after an offset (`<?php`, `<?=`, or
     * `<?` where short_open_tag is on), read in parts, never all at once.
     * Unreadable, or longer than MAX_GUARDED_FILE: counted as found.
     *
     * @param string $file   Path.
     * @param int    $offset Where to start.
     * @return bool
     */
    private static function open_tag_after($file, $offset) {
        $handle = fopen($file, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read in parts, never run.
        if (false === $handle) {
            return true;
        }
        $found = 0 !== fseek($handle, $offset);
        $read  = $offset;
        $carry = '';
        while (!$found && !feof($handle)) {
            $chunk = fread($handle, 65536); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            if (false === $chunk) {
                $found = true;
                break;
            }
            $read += strlen($chunk);
            $found = $read > self::MAX_GUARDED_FILE || false !== strpos($carry . $chunk, '<?');
            $carry = (string) substr($chunk, -1);
        }
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $found;
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
