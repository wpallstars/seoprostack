<?php
/**
 * Pro Plugins Manager Class
 * 
 * Handles the display and management of pro plugin recommendations.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WP_Allstars_Pro_Plugins_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // Hook into WordPress if needed
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_styles'));
    }
    
    /**
     * Get all pro plugin configurations
     * 
     * @return array Pro plugin configurations
     */
    public static function get_pro_plugins() {
        // Using the existing function to maintain compatibility
        return wp_allstars_get_pro_plugins_config();
    }
    
    /**
     * Display the pro plugins tab content
     */
    public static function display_tab_content() {
        $pro_plugins = self::get_pro_plugins();
        
        // Sort plugins alphabetically by name
        uasort($pro_plugins, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        // Output the HTML for the pro plugins tab
        echo '<div class="wp-allstars-settings-content tab-content" id="pro"><div class="wpa-pro-plugins">';
        
        foreach ($pro_plugins as $plugin) {
            self::display_plugin_card($plugin);
        }
        
        echo '</div></div>';
    }
    
    /**
     * Display a single plugin card
     * 
     * @param array $plugin Plugin configuration
     */
    public static function display_plugin_card($plugin) {
        ?>
        <div class="wpa-pro-plugin">
            <h3><?php echo esc_html($plugin['name']); ?></h3>
            <p><?php echo esc_html($plugin['description']); ?></p>
            <?php if (isset($plugin['button_group'])): ?>
                <div class="button-group">
                    <?php foreach ($plugin['button_group'] as $button): ?>
                        <a href="<?php echo esc_url($button['url']); ?>" target="_blank" class="button <?php echo isset($button['primary']) && $button['primary'] ? 'button-primary' : ''; ?>">
                            <?php echo esc_html($button['text']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="button-group">
                    <?php if (!empty($plugin['demo_url'])): ?>
                        <a href="<?php echo esc_url($plugin['demo_url']); ?>" class="button" target="_blank">
                            <?php esc_html_e('View Demo', 'wp-allstars'); ?>
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($plugin['url']); ?>" class="button button-primary" target="_blank">
                        <?php esc_html_e('Learn More', 'wp-allstars'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Enqueue styles specific to pro plugins
     */
    public static function enqueue_styles($hook) {
        // Only load on the plugin settings page
        if (strpos($hook, 'wp-allstars') === false) {
            return;
        }
        
        // Add inline CSS for pro plugins
        $custom_css = '
        .wpa-pro-plugins {
            padding: 0;
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
        .wpa-pro-plugin .button-primary {
            background: #0071a1;
            border-color: #0071a1;
            color: #fff;
        }
        .wpa-pro-plugin .button-primary:hover {
            background: #006291;
            border-color: #006291;
            color: #fff;
        }
        ';
        
        wp_add_inline_style('wp-allstars-admin', $custom_css);
    }
}
