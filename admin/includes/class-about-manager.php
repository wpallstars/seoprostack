<?php
/**
 * About Page Manager Class
 * 
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_About_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }
    
    /**
     * Enqueue styles for the about tab
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
     * Get the about page content
     * 
     * @return array
     */
    public static function get_about_content() {
        return wp_allstars_get_about_content();
    }
    
    /**
     * Display the about tab content
     */
    public static function display_tab_content() {
        $about = self::get_about_content();
        
        ?>
        <div class="wp-allstars-settings-content tab-content" id="about">
            <div class="wpa-pro-plugins">
                <div class="wpa-pro-plugin">
                    <h3><?php echo esc_html($about['title']); ?></h3>
                    
                    <div class="wp-allstars-markdown-content">
                        <?php echo self::parse_markdown($about['content']); ?>
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
        
        // Lists
        $markdown = preg_replace('/^\* (.*?)$/m', '<li>$1</li>', $markdown);
        $markdown = preg_replace('/(<li>.*?<\/li>\n)+/s', '<ul>$0</ul>', $markdown);
        
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
WP_Allstars_About_Manager::init();
