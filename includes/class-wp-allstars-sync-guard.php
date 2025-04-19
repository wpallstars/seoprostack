<?php
/**
 * WP ALLSTARS Sync Guard
 *
 * Prevents plugin loading during sync operations to avoid fatal errors.
 *
 * @package WP_ALLSTARS
 * @since 0.2.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class responsible for detecting sync operations and preventing plugin loading
 */
class WP_Allstars_Sync_Guard {

    /**
     * Flag file name for sync operations
     */
    const SYNC_FLAG_FILE = '.syncing';

    /**
     * Check if sync is in progress
     *
     * @return bool Whether sync is in progress
     */
    public static function is_sync_in_progress() {
        $flag_file = plugin_dir_path(dirname(__FILE__)) . self::SYNC_FLAG_FILE;
        return file_exists($flag_file);
    }

    /**
     * Handle sync mode by showing admin notice and preventing plugin loading
     *
     * @return bool True if sync is in progress, false otherwise
     */
    public static function handle_sync_mode() {
        if (self::is_sync_in_progress()) {
            // Add admin notice
            add_action('admin_notices', array(__CLASS__, 'display_sync_notice'));
            
            // Return true to indicate plugin should not continue loading
            return true;
        }
        
        return false;
    }
    
    /**
     * Display sync in progress notice
     */
    public static function display_sync_notice() {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>WP Allstars:</strong> Plugin files are currently syncing. The plugin functionality is temporarily disabled to prevent errors. Please try again in a moment.</p>';
        echo '</div>';
    }
} 