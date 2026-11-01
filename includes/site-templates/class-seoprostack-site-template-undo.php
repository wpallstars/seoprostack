<?php
/**
 * Site templates: undo the last run from its journal.
 *
 * Every resource is checked before anything is touched, and nothing edited
 * since apply is overwritten. Created posts become drafts, never deleted;
 * imported media and menus stay usable.
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

class SEOProStack_Site_Template_Undo {

    /**
     * @param SEOProStack_Site_Template_Run $run Run holding the journal.
     * @return true|WP_Error
     */
    public static function restore($run) {
        self::recover($run);
        $run->save();
        $writes = array_reverse($run->record['backup']['writes'], true);
        $error = self::preflight($writes);
        if ($error) {
            return $error;
        }
        $run->record['undoing'] = true;
        $run->save();
        foreach ($writes as $index => $write) {
            if (!empty($write['undone'])) {
                continue;
            }
            $result = self::undo_write($write);
            if (is_wp_error($result)) {
                return $result;
            }
            $run->record['backup']['writes'][$index]['undone'] = true;
            $run->save();
        }
        $before = $run->record['backup']['record'];
        if ($before) {
            update_option(SEOProStack_Site_Templates::OPTION, $before, false);
        } else {
            delete_option(SEOProStack_Site_Templates::OPTION);
        }
        wp_clear_scheduled_hook(SEOProStack_Site_Templates::HOOK);
        // Do not immediately reapply the run being undone on the next request.
        update_option(SEOProStack_Site_Templates::SEEN, SEOPROSTACK_VERSION, false);
        SEOProStack_Site_Template_Run::purge();
        return true;
    }

    /**
     * Recover identities for journalled creations before inspecting storage.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     */
    private static function recover($run) {
        foreach ($run->record['backup']['writes'] as $index => $write) {
            if ($write['id']) {
                continue;
            }
            $lookup = self::lookup($write);
            if (!$lookup) {
                continue;
            }
            $found = get_page_by_path($lookup['slug'], OBJECT, $lookup['type']);
            if ($found && get_post_meta($found->ID, SEOProStack_Site_Templates::META, true) === $lookup['key']) {
                $run->record['backup']['writes'][$index]['id'] = $found->ID;
            }
        }
    }

    /** @param array $write Journal entry. @return array|null Slug, type and template key of a creation. */
    private static function lookup($write) {
        if ('post' === $write['type']) {
            $after = $write['after'];
            return array('slug' => $after['post_name'], 'type' => $after['post_type'], 'key' => $after['meta_input'][SEOProStack_Site_Templates::META] ?? null);
        }
        if ('menu_item' === $write['type'] && isset($write['lookup'])) {
            return $write['lookup'];
        }
        return null;
    }

    /**
     * Preflight every resource before touching anything. Multiple writes to
     * one target are checked against a virtual reverse-order snapshot.
     *
     * @param array $writes Journal, newest first.
     * @return WP_Error|null
     */
    private static function preflight($writes) {
        $virtual = array();
        foreach ($writes as $write) {
            if (!empty($write['undone'])) {
                continue;
            }
            $key = $write['type'] . '/' . $write['id'];
            $current = array_key_exists($key, $virtual) ? $virtual[$key] : SEOProStack_Site_Template_Journal::read($write);
            $target = self::target($write);
            if (!self::equal($current, $write['after']) && !self::equal($current, $write['before']) && !self::equal($current, $target)) {
                return new WP_Error('template_undo_edited', 'Undo stopped: changed since apply: ' . $key);
            }
            $virtual[$key] = $target;
        }
        return null;
    }

    /** @param array $write Journal entry. @return true|WP_Error */
    private static function undo_write($write) {
        $current = SEOProStack_Site_Template_Journal::read($write);
        $target = self::target($write);
        if (self::equal($current, $write['before']) || self::equal($current, $target)) {
            return true;
        }
        if (!self::equal($current, $write['after'])) {
            return new WP_Error('template_undo_edited', 'Resource changed during undo; stopped without overwriting it.');
        }
        $result = SEOProStack_Site_Template_Journal::restore($write);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!self::equal(SEOProStack_Site_Template_Journal::read($write), $target)) {
            return new WP_Error('template_undo_write', 'Undo write did not reach its recorded target.');
        }
        return true;
    }

    /**
     * Compare structured snapshots without depending on JSON/PHP key order.
     *
     * @param mixed $left First value.
     * @param mixed $right Second value.
     * @return bool
     */
    private static function equal($left, $right) {
        if (!is_array($left) || !is_array($right)) {
            return $left === $right;
        }
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::equal($value, $right[$key])) {
                return false;
            }
        }
        return true;
    }

    /** @param array $write Journal entry. @return mixed What undo leaves. */
    private static function target($write) {
        if (null === $write['before'] && 'post' === $write['type']) {
            return array_replace($write['after'], array('post_status' => 'draft'));
        }
        if (null === $write['before'] && 'menu_item' === $write['type']) {
            return array_replace($write['after'], array('status' => 'draft'));
        }
        return $write['before'];
    }
}
