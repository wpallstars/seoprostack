<?php
/**
 * File and account integrity checks: emailing new findings to the
 * administration address, once each, by path or user name only.
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

class SEOProStack_Integrity_Email {

    /**
     * Email only when a finding is new since the last email: not when
     * findings go. One that goes and comes back is new again.
     *
     * @param array<string,mixed>            $before The previous results.
     * @param array<string,array<int,mixed>> $found  Findings.
     * @return array<int,string> Keys of the findings emailed about, to keep.
     */
    public static function notify(array $before, array $found) {
        $keys     = self::keys($found);
        $notified = isset($before['notified']) ? (array) $before['notified'] : array();
        if (array_diff($keys, $notified) && SEOProStack_Settings::get(SEOProStack_Integrity_Checks::EMAIL_KEY) && !self::send($found)) {
            return $notified; // Try again next time.
        }
        return $keys;
    }

    /**
     * A short key for each finding, to tell new ones.
     *
     * @param array<string,array<int,mixed>> $found Findings.
     * @return array<int,string>
     */
    private static function keys(array $found) {
        $keys = array();
        foreach ($found as $check => $items) {
            foreach ($items as $item) {
                $keys[] = hash('sha256', $check . '|' . (is_array($item) && isset($item['user']) ? 'user:' . $item['user'] : (string) $item));
            }
        }
        return $keys;
    }

    /**
     * Email the findings, by path and user name only.
     *
     * @param array<string,array<int,mixed>> $found Findings.
     * @return bool
     */
    private static function send(array $found) {
        $site    = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] File and account integrity: new findings', 'seoprostack'), $site);
        $message = __('SEO Pro Stack’s daily integrity checks found the following. It changed nothing. Check each one: an unexpected file or administrator can mean someone has broken into the site.', 'seoprostack')
            . "\n\n" . implode("\n", self::lines($found)) . "\n" . __('Details in Site Health:', 'seoprostack') . ' ' . admin_url('site-health.php');
        return wp_mail((string) get_option('admin_email'), $subject, $message);
    }

    /**
     * The findings as lines of text, at most 50 per check.
     *
     * @param array<string,array<int,mixed>> $found Findings.
     * @return array<int,string>
     */
    private static function lines(array $found) {
        $lines = array();
        foreach (SEOProStack_Integrity_Report::checks() as $check => $label) {
            if (empty($found[$check])) {
                continue;
            }
            $lines[] = $label . ':';
            foreach (array_slice($found[$check], 0, 50) as $item) {
                $lines[] = '- ' . SEOProStack_Integrity_Report::item_text($item);
            }
            if (count($found[$check]) > 50) {
                /* translators: %d: number of findings */
                $lines[] = sprintf(__('…and %d more', 'seoprostack'), count($found[$check]) - 50);
            }
            $lines[] = '';
        }
        return $lines;
    }
}
