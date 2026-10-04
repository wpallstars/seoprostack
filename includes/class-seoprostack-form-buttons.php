<?php
/**
 * Button layouts for Fluent Forms choices, loaded with Order flow.
 *
 * Adds choices to a field's Advanced > Layout setting in the Fluent Forms
 * editor (radio, checkbox, payment item and subscription fields), next to
 * Fluent Forms' own, which stay as they are:
 * - Buttons: one row: side by side in equal widths, joined as Fluent Forms'
 *   Button Type Styles draws them;
 * - Buttons: 1 to 4 columns: separate buttons, each with its full border.
 * Text wraps (Fluent Forms keeps it on one line, cutting it off), plans show
 * their name and price inside the button, and a clicked button's words are
 * not highlighted as if selected (keyboard focus shows as an outline). On
 * phones the buttons stack, as Fluent Forms' own do. Layout only: colours
 * and borders stay with Fluent Forms and its styler.
 *
 * Choices with these layouts:
 * - In a field that is not required, clicking the chosen button again clears
 *   it. The form then sends an empty answer, which Fluent Forms reads as
 *   "none" (with no answer at all it would charge the default plan).
 * - Fluent Forms takes one subscription per order, so choosing a plan clears
 *   any other subscription field's plan on the form, unless that one is
 *   required.
 * - A subscription field with one plan shows as one button, chosen to start
 *   with, that can be switched off unless the field is required.
 *
 * Without Order flow, fields set to these layouts show as Fluent Forms'
 * plain choices.
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

class SEOProStack_Form_Buttons {

    /** Layout values, stored in the field's settings.layout_class. */
    const ROW = 'sps_buttons_row';

    /** Column layouts: value prefix, followed by 1 to 4. */
    const COLUMNS = 'sps_buttons_';

    /** Script handle. */
    const HANDLE = 'seoprostack-form-buttons';

    /** Fields with a Layout setting. */
    const ELEMENTS = array('input_radio', 'input_checkbox', 'multi_payment_component', 'subscription_payment_component');

    /** Layout only, so Fluent Forms and its styler keep the colours and borders. */
    const CSS = '.fluentform .ff-el-group.ff_list_buttons.sps-buttons .ff-el-form-check label { -webkit-user-select: none; user-select: none; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons .ff-el-form-check label > span { line-height: 1.4; white-space: normal; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons .ff-el-form-check label:focus-within > span span { background: none; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons .ff-el-form-check label:has(input:focus-visible) > span { outline: 2px solid currentColor; outline-offset: 2px; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons .sps-button { display: flex; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons .sps-button .ff-el-form-check { display: flex; flex: 1 1 auto; margin: 0; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons .sps-button .ff-el-form-check label { display: flex; width: 100%; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons .sps-button .ff-el-form-check label > span { display: flex; flex-direction: column; justify-content: center; width: 100%; border-radius: 4px; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons-cols .ff-el-input--content { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; }
.fluentform .ff-el-group.ff_list_buttons.sps-buttons-cols .ff-el-input--content > :not(.sps-button) { grid-column: 1 / -1; }
@media (min-width: 769px) {
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-row .ff-el-input--content { display: flex; flex-wrap: wrap; }
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-row .ff-el-input--content > :not(.ff-el-form-check) { flex: 0 0 100%; }
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-row .ff-el-form-check { display: flex; flex: 1 1 0; min-width: 0; }
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-row .ff-el-form-check label { display: flex; width: 100%; }
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-row .ff-el-form-check label > span { display: flex; flex-direction: column; justify-content: center; width: 100%; }
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-2 .ff-el-input--content, .fluentform .ff-el-group.ff_list_buttons.sps-buttons-3 .ff-el-input--content, .fluentform .ff-el-group.ff_list_buttons.sps-buttons-4 .ff-el-input--content { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (min-width: 1025px) {
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-3 .ff-el-input--content { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .fluentform .ff-el-group.ff_list_buttons.sps-buttons-4 .ff-el-input--content { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}';

    /** Whether the styles are on the page yet. */
    private static $styled = false;

    /**
     * Register hooks.
     */
    public static function boot() {
        add_filter('fluentform/editor_element_customization_settings', array(__CLASS__, 'layouts'));
        add_filter('fluentform/before_render_item', array(__CLASS__, 'item'), 10, 1);
        foreach (self::ELEMENTS as $element) {
            add_filter('fluentform/rendering_field_html_' . $element, array(__CLASS__, 'html'), 10, 2);
        }
        add_filter('fluentform/validate_input_item_subscription_payment_component', array(__CLASS__, 'validate'), 20, 5);
    }

    /**
     * Accept an empty answer from a subscription field that is not required,
     * on a form with these layouts: a cleared plan. Fluent Forms' payments
     * read it as "no subscription", but its field check turns it away as an
     * invalid plan.
     *
     * @param string|array $error  Error so far.
     * @param array        $field  Field.
     * @param array        $data   Submitted data.
     * @param array        $fields Form fields.
     * @param object|null  $form   Form.
     * @return string|array
     */
    public static function validate($error, $field, $data, $fields = array(), $form = null) {
        if (!$error || empty($field['name']) || !isset($data[$field['name']]) || '' !== $data[$field['name']]) {
            return $error;
        }
        if (!empty($field['raw']['settings']['validation_rules']['required']['value'])) {
            return $error;
        }
        $source = is_object($form) && isset($form->form_fields) ? $form->form_fields : '';
        if (!is_string($source)) {
            $source = (string) wp_json_encode($source);
        }
        return false === strpos($source, '"' . self::COLUMNS) ? $error : '';
    }

    /**
     * Layout choices: value => label.
     *
     * @return array
     */
    public static function choices() {
        return array(
            self::ROW           => __('Buttons: one row', 'seoprostack'),
            self::COLUMNS . '1' => __('Buttons: 1 column', 'seoprostack'),
            self::COLUMNS . '2' => __('Buttons: 2 columns', 'seoprostack'),
            self::COLUMNS . '3' => __('Buttons: 3 columns', 'seoprostack'),
            self::COLUMNS . '4' => __('Buttons: 4 columns', 'seoprostack'),
        );
    }

    /**
     * Add the choices to the editor's Layout setting.
     *
     * @param array $settings Fluent Forms editor settings, by key.
     * @return array
     */
    public static function layouts($settings) {
        if (!isset($settings['layout_class']['options']) || !is_array($settings['layout_class']['options'])) {
            return $settings;
        }
        $have = wp_list_pluck($settings['layout_class']['options'], 'value');
        foreach (self::choices() as $value => $label) {
            if (!in_array($value, $have, true)) {
                $settings['layout_class']['options'][] = array('value' => $value, 'label' => $label);
            }
        }
        return $settings;
    }

    /**
     * Before a field renders: give a field with one of these layouts Fluent
     * Forms' button markup, and classes for the layout.
     *
     * @param array $item Field.
     * @return array
     */
    public static function item($item) {
        if (!is_array($item) || empty($item['element']) || !in_array($item['element'], self::ELEMENTS, true)) {
            return $item;
        }
        $layout  = isset($item['settings']['layout_class']) ? (string) $item['settings']['layout_class'] : '';
        $choices = self::choices();
        if (!isset($choices[$layout])) {
            return $item;
        }
        $element  = $item['element'];
        $type     = isset($item['attributes']['type']) ? (string) $item['attributes']['type'] : '';
        $required = !empty($item['settings']['validation_rules']['required']['value']);
        $classes  = array('sps-buttons');
        $classes[] = self::ROW === $layout ? 'sps-buttons-row' : 'sps-buttons-cols sps-buttons-' . substr($layout, strlen(self::COLUMNS));

        if ('subscription_payment_component' === $element) {
            $plans = isset($item['settings']['subscription_options']) ? array_values((array) $item['settings']['subscription_options']) : array();
            if ('single' === $type && $plans) {
                // One plan: a button, chosen to start with, instead of a
                // line of text that is always charged.
                $plans                     = array_slice($plans, 0, 1);
                $plans[0]['is_default']    = 'yes';
                $item['settings']['subscription_options'] = $plans;
                $item['attributes']['type'] = 'multiple';
            }
            $item['settings']['selection_type'] = 'radio';
            $radio = true;
        } else {
            $radio = 'input_radio' === $element || ('multi_payment_component' === $element && 'radio' === $type);
        }
        if ($radio && !$required) {
            $classes[] = 'sps-buttons-optional';
        }

        $item['settings']['layout_class']    = 'ff_list_buttons';
        $item['settings']['container_class'] = trim((isset($item['settings']['container_class']) ? (string) $item['settings']['container_class'] : '') . ' ' . implode(' ', $classes));
        self::script();
        return $item;
    }

    /**
     * A field's HTML: the styles before the first one, and, for columns,
     * each button in a cell of its own, so Fluent Forms draws its full
     * border (it leaves out the left one of every button but the first).
     *
     * @param string $html Field HTML.
     * @param array  $data Field.
     * @return string
     */
    public static function html($html, $data) {
        // The field's own classes, from its opening tag: radio and checkbox
        // fields pass data without them.
        $class = '';
        if (preg_match('#^\s*<div class=([\'"])([^\'"]*)\1#', (string) $html, $match)) {
            $class = ' ' . $match[2] . ' ';
        } elseif (isset($data['settings']['container_class'])) {
            $class = ' ' . $data['settings']['container_class'] . ' ';
        }
        if (false === strpos($class, ' sps-buttons ')) {
            return $html;
        }
        if (false !== strpos($class, ' sps-buttons-cols ')) {
            $wrapped = preg_replace(
                '#<div class=([\'"])ff-el-form-check(?:\s[^\'"]*)?\1>\s*<label\b.*?</label>\s*</div>#s',
                '<div class="sps-button">$0</div>',
                (string) $html
            );
            $html = null === $wrapped ? $html : $wrapped;
        }
        if (!self::$styled) {
            self::$styled = true;
            $html = '<style id="seoprostack-form-buttons">' . self::CSS . '</style>' . $html;
        }
        return $html;
    }

    /**
     * The script: clearing a chosen button, and one subscription per order.
     */
    private static function script() {
        if (wp_script_is(self::HANDLE, 'enqueued')) {
            return;
        }
        wp_register_script(self::HANDLE, false, array('jquery'), SEOPROSTACK_VERSION, true);
        wp_enqueue_script(self::HANDLE);
        wp_add_inline_script(self::HANDLE, self::js());
    }

    /**
     * Script source.
     *
     * @return string
     */
    private static function js() {
        return <<<'JS'
(function ($) {
    'use strict';
    // Radios of one field: same form, same name.
    function group($input) {
        var name = $input.attr('name');
        return $input.closest('form').find('input[type=radio]').filter(function () { return this.name === name; });
    }
    // An empty answer for a cleared field, so Fluent Forms reads "none" and
    // does not fall back to the default plan.
    function empty($input, on) {
        var $content = $input.closest('.ff-el-input--content');
        $content.children('input.sps-cleared').remove();
        if (on) {
            $('<input type="hidden" class="sps-cleared">').attr('name', $input.attr('name')).val('').appendTo($content);
        }
    }
    function clear($input) {
        var $group = group($input);
        $group.prop('checked', false).closest('.ff-el-form-check').removeClass('ff_item_selected');
        empty($input, true);
        $input.trigger('change');
    }
    function required($el) {
        return $el.closest('.ff-el-group').children('.ff-el-input--label').hasClass('ff-el-is-required');
    }
    // Fluent Forms takes one subscription per order: choosing one clears the
    // others on the form, unless they are required.
    function one($chosen) {
        var $form = $chosen.closest('form');
        if (!$form.find('.sps-buttons').length) {
            return;
        }
        var own = $chosen.attr('name');
        $form.find('input.ff_subscription_item[type=radio]:checked').each(function () {
            var $other = $(this);
            if ($other.attr('name') !== own && !required($other)) {
                clear($other);
            }
        });
        $form.find('select.ff_subscription_item').each(function () {
            var $other = $(this);
            if ($other.attr('name') !== own && $other.val() !== '' && !required($other)) {
                $other.val('').trigger('change');
            }
        });
    }
    // Whether the button was chosen before this click.
    $(document).on('mousedown touchstart', '.sps-buttons-optional .ff-el-form-check label', function () {
        var input = $(this).find('input[type=radio]')[0];
        if (input) {
            $(input).data('spsWas', input.checked);
        }
    });
    // Space on the chosen button clears it (browsers send no click for it).
    $(document).on('keydown', '.sps-buttons-optional input[type=radio]', function (event) {
        if (event.key === ' ' && this.checked) {
            event.preventDefault();
            clear($(this));
        }
    });
    $(document).on('click', '.sps-buttons-optional input[type=radio]', function () {
        var $input = $(this), was = $input.data('spsWas');
        $input.removeData('spsWas');
        if (was) {
            clear($input);
        }
    });
    $(document).on('change', 'input[type=radio], select.ff_subscription_item', function () {
        var $input = $(this);
        if ($input.is('select') ? $input.val() === '' : !this.checked) {
            return;
        }
        empty($input, false);
        if ($input.hasClass('ff_subscription_item')) {
            one($input);
        }
    });
    // Two plans chosen to start with: keep the first.
    function start($form) {
        var $first = $form.find('.ff_subscription_item:checked, select.ff_subscription_item').filter(function () {
            return $(this).is('select') ? $(this).val() !== '' : true;
        }).first();
        if ($first.length) {
            one($first);
        }
    }
    $(function () {
        $('form').has('.sps-buttons').each(function () {
            start($(this));
        });
    });
    // After a sent form is reset, its answers are the starting ones again
    // (the browser restores them after the reset event).
    $(document).on('reset', 'form', function () {
        var $form = $(this);
        if (!$form.find('.sps-buttons').length) {
            return;
        }
        $form.find('input.sps-cleared').remove();
        setTimeout(function () {
            start($form);
        }, 0);
    });
}(jQuery));
JS;
    }
}
