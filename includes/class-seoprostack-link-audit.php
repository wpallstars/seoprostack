<?php
/**
 * Aggregate-only Link Whisper retirement evidence, never a deactivation tool.
 * Third-party settings, tables and stored content remain untouched.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Link_Audit {
    /**
     * Read a known table, distinguishing missing schema and query failure.
     *
     * @param string               $suffix Constant table suffix from collect().
     * @param array<string,string> $where  Optional: one column => value to
     *                                     count; the column must exist.
     * @return int|null
     */
    private static function count($suffix, array $where = array()) {
        global $wpdb;
        if (!SEOProStack_Link_Index::has_table($suffix)) {
            return '' === $wpdb->last_error ? 0 : null;
        }
        $table = $wpdb->prefix . $suffix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- a one-off read of another plugin's tables for the retirement report; nothing to cache.
        if ($where) {
            $column = (string) key($where);
            if (!in_array($column, (array) $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table)), true)) {
                return null;
            }
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %s', $table, $column, $where[$column]));
        } else {
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
        return '' === $wpdb->last_error && null !== $count ? (int) $count : null;
    }

    /** @return array<string,mixed> No keywords, content, visitor details or third-party credentials. */
    public static function collect() {
        $active = SEOProStack_Feature::active_plugins();
        $whisper = isset($active['link-whisper-premium']) || isset($active['link-whisper']);
        $findings = array();
        $rules = array(
            'wpil_keywords' => __('Keyword autolinking rules', 'seoprostack'),
            'wpil_urls' => __('URL replacement rules', 'seoprostack'),
            'wpil_keyword_links' => __('Autolink insertion history', 'seoprostack'),
            'wpil_url_links' => __('URL replacement history', 'seoprostack'),
        );
        foreach ($rules as $table => $label) {
            $count = self::count($table);
            $findings[] = array('item' => $label, 'status' => null === $count ? 'unknown' : ($count ? 'blocked' : 'clear'), 'count' => $count, 'note' => __('Saved rules and their undo history are not imported. Export and validate replacements before retiring Link Whisper.', 'seoprostack'));
        }
        $custom = self::count('wpil_target_keyword_data', array('keyword_type' => 'custom-keyword'));
        $findings[] = array('item' => __('Custom target keywords', 'seoprostack'), 'status' => null === $custom ? 'unknown' : ($custom ? 'blocked' : 'clear'), 'count' => $custom, 'note' => __('Rank Math keywords remain available. Link Whisper-only custom keywords need an explicit migration.', 'seoprostack'));
        $clicks = self::count('wpil_click_data');
        $tracking = $whisper && !get_option('wpil_disable_click_tracking', false);
        $findings[] = array('item' => __('Click tracking and history', 'seoprostack'), 'status' => null === $clicks ? 'unknown' : (($tracking || $clicks) ? 'review' : 'clear'), 'count' => $clicks, 'note' => __('Decide whether to keep counting. SEO Pro Stack counts anonymous events only; it does not import visitor records or reproduce unique-visitor reports. Preserve historical data separately.', 'seoprostack'));
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate dependency check; never returns content.
        $shortcodes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s", '%' . $wpdb->esc_like('[wpil') . '%related%'));
        $shortcode_error = '' !== $wpdb->last_error;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- owner-requested aggregate check over the indexed meta key.
        $related = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT IN ('','0')", 'wpil_related_posts_active'));
        $related_error = '' !== $wpdb->last_error;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only the presence of a widget reference is returned.
        $widgets = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", $wpdb->esc_like('widget_') . '%', '%' . $wpdb->esc_like('wpil_related') . '%'));
        $related_count = (int) $shortcodes + (int) $related + (int) $widgets + (get_option('wpil_activate_related_posts', false) ? 1 : 0);
        $findings[] = array('item' => __('Related posts and shortcode references', 'seoprostack'), 'status' => $shortcode_error || $related_error || '' !== $wpdb->last_error ? 'unknown' : ($related_count ? 'blocked' : 'clear'), 'count' => $related_count, 'note' => __('Related-post displays are not replaced by the linking toolkit. References include drafts and saved widgets; verify rendered pages too.', 'seoprostack'));
        $attributes = array('wpil_add_nofollow', 'wpil_external_links_open_new_tab', 'wpil_js_open_new_tabs', 'wpil_open_all_external_new_tab', 'wpil_open_all_internal_new_tab', 'wpil_2_links_open_new_tab', 'wpil_nofollow_domains', 'wpil_dofollow_domains', 'wpil_sponsored_domains', 'wpil_domains_marked_as_internal', 'wpil_link_external_sites');
        $changed = 0;
        foreach ($attributes as $option) {
            if (get_option($option, false)) {
                ++$changed;
            }
        }
        $findings[] = array('item' => __('Link attributes, domain rules and cross-site links', 'seoprostack'), 'status' => $changed ? 'blocked' : 'clear', 'count' => $changed, 'note' => __('Verify equivalent Rank Math or theme behaviour. SEO Pro Stack does not silently copy these choices.', 'seoprostack'));
        $icon = get_option('wpil_add_icon_to_external_link', 'never');
        $internal_icon = get_option('wpil_add_icon_to_internal_link', 'never');
        $icon_used = !in_array($icon, array('never', '', false, '0', 0), true);
        $internal_used = !in_array($internal_icon, array('never', '', false, '0', 0), true);
        $findings[] = array('item' => __('Link icons', 'seoprostack'), 'status' => $internal_used || ($icon_used && !SEOProStack_External_Links::enabled()) ? 'blocked' : 'clear', 'count' => ($icon_used ? 1 : 0) + ($internal_used ? 1 : 0), 'note' => __('SEO Pro Stack supports external text-link icons. Check theme styling on staging; internal-link icons are not replaced.', 'seoprostack'));
        $findings[] = array('item' => __('Rendered pages and custom integrations', 'seoprostack'), 'status' => 'review', 'count' => null, 'note' => __('A database audit cannot prove compatibility with custom code, builder fields, navigation or dynamic widgets. Validate a backed-up staging copy before any deactivation.', 'seoprostack'));
        $blocked = count(array_filter($findings, static function ($finding) {
            return in_array($finding['status'], array('blocked', 'unknown'), true);
        }));
        return array('checked_at' => gmdate('c'), 'blog_id' => get_current_blog_id(), 'link_whisper_active' => $whisper, 'rank_math_reporting' => 'rank_math' === SEOProStack_Link_Index::provider(), 'toolkit_enabled' => SEOProStack_Linking::switched_on(), 'click_counting_enabled' => (bool) SEOProStack_Settings::get('linking_clicks'), 'result' => $blocked ? 'blocked' : 'staging_validation_required', 'findings' => $findings);
    }
}
