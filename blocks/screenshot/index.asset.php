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
    'dependencies' => array('wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n'),
    'version'      => '0.4.0',
);
