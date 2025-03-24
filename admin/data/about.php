<?php
/**
 * About page data for WP ALLSTARS plugin
 * Content supports markdown formatting
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define about page content
function wp_allstars_get_about_content() {
    return [
        'title' => 'About WP ALLSTARS',
        'content' => <<<MARKDOWN
## Welcome to WP ALLSTARS

WP ALLSTARS is a powerful all-in-one WordPress toolkit designed to help you build, manage, and optimize your WordPress websites more efficiently.

### Our Mission

Our mission is to simplify WordPress development and management by providing a comprehensive suite of tools that work seamlessly together.

### Key Features

* **Theme Management**: Easily install and manage professional WordPress themes
* **Plugin Recommendations**: Curated lists of the best free and premium plugins
* **Workflow Optimization**: Tools to streamline your development process
* **Hosting Recommendations**: Find the perfect hosting provider for your needs
* **Advanced Settings**: Fine-tune your WordPress installation

### Get in Touch

Have questions or suggestions? We'd love to hear from you!

* **Website**: [wpallstars.com](https://wpallstars.com)
* **Documentation**: [docs.wpallstars.com](https://docs.wpallstars.com)
* **Support**: [support@wpallstars.com](mailto:support@wpallstars.com)

### Credits

WP ALLSTARS is developed and maintained by a team of WordPress enthusiasts dedicated to creating the best possible WordPress experience.

Version: {WP_ALLSTARS_VERSION}
MARKDOWN
    ];
}
