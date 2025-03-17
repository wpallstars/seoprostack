// Define loadTheme in the global scope so it can be called from inline scripts
var loadTheme;

jQuery(document).ready(function($) {
    // Function to show notification
    function showNotification(message, $element, isError = false) {
        // Remove any existing notifications
        $('.wp-setting-notification').remove();
        
        // Create notification element
        var $notification = $('<span class="wp-setting-notification' + (isError ? ' error' : '') + '">' + message + '</span>');
        
        // If element is provided, show notification next to it
        if ($element && $element.length) {
            $element.after($notification);
        } else {
            // Fallback to header if no element provided
            $('.wp-allstars-header h1').after($notification);
        }
        
        // Fade out after delay
        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });

        }, 2000);
    }

    // Handle option updates
    function updateOption(option, value) {
        return $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_allstars_update_option',
                option: option,
                value: value,
                nonce: wpAllstars.nonce
            }
        }).then(function(response) {
            if (!response.success) {
                throw new Error(response.data || 'Error saving setting');
            }
            return response;
        });

    }

    // Handle toggle switch clicks
    $('.wp-toggle-switch').on('click', function(e) {
        e.stopPropagation();
        var $checkbox = $(this).find('input[type="checkbox"]');
        var isChecked = $checkbox.is(':checked');
        $checkbox.prop('checked', !isChecked).trigger('change');
    });


    // Prevent label clicks from toggling the checkbox directly
    $('.wp-setting-label, .wp-allstars-toggle-left label').on('click', function(e) {
        e.stopPropagation();
    });


    // Handle checkbox changes
    $('.wp-toggle-switch input[type="checkbox"]').on('change', function(e) {
        e.stopPropagation();
        var $input = $(this);
        var option = $input.attr('name');
        var value = $input.is(':checked') ? 1 : 0;
        var $label = $input.closest('.wp-setting-left, .wp-allstars-toggle-left').find('label');
        
        updateOption(option, value)
            .then(function() {
                showNotification('Saved', $label);
            })
            .catch(function() {
                showNotification('Error saving settings', $label, true);
            });

    });


    // Handle text input changes
    $('.wp-allstars-setting-row input[type="text"], .wp-allstars-setting-row input[type="number"], .wp-allstars-setting-row textarea').on('change', function() {
        var $input = $(this);
        var option = $input.attr('name');
        var value = $input.val();
        var $label = $input.closest('.wp-allstars-setting-row').find('label').first();
        
        updateOption(option, value)
            .then(function() {
                showNotification('Saved', $label);
            })
            .catch(function(error) {
                console.error('Error:', error);
                showNotification('Error saving setting', $label, true);
            });

    });


    // Toggle expandable panels
    $('.wp-allstars-toggle-header').on('click', function(e) {
        if (!$(e.target).closest('.wp-toggle-switch').length && 
            !$(e.target).closest('label').length) {
            var $settings = $(this).closest('.wp-allstars-toggle').find('.wp-allstars-toggle-settings');
            var isExpanded = $(this).attr('aria-expanded') === 'true';
            
            $(this).attr('aria-expanded', !isExpanded);
            $settings.slideToggle(200);
        }
    });


    // Set initial panel states
    $('.wp-allstars-toggle-header').each(function() {
        var $settings = $(this).closest('.wp-allstars-toggle').find('.wp-allstars-toggle-settings');
        var isExpanded = $(this).attr('aria-expanded') === 'true';
        
        if (!isExpanded) {
            $settings.hide();
        }
    });


    // Remove JavaScript-based tab switching - let the native WordPress tab links work
    
    // Plugin category filters
    if ($('#wpa-plugin-filters').length) {
        $('#wpa-plugin-filters a').on('click', function(e) {
            e.preventDefault();
            var category = $(this).data('category');
            
            // Update active filter
            $('#wpa-plugin-filters a').removeClass('current');
            $(this).addClass('current');
            
            // Load plugins for the selected category
            loadPlugins(category);
        });

        
        // Load initial plugins if we're on the recommended tab
        if ($('#recommended').is(':visible') && $('#wpa-plugin-list').is(':empty')) {
            loadPlugins('minimal');
        }
    }
    
    // Load theme tab content if we're on the theme tab
    if ($('#theme').is(':visible') && $('#wpa-theme-list').length && $('#wpa-theme-list').is(':empty')) {
        loadTheme();
    }
    
    // Function to load plugins
    function loadPlugins(category) {
        var $container = $('#wpa-plugin-list');
        var $loadingOverlay = $('<div class="wp-allstars-loading-overlay"><span class="spinner is-active"></span></div>');
        
        // Show loading overlay
        $container.css('position', 'relative').append($loadingOverlay);
        
        // Clear existing plugins
        $container.empty().append($loadingOverlay);
        
        // AJAX request to get plugins
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_allstars_get_plugins',
                category: category,
                _wpnonce: wpAllstars.nonce
            },
            success: function(response) {
                // Remove loading overlay
                $loadingOverlay.remove();
                
                if (response.success) {
                    // Append plugins HTML
                    $container.html(response.data);
                    
                    // Initialize plugin action buttons
                    initPluginActions();
                    
                    // Individual plugin card spinners have been removed
                } else {
                    // Show error message
                    $container.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                // Remove loading overlay
                $loadingOverlay.remove();
                
                // Show error message
                $container.html('<div class="notice notice-error"><p>Failed to load plugins. Please try again. Error: ' + error + '</p></div>');
                console.error('AJAX Error:', xhr.responseText);
            }
        });

    }
    
    // Theme handlers are initialized directly from the inline script
    // We don't need a separate loadTheme function anymore
    
    // Initialize plugin action buttons
    function initPluginActions() {
        // Remove any existing event handlers to prevent duplicates
        $('.plugin-card .install-now').off('click');
        $('.plugin-card .update-now').off('click');
        $('.plugin-card .activate-now').off('click');
        
        // Install plugin
        $('.plugin-card .install-now').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var slug = $button.data('slug');
            
            $button.addClass('updating-message').text('Installing...');
            
            wp.updates.installPlugin({
                slug: slug,
                success: function(response) {
                    $button.removeClass('updating-message').addClass('updated-message').text('Installed!');
                    setTimeout(function() {
                        // Replace the button with an activate button
                        var $parent = $button.parent();
                        $button.remove();
                        $parent.html('<a class="button activate-now" href="' + response.activateUrl + '" data-slug="' + slug + '" aria-label="Activate ' + slug + '">Activate</a>');
                        
                        // Re-initialize the event handlers
                        initPluginActions();
                    }, 1000);
                },
                error: function(response) {
                    $button.removeClass('updating-message').text('Install Now');
                    alert(response.errorMessage);
                }
            });
        });
        
        // Update plugin
        $('.plugin-card .update-now').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var slug = $button.data('slug');
            
            $button.addClass('updating-message').text('Updating...');
            
            wp.updates.updatePlugin({
                slug: slug,
                success: function() {
                    $button.removeClass('updating-message').addClass('updated-message').text('Updated!');
                    setTimeout(function() {
                        $button.removeClass('update-now updated-message')
                               .addClass('button-disabled')
                               .text('Active');
                    }, 1000);
                },
                error: function(response) {
                    $button.removeClass('updating-message').text('Update Now');
                    alert(response.errorMessage);
                }
            });
        });
        
        // Activate plugin
        $('.plugin-card .activate-now').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var url = $button.attr('href');
            var slug = $button.data('slug');
            
            $button.addClass('updating-message').text('Activating...');
            
            $.ajax({
                url: url,
                dataType: 'html',
                success: function() {
                    $button.removeClass('updating-message').addClass('updated-message').text('Activated!');
                    setTimeout(function() {
                        // Replace the button with an active button
                        var $parent = $button.parent();
                        $button.remove();
                        $parent.html('<button type="button" class="button button-disabled" disabled="disabled">Active</button>');
                    }, 1000);
                },
                error: function() {
                    $button.removeClass('updating-message').text('Activate');
                    alert('Failed to activate plugin. Please try again or activate from the Plugins page.');
                }
            });
        });
    }
    
    // Initialize theme handlers
    function initThemeHandlers() {
        console.log('Initializing theme handlers');
        // Remove any existing event handlers to prevent duplicates
        $('.theme-actions .install-now').off('click');
        $('.theme-actions .activate-now').off('click');
        
        // Install theme
        $('.theme-actions .install-now').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var slug = $button.data('slug');
            var $themeCard = $button.closest('.theme-card');
            
            console.log('Installing theme:', slug);
            $button.addClass('updating-message').text('Installing...');
            
            wp.updates.installTheme({
                slug: slug,
                success: function(response) {
                    console.log('Theme installed successfully:', response);
                    $button.removeClass('updating-message').addClass('updated-message').text('Installed!');
                    setTimeout(function() {
                        // Replace the button with an activate button
                        var $parent = $button.closest('.theme-actions');
                        $button.remove();
                        var activateButton = $('<button type="button" class="button button-primary activate-now">Activate</button>');
                        activateButton.attr('data-slug', slug);
                        activateButton.attr('data-nonce', wpAllstars.nonce);
                        $parent.prepend(activateButton);
                        
                        // Re-initialize the event handlers
                        initThemeHandlers();
                    }, 1000);
                },
                error: function(response) {
                    console.error('Theme installation failed:', response);
                    $button.removeClass('updating-message').text('Install Now');
                    alert(response.errorMessage);
                }
            });
        });

        // Activate theme
        $('.theme-actions .activate-now').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var slug = $button.data('slug');
            var nonce = $button.data('nonce') || wpAllstars.nonce;
            
            console.log('Activating theme:', slug, 'with nonce:', nonce);
            $button.addClass('updating-message').text('Activating...');
            
            // Use AJAX to activate the theme
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_allstars_activate_theme',
                    theme: slug,
                    _wpnonce: nonce
                },
                success: function(response) {
                    console.log('Theme activation response:', response);
                    if (response.success) {
                        $button.removeClass('updating-message').addClass('updated-message').text('Activated!');
                        setTimeout(function() {
                            // Replace the button with an active button
                            var $parent = $button.closest('.theme-actions');
                            $button.remove();
                            $parent.prepend('<button type="button" class="button button-disabled" disabled="disabled">Active</button>');
                        }, 1000);
                    } else {
                        $button.removeClass('updating-message').text('Activate');
                        alert(response.data || 'Failed to activate theme');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Theme activation AJAX error:', textStatus, errorThrown, jqXHR.responseText);
                    $button.removeClass('updating-message').text('Activate');
                    alert('Failed to activate theme. Please try again or activate from the Themes page.');
                }
            });
        });
    }
});