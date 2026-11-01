<?php
/**
 * Site templates: options, widgets, theme mods and custom CSS.
 *
 * Only manifest leaves are merged, each owned by its hash, so unmentioned
 * and site-edited keys stay. Custom CSS owns a leading base; the remainder
 * belongs to the site.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Site_Template_Values {

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $name Option.
     * @param mixed                         $values Named keys or scalar.
     * @return true|WP_Error
     */
    public static function apply_option($run, $name, $values) {
        // Non-serialized option scalars return as strings on the next request.
        // Preserve types inside arrays, which WordPress serializes intact.
        if (is_scalar($values) || null === $values) {
            $values = (string) $values;
        }
        $before = self::snapshot($name);
        $removed = !$before['exists'] && $run->owns('options/' . $name);
        $after = self::merge($run, 'options/' . $name, $before['value'], $values, $before['exists']);
        if ($run->dry || $before['value'] === $after || $removed) {
            return true;
        }
        $run->journal('option', $name, $before, array('exists' => true, 'value' => $after));
        update_option($name, $after, false);
        if (self::snapshot($name) !== array('exists' => true, 'value' => $after)) {
            return new WP_Error('template_write', 'Option write failed: ' . $name);
        }
        SEOProStack_Site_Template_Run::purge();
        return true;
    }

    /** @param string $name Option name. @return array Whether it exists, and its value. */
    public static function snapshot($name) {
        $missing = new stdClass();
        $value = get_option($name, $missing);
        if (is_scalar($value) || null === $value) {
            $value = (string) $value;
        }
        return array('exists' => $value !== $missing, 'value' => $value === $missing ? null : $value);
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $theme Theme.
     * @param string                        $base Leading CSS.
     * @return true|WP_Error
     */
    public static function apply_css($run, $theme, $base) {
        $current = wp_get_custom_css($theme);
        $old = $run->record['css'][$theme] ?? null;
        $prefix = is_array($old) ? $old['base'] : '';
        if ((null === $old && '' !== $current) || 0 !== strpos($current, $prefix)) {
            $run->report('custom_css', 'edited-on-site');
            return true;
        }
        $after = $base . substr($current, strlen($prefix));
        $run->report('custom_css', $after === $current ? 'same' : 'updated');
        if (!$run->dry && $after !== $current) {
            $result = self::write_css($run, $theme, $current, $after);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        $run->record['css'][$theme] = array('base' => $base, 'sha256' => SEOProStack_Site_Template_Run::fingerprint($base));
        return true;
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $theme Theme.
     * @param string                        $current Stored CSS.
     * @param string                        $after Intended CSS.
     * @return true|WP_Error
     */
    private static function write_css($run, $theme, $current, $after) {
        $run->journal('css', $theme, $current, $after);
        $result = wp_update_custom_css_post($after, array('stylesheet' => $theme));
        if (is_wp_error($result)) {
            return $result;
        }
        $stored = wp_get_custom_css($theme);
        $run->amend('after', $stored);
        $run->save();
        SEOProStack_Site_Template_Run::purge();
        if ($stored !== $after) {
            return new WP_Error('template_css_filtered', 'A CSS filter changed the base. Undo this run before applying again.');
        }
        return true;
    }

    /**
     * Merge only manifest leaves, preserving unmentioned and site-edited keys.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Ownership path.
     * @param mixed                         $current Current subtree.
     * @param mixed                         $desired Manifest subtree.
     * @param bool                          $exists Key exists.
     * @return mixed
     */
    private static function merge($run, $path, $current, $desired, $exists) {
        if (!$exists && $run->owns($path)) {
            $run->report($path, 'removed-on-site');
            return $current;
        }
        if (is_array($desired)) {
            return self::merge_tree($run, $path, $current, $desired, $exists);
        }
        return self::merge_leaf($run, $path, $current, $desired, $exists);
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Ownership path.
     * @param mixed                         $current Current subtree.
     * @param array                         $desired Manifest subtree.
     * @param bool                          $exists Key exists.
     * @return mixed
     */
    private static function merge_tree($run, $path, $current, $desired, $exists) {
        if ($exists && !is_array($current)) {
            $run->report($path, 'edited-on-site');
            return $current;
        }
        $out = is_array($current) ? $current : array();
        foreach ($desired as $key => $value) {
            $child = $path . '/' . rawurlencode((string) $key);
            if (!array_key_exists($key, $out) && $run->owns($child)) {
                $run->report($child, 'removed-on-site');
                continue;
            }
            $out[$key] = self::merge($run, $child, $out[$key] ?? null, $value, array_key_exists($key, $out));
        }
        return $out;
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Ownership path.
     * @param mixed                         $current Current value.
     * @param mixed                         $desired Manifest value.
     * @param bool                          $exists Key exists.
     * @return mixed
     */
    private static function merge_leaf($run, $path, $current, $desired, $exists) {
        $hash = $run->record['values'][$path] ?? null;
        if ($exists && self::site_value($run, $current, $hash)) {
            return self::keep_site_value($run, $path, $current, $desired, $hash);
        }
        $run->report($path, !$exists ? 'added' : ($current === $desired ? 'same' : 'updated'));
        $run->record['values'][$path] = SEOProStack_Site_Template_Run::fingerprint($desired);
        return $desired;
    }

    /**
     * Report the site's value and keep it.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Ownership path.
     * @param mixed                         $current Current value.
     * @param mixed                         $desired Manifest value.
     * @param string|null                   $hash Owned hash.
     * @return mixed The current value.
     */
    private static function keep_site_value($run, $path, $current, $desired, $hash) {
        $adopt = $current === $desired && !$hash;
        $run->report($path, $adopt ? 'same' : 'edited-on-site');
        // Equal pre-existing values may be adopted by an explicit apply.
        if ($adopt) {
            $run->record['values'][$path] = SEOProStack_Site_Template_Run::fingerprint($desired);
        }
        return $current;
    }

    /**
     * Whether an existing value is the site's: never owned, or edited since.
     * A site's first template run (no template before) takes its values.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param mixed                         $current Current value.
     * @param string|null                   $hash Owned hash.
     * @return bool
     */
    private static function site_value($run, $current, $hash) {
        return (!$hash || SEOProStack_Site_Template_Run::fingerprint($current) !== $hash) && empty($run->record['pending']['initial']);
    }
}
