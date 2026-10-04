<?php
/**
 * Read Me content for the SEO Pro Stack admin tab (from README.md).
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

/**
 * README.md contents, with a short fallback if the file is missing.
 *
 * @return array{title:string,content:string}
 */
function seoprostack_get_readme_content() {
    $readme_path = SEOPROSTACK_DIR . 'README.md';

    if (is_readable($readme_path)) {
        $content = (string) file_get_contents($readme_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
    } else {
        $content = "# SEO Pro Stack\n\nVersion: " . SEOPROSTACK_VERSION;
    }

    return array(
        'title'   => __('Read Me', 'seoprostack'),
        'content' => $content,
    );
}
