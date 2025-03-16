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

    // Toggle switch functionality - stop propagation to prevent panel toggle
    $('.wp-toggle-switch').on('click', function(e) {
        e.stopPropagation(); // Prevent the click from bubbling to the header
    });

    $('.wp-allstars-toggle input[type="checkbox"]').on('change', function(e) {
        e.stopPropagation(); // Prevent the change from bubbling to the header
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
                nonce: wpAllstars.nonce
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

    // Initialize accordion state
    $('.wp-allstars-toggle-header').each(function() {
        var $header = $(this);
        var $panel = $header.closest('.wp-allstars-toggle').find('.wp-allstars-toggle-settings');
        var isExpanded = $header.attr('aria-expanded') === 'true';
        
        // Set initial state
        if (isExpanded) {
            $panel.show();
        } else {
            $panel.hide();
        }
    });

    // Handle accordion functionality
    $('.wp-allstars-toggle-header').on('click', function(e) {
        var $header = $(this);
        var $panel = $header.closest('.wp-allstars-toggle').find('.wp-allstars-toggle-settings');
        var isExpanded = $header.attr('aria-expanded') === 'true';
        
        // Toggle state
        $header.attr('aria-expanded', !isExpanded);
        
        // Toggle panel with animation
        if (!isExpanded) {
            $panel.slideDown(200);
        } else {
            $panel.slideUp(200);
        }
    });

    // Handle form submission
    $('form').on('submit', function() {
        showNotification('Saved');
    });

    // Load plugins on page load
    if ($('#wpa-plugin-list').length) {
        var urlParams = new URLSearchParams(window.location.search);
        var currentCategory = urlParams.get('category') || 'minimal';
        loadPlugins(currentCategory);
        
        // Handle category filter clicks
        $('.wpa-plugin-filters a').on('click', function(e) {
            e.preventDefault();
            var category = new URLSearchParams($(this).attr('href').split('?')[1]).get('category');
            loadPlugins(category);
            
            // Update URL without page reload
            var newUrl = $(this).attr('href');
            history.pushState({}, '', newUrl);
            
            // Update active state
            $('.wpa-plugin-filters a').removeClass('button-primary');
            $(this).addClass('button-primary');
        });
    }

    // Function to load plugins
    function loadPlugins(category) {
        // Show loading overlay
        $('.wpa-loading-overlay').fadeIn();
        
        $.ajax({
            url: ajaxurl,
            data: {
                action: 'wp_allstars_get_plugins',
                category: category || 'minimal',
                _ajax_nonce: wpAllstars.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#wpa-plugin-list').html(response.data);
                } else {
                    console.error('Server returned error:', response);
                    $('#wpa-plugin-list').html('<div class="notice notice-error"><p>Failed to load plugins: ' + (response.data || 'Unknown error') + '</p></div>');
                }
                $('.wpa-loading-overlay').fadeOut();
            },
            error: function(xhr, status, error) {
                console.error('Failed to load plugins:', {xhr: xhr, status: status, error: error});
                $('#wpa-plugin-list').html('<div class="notice notice-error"><p>Failed to load plugins. Please try again. Error: ' + error + '</p></div>');
                $('.wpa-loading-overlay').fadeOut();
            }
        });
    }

    // Load theme on page load
    if ($('#wpa-theme-list').length) {
        loadTheme();
    }

    // Function to load theme
    function loadTheme() {
        // Show loading overlay
        $('.wpa-loading-overlay').fadeIn();
        
        $.ajax({
            url: ajaxurl,
            data: {
                action: 'wp_allstars_get_theme',
                _ajax_nonce: wpAllstars.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#wpa-theme-list').html(response.data);
                    initThemeHandlers();
                } else {
                    console.error('Server returned error:', response);
                    $('#wpa-theme-list').html('<div class="notice notice-error"><p>Failed to load theme: ' + (response.data || 'Unknown error') + '</p></div>');
                }
                $('.wpa-loading-overlay').fadeOut();
            },
            error: function(xhr, status, error) {
                console.error('Failed to load theme:', {xhr: xhr, status: status, error: error});
                $('#wpa-theme-list').html('<div class="notice notice-error"><p>Failed to load theme. Please try again. Error: ' + error + '</p></div>');
                $('.wpa-loading-overlay').fadeOut();
            }
        });
    }

    // Initialize theme handlers
    function initThemeHandlers() {
        // Handle theme installation
        $('.install-theme').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var slug = $button.data('slug');
            
            $button.addClass('updating-message').text('Installing...');
            
            wp.updates.installTheme({
                slug: slug,
                success: function(response) {
                    $button
                        .removeClass('updating-message install-theme')
                        .addClass('button-primary activate-theme')
                        .text('Activate');
                    
                    // Refresh the theme display
                    loadTheme();
                },
                error: function(error) {
                    $button.removeClass('updating-message');
                    console.error('Theme installation failed:', error);
                    if (error.errorMessage) {
                        alert(error.errorMessage);
                    }
                }
            });
        });
        
        // Handle theme activation
        $('.activate-theme').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var slug = $button.data('slug');
            
            $button.addClass('updating-message').text('Activating...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_allstars_activate_theme',
                    theme: slug,
                    _ajax_nonce: wpAllstars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $button.removeClass('updating-message').text('Activated');
                        setTimeout(function() {
                            if (response.data && response.data.customize_url) {
                                window.location.href = response.data.customize_url;
                            } else {
                                window.location.reload();
                            }
                        }, 1000);
                    } else {
                        $button.removeClass('updating-message').text('Activate');
                        alert(response.data || 'Theme activation failed. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    $button.removeClass('updating-message').text('Activate');
                    console.error('Theme activation failed:', error);
                    alert('Theme activation failed: ' + error);
                }
            });
        });
    }
});