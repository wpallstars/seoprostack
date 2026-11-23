<?php
/**
 * Keeping and dropping recorded memory peaks, for SEOProStack_Memory_Peaks.
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

class SEOProStack_Memory_Trim {

    /**
     * Keep an entry unless TOP others are both higher and newer: the TOP
     * highest of 30 days, plus the highest of each recent stretch, so a
     * recent peak outlives older, higher ones when they expire.
     *
     * @param array $list Entries: array(peak, limit, time).
     * @return array
     */
    public static function skyline(array $list) {
        $kept = array();
        foreach ($list as $i => $entry) {
            $above = 0;
            foreach ($list as $j => $other) {
                if ($i !== $j && $other[0] >= $entry[0] && $other[2] >= $entry[2]) {
                    ++$above;
                }
            }
            if ($above < SEOProStack_Memory_Peaks::TOP) {
                $kept[] = $entry;
            }
        }
        usort($kept, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        return $kept;
    }

    /**
     * Drop peaks older than a time, and commands left with none.
     *
     * @param array $stored From SEOProStack_Memory_Peaks::stored(), changed in place.
     * @param int   $since  Oldest time kept.
     * @return bool Whether anything was dropped.
     */
    public static function prune(array &$stored, $since) {
        $changed = false;
        foreach ($stored['peaks'] as $key => $list) {
            $kept = array();
            foreach (is_array($list) ? $list : array() as $entry) {
                if (is_array($entry) && isset($entry[0], $entry[1], $entry[2]) && (int) $entry[2] >= $since) {
                    $kept[] = array((int) $entry[0], (int) $entry[1], (int) $entry[2]);
                }
            }
            $changed = $changed || count($kept) !== count((array) $list);
            if ($kept) {
                $stored['peaks'][$key] = $kept;
            } else {
                unset($stored['peaks'][$key]);
            }
        }
        return $changed;
    }
}
