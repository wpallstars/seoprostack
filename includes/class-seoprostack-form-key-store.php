<?php
/**
 * Fluent Forms by key: the keys forms carry, in Fluent Forms' own form meta
 * table (seoprostack_form_key). Fluent Forms' tables are per site, so every
 * lookup is the current site's.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.97
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Form_Key_Store {

    /** Form IDs resolved on this request. @var array<string,int> */
    private static $ids = array();

    /**
     * Whether a string is a valid key: lower-case words joined by hyphens.
     *
     * @param string $key Key.
     * @return bool
     */
    public static function valid($key) {
        return 1 === preg_match(SEOProStack_Form_Keys::PATTERN, (string) $key);
    }

    /**
     * The published form on this site that carries a key, or 0. The oldest
     * form wins a shared key.
     *
     * @param string $key Form key.
     * @return int
     */
    public static function form_id($key) {
        $key = (string) $key;
        if (!self::valid($key)) {
            return 0;
        }
        $cache = get_current_blog_id() . ':' . $key;
        if (!isset(self::$ids[$cache])) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own tables; resolved once per request.
            self::$ids[$cache] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT m.form_id FROM {$wpdb->prefix}fluentform_form_meta m INNER JOIN {$wpdb->prefix}fluentform_forms f ON f.id = m.form_id WHERE m.meta_key = %s AND m.value = %s AND f.status = 'published' ORDER BY m.form_id ASC LIMIT 1",
                SEOProStack_Form_Keys::META,
                $key
            ));
        }
        return self::$ids[$cache];
    }

    /**
     * The key a form carries, or ''.
     *
     * @param int $form_id Form ID.
     * @return string
     */
    public static function key_of($form_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table; once per submission.
        $key = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d AND meta_key = %s ORDER BY id ASC LIMIT 1",
            (int) $form_id,
            SEOProStack_Form_Keys::META
        ));
        return self::valid($key) ? $key : '';
    }

    /**
     * Keyed published forms, for the editor: key => form title.
     *
     * @return array<string,string>
     */
    public static function keyed_forms() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own tables; editor only.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.value AS form_key, f.title FROM {$wpdb->prefix}fluentform_form_meta m INNER JOIN {$wpdb->prefix}fluentform_forms f ON f.id = m.form_id WHERE m.meta_key = %s AND f.status = 'published' ORDER BY m.form_id ASC",
            SEOProStack_Form_Keys::META
        ));
        $forms = array();
        foreach ((array) $rows as $row) {
            $key = (string) $row->form_key;
            if (self::valid($key) && !isset($forms[$key])) {
                $forms[$key] = (string) $row->title;
            }
        }
        return $forms;
    }

    /**
     * Every form with its key and, optionally, its value of another meta key.
     *
     * @param string $other Another form meta key, or ''.
     * @return array<int,array<string,mixed>>
     */
    public static function forms($other = '') {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own tables.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id, f.title, f.status, (SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = f.id AND meta_key = %s ORDER BY id ASC LIMIT 1) AS form_key, (SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = f.id AND meta_key = %s ORDER BY id ASC LIMIT 1) AS other FROM {$wpdb->prefix}fluentform_forms f ORDER BY f.id ASC",
            SEOProStack_Form_Keys::META,
            '' !== (string) $other ? (string) $other : SEOProStack_Form_Keys::META
        ), ARRAY_A);
        return (array) $rows;
    }

    /**
     * Forms without a key that have another meta key, with that value.
     *
     * @param string $other Another form meta key.
     * @return array<int,string> Form ID => that meta value.
     */
    public static function unkeyed_with($other) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table.
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT o.form_id, o.value FROM {$wpdb->prefix}fluentform_form_meta o WHERE o.meta_key = %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}fluentform_form_meta k WHERE k.form_id = o.form_id AND k.meta_key = %s) ORDER BY o.form_id ASC",
            (string) $other,
            SEOProStack_Form_Keys::META
        ), ARRAY_A);
        $forms = array();
        foreach ((array) $rows as $row) {
            $id = (int) ($row['form_id'] ?? 0);
            if ($id && !isset($forms[$id])) {
                $forms[$id] = (string) ($row['value'] ?? '');
            }
        }
        return $forms;
    }

    /**
     * Whether a form exists on this site.
     *
     * @param int $form_id Form ID.
     * @return bool
     */
    public static function exists($form_id) {
        global $wpdb;
        if ((int) $form_id < 1) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table.
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", (int) $form_id));
    }

    /**
     * Give a form its key, replacing any it had; '' removes it.
     *
     * @param int    $form_id Form ID.
     * @param string $key     Key, or '' to remove.
     * @return bool
     */
    public static function set($form_id, $key) {
        global $wpdb;
        $meta = $wpdb->prefix . 'fluentform_form_meta';
        self::$ids = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms' own table, indexed by form.
        $wpdb->delete($meta, array('form_id' => (int) $form_id, 'meta_key' => SEOProStack_Form_Keys::META));
        if ('' === (string) $key) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms' own table; one row.
        return (bool) $wpdb->insert($meta, array('form_id' => (int) $form_id, 'meta_key' => SEOProStack_Form_Keys::META, 'value' => (string) $key));
    }
}
