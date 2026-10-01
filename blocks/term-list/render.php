<?php
/**
 * Server render for the seoprostack/term-list block.
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

echo SEOProStack_Spectra_Blocks::render_terms((array) $attributes); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
