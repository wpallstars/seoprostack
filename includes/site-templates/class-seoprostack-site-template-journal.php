<?php
/**
 * Site templates: read and restore the storage behind each journal entry.
 *
 * Entry types: post, option, css, locations, menu_item. retained_media and
 * retained_menu only record creations undo keeps.
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

class SEOProStack_Site_Template_Journal {

    /** Journal type => restorer, given the entry. */
    const RESTORERS = array(
        'post' => 'restore_post',
        'option' => 'restore_option',
        'css' => 'restore_css',
        'locations' => 'restore_locations',
        'menu_item' => 'restore_menu_item',
    );

    /** @param array $write Journal entry. @return mixed The resource as stored now. */
    public static function read($write) {
        switch ($write['type']) {
            case 'post':
                return $write['id'] && get_post($write['id']) ? self::post($write['id'], array_keys($write['after']['meta_input'])) : null;
            case 'option':
                return SEOProStack_Site_Template_Values::snapshot($write['id']);
            case 'css':
                return wp_get_custom_css($write['id']);
            case 'locations':
                return get_nav_menu_locations();
            case 'menu_item':
                return self::menu_item($write['id']);
        }
        return null;
    }

    /** @param int $id Menu item. @return array|null Title, URL and status as stored. */
    private static function menu_item($id) {
        $post = $id ? get_post($id) : null;
        return $post ? SEOProStack_Site_Template_Menus::current($post) : null;
    }

    /** @param int $id Post. @param array $keys Meta names. @return array Fields and meta as stored. */
    public static function post($id, $keys) {
        $post = get_post($id);
        if (!$post) {
            return array();
        }
        $out = array();
        foreach (array('post_type', 'post_status', 'post_content', 'post_title', 'post_name') as $field) {
            $out[$field] = $post->$field;
        }
        $out['meta_input'] = array();
        foreach ($keys as $key) {
            $out['meta_input'][$key] = metadata_exists('post', $id, $key) ? get_post_meta($id, $key, true) : null;
        }
        return $out;
    }

    /** @param array $write Journal entry. @return true|WP_Error Restores the before-image. */
    public static function restore($write) {
        $method = self::RESTORERS[$write['type']] ?? null;
        return $method ? self::$method($write) : true;
    }

    /** @param array $write Post entry; before is null for a creation. @return true|WP_Error */
    private static function restore_post($write) {
        $id = $write['id'];
        if (!$id) {
            return true;
        }
        $before = $write['before'];
        $data = null === $before ? array('post_status' => 'draft') : $before;
        foreach ($data['meta_input'] ?? array() as $key => $value) {
            if (null === $value) {
                delete_post_meta($id, $key);
                unset($data['meta_input'][$key]);
            }
        }
        $data['ID'] = $id;
        $result = wp_update_post(wp_slash($data), true);
        return is_wp_error($result) ? $result : true;
    }

    /** @param array $write Option entry: id is the name, before its snapshot. @return true */
    private static function restore_option($write) {
        if ($write['before']['exists']) {
            update_option($write['id'], $write['before']['value'], false);
        } else {
            delete_option($write['id']);
        }
        return true;
    }

    /** @param array $write CSS entry: id is the theme, before its CSS. @return true|WP_Error */
    private static function restore_css($write) {
        $result = wp_update_custom_css_post($write['before'], array('stylesheet' => $write['id']));
        return is_wp_error($result) ? $result : true;
    }

    /** @param array $write Locations entry (theme mods are the active theme's). @return true */
    private static function restore_locations($write) {
        set_theme_mod('nav_menu_locations', $write['before']);
        return true;
    }

    /** @param array $write Menu item entry; before is null for a creation. @return true|WP_Error */
    private static function restore_menu_item($write) {
        $id = $write['id'];
        if (!$id) {
            return true;
        }
        $before = $write['before'];
        $result = wp_update_post(wp_slash(array('ID' => $id, 'post_status' => null === $before ? 'draft' : $before['status'], 'post_title' => null === $before ? $write['after']['title'] : $before['title'])), true);
        if (is_wp_error($result)) {
            return $result;
        }
        if (null !== $before) {
            update_post_meta($id, '_menu_item_url', $before['url']);
        }
        return true;
    }
}
