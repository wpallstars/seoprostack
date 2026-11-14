<?php
/**
 * Faster database keys: the settings panel, Check now and the Plugins
 * screen's note on Index WP MySQL For Speed.
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

final class SEOProStack_Fast_Keys_Admin {

    /** Check now (admin-post action and nonce). */
    const ACTION = 'seoprostack_fast_keys_check';

    /**
     * Register hooks (wp-admin).
     */
    public static function boot() {
        add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'extras'), 10, 2);
        if (!wp_doing_ajax()) {
            add_action('admin_init', array('SEOProStack_Fast_Keys', 'sync_schedule'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'));
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'check_now'));
        }
    }

    /**
     * What Index WP MySQL For Speed has that this does not: its query
     * monitor, named when monitor logs are saved (deleting it removes them).
     *
     * @param string[] $extras Plain names.
     * @param string   $slug   Plugin folder.
     * @return string[]
     */
    public static function extras($extras, $slug) {
        global $wpdb;
        if (!isset(SEOProStack_Fast_Keys::REPLACES[$slug])) {
            return $extras;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed lookup on the Plugins screen.
        $saved = $wpdb->get_var($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like('imfsQueryMonitor-Log-') . '%'));
        if ($saved) {
            $extras[] = __('its query monitor, with logs saved here that deleting it removes', 'seoprostack');
        }
        return $extras;
    }

    /**
     * Check now: run in the background straight away.
     */
    public static function check_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        wp_clear_scheduled_hook(SEOProStack_Fast_Keys::SOON);
        wp_schedule_single_event(time(), SEOProStack_Fast_Keys::SOON);
        spawn_cron();
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('options-general.php?page=seoprostack&tab=server'));
        exit;
    }

    /**
     * Each table's keys, in the settings panel. From the last background
     * check only: no table metadata is read here.
     *
     * @param string $key Setting key.
     */
    public static function panel($key) {
        if (SEOProStack_Fast_Keys::KEY !== $key || !SEOProStack_Settings::can_change()) {
            return;
        }
        $state = SEOProStack_Fast_Keys::state();
        $lines = self::table_lines($state);
        if (!$lines && !$state['log']) {
            return;
        }
        echo '<div class="sps-panel-note"><ul class="ul-disc">';
        foreach ($lines as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul>';
        if ($state['log']) {
            $last = end($state['log']);
            /* translators: 1: date and time, 2: table, 3: result */
            echo '<p>' . esc_html(sprintf(__('Last change %1$s: %2$s, %3$s.', 'seoprostack'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['time']), $last['table'], $last['result'])) . '</p>';
        }
        echo '<p><a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION)) . '">' . esc_html__('Check now', 'seoprostack') . '</a> ';
        echo esc_html__('WP-CLI: wp seoprostack fast-keys status, apply (also large tables) or undo (when switched off).', 'seoprostack') . '</p></div>';
    }

    /**
     * One line per table worth naming.
     *
     * @param array $state Stored state.
     * @return string[]
     */
    private static function table_lines(array $state) {
        $on      = SEOProStack_Fast_Keys::switched_on();
        $waiting = array() !== SEOProStack_Fast_Keys::replaced_active(SEOProStack_Fast_Keys::KEY);
        $lines   = array();
        foreach (array_keys(SEOProStack_Fast_Keys_Schema::targets()) as $suffix) {
            $last = isset($state['tables'][$suffix]) ? $state['tables'][$suffix] : array();
            $text = self::table_state($last, isset($state['owned'][$suffix]), $on, $waiting);
            if ('' === $text) {
                continue;
            }
            $table = SEOProStack_Fast_Keys_Schema::table($suffix);
            if (isset($last['rows'])) {
                /* translators: 1: table, 2: number of rows, 3: state */
                $lines[] = sprintf(__('%1$s (%2$s rows): %3$s', 'seoprostack'), $table, number_format_i18n((int) $last['rows']), $text);
            } else {
                /* translators: 1: table, 2: state */
                $lines[] = sprintf(__('%1$s: %2$s', 'seoprostack'), $table, $text);
            }
        }
        return $lines;
    }

    /**
     * A table's state, or '' when there is nothing to say.
     *
     * @param array $last    Last stored result.
     * @param bool  $kept    SEO Pro Stack keeps its keys.
     * @param bool  $on      The setting is on.
     * @param bool  $waiting Index WP MySQL For Speed is active.
     * @return string
     */
    private static function table_state(array $last, $kept, $on, $waiting) {
        $status = isset($last['status']) ? (string) $last['status'] : '';
        $known  = in_array($status, array('skipped', 'failed'), true) && '' !== (string) $last['note'];
        if ('missing' === $status) {
            return '';
        }
        if ($kept) {
            $text = self::kept_state($on, 'done' === $status, $known);
            if ('' !== $text) {
                return $text;
            }
        }
        if ($known) {
            /* translators: %s: reason */
            return sprintf(__('Not changed: %s', 'seoprostack'), $last['note']);
        }
        return $on && !$waiting ? __('Waiting for the background check.', 'seoprostack') : '';
    }

    /**
     * A kept table's state, or '' when its last check gave a reason.
     *
     * @param bool $on    The setting is on.
     * @param bool $done  The last check found it done.
     * @param bool $known The last check gave a reason.
     * @return string
     */
    private static function kept_state($on, $done, $known) {
        if (!$on) {
            return __('Faster keys in place; WordPress’s keys go back at the next background check.', 'seoprostack');
        }
        if ($done) {
            return __('Faster keys in place.', 'seoprostack');
        }
        return $known ? '' : __('Faster keys in place; an update changed some, and the next background check puts them back.', 'seoprostack');
    }
}
