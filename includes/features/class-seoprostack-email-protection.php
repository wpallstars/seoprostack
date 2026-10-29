<?php
/**
 * Hide rendered email addresses from HTML-only scrapers, not browser users.
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

class SEOProStack_Email_Protection extends SEOProStack_Feature {

    const KEY = 'email_protection';

    /** Explicit, nonce-protected request to inspect the public home page. */
    const CHECK = 'seoprostack_email_check';

    /** An email address in decoded text or an attribute value. */
    const ADDRESS = '/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}/i';

    /** Mailto link attributes the script puts back (with data-*). */
    const RESTORE = array('title', 'aria-label', 'aria-description');

    /** @var int|null One obfuscation key per response; not encryption. */
    private static $key = null;

    /** @return array Settings schema. */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'security',
                'label'       => __('Email protection', 'seoprostack'),
                'description' => __('Hide email addresses in content, widgets, blocks and menus from scrapers that read HTML. A small script restores the text and email links for visitors. Without JavaScript, a message asks visitors to enable it. Does not change stored content, form values, feeds or REST responses. Works without Cloudflare; scrapers that run JavaScript can still read addresses. Cannot hide schema emails, other plugins’ script data or hard-coded templates: remove the optional schema email, change the plugin’s text or edit the template at its source.', 'seoprostack'),
                'panel'       => true,
            ),
        );
    }

    /** Register output filters only while enabled. */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack email-protection check', array(__CLASS__, 'cli_check'));
        }
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'));
        }
        if (!self::enabled() || is_admin()) {
            return;
        }
        foreach (array('the_content', 'widget_text', 'widget_text_content', 'widget_block_content', 'render_block', 'wp_nav_menu_items') as $hook) {
            add_filter($hook, array(__CLASS__, 'protect'), 999);
        }
        add_filter('script_loader_tag', array(__CLASS__, 'defer_script'), 10, 2);
    }

    /**
     * Inspect one public page, without cookies or cross-host redirects.
     *
     * @param string $url Optional page on this site's host and port.
     * @return array|WP_Error Rows with address, context and hint, or a fetch error.
     */
    public static function check($url = '') {
        $home = home_url('/');
        $url  = '' === $url ? $home : $url;
        $parts = wp_parse_url($url);
        $site  = wp_parse_url($home);
        if (!is_array($parts) || !is_array($site) || empty($parts['host']) || empty($parts['scheme'])
            || !in_array($parts['scheme'], array('http', 'https'), true)
            || 0 !== strcasecmp($parts['host'], (string) ($site['host'] ?? ''))
            || ($parts['port'] ?? ('https' === $parts['scheme'] ? 443 : 80)) !== ($site['port'] ?? ('https' === ($site['scheme'] ?? '') ? 443 : 80))
            || isset($parts['user']) || isset($parts['pass'])) {
            return new WP_Error('email_check_url', __('Use a page URL on this site’s host and port, without login details.', 'seoprostack'));
        }
        $response = wp_safe_remote_get($url, array(
            'timeout'             => 3,
            'redirection'         => 0,
            'limit_response_size' => 2 * MB_IN_BYTES,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        if (200 !== wp_remote_retrieve_response_code($response)) {
            return new WP_Error('email_check_http', __('The page did not return HTTP 200. Redirects are not followed; use its final URL on this site.', 'seoprostack'));
        }
        $html = wp_remote_retrieve_body($response);
        if (strlen($html) >= 2 * MB_IN_BYTES) {
            return new WP_Error('email_check_size', __('The page is too large to check completely (2 MB limit).', 'seoprostack'));
        }
        return self::check_html($html);
    }

    /**
     * Find readable addresses, keeping each once with all observed contexts.
     * Encoded placeholders are never decoded or treated as findings.
     *
     * @param string $html Public page source.
     * @return array Rows for both the settings panel and WP-CLI.
     */
    private static function check_html($html) {
        $rows = array();
        preg_match_all('~<!--[\s\S]*?(?:-->|$)|<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</script\s*>|$)|<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $html, $tokens, PREG_OFFSET_CAPTURE);
        $offset = 0;
        foreach ($tokens[0] as $token) {
            self::find_addresses(substr($html, $offset, $token[1] - $offset), 'text', $rows);
            $tag = $token[0];
            $processor = new WP_HTML_Tag_Processor($tag);
            if ($processor->next_tag()) {
                $names = $processor->get_attribute_names_with_prefix('');
                foreach (is_array($names) ? $names : array() as $name) {
                    if (in_array($name, array('data-sps-e', 'data-sps-mailto', 'data-sps-attrs'), true)) {
                        continue;
                    }
                    $value = $processor->get_attribute($name);
                    if (is_string($value)) {
                        $json = json_decode($value, true);
                        self::find_addresses($value, is_array($json) ? 'script data' : 'attribute', $rows);
                    }
                }
                if ('SCRIPT' === $processor->get_tag()) {
                    $type = $processor->get_attribute('type');
                    $context = is_string($type) && 'application/ld+json' === strtolower(trim($type)) ? 'schema' : 'script data';
                    $start = strpos($tag, '>');
                    if (false !== $start) {
                        self::find_addresses(substr($tag, $start + 1), $context, $rows);
                    }
                }
            }
            $offset = $token[1] + strlen($tag);
        }
        self::find_addresses(substr($html, $offset), 'text', $rows);
        return array_values($rows);
    }

    /**
     * Add unique matches to the shared result, without changing plugin options.
     *
     * @param string $value   Source text or attribute value.
     * @param string $context Source context.
     * @param array  $rows    Results keyed by lower-case address.
     */
    private static function find_addresses($value, $context, &$rows) {
        preg_match_all(self::ADDRESS, html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);
        foreach ($matches[0] as $address) {
            $key = strtolower($address);
            if (!isset($rows[$key])) {
                $titles = get_option('rank-math-options-titles', array());
                $rank_email = is_array($titles) && isset($titles['email']) && is_string($titles['email']) ? $titles['email'] : '';
                $rows[$key] = array(
                    'address' => $address,
                    'context' => $context,
                    'hint'    => 0 === strcasecmp(trim($rank_email), $address)
                        ? __('Rank Math: Titles & Meta → Local SEO → Email. Clear the optional email field.', 'seoprostack')
                        : __('Search your plugins’ settings for this address; if it is in a template, edit it there.', 'seoprostack'),
                );
            } elseif (!in_array($context, explode(', ', $rows[$key]['context']), true)) {
                $rows[$key]['context'] .= ', ' . $context;
            }
        }
    }

    /**
     * On-demand home-page check inside the existing setting's panel.
     *
     * @param string $key Setting being rendered.
     */
    public static function panel($key) {
        if (self::KEY !== $key || !current_user_can('manage_options')) {
            return;
        }
        $url = wp_nonce_url(add_query_arg(self::CHECK, '1', SEOProStack_Admin_Manager::tab_url('security')), self::CHECK);
        echo '<div class="sps-panel-note"><p>' . esc_html__('Check the public home page for readable addresses in schema, script data, attributes and template text. This runs only when requested, changes no settings and does not check other pages or guarantee protection. Clear page caches after fixing a source.', 'seoprostack') . '</p>';
        echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Check for readable addresses', 'seoprostack') . '</a></p>';
        if (isset($_GET[self::CHECK])) {
            check_admin_referer(self::CHECK);
            $rows = self::check();
            if (is_wp_error($rows)) {
                echo '<p>' . esc_html($rows->get_error_message()) . '</p>';
            } elseif (!$rows) {
                echo '<p>' . esc_html__('No readable addresses found in this page’s HTML. This is not a guarantee against scrapers that run JavaScript.', 'seoprostack') . '</p>';
            } else {
                echo '<ul>';
                foreach ($rows as $row) {
                    echo '<li><code>' . esc_html($row['address']) . '</code> — ' . esc_html($row['context']) . ': ' . esc_html($row['hint']) . '</li>';
                }
                echo '</ul>';
            }
        }
        echo '</div>';
    }

    /**
     * Check a public page for readable email addresses.
     *
     * ## OPTIONS
     *
     * [<url>]
     * : Page on this site's host and port. Defaults to the home page.
     *
     * @param array $args Positional arguments.
     */
    public static function cli_check($args) {
        $rows = self::check($args[0] ?? '');
        if (is_wp_error($rows)) {
            WP_CLI::error($rows->get_error_message());
            return;
        }
        if (!$rows) {
            WP_CLI::success(__('No readable addresses found in this page’s HTML.', 'seoprostack'));
            return;
        }
        \WP_CLI\Utils\format_items('table', $rows, array('address', 'context', 'hint'));
    }

    /**
     * Transform text and mailto hrefs, preserving every other attribute.
     * Raw-text elements and comments are kept as whole tokens. Repeated
     * content/block/widget passes cannot encode an existing placeholder again.
     *
     * @param string $html Rendered HTML.
     * @return string
     */
    public static function protect($html) {
        if (self::plain_context()) {
            return $html;
        }
        /**
         * Leave a context's HTML plain (for example, a print or export view).
         *
         * @param bool   $skip Whether to skip protection.
         * @param string $html Rendered HTML.
         */
        if (apply_filters('seoprostack_email_protection_skip', false, $html)) {
            return $html;
        }
        // An "@", written plainly or as an entity (antispambot()), or a mailto
        // link; anything else skips all parsing. Placeholders hold neither.
        if (!preg_match('/@|&#0*64;?|&#x0*40;?|&commat;|mailto:/i', $html)) {
            return $html;
        }
        $matched = preg_match_all('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|title|noscript)(?=[\x09\x0A\x0C\x0D />])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1(?=[\x09\x0A\x0C\x0D />])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>|$)|<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $html, $parts, PREG_OFFSET_CAPTURE);
        if (false === $matched) {
            return $html;
        }
        $output = '';
        $offset = 0;
        foreach ($parts[0] as $part) {
            $output .= self::protect_text(substr($html, $offset, $part[1] - $offset));
            $output .= self::protect_tag($part[0]);
            $offset = $part[1] + strlen($part[0]);
        }
        return $output . self::protect_text(substr($html, $offset));
    }

    /**
     * These responses cannot safely rely on a browser's footer decoder.
     * Only a front-end page being drawn (after template_redirect) is
     * protected, so content built for emails by form handlers, cron, WP-CLI
     * or REST before then stays plain.
     *
     * @return bool
     */
    private static function plain_context() {
        return is_admin() || !did_action('template_redirect') || is_feed() || wp_doing_ajax()
            || wp_doing_cron() || wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)
            || doing_filter('wp_mail');
    }

    /**
     * Encode only mailto hrefs; keep link classes, labels and nested markup.
     *
     * @param string $tag HTML token, possibly a complete raw-text element.
     * @return string
     */
    private static function protect_tag($tag) {
        if (!preg_match('/^<a\s/i', $tag)) {
            return $tag;
        }
        $processor = new WP_HTML_Tag_Processor($tag);
        if (!$processor->next_tag()) {
            return $tag;
        }
        $href = $processor->get_attribute('href');
        if (!is_string($href) || 0 !== stripos(trim($href), 'mailto:')) {
            return $tag;
        }
        $processor->set_attribute('data-sps-mailto', self::encode(trim($href)));
        $processor->set_attribute('href', '#sps-email');
        self::protect_link_attributes($processor);
        return $processor->get_updated_html();
    }

    /**
     * A mailto link's other attributes can hold the address too: the block
     * editor's link control writes data-id="mailto:…", and a title or
     * aria-label may repeat it. Take every such attribute out of the HTML;
     * the script restores the ones in RESTORE and data-* (not data-sps-*),
     * never others, so it can never add an event handler or a style.
     *
     * @param WP_HTML_Tag_Processor $processor Processor on the link.
     * @return void
     */
    private static function protect_link_attributes($processor) {
        $names = $processor->get_attribute_names_with_prefix('');
        if (!is_array($names)) {
            return;
        }
        $hidden = array();
        foreach ($names as $name) {
            // data-sps-mailto, set just before, holds only encoded hex.
            if ('href' === $name || 'data-sps-mailto' === $name) {
                continue;
            }
            $value = $processor->get_attribute($name);
            if (!is_string($value) || (false === stripos($value, 'mailto:') && !preg_match(self::ADDRESS, $value))) {
                continue;
            }
            $processor->remove_attribute($name);
            if (in_array($name, self::RESTORE, true) || (0 === strpos($name, 'data-') && 0 !== strpos($name, 'data-sps-'))) {
                $hidden[$name] = $value;
            }
        }
        if ($hidden) {
            $processor->set_attribute('data-sps-attrs', self::encode((string) wp_json_encode($hidden)));
        }
    }

    /**
     * Replace addresses in text nodes, never interpolate decoded HTML.
     *
     * @param string $text Text node's source.
     * @return string
     */
    private static function protect_text($text) {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match_all(self::ADDRESS, $decoded, $matches, PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        // Re-escape only this text token, so entities cannot become markup.
        $output = '';
        $offset = 0;
        foreach ($matches[0] as $match) {
            $output .= self::escape_text(substr($decoded, $offset, $match[1] - $offset));
            $output .= '<span class="sps-email" data-sps-e="' . esc_attr(self::encode($match[0])) . '"></span><noscript>'
                . esc_html__('Email address hidden, enable JavaScript.', 'seoprostack') . '</noscript>';
            $offset = $match[1] + strlen($match[0]);
        }
        return $output . self::escape_text(substr($decoded, $offset));
    }

    /**
     * Escape already-decoded text, including literal entity-like strings.
     * esc_html() alone does not double-encode an ampersand in "&copy;".
     *
     * @param string $text Decoded text segment.
     * @return string
     */
    private static function escape_text($text) {
        return esc_html(str_replace('&', '&amp;', $text));
    }

    /**
     * Hex-encoded XOR bytes; first byte carries the response's key.
     *
     * @param string $value Address or complete mailto URI (including queries).
     * @return string
     */
    private static function encode($value) {
        if (null === self::$key) {
            self::$key = wp_rand(1, 255);
        }
        $encoded = sprintf('%02x', self::$key);
        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            $encoded .= sprintf('%02x', ord($value[$index]) ^ self::$key);
        }
        // Footer loading works when a placeholder first appears after wp_head.
        wp_enqueue_script('seoprostack-email-protection', SEOPROSTACK_URL . 'assets/js/email-protection.js', array(), SEOPROSTACK_VERSION, true);
        return $encoded;
    }

    /**
     * Defer on WordPress 6.2 too (native strategies arrived in 6.3).
     *
     * @param string $tag    Script tag.
     * @param string $handle Script handle.
     * @return string
     */
    public static function defer_script($tag, $handle) {
        if ('seoprostack-email-protection' !== $handle) {
            return $tag;
        }
        $processor = new WP_HTML_Tag_Processor($tag);
        if ($processor->next_tag()) {
            $processor->set_attribute('defer', true);
        }
        return $processor->get_updated_html();
    }
}
