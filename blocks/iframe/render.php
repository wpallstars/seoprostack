<?php
/**
 * Server render for the seoprostack/iframe block.
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

if (!SEOProStack_Iframe_Block::author_allowed(isset($block) ? $block : null)) {
    return;
}

$seoprostack_iframe = SEOProStack_Iframe_Block::iframe_attributes((array) $attributes);
if (!$seoprostack_iframe) {
    return;
}

$seoprostack_classes = array('wp-block-seoprostack-iframe__frame');
if (!empty($attributes['showBorder'])) {
    $seoprostack_classes[] = 'has-border';
}
$seoprostack_iframe['class'] = implode(' ', $seoprostack_classes);

$seoprostack_html = '';
foreach ($seoprostack_iframe as $seoprostack_name => $seoprostack_value) {
    $seoprostack_html .= '' === $seoprostack_value
        ? ' ' . esc_attr($seoprostack_name)
        : sprintf(' %s="%s"', esc_attr($seoprostack_name), 'src' === $seoprostack_name ? esc_url($seoprostack_value) : esc_attr($seoprostack_value));
}
?>
<figure <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- core-escaped wrapper attributes. ?>>
    <iframe<?php echo $seoprostack_html; // phpcs:ignore WordPress.Security.EscapeOutput -- each attribute escaped above. ?>></iframe>
</figure>
