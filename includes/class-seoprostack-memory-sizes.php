<?php
/**
 * Memory ceilings' sizes: memory limits as bytes, the hosting account's
 * memory, and settings read before the settings schema is ready
 * (SEOProStack_Memory_Ceilings::early()). Sizing rules:
 * SEOProStack_Memory_Needs.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.230
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Memory_Sizes {

    /** Hosting needs' setting: the hosting account's memory, such as 12G. */
    const ACCOUNT = 'account_memory';

    /** Smallest ceiling half of a small account's memory can give, MB. */
    const FLOOR_MB = 512;

    /** Files with the container's memory limit: cgroup v2, then v1. */
    const CGROUP = array('/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes');

    /**
     * memory_limit's value, or a size such as 12G, 12 GB or 1.5G, in bytes.
     *
     * @param mixed $value Value.
     * @return int Bytes; -1 for none; 0 when empty or not a size.
     */
    public static function bytes($value) {
        $value = strtoupper(str_replace(' ', '', trim(is_scalar($value) ? (string) $value : '')));
        if ('-1' === $value) {
            return -1;
        }
        if (!preg_match('/^(\d+(?:\.\d+)?)([KMGT]?)(?:I?B)?$/', $value, $m)) {
            return 0;
        }
        $units = array('' => 1, 'K' => KB_IN_BYTES, 'M' => MB_IN_BYTES, 'G' => GB_IN_BYTES, 'T' => TB_IN_BYTES);
        return (int) min(PHP_INT_MAX, round((float) $m[1] * $units[$m[2]]));
    }

    /**
     * memory_limit=… in WP_CLI_PHP_ARGS, which WP-CLI's launcher passes to PHP.
     *
     * @return int Bytes; -1 for none; 0 when not given.
     */
    public static function php_args_limit() {
        $args = (string) getenv('WP_CLI_PHP_ARGS');
        return preg_match('/memory_limit\s*=\s*["\']?(-1|[\d.]+\s*[KMGT]?)/i', $args, $m) ? self::bytes($m[1]) : 0;
    }

    /**
     * The hosting account's memory: Hosting needs' Account memory setting,
     * else the container's limit when PHP can see it.
     *
     * @return int Bytes, or 0 when not known.
     */
    public static function account() {
        $set = self::bytes((string) self::setting(self::ACCOUNT, ''));
        return $set > 0 ? $set : self::container();
    }

    /**
     * Where the account's memory comes from.
     *
     * @return string setting, container or ''.
     */
    public static function account_source() {
        if (self::bytes((string) self::setting(self::ACCOUNT, '')) > 0) {
            return 'setting';
        }
        return self::container() > 0 ? 'container' : '';
    }

    /**
     * A ceiling no more than half the account's memory when known, and at
     * least FLOOR_MB.
     *
     * @param int $mb Ceiling, MB.
     * @return int MB.
     */
    public static function within_account($mb) {
        $account = self::account();
        return $account > 0 ? min((int) $mb, max(self::FLOOR_MB, intdiv($account, 2 * MB_IN_BYTES))) : (int) $mb;
    }

    /**
     * The memory limit of the container PHP runs in. "max", cgroup v1's
     * near-2^63 "no limit" and other unlikely values do not count.
     * CloudLinux LVE often hides its limit from PHP.
     *
     * @return int Bytes, or 0.
     */
    private static function container() {
        static $bytes = null;
        if (null !== $bytes) {
            return $bytes;
        }
        $bytes = 0;
        if ('' !== (string) ini_get('open_basedir')) {
            return $bytes; // /sys is outside it, and checking would warn.
        }
        foreach (self::CGROUP as $file) {
            $value = is_readable($file) ? trim((string) file_get_contents($file)) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local system file.
            if (ctype_digit($value) && (int) $value >= 256 * MB_IN_BYTES && (int) $value <= 1024 * GB_IN_BYTES) {
                $bytes = (int) $value;
                break;
            }
        }
        return $bytes;
    }

    /**
     * A setting as SEOProStack_Settings::get() reads it (the network's
     * value, else the site's, else the default), without the schema, which
     * is built from translated labels and not ready at the plugin's load.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Default.
     * @return mixed
     */
    public static function setting($key, $default) {
        $enforced = SEOProStack_Network_Settings::enforced();
        if (array_key_exists($key, $enforced)) {
            return $enforced[$key];
        }
        $stored = get_option(SEOProStack_Settings::OPTION, array());
        return is_array($stored) && array_key_exists($key, $stored) ? $stored[$key] : $default;
    }
}
