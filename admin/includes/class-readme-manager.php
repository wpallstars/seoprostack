<?php
/**
 * Read Me Manager Class
 * 
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Readme_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }
    
    /**
     * Enqueue styles for the readme tab
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueue_styles($hook) {
        if ('settings_page_wp-allstars' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'wp-allstars-admin',
            plugins_url('css/wp-allstars-admin.css', dirname(__FILE__)),
            array(),
            WP_ALLSTARS_VERSION
        );
    }
    
    /**
     * Get the readme content
     * 
     * @return array
     */
    public static function get_readme_content() {
        return wp_allstars_get_readme_content();
    }
    
    /**
     * Display the readme tab content
     */
    public static function display_tab_content() {
        $readme = self::get_readme_content();
        
        ?>
        <div class="wp-allstars-settings-content tab-content" id="readme">
            <div class="wpa-pro-plugins">
                <div class="wpa-pro-plugin">
                    <h3><?php echo esc_html($readme['title']); ?></h3>
                    
                    <div class="wp-allstars-markdown-content">
                        <?php echo self::parse_markdown($readme['content']); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Parse markdown content to HTML
     * 
     * A simple markdown parser for basic formatting
     * 
     * @param string $markdown The markdown content
     * @return string The HTML content
     */
    private static function parse_markdown($markdown) {
        // Replace version placeholder with actual version
        $markdown = str_replace('{WP_ALLSTARS_VERSION}', WP_ALLSTARS_VERSION, $markdown);
        
        // Headers
        $markdown = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $markdown);
        $markdown = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $markdown);
        $markdown = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $markdown);
        
        // Bold and Italic
        $markdown = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $markdown);
        $markdown = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $markdown);
        
        // Process lists first - identify all list items
        $markdown = preg_replace('/^- (.*?)$/m', '<li class="bullet-item">$1</li>', $markdown);
        $markdown = preg_replace('/^\* (.*?)$/m', '<li class="bullet-item">$1</li>', $markdown);
        $markdown = preg_replace('/^\d+\. (.*?)$/m', '<li class="number-item">$1</li>', $markdown);
        
        // Group consecutive list items into appropriate list types
        // First group bullet items into unordered lists
        $markdown = preg_replace('/((?:<li class="bullet-item">.*?<\/li>\n)+)/s', '<ul class="wp-allstars-ul">$1</ul>', $markdown);
        
        // Then group numbered items into ordered lists with a special class for indentation
        $markdown = preg_replace('/((?:<li class="number-item">.*?<\/li>\n)+)/s', '<ol class="wp-allstars-ol numbered-list">$1</ol>', $markdown);
        
        // Clean up the classes from the final output
        $markdown = str_replace('class="bullet-item"', '', $markdown);
        $markdown = str_replace('class="number-item"', '', $markdown);
        $markdown = str_replace('class="wp-allstars-ul"', 'class="wp-allstars-list"', $markdown);
        $markdown = str_replace('class="wp-allstars-ol numbered-list"', 'class="wp-allstars-list numbered-list"', $markdown);
        
        // Links
        $markdown = preg_replace('/\[(.*?)\]\((.*?)\)/s', '<a href="$2" target="_blank">$1</a>', $markdown);
        
        // Paragraphs
        $markdown = preg_replace('/^(?!<[a-z]).+$/m', '<p>$0</p>', $markdown);
        
        // Fix multiple paragraph tags
        $markdown = str_replace('<p><p>', '<p>', $markdown);
        $markdown = str_replace('</p></p>', '</p>', $markdown);
        
        // Fix lists within paragraphs
        $markdown = str_replace('<p><ul>', '<ul>', $markdown);
        $markdown = str_replace('</ul></p>', '</ul>', $markdown);
        
        return $markdown;
    }
}

// Initialize the class
WP_Allstars_Readme_Manager::init();
