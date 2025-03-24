<?php
/**
 * Workflow Manager Class
 * 
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Workflow_Manager {
    
    /**
     * Initialize the class
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }
    
    /**
     * Register workflow settings
     */
    public static function register_settings() {
        register_setting('wp_allstars_workflow', 'wp_allstars_workflow_options');
        register_setting('wp_allstars_workflow', 'wp_allstars_auto_upload_images');
        register_setting('wp_allstars_workflow', 'wp_allstars_max_width');
        register_setting('wp_allstars_workflow', 'wp_allstars_max_height');
        register_setting('wp_allstars_workflow', 'wp_allstars_exclude_urls');
        register_setting('wp_allstars_workflow', 'wp_allstars_image_name_pattern');
        register_setting('wp_allstars_workflow', 'wp_allstars_image_alt_pattern');
    }
    
    /**
     * Display the workflow tab content
     */
    public static function display_tab_content() {
        ?>
        <div class="wp-allstars-settings-content tab-content" id="workflow">
            <div class="wp-allstars-toggle">
                <div class="wp-allstars-toggle-header" aria-expanded="false">
                    <div class="wp-allstars-toggle-main">
                        <div class="wp-allstars-toggle-left">
                            <div class="wp-toggle-switch">
                                <input type="checkbox" 
                                       id="wp_allstars_auto_upload_images"
                                       name="wp_allstars_auto_upload_images" 
                                       value="1"
                                       <?php checked(get_option('wp_allstars_auto_upload_images', false)); ?> 
                                />
                                <span class="wp-toggle-slider"></span>
                            </div>
                            <label for="wp_allstars_auto_upload_images">
                                <?php esc_html_e('Enable Auto Upload Images', 'wp-allstars'); ?>
                            </label>
                        </div>
                    </div>
                    <p class="wp-setting-description">
                        <?php esc_html_e('Import images that have external URLs into your Media Library when saving. Consider disabling during large data imports with many external image URLs.', 'wp-allstars'); ?>
                    </p>
                </div>
                <div class="wp-allstars-toggle-settings">
                    <div class="wp-allstars-setting-row">
                        <label for="wp_allstars_max_width"><?php esc_html_e('Max Width', 'wp-allstars'); ?></label>
                        <input type="number" 
                               id="wp_allstars_max_width"
                               name="wp_allstars_max_width" 
                               value="<?php echo esc_attr(get_option('wp_allstars_max_width', 2560)); ?>" 
                        />
                        <p class="description"><?php esc_html_e('Maximum width for uploaded images in pixels.', 'wp-allstars'); ?></p>
                    </div>
                    <div class="wp-allstars-setting-row">
                        <label for="wp_allstars_max_height"><?php esc_html_e('Max Height', 'wp-allstars'); ?></label>
                        <input type="number" 
                               id="wp_allstars_max_height"
                               name="wp_allstars_max_height" 
                               value="<?php echo esc_attr(get_option('wp_allstars_max_height', 2560)); ?>" 
                        />
                        <p class="description"><?php esc_html_e('Maximum height for uploaded images in pixels.', 'wp-allstars'); ?></p>
                    </div>

                    <div class="wp-allstars-setting-row">
                        <label for="wp_allstars_exclude_urls"><?php esc_html_e('Exclude URLs', 'wp-allstars'); ?></label>
                        <textarea id="wp_allstars_exclude_urls"
                                  name="wp_allstars_exclude_urls"
                                  rows="3"
                                  placeholder="example.com&#10;another-domain.com"
                        ><?php echo esc_textarea(get_option('wp_allstars_exclude_urls', '')); ?></textarea>
                        <p class="description"><?php esc_html_e('Enter domains to exclude (one per line). Images from these domains will not be imported.', 'wp-allstars'); ?></p>
                    </div>

                    <div class="wp-allstars-setting-row">
                        <label for="wp_allstars_image_name"><?php esc_html_e('Image Name Pattern', 'wp-allstars'); ?></label>
                        <input type="text" 
                               id="wp_allstars_image_name"
                               name="wp_allstars_image_name"
                               value="<?php echo esc_attr(get_option('wp_allstars_image_name_pattern', '%filename%')); ?>"
                        />
                        <p class="description">
                            <?php esc_html_e('Available patterns:', 'wp-allstars'); ?> %filename%, %post_id%, %postname%, %timestamp%, %date%, %year%, %month%, %day%
                        </p>
                    </div>

                    <div class="wp-allstars-setting-row">
                        <label for="wp_allstars_image_alt"><?php esc_html_e('Image Alt Pattern', 'wp-allstars'); ?></label>
                        <input type="text" 
                               id="wp_allstars_image_alt"
                               name="wp_allstars_image_alt"
                               value="<?php echo esc_attr(get_option('wp_allstars_image_alt_pattern', '%filename%')); ?>"
                        />
                        <p class="description">
                            <?php esc_html_e('Available patterns:', 'wp-allstars'); ?> %filename%, %post_title%, %post_id%, %postname%, %timestamp%
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save workflow settings via AJAX
     */
    public static function save_settings() {
        // Verify nonce for security
        check_ajax_referer('wp-allstars-nonce', 'nonce');
        
        // Check if user has proper permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $options = array();
        
        // Auto upload images setting
        $options['auto_upload_images'] = isset($_POST['auto_upload_images']) ? 
            (bool) $_POST['auto_upload_images'] : 
            false;
        
        // Max dimensions
        $options['max_width'] = isset($_POST['max_width']) ? 
            absint($_POST['max_width']) : 
            2560;
        
        $options['max_height'] = isset($_POST['max_height']) ? 
            absint($_POST['max_height']) : 
            2560;
        
        // Exclude URLs
        $options['exclude_urls'] = isset($_POST['exclude_urls']) ? 
            sanitize_textarea_field($_POST['exclude_urls']) : 
            '';
        
        // Name and alt patterns
        $options['image_name_pattern'] = isset($_POST['image_name_pattern']) ? 
            sanitize_text_field($_POST['image_name_pattern']) : 
            '%filename%';
        
        $options['image_alt_pattern'] = isset($_POST['image_alt_pattern']) ? 
            sanitize_text_field($_POST['image_alt_pattern']) : 
            '%filename%';
        
        // Update the options
        update_option('wp_allstars_workflow_options', $options);
        
        // Also update individual options for backward compatibility
        update_option('wp_allstars_auto_upload_images', $options['auto_upload_images']);
        update_option('wp_allstars_max_width', $options['max_width']);
        update_option('wp_allstars_max_height', $options['max_height']);
        update_option('wp_allstars_exclude_urls', $options['exclude_urls']);
        update_option('wp_allstars_image_name_pattern', $options['image_name_pattern']);
        update_option('wp_allstars_image_alt_pattern', $options['image_alt_pattern']);
        
        wp_send_json_success('Workflow settings saved');
    }
}
