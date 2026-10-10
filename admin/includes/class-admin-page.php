<?php
/**
 * SEO Pro Stack admin screen markup.
 *
 * The header, the tab navigation and the tab panels of the settings screen
 * (SEOProStack_Admin_Manager::render_settings_page()). The plugin's own screens
 * show the header through SEOProStack_Admin_Manager::render_header().
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
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
                </div>
                <?php
                if (current_user_can('manage_options')) {
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
     * Render one tab's panel. The script shows another tab's panel when its
     * link is chosen.
     *
     * @param string   $slug   Tab slug.
     * @param callable $render The tab's render callback.
     * @param bool     $shown  Whether it is the active tab.
     */
    public static function panel($slug, $render, $shown) {
        // Not <main>: core's #wpbody already carries role="main".
        ?>
        <div class="sps-main sps-tab-<?php echo esc_attr($slug); ?>" id="sps-tab-<?php echo esc_attr($slug); ?>" data-sps-panel="<?php echo esc_attr($slug); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
            <?php call_user_func($render); ?>
        </div>
        <?php
    }

    /**
     * Render the tab navigation: one labelled group of links per section
     * that has tabs. Links to tabs drawn on the page carry their slug, for
     * the script.
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
    }

    /**
     * Render one navigation group. A group of links, not form controls, so
     * role="group" with a label rather than <fieldset>.
     *
     * @param string   $label     Group label.
     * @param array    $tabs      The group's tabs.
     * @param string   $active    Active tab slug.
     * @param string[] $page_tabs Tabs drawn on the page.
     */
    private static function nav_group($label, array $tabs, $active, array $page_tabs) {
        ?>
        <div class="sps-nav__group" role="group" aria-label="<?php echo esc_attr($label); ?>">
            <?php foreach ($tabs as $slug => $tab) : ?>
                <?php $slug = (string) $slug; ?>
                <a href="<?php echo esc_url(SEOProStack_Admin_Manager::tab_url($slug)); ?>"
                   class="sps-nav__tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
                   <?php echo in_array($slug, $page_tabs, true) ? 'data-sps-tab="' . esc_attr($slug) . '"' : ''; ?>
                   <?php echo $slug === $active ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
