<?php
/**
 * Keep recommended settings, for SEO Pro Stack's own settings: a changed
 * default reaches sites where the setting is still at the old default.
 *
 * A changed default otherwise reaches new installs only, as the first
 * migration stores every setting (#404). This remembers each setting's
 * default (KEPT). When a release changes one, a setting still at the old
 * default gets the new one; a setting changed by hand keeps its value. On
 * the first run there is nothing to compare with, so switches that are on
 * by default but off here are switched on (features that became on by
 * default after the site was set up); after that, a switch turned off
 * stays off. Settings the network sets for every site are left to it.
 *
 * Run by SEOProStack_Presets_Keep.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Defaults_Keep {

    /** Setting key => default when last run. Not autoloaded. */
    const KEPT = 'seoprostack_defaults_kept';

    /**
     * Apply changed defaults and remember the defaults now.
     *
     * @param bool $dry Only list what would change.
     * @return array Setting key => new value, for the settings changed (or that would be).
     */
    public static function run($dry) {
        $defaults = SEOProStack_Settings::defaults();
        $kept     = get_option(self::KEPT, null);
        $changes  = self::changes($defaults, is_array($kept) ? $kept : null);
        if ($dry) {
            return $changes;
        }
        $record = $defaults;
        foreach ($changes as $key => $value) {
            if (is_wp_error(SEOProStack_Settings::set($key, $value))) {
                // Not saved: try again next time, against the same old default.
                unset($changes[$key]);
                if (is_array($kept) && array_key_exists($key, $kept)) {
                    $record[$key] = $kept[$key];
                } else {
                    unset($record[$key]);
                }
            }
        }
        update_option(self::KEPT, $record, false);
        return $changes;
    }

    /**
     * Settings to change.
     *
     * @param array      $defaults Setting key => default now.
     * @param array|null $kept     Setting key => default when last run; null on the first run.
     * @return array Setting key => new value.
     */
    private static function changes(array $defaults, $kept) {
        $schema  = SEOProStack_Settings::schema();
        $own     = SEOProStack_Settings::own();
        $changes = array();
        foreach ($defaults as $key => $default) {
            if (SEOProStack_Network_Settings::is_enforced($key)) {
                continue;
            }
            $value  = array_key_exists($key, $own) ? $own[$key] : $default;
            $change = null === $kept ? self::first_change($schema[$key], $default, $value) : self::later_change($key, $default, $value, $kept);
            if (null !== $change) {
                $changes[$key] = $change[0];
            }
        }
        return $changes;
    }

    /**
     * First run: a switch that is on by default but off here is switched on.
     *
     * @param array $field   Setting's schema.
     * @param mixed $default Default now.
     * @param mixed $value   Value here.
     * @return array{0:mixed}|null The new value, wrapped; null for no change.
     */
    private static function first_change(array $field, $default, $value) {
        $switch = isset($field['type']) && 'bool' === $field['type'];
        return $switch && true === $default && !$value ? array(true) : null;
    }

    /**
     * Later runs: a setting still at a default that changed gets the new one.
     * A setting new since the last run already has its default unless
     * someone set it, so it is left alone.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Default now.
     * @param mixed  $value   Value here.
     * @param array  $kept    Defaults when last run.
     * @return array{0:mixed}|null The new value, wrapped; null for no change.
     */
    private static function later_change($key, $default, $value, array $kept) {
        if (!array_key_exists($key, $kept) || SEOProStack_Settings::same($kept[$key], $default, $key)) {
            return null;
        }
        return SEOProStack_Settings::same($value, $kept[$key], $key) ? array($default) : null;
    }
}
