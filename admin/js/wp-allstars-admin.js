jQuery(document).ready(function($) {
    $('.wp-allstars-toggle input').on('change', function() {
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
});