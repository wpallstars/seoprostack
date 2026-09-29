<?php
/**
 * WP Allstars settings tabs.
 *
 * Renders setting cards from WP_Allstars_Settings::schema(). Each top-level
 * setting is a card with a switch; child settings appear in an expandable
 * panel. Controls save instantly via AJAX (see admin/js/wp-allstars-admin.js).
 *
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Settings_Manager {

    /**
     * General tab.
     */
    public static function render_general_tab() {
        self::render_tab('general', __('General', 'wp-allstars'), __('Site-wide admin preferences.', 'wp-allstars'));
    }

    /**
     * Workflow tab.
     */
    public static function render_workflow_tab() {
        self::render_tab('workflow', __('Workflow', 'wp-allstars'), __('Automations that run while you edit content.', 'wp-allstars'));
    }

    /**
     * Advanced tab.
     */
    public static function render_advanced_tab() {
        self::render_tab('advanced', __('Advanced', 'wp-allstars'), '');
    }

    /**
     * Render every top-level setting for a tab.
     *
     * @param string $tab         Tab slug.
     * @param string $title       Section heading.
     * @param string $description Section intro.
     */
    public static function render_tab($tab, $title, $description) {
        $fields = WP_Allstars_Settings::fields_for_tab($tab);
        ?>
        <div class="wpa-section">
            <div class="wpa-section__intro">
                <h2 class="wpa-section__title"><?php echo esc_html($title); ?></h2>
                <?php if ($description) : ?>
                    <p class="wpa-section__desc"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
                <p class="wpa-section__hint"><?php esc_html_e('Changes are saved automatically.', 'wp-allstars'); ?></p>
            </div>
            <div class="wpa-cards">
                <?php
                foreach ($fields as $key => $field) {
                    self::render_card($key, $field);
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a setting card.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function render_card($key, array $field) {
        $children = WP_Allstars_Settings::children_of($key);
        $value    = WP_Allstars_Settings::get($key);
        $id       = 'wpa-' . $key;
        $panel_id = $id . '-panel';
        $is_bool  = 'bool' === $field['type'];
        ?>
        <section class="wpa-card wpa-setting<?php echo ($is_bool && $value) ? ' is-on' : ''; ?><?php echo $children ? ' has-panel' : ''; ?>" data-setting-card="<?php echo esc_attr($key); ?>">
            <?php // Clicking the header (outside the switch) opens the options; only the switch changes the value. ?>
            <div class="wpa-setting__header"<?php echo $children ? ' data-wpa-panel-toggle' : ''; ?>>
                <?php if ($is_bool) : ?>
                    <span class="wpa-switch">
                        <input type="checkbox"
                               role="switch"
                               class="wpa-switch__input"
                               id="<?php echo esc_attr($id); ?>"
                               data-wpa-setting="<?php echo esc_attr($key); ?>"
                               aria-labelledby="<?php echo esc_attr($id); ?>-title"
                               aria-describedby="<?php echo esc_attr($id); ?>-desc"
                               <?php checked((bool) $value); ?> />
                        <span class="wpa-switch__track" aria-hidden="true"></span>
                    </span>
                <?php endif; ?>

                <div class="wpa-setting__text">
                    <div class="wpa-setting__title-row">
                        <span class="wpa-setting__title" id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($field['label']); ?></span>
                        <span class="wpa-status" data-wpa-status="<?php echo esc_attr($key); ?>" aria-hidden="true"></span>
                    </div>
                    <?php if (!empty($field['description'])) : ?>
                        <p class="wpa-setting__desc" id="<?php echo esc_attr($id); ?>-desc"><?php echo esc_html($field['description']); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($children) : ?>
                    <button type="button"
                            class="wpa-setting__expand button-link"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr($panel_id); ?>">
                        <span class="wpa-setting__expand-label"><?php esc_html_e('Options', 'wp-allstars'); ?></span>
                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php echo esc_html(sprintf(/* translators: %s: setting name */ __('for %s', 'wp-allstars'), $field['label'])); ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($children) : ?>
                <div class="wpa-setting__panel" id="<?php echo esc_attr($panel_id); ?>" hidden>
                    <?php
                    foreach ($children as $child_key => $child) {
                        self::render_field($child_key, $child);
                    }
                    ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Render a child field row.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function render_field($key, array $field) {
        $value   = WP_Allstars_Settings::get($key);
        $id      = 'wpa-' . $key;
        $desc_id = $id . '-desc';
        $attrs   = sprintf('id="%1$s" data-wpa-setting="%2$s" aria-describedby="%3$s"', esc_attr($id), esc_attr($key), esc_attr($desc_id));
        ?>
        <div class="wpa-field">
            <label class="wpa-field__label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['label']); ?></label>
            <div class="wpa-field__control">
                <?php
                switch ($field['type']) {
                    case 'int':
                        printf(
                            '<input type="number" class="small-text" %1$s value="%2$s" min="%3$s" max="%4$s" step="1" inputmode="numeric" />',
                            $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                            esc_attr((string) $value),
                            esc_attr(isset($field['min']) ? (string) $field['min'] : ''),
                            esc_attr(isset($field['max']) ? (string) $field['max'] : '')
                        );
                        if (!empty($field['unit'])) {
                            echo ' <span class="wpa-field__unit">' . esc_html($field['unit']) . '</span>';
                        }
                        break;

                    case 'domains':
                        printf(
                            '<textarea class="large-text code" rows="3" %1$s placeholder="%2$s">%3$s</textarea>',
                            $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                            esc_attr(isset($field['placeholder']) ? $field['placeholder'] : ''),
                            esc_textarea((string) $value)
                        );
                        break;

                    case 'bool':
                        printf(
                            '<span class="wpa-switch"><input type="checkbox" role="switch" class="wpa-switch__input" %1$s %2$s /><span class="wpa-switch__track" aria-hidden="true"></span></span>',
                            $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                            checked((bool) $value, true, false)
                        );
                        break;

                    case 'text':
                    default:
                        printf(
                            '<input type="text" class="regular-text" %1$s value="%2$s" placeholder="%3$s" />',
                            $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                            esc_attr((string) $value),
                            esc_attr(isset($field['placeholder']) ? $field['placeholder'] : '')
                        );
                        break;
                }
                ?>
                <span class="wpa-status" data-wpa-status="<?php echo esc_attr($key); ?>" aria-hidden="true"></span>
                <?php if (!empty($field['description']) || !empty($field['tokens'])) : ?>
                    <p class="description" id="<?php echo esc_attr($desc_id); ?>">
                        <?php echo esc_html(isset($field['description']) ? $field['description'] : ''); ?>
                        <?php if (!empty($field['tokens'])) : ?>
                            <span class="wpa-tokens">
                                <?php esc_html_e('Tokens:', 'wp-allstars'); ?>
                                <?php foreach ($field['tokens'] as $token) : ?>
                                    <button type="button" class="wpa-token" data-token="<?php echo esc_attr($token); ?>" data-target="<?php echo esc_attr($id); ?>" title="<?php esc_attr_e('Insert token', 'wp-allstars'); ?>"><code><?php echo esc_html($token); ?></code></button>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
