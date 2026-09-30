<?php
/**
 * Move admin notices behind a "Notices" button.
 *
 * WordPress's common.js moves every notice (div.notice, .updated, .error
 * that is not .inline or .below-h2) under the page heading when the page is
 * ready. This feature takes the same set, plus the core update nag, and puts
 * it in a panel opened from a "Notices (n)" button next to Screen Options and
 * Help, using core's own screen meta toggle.
 *
 * Everything printed on the notice hooks is caught too, whatever its markup
 * or wrapper (WooCommerce wraps all notices in a hidden list): markers around
 * the hooks' output let a small inline script tag it before common.js runs.
 * Notices that scripts add after the page has loaded are caught until the
 * person first clicks or types; ones drawn by React or Vue stay in place,
 * hidden, and a copy in the panel passes clicks back to them.
 *
 * The button sits with Screen Options and Help. On pages without them it has
 * a row of its own, so it never covers the page, and it stays visible where
 * a page hides that area behind its own top bar (WooCommerce).
 *
 * Kept in place: messages about what you just did (#message, settings
 * errors), inline notices inside the page's content (inline notices printed
 * above the page are moved), hidden notices, notices added after a click or
 * key press, and any types chosen in the settings. Until the move runs, the
 * notices are hidden with CSS, so they do not flash or push the page down.
 * The block editor has its own notices and is left alone.
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
                'description' => __('Move plugin and theme notices into a “Notices” button next to Screen Options, with a count. Messages about what you just did, such as “Settings saved”, stay on the page.', 'seoprostack'),
                'replaces'    => array('hide-admin-notices' => 'Hide Admin Notices'),
            ),
            'hide_admin_notices_keep' => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Keep on the page', 'seoprostack'),
                'options'     => array(__CLASS__, 'keep_options'),
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
        // Last on the last notice hook: everything the notice hooks printed is on the page.
        add_action('all_admin_notices', array(__CLASS__, 'mark'), PHP_INT_MAX);
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
     * Hide the notices until they are moved, and style the button and panel.
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
        $loading = 'body.' . self::LOADING . ' ';
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
            #sps-notices-link-wrap { float: left; float: inline-start; margin: 0; margin-inline-start: 6px; }
            /* No Screen Options or Help, or the page hid them: a row of its own, so the button never covers the page. */
            #screen-meta-links.sps-notices-only, #screen-meta-links.sps-has-notices[style*="none"] { display: flow-root !important; float: none; }
            #screen-meta-links.sps-notices-only #sps-notices-link-wrap, #screen-meta-links.sps-has-notices[style*="none"] #sps-notices-link-wrap { float: right; float: inline-end; }
            #screen-meta-links.sps-has-notices[style*="none"] > :not(#sps-notices-link-wrap) { display: none !important; }
            #sps-notices-wrap { padding: 8px 20px 12px; }
            #sps-notices-wrap > .notice, #sps-notices-wrap > .updated, #sps-notices-wrap > .error, #sps-notices-wrap > .update-nag, #sps-notices-wrap > [<?php echo self::MARK; // phpcs:ignore WordPress.Security.EscapeOutput -- constant. ?>] { display: block; margin: 12px 0 0; }
            #sps-notices-wrap > .update-nag { max-width: none; }
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
            /* translators: %d: number of notices */
            'label'   => __('Notices (%d)', 'seoprostack'),
            'panel'   => __('Notices', 'seoprostack'),
        ));
    }
}

