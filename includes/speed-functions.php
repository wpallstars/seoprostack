<?php
/**
 * Speed optimization functions for WPA Superstar plugin
 *
 * @package WPA_Superstar
 * @since 1.0.0
 */

// If this file is called directly, abort
if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
    // Still allow the file to be loaded for IDE analysis
    defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
    defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( dirname( __FILE__ ) ) . '/' );
    defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

/**
 * Logging function for the plugin
 *
 * @param string $message The message to log
 * @return void
 */
function wpa_superstar_log( $message ) {
    // Safe check for WordPress constants
    if ( defined( 'WP_CONTENT_DIR' ) ) {
        $log_file = WP_CONTENT_DIR . '/wpa-superstar.log';
        $time = function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' );
        @file_put_contents( $log_file, "[$time] $message\n", FILE_APPEND );
    }
}

/**
 * Add lazy loading attribute to images to improve page load speed
 *
 * @param string $content The content to process
 * @return string The processed content with lazy loading
 */
function wpa_superstar_lazy_load_images( $content ) {
    // Early return if admin or option disabled
    if ( ! $content || 
         ( function_exists( 'is_admin' ) && is_admin() ) || 
         ( function_exists( 'get_option' ) && ! get_option( 'wpa_superstar_lazy_load', 1 ) ) 
       ) {
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

// Register the lazy load filters if add_filter exists
if ( function_exists( 'add_filter' ) ) {
    add_filter( 'the_content', 'wpa_superstar_lazy_load_images' );
    add_filter( 'wp_get_attachment_image', 'wpa_superstar_lazy_load_images' );
}

/**
 * Advanced CSS minification function
 * 
 * This function takes CSS content and returns a minified version by:
 * - Removing comments
 * - Removing whitespace
 * - Removing unnecessary semicolons
 * - Shortening color values
 * - Optimizing zero values
 * 
 * @param string $css The CSS content to minify
 * @return string The minified CSS content
 */
function wpa_superstar_minify_css_content( $css ) {
    if ( empty( $css ) ) {
        return $css;
    }
    
    try {
        // Remove comments
        $css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );
        
        // Remove whitespace around key characters
        $css = preg_replace( '/\s*([{}:;,])\s*/', '$1', $css );
        
        // Remove trailing whitespace
        $css = preg_replace( '/\s+/', ' ', $css );
        
        // Remove unnecessary semicolons
        $css = str_replace( ';}', '}', $css );
        
        // Convert rgb to hex where shorter
        $css = preg_replace_callback( '/rgb\s*\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)\s*\)/i', function( $matches ) {
            $r = intval( $matches[1] );
            $g = intval( $matches[2] );
            $b = intval( $matches[3] );
            
            // Only convert if the hex representation would be shorter
            if ( $r < 256 && $g < 256 && $b < 256 ) {
                $hex = sprintf( "#%02x%02x%02x", $r, $g, $b );
                // Check if it can be shortened to #XXX format
                if ( preg_match( '/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', $hex ) ) {
                    $hex = preg_replace( '/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', '#$1$2$3', $hex );
                }
                return $hex;
            }
            return $matches[0];
        }, $css );
        
        // Optimize zero values
        $css = preg_replace( '/([:\s])0(?:px|pt|em|rem|%|in|cm|mm|pc|ex|vw|vh|vmin|vmax)/', '$10', $css );
        
        // Shorten multiple zeros
        $css = preg_replace( '/\b0 0 0 0\b/', '0', $css );
        $css = preg_replace( '/\b0 0 0\b/', '0', $css );
        $css = preg_replace( '/\b0 0\b/', '0', $css );
        
        // Shorten colors where possible (e.g., #ffffff to #fff)
        $css = preg_replace( '/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', '#$1$2$3', $css );
        
    } catch ( Exception $e ) {
        wpa_superstar_log( "CSS content minify error: " . $e->getMessage() );
        return $css; // Return original on error
    }
    
    return trim( $css );
}

/**
 * Minify CSS in HTML content to reduce file size
 *
 * @param string $html The HTML content to process
 * @return string The processed HTML with minified CSS
 */
function wpa_superstar_minify_css( $html ) {
    // Early return if admin or option disabled
    if ( ( function_exists( 'is_admin' ) && is_admin() ) || 
         ( function_exists( 'get_option' ) && ! get_option( 'wpa_superstar_minify_css', 0 ) ) 
       ) {
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

// Register the CSS minify filter if add_filter exists
if ( function_exists( 'add_filter' ) ) {
    add_filter( 'style_loader_tag', 'wpa_superstar_minify_css' );
}

/**
 * Minify JavaScript in HTML content to reduce file size
 *
 * @param string $html The HTML content to process
 * @return string The processed HTML with minified JavaScript
 */
function wpa_superstar_minify_js( $html ) {
    // Early return if admin or option disabled
    if ( ( function_exists( 'is_admin' ) && is_admin() ) || 
         ( function_exists( 'get_option' ) && ! get_option( 'wpa_superstar_minify_js', 0 ) ) 
       ) {
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

// Register the JS minify filter if add_filter exists
if ( function_exists( 'add_filter' ) ) {
    add_filter( 'script_loader_tag', 'wpa_superstar_minify_js' );
}

/**
 * Minify inline CSS in HTML content
 * 
 * @param string $html The HTML content containing style tags
 * @return string The HTML with minified inline CSS
 */
function wpa_superstar_minify_inline_css( $html ) {
    // Early return if admin or option disabled
    if ( ( function_exists( 'is_admin' ) && is_admin() ) || 
         ( function_exists( 'get_option' ) && ! get_option( 'wpa_superstar_minify_inline_css', 0 ) ) 
       ) {
        return $html;
    }
    
    try {
        // Find and minify all inline style blocks
        $html = preg_replace_callback( 
            '/<style[^>]*>(.*?)<\/style>/si',
            function( $matches ) {
                $minified_css = wpa_superstar_minify_css_content( $matches[1] );
                return '<style>' . $minified_css . '</style>';
            },
            $html
        );
    } catch ( Exception $e ) {
        wpa_superstar_log( "Inline CSS minify error: " . $e->getMessage() );
    }
    
    return $html;
}

// Apply the inline CSS minify filters if add_filter exists
if ( function_exists( 'add_filter' ) ) {
    add_filter( 'wp_head', 'wpa_superstar_minify_inline_css', 999 );
    add_filter( 'wp_footer', 'wpa_superstar_minify_inline_css', 999 );
}
