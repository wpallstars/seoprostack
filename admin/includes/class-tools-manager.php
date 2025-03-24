<?php
/**
 * Tools Manager Class
 * 
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Tools_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }
    
    /**
     * Enqueue styles for the tools tab
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
     * Get all tools
     * 
     * @return array
     */
    public static function get_tools() {
        return wp_allstars_get_tools();
    }
    
    /**
     * Display the tools tab content
     */
    public static function display_tab_content() {
        $tools = self::get_tools();
        
        // Sort tools alphabetically by name
        uasort($tools, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        ?>
        <div class="wp-allstars-settings-content tab-content" id="tools">
            <div class="wpa-pro-plugins">
                <?php
                foreach ($tools as $tool) {
                    self::display_tool_card(self::sanitize_tool_data($tool));
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display a single tool card
     * 
     * @param array $tool Sanitized tool configuration
     */
    private static function display_tool_card($tool) {
        // Ensure we have the required fields
        if (empty($tool['name'])) {
            return;
        }
        ?>
        <div class="wpa-pro-plugin">
            <h3><?php echo esc_html($tool['name']); ?></h3>
            
            <?php if (!empty($tool['description'])): ?>
                <p><?php echo esc_html($tool['description']); ?></p>
            <?php endif; ?>
            
            <?php if (!empty($tool['button_group']) && is_array($tool['button_group'])): ?>
                <div class="button-group">
                    <?php foreach ($tool['button_group'] as $button): ?>
                        <?php if (!empty($button['url']) && !empty($button['text'])): ?>
                            <a 
                                href="<?php echo esc_url($button['url']); ?>" 
                                target="_blank" 
                                class="button <?php echo !empty($button['primary']) ? 'button-primary' : ''; ?>"
                            >
                                <?php echo esc_html($button['text']); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Sanitize tool data
     *
     * @param array $tool Raw tool data
     * @return array Sanitized tool data
     */
    private static function sanitize_tool_data($tool) {
        return array(
            'name' => isset($tool['name']) ? sanitize_text_field($tool['name']) : '',
            'description' => isset($tool['description']) ? sanitize_text_field($tool['description']) : '',
            'button_group' => isset($tool['button_group']) ? $tool['button_group'] : array(),
        );
    }
}
