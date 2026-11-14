<?php
/**
 * Brand assets page WP-CLI commands.
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

class SEOProStack_Brand_Assets_CLI {

    /**
     * Show the setting, the page, the footer link and what the page reads.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostack brand-assets status --url=example.com
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Flags.
     */
    public function status($args, $assoc_args) {
        $page     = SEOProStack_Brand_Assets::page();
        $logos    = SEOProStack_Brand_Assets::logos();
        $branding = SEOProStack_Brand_Assets::branding();
        $palettes = SEOProStack_Brand_Assets::palettes();
        $fonts    = SEOProStack_Brand_Assets::fonts();
        $footer   = $page ? SEOProStack_Brand_Assets::add_footer_link(true) : array('status' => 'no-page', 'message' => '');
        $rows     = array(
            array('item' => 'setting', 'value' => SEOProStack_Brand_Assets::switched_on() ? 'on' : 'off'),
            array('item' => 'kadence', 'value' => SEOProStack_Brand_Assets::applies() ? 'yes' : 'no'),
            array('item' => 'page', 'value' => $page ? get_permalink($page) . ' (' . $page->post_status . ', ' . (has_block(SEOProStack_Brand_Assets::BLOCK, $page) ? 'block' : 'no block') . ')' : 'none'),
            array('item' => 'footer link', 'value' => $footer['status']),
            array('item' => 'header', 'value' => implode(' + ', $branding['parts']) . ' (' . $branding['direction'] . ')'),
            array('item' => 'logos', 'value' => $logos ? implode(', ', array_keys($logos)) : 'none'),
            array('item' => 'palettes', 'value' => implode(', ', array_map(function ($mode, $set) {
                return $mode . ' ' . count($set);
            }, array_keys($palettes), $palettes))),
            array('item' => 'fonts', 'value' => $fonts['heading']['name'] . ' / ' . $fonts['body']['name']),
        );
        WP_CLI\Utils\format_items(isset($assoc_args['format']) ? $assoc_args['format'] : 'table', $rows, array('item', 'value'));
    }

    /**
     * Make the Brand assets page and, with --footer, link it after About.
     *
     * Switches Brand assets page on for this site when it is off. An
     * existing page at /brand-assets/ without the block is left alone
     * unless --replace is given (a revision keeps its content).
     *
     * ## OPTIONS
     *
     * [--footer]
     * : Also add a link to the page after the About link in the footer's
     * block widgets or footer menu, once.
     *
     * [--replace]
     * : Put the block in an existing page at /brand-assets/ that lacks it.
     *
     * [--dry-run]
     * : Say what would change, and change nothing.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack brand-assets create --footer --dry-run --url=example.com
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Flags.
     */
    public function create($args, $assoc_args) {
        $dry_run = !empty($assoc_args['dry-run']);
        if (!SEOProStack_Brand_Assets::applies()) {
            WP_CLI::warning('Kadence is not the active theme; the page will say so until it is.');
        }
        if (!SEOProStack_Brand_Assets::switched_on()) {
            if ($dry_run) {
                WP_CLI::log('Would switch on Brand assets page.');
            } else {
                $saved = SEOProStack_Settings::set(SEOProStack_Brand_Assets::KEY, true);
                if (is_wp_error($saved)) {
                    WP_CLI::error('Brand assets page could not be switched on: ' . $saved->get_error_message());
                }
                WP_CLI::log('Switched on Brand assets page.');
            }
        }
        $page = SEOProStack_Brand_Assets::create_page(!empty($assoc_args['replace']), $dry_run);
        if ('error' === $page['status']) {
            WP_CLI::error($page['message']);
        }
        WP_CLI::log($page['message']);
        if (empty($assoc_args['footer'])) {
            return;
        }
        if ($dry_run && 'would-create' === $page['status']) {
            WP_CLI::log('Would then look for the footer\'s About link.');
            return;
        }
        $footer = SEOProStack_Brand_Assets::add_footer_link($dry_run);
        if ('error' === $footer['status']) {
            WP_CLI::error($footer['message']);
        }
        if ('no-about' === $footer['status'] || 'no-page' === $footer['status']) {
            WP_CLI::warning($footer['message']);
            return;
        }
        WP_CLI::log($footer['message']);
    }
}
