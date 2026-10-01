<?php
/**
 * Script dependencies for index.js (hand-written; no build step).
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'dependencies' => array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-core-data', 'wp-server-side-render'),
    'version'      => '0.4.0',
);
