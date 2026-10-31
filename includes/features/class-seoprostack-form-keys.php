<?php
/**
 * Fluent Forms by key: place a Fluent form by a portable key, in a panel or
 * in a Kadence Blocks Pro modal, so page content never names a form ID.
 *
 * Form IDs differ on every site a page is copied to (a template, a clone, an
 * imported form). A form carries its key in Fluent Forms' form meta
 * (seoprostack_form_key, set with `wp seoprostack form-keys set`), and the
 * Form by key block (seoprostack/form) or [seoprostack_form key="contact"]
 * shows whichever published form holds that key on the current site. The
 * Form modal block (seoprostack/form-modal) or [seoprostack_form_modal]
 * prints the form at the foot of the page in Kadence Blocks Pro's modal
 * markup; any link to its #ID opens it (Kadence's modal-trigger links, or
 * SEO Pro Stack's Modal links). Without Kadence Blocks Pro the form stays in
 * place in a panel with the same ID, so the same links still reach it
 * (SEOProStack_Form_Modal).
 *
 * In a form modal: a press that starts in the panel and ends on the overlay
 * does not close it, an opened searchable list scrolls into view, and Tab
 * stays on the visible fields of a multi-step form (assets/form-keys.js).
 *
 * With "Policy links in forms open a new tab", links to the privacy policy,
 * cookie policy or terms inside a Fluent form open in a new tab, so reading
 * them never loses what the visitor typed (SEOProStack_Form_Policy_Links).
 *
 * Every submission of a keyed form fires seoprostack_form_submitted with the
 * key, so other plugins (a CRM hand-off) follow forms by key, not by ID. A
 * Fluent Support ticket made with no business inbox (a copied form's feed
 * cannot name one) goes to the default inbox.
 *
 * The Review links block (seoprostack/review-links), after a keyed feedback
 * form, shows the visitor's comment to copy and the site's review short
 * links once the form is sent (SEOProStack_Review_Links).
 *
 * Keys are stored and found by SEOProStack_Form_Key_Store and managed with
 * WP-CLI by SEOProStack_Form_Keys_CLI.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.97
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/class-seoprostack-form-key-store.php';
require_once dirname(__DIR__) . '/class-seoprostack-form-modal.php';
require_once dirname(__DIR__) . '/class-seoprostack-form-policy-links.php';
require_once dirname(__DIR__) . '/class-seoprostack-review-links.php';

class SEOProStack_Form_Keys extends SEOProStack_Feature {

    const KEY = 'fluentforms_keys';

    /** Setting: policy links in forms open a new tab. */
    const TABS_KEY = 'fluentforms_policy_tabs';

    /** Fluent Forms form meta holding a form's key. */
    const META = 'seoprostack_form_key';

    const BLOCK = 'seoprostack/form';

    const MODAL_BLOCK = 'seoprostack/form-modal';

    /** Script and style of form modals and panels. */
    const HANDLE = 'seoprostack-form-keys';

    /** The blocks' editor script. */
    const EDITOR_HANDLE = 'seoprostack-form-keys-editor';

    /** A key: lower-case words joined by hyphens. */
    const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY      => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'content',
                'label'       => __('Fluent Forms by key', 'seoprostack'),
                'description' => __('The Form by key and Form modal blocks show a Fluent form by a key it carries (wp seoprostack form-keys set), not its ID, so a page copied to another site shows that site\'s form. Form modal opens from any link to its #ID. Every submission of a keyed form tells other plugins its key (seoprostack_form_submitted). Nothing changes without Fluent Forms.', 'seoprostack'),
            ),
            self::TABS_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'content',
                'label'       => __('Policy links in forms open a new tab', 'seoprostack'),
                'description' => __('Links to the privacy policy, cookie policy or terms inside a Fluent form open in a new tab, marked for screen readers, so reading them never loses what was typed.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks, only with Fluent Forms.
     */
    public static function boot() {
        if (!defined('FLUENTFORM') && !class_exists('FluentForm\App\Models\Form')) {
            return;
        }
        if ((bool) SEOProStack_Settings::get(self::TABS_KEY)) {
            SEOProStack_Form_Policy_Links::boot();
        }
        if (!self::enabled()) {
            return;
        }
        self::register();
        add_action('fluentform/submission_inserted', array(__CLASS__, 'submitted'), 20, 3);
        add_filter('fluent_support/create_ticket_data', array(__CLASS__, 'default_inbox'), 5);
        add_filter('enqueue_empty_block_content_assets', array('SEOProStack_Form_Modal', 'keep_assets'), 10, 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 20);
        add_action('enqueue_block_assets', array(__CLASS__, 'assets'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_data'));
        add_action('enqueue_block_editor_assets', array('SEOProStack_Review_Links', 'editor_data'));
        if (defined('WP_CLI') && WP_CLI) {
            require_once dirname(__DIR__) . '/class-seoprostack-form-keys-cli.php';
            WP_CLI::add_command('seoprostack form-keys', 'SEOProStack_Form_Keys_CLI');
        }
    }

    /**
     * The blocks, their editor script and the shortcodes.
     */
    private static function register() {
        $js = 'blocks/form/index.js';
        wp_register_script(
            self::EDITOR_HANDLE,
            SEOPROSTACK_URL . $js,
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'),
            (string) filemtime(SEOPROSTACK_DIR . $js),
            true
        );
        register_block_type(SEOPROSTACK_DIR . 'blocks/form', array('render_callback' => array(__CLASS__, 'render_block')));
        register_block_type(SEOPROSTACK_DIR . 'blocks/form-modal', array('render_callback' => array('SEOProStack_Form_Modal', 'render_block')));
        add_shortcode('seoprostack_form', array(__CLASS__, 'shortcode'));
        add_shortcode('seoprostack_form_modal', array('SEOProStack_Form_Modal', 'shortcode'));
        SEOProStack_Review_Links::register();
    }

    /**
     * A keyed form's markup, or '' when no published form holds the key.
     *
     * @param string $key Form key.
     * @return string
     */
    public static function form_html($key) {
        if (!shortcode_exists('fluentform')) {
            return '';
        }
        $id = SEOProStack_Form_Key_Store::form_id($key);
        return $id ? do_shortcode(sprintf('[fluentform id="%d"]', $id)) : '';
    }

    /**
     * A keyed form, in a panel or bare. Visitors see nothing when the form
     * is missing; editors see a short notice.
     *
     * @param string $key   Form key.
     * @param bool   $panel Whether to draw the panel.
     * @return string
     */
    public static function render($key, $panel = true) {
        $form = self::form_html($key);
        if ('' === $form) {
            return self::missing($key);
        }
        if (!$panel) {
            return $form;
        }
        self::enqueue_style();
        return '<div class="seoprostack-form seoprostack-form-panel">' . $form . '</div>';
    }

    /**
     * [seoprostack_form key="contact" panel="1"].
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function shortcode($atts) {
        // WordPress passes '' when there are no attributes.
        $atts = shortcode_atts(array('key' => '', 'panel' => '1'), is_array($atts) ? $atts : array(), 'seoprostack_form');
        return self::render((string) $atts['key'], '0' !== (string) $atts['panel']);
    }

    /**
     * The Form by key block.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_block($attributes) {
        $attributes = is_array($attributes) ? $attributes : array();
        return self::render((string) ($attributes['formKey'] ?? ''), !isset($attributes['panel']) || (bool) $attributes['panel']);
    }

    /** The panel and modal base style, which any theme rule naming these classes beats. */
    public static function enqueue_style() {
        $css = 'assets/form-keys.css';
        wp_enqueue_style(self::HANDLE, SEOPROSTACK_URL . $css, array(), (string) filemtime(SEOPROSTACK_DIR . $css));
    }

    /**
     * The panel style in the block editor's canvas, for the form preview;
     * on the front end, the style (and the modal's) in the head of a page
     * whose content has a form block or shortcode, so nothing shows
     * unstyled.
     */
    public static function assets() {
        if (is_admin()) {
            self::enqueue_style();
            return;
        }
        $post = get_post();
        if (!is_singular() || !$post) {
            return;
        }
        if (has_block(self::MODAL_BLOCK, $post) || has_shortcode($post->post_content, 'seoprostack_form_modal')) {
            SEOProStack_Form_Modal::enqueue();
        } elseif (has_block(self::BLOCK, $post) || has_shortcode($post->post_content, 'seoprostack_form')) {
            self::enqueue_style();
        }
    }

    /** The site's keyed forms for the blocks' Form setting. */
    public static function editor_data() {
        $forms = array();
        foreach (SEOProStack_Form_Key_Store::keyed_forms() as $key => $title) {
            $forms[] = array('key' => $key, 'title' => $title);
        }
        wp_add_inline_script(self::EDITOR_HANDLE, 'window.seoprostackFormKeys = ' . wp_json_encode(array('forms' => $forms, 'modal' => SEOProStack_Form_Modal::available())) . ';', 'before');
    }

    /**
     * A keyed form was submitted: tell other plugins its key.
     *
     * @param int    $entry_id Entry ID.
     * @param array  $data     Submitted data.
     * @param object $form     Fluent form.
     */
    public static function submitted($entry_id, $data, $form) {
        if (!is_object($form) || empty($form->id) || !is_array($data)) {
            return;
        }
        $key = SEOProStack_Form_Key_Store::key_of((int) $form->id);
        if ('' === $key) {
            return;
        }
        /**
         * A form carrying a key was submitted.
         *
         * @param string $key      The form's key.
         * @param array  $data     Submitted data, by field name.
         * @param object $form     Fluent form.
         * @param int    $entry_id Fluent Forms entry ID.
         */
        do_action('seoprostack_form_submitted', $key, $data, $form, (int) $entry_id);
    }

    /**
     * A Fluent Support ticket with no business inbox goes to the default one.
     *
     * A form copied to another site (a template's contact or feedback form)
     * can carry a Fluent Support feed, which names a business inbox by ID; IDs
     * differ per site and the inbox exists only once Fluent Support is
     * active, so such a feed leaves it empty. Fluent Support would then make
     * a ticket in no inbox, which agents do not see.
     *
     * @param array $ticket Ticket data (fluent_support/create_ticket_data).
     * @return array
     */
    public static function default_inbox($ticket) {
        if (!is_array($ticket) || !empty($ticket['mailbox_id']) || !class_exists('FluentSupport\App\Services\Helper')) {
            return $ticket;
        }
        $inbox = \FluentSupport\App\Services\Helper::getDefaultMailBox();
        if ($inbox && !empty($inbox->id)) {
            $ticket['mailbox_id'] = (int) $inbox->id;
        }
        return $ticket;
    }

    /**
     * Explain a missing form to editors only.
     *
     * @param string $key Requested key.
     * @return string
     */
    private static function missing($key) {
        if (!current_user_can('edit_pages')) {
            return '';
        }
        $text = '' === (string) $key
            ? __('Choose a form in the block settings.', 'seoprostack')
            /* translators: %s: form key */
            : sprintf(__('No published Fluent form has the key "%s". Give one the key with wp seoprostack form-keys set.', 'seoprostack'), $key);
        return '<p class="seoprostack-form-missing">' . esc_html($text) . '</p>';
    }
}
