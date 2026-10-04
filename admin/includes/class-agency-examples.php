<?php
/**
 * Agency tab: example data.
 *
 * Shows the agency starter set (starters/agency.json, see
 * SEOProStack_Starters) under the Agency tab's settings: what it would add
 * for the plugins that are active, what waits for Fluent Forms' payments,
 * and buttons to add it or remove what was added while unused. Each asks
 * first. WP-CLI: `wp seoprostack starters add agency`.
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

class SEOProStack_Agency_Examples {

    /** Starter set slug. */
    const SET = 'agency';

    /** admin-post action. */
    const ACTION = 'seoprostack_agency_examples';

    /** Query arg carrying the result back. */
    const RESULT = 'seoprostack_examples';

    /** Plugins the set uses: main file => name. */
    const PLUGINS = array(
        'fluentform/fluentform.php'             => 'Fluent Forms',
        'fluent-crm/fluent-crm.php'             => 'FluentCRM',
        'fluent-boards/fluent-boards.php'       => 'Fluent Boards',
        'fluent-support/fluent-support.php'     => 'Fluent Support',
        'fluent-booking/fluent-booking.php'     => 'FluentBooking',
        'fluent-community/fluent-community.php' => 'FluentCommunity',
        'tutor/tutor.php'                       => 'Tutor LMS',
    );

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('seoprostack_settings_tab_after', array(__CLASS__, 'render'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Whether the current person may add or remove example data.
     *
     * @return bool
     */
    private static function allowed() {
        return current_user_can('manage_options') && current_user_can('activate_plugins') && !is_network_admin();
    }

    /**
     * Load the starters class.
     *
     * @return array|null The set.
     */
    private static function set() {
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-starters.php';
        return SEOProStack_Starters::get(self::SET);
    }

    /**
     * The section, after the Agency tab's settings.
     *
     * @param string $tab Tab slug.
     */
    public static function render($tab) {
        if ('agency' !== $tab || !self::allowed()) {
            return;
        }
        $set = self::set();
        if (!$set) {
            return;
        }
        $active  = array();
        $missing = array();
        foreach (self::PLUGINS as $file => $name) {
            if (SEOProStack_Plugin_Loader::is_active($file)) {
                $active[] = $name;
            } else {
                $missing[] = $name;
            }
        }
        $ready   = SEOProStack_Starters::ready(self::SET);
        $to_add  = $ready ? SEOProStack_Starters::missing(self::SET) : array();
        $count   = 0;
        foreach ($to_add as $names) {
            $count += count($names);
        }
        $waiting = $ready ? SEOProStack_Starters::waiting(self::SET) : array();
        $added   = SEOProStack_Starters::added_count(self::SET);
        $action  = admin_url('admin-post.php');
        ?>
        <div class="sps-examples" id="sps-examples">
            <h2 class="sps-section__title"><?php esc_html_e('Example data', 'seoprostack'); ?></h2>
            <?php self::notice(); ?>
            <?php if ('' !== $set['notes']) : ?>
                <p class="sps-section__desc"><?php echo esc_html($set['notes']); ?></p>
            <?php endif; ?>
            <p class="sps-section__desc">
                <?php
                if ($missing) {
                    /* translators: 1: plugin names that are active, 2: plugin names that are not */
                    echo esc_html(sprintf(__('Active: %1$s. Not active: %2$s. Examples for plugins that are not active are added when you add again after activating them.', 'seoprostack'), $active ? implode(', ', $active) : __('none', 'seoprostack'), implode(', ', $missing)));
                } else {
                    esc_html_e('Every plugin it uses is active.', 'seoprostack');
                }
                ?>
            </p>

            <?php if (!$ready) : ?>
                <p><?php esc_html_e('Activate Fluent Forms, Fluent Boards, Fluent Support, FluentBooking, FluentCommunity or Tutor LMS first.', 'seoprostack'); ?></p>
            <?php elseif ($to_add) : ?>
                <details class="sps-examples__list">
                    <summary>
                        <?php
                        /* translators: %d: number of items */
                        echo esc_html(sprintf(_n('%d example to add', '%d examples to add', $count, 'seoprostack'), $count));
                        ?>
                    </summary>
                    <ul>
                        <?php foreach ($to_add as $names) : ?>
                            <?php foreach ($names as $name) : ?>
                                <li><?php echo esc_html($name); ?></li>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php else : ?>
                <p><?php esc_html_e('Every example for the active plugins is there.', 'seoprostack'); ?></p>
            <?php endif; ?>

            <?php if ($waiting) : ?>
                <p class="sps-examples__waiting">
                    <?php
                    /* translators: %s: list of example names */
                    echo esc_html(sprintf(__('Turn on payments in Fluent Forms (Global Settings, Payments) and set up a payment method such as Stripe to add the forms that take payment: %s.', 'seoprostack'), implode(', ', $waiting)));
                    ?>
                </p>
            <?php endif; ?>

            <div class="sps-examples__actions">
                <?php if ($ready && $count) : ?>
                    <form method="post" action="<?php echo esc_url($action); ?>" data-sps-confirm="<?php echo esc_attr(sprintf(/* translators: %d: number of items */ _n('Add %d example? Nothing already there is changed. You can remove what was added while it is unused.', 'Add %d examples? Nothing already there is changed. You can remove what was added while it is unused.', $count, 'seoprostack'), $count)); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                        <input type="hidden" name="do" value="add" />
                        <?php wp_nonce_field(self::ACTION . '_add'); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Add example data', 'seoprostack'); ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($added) : ?>
                    <form method="post" action="<?php echo esc_url($action); ?>" data-sps-confirm="<?php esc_attr_e('Remove the example data SEO Pro Stack added? Anything in use stays: forms with entries, boards with tasks, products with tickets, booking events with bookings, spaces with members or posts, courses with students, and pages or settings you changed.', 'seoprostack'); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                        <input type="hidden" name="do" value="remove" />
                        <?php wp_nonce_field(self::ACTION . '_remove'); ?>
                        <button type="submit" class="button"><?php esc_html_e('Remove example data', 'seoprostack'); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Add or remove the set.
     */
    public static function handle() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below with the action in the nonce.
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        if (!in_array($do, array('add', 'remove'), true)) {
            wp_die(esc_html__('Unknown action.', 'seoprostack'), '', array('response' => 400));
        }
        check_admin_referer(self::ACTION . '_' . $do);
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to change plugin data.', 'seoprostack'), '', array('response' => 403));
        }
        self::set();
        $result = 'add' === $do ? SEOProStack_Starters::add(self::SET) : SEOProStack_Starters::remove(self::SET);
        if (is_wp_error($result)) {
            $value = $do . ':error:' . $result->get_error_code();
        } elseif (is_array($result)) {
            $value = $do . ':' . (int) $result['removed'] . ':' . count($result['kept']);
        } else {
            $value = $do . ':' . (int) $result . ':0';
        }
        wp_safe_redirect(SEOProStack_Admin_Manager::tab_url('agency', array(self::RESULT => rawurlencode($value))) . '#sps-examples');
        exit;
    }

    /**
     * Say what happened.
     */
    private static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $raw = isset($_GET[self::RESULT]) ? sanitize_text_field(wp_unslash($_GET[self::RESULT])) : '';
        if ('' === $raw) {
            return;
        }
        $parts = explode(':', $raw, 3);
        if (isset($parts[1]) && 'error' === $parts[1]) {
            $messages = array(
                'seoprostack_starter_inactive' => __('Activate the plugins first.', 'seoprostack'),
                'seoprostack_nothing_added'    => __('SEO Pro Stack has not added any example data.', 'seoprostack'),
                'seoprostack_starter_failed'   => __('A plugin could not save the example data. Check that it is up to date.', 'seoprostack'),
            );
            $code = isset($parts[2]) ? $parts[2] : '';
            printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html(isset($messages[$code]) ? $messages[$code] : __('The example data could not be changed.', 'seoprostack')));
            return;
        }
        $done = isset($parts[1]) ? (int) $parts[1] : 0;
        $kept = isset($parts[2]) ? (int) $parts[2] : 0;
        if ('add' === $parts[0]) {
            /* translators: %d: number of items */
            $text = $done ? sprintf(_n('Added %d example.', 'Added %d examples.', $done, 'seoprostack'), $done) : __('Every example was already there. Nothing changed.', 'seoprostack');
        } else {
            /* translators: %d: number of items */
            $text = sprintf(_n('Removed %d example.', 'Removed %d examples.', $done, 'seoprostack'), $done);
            if ($kept) {
                /* translators: %d: number of items */
                $text .= ' ' . sprintf(_n('%d is in use or was changed, so it stays.', '%d are in use or were changed, so they stay.', $kept, 'seoprostack'), $kept);
            }
        }
        printf('<div class="notice notice-success inline"><p>%s</p></div>', esc_html($text));
    }

    /**
     * Let core drop the result from the address bar.
     *
     * @param string[] $args Query args.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::RESULT;
        return $args;
    }
}
