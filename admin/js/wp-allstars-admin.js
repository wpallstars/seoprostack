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

    // Initialize all toggle switches
    function initToggleSwitches() {
        // Handle toggle switch clicks
        $('.wp-toggle-switch').on('click', function(e) {
            e.stopPropagation();
            var $checkbox = $(this).find('input[type="checkbox"]');
            var isChecked = $checkbox.is(':checked');
            $checkbox.prop('checked', !isChecked).trigger('change');
        });

        // Prevent label clicks from triggering the toggle
        $('.wp-setting-label, .wp-allstars-toggle label').on('click', function(e) {
            e.stopPropagation();
            return false;
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
    }

    // Initialize expandable panels
    function initExpandablePanels() {
        // Handle panel toggle
        $('.wp-allstars-toggle-header').on('click', function(e) {
            if (!$(e.target).closest('.wp-toggle-switch').length) {
                var $settings = $(this).closest('.wp-allstars-toggle').find('.wp-allstars-toggle-settings');
                var isExpanded = $(this).attr('aria-expanded') === 'true';
                
                $(this).attr('aria-expanded', !isExpanded);
                $settings.slideToggle();
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
    }

    // Initialize text inputs
    function initTextInputs() {
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
    }

    // Initialize all components
    initToggleSwitches();
    initExpandablePanels();
    initTextInputs();

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
        
        // Log the nonce for debugging
        console.log('Using nonce:', wpAllstars.nonce);
        
        $.ajax({
            url: ajaxurl,
            type: 'GET',
            data: {
                action: 'wp_allstars_get_plugins',
                category: category || 'minimal',
                _wpnonce: wpAllstars.nonce
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
            type: 'GET',
            data: {
                action: 'wp_allstars_get_theme',
                _wpnonce: wpAllstars.nonce
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
        $('.install-theme').on('click', function() {
            var slug = $(this).data('slug');
            var $button = $(this);
            
            $button.text('Installing...').prop('disabled', true);
            
            wp.updates.installTheme({
                slug: slug,
                success: function(response) {
                    $button.text('Activate').removeClass('install-theme').addClass('activate-theme');
                    $button.prop('disabled', false);
                },
                error: function(response) {
                    $button.text('Error').prop('disabled', false);
                    console.error('Theme installation error:', response);
                }
            });
        });
        
        // Handle theme activation
        $('.activate-theme').on('click', function() {
            var slug = $(this).data('slug');
            var $button = $(this);
            
            $button.text('Activating...').prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_allstars_activate_theme',
                    theme: slug,
                    _wpnonce: wpAllstars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $button.text('Activated').prop('disabled', true);
                        // Optionally redirect to customizer
                        if (response.data && response.data.customize_url) {
                            setTimeout(function() {
                                window.location.href = response.data.customize_url;
                            }, 1000);
                        }
                    } else {
                        $button.text('Error').prop('disabled', false);
                        console.error('Theme activation error:', response);
                    }
                },
                error: function(xhr, status, error) {
                    $button.text('Error').prop('disabled', false);
                    console.error('Theme activation AJAX error:', error);
                }
            });
        });
    }
});