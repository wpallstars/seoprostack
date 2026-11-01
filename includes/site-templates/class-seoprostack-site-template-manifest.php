<?php
/**
 * Site templates: reads a shipped manifest and lists its resources in order.
 *
 * The format is documented in site-templates/README.md and the feature class.
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

class SEOProStack_Site_Template_Manifest {

    /** Sections that are always arrays once validated. */
    const SECTIONS = array('pages', 'elements', 'images', 'options', 'widgets', 'theme_mods', 'menus');

    /** Checks in order; the first failure is reported. */
    const CHECKS = array(
        array(__CLASS__, 'sections_error'),
        array('SEOProStack_Site_Template_Schema', 'items_error'),
        array(__CLASS__, 'css_error'),
        array('SEOProStack_Site_Template_Schema', 'menus_error'),
    );

    /** Option names a manifest may never write. */
    const PROTECTED_OPTIONS = '/(?:password|secret|token|licen[cs]e|api.?key|^seoprostack_|^active_plugins$|^siteurl$|^home$|^cron$|^users_can_register$|^default_role$)/i';

    /**
     * Validate the entire manifest before any writes, including duplicate keys.
     *
     * @param string $slug Shipped slug, never a path or URL.
     * @return array|WP_Error
     */
    public static function load($slug) {
        $data = self::read($slug);
        if (is_wp_error($data)) {
            return $data;
        }
        foreach (self::CHECKS as $check) {
            $error = call_user_func($check, $data);
            if ('' !== $error) {
                return new WP_Error('template_schema', $error);
            }
        }
        foreach (self::SECTIONS as $section) {
            $data[$section] = $data[$section] ?? array();
        }
        return $data;
    }

    /** @param mixed $key Stable key. @return bool */
    public static function valid_key($key) {
        return is_string($key) && 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $key);
    }

    /** @param mixed $value Value. @return bool A non-empty string. */
    public static function text($value) {
        return is_string($value) && !empty($value);
    }

    /**
     * The manifest's resources in write order: images, posts, menus, CSS,
     * then options, widgets and theme mods.
     *
     * @param array $manifest Validated input.
     * @return array|WP_Error List of array(kind, id, value).
     */
    public static function resources($manifest) {
        $values = self::value_resources($manifest);
        if (is_wp_error($values)) {
            return $values;
        }
        $ops = array_merge(self::content_resources($manifest), $values);
        if (count($ops) > 500) {
            return new WP_Error('template_size', 'A manifest may contain at most 500 resources.');
        }
        return $ops;
    }

    /** @param string $slug Slug. @return array|WP_Error Decoded file with a valid header. */
    private static function read($slug) {
        if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            return new WP_Error('template_slug', 'Invalid template slug.');
        }
        $file = SEOPROSTACK_DIR . 'site-templates/' . $slug . '.json';
        if (!is_readable($file) || filesize($file) > 2097152) {
            return new WP_Error('template_file', 'Template missing or larger than 2 MB.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded, shipped local JSON only.
        $data = json_decode((string) file_get_contents($file), true);
        $valid = is_array($data) && ($data['slug'] ?? '') === $slug && self::text($data['version'] ?? '') && self::text($data['name'] ?? '');
        return $valid ? $data : new WP_Error('template_schema', 'Template needs slug, name and a string version.');
    }

    /** @param array $data Manifest. @return string */
    public static function sections_error($data) {
        foreach (self::SECTIONS as $section) {
            if (isset($data[$section]) && !is_array($data[$section])) {
                return 'Invalid section: ' . $section;
            }
        }
        return '';
    }

    /** @param array $data Manifest. @return string */
    public static function css_error($data) {
        return isset($data['custom_css']) && !is_string($data['custom_css']) ? 'custom_css must be a string.' : '';
    }

    /** @param array $manifest Validated input. @return array Images, posts, menus, then CSS. */
    private static function content_resources($manifest) {
        $ops = array();
        foreach (array('images', 'pages', 'elements', 'menus') as $section) {
            foreach ($manifest[$section] as $key => $item) {
                $ops[] = array($section, 'menus' === $section ? $key : $item['key'], $item);
            }
        }
        // Core stores the custom CSS post ID in theme mods. Write CSS before
        // snapshotting named settings, so that cache update is not mistaken
        // for a later site edit during undo's preflight.
        if (isset($manifest['custom_css'])) {
            $ops[] = array('css', get_stylesheet(), $manifest['custom_css']);
        }
        return $ops;
    }

    /** @param array $manifest Validated input. @return array|WP_Error Options, widgets, then theme mods. */
    private static function value_resources($manifest) {
        $ops = array();
        foreach (array('options', 'widgets') as $section) {
            foreach ($manifest[$section] as $name => $values) {
                if (!is_string($name) || preg_match(self::PROTECTED_OPTIONS, $name)) {
                    return new WP_Error('template_option', 'Protected option name.');
                }
                $ops[] = array('option', $name, $values);
            }
        }
        if ($manifest['theme_mods']) {
            $ops[] = array('option', 'theme_mods_' . get_stylesheet(), $manifest['theme_mods']);
        }
        return $ops;
    }
}
