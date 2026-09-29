<?php
/**
 * Read Me content for the WP Allstars admin tab (from README.md).
 *
 * @package WP_ALLSTARS
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * README.md contents, with a short fallback if the file is missing.
 *
 * @return array{title:string,content:string}
 */
function wp_allstars_get_readme_content() {
    $readme_path = WP_ALLSTARS_DIR . 'README.md';

    if (is_readable($readme_path)) {
        $content = (string) file_get_contents($readme_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
    } else {
        $content = "# WP Allstars\n\nCurated plugins, themes, hosting and workflow tools for WordPress.\n\nVersion: {WP_ALLSTARS_VERSION}";
    }

    return array(
        'title'   => __('Read Me', 'wp-allstars'),
        'content' => $content,
    );
}
