<?php
/**
 * Search custom fields.
 *
 * With Advanced Custom Fields or Secure Custom Fields active, site searches
 * and admin list searches also match the values of text-like fields: text,
 * text area, WYSIWYG, email, URL, number, select, checkbox and radio,
 * including fields inside repeater, group and flexible content fields.
 *
 * Fields are found through the reference ACF stores next to each value
 * (`_{meta key}` => `field_…`), so repeater rows (`list_0_name`) count
 * without listing every row. As in core, each search word must match
 * somewhere in the post (title, excerpt, content or a field), and `-word`
 * leaves out posts that have it anywhere.
 *
 * Fields listed in Fields to leave out (Troubleshooting), and fields a
 * plugin leaves out with the `seoprostack_field_search_field` filter, are
 * not searched; leaving out a group, repeater or flexible content field
 * leaves out every field inside it.
 *
 * Only the main query of a search page or admin list: widgets, blocks,
 * REST requests and the media library keep core's search.
 *
 * Replaces ACF: Better Search.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Field_Search extends SEOProStack_Feature {

    const KEY = 'field_search';

    /** Field names or keys that are never searched, one per line. */
    const SKIP_KEY = 'field_search_skip';

    /** Field types whose values are searched. */
    const TYPES = array('text', 'textarea', 'wysiwyg', 'email', 'url', 'number', 'select', 'checkbox', 'radio');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'content',
                'label'       => __('Search custom fields', 'seoprostack'),
                'description' => __('Searches on the site and in admin lists also look in text fields made with Advanced Custom Fields or Secure Custom Fields, including repeater and flexible content rows.', 'seoprostack'),
                'replaces'    => array('acf-better-search' => 'ACF: Better Search'),
            ),
            // Plugins that define fields leave theirs out with the
            // seoprostack_field_search_field filter; this list is the bypass
            // for site owners without code, so it sits under Troubleshooting.
            self::SKIP_KEY => array(
                'type'        => 'lines',
                'default'     => '',
                'rows'        => 4,
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'label'       => __('Fields to leave out', 'seoprostack'),
                'description' => __('One field name or field key per line. Searches never look in these fields; a group, repeater or flexible content field leaves out everything in it. Add fields your pages never show, such as private notes: a search shows no values, but a match tells which post holds what someone searched for.', 'seoprostack'),
            ),
        );
    }

    /**
     * Switch on while ACF: Better Search is active. Its choice of field
     * types is not needed: the text-like types are always searched.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['acf-better-search']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('posts_search', array(__CLASS__, 'search'), 10, 2);
    }

    /**
     * Keys (field_…) of the searched fields, sub fields included, once per
     * request and context.
     *
     * @param string $context 'site' for searches on the site, 'admin' for
     *                        admin list searches.
     * @return string[]
     */
    public static function field_keys($context = 'site') {
        return self::field_list($context)['keys'];
    }

    /**
     * Exact value names and escaped row patterns, or none for unsafe containers.
     *
     * @param string $context 'site' or 'admin'.
     * @return array
     */
    public static function field_names($context = 'site') {
        return self::field_list($context)['names'];
    }

    /**
     * Collect keys and names together so filters run only once per context.
     *
     * @param string $context 'site' or 'admin'.
     * @return array
     */
    private static function field_list($context) {
        static $cache = array();
        $context = 'admin' === $context ? 'admin' : 'site';
        if (isset($cache[$context])) {
            return $cache[$context];
        }
        $keys = array();
        $names = array('exact' => array(), 'patterns' => array());
        $safe = true;
        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            $skip = preg_split('/[\r\n]+/', (string) SEOProStack_Settings::get(self::SKIP_KEY)) ?: array();
            $skip = array_values(array_filter(array_map('trim', $skip), function ($line) {
                return '' !== $line;
            }));
            foreach ((array) acf_get_field_groups() as $group) {
                self::collect((array) acf_get_fields($group), $keys, $skip, $context, $names, $safe);
            }
        }
        $cache[$context] = array(
            'keys'  => array_values(array_unique($keys)),
            'names' => $safe ? array_map(function ($values) {
                return array_values(array_unique($values));
            }, $names) : array(),
        );
        return $cache[$context];
    }

    /**
     * Add the searched fields' keys, looking inside repeaters, groups and
     * flexible content layouts. A field that is left out takes every field
     * inside it with it.
     *
     * @param array    $fields  Fields.
     * @param string[] $keys    Keys found so far.
     * @param string[] $skip    Field names and keys in Fields to leave out.
     * @param string   $context 'site' or 'admin'.
     * @param array    $names   Exact names and escaped row patterns.
     * @param bool     $safe    Whether every searched name can be derived.
     * @param array    $path    Name segments; null represents a row index.
     * @param bool     $known   Whether the ancestor containers are supported.
     */
    private static function collect(array $fields, array &$keys, array $skip, $context, array &$names, &$safe, array $path = array(), $known = true) {
        global $wpdb;
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['key'])) {
                continue;
            }
            $listed = in_array((string) $field['key'], $skip, true)
                || (isset($field['name']) && in_array((string) $field['name'], $skip, true));
            /**
             * Filter whether Search custom fields looks in a field's values.
             *
             * Runs once per request and context for each field, before the
             * fields inside it: returning false for a group, repeater or
             * flexible content field leaves out every field inside it. A
             * field listed in Fields to leave out stays out whatever this
             * returns.
             *
             * @param bool   $search  Whether to search the field. False when
             *                        Fields to leave out lists it.
             * @param array  $field   ACF field array (key, name, type, parent…).
             * @param string $context 'site' for searches on the site, 'admin'
             *                        for admin list searches.
             */
            $search = apply_filters('seoprostack_field_search_field', !$listed, $field, $context);
            if ($listed || !$search) {
                continue;
            }
            $named = isset($field['name']) && '' !== (string) $field['name'];
            $value_path = array_merge($path, array($named ? (string) $field['name'] : ''));
            if (isset($field['type']) && in_array($field['type'], self::TYPES, true)) {
                $keys[] = (string) $field['key'];
                if (!$known || !$named) {
                    $safe = false;
                } elseif (in_array(null, $value_path, true)) {
                    $segments = array_map(function ($segment) use ($wpdb) {
                        return null === $segment ? '%' : $wpdb->esc_like($segment);
                    }, $value_path);
                    $names['patterns'][] = implode($wpdb->esc_like('_'), $segments);
                } else {
                    $names['exact'][] = implode('_', $value_path);
                }
            }
            $type = isset($field['type']) ? $field['type'] : '';
            $child_known = $known && $named && in_array($type, array('group', 'repeater', 'flexible_content'), true);
            if (in_array($type, array('repeater', 'flexible_content'), true)) {
                $value_path[] = null;
            }
            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::collect($field['sub_fields'], $keys, $skip, $context, $names, $safe, $value_path, $child_known);
            }
            if (!empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (!empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                        self::collect($layout['sub_fields'], $keys, $skip, $context, $names, $safe, $value_path, $child_known);
                    }
                }
            }
        }
    }

    /**
     * Rebuild the search clause of a main search query with the fields.
     *
     * @param string   $search Search SQL from core.
     * @param WP_Query $query  Query.
     * @return string
     */
    public static function search($search, $query) {
        global $wpdb;
        if ('' === $search || !$query instanceof WP_Query || !$query->is_main_query() || !$query->is_search()) {
            return $search;
        }
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || 'attachment' === $query->get('post_type')) {
            return $search;
        }
        // A query that chose its own columns keeps them (WordPress 6.2+).
        $columns = $query->get('search_columns');
        if (!empty($columns) && array_diff(array('post_title', 'post_excerpt', 'post_content'), (array) $columns)) {
            return $search;
        }
        $terms = (array) $query->get('search_terms');
        $keys  = self::field_keys(is_admin() ? 'admin' : 'site');
        if (!$terms || !$keys) {
            return $search;
        }

        $n      = $query->get('exact') ? '' : '%';
        /** This filter is documented in wp-includes/class-wp-query.php */
        $prefix = apply_filters('wp_query_search_exclusion_prefix', '-'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter.
        $in     = implode(',', array_fill(0, count($keys), '%s'));
        $names  = self::field_names(is_admin() ? 'admin' : 'site');
        $name_conditions = array();
        $name_values = array();
        if (!empty($names['exact'])) {
            $name_conditions[] = 'sps_v.meta_key IN (' . implode(',', array_fill(0, count($names['exact']), '%s')) . ')';
            $name_values = $names['exact'];
        }
        foreach ($names['patterns'] ?? array() as $pattern) {
            $name_conditions[] = 'sps_v.meta_key LIKE %s';
            $name_values[] = $pattern;
        }
        $name_sql = $name_conditions ? ' AND (' . implode(' OR ', $name_conditions) . ')' : '';
        $parts  = array();
        foreach ($terms as $term) {
            $term    = (string) $term;
            $exclude = $prefix && 0 === strpos($term, $prefix);
            if ($exclude) {
                $term = substr($term, strlen($prefix));
            }
            if ('' === $term) {
                continue;
            }
            $like  = $n . $wpdb->esc_like($term) . $n;
            $field = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS sps_v INNER JOIN {$wpdb->postmeta} AS sps_r ON sps_r.post_id = sps_v.post_id AND sps_r.meta_key = CONCAT('_', sps_v.meta_key) WHERE sps_v.post_id = {$wpdb->posts}.ID AND sps_r.meta_value IN ($in){$name_sql} AND sps_v.meta_value LIKE %s)";
            $sql   = $exclude
                ? "(({$wpdb->posts}.post_title NOT LIKE %s) AND ({$wpdb->posts}.post_excerpt NOT LIKE %s) AND ({$wpdb->posts}.post_content NOT LIKE %s) AND NOT {$field})"
                : "(({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s) OR {$field})";
            // One prepare for the whole part, so % in a search word is never read as a placeholder.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value, built from counts only.
            $parts[] = $wpdb->prepare($sql, array_merge(array($like, $like, $like), $keys, $name_values, array($like)));
        }
        if (!$parts) {
            return $search;
        }
        $search = ' AND (' . implode(' AND ', $parts) . ') ';
        if (!is_user_logged_in()) {
            $search .= " AND ({$wpdb->posts}.post_password = '') ";
        }
        return $search;
    }
}
