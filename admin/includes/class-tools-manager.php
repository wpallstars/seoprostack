<?php
/**
 * Tools Manager Class
 * 
 * Handles the display and management of recommended tools.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WP_Allstars_Tools_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // No hooks needed currently as styles are included directly in the HTML output
    }
    
    /**
     * Get all tools
     * 
     * @return array Tools configuration
     */
    public static function get_tools() {
        // Using the existing function to maintain compatibility
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
        
        // Output the CSS and HTML for the tools tab
        ?>
        <div class="wp-allstars-settings-content tab-content" id="tools">
        <style>
        .wpa-pro-plugins {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            gap: 24px;
            max-width: 1920px;
            margin: 0 auto;
        }
        .wpa-pro-plugin {
            background: #fff;
            border: 1px solid #ddd;
            padding: 24px;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .wpa-pro-plugin:hover {
            border-color: #2271b1;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .wpa-pro-plugin h3 {
            margin: 0 0 12px;
            font-size: 16px;
            font-weight: 600;
            color: #1d2327;
            line-height: 1.4;
        }
        .wpa-pro-plugin p {
            margin: 0 0 16px;
            color: #50575e;
            font-size: 14px;
            line-height: 1.6;
        }
        .wpa-pro-plugin .button-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: auto;
        }
        .wpa-pro-plugin .button {
            text-decoration: none;
            min-width: 120px;
            text-align: center;
            height: 30px;
            line-height: 28px;
            padding: 0 12px;
            font-size: 13px;
            font-weight: normal;
            margin: 0;
            border: 1px solid #0071a1 !important;
            border-radius: 3px !important;
            background: #f6f7f7;
            color: #0071a1;
            display: inline-block;
            vertical-align: top;
            box-shadow: none;
            cursor: pointer;
        }
        .wpa-pro-plugin .button:hover {
            background: #f0f0f1;
            border-color: #0071a1;
            color: #0071a1;
        }
        .wpa-pro-plugin .button.button-primary {
            background: #2271b1;
            border-color: #2271b1 !important;
            color: #fff;
        }
        .wpa-tool-card .button.button-primary:hover {
            background: #135e96;
            border-color: #135e96 !important;
        }
        @media screen and (max-width: 960px) {
            .wpa-tools-grid {
                grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
                gap: 20px;
                padding: 16px;
            }
            .wpa-pro-plugin {
                padding: 20px;
            }
        }
        @media screen and (max-width: 782px) {
            .wpa-tools-grid {
                grid-template-columns: 1fr;
                gap: 16px;
                padding: 12px;
            }
            .wpa-pro-plugin {
                padding: 16px;
            }
            .wpa-pro-plugin .button {
                width: 100%;
            }
        }
        </style>

        <div class="wpa-pro-plugins">
            <?php
            foreach ($tools as $tool) {
                self::display_tool_card($tool);
            }
            ?>
        </div>
        </div>
        <?php
    }
    
    /**
     * Display a single tool card
     * 
     * @param array $tool Tool configuration
     */
    public static function display_tool_card($tool) {
        ?>
        <div class="wpa-pro-plugin">
            <h3><?php echo esc_html($tool['name']); ?></h3>
            <p><?php echo esc_html($tool['description']); ?></p>
            <?php if (isset($tool['button_group'])): ?>
                <div class="button-group">
                    <?php foreach ($tool['button_group'] as $button): ?>
                        <a href="<?php echo esc_url($button['url']); ?>" target="_blank" class="button <?php echo isset($button['primary']) && $button['primary'] ? 'button-primary' : ''; ?>">
                            <?php echo esc_html($button['text']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Enqueue styles specific to tools
     * 
     * Note: This method is no longer used as styles are now directly included in the display_tab_content method
     * for immediate application. Kept for reference or future use if needed.
     */
    public static function enqueue_styles($hook) {
        // This method is currently not in use as styles are directly included in the HTML output
        // to ensure immediate application without relying on the WordPress hook system timing
    }
}
