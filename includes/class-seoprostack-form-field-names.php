<?php
/**
 * Fluent Forms: a field with no label gets an accessible name. Fluent Forms
 * writes aria-labelledby="label_<field id>" on every input, select and
 * textarea but prints that label only when the field has one, so a form that
 * uses placeholders instead of labels points at an element that does not
 * exist and screen readers announce an unnamed field. The reference is
 * replaced by aria-label, from the placeholder, the admin label or the
 * field's name. Fields with a printed label are left alone.
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

class SEOProStack_Form_Field_Names {

    /** The single-input fields that carry aria-labelledby. */
    const ELEMENTS = array(
        'select',
        'input_text',
        'input_email',
        'input_number',
        'input_url',
        'input_password',
        'input_date',
        'phone',
        'textarea',
    );

    /**
     * Filter the fields' HTML, after other filters of the same fields.
     */
    public static function boot() {
        foreach (self::ELEMENTS as $element) {
            add_filter('fluentform/rendering_field_html_' . $element, array(__CLASS__, 'filter'), 25, 2);
        }
    }

    /**
     * Name an unlabelled field.
     *
     * @param string $html Field HTML.
     * @param mixed  $data Field definition.
     * @return string
     */
    public static function filter($html, $data = array()) {
        if (!is_string($html) || false === stripos($html, 'aria-labelledby') || !is_array($data)) {
            return $html;
        }
        $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : array();
        if (isset($settings['label']) && '' !== trim(wp_strip_all_tags((string) $settings['label']))) {
            return $html;
        }
        $name = self::name($data, $settings);
        if ('' === $name) {
            return $html;
        }
        $tags = new WP_HTML_Tag_Processor($html);
        while ($tags->next_tag()) {
            if (!in_array($tags->get_tag(), array('SELECT', 'INPUT', 'TEXTAREA'), true)) {
                continue;
            }
            $ref = $tags->get_attribute('aria-labelledby');
            if (!is_string($ref) || 0 !== strpos($ref, 'label_')) {
                continue;
            }
            // A label with that ID in the HTML means the reference works.
            if (false !== strpos($html, 'id="' . $ref . '"') || false !== strpos($html, "id='" . $ref . "'")) {
                break;
            }
            $tags->remove_attribute('aria-labelledby');
            if (!is_string($tags->get_attribute('aria-label')) || '' === $tags->get_attribute('aria-label')) {
                $tags->set_attribute('aria-label', $name);
            }
            break;
        }
        return $tags->get_updated_html();
    }

    /**
     * The text for the field: placeholder, admin label, then field name.
     *
     * @param array<string,mixed> $data     Field definition.
     * @param array<string,mixed> $settings Field settings.
     * @return string
     */
    private static function name(array $data, array $settings) {
        $attributes = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : array();
        $candidates = array(
            isset($attributes['placeholder']) ? $attributes['placeholder'] : '',
            isset($settings['admin_field_label']) ? $settings['admin_field_label'] : '',
            isset($attributes['name']) ? $attributes['name'] : '',
        );
        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $text = trim(wp_strip_all_tags((string) $candidate));
            if ('' !== $text) {
                return $text;
            }
        }
        return '';
    }
}
