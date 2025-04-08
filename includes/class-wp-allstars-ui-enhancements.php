<?php
/**
 * WP Allstars UI Enhancements
 *
 * Responsible for enhancing the WordPress admin interface with improved UI components
 * like cards, panels, buttons, and responsive design elements.
 *
 * @package WP_ALLSTARS
 * @version v0.2.3.3
 */

if (!defined('WPINC')) {
    exit;
}

class WP_Allstars_UI_Enhancements {
    /**
     * Constructor
     * Initialize hooks and settings
     */
    public function __construct() {
        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Add body class for enhanced UI
        add_filter('admin_body_class', array($this, 'add_body_class'));
        
        // Initialize UI components
        $this->init_components();
        
        // Ensure toggle functionality works
        add_action('admin_footer', array($this, 'ensure_toggle_functionality'), 99);
    }
    
    /**
     * Enqueue CSS and JavaScript assets
     */
    public function enqueue_assets($hook) {
        // Only load on WP Allstars pages
        if (strpos($hook, 'wp-allstars') === false) {
            return;
        }
        
        // Already registered in main plugin file, but ensure they're enqueued
        wp_enqueue_style('wp-allstars-admin');
        wp_enqueue_script('wp-allstars-admin');
        
        // Add UI enhancements script
        wp_enqueue_script(
            'wp-allstars-ui-enhancements',
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/wp-allstars-ui-enhancements.js',
            array('jquery', 'wp-allstars-admin'),
            WP_ALLSTARS_VERSION,
            true
        );
        
        // Add enhanced UI styles
        wp_enqueue_style(
            'wp-allstars-ui-enhancements',
            plugin_dir_url(dirname(__FILE__)) . 'admin/css/wp-allstars-ui-enhancements.css',
            array('wp-allstars-admin'),
            WP_ALLSTARS_VERSION
        );
        
        // Localize script with settings
        wp_localize_script('wp-allstars-ui-enhancements', 'wpAllstarsUI', array(
            'ajaxurl' => admin_url('ajax.php'),
            'nonce' => wp_create_nonce('wp_allstars_ui_nonce'),
        ));
    }
    
    /**
     * Add body class for enhanced UI
     */
    public function add_body_class($classes) {
        if (isset($_GET['page']) && strpos($_GET['page'], 'wp-allstars') !== false) {
            $classes .= ' wp-allstars-enhanced-ui';
        }
        return $classes;
    }
    
    /**
     * Initialize UI components
     */
    private function init_components() {
        // Add accordion functionality
        add_action('admin_footer', array($this, 'render_accordion_template'));
        
        // Add card component
        add_action('admin_footer', array($this, 'render_card_template'));
        
        // Add notification system
        add_action('admin_footer', array($this, 'render_notification_template'));
    }
    
    /**
     * Ensure toggle switch functionality
     * This adds JS to reinitialize toggle switch handlers after our enhanced UI is applied
     */
    public function ensure_toggle_functionality() {
        // Only on WP Allstars pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wp-allstars') === false) {
            return;
        }
        
        // Get the nonce value
        $nonce = wp_create_nonce('wp-allstars-nonce');
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Make sure the wpAllstars object is available
            if (typeof wpAllstars === 'undefined') {
                window.wpAllstars = {
                    ajaxurl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    nonce: '<?php echo esc_js($nonce); ?>'
                };
            }
            
            // Remove any existing handlers first to prevent duplicates
            $('.wp-toggle-switch input[type="checkbox"]').off('change');
            $('.wp-allstars-toggle-header').off('click');
            
            // Re-bind toggle switch handlers
            $('.wp-toggle-switch input[type="checkbox"]').on('change', function() {
                console.log('Toggle switch changed:', this.id);
                var $this = $(this);
                var option = $this.attr('id');
                var value = $this.is(':checked') ? 1 : 0;
                
                // Don't handle the admin color scheme toggle here - it has its own handler
                if (option === 'wp_allstars_admin_color_scheme') {
                    return;
                }
                
                // Show update notification
                var $notification = $this.closest('.wp-setting-left').find('.wp-setting-notification');
                if ($notification.length === 0) {
                    $notification = $('<span class="wp-setting-notification">Saving...</span>');
                    $this.closest('.wp-setting-left').append($notification);
                } else {
                    $notification.text('Saving...').removeClass('error').show();
                }
                
                // Save the option via AJAX
                $.ajax({
                    url: wpAllstars.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_allstars_update_option',
                        nonce: wpAllstars.nonce,
                        option: option,
                        value: value
                    },
                    success: function(response) {
                        if (response.success) {
                            $notification.text('Saved!');
                            setTimeout(function() {
                                $notification.fadeOut(300);
                            }, 2000);
                        } else {
                            $notification.text('Error').addClass('error');
                            console.error('Error saving option:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        $notification.text('Error').addClass('error');
                        console.error('AJAX error:', error);
                    }
                });
            });
            
            // Re-bind expandable panels
            $('.wp-allstars-toggle-header').on('click', function() {
                var $this = $(this);
                var $settings = $this.next('.wp-allstars-toggle-settings');
                var isExpanded = $this.attr('aria-expanded') === 'true';
                
                // Toggle aria-expanded attribute
                $this.attr('aria-expanded', !isExpanded);
                
                // Toggle settings visibility
                $settings.slideToggle(200);
            });
            
            // Special handling for admin color scheme toggle if exists
            if (typeof wpAllstarsColors !== 'undefined') {
                var $colorToggle = $('#wp_allstars_admin_color_scheme');
                if ($colorToggle.length) {
                    $colorToggle.off('change').on('change', function() {
                        var isModern = $(this).is(':checked');
                        
                        // Show saving notification
                        var $notification = $colorToggle.closest('.wp-setting-left').find('.wp-setting-notification');
                        if ($notification.length === 0) {
                            $notification = $('<span class="wp-setting-notification">Saving...</span>');
                            $colorToggle.closest('.wp-setting-left').append($notification);
                        } else {
                            $notification.text('Saving...').removeClass('error').show();
                        }
                        
                        // Save the option via AJAX
                        $.ajax({
                            url: wpAllstarsColors.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wp_allstars_update_color_scheme',
                                nonce: wpAllstarsColors.nonce,
                                is_modern: isModern ? 1 : 0
                            },
                            success: function(response) {
                                if (response.success) {
                                    if (isModern) {
                                        $('body').addClass('wp-allstars-modern-admin');
                                    } else {
                                        $('body').removeClass('wp-allstars-modern-admin');
                                    }
                                    
                                    $notification.text('Saved!');
                                    setTimeout(function() {
                                        $notification.fadeOut(300);
                                    }, 2000);
                                } else {
                                    $notification.text('Error').addClass('error');
                                    console.error('Error updating color scheme:', response.data);
                                    
                                    // Revert toggle
                                    $colorToggle.prop('checked', !isModern);
                                }
                            },
                            error: function(xhr, status, error) {
                                $notification.text('Error').addClass('error');
                                console.error('AJAX error:', error);
                                
                                // Revert toggle
                                $colorToggle.prop('checked', !isModern);
                            }
                        });
                    });
                }
            }
        });
        </script>
        <?php
    }
    
    /**
     * Render accordion template
     */
    public function render_accordion_template() {
        // Only on WP Allstars pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wp-allstars') === false) {
            return;
        }
        
        ?>
        <script type="text/html" id="tmpl-wp-allstars-accordion">
            <div class="wp-allstars-accordion" role="tablist">
                <div class="wp-allstars-accordion-header" role="tab" id="accordion-header-{{data.id}}" aria-expanded="false">
                    <div class="wp-allstars-accordion-title">{{data.title}}</div>
                    <div class="wp-allstars-accordion-icon"></div>
                </div>
                <div class="wp-allstars-accordion-content" role="tabpanel" aria-labelledby="accordion-header-{{data.id}}">
                    <div class="wp-allstars-accordion-inner">
                        {{{data.content}}}
                    </div>
                </div>
            </div>
        </script>
        <?php
    }
    
    /**
     * Render card template
     */
    public function render_card_template() {
        // Only on WP Allstars pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wp-allstars') === false) {
            return;
        }
        
        ?>
        <script type="text/html" id="tmpl-wp-allstars-card">
            <div class="wp-allstars-card">
                <# if (data.header) { #>
                <div class="wp-allstars-card-header">
                    <# if (data.icon) { #>
                    <div class="wp-allstars-card-icon">{{{data.icon}}}</div>
                    <# } #>
                    <div class="wp-allstars-card-title">{{data.header}}</div>
                </div>
                <# } #>
                <div class="wp-allstars-card-content">
                    {{{data.content}}}
                </div>
                <# if (data.footer) { #>
                <div class="wp-allstars-card-footer">
                    {{{data.footer}}}
                </div>
                <# } #>
            </div>
        </script>
        <?php
    }
    
    /**
     * Render notification template
     */
    public function render_notification_template() {
        // Only on WP Allstars pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wp-allstars') === false) {
            return;
        }
        
        ?>
        <script type="text/html" id="tmpl-wp-allstars-notification">
            <div class="wp-allstars-notification wp-allstars-notification-{{data.type}}">
                <div class="wp-allstars-notification-icon"></div>
                <div class="wp-allstars-notification-content">
                    <# if (data.title) { #>
                    <div class="wp-allstars-notification-title">{{data.title}}</div>
                    <# } #>
                    <div class="wp-allstars-notification-message">{{data.message}}</div>
                </div>
                <div class="wp-allstars-notification-dismiss"></div>
            </div>
        </script>
        <?php
    }
} 