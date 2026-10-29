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
 * place in a panel with the same ID, so the same links still reach it.
 *
 * In a form modal: a press that starts in the panel and ends on the overlay
 * does not close it, an opened searchable list scrolls into view, and Tab
 * stays on the visible fields of a multi-step form (assets/form-keys.js).
 *
 * With "Policy links in forms open a new tab", links to the privacy policy,
 * cookie policy or terms inside a Fluent form open in a new tab, so reading
 * them never loses what the visitor typed.
 *
 * Every submission of a keyed form fires seoprostack_form_submitted with the
 * key, so other plugins (a CRM hand-off) follow forms by key, not by ID.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.96
 */

if (!defined('ABSPATH')) {
    exit;
}

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

    /** Kadence Blocks Pro's modal script and style handles. */
    const MODAL_HANDLE = 'kadence-blocks-pro-modal';

    /** A key: lower-case words joined by hyphens. */
    const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Form IDs resolved on this request. @var array<string,int> */
    private static $ids = array();

    /** Modal IDs printed on this request. @var array<string,bool> */
    private static $printed = array();

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
            // After other filters of the same fields: a link one of them has
            // already opened in a new tab is left alone.
            foreach (array('terms_and_condition', 'gdpr_agreement', 'input_checkbox', 'custom_html') as $element) {
                add_filter('fluentform/rendering_field_html_' . $element, array(__CLASS__, 'policy_links'), 25);
            }
        }
        if (!self::enabled()) {
            return;
        }
        self::register();
        add_action('fluentform/submission_inserted', array(__CLASS__, 'submitted'), 20, 3);
        add_filter('enqueue_empty_block_content_assets', array(__CLASS__, 'keep_modal_assets'), 10, 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue'), 20);
        add_action('enqueue_block_assets', array(__CLASS__, 'editor_styles'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_data'));
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack form-keys', array(__CLASS__, 'cli'));
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
        register_block_type(SEOPROSTACK_DIR . 'blocks/form-modal', array('render_callback' => array(__CLASS__, 'render_modal_block')));
        add_shortcode('seoprostack_form', array(__CLASS__, 'shortcode'));
        add_shortcode('seoprostack_form_modal', array(__CLASS__, 'modal_shortcode'));
    }

    /* ------------------------------------------------------------------
     * Forms by key
     * ------------------------------------------------------------------ */

    /**
     * The published form on this site that carries a key, or 0. Fluent
     * Forms' tables are per site; the oldest form wins a shared key.
     *
     * @param string $key Form key.
     * @return int
     */
    public static function form_id($key) {
        $key = (string) $key;
        if (!preg_match(self::PATTERN, $key)) {
            return 0;
        }
        $cache = get_current_blog_id() . ':' . $key;
        if (!isset(self::$ids[$cache])) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own tables; resolved once per request.
            self::$ids[$cache] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT m.form_id FROM {$wpdb->prefix}fluentform_form_meta m INNER JOIN {$wpdb->prefix}fluentform_forms f ON f.id = m.form_id WHERE m.meta_key = %s AND m.value = %s AND f.status = 'published' ORDER BY m.form_id ASC LIMIT 1",
                self::META,
                $key
            ));
        }
        return self::$ids[$cache];
    }

    /**
     * The key a form carries, or ''.
     *
     * @param int $form_id Form ID.
     * @return string
     */
    public static function key_of($form_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table; once per submission.
        $key = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d AND meta_key = %s ORDER BY id ASC LIMIT 1",
            (int) $form_id,
            self::META
        ));
        return preg_match(self::PATTERN, $key) ? $key : '';
    }

    /**
     * Keyed published forms, for the editor: key => form title.
     *
     * @return array<string,string>
     */
    public static function keyed_forms() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own tables; editor only.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.value AS form_key, f.title FROM {$wpdb->prefix}fluentform_form_meta m INNER JOIN {$wpdb->prefix}fluentform_forms f ON f.id = m.form_id WHERE m.meta_key = %s AND f.status = 'published' ORDER BY m.form_id ASC",
            self::META
        ));
        $forms = array();
        foreach ((array) $rows as $row) {
            $key = (string) $row->form_key;
            if (preg_match(self::PATTERN, $key) && !isset($forms[$key])) {
                $forms[$key] = (string) $row->title;
            }
        }
        return $forms;
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
        $id = self::form_id($key);
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

    /* ------------------------------------------------------------------
     * Form modal
     * ------------------------------------------------------------------ */

    /**
     * Whether Kadence Blocks Pro's modal is available.
     *
     * @return bool
     */
    public static function modal_available() {
        return defined('KBP_URL') && defined('KBP_VERSION');
    }

    /**
     * A keyed form in a modal, printed at the foot of the page so no row's
     * stacking context traps it. Prints nothing in place, and each ID once.
     * Without Kadence Blocks Pro the form stays here, in a panel with the ID.
     *
     * @param array $atts key, id (the modal's ID, default the key), title, intro.
     * @return string
     */
    public static function modal(array $atts) {
        $key   = (string) ($atts['key'] ?? '');
        $id    = sanitize_html_class('' !== (string) ($atts['id'] ?? '') ? (string) $atts['id'] : $key);
        $title = (string) ($atts['title'] ?? '');
        $intro = (string) ($atts['intro'] ?? '');
        $kbp   = self::modal_available();
        if ('' === $id || ($kbp && isset(self::$printed[$id]))) {
            return '';
        }
        $form = self::form_html($key);
        if ('' === $form) {
            return self::missing($key);
        }
        $head = '' !== $title ? '<h2 id="' . esc_attr($id) . '-title" class="seoprostack-form-modal-title">' . esc_html($title) . '</h2>' : '';
        if ('' !== $intro) {
            $head .= '<p class="seoprostack-form-modal-intro">' . esc_html($intro) . '</p>';
        }
        if (!$kbp) {
            self::enqueue_style();
            return '<div id="' . esc_attr($id) . '" class="seoprostack-form seoprostack-form-panel">' . $head . $form . '</div>';
        }
        self::$printed[$id] = true;
        self::modal_enqueue();
        $label = '' !== $title ? ' aria-labelledby="' . esc_attr($id) . '-title"' : ' aria-label="' . esc_attr__('Form', 'seoprostack') . '"';
        $close = '<button type="button" class="kt-modal-close" aria-label="' . esc_attr__('Close', 'seoprostack') . '" data-modal-close="true">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>';
        // Kadence Blocks Pro's Modal block markup (its save()), so its script
        // and stylesheet drive it.
        $html = '<div id="' . esc_attr($id) . '" class="kadence-block-pro-modal seoprostack-form-modal kt-m-animate-in-fadeup kt-m-animate-out-fadeout" aria-hidden="true">'
            . '<div class="kt-modal-overlay" tabindex="-1" data-modal-close="true">'
            . '<div class="kt-modal-container kt-modal-height-auto kt-close-position-inside" role="dialog" aria-modal="true"' . $label . '>'
            . $close . '<div class="kt-modal-content">' . $head . $form . '</div>'
            . '</div></div></div>';
        add_action(
            'wp_footer',
            static function () use ($html) {
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above; the form is Fluent Forms' output.
            },
            9
        );
        return '';
    }

    /**
     * [seoprostack_form_modal key="request" id="request" title="..." intro="..."].
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function modal_shortcode($atts) {
        return self::modal(shortcode_atts(array('key' => '', 'id' => '', 'title' => '', 'intro' => ''), is_array($atts) ? $atts : array(), 'seoprostack_form_modal'));
    }

    /**
     * The Form modal block.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_modal_block($attributes) {
        $attributes = is_array($attributes) ? $attributes : array();
        return self::modal(array(
            'key'   => (string) ($attributes['formKey'] ?? ''),
            'id'    => (string) ($attributes['modalId'] ?? ''),
            'title' => (string) ($attributes['title'] ?? ''),
            'intro' => (string) ($attributes['intro'] ?? ''),
        ));
    }

    /**
     * Keep the assets a Form modal block enqueues: it prints nothing in
     * place, and since WordPress 6.9 an empty block's assets are dequeued,
     * which left the modal's form unstyled and stuck loading on pages with
     * no other form.
     *
     * @param bool   $enqueue    Whether to keep them.
     * @param string $block_name Block name.
     * @return bool
     */
    public static function keep_modal_assets($enqueue, $block_name) {
        return self::MODAL_BLOCK === $block_name ? true : (bool) $enqueue;
    }

    /**
     * Kadence Blocks Pro's modal script and stylesheet under the Modal
     * block's own handles, so a page that also has a Modal block loads them
     * once, then this feature's script and style.
     */
    public static function modal_enqueue() {
        if (!self::modal_available()) {
            return;
        }
        $url     = (string) constant('KBP_URL');
        $version = (string) constant('KBP_VERSION');
        if (!wp_script_is(self::MODAL_HANDLE, 'registered')) {
            wp_register_script(self::MODAL_HANDLE, $url . 'includes/assets/js/kt-modal-init.min.js', array(), $version, true);
        }
        if (!wp_style_is(self::MODAL_HANDLE, 'registered')) {
            wp_register_style(self::MODAL_HANDLE, $url . 'dist/style-blocks-modal.css', array(), $version);
        }
        wp_enqueue_script(self::MODAL_HANDLE);
        wp_enqueue_style(self::MODAL_HANDLE);
        $js = 'assets/form-keys.js';
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $js, array(self::MODAL_HANDLE), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        self::enqueue_style();
    }

    /** The panel and modal base style, which any theme rule naming these classes beats. */
    public static function enqueue_style() {
        $css = 'assets/form-keys.css';
        wp_enqueue_style(self::HANDLE, SEOPROSTACK_URL . $css, array(), (string) filemtime(SEOPROSTACK_DIR . $css));
    }

    /**
     * Load the style (and the modal's) in the head on a page whose content
     * has a form block or shortcode, so nothing shows unstyled.
     */
    public static function maybe_enqueue() {
        $post = get_post();
        if (!is_singular() || !$post) {
            return;
        }
        if (has_block(self::MODAL_BLOCK, $post) || has_shortcode($post->post_content, 'seoprostack_form_modal')) {
            self::modal_enqueue();
        } elseif (has_block(self::BLOCK, $post) || has_shortcode($post->post_content, 'seoprostack_form')) {
            self::enqueue_style();
        }
    }

    /** The panel style in the block editor's canvas, for the form preview. */
    public static function editor_styles() {
        if (is_admin()) {
            self::enqueue_style();
        }
    }

    /** The site's keyed forms for the blocks' Form setting. */
    public static function editor_data() {
        $forms = array();
        foreach (self::keyed_forms() as $key => $title) {
            $forms[] = array('key' => $key, 'title' => $title);
        }
        wp_add_inline_script(self::EDITOR_HANDLE, 'window.seoprostackFormKeys = ' . wp_json_encode(array('forms' => $forms, 'modal' => self::modal_available())) . ';', 'before');
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

    /* ------------------------------------------------------------------
     * Submissions and policy links
     * ------------------------------------------------------------------ */

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
        $key = self::key_of((int) $form->id);
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
     * Policy page paths matched in form links.
     *
     * @return string[]
     */
    private static function policy_paths() {
        $paths = array('privacy-policy', 'cookie-policy', 'terms', 'terms-and-conditions', 'terms-of-service', 'terms-of-use');
        $privacy = (int) get_option('wp_page_for_privacy_policy');
        if ($privacy) {
            $path = trim((string) wp_parse_url((string) get_permalink($privacy), PHP_URL_PATH), '/');
            if ('' !== $path) {
                $paths[] = $path;
            }
        }
        /**
         * Filter the page paths whose links in forms open a new tab.
         *
         * @param string[] $paths Paths without slashes at either end, such as "privacy-policy".
         */
        $paths = (array) apply_filters('seoprostack_form_policy_paths', $paths);
        return array_values(array_unique(array_filter(array_map(static function ($path) {
            return trim((string) $path, '/');
        }, $paths))));
    }

    /**
     * Open a form's policy links in a new tab, with rel="noopener", the
     * seoprostack-new-tab class and "(opens in a new tab)" for screen
     * readers. Links already opening a new tab are left alone.
     *
     * @param string $html Field HTML.
     * @return string
     */
    public static function policy_links($html) {
        if (!is_string($html) || false === stripos($html, '<a')) {
            return $html;
        }
        $paths = self::policy_paths();
        if (!$paths) {
            return $html;
        }
        $pattern = '~/(?:' . implode('|', array_map(static function ($path) {
            return preg_quote($path, '~');
        }, $paths)) . ')/?(?:[?#].*)?$~i';
        return (string) preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a>~is',
            static function ($match) use ($pattern) {
                $attrs = $match[1];
                if (!preg_match('~\bhref\s*=\s*(["\'])([^"\']*)\1~i', $attrs, $href) || !preg_match($pattern, $href[2]) || preg_match('~\btarget\s*=\s*(["\'])_blank\1~i', $attrs)) {
                    return $match[0];
                }
                $attrs = (string) preg_replace('~\s(?:target|rel)\s*=\s*(["\'])[^"\']*\1~i', '', $attrs);
                if (preg_match('~\bclass\s*=\s*(["\'])([^"\']*)\1~i', $attrs)) {
                    $attrs = (string) preg_replace('~\bclass\s*=\s*(["\'])([^"\']*)\1~i', 'class=$1$2 seoprostack-new-tab$1', $attrs, 1);
                } else {
                    $attrs .= ' class="seoprostack-new-tab"';
                }
                return '<a' . $attrs . ' target="_blank" rel="noopener">' . $match[2] . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'seoprostack') . '</span></a>';
            },
            $html
        );
    }

    /* ------------------------------------------------------------------
     * WP-CLI
     * ------------------------------------------------------------------ */

    /**
     * List, set or remove Fluent form keys on this site.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, set or unset.
     *
     * [<form-id>]
     * : Fluent form ID (set, unset).
     *
     * [<key>]
     * : Key, lower-case words joined by hyphens (set).
     *
     * [--copy-from=<meta-key>]
     * : With list, also show each form's value of another form meta key; with
     *   set and no form ID, give every form that has that meta key the same
     *   value as its key (forms that already have a key are left alone).
     *
     * ## EXAMPLES
     *
     *     wp seoprostack form-keys list
     *     wp seoprostack form-keys set 3 contact
     *     wp seoprostack form-keys set --copy-from=old_form_key
     *     wp seoprostack form-keys unset 3
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Associative arguments.
     */
    public static function cli($args, $assoc) {
        global $wpdb;
        $action = (string) ($args[0] ?? '');
        $meta   = $wpdb->prefix . 'fluentform_form_meta';
        $copy   = isset($assoc['copy-from']) ? sanitize_key((string) $assoc['copy-from']) : '';
        if ('list' === $action) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own tables.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT f.id, f.title, f.status, (SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = f.id AND meta_key = %s ORDER BY id ASC LIMIT 1) AS form_key, (SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = f.id AND meta_key = %s ORDER BY id ASC LIMIT 1) AS other FROM {$wpdb->prefix}fluentform_forms f ORDER BY f.id ASC",
                self::META,
                '' !== $copy ? $copy : self::META
            ), ARRAY_A);
            $fields = '' !== $copy ? array('id', 'title', 'status', 'form_key', 'other') : array('id', 'title', 'status', 'form_key');
            WP_CLI\Utils\format_items('table', (array) $rows, $fields);
            return;
        }
        if ('set' === $action && '' !== $copy && !isset($args[1])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT o.form_id, o.value FROM {$wpdb->prefix}fluentform_form_meta o WHERE o.meta_key = %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}fluentform_form_meta k WHERE k.form_id = o.form_id AND k.meta_key = %s) ORDER BY o.form_id ASC",
                $copy,
                self::META
            ));
            $done = 0;
            foreach ((array) $rows as $row) {
                if (preg_match(self::PATTERN, (string) $row->value) && self::set_key((int) $row->form_id, (string) $row->value)) {
                    WP_CLI::log(sprintf('Form %d: %s', (int) $row->form_id, (string) $row->value));
                    ++$done;
                }
            }
            WP_CLI::success(sprintf('%d form keys set.', $done));
            return;
        }
        $form_id = (int) ($args[1] ?? 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table.
        if ($form_id < 1 || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", $form_id))) {
            WP_CLI::error('Use list, set <form-id> <key>, set --copy-from=<meta-key> or unset <form-id>, with an existing form ID.');
        }
        if ('unset' === $action) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms' own table, indexed by form.
            $wpdb->delete($meta, array('form_id' => $form_id, 'meta_key' => self::META));
            WP_CLI::success(sprintf('Form %d has no key.', $form_id));
            return;
        }
        $key = (string) ($args[2] ?? '');
        if ('set' !== $action || !preg_match(self::PATTERN, $key)) {
            WP_CLI::error('A key is lower-case letters and digits, words joined by hyphens.');
        }
        $other = self::form_id($key);
        if ($other && $other !== $form_id) {
            WP_CLI::warning(sprintf('Form %d already has the key %s and, as the older form, keeps showing for it.', $other, $key));
        }
        self::set_key($form_id, $key);
        WP_CLI::success(sprintf('Form %d: %s', $form_id, $key));
    }

    /**
     * Give a form its key, replacing any it had.
     *
     * @param int    $form_id Form ID.
     * @param string $key     Key.
     * @return bool
     */
    private static function set_key($form_id, $key) {
        global $wpdb;
        $meta = $wpdb->prefix . 'fluentform_form_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms' own table, indexed by form.
        $wpdb->delete($meta, array('form_id' => (int) $form_id, 'meta_key' => self::META));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms' own table; one row.
        $saved = (bool) $wpdb->insert($meta, array('form_id' => (int) $form_id, 'meta_key' => self::META, 'value' => (string) $key));
        self::$ids = array();
        return $saved;
    }
}
