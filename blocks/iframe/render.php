<?php
/**
 * Server render for the allstars/iframe block.
 *
 * @package Allstars
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner content (unused).
 * @var WP_Block $block      Block instance.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!Allstars_Iframe_Block::author_allowed(isset($block) ? $block : null)) {
    return;
}

$allstars_iframe = Allstars_Iframe_Block::iframe_attributes((array) $attributes);
if (!$allstars_iframe) {
    return;
}

$allstars_classes = array('wp-block-allstars-iframe__frame');
if (!empty($attributes['showBorder'])) {
    $allstars_classes[] = 'has-border';
}
$allstars_iframe['class'] = implode(' ', $allstars_classes);

$allstars_html = '';
foreach ($allstars_iframe as $allstars_name => $allstars_value) {
    $allstars_html .= '' === $allstars_value
        ? ' ' . esc_attr($allstars_name)
        : sprintf(' %s="%s"', esc_attr($allstars_name), 'src' === $allstars_name ? esc_url($allstars_value) : esc_attr($allstars_value));
}
?>
<figure <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- core-escaped wrapper attributes. ?>>
    <iframe<?php echo $allstars_html; // phpcs:ignore WordPress.Security.EscapeOutput -- each attribute escaped above. ?>></iframe>
</figure>
