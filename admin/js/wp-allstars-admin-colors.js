/**
 * WP Allstars Admin Colors Script
 *
 * Handles toggling the admin color scheme
 */
(function($) {
    'use strict';

    // Once the DOM is ready
    $(document).ready(function() {
        // Find the color scheme toggle
        var $toggle = $('#wp_allstars_admin_color_scheme');
        
        if (!$toggle.length) {
            return;
        }
        
        // Listen for changes to the toggle
        $toggle.on('change', function() {
            var $this = $(this);
            var enabled = $this.is(':checked');
            var $notification = $this.closest('label').find('.wp-setting-notification');
            
            // Show loading notification
            if ($notification.length) {
                $notification.text('Saving...').show();
            } else {
                $notification = $('<span class="wp-setting-notification">Saving...</span>');
                $this.closest('label').append($notification);
            }
            
            // Send AJAX request
            $.ajax({
                url: wpAllstarsColors.ajax_url,
                type: 'POST',
                data: {
                    action: 'wp_allstars_update_color_scheme',
                    nonce: wpAllstarsColors.nonce,
                    enabled: enabled ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        // Show success notification
                        $notification.text('Saved!').removeClass('error');
                        
                        // Reload page after a short delay to apply new color scheme
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // Show error notification
                        $notification.text('Error').addClass('error');
                        
                        // Revert toggle
                        $this.prop('checked', !enabled);
                        
                        // Log error
                        console.error('Error updating color scheme:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    // Show error notification
                    $notification.text('Error').addClass('error');
                    
                    // Revert toggle
                    $this.prop('checked', !enabled);
                    
                    // Log error
                    console.error('AJAX error:', error);
                }
            });
        });
    });
})(jQuery); 