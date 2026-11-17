<?php
/**
 * Fluent Forms: a Website URL field (input_url) accepts an address typed
 * without https://, such as www.example.com or example.com, and adds it.
 *
 * Fluent Forms prints the field as <input type="url"> in a form the browser
 * validates, so the browser refused such an address ("Please enter a URL")
 * before Fluent Forms' own script, whose url rule also wants a scheme. The
 * server already accepted it: Fluent Forms runs sanitize_url() on the value
 * before validating, which adds http://.
 *
 * - The field becomes a text input with inputmode="url" (the same keyboard
 *   on phones), so the browser no longer blocks it; Fluent Forms' url rule,
 *   which comes from the form's rules, still applies.
 * - assets/form-url-fields.js adds https:// when the visitor leaves the
 *   field and when the form is sent (pressing Enter does not leave it), to
 *   anything that looks like a web address, and shows Fluent Forms' own url
 *   message at once for anything else.
 * - Without the script (a direct post), the http:// that sanitize_url()
 *   added to an address typed without a scheme becomes https:// before the
 *   entry is saved, so entries, notifications and integrations get the same
 *   address either way.
 *
 * Addresses typed with a scheme are left as typed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.226
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Form_Url_Fields {

    /** The field script. */
    const HANDLE = 'seoprostack-form-url-fields';

    /** Marks a field the script looks after. */
    const MARK = 'data-seoprostack-url';

    /**
     * Filter the field's HTML, after other filters of it, and the entry
     * before it is saved.
     */
    public static function boot() {
        add_filter('fluentform/rendering_field_html_input_url', array(__CLASS__, 'filter'), 25, 1);
        add_filter('fluentform/insert_response_data', array(__CLASS__, 'stored'), 20, 3);
    }

    /**
     * Make the field a text input with a URL keyboard, and load the script.
     *
     * @param string $html Field HTML.
     * @return string
     */
    public static function filter($html) {
        if (!is_string($html) || false === stripos($html, 'url')) {
            return $html;
        }
        $tags  = new WP_HTML_Tag_Processor($html);
        $found = false;
        while ($tags->next_tag(array('tag_name' => 'input'))) {
            $type = $tags->get_attribute('type');
            if (!is_string($type) || 'url' !== strtolower($type)) {
                continue;
            }
            $tags->set_attribute('type', 'text');
            $tags->set_attribute('inputmode', 'url');
            foreach (array('autocomplete' => 'url', 'autocapitalize' => 'off', 'spellcheck' => 'false') as $name => $value) {
                if (null === $tags->get_attribute($name)) {
                    $tags->set_attribute($name, $value);
                }
            }
            $tags->set_attribute(self::MARK, true);
            $found = true;
        }
        if (!$found) {
            return $html;
        }
        self::enqueue();
        return $tags->get_updated_html();
    }

    /**
     * The script, at the end of the page (a field is printed in the content
     * or, in a form modal, before the footer's scripts).
     */
    public static function enqueue() {
        if (wp_script_is(self::HANDLE, 'enqueued')) {
            return;
        }
        $js = 'assets/form-url-fields.js';
        wp_enqueue_script(self::HANDLE, SEOProStack::asset_url($js), array(), (string) filemtime(SEOPROSTACK_DIR . $js), array('strategy' => 'defer', 'in_footer' => true));
    }

    /**
     * Before the entry is saved: https:// for each Website URL field whose
     * address was typed without a scheme.
     *
     * @param mixed $form_data Validated, sanitised submission.
     * @param mixed $form_id   Form ID.
     * @param mixed $inputs    The form's fields by name (element, admin_label, raw).
     * @return mixed
     */
    public static function stored($form_data, $form_id = 0, $inputs = array()) {
        unset($form_id);
        if (!is_array($form_data) || !is_array($inputs)) {
            return $form_data;
        }
        $typed = null;
        foreach ($inputs as $name => $input) {
            if (!is_array($input) || 'input_url' !== ($input['element'] ?? '') || !isset($form_data[$name]) || !is_string($form_data[$name])) {
                continue;
            }
            if (null === $typed) {
                $typed = self::typed();
            }
            if (isset($typed[$name]) && is_string($typed[$name])) {
                $form_data[$name] = self::secure($typed[$name], $form_data[$name]);
            }
        }
        return $form_data;
    }

    /**
     * https:// in place of the http:// that sanitize_url() adds to an
     * address typed without a scheme.
     *
     * @param string $typed The value as typed.
     * @param string $value The sanitised value.
     * @return string
     */
    public static function secure($typed, $value) {
        $typed = trim($typed);
        if ('' === $typed || 1 === preg_match('#^[a-z][a-z0-9+.\-]*:#i', $typed) || 0 !== strpos($value, 'http://')) {
            return $value;
        }
        return 'https://' . substr($value, 7);
    }

    /**
     * The submission as the visitor sent it: Fluent Forms posts the form's
     * fields as one encoded string in data.
     *
     * @return array<int|string,mixed>
     */
    private static function typed() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read inside Fluent Forms' own submission handling, which checks the form's nonce and spam protection; an encoded query string that sanitising would corrupt, parsed only to see whether each field was typed with a scheme, never output or stored.
        $data = isset($_POST['data']) && is_string($_POST['data']) ? wp_unslash($_POST['data']) : '';
        if ('' === $data) {
            return array();
        }
        $typed = array();
        parse_str($data, $typed);
        return $typed;
    }
}
