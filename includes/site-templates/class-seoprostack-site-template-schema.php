<?php
/**
 * Site templates: validates a manifest's keyed items and menus.
 *
 * Keys are stable ownership names, so a duplicate or invalid key rejects the
 * whole manifest before any write.
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

class SEOProStack_Site_Template_Schema {

    /** @param array $data Manifest. @return string The first problem with pages, elements or images. */
    public static function items_error($data) {
        foreach (array('pages', 'elements', 'images') as $section) {
            $keys = array();
            foreach ($data[$section] ?? array() as $item) {
                if (!is_array($item) || !SEOProStack_Site_Template_Manifest::valid_key($item['key'] ?? '') || isset($keys[$item['key']])) {
                    return 'Invalid or duplicate key in ' . $section;
                }
                $keys[$item['key']] = true;
                $error = 'images' === $section ? SEOProStack_Site_Template_Media::item_error($item) : self::post_error($item);
                if ('' !== $error) {
                    return $error;
                }
            }
        }
        return '';
    }

    /** @param array $data Manifest. @return string The first problem with menus. */
    public static function menus_error($data) {
        foreach ($data['menus'] ?? array() as $location => $menu) {
            if (!self::menu_ok($location, $menu)) {
                return 'Menus need a location, name and keyed items.';
            }
            foreach ($menu['items'] as $key => $item) {
                if (!self::menu_item_ok($key, $item)) {
                    return 'Invalid menu item.';
                }
            }
        }
        return '';
    }

    /** @param array $item Manifest page or element. @return string */
    private static function post_error($item) {
        foreach (array('post_shape_error', 'post_types_error', 'parts_error') as $check) {
            $error = self::$check($item);
            if ('' !== $error) {
                return $error;
            }
        }
        return '';
    }

    /** @param array $item Manifest post. @return string */
    private static function post_shape_error($item) {
        $meta_ok = !isset($item['meta']) || is_array($item['meta']);
        $valid = !empty($item['title']) && !empty($item['slug']) && isset($item['parts']) && is_array($item['parts']) && $meta_ok;
        return $valid ? '' : 'Posts need title, slug and parts.';
    }

    /** @param array $item Manifest post. @return string */
    private static function post_types_error($item) {
        $template_ok = !isset($item['template']) || is_string($item['template']);
        $valid = is_string($item['title']) && is_string($item['slug']) && sanitize_title($item['slug']) === $item['slug'] && $template_ok;
        return $valid ? '' : 'Invalid post title, slug or page template.';
    }

    /** @param array $item Manifest post. @return string */
    private static function parts_error($item) {
        $parts = array();
        foreach ($item['parts'] as $part) {
            if (!is_array($part) || !SEOProStack_Site_Template_Manifest::valid_key($part['key'] ?? '') || isset($parts[$part['key']]) || !is_string($part['markup'] ?? null)) {
                return 'Invalid or duplicate part key/markup.';
            }
            $parts[$part['key']] = true;
        }
        return '';
    }

    /** @param mixed $location Theme location. @param mixed $menu Manifest menu. @return bool */
    private static function menu_ok($location, $menu) {
        return SEOProStack_Site_Template_Manifest::valid_key($location) && is_array($menu) && SEOProStack_Site_Template_Manifest::text($menu['name'] ?? '') && is_array($menu['items'] ?? null);
    }

    /** @param mixed $key Item key. @param mixed $item Manifest item. @return bool */
    private static function menu_item_ok($key, $item) {
        return SEOProStack_Site_Template_Manifest::valid_key($key) && is_array($item) && is_string($item['title'] ?? null) && is_string($item['url'] ?? null) && esc_url_raw($item['url']);
    }
}
