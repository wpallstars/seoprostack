<?php
/**
 * Move admin notices behind a bell in the admin bar.
 *
 * WordPress's common.js moves every notice (div.notice, .updated, .error
 * that is not .inline or .below-h2) under the page heading when the page is
 * ready. This feature takes the same set, plus the core update nag, and puts
 * it in a panel that drops down from a bell at the right of the admin bar.
 * The admin bar is the one place every admin screen leaves alone, so the
 * bell never sits on a plugin's own header. The bell is on every screen,
 * with a dot while there are notices, so nothing on the bar moves when the
 * notices are counted.
 *
 * With "Load plugins only where needed", a screen may skip the plugin that
 * prints a notice. Notices those plugins print on screens that load every
 * plugin are kept for each person (see capture(); only notices, see
 * notices()), and shown behind the
 * bell on screens that skip them, until they stop printing them, they are
 * dismissed, or 12 hours pass (links in them carry nonces).
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

    /** User option (per site) with notices kept from skipped plugins. */
    const STORE = 'seoprostack_stored_notices';

    /** Attribute holding a kept notice's hash. */
    const STORED = 'data-sps-stored';

    /** AJAX action and nonce for dismissing a kept notice. */
    const FORGET = 'seoprostack_forget_notice';

    /** Seconds a kept notice is shown: nonces in its links last at least this long. */
    const STORE_LIFE = 43200;

    /** Seconds a dismissed kept notice stays dismissed. */
    const DISMISS_LIFE = 2592000;

    /** Most notices kept per person, and largest notice kept, in bytes. */
    const STORE_MAX  = 40;
    const STORE_SIZE = 30000;

    /**
     * Output of the notice hooks on this request, by plugin.
     *
     * @var array<int,array{0:string,1:string}>
     */
    private static $printed = array();

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
                'description' => __('Move plugin and theme notices behind a bell in the admin bar, with a dot while there are notices. Messages about what you just did, such as “Settings saved”, stay on the page.', 'seoprostack'),
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
        if (!self::enabled() || !is_admin()) {
            return;
        }
        if (wp_doing_ajax()) {
            add_action('wp_ajax_' . self::FORGET, array(__CLASS__, 'forget'));
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
        add_action('admin_bar_menu', array(__CLASS__, 'bell'), 90);
        // Left of the Plugins menu, with other plugins' items to its left.
        SEOProStack_Admin_Bar::pin(self::NODE, 1);
        if (SEOProStack_Settings::get(self::EXAMPLES)) {
            add_action('admin_notices', array(__CLASS__, 'examples'));
        }
        // Notices of plugins that "Load plugins only where needed" skips.
        $loading = self::loading();
        if ('filter' === $loading) {
            add_action('admin_notices', array(__CLASS__, 'replay'), PHP_INT_MAX);
        } elseif ('full' === $loading) {
            // admin_notices runs straight after in_admin_header.
            add_action('in_admin_header', array(__CLASS__, 'capture'), PHP_INT_MAX);
            add_action('all_admin_notices', array(__CLASS__, 'keep'), PHP_INT_MAX);
        }
        // Last on the last notice hook: everything the notice hooks printed is on the page.
        add_action('all_admin_notices', array(__CLASS__, 'mark'), PHP_INT_MAX);
    }

    /* --------------------------------------------------------------------- */
    /* Notices of plugins skipped by "Load plugins only where needed"         */
    /* --------------------------------------------------------------------- */

    /**
     * How "Load plugins only where needed" treats this request: 'filter'
     * (some plugins skipped), 'full' (every plugin loaded) or '' (feature off).
     *
     * @return string
     */
    private static function loading() {
        if (!class_exists('SEOProStack_Plugin_Loading', false) || !SEOProStack_Plugin_Loading::enabled()) {
            return '';
        }
        $state = SEOProStack_Plugin_Loader::state();
        return 'filter' === $state['mode'] ? 'filter' : 'full';
    }

    /**
     * Plugins that some screens skip: the ticked ones, and plugins that need
     * them, less those that always load.
     *
     * @return array<string,true>
     */
    private static function skippable() {
        $map    = get_option(SEOProStack_Plugin_Loader::MAP, array());
        $map    = is_array($map) ? $map : array();
        $deps   = isset($map['deps']) ? (array) $map['deps'] : array();
        $always = isset($map['always']) ? (array) $map['always'] : array();
        $list   = array_diff((array) SEOProStack_Settings::get(SEOProStack_Plugin_Loader::LIST_KEY), $always);
        $set    = array_fill_keys(array_filter($list, 'is_string'), true);
        do {
            $added = false;
            foreach ($deps as $file => $needs) {
                if (!isset($set[$file]) && !in_array($file, $always, true) && array_intersect((array) $needs, array_keys($set))) {
                    $set[$file] = true;
                    $added      = true;
                }
            }
        } while ($added);
        unset($set[plugin_basename(SEOPROSTACK_FILE)]);
        return $set;
    }

    /**
     * On a screen with every plugin, record what each skippable plugin
     * prints on the notice hooks: each of its callbacks is wrapped, in
     * place, by one that passes its output through unchanged. The key stays
     * the same, so remove_action() and has_action() still find it.
     */
    public static function capture() {
        global $wp_filter;
        $watched = self::skippable();
        if (!$watched) {
            return;
        }
        foreach (array('admin_notices', 'all_admin_notices') as $name) {
            if (empty($wp_filter[$name]) || !($wp_filter[$name] instanceof WP_Hook)) {
                continue;
            }
            foreach ($wp_filter[$name]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $id => $callback) {
                    $plugin = SEOProStack_Plugin_Loader::plugin_for_callback($callback['function']);
                    if ('' !== $plugin && isset($watched[$plugin])) {
                        $wp_filter[$name]->callbacks[$priority][$id]['function'] = self::recorder($callback['function'], $plugin);
                    }
                }
            }
        }
    }

    /**
     * A callback that runs another and notes what it printed.
     *
     * @param callable $callback Plugin's callback.
     * @param string   $plugin   Plugin file.
     * @return Closure
     */
    private static function recorder($callback, $plugin) {
        return function (...$args) use ($callback, $plugin) {
            $level = ob_get_level();
            ob_start();
            try {
                return call_user_func_array($callback, $args);
            } finally {
                // Close buffers the callback left open, so its output is all here.
                while (ob_get_level() > $level + 1) {
                    ob_end_flush();
                }
                $html = ob_get_level() > $level ? (string) ob_get_clean() : '';
                self::$printed[] = array($plugin, $html);
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- the plugin's own output, passed on unchanged.
            }
        };
    }

    /**
     * After the notice hooks on a screen with every plugin: keep what the
     * skippable plugins printed. Notices kept from this screen before and
     * not printed now are dropped; ones from other screens stay until they
     * expire.
     */
    public static function keep() {
        $screen = get_current_screen();
        $where  = $screen ? (string) $screen->id : '';
        $before = self::stored();
        $store  = $before;
        $now    = time();
        foreach ($store['notices'] as $hash => $notice) {
            if ($notice['screen'] === $where) {
                unset($store['notices'][$hash]);
            }
        }
        foreach (self::$printed as $printed) {
            list($plugin, $output) = $printed;
            if (strlen($output) > 4 * self::STORE_SIZE) {
                continue;
            }
            foreach (self::notices($output) as $html) {
                if (strlen($html) > self::STORE_SIZE) {
                    continue;
                }
                $hash = self::hash($plugin, $html);
                if (isset($store['dismissed'][$hash])) {
                    continue;
                }
                $old  = isset($before['notices'][$hash]) ? $before['notices'][$hash] : null;
                // Only rewrite the option for a new notice, or once an hour.
                $time = $old && $old['html'] === $html && $old['screen'] === $where && $now - $old['time'] < HOUR_IN_SECONDS ? $old['time'] : $now;
                $store['notices'][$hash] = array(
                    'plugin' => $plugin,
                    'html'   => $html,
                    'screen' => $where,
                    'time'   => $time,
                );
            }
        }
        if ($store !== $before) {
            self::save($store);
        }
    }

    /**
     * The notices in what a plugin printed on the notice hooks, each as
     * markup of its own. They are picked as mark() picks them on the page:
     * visible notice boxes, wherever they sit, and boxes with text whose
     * class or id says they are a notice or banner.
     *
     * Anything else belongs to the screen it was printed on and is not kept:
     * buttons or forms a plugin prints above its own list (Kadence Blocks'
     * Export All and Import), hidden notices that its scripts show there,
     * and half a wrapper opened on one hook and closed on another
     * (WooCommerce's notice list), which would break the page elsewhere.
     *
     * @param string $output What the plugin printed.
     * @return string[]
     */
    private static function notices($output) {
        $output = trim((string) $output);
        if ('' === $output || !class_exists('DOMDocument')) {
            return array();
        }
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><html><body><div id="sps-notices-root">' . $output . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xpath = new DOMXPath($doc);
        $root  = $xpath->query('//div[@id="sps-notices-root"]')->item(0);
        $found = array();
        if ($root) {
            self::find_notices($root, $xpath, $found);
        }
        return $found;
    }

    /**
     * Add the notices among an element's children to $found, looking inside
     * children that hold notices (see notices()).
     *
     * @param DOMNode   $parent Element to look in.
     * @param DOMXPath  $xpath  Query object for its document.
     * @param string[]  $found  Markup of the notices found so far.
     */
    private static function find_notices(DOMNode $parent, DOMXPath $xpath, array &$found) {
        $classes = function ($names) {
            $test = array();
            foreach ($names as $name) {
                $test[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")';
            }
            return implode(' or ', $test);
        };
        $inside = './/div[' . $classes(array('updated', 'error', 'notice')) . '] | .//*[' . $classes(array('update-nag')) . ']';
        foreach ($parent->childNodes as $el) {
            if (!($el instanceof DOMElement) || preg_match('/^(script|style|link|template|noscript|meta|br|hr)$/i', $el->tagName)) {
                continue;
            }
            $class  = ' ' . preg_replace('/\s+/', ' ', $el->getAttribute('class')) . ' ';
            $notice = ('div' === strtolower($el->tagName) && preg_match('/ (updated|error|notice) /', $class)) || false !== strpos($class, ' update-nag ');
            $hidden = $el->hasAttribute('hidden') || false !== strpos($class, ' hidden ') || preg_match('/display\s*:\s*none/i', $el->getAttribute('style'));
            if ($notice) {
                if (!$hidden) {
                    $found[] = trim((string) $el->ownerDocument->saveHTML($el));
                }
            } elseif ($xpath->query($inside, $el)->length) {
                self::find_notices($el, $xpath, $found);
            } elseif (!$hidden && '' !== trim($el->textContent) && preg_match('/(^|[\s_-])(notices?|nag|notification|alert|banner|promo|announcement)([\s_-]|$)/i', $el->getAttribute('class') . ' ' . $el->getAttribute('id'))) {
                $found[] = trim((string) $el->ownerDocument->saveHTML($el));
            }
        }
    }

    /**
     * On a screen that skips plugins, print their kept notices. They are
     * marked and moved behind the bell like any other (see mark()).
     *
     * Each is checked again with notices(), which drops anything kept before
     * that check existed.
     */
    public static function replay() {
        $state   = SEOProStack_Plugin_Loader::state();
        $skipped = array_flip($state['skipped']);
        foreach (self::stored()['notices'] as $hash => $notice) {
            if (!isset($skipped[$notice['plugin']])) {
                continue;
            }
            $html = implode("\n", self::notices($notice['html']));
            if ('' !== $html) {
                printf(
                    '<div class="sps-stored-notice" %1$s="%2$s">%3$s</div>',
                    self::STORED, // phpcs:ignore WordPress.Security.EscapeOutput -- constant.
                    esc_attr($hash),
                    $html // phpcs:ignore WordPress.Security.EscapeOutput -- notices the plugin printed for this person on another screen.
                );
            }
        }
    }

    /**
     * AJAX: a kept notice was dismissed. Stop showing it on screens that
     * skip its plugin. Where its plugin loads, the plugin decides.
     */
    public static function forget() {
        check_ajax_referer(self::FORGET, 'nonce');
        $hash = isset($_POST['hash']) ? sanitize_key(wp_unslash($_POST['hash'])) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
            wp_send_json_error(null, 400);
        }
        $store = self::stored();
        unset($store['notices'][$hash]);
        $store['dismissed'][$hash] = time();
        self::save($store);
        wp_send_json_success();
    }

    /**
     * A notice's identity: its plugin and its words, so nonces and other
     * changing attributes do not make it new.
     *
     * @param string $plugin Plugin file.
     * @param string $html   Notice markup.
     * @return string
     */
    private static function hash($plugin, $html) {
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($html)));
        return md5($plugin . "\n" . ('' === $text ? $html : $text));
    }

    /**
     * This person's kept notices, without expired ones.
     *
     * @return array{notices: array<string,array{plugin:string,html:string,screen:string,time:int}>, dismissed: array<string,int>}
     */
    private static function stored() {
        $store = get_user_option(self::STORE);
        $store = is_array($store) ? $store : array();
        $now   = time();
        $clean = array('notices' => array(), 'dismissed' => array());
        foreach (isset($store['notices']) ? (array) $store['notices'] : array() as $hash => $notice) {
            if (is_array($notice) && isset($notice['plugin'], $notice['html'], $notice['screen'], $notice['time']) && $now - (int) $notice['time'] < self::STORE_LIFE) {
                $clean['notices'][(string) $hash] = $notice;
            }
        }
        foreach (isset($store['dismissed']) ? (array) $store['dismissed'] : array() as $hash => $time) {
            if ($now - (int) $time < self::DISMISS_LIFE) {
                $clean['dismissed'][(string) $hash] = (int) $time;
            }
        }
        return $clean;
    }

    /**
     * Save this person's kept notices, newest first, within the limits.
     *
     * @param array $store As stored() returns.
     */
    private static function save(array $store) {
        $user = get_current_user_id();
        if (!$user) {
            return;
        }
        uasort($store['notices'], function ($a, $b) {
            return $b['time'] - $a['time'];
        });
        arsort($store['dismissed']);
        $store['notices']   = array_slice($store['notices'], 0, self::STORE_MAX, true);
        $store['dismissed'] = array_slice($store['dismissed'], 0, 5 * self::STORE_MAX, true);
        if (!$store['notices'] && !$store['dismissed']) {
            delete_user_option($user, self::STORE);
        } else {
            update_user_option($user, self::STORE, $store);
        }
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
     * It is on every screen, so nothing on the bar moves when the notices
     * are counted; a dot on it shows there are notices. A count would change
     * the bell's width once counted. No tooltip, like core's menus: it would
     * cover the open panel. Screen readers get the name from the hidden
     * text, and the script's aria-label adds the count.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function bell($bar) {
        $bar->add_node(array(
            'id'     => self::NODE,
            'parent' => 'top-secondary',
            'title'  => '<span class="ab-icon" aria-hidden="true"><span class="sps-notices-dot"></span></span><span class="screen-reader-text">' . esc_html__('Notices', 'seoprostack') . '</span>',
            'href'   => '#',
            'meta'   => array('class' => 'sps-notices-empty'),
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
            /* Bell: on every screen, with a dot in the bar's text colour while there are notices. */
            #wpadminbar #wp-admin-bar-sps-notices .ab-icon { margin-right: 0; }
            #wpadminbar #wp-admin-bar-sps-notices .ab-icon:before { content: "\f16d"; content: "\f16d" / ""; top: 2px; }
            #wpadminbar #wp-admin-bar-sps-notices .sps-notices-dot { position: absolute; top: 4px; right: -3px; width: 7px; height: 7px; border-radius: 50%; background: currentColor; pointer-events: none; }
            #wpadminbar #wp-admin-bar-sps-notices.sps-notices-empty .sps-notices-dot { display: none; }
            #sps-notices-wrap > .sps-notices-none { margin: 16px 0 0; }
            #sps-notices-wrap > .sps-notices-none:not(:only-child) { display: none; }
            /* Panel: drops down from the bell over the page, scrolling when long. */
            #sps-notices-wrap { position: fixed; top: 32px; right: 0; z-index: 99998; box-sizing: border-box; width: 640px; max-width: 100%; max-height: calc(100vh - 32px); overflow-y: auto; padding: 0 16px 16px; background: #f0f0f1; border: 1px solid #c3c4c7; border-top: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2); }
            #sps-notices-wrap.hidden { display: none; }
            #sps-notices-wrap:focus { outline: none; }
            #sps-notices-wrap > .notice, #sps-notices-wrap > .updated, #sps-notices-wrap > .error, #sps-notices-wrap > .update-nag, #sps-notices-wrap > [<?php echo self::MARK; // phpcs:ignore WordPress.Security.EscapeOutput -- constant. ?>] { display: block; margin: 12px 0 0; }
            #sps-notices-wrap > .update-nag { max-width: none; }
            @media screen and (max-width: 782px) {
                /* Core shows only its own items here; the bell joins them. */
                #wpadminbar li#wp-admin-bar-sps-notices { display: block; position: static; }
                #wpadminbar #wp-admin-bar-sps-notices .ab-icon:before { display: block; font-size: 34px; height: 46px; line-height: 1.38235294; top: 0; }
                #wpadminbar #wp-admin-bar-sps-notices .sps-notices-dot { top: 7px; right: 8px; width: 9px; height: 9px; }
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
     *
     * Kept notices of skipped plugins (see replay()) are unwrapped first, each
     * element taking the wrapper's hash, so they are treated like the rest.
     */
    public static function mark() {
        $s    = self::selectors();
        $data = array(
            'notices' => $s['notices'] . ', .update-nag',
            'kinds'   => $s['kinds'],
            'mark'    => self::MARK,
            'stored'  => self::STORED,
        );
        $js = '(function (cfg) {
    var content = document.getElementById("wpbody-content");
    if (!content || !content.querySelector || !window.Element || !Element.prototype.matches) {
        return;
    }
    var wrappers = content.querySelectorAll("div.sps-stored-notice[" + cfg.stored + "]");
    for (var i = 0; i < wrappers.length; i++) {
        var w = wrappers[i], hash = w.getAttribute(cfg.stored);
        while (w.firstChild) {
            if (1 === w.firstChild.nodeType) {
                w.firstChild.setAttribute(cfg.stored, hash);
            }
            w.parentNode.insertBefore(w.firstChild, w);
        }
        w.parentNode.removeChild(w);
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
            'none'    => __('No notices.', 'seoprostack'),
            'stored'  => self::STORED,
            'ajax'    => admin_url('admin-ajax.php'),
            'forget'  => self::FORGET,
            'nonce'   => wp_create_nonce(self::FORGET),
        ));
    }
}

