<?php
/**
 * SEO Pro Stack settings tabs.
 *
 * Renders setting cards from SEOProStack_Settings::schema(). Each top-level
 * setting is a card with a switch; child settings appear in an expandable
 * panel. Controls save instantly via AJAX (see admin/js/seoprostack-admin.js).
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Settings_Manager {

    /**
     * General tab.
     */
    public static function render_general_tab() {
        self::render_tab('general', __('General', 'seoprostack'), __('Site-wide admin preferences.', 'seoprostack'));
    }

    /**
     * Workflow tab.
     */
    public static function render_workflow_tab() {
        self::render_tab('workflow', __('Workflow', 'seoprostack'), __('Automations that run while you edit content.', 'seoprostack'));
    }

    /**
     * Advanced tab.
     */
    public static function render_advanced_tab() {
        self::render_tab('advanced', __('Advanced', 'seoprostack'), '');
    }

    /**
     * Render every top-level setting for a tab.
     *
     * @param string $tab         Tab slug.
     * @param string $title       Section heading.
     * @param string $description Section intro.
     */
    public static function render_tab($tab, $title, $description) {
        $fields = SEOProStack_Settings::fields_for_tab($tab);
        ?>
        <div class="sps-section">
            <div class="sps-section__intro">
                <h2 class="sps-section__title"><?php echo esc_html($title); ?></h2>
                <?php if ($description) : ?>
                    <p class="sps-section__desc"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
                <p class="sps-section__hint"><?php esc_html_e('Changes are saved automatically.', 'seoprostack'); ?></p>
            </div>
            <div class="sps-cards">
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
        $children = SEOProStack_Settings::children_of($key);
        $value    = SEOProStack_Settings::get($key);
        $id       = 'sps-' . $key;
        $panel_id = $id . '-panel';
        $is_bool  = 'bool' === $field['type'];
        ?>
        <section class="sps-card sps-setting<?php echo ($is_bool && $value) ? ' is-on' : ''; ?><?php echo $children ? ' has-panel' : ''; ?>" data-setting-card="<?php echo esc_attr($key); ?>">
            <?php // Clicking the header (outside the switch) opens the options; only the switch changes the value. ?>
            <div class="sps-setting__header"<?php echo $children ? ' data-sps-panel-toggle' : ''; ?>>
                <?php if ($is_bool) : ?>
                    <span class="sps-switch">
                        <input type="checkbox"
                               role="switch"
                               class="sps-switch__input"
                               id="<?php echo esc_attr($id); ?>"
                               data-sps-setting="<?php echo esc_attr($key); ?>"
                               aria-labelledby="<?php echo esc_attr($id); ?>-title"
                               aria-describedby="<?php echo esc_attr($id); ?>-desc"
                               <?php checked((bool) $value); ?> />
                        <span class="sps-switch__track" aria-hidden="true"></span>
                    </span>
                <?php endif; ?>

                <div class="sps-setting__text">
                    <div class="sps-setting__title-row">
                        <span class="sps-setting__title" id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($field['label']); ?></span>
                        <span class="sps-status" data-sps-status="<?php echo esc_attr($key); ?>" aria-hidden="true"></span>
                    </div>
                    <?php if (!empty($field['description'])) : ?>
                        <p class="sps-setting__desc" id="<?php echo esc_attr($id); ?>-desc"><?php echo esc_html($field['description']); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($children) : ?>
                    <button type="button"
                            class="sps-setting__expand button-link"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr($panel_id); ?>">
                        <span class="sps-setting__expand-label"><?php esc_html_e('Options', 'seoprostack'); ?></span>
                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php echo esc_html(sprintf(/* translators: %s: setting name */ __('for %s', 'seoprostack'), $field['label'])); ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($children) : ?>
                <div class="sps-setting__panel" id="<?php echo esc_attr($panel_id); ?>" hidden>
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
        $value   = SEOProStack_Settings::get($key);
        $id      = 'sps-' . $key;
        $desc_id = $id . '-desc';
        $attrs   = sprintf('id="%1$s" data-sps-setting="%2$s" aria-describedby="%3$s"', esc_attr($id), esc_attr($key), esc_attr($desc_id));
        $is_multi = 'multi' === $field['type'];
        ?>
        <div class="sps-field">
            <?php if ($is_multi) : ?>
                <span class="sps-field__label" id="<?php echo esc_attr($id); ?>-label"><?php echo esc_html($field['label']); ?></span>
            <?php else : ?>
                <label class="sps-field__label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['label']); ?></label>
            <?php endif; ?>
            <div class="sps-field__control">
                <?php
                switch ($field['type']) {
                    case 'select':
                        printf('<select %s>', $attrs); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                        foreach (SEOProStack_Settings::options_for($field) as $option_value => $option_label) {
                            printf(
                                '<option value="%1$s"%2$s>%3$s</option>',
                                esc_attr((string) $option_value),
                                selected((string) $value, (string) $option_value, false),
                                esc_html($option_label)
                            );
                        }
                        echo '</select>';
                        break;

                    case 'multi':
                        // The group carries data-sps-setting; the JS saves every checked value.
                        printf(
                            '<fieldset class="sps-checkboxes" data-sps-multi %1$s aria-labelledby="%2$s">',
                            $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                            esc_attr($id . '-label')
                        );
                        foreach (SEOProStack_Settings::options_for($field) as $option_value => $option_label) {
                            printf(
                                '<label class="sps-checkbox"><input type="checkbox" value="%1$s"%2$s /> %3$s</label>',
                                esc_attr((string) $option_value),
                                checked(in_array((string) $option_value, array_map('strval', (array) $value), true), true, false),
                                esc_html($option_label)
                            );
                        }
                        echo '</fieldset>';
                        break;

                    case 'times':
                        printf(
                            '<input type="text" class="regular-text" %1$s value="%2$s" placeholder="%3$s" autocomplete="off" />',
                            $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                            esc_attr((string) $value),
                            esc_attr(isset($field['placeholder']) ? $field['placeholder'] : '')
                        );
                        break;

                    case 'int':
                        printf(
                            '<input type="number" class="small-text" %1$s value="%2$s" min="%3$s" max="%4$s" step="1" inputmode="numeric" />',
                            $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                            esc_attr((string) $value),
                            esc_attr(isset($field['min']) ? (string) $field['min'] : ''),
                            esc_attr(isset($field['max']) ? (string) $field['max'] : '')
                        );
                        if (!empty($field['unit'])) {
                            echo ' <span class="sps-field__unit">' . esc_html($field['unit']) . '</span>';
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
                            '<span class="sps-switch"><input type="checkbox" role="switch" class="sps-switch__input" %1$s %2$s /><span class="sps-switch__track" aria-hidden="true"></span></span>',
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
                <span class="sps-status" data-sps-status="<?php echo esc_attr($key); ?>" aria-hidden="true"></span>
                <?php if (!empty($field['description']) || !empty($field['tokens'])) : ?>
                    <p class="description" id="<?php echo esc_attr($desc_id); ?>">
                        <?php echo esc_html(isset($field['description']) ? $field['description'] : ''); ?>
                        <?php if (!empty($field['tokens'])) : ?>
                            <span class="sps-tokens">
                                <?php esc_html_e('Tokens:', 'seoprostack'); ?>
                                <?php foreach ($field['tokens'] as $token) : ?>
                                    <button type="button" class="sps-token" data-token="<?php echo esc_attr($token); ?>" data-target="<?php echo esc_attr($id); ?>" title="<?php esc_attr_e('Insert token', 'seoprostack'); ?>"><code><?php echo esc_html($token); ?></code></button>
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
