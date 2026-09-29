<?php
/**
 * Script dependencies for index.js (hand-written; no build step).
 *
 * @package Allstars
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'dependencies' => array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'),
    'version'      => '0.3.0',
);
