<?php
/**
 * File and account integrity checks: reading folders and hashing files.
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

class SEOProStack_Integrity_Files {

    /** Larger files are skipped. */
    const MAX_SIZE = 2097152;

    /** Files and folders looked at in one plugin before it is left unchecked. */
    const MAX_PLUGIN_ENTRIES = 100000;

    /**
     * A folder's entries: name => whether a folder. Linked folders are left
     * out, so a loop cannot trap the check.
     *
     * @param string $dir Folder.
     * @return array<string,bool>
     */
    public static function entries($dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            return array();
        }
        $names = scandir($dir);
        $out   = array();
        foreach (is_array($names) ? $names : array() as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_dir($path)) {
                $out[(string) $name] = false;
            } elseif (!is_link($path)) {
                $out[(string) $name] = true;
            }
        }
        return $out;
    }

    /**
     * Whether a file holds code worth checking.
     *
     * @param string $path File.
     * @return bool
     */
    public static function is_code($path) {
        return '.htaccess' === basename($path) || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('php', 'phtml', 'phar', 'js'), true);
    }

    /**
     * Whether a server may run a file as PHP.
     *
     * @param string $path File.
     * @return bool
     */
    public static function is_php($path) {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('php', 'phtml', 'phar', 'pht', 'php3', 'php4', 'php5', 'php7', 'php8'), true);
    }

    /**
     * A plugin's folder, the prefix its findings show, and its code files.
     *
     * @param string $file Plugin file.
     * @return array{0:string,1:string,2:array<string,array{0:int,1:int}>|null}
     */
    public static function plugin_files($file) {
        $dir = dirname($file);
        if ('.' === $dir) {
            // A single-file plugin in the plugins folder.
            $base = untrailingslashit(WP_PLUGIN_DIR);
            $path = $base . '/' . $file;
            $size = is_file($path) ? filesize($path) : false;
            return array($base, 'plugins/', false === $size ? array() : array($file => array((int) $size, (int) filemtime($path))));
        }
        $base = untrailingslashit(WP_PLUGIN_DIR) . '/' . $dir;
        return array($base, 'plugins/' . $dir . '/', self::code_files($base));
    }

    /**
     * Code files under a folder (not in hidden folders such as .git), with
     * sizes and times. Null when there are too many to look at.
     *
     * @param string $base Folder.
     * @return array<string,array{0:int,1:int}>|null
     */
    public static function code_files($base) {
        $files = array();
        $queue = array('');
        $seen  = 0;
        while ($queue) {
            $rel = (string) array_shift($queue);
            foreach (self::entries('' === $rel ? $base : $base . '/' . $rel) as $name => $is_dir) {
                if (++$seen > self::MAX_PLUGIN_ENTRIES) {
                    return null;
                }
                self::add_entry($base, '' === $rel ? (string) $name : $rel . '/' . $name, $is_dir, $queue, $files);
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Queue a folder, or list a code file with its size and time.
     *
     * @param string                           $base   Folder being listed.
     * @param string                           $path   Entry, relative to it.
     * @param bool                             $is_dir Whether a folder.
     * @param array<int,string>                $queue  Folders still to list.
     * @param array<string,array{0:int,1:int}> $files  Code files so far.
     */
    private static function add_entry($base, $path, $is_dir, array &$queue, array &$files) {
        $name = basename($path);
        if ($is_dir) {
            if ('.' !== substr($name, 0, 1)) {
                $queue[] = $path;
            }
            return;
        }
        if (!self::is_code($name)) {
            return;
        }
        $size = filesize($base . '/' . $path);
        if (false !== $size && $size <= self::MAX_SIZE) {
            $files[$path] = array((int) $size, (int) filemtime($base . '/' . $path));
        }
    }

    /**
     * A file's SHA-256 hash, for the records kept of plugins.
     *
     * @param string $file Path.
     * @return string|false
     */
    public static function hash($file) {
        return hash_file('sha256', $file);
    }

    /**
     * Hash every listed file, for a new record.
     *
     * @param string                           $base Folder.
     * @param array<string,array{0:int,1:int}> $list Files.
     * @return array<string,array{0:int,1:int,2:string}>
     */
    public static function hash_all($base, array $list) {
        $files = array();
        foreach ($list as $path => $meta) {
            $hash = self::hash($base . '/' . $path);
            if (false !== $hash) {
                $files[$path] = array($meta[0], $meta[1], $hash);
            }
        }
        return $files;
    }
}
