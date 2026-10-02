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
    'dependencies' => array('wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-element', 'wp-i18n', 'wp-url'),
    'version'      => '0.9.0',
);
