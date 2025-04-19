/**
 * WP Allstars Admin Script
 *
 * Handles generic UI interactions in the admin settings page.
 * Specific AJAX handlers for themes/plugins are handled in inline scripts
 * loaded by their respective managers.
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // --- General UI Enhancements ---

        // Toggle expandable sections/panels (using consistent class names)
        $('.wpallstars-toggle-header').on('click', function() {
            var $this = $(this);
            var $content = $this.next('.wpallstars-toggle-content'); // Use a consistent content class
            var isExpanded = $this.attr('aria-expanded') === 'true';

            // Toggle ARIA attribute for accessibility
            $this.attr('aria-expanded', !isExpanded);

            // Toggle content visibility with animation
            $content.slideToggle(200);

            // Optional: Toggle an icon class on the header (e.g., dashicons-arrow-down / dashicons-arrow-up)
            $this.find('.dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
        });

        // Initialize state for expandable sections (ensure icons are correct on load)
        $('.wpallstars-toggle-header').each(function() {
            var $this = $(this);
            var isExpanded = $this.attr('aria-expanded') === 'true';
            if (isExpanded) {
                $this.find('.dashicons').removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
                $this.next('.wpallstars-toggle-content').show();
            } else {
                 $this.find('.dashicons').removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
                $this.next('.wpallstars-toggle-content').hide();
            }
        });


        // --- Settings Save Logic (Example for simple toggles/inputs via AJAX) ---

        // AJAX saving for specific settings (e.g., checkboxes, selects)
        // Add the class 'wpallstars-ajax-save' and 'data-setting-key="your_key"' to the input elements
        $('.wpallstars-settings-wrap').on('change', 'input.wpallstars-ajax-save, select.wpallstars-ajax-save', function(e) {
            e.preventDefault();

            const $input = $(this);
            const settingKey = $input.data('setting-key'); // Get key from data attribute
            let settingValue;

            // Determine the value based on input type
            if ($input.is(':checkbox')) {
                settingValue = $input.is(':checked'); // Send true/false
            } else {
                settingValue = $input.val(); // Get value from select or other inputs
            }

            // Find the feedback element (could be next to the input, or a general area)
            let $feedback = $input.siblings('.wpallstars-ajax-feedback');
            if (!$feedback.length) {
                // Fallback to a general feedback area if specific one not found
                $feedback = $input.closest('td, p, div').find('.wpallstars-ajax-feedback');
                if (!$feedback.length) {
                     // If still no feedback area, maybe create one dynamically or log error
                     console.error('WPAllstars: Feedback element not found for AJAX save.', $input);
                     return; // Stop if no feedback area
                }
            }

            // Check if settingKey is valid
            if (!settingKey) {
                console.error('WPAllstars: Missing data-setting-key attribute for AJAX save.', $input);
                $feedback.removeClass('spinner success is-active').addClass('error').text('Error: Missing setting key.').show();
                return;
            }

            // Show spinner/updating message
            if ($feedback.hasClass('spinner')) {
                 // If it's already designed as a spinner container
                 $feedback.removeClass('success error').addClass('is-active').show();
                 // Optionally add text: $feedback.text(wpallstars.l10n.updating || 'Updating...');
            } else {
                $feedback.removeClass('success error').addClass('spinner is-active').text(wpallstars.l10n.updating || 'Updating...').show();
            }

            // Use localized strings and the specific nonce
            // $feedback.text(wpallstars.l10n.updating || 'Updating...'); // Moved spinner text above


            // Perform AJAX request
            $.ajax({
                url: wpallstars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpallstars_update_option', // The action hook in WPALLSTARS_Admin_Manager
                    nonce: wpallstars.update_option_nonce, // Use the specific nonce
                    setting_key: settingKey,      // Send the setting key
                    setting_value: settingValue   // Send the setting value
                },
                success: function(response) {
                    if (response.success) {
                        $feedback.removeClass('spinner is-active error').addClass('success').text(response.data || wpallstars.l10n.success || 'Saved!');
                        // Optionally fade out the success message after a delay
                        setTimeout(function() {
                            // $feedback.fadeOut();
                            // Or just remove the text/class if element should stay
                            $feedback.removeClass('success').text(''); 
                        }, 3000); 
                    } else {
                        const errorMessage = response.data || wpallstars.l10n.error || 'Error';
                        $feedback.removeClass('spinner is-active success').addClass('error').text(errorMessage);
                         console.error('WPAllstars AJAX Error:', response.data);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $feedback.removeClass('spinner is-active success').addClass('error').text(wpallstars.l10n.error || 'Error');
                    console.error('WPAllstars AJAX Request Failed:', textStatus, errorThrown);
                },
                // Removed complete: function() { ... } as feedback is handled in success/error
            });
        });

        // --- Tab Handling ---
        // Basic tab switching is handled by WordPress core CSS (.nav-tab-active) and page reloads.
        // If you need AJAX tab loading in the future, implement it here.

        // --- Initialization ---
        // Add any code that needs to run on page load after elements are ready.
        // Example: Initialize color pickers if added via WPALLSTARS_Settings_Manager


    }); // End document ready

})(jQuery);