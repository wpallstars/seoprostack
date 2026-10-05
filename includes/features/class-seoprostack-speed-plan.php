<?php
/**
 * Recommended speed settings: from this site's own size and set-up, which
 * of SEO Pro Stack's speed and server settings to turn on, why, and what
 * each costs, below the Speed tab's settings.
 *
 * Nothing changes until someone clicks. Each click turns on one setting
 * through the same path as the settings screen (SEOProStack_Settings::set()
 * then seoprostack_setting_saved), and is logged in OPTION (not
 * autoloaded) so it can be undone while the setting is still as the plan
 * left it. Sizes are the database's own estimates (information_schema).
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

class SEOProStack_Speed_Plan extends SEOProStack_Feature {

    const KEY    = 'speed_plan';
    const OPTION = 'seoprostack_speed_plan';
    const ACTION = 'seoprostack_speed_plan';

    /** Query arg carrying the result back. */
    const RESULT = 'seoprostack_plan';

    /** Log entries kept. */
    const LOG_MAX = 50;

    /** Comments or posts-table rows from which admin counts are worth keeping. */
    const COUNT_ROWS = 100000;

    /** Posts-table rows from which counting pages separately pays. */
    const LIST_ROWS = 50000;

    /** Products, or term relationships, from which counting in the background pays. */
    const PRODUCTS  = 1000;
    const TERM_ROWS = 100000;

    /** postmeta or usermeta rows from which a key on field values pays. */
    const META_ROWS = 500000;

    /** Off by default because they need the site owner's choices. */
    const CHOICES = array('delay_scripts', 'delayed_analytics');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Recommended speed settings', 'seoprostack'),
                'description' => __('Below these settings: which speed and server settings suit this site from its own size, why, and what each costs. Nothing changes until you click, one setting at a time, and each change can be undone.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!is_admin() || !self::enabled()) {
            return;
        }
        add_action('seoprostack_settings_tab_after', array(__CLASS__, 'render'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Drop the result from the address after showing it.
     *
     * @param array $args Query args.
     * @return array
     */
    public static function removable_query_args($args) {
        $args[] = self::RESULT;
        return $args;
    }

    /**
     * Sizes that decide the plan: rows in posts, comments, postmeta,
     * usermeta and term_relationships (estimates), and published products.
     *
     * @return array<string,int>
     */
    public static function sizes() {
        global $wpdb;
        $tables = array(
            'posts'    => $wpdb->posts,
            'comments' => $wpdb->comments,
            'postmeta' => $wpdb->postmeta,
            'usermeta' => $wpdb->usermeta,
            'terms'    => $wpdb->term_relationships,
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table statistics for one admin screen; nothing to cache.
        $rows  = $wpdb->get_results($wpdb->prepare(
            'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s, %s, %s)',
            $tables['posts'],
            $tables['comments'],
            $tables['postmeta'],
            $tables['usermeta'],
            $tables['terms']
        ), ARRAY_A);
        $found = array();
        foreach ((array) $rows as $row) {
            $found[(string) $row['TABLE_NAME']] = (int) $row['TABLE_ROWS'];
        }
        $sizes = array();
        foreach ($tables as $id => $table) {
            $sizes[$id] = isset($found[$table]) ? $found[$table] : 0;
        }
        $products          = post_type_exists('product') ? wp_count_posts('product') : null;
        $sizes['products'] = $products && isset($products->publish) ? (int) $products->publish : 0;
        return $sizes;
    }

    /**
     * The plan: setting key => state (on, recommended or skip), why (for
     * this site), cost (the trade-off). Only settings this version has.
     *
     * @return array<string,array{state:string,why:string,cost:string}>
     */
    public static function items() {
        $schema = SEOProStack_Settings::schema();
        $sizes  = self::sizes();
        $items  = array();

        // On by default, as they are safe on any site: key => whether it
        // applies here, and why not.
        $defaults = array(
            'plugin_loading'            => array(true, ''),
            'preload_pages'             => array(true, ''),
            'image_loading'             => array(true, ''),
            'wp_extras'                 => array(true, ''),
            'woo_light'                 => array(class_exists('WooCommerce', false), __('For shops: WooCommerce is not active.', 'seoprostack')),
            'kadence_library_on_demand' => array(defined('KADENCE_BLOCKS_VERSION'), __('For Kadence Blocks, which is not active.', 'seoprostack')),
            'heartbeat_limit'           => array(true, ''),
            'database_cleanup'          => array(true, ''),
            'autoload_options'          => array(!is_multisite(), __('For single sites.', 'seoprostack')),
        );
        foreach ($defaults as $key => $applies) {
            if (!$applies[0]) {
                $items[$key] = array('state' => 'skip', 'why' => $applies[1], 'cost' => '');
                continue;
            }
            $items[$key] = array(
                'state' => 'recommended',
                'why'   => __('On by default, as it is safe on any site, but off here. If it was turned off because something stopped working, leave it off or ask for help first.', 'seoprostack'),
                'cost'  => '',
            );
        }

        $comments = number_format_i18n($sizes['comments']);
        $posts    = number_format_i18n($sizes['posts']);
        if (wp_using_ext_object_cache()) {
            $items['admin_counts'] = array('state' => 'skip', 'why' => __('Not needed: the persistent object cache already keeps these counts.', 'seoprostack'), 'cost' => '');
        } elseif ($sizes['comments'] >= self::COUNT_ROWS || $sizes['posts'] >= self::COUNT_ROWS) {
            $items['admin_counts'] = array(
                /* translators: 1: number of comments; 2: number of rows in the posts table */
                'why'   => sprintf(__('About %1$s comments and %2$s posts, pages, revisions and other entries are counted on admin screens, with no persistent object cache to keep the counts.', 'seoprostack'), $comments, $posts),
                'cost'  => __('Counts changed straight in the database, not through WordPress, can be up to an hour old.', 'seoprostack'),
                'state' => 'recommended',
            );
        } else {
            $items['admin_counts'] = array(
                'state' => 'skip',
                /* translators: 1: number of comments; 2: number of rows in the posts table; 3: threshold */
                'why'   => sprintf(__('Not needed yet: about %1$s comments and %2$s entries in the posts table. Recommended from about %3$s.', 'seoprostack'), $comments, $posts, number_format_i18n(self::COUNT_ROWS)),
                'cost'  => '',
            );
        }

        if ($sizes['posts'] >= self::LIST_ROWS) {
            $items['found_rows'] = array(
                'state' => 'recommended',
                /* translators: %s: number of rows in the posts table */
                'why'   => sprintf(__('About %s entries in the posts table: archives, searches and admin lists count every match while fetching one page.', 'seoprostack'), $posts),
                'cost'  => __('Each page of results runs a second, lighter count query. Totals and page numbers stay exact.', 'seoprostack'),
            );
        } else {
            $items['found_rows'] = array(
                'state' => 'skip',
                /* translators: 1: number of rows in the posts table; 2: threshold */
                'why'   => sprintf(__('Not needed yet: about %1$s entries in the posts table. Recommended from about %2$s.', 'seoprostack'), $posts, number_format_i18n(self::LIST_ROWS)),
                'cost'  => '',
            );
        }

        $products = number_format_i18n($sizes['products']);
        $terms    = number_format_i18n($sizes['terms']);
        if ($sizes['products'] >= self::PRODUCTS || $sizes['terms'] >= self::TERM_ROWS) {
            $items['deferred_counts'] = array(
                'state' => 'recommended',
                /* translators: 1: number of products; 2: number of category and tag links */
                'why'   => sprintf(__('%1$s products and about %2$s category and tag links: every save, import and deletion recounts the categories and tags it touches.', 'seoprostack'), $products, $terms),
                'cost'  => __('Counts in widgets and term lists can be a few minutes behind.', 'seoprostack'),
            );
        } else {
            $items['deferred_counts'] = array(
                'state' => 'skip',
                /* translators: 1: number of products; 2: number of category and tag links; 3: products threshold; 4: links threshold */
                'why'   => sprintf(__('Not needed yet: %1$s products and about %2$s category and tag links. Recommended from %3$s products or about %4$s links, or before a large import.', 'seoprostack'), $products, $terms, number_format_i18n(self::PRODUCTS), number_format_i18n(self::TERM_ROWS)),
                'cost'  => '',
            );
        }

        $meta = $sizes['postmeta'];
        if (!is_multisite()) {
            $meta = max($meta, $sizes['usermeta']);
        }
        if ($meta >= self::META_ROWS) {
            $items['added_keys'] = array(
                'state' => 'recommended',
                /* translators: %s: number of rows */
                'why'   => sprintf(__('About %s custom field rows: finding posts or people by a field’s value reads many of them.', 'seoprostack'), number_format_i18n($meta)),
                'cost'  => __('Turning it on only offers the keys, under Tools → Add database keys; each is added when you click there. Back up the database first.', 'seoprostack'),
            );
        } else {
            $items['added_keys'] = array(
                'state' => 'skip',
                /* translators: 1: number of rows; 2: threshold */
                'why'   => sprintf(__('Not needed yet: about %1$s custom field rows. Recommended from about %2$s.', 'seoprostack'), number_format_i18n($meta), number_format_i18n(self::META_ROWS)),
                'cost'  => '',
            );
        }

        foreach ($items as $key => $item) {
            if (!isset($schema[$key]) || 'bool' !== $schema[$key]['type']) {
                unset($items[$key]);
            } elseif (SEOProStack_Settings::get($key)) {
                $items[$key]['state'] = 'on';
            }
        }
        return $items;
    }

    /**
     * Turn on one recommended setting.
     *
     * @param string $key Setting key.
     * @return true|WP_Error
     */
    public static function apply($key) {
        $items = self::items();
        if (!isset($items[$key]) || 'recommended' !== $items[$key]['state']) {
            return new WP_Error('seoprostack_plan_state', __('That setting is not recommended now, so nothing changed. Look at the plan again.', 'seoprostack'));
        }
        $from  = (bool) SEOProStack_Settings::get($key);
        $saved = self::save($key, true);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $log   = self::log();
        $log[] = array('time' => time(), 'user' => get_current_user_id(), 'key' => $key, 'from' => $from, 'to' => true, 'undone' => 0);
        update_option(self::OPTION, array_slice($log, -self::LOG_MAX), false);
        return true;
    }

    /**
     * Undo the plan's last change to a setting, if it is still as the plan
     * left it.
     *
     * @param string $key Setting key.
     * @return true|WP_Error
     */
    public static function undo($key) {
        $log   = self::log();
        $index = self::undoable($key, $log);
        if (null === $index) {
            return new WP_Error('seoprostack_plan_changed', __('That setting was changed since, or already undone, so nothing changed.', 'seoprostack'));
        }
        $saved = self::save($key, $log[$index]['from']);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $log[$index]['undone'] = time();
        update_option(self::OPTION, $log, false);
        return true;
    }

    /**
     * Index of the log entry Undo would reverse for a setting, if any: its
     * latest, not yet undone, with the setting still at the plan's value.
     *
     * @param string $key Setting key.
     * @param array  $log Log.
     * @return int|null
     */
    private static function undoable($key, array $log) {
        for ($i = count($log) - 1; $i >= 0; $i--) {
            if ($key !== $log[$i]['key']) {
                continue;
            }
            if (!empty($log[$i]['undone']) || (bool) SEOProStack_Settings::get($key) !== (bool) $log[$i]['to']) {
                return null;
            }
            return $i;
        }
        return null;
    }

    /**
     * Save a setting as the settings screen does.
     *
     * @param string $key   Setting key.
     * @param bool   $value Value.
     * @return true|WP_Error
     */
    private static function save($key, $value) {
        $saved = SEOProStack_Settings::set($key, $value);
        if (is_wp_error($saved)) {
            return $saved;
        }
        /** This action is documented in includes/class-seoprostack-settings.php */
        do_action('seoprostack_setting_saved', $key, $saved);
        return true;
    }

    /**
     * The change log, well-formed entries only.
     *
     * @return array<int,array{time:int,user:int,key:string,from:bool,to:bool,undone:int}>
     */
    private static function log() {
        $stored = get_option(self::OPTION, array());
        $log    = array();
        foreach (is_array($stored) ? $stored : array() as $entry) {
            if (!is_array($entry) || !isset($entry['time'], $entry['key'], $entry['from'], $entry['to']) || !is_string($entry['key'])) {
                continue;
            }
            $log[] = array(
                'time'   => (int) $entry['time'],
                'user'   => isset($entry['user']) ? (int) $entry['user'] : 0,
                'key'    => $entry['key'],
                'from'   => (bool) $entry['from'],
                'to'     => (bool) $entry['to'],
                'undone' => isset($entry['undone']) ? (int) $entry['undone'] : 0,
            );
        }
        return $log;
    }

    /**
     * Apply or Undo.
     */
    public static function handle() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below with the action and setting in the nonce.
        $do  = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if (!in_array($do, array('apply', 'undo'), true)) {
            wp_die(esc_html__('Unknown action.', 'seoprostack'), '', array('response' => 400));
        }
        check_admin_referer(self::ACTION . '_' . $do . '_' . $key);
        if (!SEOProStack_Settings::can_change()) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'seoprostack'), '', array('response' => 403));
        }
        $result = 'apply' === $do ? self::apply($key) : self::undo($key);
        $value  = is_wp_error($result) ? 'error:' . $result->get_error_code() : $do . ':' . $key;
        wp_safe_redirect(SEOProStack_Admin_Manager::tab_url('speed', array(self::RESULT => $value)) . '#sps-speed-plan');
        exit;
    }

    /**
     * The plan, after the Speed tab's settings.
     *
     * @param string $tab Tab slug.
     */
    public static function render($tab) {
        if ('speed' !== $tab || !SEOProStack_Settings::can_change()) {
            return;
        }
        $schema = SEOProStack_Settings::schema();
        $items  = self::items();
        $log    = self::log();
        $states = array(
            'recommended' => __('Recommended', 'seoprostack'),
            'on'          => __('On', 'seoprostack'),
            'skip'        => __('Not recommended here', 'seoprostack'),
        );
        $order  = array('recommended' => 0, 'on' => 1, 'skip' => 2);
        uasort($items, function ($a, $b) use ($order) {
            return $order[$a['state']] - $order[$b['state']];
        });
        echo '<div class="sps-examples sps-plan" id="sps-speed-plan">';
        echo '<h2 class="sps-section__title">' . esc_html__('Recommended speed settings', 'seoprostack') . '</h2>';
        self::notice($schema);
        echo '<p class="sps-section__desc">' . esc_html__('From this site’s own size and plugins. Each button turns on one setting, as its switch would; try the site after each change. Undo puts it back while it is still as the plan left it.', 'seoprostack') . '</p>';
        echo '<ul class="sps-plan__items">';
        foreach ($items as $key => $item) {
            $field = $schema[$key];
            $where = isset($field['tab']) ? (string) $field['tab'] : 'speed';
            echo '<li class="sps-plan__item sps-plan__item--' . esc_attr($item['state']) . '">';
            echo '<p><a href="' . esc_url(SEOProStack_Admin_Manager::tab_url($where) . '#sps-' . $key) . '"><strong>' . esc_html($field['label']) . '</strong></a> — <span class="sps-plan__state">' . esc_html($states[$item['state']]) . '</span></p>';
            if ('' !== $item['why'] && 'on' !== $item['state']) {
                echo '<p>' . esc_html($item['why']) . '</p>';
            }
            if ('' !== $item['cost'] && 'recommended' === $item['state']) {
                /* translators: %s: the trade-off */
                echo '<p>' . esc_html(sprintf(__('Trade-off: %s', 'seoprostack'), $item['cost'])) . '</p>';
            }
            if ('recommended' === $item['state']) {
                self::button('apply', $key, __('Turn on', 'seoprostack'), 'button button-primary button-small');
            } elseif (null !== self::undoable($key, $log)) {
                self::button('undo', $key, __('Undo', 'seoprostack'), 'button button-small');
            }
            echo '</li>';
        }
        echo '</ul>';
        $choices = array();
        foreach (self::CHOICES as $key) {
            if (isset($schema[$key]) && !SEOProStack_Settings::get($key)) {
                $choices[] = $schema[$key]['label'];
            }
        }
        if ($choices) {
            /* translators: %s: setting names */
            echo '<p>' . esc_html(sprintf(__('Not in the plan, as they need your choices: %s.', 'seoprostack'), implode(', ', $choices))) . '</p>';
        }
        $done = array_reverse(array_slice($log, -10));
        if ($done) {
            echo '<details class="sps-examples__list"><summary>' . esc_html__('Changes made from this plan', 'seoprostack') . '</summary><ul>';
            foreach ($done as $entry) {
                $label = isset($schema[$entry['key']]) ? $schema[$entry['key']]['label'] : $entry['key'];
                $user  = get_userdata((int) $entry['user']);
                $text  = wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['time']) . ': ' . $label . ' ' . __('turned on', 'seoprostack');
                if ($user) {
                    $text .= ' ' . sprintf(/* translators: %s: person's name */ __('by %s', 'seoprostack'), $user->display_name);
                }
                if (!empty($entry['undone'])) {
                    $text .= ' (' . sprintf(/* translators: %s: date */ __('undone %s', 'seoprostack'), wp_date(get_option('date_format'), (int) $entry['undone'])) . ')';
                }
                echo '<li>' . esc_html($text) . '</li>';
            }
            echo '</ul></details>';
        }
        echo '</div>';
    }

    /**
     * A one-click form.
     *
     * @param string $do    apply or undo.
     * @param string $key   Setting key.
     * @param string $label Button text.
     * @param string $class Button classes.
     */
    private static function button($do, $key, $label, $class) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="key" value="' . esc_attr($key) . '">';
        wp_nonce_field(self::ACTION . '_' . $do . '_' . $key);
        echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    /**
     * Say what happened.
     *
     * @param array $schema Settings schema.
     */
    private static function notice(array $schema) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $raw = isset($_GET[self::RESULT]) ? sanitize_text_field(wp_unslash($_GET[self::RESULT])) : '';
        if ('' === $raw) {
            return;
        }
        $parts = explode(':', $raw, 2);
        if ('error' === $parts[0]) {
            $messages = array(
                'seoprostack_plan_state'   => __('That setting is not recommended now, so nothing changed.', 'seoprostack'),
                'seoprostack_plan_changed' => __('That setting was changed since, or already undone, so nothing changed.', 'seoprostack'),
            );
            $code = isset($parts[1]) ? $parts[1] : '';
            printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html(isset($messages[$code]) ? $messages[$code] : __('The setting could not be saved. Please try again.', 'seoprostack')));
            return;
        }
        $key   = isset($parts[1]) ? $parts[1] : '';
        $label = isset($schema[$key]) ? $schema[$key]['label'] : '';
        if ('' === $label) {
            return;
        }
        /* translators: %s: setting name */
        $text = 'apply' === $parts[0] ? sprintf(__('%s is on. Try a few pages of the site now.', 'seoprostack'), $label) : sprintf(/* translators: %s: setting name */ __('%s is back as it was.', 'seoprostack'), $label);
        printf('<div class="notice notice-success inline"><p>%s</p></div>', esc_html($text));
    }
}
