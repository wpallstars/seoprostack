<?php
/**
 * Shareable preview links for drafts.
 *
 * Tick "Share a preview link" in the editor to get a URL that shows the
 * draft (or pending, scheduled or staged version) to anyone, without an
 * account. Links expire, can be turned off at any time and stop working
 * when the post is published. Preview pages are not cached or indexed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Preview_Links extends SEOProStack_Feature {

    const KEY = 'preview_links';

    /** Post meta: array{token:string,expires:int}. */
    const META = '_seoprostack_preview';

    /** Query argument carrying the token. */
    const QUERY_ARG = 'sps_preview';

    /** AJAX action. */
    const AJAX = 'seoprostack_preview_link';

    /** Statuses that can be shared. */
    const STATUSES = array('draft', 'pending', 'future');

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
                'tab'         => 'content',
                'label'       => __('Shareable preview links', 'seoprostack'),
                'description' => __('Share a draft with people who do not have an account. Turn the link on in the editor; it stops working when it expires, when you turn it off, or when the post is published.', 'seoprostack'),
                'replaces'    => array(
                    'post-draft-preview'  => 'Post Draft Preview',
                    'public-post-preview' => 'Public Post Preview',
                ),
            ),
            'preview_links_days' => array(
                'type'        => 'int',
                'default'     => 7,
                'min'         => 1,
                'max'         => 90,
                'unit'        => __('days', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Links expire after', 'seoprostack'),
                'description' => __('Turning a link off and on again restarts the period.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_filter('posts_results', array(__CLASS__, 'posts_results'), 10, 2);
        add_action('transition_post_status', array(__CLASS__, 'expire_on_publish'), 10, 3);
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        add_action('post_submitbox_misc_actions', array(__CLASS__, 'submitbox'));
    }

    /* --------------------------------------------------------------------- */
    /* Front end                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Register the token query var.
     *
     * @param string[] $vars Public query vars.
     * @return string[]
     */
    public static function query_vars($vars) {
        $vars[] = self::QUERY_ARG;
        return $vars;
    }

    /**
     * Show a shared draft to visitors with a valid token.
     *
     * Runs before WP_Query hides unpublished single posts, so presenting the
     * post as published here is what makes it visible.
     *
     * @param WP_Post[] $posts Query results.
     * @param WP_Query  $query Query.
     * @return WP_Post[]
     */
    public static function posts_results($posts, $query) {
        if (!$query->is_main_query() || 1 !== count($posts) || !$query->is_singular()) {
            return $posts;
        }
        $token = (string) $query->get(self::QUERY_ARG);
        if ('' === $token || !self::token_valid($posts[0], $token)) {
            return $posts;
        }

        $posts[0]->post_status = 'publish';

        // Page caches (LiteSpeed, WP Rocket, W3TC...) must not keep a copy.
        // Names are set by the cache plugins, so they cannot carry our prefix.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared page-cache flag.
        }
        do_action('litespeed_control_set_nocache', 'seoprostack preview link'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.

        // SEO plugins that print their own robots tag see a published post.
        add_filter('wp_robots', 'wp_robots_no_robots');
        add_filter('rank_math/frontend/robots', array(__CLASS__, 'rank_math_robots'));
        add_filter('wpseo_robots', array(__CLASS__, 'noindex_string'));
        add_filter('comments_open', '__return_false');
        add_filter('pings_open', '__return_false');
        add_action('send_headers', 'nocache_headers');
        add_filter('wp_headers', array(__CLASS__, 'headers'));
        return $posts;
    }

    /**
     * Rank Math robots tag for preview pages.
     *
     * @param array $robots Directives keyed by name.
     * @return array
     */
    public static function rank_math_robots($robots) {
        $robots           = is_array($robots) ? $robots : array();
        $robots['index']  = 'noindex';
        $robots['follow'] = 'nofollow';
        return $robots;
    }

    /**
     * Robots tag string for SEO plugins that take one (Yoast SEO).
     *
     * @return string
     */
    public static function noindex_string() {
        return 'noindex, nofollow';
    }

    /**
     * Keep preview pages out of caches and referrers.
     *
     * @param array $headers Response headers.
     * @return array
     */
    public static function headers($headers) {
        $headers['X-Robots-Tag']    = 'noindex, nofollow';
        $headers['Referrer-Policy'] = 'no-referrer';
        return array_merge($headers, wp_get_nocache_headers());
    }

    /**
     * Whether a token opens a post.
     *
     * @param WP_Post $post  Post.
     * @param string  $token Token from the URL.
     * @return bool
     */
    public static function token_valid(WP_Post $post, $token) {
        if (!in_array($post->post_status, self::STATUSES, true)) {
            return false;
        }
        $link = get_post_meta($post->ID, self::META, true);
        return is_array($link) && !empty($link['token']) && !empty($link['expires'])
            && time() < (int) $link['expires'] && hash_equals((string) $link['token'], $token);
    }

    /**
     * Links stop working once a post is published.
     *
     * @param string  $new_status New status.
     * @param string  $old_status Old status.
     * @param WP_Post $post       Post.
     */
    public static function expire_on_publish($new_status, $old_status, $post) {
        if ('publish' === $new_status && $new_status !== $old_status) {
            delete_post_meta($post->ID, self::META);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Editor                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Current shareable URL for a post, or '' when off or expired.
     *
     * @param WP_Post $post Post.
     * @return string
     */
    public static function url(WP_Post $post) {
        $link = get_post_meta($post->ID, self::META, true);
        if (!is_array($link) || empty($link['token']) || time() >= (int) (isset($link['expires']) ? $link['expires'] : 0)) {
            return '';
        }
        return add_query_arg(array('preview' => 'true', self::QUERY_ARG => $link['token']), get_permalink($post));
    }

    /**
     * Human "expires in" text.
     *
     * @param WP_Post $post Post.
     * @return string
     */
    private static function expiry_text(WP_Post $post) {
        $link = get_post_meta($post->ID, self::META, true);
        if (!is_array($link) || empty($link['expires'])) {
            return '';
        }
        /* translators: %s: time until expiry, e.g. "7 days" */
        return sprintf(__('Expires in %s.', 'seoprostack'), human_time_diff(time(), (int) $link['expires']));
    }

    /**
     * Whether the current user can share this post.
     *
     * @param WP_Post|null $post Post.
     * @return bool
     */
    private static function can_share($post) {
        return $post instanceof WP_Post && is_post_type_viewable($post->post_type) && current_user_can('edit_post', $post->ID);
    }

    /**
     * Editor config shared by both editors.
     *
     * @param WP_Post $post Post.
     * @return array
     */
    private static function editor_config(WP_Post $post) {
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'post'    => $post->ID,
            'nonce'   => wp_create_nonce(self::AJAX . '_' . $post->ID),
            'url'     => self::url($post),
            'expires' => self::expiry_text($post),
            'i18n'    => array(
                'label'  => __('Share a preview link', 'seoprostack'),
                'copy'   => __('Copy link', 'seoprostack'),
                'copied' => __('Copied', 'seoprostack'),
                'failed' => __('Could not update the preview link. Please try again.', 'seoprostack'),
                'save'   => __('Save the draft first to share it.', 'seoprostack'),
            ),
        );
    }

    /**
     * Block editor panel.
     */
    public static function block_editor() {
        $post = get_post();
        if (!self::can_share($post)) {
            return;
        }
        wp_register_script('seoprostack-preview-links', false, array('wp-plugins', 'wp-element', 'wp-components', 'wp-editor', 'wp-data'), SEOPROSTACK_VERSION, true);
        wp_enqueue_script('seoprostack-preview-links');
        wp_add_inline_script('seoprostack-preview-links', sprintf(
            '(function (wp, cfg) {
                var el = wp.element.createElement, useState = wp.element.useState;
                var Info = (wp.editor && wp.editor.PluginPostStatusInfo) || (wp.editPost && wp.editPost.PluginPostStatusInfo);
                var C = wp.components;
                if (!Info) { return; }
                function request(op) {
                    var body = new FormData();
                    body.append("action", cfg.action);
                    body.append("post", cfg.post);
                    body.append("nonce", cfg.nonce);
                    body.append("op", op);
                    return fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" }).then(function (r) { return r.json(); });
                }
                function Panel() {
                    var state = useState({ url: cfg.url, expires: cfg.expires }), link = state[0], setLink = state[1];
                    var b = useState(false), busy = b[0], setBusy = b[1];
                    var c = useState(false), copied = c[0], setCopied = c[1];
                    var e = useState(""), error = e[0], setError = e[1];
                    var info = wp.data.useSelect(function (select) {
                        var ed = select("core/editor");
                        return { status: ed.getEditedPostAttribute("status"), isNew: ed.isEditedPostNew() };
                    }, []);
                    if (["draft", "pending", "future"].indexOf(info.status) === -1) { return null; }
                    function toggle(on) {
                        setBusy(true); setError("");
                        request(on ? "enable" : "disable").then(function (res) {
                            if (!res || !res.success) { throw new Error(); }
                            setLink({ url: res.data.url, expires: res.data.expires });
                        }).catch(function () { setError(cfg.i18n.failed); }).then(function () { setBusy(false); });
                    }
                    function copy() {
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(link.url).then(function () { setCopied(true); setTimeout(function () { setCopied(false); }, 2000); });
                        }
                    }
                    return el(Info, null, el("div", { style: { width: "100%%" } },
                        el(C.CheckboxControl, { label: cfg.i18n.label, checked: !!link.url, disabled: busy || info.isNew, help: info.isNew ? cfg.i18n.save : "", onChange: toggle, __nextHasNoMarginBottom: true }),
                        link.url ? el("div", { style: { marginTop: "8px" } },
                            el(C.TextControl, { value: link.url, readOnly: true, label: cfg.i18n.label, hideLabelFromVision: true, onFocus: function (ev) { ev.target.select(); }, onChange: function () {}, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true }),
                            el("div", { style: { display: "flex", gap: "8px", alignItems: "center", marginTop: "6px" } },
                                el(C.Button, { variant: "secondary", size: "compact", onClick: copy }, copied ? cfg.i18n.copied : cfg.i18n.copy),
                                el("span", { style: { color: "#757575" } }, link.expires))) : null,
                        error ? el("p", { role: "alert", style: { color: "#cc1818" } }, error) : null));
                }
                wp.plugins.registerPlugin("seoprostack-preview-links", { render: Panel });
            })(window.wp, %s);',
            wp_json_encode(self::editor_config($post))
        ));
    }

    /**
     * Classic editor control in the Publish box.
     *
     * @param WP_Post $post Post.
     */
    public static function submitbox($post) {
        if (!self::can_share($post) || !in_array($post->post_status, self::STATUSES, true)) {
            return;
        }
        $cfg = self::editor_config($post);
        ?>
        <div class="misc-pub-section seoprostack-preview-link" data-config="<?php echo esc_attr((string) wp_json_encode($cfg)); ?>">
            <label><input type="checkbox" class="seoprostack-preview-toggle" <?php checked('' !== $cfg['url']); ?> /> <?php echo esc_html($cfg['i18n']['label']); ?></label>
            <p class="seoprostack-preview-url"<?php echo '' === $cfg['url'] ? ' hidden' : ''; ?>>
                <input type="text" class="widefat code" readonly value="<?php echo esc_attr($cfg['url']); ?>" aria-label="<?php echo esc_attr($cfg['i18n']['label']); ?>" onfocus="this.select()" />
                <span class="description"><?php echo esc_html($cfg['expires']); ?></span>
            </p>
        </div>
        <script>
        (function ($) {
            var $box = $('.seoprostack-preview-link'), cfg = $box.data('config');
            $box.on('change', '.seoprostack-preview-toggle', function () {
                var $toggle = $(this).prop('disabled', true);
                $.post(cfg.ajaxUrl, { action: cfg.action, post: cfg.post, nonce: cfg.nonce, op: this.checked ? 'enable' : 'disable' })
                    .done(function (res) {
                        if (!res || !res.success) { return this.fail(); }
                        $box.find('.seoprostack-preview-url').prop('hidden', !res.data.url)
                            .find('input').val(res.data.url).end().find('.description').text(res.data.expires);
                    })
                    .fail(function () { $toggle.prop('checked', !$toggle.prop('checked')); window.alert(cfg.i18n.failed); })
                    .always(function () { $toggle.prop('disabled', false); });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: turn a post's link on or off.
     */
    public static function ajax() {
        $post_id = isset($_POST['post']) ? absint(wp_unslash($_POST['post'])) : 0;
        check_ajax_referer(self::AJAX . '_' . $post_id, 'nonce');

        $post = get_post($post_id);
        if (!self::can_share($post)) {
            wp_send_json_error(array('message' => __('You cannot share this post.', 'seoprostack')), 403);
        }

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        if ('enable' === $op) {
            if (!in_array($post->post_status, self::STATUSES, true)) {
                wp_send_json_error(array('message' => __('Only unpublished posts can be shared.', 'seoprostack')), 400);
            }
            $link  = get_post_meta($post_id, self::META, true);
            $valid = is_array($link) && !empty($link['token']) && time() < (int) (isset($link['expires']) ? $link['expires'] : 0);
            update_post_meta($post_id, self::META, array(
                // Keep a live token so links already sent keep working.
                'token'   => $valid ? (string) $link['token'] : wp_generate_password(24, false, false),
                'expires' => time() + (int) SEOProStack_Settings::get('preview_links_days') * DAY_IN_SECONDS,
            ));
        } else {
            delete_post_meta($post_id, self::META);
        }

        wp_send_json_success(array(
            'url'     => self::url($post),
            'expires' => self::expiry_text($post),
        ));
    }
}
