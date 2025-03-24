<?php
/**
 * Read Me data for WP ALLSTARS plugin
 * Content is pulled from the README.md file
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define readme content
function wp_allstars_get_readme_content() {
    // Get README.md content
    $readme_path = WP_PLUGIN_DIR . '/wpa-superstar-plugin/README.md';
    $readme_content = '';
    
    if (file_exists($readme_path)) {
        $readme_content = file_get_contents($readme_path);
    } else {
        // Fallback content if README.md is not found
        $readme_content = <<<MARKDOWN
# WP Allstars

A WordPress plugin that enhances your WordPress experience with curated plugins, themes, and optimization tools.

## Description

WP Allstars is a powerful WordPress plugin designed to help site owners and developers optimize their WordPress installations. It provides a curated collection of recommended plugins, themes, and optimization tools all in one place.

## Features

- **Curated Plugin Recommendations**: Browse and install recommended free plugins organized by category.
- **Pro Plugin Showcase**: Discover premium plugins with direct links to purchase.
- **Theme Integration**: Easily install and activate the Kadence theme.
- **Workflow Optimization**: Tools to streamline your WordPress workflow.
- **Advanced Settings**: Fine-tune your WordPress installation with advanced configuration options.

Version: {WP_ALLSTARS_VERSION}
MARKDOWN;
    }
    
    return [
        'title' => 'Read Me',
        'content' => $readme_content
    ];
}
