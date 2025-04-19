<?php
/**
 * WPALLSTARS Sync Guard
 *
 * Prevents accidental overwrites during content synchronization or updates.
 * Adds checks before saving posts or terms, potentially comparing modification dates
 * or using a locking mechanism to avoid data loss when multiple sources might update content.
 *
 * @package WPALLSTARS
 * @subpackage Core
 * @since 1.0.0 // Adjust version as needed
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class WPALLSTARS_Sync_Guard
 *
 * Implements mechanisms to protect content from concurrent edits or overwrites
 * during synchronization processes.
 */
class WPALLSTARS_Sync_Guard {

    /**
     * Option key for Sync Guard settings within the main options array.
     *
     * @var string
     */
    private $options_key = 'wpallstars_options';

    /**
     * Key for the enable/disable setting within the options array.
     *
     * @var string
     */
    private $setting_key_enabled = 'sync_guard_enabled';

    /**
     * Key for the sync guard mode (e.g., 'timestamp', 'lock').
     *
     * @var string
     */
    private $setting_key_mode = 'sync_guard_mode';

    /**
     * Meta key to store the last sync timestamp on a post/term.
     *
     * @var string
     */
    private $meta_key_last_sync = '_wpallstars_last_sync_timestamp';

    /**
     * Meta key to store a lock indicator (e.g., user ID and timestamp).
     *
     * @var string
     */
    private $meta_key_lock = '_wpallstars_sync_lock';

    /**
     * Lock timeout in seconds.
     *
     * @var int
     */
    private $lock_timeout = 300; // 5 minutes

    /**
     * Constructor. Sets up hooks if the feature is enabled.
     */
    public function __construct() {
        if ($this->is_enabled()) {
            // Hook into pre-save actions for posts and potentially terms
            // Priority 10 is default, allowing other plugins to modify data first.
            add_filter('wp_insert_post_data', array($this, 'check_post_sync_conflict'), 10, 3); // Use 3 args for postarr, update, WP_Error
            // Hook into post-save action to update meta or release locks.
            add_action('save_post', array($this, 'update_post_sync_meta'), 20, 2); // Run later to capture final state

            // --- Term Hooks (Example - Not included in this edit for brevity) ---
            // --- Optional AJAX Lock Release (Not included in this edit for brevity) ---
        }
    }

    /**
     * Check if Sync Guard is enabled in settings.
     *
     * @return bool True if enabled, false otherwise.
     */
    private function is_enabled() {
        $options = get_option($this->options_key, []);
        // Ensure the key exists and is explicitly set to '1' or true.
        return !empty($options[$this->setting_key_enabled]) && $options[$this->setting_key_enabled] == 1;
    }

    /**
     * Get the configured sync guard mode from settings.
     *
     * @return string The mode ('timestamp', 'lock', or default 'timestamp').
     */
    private function get_mode() {
        $options = get_option($this->options_key, []);
        $mode = isset($options[$this->setting_key_mode]) ? sanitize_key($options[$this->setting_key_mode]) : 'timestamp';
        // Ensure mode is one of the allowed values
        return in_array($mode, ['timestamp', 'lock'], true) ? $mode : 'timestamp'; // Default to timestamp
    }

    /**
     * Check for potential sync conflicts before saving post data.
     * Runs on the 'wp_insert_post_data' filter.
     *
     * @param array $data An array of slashed post data.
     * @param array $postarr An array of sanitized, but otherwise unmodified post data.
     * @param bool|WP_Error $update Whether this is an update. WP_Error if validation failed.
     * @return array|WP_Error The original $data or a WP_Error if a conflict is detected and blocking is configured.
     */
    public function check_post_sync_conflict($data, $postarr, $update) {
        // --- Basic Checks ---
        // 1. Only act on updates (existing posts).
        // 2. Ignore if validation already failed ($update is WP_Error).
        // 3. Ensure we have a Post ID.
        // 4. Check if the post type is relevant.
        if (!$update || is_wp_error($update) || empty($postarr['ID']) || !$this->is_relevant_post_type($postarr['post_type'])) {
            return $data; // Pass through if not applicable
        }

        $post_id = absint($postarr['ID']);
        $mode = $this->get_mode();
        $current_user_id = get_current_user_id(); // Can be 0 for system processes

        // --- Timestamp Mode Logic ---
        if ($mode === 'timestamp') {
            $last_sync_time_str = get_post_meta($post_id, $this->meta_key_last_sync, true);
            $post_modified_gmt_str = isset($data['post_modified_gmt']) ? $data['post_modified_gmt'] : get_post_field('post_modified_gmt', $post_id);

            // Convert to timestamps for comparison
            $last_sync_ts = !empty($last_sync_time_str) ? strtotime($last_sync_time_str) : 0;
            $post_modified_ts = !empty($post_modified_gmt_str) ? strtotime($post_modified_gmt_str) : 0;

            // Check if a sync occurred *after* the last modification recorded in the database.
            // This implies an external update happened since the content being edited was loaded.
            if ($last_sync_ts > 0 && $post_modified_ts > 0 && $last_sync_ts > $post_modified_ts) {
                // Conflict detected: External sync is newer than the post's last known modification time.
                // Option 1: Block the save (disruptive) - Uncomment if needed
                /*
                return new WP_Error('sync_conflict_timestamp',
                    __('Warning: This content appears to have been updated by an external sync process since you started editing. Saving now would overwrite those changes. Please reload the content.', WPALLSTARS_TEXT_DOMAIN)
                );
                */

                // Option 2: Allow save but log it (less disruptive)
                $log_message = sprintf(
                    '[WPALLSTARS Sync Guard] Post ID %d: Potential timestamp conflict detected. Save allowed. Last Sync: %s, DB Post Modified: %s',
                    $post_id,
                    esc_html($last_sync_time_str),
                    esc_html($post_modified_gmt_str)
                );
                error_log($log_message);
            }
        }
        // --- Lock Mode Logic ---
        elseif ($mode === 'lock') {
            $lock_data = get_post_meta($post_id, $this->meta_key_lock, true);

            if (!empty($lock_data) && is_array($lock_data)) {
                $lock_time = isset($lock_data['time']) ? (int) $lock_data['time'] : 0;
                $lock_user = isset($lock_data['user']) ? (int) $lock_data['user'] : -1; // -1 for unknown/system lock owner

                // Check if lock is expired
                if ((time() - $lock_time) > $this->lock_timeout) {
                    $this->release_post_lock($post_id, 'timeout_expired');
                    // Lock released due to timeout, proceed to acquire new lock below.
                }
                // Check if lock is held by someone else and not expired
                elseif ($lock_user !== $current_user_id) {
                    $locked_by_user = ($lock_user > 0) ? get_userdata($lock_user) : null;
                    $locked_by_name = $locked_by_user ? $locked_by_user->display_name : __('another process', WPALLSTARS_TEXT_DOMAIN);
                    $time_ago = human_time_diff($lock_time);

                    $error_message = sprintf(
                        // translators: %1$s: User display name or 'another process'. %2$s: Time duration (e.g., '5 minutes ago').
                        __('Sync Conflict: This content is currently locked for editing by %1$s (since %2$s ago). Please try again later or ask them to finish.', WPALLSTARS_TEXT_DOMAIN),
                        esc_html($locked_by_name),
                        esc_html($time_ago)
                    );
                    // Block the save by returning a WP_Error
                    return new WP_Error('sync_lock_conflict', $error_message);
                }
                // Else: Lock is held by the current user, allow save to proceed.
            }

            // If we reached here (no conflict or lock expired), attempt to acquire or update the lock.
            // This ensures the lock is held throughout the save process.
            $this->acquire_post_lock($post_id, $current_user_id);
        }

        // No conflict or conflict handled (e.g., logging), allow data to pass through.
        return $data;
    }

    /**
     * Update sync-related meta data after a post is saved.
     * Runs on the 'save_post' action.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function update_post_sync_meta($post_id, $post) {
        // Prevent infinite loops and ensure post type is relevant
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$this->is_relevant_post_type($post->post_type)) return;

        $mode = $this->get_mode();

        // Update timestamp after successful save (if in timestamp mode)
        if ($mode === 'timestamp') {
             // Update our custom meta field to reflect the latest save time.
             // This uses the post_modified_gmt from the saved post object.
             update_post_meta($post_id, $this->meta_key_last_sync, $post->post_modified_gmt);
        }
        // Release lock after successful save (if in lock mode)
        elseif ($mode === 'lock') {
             // Only release if the lock is held by the current user who initiated the save.
             $lock_data = get_post_meta($post_id, $this->meta_key_lock, true);
             $current_user_id = get_current_user_id();
             if (!empty($lock_data) && is_array($lock_data) && isset($lock_data['user']) && $lock_data['user'] === $current_user_id) {
                $this->release_post_lock($post_id, 'save_completed_by_user');
             }
             // Locks held by other users or expired locks should have been handled earlier or will time out.
        }
    }

     /**
      * Acquire or update a lock on a post for the current user/process.
      *
      * @param int $post_id Post ID.
      * @param int $user_id User ID acquiring the lock (0 for system/unknown).
      * @return bool True on success, false on failure.
      */
     private function acquire_post_lock($post_id, $user_id) {
         $lock_data = array(
             'user' => (int) $user_id,
             'time' => time(), // Current server time
         );
         // Use update_post_meta - it handles both adding and updating the meta key.
         $result = update_post_meta($post_id, $this->meta_key_lock, $lock_data);
         if (!$result) {
              error_log("[WPALLSTARS Sync Guard] Failed to acquire/update lock for Post ID {$post_id} by User ID {$user_id}.");
         }
         return (bool) $result;
     }

     /**
      * Release a lock on a post.
      *
      * @param int    $post_id Post ID.
      * @param string $reason  Optional reason for logging purposes.
      * @return bool True if the meta key was deleted, false otherwise.
      */
     private function release_post_lock($post_id, $reason = '') {
         $log_message = sprintf(
             '[WPALLSTARS Sync Guard] Releasing lock for Post ID %d. Reason: %s',
             absint($post_id),
             sanitize_text_field($reason)
         );
         error_log($log_message);
         // Deleting the meta key removes the lock.
         return delete_post_meta($post_id, $this->meta_key_lock);
     }

    /**
     * Check if the post type is relevant for sync guarding.
     * Post types can be configured via the 'wpallstars_sync_guard_post_types' filter.
     *
     * @param string $post_type The post type slug.
     * @return bool True if the post type should be guarded, false otherwise.
     */
    private function is_relevant_post_type($post_type) {
        // Default relevant post types
        $default_types = array('post', 'page');
        // Allow themes/plugins to filter the list of guarded post types
        $relevant_types = apply_filters('wpallstars_sync_guard_post_types', $default_types);
        // Ensure it's an array before checking
        return is_array($relevant_types) && in_array($post_type, $relevant_types, true);
    }

    // --- Methods for Term Guarding (Example Structure - requires implementation) ---
    // Term guarding logic would go here, mirroring the post logic but using
    // term meta (get/update/delete_term_meta) and term-related hooks.

}