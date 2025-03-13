<?php
/**
 * Speed optimization functions
 */

// Logging function
function wpa_superstar_log( $message ) {
    $log_file = WP_CONTENT_DIR . '/wpa-superstar.log';
    $time = current_time( 'mysql' );
    file_put_contents( $log_file, "[$time] $message\n", FILE_APPEND );
}

function wpa_superstar_lazy_load_images( $content ) {
    if ( is_admin() || ! $content || ! get_option( 'wpa_superstar_lazy_load', 1 ) ) {
        return $content;
    }
    try {
        $content = preg_replace(
            '/(<img[^>]+)\/?>/i',
            '$1 loading="lazy" />',
            $content
        );
    } catch ( Exception $e ) {
        wpa_superstar_log( "Lazy load error: " . $e->getMessage() );
    }
    return $content;
}
add_filter( 'the_content', 'wpa_superstar_lazy_load_images' );
add_filter( 'wp_get_attachment_image', 'wpa_superstar_lazy_load_images' );

function wpa_superstar_minify_css( $html ) {
    if ( is_admin() || ! get_option( 'wpa_superstar_minify_css', 0 ) ) {
        return $html;
    }
    try {
        $html = preg_replace(
            array( '/\s+/', '/\/\*.*?\*\//s', '/;}/' ),
            array( ' ', '', '}' ),
            $html
        );
    } catch ( Exception $e ) {
        wpa_superstar_log( "CSS minify error: " . $e->getMessage() );
    }
    return trim( $html );
}
add_filter( 'style_loader_tag', 'wpa_superstar_minify_css' );

function wpa_superstar_minify_js( $html ) {
    if ( is_admin() || ! get_option( 'wpa_superstar_minify_js', 0 ) ) {
        return $html;
    }
    try {
        $html = preg_replace(
            array( '/\s+/', '/\/\*.*?\*\//s', '//.*?\n/' ),
            array( ' ', '', '' ),
            $html
        );
    } catch ( Exception $e ) {
        wpa_superstar_log( "JS minify error: " . $e->getMessage() );
    }
    return trim( $html );
}
add_filter( 'script_loader_tag', 'wpa_superstar_minify_js' );