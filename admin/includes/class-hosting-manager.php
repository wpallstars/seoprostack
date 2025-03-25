<?php
/**
 * WP ALLSTARS Hosting Manager
 * 
 * Manages the hosting providers tab and functionality
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Allstars_Hosting_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }
    
    /**
     * Display the hosting tab content
     */
    public static function display_tab_content() {
        ?>
        <div class="wp-allstars-settings-content tab-content" id="hosting">
            <div class="wpa-pro-plugins">
            <?php
            $hosting_providers = self::get_hosting_providers();
            // Sort providers alphabetically by name
            uasort($hosting_providers, function($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
            foreach ($hosting_providers as $provider) {
                ?>
                <div class="wpa-pro-plugin">
                    <h3><?php echo esc_html($provider['name']); ?></h3>
                    <p><?php echo esc_html($provider['description']); ?></p>
                    <?php if (isset($provider['button_group'])): ?>
                        <div class="button-group">
                            <?php foreach ($provider['button_group'] as $button): ?>
                                <?php 
                                $button_class = 'button';
                                if (isset($button['primary']) && $button['primary']) {
                                    $button_class .= ' button-primary go-pro-button';
                                } else {
                                    // Add green styling to secondary buttons
                                    $button_class .= ' green-button-secondary';
                                    // Special handling for Pricing buttons
                                    if ($button['text'] === 'Pricing') {
                                        $button_class .= ' pricing-button';
                                    }
                                }
                                ?>
                                <a href="<?php echo esc_url($button['url']); ?>" target="_blank" class="<?php echo esc_attr($button_class); ?>">
                                    <?php echo esc_html($button['text']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
            }
            ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get the list of hosting providers
     * 
     * @return array Array of hosting providers
     */
    public static function get_hosting_providers() {
        // Define the providers data if it hasn't been included yet
        if (!function_exists('wp_allstars_get_hosting_providers')) {
            require_once dirname(dirname(__FILE__)) . '/data/hosting-providers.php';
        }
        
        return wp_allstars_get_hosting_providers();
    }
    
    /**
     * Enqueue styles for the hosting tab
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
        
        // Add inline CSS for hosting to match the single column layout
        $custom_css = '
        #hosting .wpa-pro-plugins {
            padding: 15px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            max-width: 700px;
            margin: 0 auto;
        }
        #hosting .wpa-pro-plugin {
            background: #fff;
            border: 1px solid #ddd;
            padding: 24px;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            width: 100%;
            margin-bottom: 24px;
            max-width: 100%;
        }
        #hosting .wpa-pro-plugin:last-child {
            margin-bottom: 0;
        }
        #hosting .wpa-pro-plugin .button-group {
            justify-content: flex-end;
        }
        ';
        
        wp_add_inline_style('wp-allstars-admin', $custom_css);
    }
}
