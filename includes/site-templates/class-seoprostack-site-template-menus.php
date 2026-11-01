<?php
/**
 * Site templates: a menu per theme location, with keyed custom links.
 *
 * A location the site already filled is the site's. Each item is owned by
 * the hash of its title, URL and status, so site edits stay.
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

class SEOProStack_Site_Template_Menus {

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $location Theme menu location.
     * @param array                         $menu Manifest menu.
     * @return true|WP_Error
     */
    public static function apply($run, $location, $menu) {
        $locations = get_nav_menu_locations();
        $old = $run->record['menus'][$location] ?? array();
        list($status, $existing) = self::owned($old, $location, $locations);
        if ('' !== $status) {
            $run->report('menus/' . $location, $status);
            return true;
        }
        $id = $old['id'] ?? 0;
        if (!$id && !$run->dry) {
            $id = self::create($run, $location, $menu['name'], $locations);
            if (is_wp_error($id)) {
                return $id;
            }
        }
        foreach ($menu['items'] as $key => $item) {
            $result = self::item($run, $location, $key, $item, $id, $existing, $old);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        $old['id'] = $id;
        $run->record['menus'][$location] = $old;
        return true;
    }

    /**
     * Whether the location's menu is still the template's.
     *
     * @param array  $old Menu ownership.
     * @param string $location Theme menu location.
     * @param array  $locations Current locations.
     * @return array Status ('' when owned or new) and the owned menu.
     */
    private static function owned($old, $location, $locations) {
        if (!$old && !empty($locations[$location])) {
            return array('edited-on-site', false);
        }
        $id = $old['id'] ?? 0;
        $menu = $id ? wp_get_nav_menu_object($id) : false;
        if ($id && (!$menu || ($locations[$location] ?? 0) !== $id)) {
            return array('removed-on-site', $menu);
        }
        return array('', $menu);
    }

    /**
     * Journal, create the menu and assign it to the location.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $location Theme menu location.
     * @param string                        $name Menu name.
     * @param array                         $locations Current locations.
     * @return int|WP_Error Menu.
     */
    private static function create($run, $location, $name, $locations) {
        $run->journal('retained_menu', $location, null, null);
        $id = wp_create_nav_menu($name);
        if (is_wp_error($id)) {
            return $id;
        }
        $run->journal('locations', get_stylesheet(), $locations, array_merge($locations, array($location => $id)));
        $locations[$location] = $id;
        set_theme_mod('nav_menu_locations', $locations);
        return $id;
    }

    /** @param WP_Post $post Menu item. @return array Title, URL and status as stored. */
    public static function current($post) {
        return array('title' => $post->post_title, 'url' => get_post_meta($post->ID, '_menu_item_url', true), 'status' => $post->post_status);
    }

    /**
     * Compare or write one item.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $location Theme menu location.
     * @param string                        $key Item key.
     * @param array                         $item Manifest item.
     * @param int                           $id Menu.
     * @param WP_Term|false                 $existing Menu as owned before this run.
     * @param array                         $old Menu ownership, updated in place.
     * @return true|WP_Error
     */
    private static function item($run, $location, $key, $item, $id, $existing, &$old) {
        $path = 'menus/' . $location . '/' . $key;
        $tracked = $old['items'][$key] ?? array();
        $post = !empty($tracked['id']) ? get_post($tracked['id']) : null;
        $current = $post ? self::current($post) : null;
        $desired = self::wanted($run, $path, $item, $tracked, $post, $current, $existing);
        if (null === $desired) {
            return true;
        }
        $item_id = self::write($run, $path, $post, $current, $desired, $id);
        if (is_wp_error($item_id)) {
            return $item_id;
        }
        $old['items'][$key] = array('id' => $item_id, 'hash' => SEOProStack_Site_Template_Run::fingerprint($desired));
        update_post_meta($item_id, SEOProStack_Site_Templates::META, $path);
        $old['id'] = $id;
        $run->record['menus'][$location] = $old;
        $run->save();
        SEOProStack_Site_Template_Run::purge();
        return true;
    }

    /**
     * Report the item; return what to write, or null when nothing is written.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Resource key.
     * @param array                         $item Manifest item.
     * @param array                         $tracked Item ownership.
     * @param WP_Post|null                  $post Tracked item.
     * @param array|null                    $current Stored item.
     * @param WP_Term|false                 $menu Owned menu.
     * @return array|null
     */
    private static function wanted($run, $path, $item, $tracked, $post, $current, $menu) {
        $kept = $tracked ? self::kept($post, $path, $menu, $current, $tracked['hash']) : '';
        if ('' !== $kept) {
            $run->report($path, $kept);
            return null;
        }
        $desired = array('title' => $item['title'], 'url' => esc_url_raw($item['url']), 'status' => $post ? $post->post_status : 'publish');
        $run->report($path, $desired === $current ? 'same' : ($post ? 'updated' : 'added'));
        return $desired === $current || $run->dry ? null : $desired;
    }

    /**
     * Why a tracked item stays as the site has it, or ''.
     *
     * @param WP_Post|null  $post Tracked item.
     * @param string        $path Resource key.
     * @param WP_Term|false $menu Owned menu.
     * @param array|null    $current Stored item.
     * @param string        $hash Owned hash.
     * @return string
     */
    private static function kept($post, $path, $menu, $current, $hash) {
        if (!$post || 'trash' === $post->post_status || 'nav_menu_item' !== $post->post_type || get_post_meta($post->ID, SEOProStack_Site_Templates::META, true) !== $path || !$menu || !has_term($menu->term_id, 'nav_menu', $post->ID)) {
            return 'removed-on-site';
        }
        return SEOProStack_Site_Template_Run::fingerprint($current) !== $hash ? 'edited-on-site' : '';
    }

    /**
     * Journal, then insert or update the item.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Resource key.
     * @param WP_Post|null                  $post Tracked item.
     * @param array|null                    $current Stored item.
     * @param array                         $desired Intended item.
     * @param int                           $menu Menu.
     * @return int|WP_Error Item.
     */
    private static function write($run, $path, $post, $current, $desired, $menu) {
        // A stable post name makes a creation discoverable after a crash.
        // Existing items update only title and URL: core's menu helper
        // otherwise defaults (and clears) unmentioned site-owned fields.
        $marker = 'sps-template-' . substr(hash('sha256', $path), 0, 32);
        $meta = array('_menu_item_url' => $desired['url']);
        if (!$post) {
            $meta += array('_menu_item_type' => 'custom', '_menu_item_menu_item_parent' => '0', '_menu_item_object_id' => '0', '_menu_item_object' => 'custom', SEOProStack_Site_Templates::META => $path);
        }
        $data = array('post_type' => 'nav_menu_item', 'post_status' => $desired['status'], 'post_title' => $desired['title'], 'meta_input' => $meta);
        if ($post) {
            $data['ID'] = $post->ID;
        } else {
            $data['post_name'] = $marker;
        }
        $run->journal('menu_item', $post ? $post->ID : 0, $current, $desired);
        $run->amend('lookup', array('slug' => $post ? $post->post_name : $marker, 'type' => 'nav_menu_item', 'key' => $path));
        $run->save();
        $item_id = $post ? wp_update_post(wp_slash($data), true) : wp_insert_post(wp_slash($data), true);
        if (is_wp_error($item_id)) {
            return $item_id;
        }
        if (!$post) {
            // Cron has no current user; tax_input's capability check
            // would silently leave the new item outside the menu.
            $terms = wp_set_object_terms($item_id, array($menu), 'nav_menu');
            if (is_wp_error($terms)) {
                return $terms;
            }
        }
        $run->amend('id', $item_id);
        return $item_id;
    }
}
