/**
 * WP Allstars UI Enhancements
 *
 * Handles interactions for enhanced UI components
 */
(function($) {
    'use strict';

    // UI Components Object
    var WPAallstarsUI = {
        /**
         * Initialize all UI components
         */
        init: function() {
            this.initAccordions();
            this.initNotifications();
            this.initCards();
            
            // Initialize templates
            this.initTemplates();
        },

        /**
         * Initialize accordion functionality
         */
        initAccordions: function() {
            // Handle accordion toggle
            $(document).on('click', '.wp-allstars-accordion-header', function() {
                var $header = $(this);
                var $content = $header.next('.wp-allstars-accordion-content');
                var isExpanded = $header.attr('aria-expanded') === 'true';
                
                // Toggle aria-expanded attribute
                $header.attr('aria-expanded', !isExpanded);
                
                // Toggle content visibility with animation
                $content.slideToggle(200);
            });
        },

        /**
         * Initialize notification system
         */
        initNotifications: function() {
            // Handle notification dismissal
            $(document).on('click', '.wp-allstars-notification-dismiss', function() {
                $(this).closest('.wp-allstars-notification').fadeOut(200, function() {
                    $(this).remove();
                });
            });
            
            // Auto-dismiss success notifications after 5 seconds
            setTimeout(function() {
                $('.wp-allstars-notification-success').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Initialize card components
         */
        initCards: function() {
            // Add any card-specific interactions here
            $('.wp-allstars-card').on('mouseenter', function() {
                $(this).addClass('wp-allstars-card-hover');
            }).on('mouseleave', function() {
                $(this).removeClass('wp-allstars-card-hover');
            });
        },
        
        /**
         * Initialize Underscore.js templates
         */
        initTemplates: function() {
            // Only proceed if wp.template is available (WordPress admin)
            if (typeof wp !== 'undefined' && wp.template) {
                // Store template functions for easy access
                this.templates = {
                    accordion: wp.template('wp-allstars-accordion'),
                    card: wp.template('wp-allstars-card'),
                    notification: wp.template('wp-allstars-notification')
                };
            }
        },
        
        /**
         * Create an accordion
         * 
         * @param {Object} data Accordion data with id, title, and content
         * @param {jQuery|String} target Where to append the accordion
         * @return {jQuery} The created accordion element
         */
        createAccordion: function(data, target) {
            if (!this.templates || !this.templates.accordion) {
                return $('<div>').text('Template not available');
            }
            
            var $accordion = $(this.templates.accordion(data));
            
            if (target) {
                if (typeof target === 'string') {
                    $(target).append($accordion);
                } else {
                    target.append($accordion);
                }
            }
            
            return $accordion;
        },
        
        /**
         * Create a card
         * 
         * @param {Object} data Card data with header, content, footer, and icon
         * @param {jQuery|String} target Where to append the card
         * @return {jQuery} The created card element
         */
        createCard: function(data, target) {
            if (!this.templates || !this.templates.card) {
                return $('<div>').text('Template not available');
            }
            
            var $card = $(this.templates.card(data));
            
            if (target) {
                if (typeof target === 'string') {
                    $(target).append($card);
                } else {
                    target.append($card);
                }
            }
            
            return $card;
        },
        
        /**
         * Create a notification
         * 
         * @param {Object} data Notification data with type, title, and message
         * @param {jQuery|String} target Where to append the notification
         * @return {jQuery} The created notification element
         */
        createNotification: function(data, target) {
            if (!this.templates || !this.templates.notification) {
                return $('<div>').text('Template not available');
            }
            
            // Set default type if not provided
            data.type = data.type || 'info';
            
            var $notification = $(this.templates.notification(data));
            
            if (target) {
                if (typeof target === 'string') {
                    $(target).prepend($notification);
                } else {
                    target.prepend($notification);
                }
                
                // Auto-dismiss success notifications
                if (data.type === 'success') {
                    setTimeout(function() {
                        $notification.fadeOut(300, function() {
                            $(this).remove();
                        });
                    }, 5000);
                }
            }
            
            return $notification;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Initialize UI components
        WPAallstarsUI.init();
        
        // Make UI components available globally for use elsewhere
        window.WPAallstarsUI = WPAallstarsUI;
    });

})(jQuery); 