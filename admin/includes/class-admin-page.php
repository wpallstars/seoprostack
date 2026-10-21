<?php
/**
 * SEO Pro Stack admin screen markup.
 *
 * The header, the tab navigation, the tab panels and the browser title of
 * the settings screen (SEOProStack_Admin_Manager::render_settings_page()). The
 * plugin's own screens show the header through
 * SEOProStack_Admin_Manager::render_header(), their sections as tabs with
 * sections_nav(), and name their section in the browser title with
 * title_section().
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-e4502af0f07e8a92. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Page {

    /**
     * Render the screen's header: the plugin's name and version, the feature
     * search (for people who can open the settings screen) and the links
     * SEOProStack_Setup::header_links() gives.
     */
    public static function header() {
        ?>
            <header class="sps-header">
                <div class="sps-header__brand">
                    <span class="sps-header__logo dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <h1 class="sps-header__title"><?php esc_html_e('SEO Pro Stack', 'seoprostack'); ?></h1>
                    <span class="sps-badge"><?php echo esc_html('v' . SEOPROSTACK_VERSION); ?></span>
                    <?php if (is_network_admin()) : ?>
                        <span class="sps-badge sps-badge--network"><?php esc_html_e('Network', 'seoprostack'); ?></span>
                    <?php endif; ?>
                </div>
                <?php
                if (current_user_can(is_network_admin() ? 'manage_network_options' : 'manage_options')) {
                    self::search_form();
                }
                self::header_links(SEOProStack_Setup::header_links());
                ?>
            </header>
        <?php
    }

    /**
     * Render the header's feature search. It sends page and tab itself, so
     * its action is the screen's file alone.
     */
    private static function search_form() {
        $action = remove_query_arg('page', SEOProStack_Admin_Manager::page_url());
        ?>
                <form class="sps-search" role="search" method="get" action="<?php echo esc_url($action); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr(SEOProStack_Admin_Manager::PAGE); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr(SEOProStack_Admin_Manager::SEARCH); ?>" />
                    <label class="screen-reader-text" for="sps-search-input"><?php esc_html_e('Search features', 'seoprostack'); ?></label>
                    <span class="sps-search__field">
                        <span class="sps-search__icon dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search"
                               id="sps-search-input"
                               class="sps-search__input"
                               name="s"
                               maxlength="100"
                               value="<?php echo esc_attr(SEOProStack_Admin_Manager::search_query()); ?>"
                               placeholder="<?php esc_attr_e('Search features', 'seoprostack'); ?>" />
                    </span>
                    <button type="submit" class="button sps-search__button"><?php esc_html_e('Search', 'seoprostack'); ?></button>
                </form>
        <?php
    }

    /**
     * Render the header's buttons: Source code (or, from older
     * {Prefix}_Setup classes, the maker's website), Support and Buy me a
     * coffee, each only when its link is set.
     *
     * @param array<string,string> $links SEOProStack_Setup::header_links().
     */
    private static function header_links(array $links) {
        $buttons = array();
        if (!empty($links['source'])) {
            $buttons[] = array($links['source'], __('Source code', 'seoprostack'), 'editor-code', 'sps-header__support');
        } elseif (!empty($links['website'])) {
            $buttons[] = array($links['website'], __('Visit website', 'seoprostack'), '', '');
        }
        if (!empty($links['support'])) {
            $buttons[] = array($links['support'], __('Support', 'seoprostack'), 'sos', 'sps-header__support');
        }
        if (!empty($links['donate'])) {
            $buttons[] = array($links['donate'], __('Buy me a coffee', 'seoprostack'), 'coffee', 'sps-header__support sps-header__donate');
        }
        ?>
                <div class="sps-header__actions">
                    <?php foreach ($buttons as $button) : ?>
                        <?php list($url, $label, $icon, $class) = $button; ?>
                        <a class="<?php echo esc_attr(trim('button ' . $class)); ?>" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php if ('' !== $icon) : ?>
                                <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php echo esc_html($label); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostack'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
        <?php
    }

    /**
     * Put a section's name after the screen's name in the browser's title,
     * as "SEO Pro Stack: General ‹ Site — WordPress". Call it on the
     * screen's load-{hook} action: the settings screen does for its tabs,
     * and the plugin's own screens with sections (render_header()) do for
     * the section shown. The rest of core's title (the site's name, Network
     * Admin, Recovery Mode) stays.
     *
     * @param string $section The section's name; empty leaves the title as it is.
     */
    public static function title_section($section) {
        $section = (string) $section;
        if ('' === $section) {
            return;
        }
        add_filter('admin_title', function ($admin_title, $title) use ($section) {
            $admin_title = (string) $admin_title;
            $title       = (string) $title;
            $at          = '' === $title ? false : strpos($admin_title, $title);
            if (false === $at) {
                return $admin_title;
            }
            return substr_replace($admin_title, self::screen_title($title, $section), $at, strlen($title));
        }, 10, 2);
    }

    /**
     * Name the settings screen's tab shown in the browser title
     * (title_section()). Runs on the screen's load-{hook} action, on each
     * site and on the network screen (multisite).
     */
    public static function title_tab() {
        self::title_section(self::tab_label(SEOProStack_Admin_Manager::get_active_tab(), SEOProStack_Admin_Manager::get_tabs()));
    }

    /**
     * A screen's name followed by a section's, as the browser title shows
     * them: "SEO Pro Stack: General".
     *
     * @param string $screen  The screen's name.
     * @param string $section The section's name.
     * @return string
     */
    public static function screen_title($screen, $section) {
        /* translators: Browser title of a section of an admin screen. 1: the screen's name, 2: the section's name. */
        return sprintf(__('%1$s: %2$s', 'seoprostack'), $screen, $section);
    }

    /**
     * Name of a tab (or of the search results), as plain text.
     *
     * @param string $tab  Tab slug.
     * @param array  $tabs Tabs (SEOProStack_Admin_Manager::get_tabs()).
     * @return string Empty for an unknown tab.
     */
    public static function tab_label($tab, array $tabs) {
        if (SEOProStack_Admin_Manager::SEARCH === $tab) {
            return __('Search results', 'seoprostack');
        }
        return isset($tabs[$tab]['label']) ? trim(wp_strip_all_tags((string) $tabs[$tab]['label'])) : '';
    }

    /**
     * Browser title of each tab drawn on the page, as the browser shows it
     * (entities decoded), so the script can name the tab it switches to.
     *
     * @param string[] $page_tabs Tabs drawn on the page (SEOProStack_Admin_Manager::page_tabs()).
     * @param array    $tabs      Tabs (SEOProStack_Admin_Manager::get_tabs()).
     * @return array<string,string>
     */
    public static function page_titles(array $page_tabs, array $tabs) {
        $screen = wp_strip_all_tags((string) get_admin_page_title());
        $titles = array();
        foreach ($page_tabs as $slug) {
            $label = self::tab_label($slug, $tabs);
            if ('' !== $screen && '' !== $label) {
                $titles[$slug] = html_entity_decode(self::screen_title($screen, $label), ENT_QUOTES, 'UTF-8');
            }
        }
        return $titles;
    }

    /**
     * Render one tab's panel. The script shows another tab's panel when its
     * link is chosen.
     *
     * @param string   $slug   Tab slug.
     * @param callable $render The tab's render callback.
     * @param bool     $shown  Whether it is the active tab.
     * @param bool     $wide   Whether the tab uses the whole width (its 'wide' key).
     */
    public static function panel($slug, $render, $shown, $wide = false) {
        // Not <main>: core's #wpbody already carries role="main".
        ?>
        <div class="sps-main<?php echo $wide ? ' sps-main--wide' : ''; ?> sps-tab-<?php echo esc_attr($slug); ?>" id="sps-tab-<?php echo esc_attr($slug); ?>" data-sps-panel="<?php echo esc_attr($slug); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
            <?php call_user_func($render); ?>
        </div>
        <?php
    }

    /**
     * Render the tab navigation: one labelled group of links per section
     * that has tabs. Links to tabs drawn on the page carry their slug, for
     * the script. Tabs with the same 'menu' name show as one tab with that
     * name, opening the first of them, and, while one of them is shown, as a
     * second row of tabs under the bar (menu_row()).
     *
     * @param array    $tabs      Tabs (SEOProStack_Admin_Manager::get_tabs()).
     * @param string   $active    Active tab slug.
     * @param string[] $page_tabs Tabs drawn on the page (SEOProStack_Admin_Manager::page_tabs()).
     */
    public static function nav(array $tabs, $active, array $page_tabs) {
        $groups = array(
            'settings' => __('Settings', 'seoprostack'),
            'discover' => __('Discover', 'seoprostack'),
            'about'    => __('About', 'seoprostack'),
        );
        ?>
        <nav class="sps-nav" aria-label="<?php esc_attr_e('SEO Pro Stack sections', 'seoprostack'); ?>">
            <?php
            foreach ($groups as $group => $group_label) {
                $group_tabs = array_filter($tabs, function ($tab) use ($group) {
                    return isset($tab['group']) && $tab['group'] === $group;
                });
                if ($group_tabs) {
                    self::nav_group($group_label, $group_tabs, $active, $page_tabs);
                }
            }
            ?>
        </nav>
        <?php
        $menu = isset($tabs[$active]['menu']) ? (string) $tabs[$active]['menu'] : '';
        if ('' !== $menu) {
            self::menu_row($menu, self::menu_tabs($tabs, $menu), $active, $page_tabs);
        }
    }

    /**
     * Render the sections of one of the plugin's own screens as the settings
     * screen's tabs (SEOProStack_Admin_Manager::render_header()): a
     * `sps-nav` of `sps-nav__tab` links, the one shown `is-active` with
     * aria-current="page". Each link loads its section's page; name the
     * section shown in the browser title with title_section().
     *
     * @param array<string,array{label:string,url:string}> $sections Sections in order, by slug: each its label (plain text) and URL.
     * @param string                                       $current  Slug of the section shown.
     * @param string                                       $label    The navigation's accessible name; empty for "SEO Pro Stack sections".
     */
    public static function sections_nav(array $sections, $current, $label = '') {
        $label = '' === (string) $label ? __('SEO Pro Stack sections', 'seoprostack') : (string) $label;
        ?>
        <nav class="sps-nav" aria-label="<?php echo esc_attr($label); ?>">
            <?php foreach ($sections as $slug => $section) : ?>
                <?php
                $shown = (string) $slug === (string) $current;
                $text  = isset($section['label']) ? (string) $section['label'] : '';
                $url   = isset($section['url']) ? (string) $section['url'] : '';
                if ('' === $text || '' === $url) {
                    continue;
                }
                ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="sps-nav__tab<?php echo $shown ? ' is-active' : ''; ?>"
                   <?php echo $shown ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($text); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Tabs with the given 'menu' name, in navigation order.
     *
     * @param array  $tabs Tabs.
     * @param string $menu Menu name.
     * @return array
     */
    private static function menu_tabs(array $tabs, $menu) {
        return array_filter($tabs, function ($tab) use ($menu) {
            return isset($tab['menu']) && (string) $tab['menu'] === $menu;
        });
    }

    /**
     * Render one navigation group. A group of links, not form controls, so
     * role="group" with a label rather than <fieldset>. A menu's tabs show
     * as one link, where the first of them is; data-sps-tabs lists them, so
     * the script marks it current with any of them.
     *
     * @param string   $label     Group label.
     * @param array    $tabs      The group's tabs.
     * @param string   $active    Active tab slug.
     * @param string[] $page_tabs Tabs drawn on the page.
     */
    private static function nav_group($label, array $tabs, $active, array $page_tabs) {
        $menus = array();
        ?>
        <div class="sps-nav__group" role="group" aria-label="<?php echo esc_attr($label); ?>"> <?php // NOSONAR: a labelled group of navigation links, not a fieldset of form controls (S6819). ?>
            <?php foreach ($tabs as $slug => $tab) : ?>
                <?php
                $slug    = (string) $slug;
                $menu    = isset($tab['menu']) ? (string) $tab['menu'] : '';
                $members = array($slug);
                if ('' !== $menu) {
                    if (isset($menus[$menu])) {
                        continue;
                    }
                    $menus[$menu] = true;
                    $members      = array_map('strval', array_keys(self::menu_tabs($tabs, $menu)));
                }
                self::nav_link($slug, $tab, $menu, $members, $active, $page_tabs);
                ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a navigation link after the group's menu deduplication.
     *
     * @param string   $slug      First tab slug.
     * @param array    $tab       First tab.
     * @param string   $menu      Menu label, or empty for a single tab.
     * @param string[] $members   Tabs represented by this link.
     * @param string   $active    Active tab slug.
     * @param string[] $page_tabs Tabs drawn on the page.
     */
    private static function nav_link($slug, array $tab, $menu, array $members, $active, array $page_tabs) {
        $current      = in_array($active, $members, true);
        $aria_current = '' === $menu ? 'page' : 'true';
        ?>
        <a href="<?php echo esc_url(SEOProStack_Admin_Manager::tab_url($slug)); ?>"
           class="sps-nav__tab<?php echo $current ? ' is-active' : ''; ?>"
           <?php echo '' === $menu && in_array($slug, $page_tabs, true) ? 'data-sps-tab="' . esc_attr($slug) . '"' : ''; ?>
           <?php echo '' !== $menu ? 'data-sps-tabs="' . esc_attr(implode(' ', $members)) . '"' : ''; ?>
           <?php echo $current ? 'aria-current="' . esc_attr($aria_current) . '"' : ''; ?>>
            <?php echo esc_html('' === $menu ? $tab['label'] : $menu); ?>
        </a>
        <?php
    }

    /**
     * Render the second row of tabs: the tabs of the menu shown.
     *
     * @param string   $menu      Menu name.
     * @param array    $tabs      The menu's tabs.
     * @param string   $active    Active tab slug.
     * @param string[] $page_tabs Tabs drawn on the page.
     */
    private static function menu_row($menu, array $tabs, $active, array $page_tabs) {
        ?>
        <nav class="sps-subnav" aria-label="<?php echo esc_attr($menu); ?>" data-sps-tabs="<?php echo esc_attr(implode(' ', array_map('strval', array_keys($tabs)))); ?>">
            <?php foreach ($tabs as $slug => $tab) : ?>
                <?php $slug = (string) $slug; ?>
                <a href="<?php echo esc_url(SEOProStack_Admin_Manager::tab_url($slug)); ?>"
                   class="sps-subnav__tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
                   <?php echo in_array($slug, $page_tabs, true) ? 'data-sps-tab="' . esc_attr($slug) . '"' : ''; ?>
                   <?php echo $slug === $active ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}
