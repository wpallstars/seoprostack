<?php
/**
 * WPALLSTARS UI Enhancements
 *
 * Responsible for enhancing the WordPress admin interface with improved UI components
 * like cards, panels, buttons, and responsive design elements.
 *
 * @package WPALLSTARS
 * @version v0.2.3.3
 */

if (!defined('WPINC')) {
    exit;
}

class WPALLSTARS_UI_Enhancements {
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
        // Only load on WPALLSTARS pages
        if (strpos($hook, 'wpallstars') === false) {
            return;
        }
        
        // Already registered in main plugin file, but ensure they're enqueued
        wp_enqueue_style('wpallstars-admin');
        wp_enqueue_script('wpallstars-admin');
        
        // Add UI enhancements script
        wp_enqueue_script(
            'wpallstars-ui-enhancements',
            WPALLSTARS_URL . 'admin/js/wpallstars-ui-enhancements.js',
            array('jquery', 'wpallstars-admin'),
            WPALLSTARS_VERSION,
            true
        );
        
        // Add enhanced UI styles
        wp_enqueue_style(
            'wpallstars-ui-enhancements',
            WPALLSTARS_URL . 'admin/css/wpallstars-ui-enhancements.css',
            array('wpallstars-admin'),
            WPALLSTARS_VERSION
        );
        
        // Localize script with settings
        wp_localize_script('wpallstars-ui-enhancements', 'wpallstarsUI', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpallstars_ui_nonce'),
        ));
    }
    
    /**
     * Add body class for enhanced UI
     */
    public function add_body_class($classes) {
        if (isset($_GET['page']) && strpos($_GET['page'], 'wpallstars') !== false) {
            $classes .= ' wpallstars-ui-enabled';
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
        // Only on WPALLSTARS pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wpallstars') === false) {
            return;
        }
        
        ?>
        <script type="text/html" id="tmpl-wpallstars-accordion">
            <div class="wpallstars-accordion" role="tablist">
                <div class="wpallstars-accordion-header" role="tab" id="accordion-header-{{data.id}}" aria-expanded="false">
                    <div class="wpallstars-accordion-title">{{data.title}}</div>
                    <div class="wpallstars-accordion-icon"></div>
                </div>
                <div class="wpallstars-accordion-content" role="tabpanel" aria-labelledby="accordion-header-{{data.id}}">
                    <div class="wpallstars-accordion-inner">
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
        // Only on WPALLSTARS pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wpallstars') === false) {
            return;
        }
        
        ?>
        <script type="text/html" id="tmpl-wpallstars-card">
            <div class="wpallstars-card">
                <# if (data.header) { #>
                <div class="wpallstars-card-header">
                    <# if (data.icon) { #>
                    <div class="wpallstars-card-icon">{{{data.icon}}}</div>
                    <# } #>
                    <div class="wpallstars-card-title">{{data.header}}</div>
                </div>
                <# } #>
                <div class="wpallstars-card-content">
                    {{{data.content}}}
                </div>
                <# if (data.footer) { #>
                <div class="wpallstars-card-footer">
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
        // Only on WPALLSTARS pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wpallstars') === false) {
            return;
        }
        
        ?>
        <script type="text/html" id="tmpl-wpallstars-notification">
            <div class="wpallstars-notification wpallstars-notification-{{data.type}}">
                <div class="wpallstars-notification-icon"></div>
                <div class="wpallstars-notification-content">
                    <# if (data.title) { #>
                    <div class="wpallstars-notification-title">{{data.title}}</div>
                    <# } #>
                    <div class="wpallstars-notification-message">{{data.message}}</div>
                </div>
                <div class="wpallstars-notification-dismiss"></div>
            </div>
        </script>
        <?php
    }
} 