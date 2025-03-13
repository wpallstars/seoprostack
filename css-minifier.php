<?php
/**
 * CSS Minifier Function
 * 
 * A standalone PHP function to minify CSS content by:
 * - Removing comments
 * - Removing whitespace and line breaks
 * - Removing unnecessary semicolons
 * - Optimizing colors
 * - Shortening zero values
 * 
 * @author WPA Superstar Plugin
 * @version 1.0.0
 */

/**
 * Minifies CSS content
 * 
 * @param string $css The CSS content to minify
 * @return string The minified CSS content
 */
function minify_css($css) {
    if (empty($css)) {
        return $css;
    }
    
    try {
        // Remove comments
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);
        
        // Remove whitespace around key characters
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
        
        // Remove newlines, tabs, and extra spaces
        $css = preg_replace('/\s+/', ' ', $css);
        
        // Remove unnecessary semicolons
        $css = str_replace(';}', '}', $css);
        
        // Convert rgb to hex where shorter
        $css = preg_replace_callback(
            '/rgb\s*\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)\s*\)/i',
            function($matches) {
                $r = intval($matches[1]);
                $g = intval($matches[2]);
                $b = intval($matches[3]);
                
                // Only convert if the hex representation would be shorter
                if ($r < 256 && $g < 256 && $b < 256) {
                    $hex = sprintf("#%02x%02x%02x", $r, $g, $b);
                    // Check if it can be shortened to #XXX format
                    if (preg_match('/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', $hex)) {
                        $hex = preg_replace('/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', '#$1$2$3', $hex);
                    }
                    return $hex;
                }
                return $matches[0];
            },
            $css
        );
        
        // Optimize zero values (remove units)
        $css = preg_replace('/([:\s])0(?:px|pt|em|rem|%|in|cm|mm|pc|ex|vw|vh|vmin|vmax)/', '$10', $css);
        
        // Shorten multiple zeros
        $css = preg_replace('/\b0 0 0 0\b/', '0', $css);
        $css = preg_replace('/\b0 0 0\b/', '0', $css);
        $css = preg_replace('/\b0 0\b/', '0', $css);
        
        // Shorten colors where possible (e.g., #ffffff to #fff)
        $css = preg_replace('/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', '#$1$2$3', $css);
        
    } catch (Exception $e) {
        // If any error occurs, return original CSS
        return $css;
    }
    
    return trim($css);
}

// Example usage
// -----------------
// Input CSS:
//   .example {
//       color: #ffffff;
//       margin: 0px 0px 0px 0px;
//       padding: 20px;
//       background-color: rgb(51, 51, 51);
//   }
//
// Output (minified):
//   .example{color:#fff;margin:0;padding:20px;background-color:#333}

// Simple test case
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $test_css = '
    .example {
        color: #ffffff;
        margin: 0px 0px 0px 0px;
        padding: 20px;
        background-color: rgb(51, 51, 51);
    }';
    
    echo "Original:\n" . $test_css . "\n\n";
    echo "Minified:\n" . minify_css($test_css);
}
