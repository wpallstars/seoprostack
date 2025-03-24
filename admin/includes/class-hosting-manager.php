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
        // No specific initialization needed currently
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
}
