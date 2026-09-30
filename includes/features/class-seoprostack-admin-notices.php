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
 * Kept in place: messages about what you just did (#message, settings
 * errors), inline notices, hidden notices, notices added after the page has
 * loaded, and any types chosen in the settings. Until the move runs, the
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
     * @return array{notices:string,skip:string,nag:string}
     */
    private static function selectors() {
        $skip = array('.inline', '.below-h2', '#message', '.settings-error', '.hidden', '.sps-keep');
        $keep = array_flip((array) SEOProStack_Settings::get('hide_admin_notices_keep'));
        if (isset($keep['error'])) {
            $skip[] = '.notice-error';
            $skip[] = 'div.error';
        }
        if (isset($keep['warning'])) {
            $skip[] = '.notice-warning';
        }
        return array(
            'notices' => 'div.updated, div.error, div.notice',
            'skip'    => implode(', ', $skip),
            'nag'     => isset($keep['warning']) ? '' : '#wpbody-content > .update-nag',
        );
    }

    /**
     * Hide the notices until they are moved, and style the panel.
     */
    public static function style() {
        $s    = self::selectors();
        $not  = '';
        foreach (explode(', ', $s['skip']) as $selector) {
            $not .= ':not(' . $selector . ')';
        }
        $hide = array();
        foreach (explode(', ', $s['notices']) as $selector) {
            $hide[] = 'body.' . self::LOADING . ' #wpbody-content ' . $selector . $not;
        }
        if ('' !== $s['nag']) {
            $hide[] = 'body.' . self::LOADING . ' ' . $s['nag'];
        }
        ?>
        <style id="seoprostack-admin-notices">
            <?php echo implode(",\n", $hide); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed selectors. ?> { display: none !important; }
            #sps-notices-wrap { padding: 8px 20px 12px; }
            #sps-notices-wrap > .notice, #sps-notices-wrap > .updated, #sps-notices-wrap > .error, #sps-notices-wrap > .update-nag { display: block; margin: 12px 0 0; }
            #sps-notices-wrap > .update-nag { max-width: none; }
        </style>
        <?php
    }

    /**
     * The script runs after common.js has moved the notices under the heading.
     */
    public static function script() {
        $s    = self::selectors();
        $data = array(
            'notices' => $s['notices'],
            'skip'    => $s['skip'],
            'nag'     => $s['nag'],
            'loading' => self::LOADING,
            /* translators: %d: number of notices */
            'label'   => __('Notices (%d)', 'seoprostack'),
            'panel'   => __('Notices', 'seoprostack'),
        );
        wp_register_script('seoprostack-admin-notices', false, array('common'), SEOPROSTACK_VERSION, true);
        wp_enqueue_script('seoprostack-admin-notices');
        wp_add_inline_script('seoprostack-admin-notices', '(function ($, cfg) {
    $(function () {
        var $body = $(document.body);
        try {
            var $content = $("#wpbody-content");
            var $notices = $content.find(cfg.notices).not(cfg.skip)
                .filter(function () { return this.style.display !== "none"; });
            if (cfg.nag) {
                $notices = $notices.add($(cfg.nag));
            }
            $notices = $notices.not("#screen-meta *");
            if (!$notices.length) {
                return;
            }

            var $meta = $("#screen-meta");
            var $links = $("#screen-meta-links");
            if (!$links.length) {
                $links = $("<div id=\"screen-meta-links\"></div>").insertAfter($meta);
            }
            var $panel = $("<div id=\"sps-notices-wrap\" class=\"hidden\" tabindex=\"-1\"></div>").attr("aria-label", cfg.panel).appendTo($meta);
            var buttonClass = $links.find(".show-settings").first().attr("class") || "button show-settings";
            var $button = $("<button type=\"button\" id=\"sps-notices-link\" aria-controls=\"sps-notices-wrap\" aria-expanded=\"false\"></button>")
                .attr("class", buttonClass.replace(/\bscreen-meta-active\b/, ""));
            var $wrap = $("<div id=\"sps-notices-link-wrap\" class=\"hide-if-no-js screen-meta-toggle\"></div>").append($button).appendTo($links);

            $notices.appendTo($panel);

            var count = function () {
                var n = $panel.children().length;
                $button.text(cfg.label.replace("%d", n));
                if (!n) {
                    if ($panel.is(":visible") && window.screenMeta) {
                        window.screenMeta.close($panel, $button);
                    }
                    $wrap.remove();
                }
            };
            count();
            if (window.MutationObserver) {
                new MutationObserver(count).observe($panel[0], { childList: true });
            }

            $button.on("click", function () {
                if (window.screenMeta) {
                    window.screenMeta.toggleEvent.call(this);
                } else {
                    $meta.toggle();
                    $panel.toggleClass("hidden");
                }
            });
        } finally {
            $body.removeClass(cfg.loading);
        }
    });
})(jQuery, ' . wp_json_encode($data) . ');');
    }
}
