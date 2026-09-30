<?php
/**
 * Server render for the seoprostack/screenshot block.
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

echo SEOProStack_Screenshots::render_block((array) $attributes, isset($block) ? $block : null); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped when built.
