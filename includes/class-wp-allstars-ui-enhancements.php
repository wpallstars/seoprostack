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