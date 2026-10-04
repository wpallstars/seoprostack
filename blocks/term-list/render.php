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

$seoprostack_attributes = (array) $attributes;
if (isset($block->context['postId'])) {
    $seoprostack_attributes['postId'] = (int) $block->context['postId'];
}
echo SEOProStack_Term_List::render($seoprostack_attributes); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
