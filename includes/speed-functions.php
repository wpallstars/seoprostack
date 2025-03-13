<?php
/**
 * Speed optimization functions
 */

function wpa_superstar_lazy_load_images( $content ) {
    if ( is_admin() || ! $content || ! get_option( 'wpa_superstar_lazy_load', 1 ) ) {
        return $content;
    }
    $content = preg_replace(
        '/(<img[^>]+)\/?>/i',
        '$1 loading="lazy" />',
        $content
    );
    return $content;
}
add_filter( 'the_content', 'wpa_superstar_lazy_load_images' );
add_filter( 'wp_get_attachment_image', 'wpa_superstar_lazy_load_images' );

function wpa_superstar_minify_css( $html ) {
    if ( is_admin() || ! get_option( 'wpa_superstar_minify_css', 0 ) ) {
        return $html;
    }
    $html = preg_replace(
        array( '/\s+/', '/\/\*.*?\*\//s', '/;}/' ),
        array( ' ', '', '}' ),
        $html
    );
    return trim( $html );
}
add_filter( 'style_loader_tag', 'wpa_superstar_minify_css' );