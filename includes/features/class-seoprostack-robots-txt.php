<?php
/**
 * Robots.txt rules: what every site needs in robots.txt and in its
 * robots headers, with or without an SEO plugin.
 *
 * WordPress answers /robots.txt itself (do_robots()), and SEO plugins change
 * that answer through the robots_txt filter: Rank Math's editor replaces it
 * (priority 10, dropping the sitemap line its own sitemap module added at 0),
 * and Yoast SEO rebuilds it (99999). This runs after both and only adds what
 * is missing to each group of rules: the admin, search results and
 * WooCommerce's own lines, then a sitemap line when there is none. Lines that
 * another plugin or the owner wrote are never removed or changed, a path a
 * group already names (with Allow or Disallow) is left to that line, and a
 * group that blocks the whole site is left as it is.
 *
 * Search results also say noindex. A site that discourages search engines
 * (Settings → Reading) says noindex, nofollow in the headers of every page
 * and feed WordPress sends. robots.txt never blocks such a site: a page that
 * search engines may not fetch cannot show them its noindex, so it can still
 * be listed, without a description, from links to it.
 *
 * A robots.txt file in the site's folder answers instead of WordPress, so
 * none of this (nor the SEO plugin's rules) is used. The settings panel names
 * it and can keep its own lines as the site's own rules, renaming the file.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.197
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Robots_Txt extends SEOProStack_Feature {

    const KEY = 'robots_txt';

    /** robots.txt's path from the root of the domain. */
    const PATH = '/robots.txt';

    /** Option: the site's own rules, added at the end (not autoloaded). */
    const RULES = 'seoprostack_robots_txt_rules';

    /** Save the site's own rules (admin-post and AJAX). */
    const SAVE = 'seoprostack_robots_txt_save';

    /** Use the rules instead of a robots.txt file (admin-post and AJAX). */
    const ADOPT = 'seoprostack_robots_txt_adopt';

    /** After Yoast SEO's robots_txt filter (99999), which rebuilds the answer. */
    const PRIORITY = 100000;

    /** Most bytes of own rules kept. Google reads the first 500 KiB. */
    const MAX_BYTES = 32768;

    /** Most bytes of a robots.txt file shown in the panel. */
    const SHOW_BYTES = 8192;

    /**
     * Crawlers that only collect pages to train AI models. Search engines
     * and AI search and answer crawlers (Googlebot, Bingbot, OAI-SearchBot,
     * Claude-SearchBot, PerplexityBot) are not here, so the site can still
     * be found and cited. Google-Extended and Applebot-Extended are tokens
     * that Google's and Apple's crawlers read, not crawlers of their own.
     */
    const AI_TRAINING = array('GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent', 'Bytespider');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'links',
                'label'       => __('Robots.txt rules', 'seoprostack'),
                'description' => __('Ask search engines not to crawl search results or the admin, and list the sitemap, with or without an SEO plugin. Rules from other plugins and your own are kept. Sites that discourage search engines say so on every page and feed.', 'seoprostack'),
            ),
            'robots_txt_search' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Keep search results out of search engines', 'seoprostack'),
                'description' => __('Search results (?s= and /search/) are not crawled and say noindex, so spam links to made-up searches cannot fill search engines with pages of this site.', 'seoprostack'),
            ),
            'robots_txt_private' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Hide sites that discourage search engines', 'seoprostack'),
                'description' => __('While Settings → Reading → Search engine visibility is ticked, every page and feed also says noindex, nofollow in its headers, which search engines read without reading the page. For staging, demo and preview sites.', 'seoprostack'),
            ),
            'robots_txt_ai_training' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Ask AI training crawlers to stay away', 'seoprostack'),
                'description' => sprintf(
                    /* translators: %s: crawler names */
                    __('Asks crawlers that only collect pages to train AI models not to crawl the site: %s. Search engines and AI search and answer services still crawl it, so the site can still be found and cited. Crawlers that ignore robots.txt are not stopped.', 'seoprostack'),
                    implode(', ', self::AI_TRAINING)
                ),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Also while off, so switching it off clears the cached robots.txt.
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'));
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'));
            foreach (array(self::SAVE => 'save_rules', self::ADOPT => 'adopt_file') as $action => $method) {
                add_action('admin_post_' . $action, array(__CLASS__, $method));
                add_action('wp_ajax_' . $action, array(__CLASS__, $method));
            }
        }
        if (!self::enabled()) {
            return;
        }
        add_filter('robots_txt', array(__CLASS__, 'filter'), self::PRIORITY, 2);
        add_action('update_option_blog_public', array(__CLASS__, 'visibility_changed'));
        if (SEOProStack_Settings::get('robots_txt_search')) {
            add_filter('wp_robots', array(__CLASS__, 'search_robots'));
            // Before feeds and templates; the query is known by then.
            add_action('template_redirect', array(__CLASS__, 'search_header'), 0);
        }
        if (self::hiding()) {
            add_action('send_headers', array(__CLASS__, 'private_header'));
        }
    }

    /**
     * Whether this site discourages search engines and the feature says so
     * in the headers.
     *
     * @return bool
     */
    private static function hiding() {
        return '0' === (string) get_option('blog_public') && (bool) SEOProStack_Settings::get('robots_txt_private');
    }

    /* ------------------------------------------------------------------ */
    /* robots.txt                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Add the missing rules to robots.txt as WordPress and other plugins
     * made it, then the site's own rules and a sitemap line.
     *
     * @param string $output robots.txt so far.
     * @param bool   $public Whether the site lets search engines in (blog_public).
     * @return string
     */
    public static function filter($output, $public = true) {
        $lines  = self::lines((string) $output);
        $public = (bool) $public;
        $own    = trim((string) get_option(self::RULES, ''));
        $own    = '' === $own ? array() : self::lines($own);
        if ($public) {
            $lines = self::add_rules($lines, self::wanted());
            $lines = self::add_ai_group($lines, $own);
        }
        if ($own) {
            $lines[] = '';
            $lines   = array_merge($lines, $own);
        }
        if ($public) {
            $lines = self::add_sitemap($lines);
        }
        return self::text($lines);
    }

    /**
     * The rules every group should have, as [directive, path].
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function wanted() {
        $base  = self::base_path();
        $rules = array();

        $admin = wp_parse_url(admin_url(), PHP_URL_PATH);
        $ajax  = wp_parse_url(admin_url('admin-ajax.php'), PHP_URL_PATH);
        if (is_string($admin) && '' !== $admin && is_string($ajax) && '' !== $ajax) {
            $rules[] = array('Disallow', $admin);
            $rules[] = array('Allow', $ajax);
        }

        if (SEOProStack_Settings::get('robots_txt_search')) {
            // "*" matches nothing too, so /*?s= also covers /?s=.
            $rules[] = array('Disallow', $base . '*?s=');
            $rules[] = array('Disallow', $base . '*&s=');
            $search = self::search_base();
            if ('' !== $search) {
                $rules[] = array('Disallow', $base . $search . '/');
                // A page at that address (a search page of its own) stays crawlable.
                if (get_page_by_path($search)) {
                    $rules[] = array('Allow', $base . $search . '/$');
                }
            }
        }

        // WooCommerce's own lines (WooCommerce::robots_txt()), which Rank
        // Math's editor or a static file can leave out.
        if (class_exists('WooCommerce', false)) {
            $uploads = wp_get_upload_dir();
            $path    = wp_parse_url(isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '', PHP_URL_PATH);
            $path    = is_string($path) && '' !== $path ? rtrim($path, '/') : '/wp-content/uploads';
            foreach (array('/wc-logs/', '/woocommerce_transient_files/', '/woocommerce_uploads/') as $folder) {
                $rules[] = array('Disallow', $path . $folder);
            }
            $rules[] = array('Disallow', $base . '*?add-to-cart=');
            $rules[] = array('Disallow', $base . '*?*add-to-cart=');
        }

        /**
         * Filter the rules Robots.txt rules adds to each group of
         * robots.txt that lacks them.
         *
         * @param array<int,array{0:string,1:string}> $rules [directive, path] pairs, such as ['Disallow', '/*?s='].
         */
        $rules = apply_filters('seoprostack_robots_txt_rules', $rules);
        return is_array($rules) ? array_values(array_filter($rules, function ($rule) {
            return is_array($rule) && isset($rule[0], $rule[1]) && is_string($rule[0]) && is_string($rule[1]);
        })) : array();
    }

    /**
     * The site's address path, with slashes at both ends ("/" for most).
     * robots.txt is read only at the root of a domain, so a site in a folder
     * gives paths from the root.
     *
     * @return string
     */
    private static function base_path() {
        $path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        return is_string($path) && '' !== $path ? trailingslashit($path) : '/';
    }

    /**
     * The search results' pretty address ("search" by default), or '' with
     * plain permalinks.
     *
     * @return string
     */
    private static function search_base() {
        global $wp_rewrite;
        if (!$wp_rewrite instanceof WP_Rewrite || !$wp_rewrite->using_permalinks()) {
            return '';
        }
        return trim((string) $wp_rewrite->search_base, '/');
    }

    /**
     * Add each missing rule to every group, after the group's last line. A
     * group that already names a path keeps its own line for it, and a group
     * that blocks the whole site (Disallow: /) is left alone. With no group
     * for every crawler (User-agent: *), one is added.
     *
     * @param string[]                            $lines robots.txt lines.
     * @param array<int,array{0:string,1:string}> $rules [directive, path] pairs.
     * @return string[]
     */
    public static function add_rules(array $lines, array $rules) {
        $lines = array_values($lines);
        if (!$rules) {
            return $lines;
        }
        $groups = self::groups($lines);
        $star   = false;
        // From the last group, so earlier line numbers stay right.
        foreach (array_reverse($groups) as $group) {
            $star = $star || in_array('*', $group['agents'], true);
            if (isset($group['paths']['/']) && 'disallow' === $group['paths']['/']) {
                continue;
            }
            $add  = array();
            $have = $group['paths'];
            foreach ($rules as $rule) {
                if (!isset($have[$rule[1]])) {
                    $add[]           = $rule[0] . ': ' . $rule[1];
                    $have[$rule[1]] = strtolower($rule[0]);
                }
            }
            if ($add) {
                array_splice($lines, $group['last'] + 1, 0, $add);
            }
        }
        if (!$star) {
            $lines[] = '';
            $lines[] = 'User-agent: *';
            foreach ($rules as $rule) {
                $lines[] = $rule[0] . ': ' . $rule[1];
            }
        }
        return $lines;
    }

    /**
     * The groups of rules: one or more User-agent lines and the lines after
     * them, up to the next User-agent line. Sitemap lines belong to no group.
     *
     * @param string[] $lines robots.txt lines.
     * @return array<int,array<string,mixed>> Each group's agents (lowercase),
     *         agent_lines (their line numbers), last (its last line), members
     *         (how many rule lines it has) and paths (path => lowercase
     *         directive).
     */
    public static function groups(array $lines) {
        $groups = array();
        $group  = null;
        $open   = false;
        foreach (array_values($lines) as $i => $line) {
            $field = self::field($line);
            if (null === $field) {
                continue;
            }
            if ('user-agent' === $field[0]) {
                if (!$open) {
                    if (null !== $group) {
                        $groups[] = $group;
                    }
                    $group = array('agents' => array(), 'agent_lines' => array(), 'last' => $i, 'members' => 0, 'paths' => array());
                    $open  = true;
                }
                $group['agents'][]      = strtolower($field[1]);
                $group['agent_lines'][] = $i;
                $group['last']          = $i;
                continue;
            }
            $open = false;
            if ('sitemap' === $field[0] || null === $group) {
                continue;
            }
            $group['last'] = $i;
            ++$group['members'];
            if (('allow' === $field[0] || 'disallow' === $field[0]) && !isset($group['paths'][$field[1]])) {
                $group['paths'][$field[1]] = $field[0];
            }
        }
        if (null !== $group) {
            $groups[] = $group;
        }
        return $groups;
    }

    /**
     * A robots.txt line's field name (lowercase) and value, without its
     * comment; null for blank lines, comments and lines that are not fields.
     *
     * @param string $line robots.txt line.
     * @return array{0:string,1:string}|null
     */
    public static function field($line) {
        $line = trim((string) preg_replace('/#.*$/', '', (string) $line));
        if ('' === $line || !preg_match('/^([A-Za-z][A-Za-z-]*)\s*:\s*(.*)$/', $line, $match)) {
            return null;
        }
        return array(strtolower($match[1]), trim($match[2]));
    }

    /**
     * Add a group asking AI training crawlers to stay away, for those that
     * no group names yet (a group of another plugin's or the owner's for one
     * of them wins).
     *
     * @param string[] $lines robots.txt lines.
     * @param string[] $own   The site's own rules, added after these lines.
     * @return string[]
     */
    private static function add_ai_group(array $lines, array $own = array()) {
        if (!SEOProStack_Settings::get('robots_txt_ai_training')) {
            return $lines;
        }
        $named = array();
        foreach (array_merge(self::groups($lines), self::groups($own)) as $group) {
            $named = array_merge($named, $group['agents']);
        }

        /**
         * Filter the crawlers that "Ask AI training crawlers to stay away"
         * names in robots.txt.
         *
         * @param string[] $agents User-agent tokens.
         */
        $agents = (array) apply_filters('seoprostack_robots_txt_ai_crawlers', self::AI_TRAINING);
        $add    = array();
        foreach ($agents as $agent) {
            $agent = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) $agent));
            if ('' !== $agent && !in_array(strtolower($agent), $named, true)) {
                $add[] = 'User-agent: ' . $agent;
            }
        }
        if ($add) {
            $lines[] = '';
            $lines   = array_merge($lines, $add, array('Disallow: /'));
        }
        return $lines;
    }

    /**
     * Add the sitemap when no line names one.
     *
     * @param string[] $lines robots.txt lines.
     * @return string[]
     */
    private static function add_sitemap(array $lines) {
        foreach ($lines as $line) {
            $field = self::field($line);
            if (null !== $field && 'sitemap' === $field[0]) {
                return $lines;
            }
        }
        $url = self::sitemap_url();
        if ('' !== $url) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $url;
        }
        return $lines;
    }

    /**
     * The sitemap index robots.txt lists: Rank Math's, Yoast's or
     * WordPress's own.
     *
     * @return string URL, or ''.
     */
    private static function sitemap_url() {
        /**
         * Filter the sitemap Robots.txt rules lists when robots.txt has no
         * Sitemap line.
         *
         * @param string $url Sitemap index URL, or '' for none.
         */
        $url = apply_filters('seoprostack_robots_txt_sitemap', SEOProStack_Litespeed::sitemap_index());
        return is_string($url) ? esc_url_raw(trim($url), array('http', 'https')) : '';
    }

    /**
     * Text as lines, without line ends or trailing spaces.
     *
     * @param string $text Text.
     * @return string[]
     */
    private static function lines($text) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        return array_map('rtrim', is_array($lines) ? $lines : array());
    }

    /**
     * Lines as robots.txt text: no blank lines at either end, at most one
     * in a row, ending with a line end.
     *
     * @param string[] $lines Lines.
     * @return string
     */
    private static function text(array $lines) {
        $out   = array();
        $blank = true;
        foreach ($lines as $line) {
            $line = rtrim((string) $line);
            if ('' === $line) {
                if (!$blank) {
                    $out[] = '';
                }
                $blank = true;
                continue;
            }
            $out[] = $line;
            $blank = false;
        }
        while ($out && '' === end($out)) {
            array_pop($out);
        }
        return $out ? implode("\n", $out) . "\n" : '';
    }

    /* ------------------------------------------------------------------ */
    /* Robots headers                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Search results: noindex, follow, in the page's robots meta tag.
     *
     * @param array $robots wp_robots directives.
     * @return array
     */
    public static function search_robots($robots) {
        $robots = (array) $robots;
        if (is_search()) {
            $robots['noindex'] = true;
            if (empty($robots['nofollow'])) {
                $robots['follow'] = true;
            }
        }
        return $robots;
    }

    /**
     * Search results and their feeds: noindex, follow, in the headers too.
     */
    public static function search_header() {
        if (is_search() && !headers_sent() && !self::hiding()) {
            header('X-Robots-Tag: noindex, follow', false);
        }
    }

    /**
     * A site that discourages search engines: noindex, nofollow on every
     * page, feed and robots.txt WordPress sends.
     */
    public static function private_header() {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', false);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Caches                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * A setting of this card changed: robots.txt changed, and with the
     * header setting on a hidden site, every page.
     *
     * @param string $key Setting key.
     */
    public static function setting_saved($key) {
        $key = (string) $key;
        if (self::KEY !== $key && 0 !== strpos($key, self::KEY . '_')) {
            return;
        }
        if ('0' === (string) get_option('blog_public') && in_array($key, array(self::KEY, 'robots_txt_private'), true)) {
            self::visibility_changed();
            return;
        }
        self::purge_robots();
    }

    /**
     * Settings → Reading → Search engine visibility changed: every page's
     * robots headers and tags change.
     */
    public static function visibility_changed() {
        SEOProStack_Page_Cache::purge_all();
        if (class_exists('SEOProStack_Cloudflare_Purge', false) && SEOProStack_Cloudflare_Purge::enabled()) {
            SEOProStack_Cloudflare_Purge::site();
        }
    }

    /**
     * Drop the cached copies of robots.txt: LiteSpeed Cache's (it keeps
     * robots.txt like a page) and, with Clear Cloudflare's copies of pages,
     * Cloudflare's.
     */
    public static function purge_robots() {
        $url = home_url(self::PATH);
        do_action('litespeed_purge_url', $url); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
        if (class_exists('SEOProStack_Cloudflare_Purge', false) && SEOProStack_Cloudflare_Purge::enabled()) {
            SEOProStack_Cloudflare_Purge::urls(array($url));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Settings panel                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * A robots.txt file in the site's folder, which the web server sends
     * instead of WordPress's answer.
     *
     * @return string Path, or '' when there is none.
     */
    public static function static_file() {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = trailingslashit(get_home_path()) . 'robots.txt';
        return is_file($file) ? $file : '';
    }

    /**
     * robots.txt at the root of the site's domain, the only one search
     * engines read (for a site in a folder).
     *
     * @return string
     */
    private static function root_url() {
        $parts = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return self::PATH;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port . self::PATH;
    }

    /**
     * The settings panel: a robots.txt file that answers instead, a site
     * in a folder, a link to robots.txt, and the site's own rules.
     *
     * @param string $key Setting key.
     */
    public static function panel($key) {
        if (self::KEY !== $key || !SEOProStack_Settings::can_change()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which message to show after a redirect.
        $done = isset($_GET['sps-robots']) ? sanitize_key(wp_unslash($_GET['sps-robots'])) : '';
        $file = self::static_file();
        $url  = home_url(self::PATH);

        echo '<div class="sps-panel-note">';
        if ('' !== $done) {
            echo '<p role="status">' . esc_html(self::message($done)) . '</p>';
        }
        if ('' !== $file) {
            $contents = is_readable($file) ? (string) file_get_contents($file, false, null, 0, self::SHOW_BYTES) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            echo '<div data-sps-robots-static>';
            echo '<p><strong>' . esc_html__('A robots.txt file in the site’s folder answers instead of WordPress', 'seoprostack') . '</strong></p>';
            echo '<p>' . esc_html__('So these rules, your SEO plugin’s and WordPress’s own are not used, and the file must be kept up to date by hand.', 'seoprostack') . '</p>';
            if ('' !== $contents) {
                echo '<details><summary>' . esc_html__('What the file says', 'seoprostack') . '</summary><pre class="code">' . esc_html($contents) . '</pre></details>';
            }
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-sps-robots-form>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ADOPT); ?>" />
                <?php wp_nonce_field(self::ADOPT); ?>
                <p class="description"><?php esc_html_e('The file’s own lines are kept in Your own rules, without the ones these settings add, and the file is renamed with .bak at the end, so you can still restore it.', 'seoprostack'); ?></p>
                <p><?php submit_button(__('Use these rules instead of the file', 'seoprostack'), 'secondary', 'submit', false); ?></p>
                <p class="sps-robots-status" data-sps-robots-status role="status"></p>
            </form>
            </div>
            <?php
        }
        if ('/' !== self::base_path()) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: robots.txt address at the root of the domain */
                __('This site is in a folder, and search engines read robots.txt only at the root of the domain (%s). Add these rules there.', 'seoprostack'),
                self::root_url()
            )) . '</p>';
        }
        printf(
            '<p><a href="%1$s" target="_blank" rel="noopener">%2$s</a></p>',
            esc_url($url),
            esc_html__('View robots.txt', 'seoprostack')
        );
        echo '</div>';
        ?>
        <div class="sps-panel-note">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-sps-robots-form>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE); ?>" />
                <?php wp_nonce_field(self::SAVE); ?>
                <p><label for="sps-robots-rules"><strong><?php esc_html_e('Your own rules', 'seoprostack'); ?></strong></label></p>
                <p class="description"><?php esc_html_e('Added at the end of robots.txt, after what WordPress, other plugins and these settings add. One per line: User-agent, Allow, Disallow and Sitemap lines, or # comments. A group of your own for a crawler is the only one that crawler reads, so repeat any rules it should keep.', 'seoprostack'); ?></p>
                <textarea id="sps-robots-rules" class="large-text code" rows="8" name="seoprostack_robots_rules" data-sps-robots-rules placeholder="<?php echo esc_attr("User-agent: *\nDisallow: /private/"); ?>"><?php echo esc_textarea((string) get_option(self::RULES, '')); ?></textarea>
                <p><?php submit_button(__('Save your rules', 'seoprostack'), 'secondary', 'submit', false); ?></p>
                <p class="sps-robots-status" data-sps-robots-status role="status"></p>
            </form>
        </div>
        <?php
    }

    /**
     * A result's message.
     *
     * @param string $code Result code.
     * @return string
     */
    private static function message($code) {
        $messages = array(
            'saved'   => __('Your rules are saved.', 'seoprostack'),
            'skipped' => __('Your rules are saved. Lines that are not robots.txt rules were left out.', 'seoprostack'),
            'adopted' => __('WordPress answers robots.txt now. The file’s own lines are in Your own rules, and the file was renamed with .bak at the end.', 'seoprostack'),
            'unmoved' => __('The file’s own lines are in Your own rules, but the file could not be renamed, so it still answers instead of WordPress. Rename or delete robots.txt in the site’s folder with your host’s file manager.', 'seoprostack'),
            'unread'  => __('The robots.txt file could not be read.', 'seoprostack'),
            'missing' => __('There is no robots.txt file in the site’s folder now.', 'seoprostack'),
        );
        return isset($messages[$code]) ? $messages[$code] : '';
    }

    /**
     * Save the site's own rules.
     */
    public static function save_rules() {
        self::check(self::SAVE);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check() verified the nonce; clean_rules() keeps only robots.txt lines, without tags or control characters.
        $raw     = isset($_POST['seoprostack_robots_rules']) && is_string($_POST['seoprostack_robots_rules']) ? wp_unslash($_POST['seoprostack_robots_rules']) : '';
        $cleaned = self::clean_rules((string) $raw);
        update_option(self::RULES, $cleaned['rules'], false);
        self::purge_robots();
        self::finish($cleaned['dropped'] ? 'skipped' : 'saved');
    }

    /**
     * Keep a robots.txt file's own lines as the site's rules, and rename the
     * file so WordPress answers robots.txt.
     */
    public static function adopt_file() {
        self::check(self::ADOPT);
        $file = self::static_file();
        if ('' === $file) {
            self::finish('missing');
            return;
        }
        $contents = is_readable($file) ? file_get_contents($file) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if (false === $contents) {
            self::finish('unread');
            return;
        }

        $own     = self::without_added(self::lines((string) $contents));
        $current = (string) get_option(self::RULES, '');
        $cleaned = self::clean_rules(trim($current . "\n\n" . implode("\n", $own)));
        update_option(self::RULES, $cleaned['rules'], false);

        $backup = $file . '-' . gmdate('Ymd-His') . '.bak';
        $moved = wp_is_writable(dirname($file)) && @rename($file, $backup); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- renames the site's own robots.txt, as asked; a failure is reported.
        self::purge_robots();
        self::finish($moved ? 'adopted' : 'unmoved');
    }

    /**
     * A robots.txt file's lines without those this feature adds anyway,
     * the sitemap it lists, and groups left without rules.
     *
     * @param string[] $lines robots.txt lines.
     * @return string[]
     */
    public static function without_added(array $lines) {
        $added = array();
        foreach (self::wanted() as $rule) {
            $added[strtolower($rule[0]) . ':' . $rule[1]] = true;
        }
        $sitemap = untrailingslashit(self::sitemap_url());
        $kept    = array();
        foreach ($lines as $line) {
            $field = self::field($line);
            if (null !== $field && (isset($added[$field[0] . ':' . $field[1]]) || ('sitemap' === $field[0] && '' !== $sitemap && untrailingslashit($field[1]) === $sitemap))) {
                continue;
            }
            $kept[] = $line;
        }
        $drop = array();
        foreach (self::groups($kept) as $group) {
            if (0 === $group['members']) {
                $drop = array_merge($drop, $group['agent_lines']);
            }
        }
        return array_values(array_diff_key($kept, array_flip($drop)));
    }

    /**
     * Keep only robots.txt lines: fields (Name: value), # comments and
     * blank lines, without tags or control characters, up to MAX_BYTES.
     *
     * @param string $raw Submitted text.
     * @return array{rules:string,dropped:int}
     */
    public static function clean_rules($raw) {
        $kept    = array();
        $dropped = 0;
        foreach (self::lines(wp_strip_all_tags((string) $raw)) as $line) {
            $line = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $line));
            if ('' === $line || '#' === $line[0] || preg_match('/^[A-Za-z][A-Za-z-]*\s*:/', $line)) {
                $kept[] = $line;
            } else {
                ++$dropped;
            }
        }
        $text = trim(self::text($kept));
        if (strlen($text) > self::MAX_BYTES) {
            $text = substr($text, 0, self::MAX_BYTES);
            $end  = strrpos($text, "\n");
            $text = rtrim(false === $end ? '' : substr($text, 0, $end));
            ++$dropped;
        }
        return array('rules' => $text, 'dropped' => $dropped);
    }

    /**
     * Stop unless the person may change settings and the form is theirs.
     *
     * @param string $action Nonce action.
     */
    private static function check($action) {
        if (wp_doing_ajax()) {
            check_ajax_referer($action);
        } else {
            check_admin_referer($action);
        }
        if (!SEOProStack_Settings::can_change()) {
            if (wp_doing_ajax()) {
                wp_send_json_error(array('message' => __('You cannot change these settings.', 'seoprostack')), 403);
            }
            wp_die(esc_html__('You cannot change these settings.', 'seoprostack'), '', array('response' => 403));
        }
    }

    /**
     * Answer the form: JSON for the settings screen's script, or back to
     * the Links tab.
     *
     * @param string $code Result code (message()).
     */
    private static function finish($code) {
        if (wp_doing_ajax()) {
            $data = array(
                'message' => self::message($code),
                'rules'   => (string) get_option(self::RULES, ''),
                'file'    => '' !== self::static_file(),
            );
            if (in_array($code, array('unread', 'missing'), true)) {
                wp_send_json_error($data);
            }
            wp_send_json_success($data);
        }
        wp_safe_redirect(SEOProStack_Admin_Manager::tab_url('links', array('sps-robots' => $code)));
        exit;
    }
}
