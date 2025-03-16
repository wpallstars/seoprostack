jQuery(document).ready(function($) {
    // Function to show notification
    function showNotification(message, element, isError = false) {
        // Remove any existing notifications
        $('.wp-setting-notification').remove();
        
        // Create notification element
        var $notification = $('<span class="wp-setting-notification' + (isError ? ' error' : '') + '">' + message + '</span>');
        
        // If element is provided, show notification next to it
        if (element) {
            $(element).after($notification);
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
    function updateOption(option, value, element) {
        return $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
            action: 'wp_allstars_update_option',
            option: option,
            value: value,
                _wpnonce: wpAllstars.nonce
            }
        }).then(function(response) {
            if (!response.success) {
                throw new Error(response.data || 'Error saving setting');
            }
            return response;
        });
    }

    // Toggle settings panel
    $('.wp-allstars-toggle-switch').on('click', function(e) {
        e.stopPropagation();
        $(this).closest('.wp-allstars-toggle-main').find('.wp-allstars-toggle-settings').slideToggle(200);
        $(this).closest('.wp-allstars-toggle-main').toggleClass('expanded');
    });

    // Handle click on the main toggle area (but not on the switch itself)
    $('.wp-allstars-toggle-main').on('click', function(e) {
        if (!$(e.target).hasClass('wp-allstars-toggle-switch') && 
            !$(e.target).closest('.wp-allstars-toggle-switch').length && 
            !$(e.target).closest('.wp-allstars-toggle-label').length) {
            $(this).find('.wp-allstars-toggle-settings').slideToggle(200);
            $(this).toggleClass('expanded');
        }
    });

    // Prevent toggle when clicking on the label
    $('.wp-allstars-toggle-label').on('click', function(e) {
        e.stopPropagation();
    });

    // Initialize tabs
    $('.nav-tab-wrapper .nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).data('tab');
        
        // Update active tab
        $('.nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Show target tab content
        $('.tab-content').hide();
        $('#' + target).show();
        
        // Update URL hash
        window.location.hash = target;
        
        // Load plugins if needed
        if (target === 'recommended' && $('#wpa-plugin-list').length && $('#wpa-plugin-list').is(':empty')) {
            loadPlugins('all');
        }
        
        // Load themes if needed
        if (target === 'theme' && $('#wpa-theme-list').length && $('#wpa-theme-list').is(':empty')) {
            loadTheme();
        }
    });
    
    // Initialize based on hash
    if (window.location.hash) {
        var hash = window.location.hash.substring(1);
        $('.nav-tab-wrapper .nav-tab[data-tab="' + hash + '"]').trigger('click');
    } else {
        $('.nav-tab-wrapper .nav-tab:first').trigger('click');
    }
    
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
            loadPlugins('all');
        }
    }
    
    // Load theme tab content if we're on the theme tab
    if ($('#theme').is(':visible') && $('#wpa-theme-list').length && $('#wpa-theme-list').is(':empty')) {
        loadTheme();
    }
    
    // Function to load plugins
    function loadPlugins(category) {
        var $container = $('#wpa-plugin-list');
        var $loadingOverlay = $('<div class="wp-allstars-loading-overlay"><div class="wp-allstars-loading-spinner"></div></div>');
        
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
    
    // Function to load themes
    function loadTheme() {
        var $container = $('#wpa-theme-list');
        var $loadingOverlay = $('<div class="wp-allstars-loading-overlay"><div class="wp-allstars-loading-spinner"></div></div>');
        
        // Show loading overlay
        $container.css('position', 'relative').append($loadingOverlay);
        
        // Clear existing themes
        $container.empty().append($loadingOverlay);
        
        // AJAX request to get themes
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_allstars_get_themes',
                _wpnonce: wpAllstars.nonce
            },
            success: function(response) {
                // Remove loading overlay
                $loadingOverlay.remove();
                
                if (response.success) {
                    // Append themes HTML
                    $container.html(response.data);
                    
                    // Initialize theme action buttons
                    initThemeHandlers();
                } else {
                    // Show error message
                    $container.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                // Remove loading overlay
                $loadingOverlay.remove();
                
                // Show error message
                $container.html('<div class="notice notice-error"><p>Failed to load themes. Please try again. Error: ' + error + '</p></div>');
                console.error('AJAX Error:', xhr.responseText);
            }
        });
    }
    
    // Initialize plugin action buttons
    function initPluginActions() {
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
                        $button.removeClass('updated-message install-now')
                               .addClass('activate-now')
                               .text('Activate')
                               .attr('href', response.activateUrl);
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
            
            $button.addClass('updating-message').text('Activating...');
            
            $.ajax({
                url: url,
                dataType: 'html',
                success: function() {
                    $button.removeClass('updating-message').addClass('updated-message').text('Activated!');
                    setTimeout(function() {
                        $button.removeClass('activate-now updated-message')
                               .addClass('button-disabled')
                               .text('Active');
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
        // Install theme
        $('.theme-browser .theme-actions .install-now').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var slug = $button.data('slug');
            var $themeCard = $button.closest('.theme');
            
            $button.addClass('updating-message').text('Installing...');
            
            wp.updates.installTheme({
                slug: slug,
                success: function(response) {
                    $button.removeClass('updating-message').addClass('updated-message').text('Installed!');
                    setTimeout(function() {
                        $button.removeClass('updated-message install-now')
                               .addClass('activate-now')
                               .text('Activate')
                               .attr('href', response.activateUrl);
                    }, 1000);
                },
                error: function(response) {
                    $button.removeClass('updating-message').text('Install Now');
                    alert(response.errorMessage);
                }
            });
        });
        
        // Activate theme
        $('.theme-browser .theme-actions .activate-now').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var url = $button.attr('href');
            
            $button.addClass('updating-message').text('Activating...');
            
            $.ajax({
                url: url,
                dataType: 'html',
                success: function() {
                    $button.removeClass('updating-message').addClass('updated-message').text('Activated!');
                    setTimeout(function() {
                        $('.theme-browser .theme').removeClass('active');
                        $button.closest('.theme').addClass('active');
                        $button.removeClass('activate-now updated-message')
                               .addClass('button-disabled')
                               .text('Active');
                    }, 1000);
                },
                error: function() {
                    $button.removeClass('updating-message').text('Activate');
                    alert('Failed to activate theme. Please try again or activate from the Themes page.');
                }
            });
        });
    }
});