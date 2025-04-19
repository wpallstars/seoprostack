/**
 * WP Allstars Admin Script
 *
 * Handles UI interactions in the admin settings
 */
(function($) {
    'use strict';

    // Document ready handler
    $(document).ready(function() {
        // Handle toggle switches
        $('.wp-toggle-switch input[type="checkbox"]').on('change', function() {
            var $this = $(this);
            var option = $this.attr('id');
            var value = $this.is(':checked') ? 1 : 0;
            
            // Don't handle the admin color scheme toggle here - it has its own handler
            if (option === 'wp_allstars_admin_color_scheme') {
                return;
            }
            
            // Show update notification
            var $notification = $this.closest('label').find('.wp-setting-notification');
            if ($notification.length === 0) {
                $notification = $('<span class="wp-setting-notification">Saving...</span>');
                $this.closest('label').append($notification);
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
        
        // Toggle expandable panels
        $('.wp-allstars-toggle-header').on('click', function() {
            var $this = $(this);
            var $settings = $this.next('.wp-allstars-toggle-settings');
            var isExpanded = $this.attr('aria-expanded') === 'true';
            
            // Toggle aria-expanded attribute
            $this.attr('aria-expanded', !isExpanded);
            
            // Toggle settings visibility
            $settings.slideToggle(200);
        });
    });
})(jQuery);