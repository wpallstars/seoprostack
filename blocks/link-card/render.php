<?php
/**
 * Server render for the seoprostack/link-card block.
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

echo SEOProStack_Link_Cards::render_block((array) $attributes); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped when built.
