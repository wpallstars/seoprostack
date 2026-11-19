<?php
/**
 * Website status link: links to #website-status go to the site's public
 * status page, or are left out.
 *
 * An uptime monitor (updown.io, the Website Setup board's Setup Uptime
 * Monitoring task, or any other) gives each site a public status page. A
 * theme or an editor adds a link to #website-status once, such as in the
 * footer; each site then decides whether visitors see it:
 *
 * - switch on, with an address: every such link goes to the status page in
 *   a new tab;
 * - switch off (the default), or no address: the paragraph, list item,
 *   button or navigation link holding it is left out, as is a classic menu
 *   item linking to #website-status.
 *
 * The editor and wp-admin show the link as written. Nothing is stored in
 * pages: the address is applied as each page is made.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.227
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Status_Link extends SEOProStack_Feature {

    const KEY = 'status_link';

    /** Setting: the status page's address. */
    const URL_KEY = 'status_link_url';

    /** The link target that themes and editors use. */
    const HREF = '#website-status';

    /** Blocks whose link is changed or left out. */
    const BLOCKS = array('core/paragraph', 'core/list-item', 'core/button', 'core/navigation-link');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // Off: a site shows its status page only when its owner chooses to.
                'default'     => false,
                'tab'         => 'links',
                'label'       => __('Website status link', 'seoprostack'),
                'description' => __('Links to #website-status, such as a "Website status" link in the footer, go to this site\'s public uptime status page in a new tab. While this is off, or no address is set, those links are left out of the page.', 'seoprostack'),
            ),
            self::URL_KEY => array(
                'type'        => 'url',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Status page', 'seoprostack'),
                'placeholder' => 'https://status.example.com/',
                'description' => __('The public status page of this site\'s uptime monitor, such as an updown.io check\'s page or a status page on your own domain.', 'seoprostack'),
                'network'     => false,
            ),
        );
    }

    /**
     * Register hooks. The filters run while the switch is off too: they
     * leave the links out then.
     */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack status-link', array(__CLASS__, 'cli'), array(
                'shortdesc' => 'Show or set the Website status link: [set <address>] [--on|--off].',
            ));
        }
        if (is_admin()) {
            return;
        }
        add_filter('render_block', array(__CLASS__, 'render_block'), 10, 2);
        add_filter('wp_nav_menu_objects', array(__CLASS__, 'menu_items'));
    }

    /**
     * The status page's address while the link shows, else ''.
     *
     * @return string
     */
    public static function address() {
        if (!self::enabled()) {
            return '';
        }
        return trim((string) SEOProStack_Settings::get(self::URL_KEY));
    }

    /**
     * Point a block's #website-status links at the status page, or leave the
     * block out.
     *
     * @param mixed $content The block's HTML.
     * @param mixed $block   The parsed block.
     * @return mixed
     */
    public static function render_block($content, $block) {
        if (!is_string($content) || false === strpos($content, self::HREF)
            || !is_array($block) || !in_array($block['blockName'] ?? '', self::BLOCKS, true)) {
            return $content;
        }
        $address = self::address();
        $tags    = new WP_HTML_Tag_Processor($content);
        $found   = false;
        while ($tags->next_tag(array('tag_name' => 'a'))) {
            if (self::HREF !== trim((string) $tags->get_attribute('href'))) {
                continue;
            }
            $found = true;
            if ('' === $address) {
                break;
            }
            $tags->set_attribute('href', esc_url($address));
            $tags->set_attribute('target', '_blank');
            $tags->set_attribute('rel', self::rel((string) $tags->get_attribute('rel')));
        }
        if (!$found) {
            return $content;
        }
        return '' === $address ? '' : $tags->get_updated_html();
    }

    /**
     * Point classic menu items linking to #website-status at the status
     * page, or leave them (and their sub-items) out.
     *
     * @param mixed $items Menu item objects.
     * @return mixed
     */
    public static function menu_items($items) {
        if (!is_array($items)) {
            return $items;
        }
        $address = self::address();
        $removed = array();
        foreach ($items as $index => $item) {
            if (!is_object($item) || !isset($item->ID)) {
                continue;
            }
            $id     = (int) $item->ID;
            $parent = isset($item->menu_item_parent) ? (int) $item->menu_item_parent : 0;
            // Menu items come parents first, so a removed parent is known here.
            if ($parent && isset($removed[$parent])) {
                $removed[$id] = true;
                unset($items[$index]);
                continue;
            }
            if (!isset($item->url) || self::HREF !== trim((string) $item->url)) {
                continue;
            }
            if ('' === $address) {
                $removed[$id] = true;
                unset($items[$index]);
                continue;
            }
            $item->url = $address;
            if (property_exists($item, 'target')) {
                $item->target = '_blank';
            }
            if (property_exists($item, 'xfn')) {
                $item->xfn = self::rel((string) $item->xfn);
            }
        }
        return $items;
    }

    /**
     * A rel value with noopener added.
     *
     * @param string $rel Current rel value.
     * @return string
     */
    private static function rel($rel) {
        $parts = preg_split('/\s+/', trim($rel), -1, PREG_SPLIT_NO_EMPTY);
        $parts = is_array($parts) ? $parts : array();
        if (!in_array('noopener', $parts, true)) {
            $parts[] = 'noopener';
        }
        return implode(' ', $parts);
    }

    /**
     * Show or set the Website status link.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : `set` to change it; without it, show it.
     *
     * [<address>]
     * : With `set`: the status page's address ('' clears it).
     *
     * [--on]
     * : Show the link.
     *
     * [--off]
     * : Leave the link out.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack status-link
     *     wp seoprostack status-link set https://status.example.com/ --off
     *     wp seoprostack status-link set --on
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Flags.
     */
    public static function cli($args, $assoc_args) {
        $action = isset($args[0]) ? (string) $args[0] : '';
        if ('' !== $action && 'set' !== $action) {
            WP_CLI::error('Unknown action: ' . $action . '. Use set, or nothing to show the link.');
        }
        if ('set' === $action) {
            if (isset($assoc_args['on']) && isset($assoc_args['off'])) {
                WP_CLI::error('Use --on or --off, not both.');
            }
            if (isset($args[1])) {
                $saved = SEOProStack_Settings::set(self::URL_KEY, (string) $args[1]);
                if (is_wp_error($saved)) {
                    WP_CLI::error($saved->get_error_message());
                }
                if ('' !== trim((string) $args[1]) && '' === (string) $saved) {
                    WP_CLI::error('Not an http or https address: ' . $args[1]);
                }
            }
            if (isset($assoc_args['on']) || isset($assoc_args['off'])) {
                $saved = SEOProStack_Settings::set(self::KEY, isset($assoc_args['on']));
                if (is_wp_error($saved)) {
                    WP_CLI::error($saved->get_error_message());
                }
            }
        }
        $url = (string) SEOProStack_Settings::get(self::URL_KEY);
        WP_CLI::log(sprintf('switch=%s address=%s shows=%s', self::switched_on() ? 'on' : 'off', '' !== $url ? $url : '-', '' !== self::address() ? 'yes' : 'no'));
    }
}
