<?php
/**
 * Code Snippets audit: a Site Health test, shown while Code Snippets (or
 * Code Snippets Pro) is active, that names snippets which slow pages or are
 * not needed, and the SEO Pro Stack setting that replaces a snippet where
 * there is one.
 *
 * It only reads Code Snippets' table and never changes a snippet. Checks:
 *
 * - Active front-end styles and scripts (and admin styles): Code Snippets
 *   Pro serves them through a separate request to WordPress on every page
 *   (such as /?code-snippets-js-snippets=footer), which page caches skip.
 * - Active content snippets that no post, widget or theme setting uses.
 * - Inactive snippets untouched for six months, and Code Snippets' samples.
 * - Code a setting replaces (lists in REPLACES).
 * - save_post code that saves the post again without a guard, so every
 *   save writes the post twice.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.12.8
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Snippets_Audit extends SEOProStack_Feature {

    /** Site Health test. */
    const TEST = 'seoprostack-snippets';

    /** Most snippets read. */
    const MAX_SNIPPETS = 500;

    /** Inactive snippets untouched this long are named. */
    const STALE = 15552000; // 180 days.

    /** Sentence Code Snippets adds to the description of its samples. */
    const SAMPLE = 'This is a sample snippet.';

    /**
     * Snippet code that an SEO Pro Stack setting replaces: setting key =>
     * patterns that must all match.
     */
    const REPLACES = array(
        'sticky_posts'          => array('/kadence_blocks_pro_query_loop_query_vars/', '/sticky/i'),
        'rank_math_defaults'    => array('/rank_math_focus_keyword/'),
        'term_tools'            => array('/[\'"]created_/', '/wp_update_term\s*\(/'),
        'field_content'         => array('/get_field_objects\s*\(/', '/wp_update_post\s*\(/'),
        'kadence_filter_scroll' => array('/kb-query|wp-block-kadence-query/', '/scroll(IntoView|To|Top)/'),
    );

    /** Register hooks. */
    public static function boot() {
        // Always on: it only gives advice. Site Health also runs its tests
        // from cron, outside wp-admin.
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        if (is_admin()) {
            add_action('wp_ajax_health-check-' . self::TEST, array(__CLASS__, 'ajax_test'));
        }
    }

    /**
     * Add the test while Code Snippets is active and has its table.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        if (self::tables()) {
            $tests['async'][self::TEST] = array(
                'label'             => __('Code Snippets', 'seoprostack'),
                'test'              => self::TEST,
                'async_direct_test' => array(__CLASS__, 'test'),
            );
        }
        return $tests;
    }

    /**
     * Run the test for the Site Health screen.
     */
    public static function ajax_test() {
        check_ajax_referer('health-check-site-status');
        if (!current_user_can('view_site_health_checks')) {
            wp_send_json_error();
        }
        wp_send_json_success(self::test());
    }

    /**
     * Code Snippets' tables on this site: the site's, and the network's on
     * multisite. Empty while no Code Snippets plugin is active.
     *
     * @return array<string,bool> table => whether it holds network snippets
     */
    public static function tables() {
        $active = false;
        foreach (array_keys(self::active_plugins()) as $slug) {
            if (0 === strpos($slug, 'code-snippets')) {
                $active = true;
                break;
            }
        }
        if (!$active) {
            return array();
        }
        global $wpdb;
        $tables = array($wpdb->prefix . 'snippets' => false);
        if (is_multisite()) {
            $tables[$wpdb->base_prefix . 'ms_snippets'] = true;
        }
        foreach (array_keys($tables) as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- on-demand check for another plugin's table.
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
                unset($tables[$table]);
            }
        }
        return $tables;
    }

    /**
     * Snippets, newest first.
     *
     * @return array<int,array{id:int,name:string,description:string,code:string,scope:string,active:bool,modified:string,network:bool}>
     */
    private static function snippets() {
        global $wpdb;
        $snippets = array();
        foreach (self::tables() as $table => $network) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads another plugin's table for a Site Health test; nothing cached.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id, name, description, code, scope, active, modified FROM %i ORDER BY modified DESC LIMIT %d', $table, self::MAX_SNIPPETS), ARRAY_A);
            foreach ((array) $rows as $row) {
                $snippets[] = array(
                    'id'          => (int) $row['id'],
                    'name'        => (string) $row['name'],
                    'description' => (string) $row['description'],
                    'code'        => (string) $row['code'],
                    'scope'       => (string) $row['scope'],
                    'active'      => (bool) $row['active'],
                    'modified'    => (string) $row['modified'],
                    'network'     => $network,
                );
            }
        }
        return $snippets;
    }

    /**
     * IDs of content snippets used in posts (any status but trash, through
     * the [code_snippet] shortcode or Code Snippets Pro's block), widgets and
     * the theme's settings.
     *
     * @return array<int,true>
     */
    private static function used_ids() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one search for a Site Health test; nothing cached.
        $texts   = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft') AND (post_content LIKE %s OR post_content LIKE %s)",
            '%' . $wpdb->esc_like('[code_snippet') . '%',
            '%' . $wpdb->esc_like('wp:code-snippets/') . '%'
        ));
        $options = array('widget_text', 'widget_block', 'widget_custom_html');
        foreach ($options as $option) {
            $texts[] = maybe_serialize(get_option($option, array()));
        }
        $texts[] = maybe_serialize(get_theme_mods());
        $used    = array();
        foreach ($texts as $text) {
            $text = (string) $text;
            if (preg_match_all('/\[code_snippet\b[^\]]*\]/', $text, $shortcodes)) {
                foreach ($shortcodes[0] as $shortcode) {
                    if (preg_match('/\b(?:snippet_id|id)\s*=\s*["\']?(\d+)/', $shortcode, $id)) {
                        $used[(int) $id[1]] = true;
                    }
                }
            }
            if (preg_match_all('/wp:code-snippets\/[a-z-]+\s+\{[^}]*"snippet_id"\s*:\s*"?(\d+)/', $text, $blocks)) {
                foreach ($blocks[1] as $id) {
                    $used[(int) $id] = true;
                }
            }
        }
        return $used;
    }

    /**
     * What to say about each snippet.
     *
     * @return array<int,array{snippet:array,notes:string[]}>
     */
    public static function findings() {
        $snippets = self::snippets();
        if (!$snippets) {
            return array();
        }
        $schema   = SEOProStack_Settings::schema();
        $used     = null;
        $stale    = gmdate('Y-m-d H:i:s', time() - self::STALE);
        $findings = array();
        foreach ($snippets as $snippet) {
            $notes = array();
            $scope = $snippet['scope'];
            if (false !== strpos($snippet['description'], self::SAMPLE)) {
                $notes[] = __('a sample from Code Snippets: delete it unless you use it.', 'seoprostack');
            } elseif (!$snippet['active'] && '' !== $snippet['modified'] && $snippet['modified'] < $stale) {
                $notes[] = __('off and unchanged for over six months: delete it unless you plan to use it.', 'seoprostack');
            }
            if ($snippet['active']) {
                if (in_array($scope, array('site-css', 'site-head-js', 'site-footer-js'), true)) {
                    $notes[] = __('served through a separate request to WordPress on every page, which page caches skip. Add it to the theme’s custom CSS or a block on the pages that need it.', 'seoprostack');
                } elseif ('admin-css' === $scope) {
                    $notes[] = __('served through a separate request to WordPress on every admin screen.', 'seoprostack');
                } elseif ('content' === $scope) {
                    if (null === $used) {
                        $used = self::used_ids();
                    }
                    if (!isset($used[$snippet['id']])) {
                        $notes[] = __('not used in any post, widget or theme setting. Check page builders’ own content before turning it off.', 'seoprostack');
                    }
                }
                foreach (self::REPLACES as $key => $patterns) {
                    if (empty($schema[$key]['label']) || !self::matches($snippet['code'], $patterns)) {
                        continue;
                    }
                    $tab     = isset($schema[$key]['tab']) ? (string) $schema[$key]['tab'] : '';
                    $notes[] = sprintf(
                        /* translators: %s: link to an SEO Pro Stack setting */
                        __('SEO Pro Stack’s %s does this: switch it on, then turn this snippet off.', 'seoprostack'),
                        '<a href="' . esc_url(admin_url('options-general.php?page=seoprostack' . ('' !== $tab ? '&tab=' . rawurlencode($tab) : ''))) . '">' . esc_html($schema[$key]['label']) . '</a>'
                    );
                }
                if (preg_match('/[\'"]save_post/', $snippet['code']) && preg_match('/wp_update_post\s*\(/', $snippet['code']) && !preg_match('/remove_action\s*\(|remove_filter\s*\(|did_action\s*\(|static\s+\$/', $snippet['code'])) {
                    $notes[] = __('saves the post again from save_post, so every save writes the post twice (and runs save_post again). Remove its hook before saving, or save only when a value changes.', 'seoprostack');
                }
            }
            if ($notes) {
                $findings[] = array('snippet' => $snippet, 'notes' => $notes);
            }
        }
        return $findings;
    }

    /**
     * Whether all patterns match.
     *
     * @param string   $code     Snippet code.
     * @param string[] $patterns Regular expressions.
     * @return bool
     */
    private static function matches($code, array $patterns) {
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $code)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Link to edit a snippet in Code Snippets, as its Plugin::get_menu_url()
     * builds it (3.10): under Tools when its compact menu filter is on.
     *
     * @param array $snippet Snippet.
     * @return string
     */
    private static function edit_url(array $snippet) {
        if ($snippet['network']) {
            return network_admin_url('admin.php?page=edit-snippet&id=' . $snippet['id']);
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Code Snippets' own filter.
        $compact = apply_filters('code_snippets_compact_menu', false);
        return admin_url(($compact ? 'tools.php?page=snippets&sub=edit-snippet' : 'admin.php?page=edit-snippet') . '&id=' . $snippet['id']);
    }

    /**
     * The test.
     *
     * @return array
     */
    public static function test() {
        $findings = self::findings();
        $result   = array(
            'label'       => __('Code Snippets has no snippets that slow pages or are not needed', 'seoprostack'),
            'status'      => 'good',
            'badge'       => array(
                'label' => __('Performance', 'seoprostack'),
                'color' => 'blue',
            ),
            'description' => '<p>' . esc_html__('SEO Pro Stack looks for snippets that add a request to every page, content snippets nothing uses, old inactive snippets and samples, code one of its settings replaces, and code that saves a post twice. Snippets are never changed.', 'seoprostack') . '</p>',
            'actions'     => '',
            'test'        => 'seoprostack_snippets',
        );
        if (!$findings) {
            return $result;
        }
        $result['status'] = 'recommended';
        $result['label']  = sprintf(
            /* translators: %d: number of snippets */
            _n('%d code snippet slows pages or is not needed', '%d code snippets slow pages or are not needed', count($findings), 'seoprostack'),
            count($findings)
        );
        $items = '';
        foreach ($findings as $finding) {
            $snippet = $finding['snippet'];
            $name    = '' !== trim($snippet['name']) ? $snippet['name'] : sprintf(
                /* translators: %d: snippet ID */
                __('Snippet #%d', 'seoprostack'),
                $snippet['id']
            );
            $items  .= '<li><a href="' . esc_url(self::edit_url($snippet)) . '">' . esc_html($name) . '</a>';
            $items  .= $snippet['network'] ? ' (' . esc_html__('network', 'seoprostack') . ')' : '';
            $items  .= ': ' . wp_kses(implode(' ', $finding['notes']), array('a' => array('href' => array()))) . '</li>';
        }
        $result['description'] .= '<ul>' . $items . '</ul>';
        return $result;
    }
}
