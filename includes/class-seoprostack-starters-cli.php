<?php
/**
 * WP-CLI commands for starter data: `wp seoprostack starters …`.
 *
 * @package SEOProStack
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * List, show, add and remove starter data: SEO Pro Stack's example lists,
 * tags, fields and boards for other plugins.
 *
 * Adding never changes or removes what is already there. Removing takes away
 * only what SEO Pro Stack added, and only while it is unused.
 */
class SEOProStack_Starters_CLI {

    /**
     * List the starters, what this site is missing and what was added.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function list_($args, $assoc_args) {
        unset($args);
        $rows = array();
        foreach (SEOProStack_Starters::all() as $slug => $starter) {
            $ready  = SEOProStack_Starters::ready($slug);
            $rows[] = array(
                'plugin'  => $slug,
                'name'    => $starter['name'],
                'tested'  => $starter['tested'],
                'active'  => $ready ? 'yes' : 'no',
                'missing' => $ready ? SEOProStack_Starters::missing_count($slug) : '',
                'added'   => SEOProStack_Starters::added_count($slug),
            );
        }
        WP_CLI\Utils\format_items(isset($assoc_args['format']) ? $assoc_args['format'] : 'table', $rows, array('plugin', 'name', 'tested', 'active', 'missing', 'added'));
    }

    /**
     * Show the starter items this site does not have yet.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin folder, such as fluent-crm.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function diff($args, $assoc_args) {
        unset($assoc_args);
        $slug = $this->starter_slug($args[0]);
        if (!SEOProStack_Starters::ready($slug)) {
            WP_CLI::error(sprintf('%s is not active.', $slug));
        }
        $rows = array();
        foreach (SEOProStack_Starters::missing($slug) as $names) {
            foreach ($names as $name) {
                $rows[] = array('missing' => $name);
            }
        }
        if (!$rows) {
            WP_CLI::success('This site has every starter item.');
            return;
        }
        WP_CLI\Utils\format_items('table', $rows, array('missing'));
    }

    /**
     * Add the starter items a plugin is missing. Items already there stay as they are.
     *
     * ## OPTIONS
     *
     * [<plugin>...]
     * : Plugin folders.
     *
     * [--all]
     * : Every active plugin with starter data.
     *
     * [--user=<id|login|email>]
     * : WP-CLI's global option. Boards are created by this user; without it, by the first administrator.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function add($args, $assoc_args) {
        if (WP_CLI\Utils\get_flag_value($assoc_args, 'all')) {
            $args = array_values(array_filter(array_keys(SEOProStack_Starters::all()), array('SEOProStack_Starters', 'ready')));
        }
        if (!$args) {
            WP_CLI::error('Name a plugin, or use --all.');
        }
        foreach ($args as $slug) {
            $result = SEOProStack_Starters::add($slug);
            if (is_wp_error($result)) {
                WP_CLI::warning($slug . ': ' . $result->get_error_message());
                continue;
            }
            WP_CLI::success(sprintf('%s: %d %s added.', $slug, $result, 1 === (int) $result ? 'item' : 'items'));
        }
    }

    /**
     * Remove what SEO Pro Stack added, where it is still unused.
     *
     * Lists and tags with contacts, contact fields with values, boards with
     * tasks and settings changed since are kept.
     *
     * ## OPTIONS
     *
     * <plugin>...
     * : Plugin folders.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function remove($args, $assoc_args) {
        unset($assoc_args);
        foreach ($args as $slug) {
            $result = SEOProStack_Starters::remove($slug);
            if (is_wp_error($result)) {
                WP_CLI::warning($slug . ': ' . $result->get_error_message());
                continue;
            }
            WP_CLI::success(sprintf('%s: %d %s removed.', $slug, $result['removed'], 1 === (int) $result['removed'] ? 'item' : 'items'));
            if ($result['kept']) {
                WP_CLI::log(sprintf('Kept, in use or changed: %s.', implode(', ', $result['kept'])));
            }
        }
    }

    /**
     * A plugin folder that has starter data, or stop.
     *
     * @param string $slug Plugin folder.
     * @return string
     */
    private function starter_slug($slug) {
        if (!SEOProStack_Starters::get($slug)) {
            WP_CLI::error(sprintf('There is no starter data for %s. See wp seoprostack starters list.', $slug));
        }
        return $slug;
    }
}
