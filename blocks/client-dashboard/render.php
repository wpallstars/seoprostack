<?php
/**
 * Server render for the seoprostack/client-dashboard block.
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

$seoprostack_show = array();
foreach (SEOProStack_Agency_Dashboard::PARTS as $seoprostack_part) {
    $seoprostack_attr = 'show' . ucfirst($seoprostack_part);
    if (!isset($attributes[$seoprostack_attr]) || $attributes[$seoprostack_attr]) {
        $seoprostack_show[] = $seoprostack_part;
    }
}

$seoprostack_args = array(
    'show'      => $seoprostack_show,
    'orderPage' => isset($attributes['orderPage']) ? (string) $attributes['orderPage'] : '',
    'callPage'  => isset($attributes['callPage']) ? (string) $attributes['callPage'] : '',
    'wrapper'   => get_block_wrapper_attributes(),
);
echo SEOProStack_Agency_Dashboard::render($seoprostack_args); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
