jQuery(document).ready(function($) {
    // Function to show notification
    function showNotification(message, isError = false) {
        // Remove any existing notifications
        $('.wp-status').remove();
        
        // Add notification to header
        var $notification = $('<span class="wp-status' + (isError ? ' error' : '') + '">' + message + '</span>');
        $('.wp-allstars-header').append($notification);
        
        // Fade out and remove after delay
        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 2000);
    }

    // Handle all settings changes
    function updateOption(option, value) {
        return $.post(wpAllstars.ajaxurl, {
            action: 'wp_allstars_update_option',
            option: option,
            value: value,
            nonce: wpAllstars.nonce
        }).then(function(response) {
            if (!response.success) {
                throw new Error(response.data || 'Error saving setting');
            }
            return response;
        });
    }

    // Toggle switch functionality
    $('.wp-allstars-toggle input[type="checkbox"]').on('change', function() {
        var $input = $(this);
        var option = $input.attr('name');
        var value = $input.is(':checked') ? 1 : 0;
        
        updateOption(option, value)
            .then(function() {
                showNotification('Setting saved');
            })
            .catch(function(error) {
                console.error('Error:', error);
                showNotification('Error saving setting', true);
            });
    });

    // Handle text, number, and textarea inputs
    $('.wp-allstars-setting-row input[type="text"], .wp-allstars-setting-row input[type="number"], .wp-allstars-setting-row textarea').on('change', function() {
        var $input = $(this);
        var option = $input.attr('name');
        var value = $input.val();
        
        updateOption(option, value)
            .then(function() {
                showNotification('Setting saved');
            })
            .catch(function(error) {
                console.error('Error:', error);
                showNotification('Error saving setting', true);
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
});