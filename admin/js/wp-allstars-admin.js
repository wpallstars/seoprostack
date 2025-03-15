jQuery(document).ready(function($) {
    // Function to show notification
    function showNotification(message, isError = false) {
        // Remove any existing notification
        $('.wp-status').remove();
        
        // Create and append the new notification
        var $notification = $('<span class="wp-status' + (isError ? ' error' : '') + '">' + message + '</span>');
        $('.wp-allstars-header h1').after($notification);
        
        // Remove the notification after 2 seconds
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
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_allstars_update_option',
                option: option,
                value: value,
                nonce: wpAllstarsData.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Saved');
                } else {
                    showNotification('Error saving settings', true);
                }
            },
            error: function() {
                showNotification('Error saving settings', true);
            }
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

    // Handle form submission
    $('form').on('submit', function() {
        showNotification('Saved');
    });
});