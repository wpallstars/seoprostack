<?php
/**
 * Server render for the seoprostack/link-card block.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner content (unused).
 * @var WP_Block $block      Block instance.
 */

if (!defined('ABSPATH')) {
    exit;
}

echo SEOProStack_Link_Card_Block::render_block((array) $attributes); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped when built.
