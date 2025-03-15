jQuery(document).ready(function($) {
    // Toggle switch functionality
    $('.wp-allstars-toggle input[type="checkbox"]').on('change', function() {
        var $input = $(this);
        var option = $input.attr('name');
        var value = $input.is(':checked') ? 1 : 0;
        
        // Remove any existing status messages
        $('.wp-status').remove();
        
        // Add a small notification that fades out
        var $notification = $('<span class="wp-status" style="position: fixed; top: 32px; left: 50%; transform: translateX(-50%); z-index: 99999; background: #00a32a; color: white; padding: 8px 16px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Setting saved</span>');
        $('body').append($notification);
        
        $.post(wpAllstars.ajaxurl, {
            action: 'wp_allstars_update_option',
            option: option,
            value: value,
            nonce: wpAllstars.nonce
        }, function(response) {
            if (!response.success) {
                console.error('Error:', response);
                $notification.css('background', '#d63638').text('Error saving setting');
            }
            
            // Fade out and remove the notification after 2 seconds
            setTimeout(function() {
                $notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 2000);
        });
    });

    // Expand/collapse settings functionality
    $('.wp-allstars-expand-settings').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $settings = $button.closest('.wp-allstars-toggle').find('.wp-allstars-toggle-settings');
        var isExpanded = $button.attr('aria-expanded') === 'true';
        
        $button.attr('aria-expanded', !isExpanded);
        $button.find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
        
        if (isExpanded) {
            $settings.slideUp(200);
        } else {
            $settings.slideDown(200);
        }
    });

    // Save settings on form submit
    $('form').on('submit', function() {
        // Remove any existing status messages
        $('.wp-status').remove();
        
        // Add a small notification that fades out
        var $notification = $('<span class="wp-status" style="position: fixed; top: 32px; left: 50%; transform: translateX(-50%); z-index: 99999; background: #00a32a; color: white; padding: 8px 16px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Settings saved</span>');
        $('body').append($notification);
        
        // Fade out and remove the notification after 2 seconds
        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 2000);
    });
});