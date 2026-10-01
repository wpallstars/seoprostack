<?php
/**
 * Move admin notices behind a bell in the admin bar.
 *
 * WordPress's common.js moves every notice (div.notice, .updated, .error
 * that is not .inline or .below-h2) under the page heading when the page is
 * ready. This feature takes the same set, plus the core update nag, and puts
 * it in a panel that drops down from a bell with a count at the right of the
 * admin bar. The admin bar is the one place every admin screen leaves alone,
 * so the bell never sits on a plugin's own header.
 *
 * Everything printed on the notice hooks is caught too, whatever its markup
 * or wrapper (WooCommerce wraps all notices in a hidden list): markers around
 * the hooks' output let a small inline script tag it before common.js runs.
 * Notices that scripts add after the page has loaded are caught until the
 * person first clicks or types; ones drawn by React or Vue stay in place,
 * hidden, and a copy in the panel passes clicks back to them.
 *
 * The panel stays inside #wpbody-content, where the notices were printed, so
 * plugin styles and click handlers scoped to that area keep working.
 *
 * Kept in place: messages about what you just did (#message, settings
 * errors), inline notices inside the page's content (inline notices printed
 * above the page are moved), hidden notices, notices added after a click or
 * key press, and any types chosen in the settings. Until the move runs, the
 * notices are hidden with CSS, so they do not flash or push the page down;
 * without JavaScript they are not hidden at all.
 * The block editor has its own notices and is left alone.
 *
 * "Show example notices" prints one notice of each kind, to try it out.
 *
 * Replaces "Hide Admin Notices", which has no settings to import.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Notices extends SEOProStack_Feature {

    const KEY = 'hide_admin_notices';

    /** Body class while notices wait to be moved. */
    const LOADING = 'sps-notices-loading';

    /** Notice markup, as common.js selects it. */
    const NOTICES = 'div.updated, div.error, div.notice';

    /** Script handle. */
    const HANDLE = 'seoprostack-admin-notices';

    /** Attribute set on everything printed on the notice hooks that is moved or kept. */
    const MARK = 'data-sps-notice';

    /** Admin bar node; its list item's id is "wp-admin-bar-" followed by this. */
    const NODE = 'sps-notices';

    /** Setting that prints example notices. */
    const EXAMPLES = 'hide_admin_notices_examples';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Hide admin notices', 'seoprostack'),
                'description' => __('Move plugin and theme notices behind a bell in the admin bar, with a count. Messages about what you just did, such as “Settings saved”, stay on the page.', 'seoprostack'),
                'replaces'    => array('hide-admin-notices' => 'Hide Admin Notices'),
            ),
            'hide_admin_notices_keep' => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Keep on the page', 'seoprostack'),
                'options'     => array(__CLASS__, 'keep_options'),
            ),
            self::EXAMPLES => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Show example notices', 'seoprostack'),
                'description' => __('Add one notice of each kind to every admin screen, to see where they go. Reload the page after changing this.', 'seoprostack'),
            ),
        );
    }

    /**
     * Notice types that can stay on the page.
     *
     * @return array<string,string>
     */
    public static function keep_options() {
        return array(
            'error'   => __('Errors', 'seoprostack'),
            'warning' => __('Warnings, including the WordPress update message', 'seoprostack'),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin() || wp_doing_ajax()) {
            return;
        }
        add_action('current_screen', array(__CLASS__, 'setup'));
    }

    /**
     * Hook the page, except in the block editor.
     *
     * @param WP_Screen $screen Current screen.
     */
    public static function setup($screen) {
        if (($screen instanceof WP_Screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) || defined('IFRAME_REQUEST')) {
            return;
        }
        add_filter('admin_body_class', array(__CLASS__, 'body_class'));
        add_action('admin_head', array(__CLASS__, 'style'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'script'));
        // Late, so it sits left of the account menu and other plugins' items.
        add_action('admin_bar_menu', array(__CLASS__, 'bell'), 90);
        if (SEOProStack_Settings::get(self::EXAMPLES)) {
            add_action('admin_notices', array(__CLASS__, 'examples'));
        }
        // Last on the last notice hook: everything the notice hooks printed is on the page.
        add_action('all_admin_notices', array(__CLASS__, 'mark'), PHP_INT_MAX);
    }

    /**
     * Print one notice of each kind, from the "Show example notices" setting.
     *
     * Four go behind the bell; the last carries `sps-keep` and stays on the
     * page, as messages about what you just did do.
     */
    public static function examples() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $off = sprintf(
            /* translators: %s: link to the setting */
            __('Turn these off under %s.', 'seoprostack'),
            sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url(admin_url('options-general.php?page=seoprostack&tab=admin')),
                esc_html__('Hide admin notices', 'seoprostack')
            )
        );
        $notices = array(
            'notice-info is-dismissible' => __('Example information notice.', 'seoprostack'),
            'notice-success'             => __('Example success notice.', 'seoprostack'),
            'notice-warning'             => __('Example warning notice.', 'seoprostack'),
            'notice-error'               => __('Example error notice.', 'seoprostack'),
            'notice-info sps-keep'       => __('Example notice that stays on the page: add the class sps-keep to a notice to keep it here.', 'seoprostack'),
        );
        foreach ($notices as $class => $text) {
            printf(
                '<div class="notice %1$s sps-example"><p>%2$s %3$s</p></div>',
                esc_attr($class),
                esc_html($text),
                wp_kses($off, array('a' => array('href' => array())))
            );
        }
    }

    /**
     * Add the bell to the right of the admin bar.
     *
     * It stays hidden until the script has moved notices into the panel.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function bell($bar) {
        $bar->add_node(array(
            'id'     => self::NODE,
            'parent' => 'top-secondary',
            'title'  => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label" aria-hidden="true">0</span>',
            'href'   => '#',
            'meta'   => array(
                'class' => 'sps-notices-empty',
                'title' => __('Notices', 'seoprostack'),
            ),
        ));
    }

    /**
     * Mark the page until the script has moved the notices.
     *
     * @param string $classes Space-separated classes.
     * @return string
     */
    public static function body_class($classes) {
        return $classes . ' ' . self::LOADING . ' ';
    }

    /**
     * Selectors that pick the notices to move (mirrors common.js).
     *
     * `kinds` are kept wherever they are. `skip` adds inline notices, which
     * are kept only inside the page's own content: an inline notice printed
     * on `admin_notices` sits above the page, where it only gets in the way.
     *
     * @return array{notices:string,skip:string,kinds:string,nag:string}
     */
    private static function selectors() {
        $kinds = array('#message', '.settings-error', '.hidden', '.sps-keep');
        $keep  = array_flip((array) SEOProStack_Settings::get('hide_admin_notices_keep'));
        if (isset($keep['error'])) {
            $kinds[] = '.notice-error';
            $kinds[] = 'div.error';
        }
        if (isset($keep['warning'])) {
            $kinds[] = '.notice-warning';
            $kinds[] = '.update-nag';
        }
        return array(
            'notices' => self::NOTICES,
            'skip'    => implode(', ', array_merge(array('.inline', '.below-h2'), $kinds)),
            'kinds'   => implode(', ', $kinds),
            'nag'     => isset($keep['warning']) ? '' : '#wpbody-content > .update-nag',
        );
    }

    /**
     * Hide the notices until they are moved, and style the bell and panel.
     *
     * Hiding needs the "js" body class, which core sets as the page starts to
     * draw, so without JavaScript the notices stay on the page.
     */
    public static function style() {
        $s    = self::selectors();
        $not  = '';
        foreach (explode(', ', $s['skip']) as $selector) {
            $not .= ':not(' . $selector . ')';
        }
        $not_kind = '';
        foreach (explode(', ', $s['kinds']) as $selector) {
            $not_kind .= ':not(' . $selector . ')';
        }
        $loading = 'body.js.' . self::LOADING . ' ';
        $hide    = array($loading . '[' . self::MARK . '="move"]');
        foreach (explode(', ', $s['notices']) as $selector) {
            $hide[] = $loading . '#wpbody-content ' . $selector . $not;
            // Inline notices printed above the page.
            $hide[] = $loading . '#wpbody-content > ' . $selector . $not_kind;
        }
        if ('' !== $s['nag']) {
            $hide[] = $loading . $s['nag'];
        }
        ?>
        <style id="seoprostack-admin-notices">
            <?php echo implode(",\n", $hide); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed selectors. ?>,
            .sps-notice-away { display: none !important; }
            /* Bell: hidden while there is nothing to show, like core's Updates item. */
            #wpadminbar #wp-admin-bar-sps-notices.sps-notices-empty { display: none; }
            #wpadminbar #wp-admin-bar-sps-notices .ab-icon:before { content: "\f16d"; content: "\f16d" / ""; top: 2px; }
            /* Panel: drops down from the bell over the page, scrolling when long. */
            #sps-notices-wrap { position: fixed; top: 32px; right: 0; z-index: 99998; box-sizing: border-box; width: 640px; max-width: 100%; max-height: calc(100vh - 32px); overflow-y: auto; padding: 0 16px 16px; background: #f0f0f1; border: 1px solid #c3c4c7; border-top: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2); }
            #sps-notices-wrap.hidden { display: none; }
            #sps-notices-wrap:focus { outline: none; }
            #sps-notices-wrap > .notice, #sps-notices-wrap > .updated, #sps-notices-wrap > .error, #sps-notices-wrap > .update-nag, #sps-notices-wrap > [<?php echo self::MARK; // phpcs:ignore WordPress.Security.EscapeOutput -- constant. ?>] { display: block; margin: 12px 0 0; }
            #sps-notices-wrap > .update-nag { max-width: none; }
            @media screen and (max-width: 782px) {
                /* Core shows only its own items here; the bell joins them, the count in a bubble. */
                #wpadminbar li#wp-admin-bar-sps-notices { display: block; position: static; }
                #wpadminbar li#wp-admin-bar-sps-notices.sps-notices-empty { display: none; }
                #wpadminbar #wp-admin-bar-sps-notices > .ab-item { position: relative; }
                #wpadminbar #wp-admin-bar-sps-notices .ab-icon:before { display: block; font-size: 34px; height: 46px; line-height: 1.38235294; top: 0; }
                #wpadminbar #wp-admin-bar-sps-notices .ab-label { position: absolute; top: 5px; right: 4px; width: auto; height: auto; min-width: 18px; margin: 0; padding: 0 5px; overflow: visible; clip-path: none; box-sizing: border-box; border-radius: 9px; background: #d63638; color: #fff; font-size: 11px; line-height: 18px; text-align: center; }
                #sps-notices-wrap { top: 46px; max-height: calc(100vh - 46px); padding: 0 10px 10px; }
            }
        </style>
        <?php
    }

    /**
     * Mark what the notice hooks printed, before common.js moves it.
     *
     * Runs as the page is drawn, straight after the notice hooks, so the only
     * things in #wpbody-content are the Screen Options area and the hooks'
     * output. Notices are marked wherever they sit, including inside a hidden
     * wrapper (WooCommerce puts every notice in one on its own screens).
     * Plugin boxes without notice classes are marked when their class or id
     * says they are a notice or banner; other output, such as buttons a
     * plugin prints here, stays.
     */
    public static function mark() {
        $s    = self::selectors();
        $data = array(
            'notices' => $s['notices'] . ', .update-nag',
            'kinds'   => $s['kinds'],
            'mark'    => self::MARK,
        );
        $js = '(function (cfg) {
    var content = document.getElementById("wpbody-content");
    if (!content || !content.querySelector || !window.Element || !Element.prototype.matches) {
        return;
    }
    var banner = /(^|[\s_-])(notices?|nag|notification|alert|banner|promo|announcement)([\s_-]|$)/i;
    var skip = /^(SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT|META|BR|HR)$/;
    var walk = function (parent) {
        for (var el = parent.firstElementChild; el; el = el.nextElementSibling) {
            if (skip.test(el.tagName) || el.id === "screen-meta" || el.id === "screen-meta-links" || el.hasAttribute(cfg.mark)) {
                continue;
            }
            if (el.matches(cfg.notices)) {
                if (!el.hidden && !el.classList.contains("hidden")) {
                    el.setAttribute(cfg.mark, el.matches(cfg.kinds) ? "keep" : "move");
                }
            } else if (el.querySelector(cfg.notices)) {
                walk(el);
            } else if (banner.test((el.getAttribute("class") || "") + " " + el.id) && el.textContent.trim() && (parent !== content || el.getClientRects().length)) {
                el.setAttribute(cfg.mark, "move");
            }
        }
    };
    walk(content);
})(' . wp_json_encode($data) . ');';
        wp_print_inline_script_tag($js, array('id' => 'seoprostack-admin-notices-mark'));
    }

    /**
     * The script runs after common.js has moved the notices under the heading.
     * `label` names the bell for screen readers, with the count.
     */
    public static function script() {
        $s    = self::selectors();
        $file = 'admin/js/seoprostack-admin-notices.js';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $file, array('jquery', 'common'), $ver, true);
        wp_localize_script(self::HANDLE, 'seoprostackNotices', array(
            'notices' => $s['notices'],
            'skip'    => $s['skip'],
            'kinds'   => $s['kinds'],
            'nag'     => $s['nag'],
            'mark'    => self::MARK,
            'loading' => self::LOADING,
            'node'    => 'wp-admin-bar-' . self::NODE,
            /* translators: %d: number of notices */
            'label'   => __('Notices (%d)', 'seoprostack'),
            'panel'   => __('Notices', 'seoprostack'),
        ));
    }
}

