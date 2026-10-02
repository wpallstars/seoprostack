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
 * Only the main query of a search page or admin list: widgets, blocks,
 * REST requests and the media library keep core's search.
 *
 * Replaces ACF: Better Search.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Field_Search extends SEOProStack_Feature {

    const KEY = 'field_search';

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
     * Keys (field_…) of the searched fields, sub fields included.
     *
     * @return string[]
     */
    public static function field_keys() {
        static $keys = null;
        if (null !== $keys) {
            return $keys;
        }
        $keys = array();
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $keys;
        }
        foreach ((array) acf_get_field_groups() as $group) {
            self::collect((array) acf_get_fields($group), $keys);
        }
        $keys = array_values(array_unique($keys));
        return $keys;
    }

    /**
     * Add the searched fields' keys, looking inside repeaters, groups and
     * flexible content layouts.
     *
     * @param array    $fields Fields.
     * @param string[] $keys   Keys found so far.
     */
    private static function collect(array $fields, array &$keys) {
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['key'])) {
                continue;
            }
            if (isset($field['type']) && in_array($field['type'], self::TYPES, true)) {
                $keys[] = (string) $field['key'];
            }
            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::collect($field['sub_fields'], $keys);
            }
            if (!empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (!empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                        self::collect($layout['sub_fields'], $keys);
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
        $keys  = self::field_keys();
        if (!$terms || !$keys) {
            return $search;
        }

        $n      = $query->get('exact') ? '' : '%';
        /** This filter is documented in wp-includes/class-wp-query.php */
        $prefix = apply_filters('wp_query_search_exclusion_prefix', '-'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter.
        $in     = implode(',', array_fill(0, count($keys), '%s'));
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
            $field = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS sps_v INNER JOIN {$wpdb->postmeta} AS sps_r ON sps_r.post_id = sps_v.post_id AND sps_r.meta_key = CONCAT('_', sps_v.meta_key) WHERE sps_v.post_id = {$wpdb->posts}.ID AND sps_r.meta_value IN ($in) AND sps_v.meta_value LIKE %s)";
            $sql   = $exclude
                ? "(({$wpdb->posts}.post_title NOT LIKE %s) AND ({$wpdb->posts}.post_excerpt NOT LIKE %s) AND ({$wpdb->posts}.post_content NOT LIKE %s) AND NOT {$field})"
                : "(({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s) OR {$field})";
            // One prepare for the whole part, so % in a search word is never read as a placeholder.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value, built from counts only.
            $parts[] = $wpdb->prepare($sql, array_merge(array($like, $like, $like), $keys, array($like)));
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
