<?php
/**
 * TaxoPress' template functions, kept for themes that call them after
 * TaxoPress is deactivated (SEOProStack_Term_Legacy). They take the same
 * arguments and draw with SEO Pro Stack's Term list and Related posts.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.11.11
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- TaxoPress' public function names, kept on purpose.

/**
 * TaxoPress arguments as an array, title shown only when given.
 *
 * @param string|array $args Query string or array.
 * @return array
 */
function seoprostack_taxopress_args($args) {
    $args = wp_parse_args($args);
    if (!isset($args['title'])) {
        $args['hide_title'] = 1;
    }
    return $args;
}

if (!function_exists('st_get_tag_cloud')) {
    /**
     * @param string|array $args TaxoPress tag cloud arguments.
     * @return string
     */
    function st_get_tag_cloud($args = '') {
        return SEOProStack_Term_Legacy::taxopress_render('cloud', seoprostack_taxopress_args($args));
    }
}

if (!function_exists('st_tag_cloud')) {
    /**
     * @param string|array $args TaxoPress tag cloud arguments.
     */
    function st_tag_cloud($args = '') {
        echo st_get_tag_cloud($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
    }
}

if (!function_exists('st_get_the_tags')) {
    /**
     * @param string|array $args TaxoPress post terms arguments.
     * @return string
     */
    function st_get_the_tags($args = '') {
        $args = seoprostack_taxopress_args($args);
        $args += array('format' => 'comma', 'before' => __('Tags: ', 'seoprostack'));
        return SEOProStack_Term_Legacy::taxopress_render('post', $args);
    }
}

if (!function_exists('st_the_tags')) {
    /**
     * @param string|array $args TaxoPress post terms arguments.
     */
    function st_the_tags($args = '') {
        echo st_get_the_tags($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
    }
}

if (!function_exists('st_get_related_posts')) {
    /**
     * @param string|array $args TaxoPress related posts arguments.
     * @return string
     */
    function st_get_related_posts($args = '') {
        $args = seoprostack_taxopress_args($args);
        $args += array('format' => 'list');
        return SEOProStack_Term_Legacy::taxopress_render('related', $args);
    }
}

if (!function_exists('st_related_posts')) {
    /**
     * @param string|array $args TaxoPress related posts arguments.
     */
    function st_related_posts($args = '') {
        echo st_get_related_posts($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
    }
}
