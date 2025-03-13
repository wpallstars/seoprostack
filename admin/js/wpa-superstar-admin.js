jQuery(document).ready(function($) {
    $('.wpa-superstar-toggle input').on('change', function() {
        var $input = $(this);
        var option = $input.attr('name');
        var value = $input.is(':checked') ? 1 : 0;
        $.post(wpaSuperstar.ajaxurl, {
            action: 'wpa_superstar_update_option',
            option: option,
            value: value,
            nonce: wpaSuperstar.nonce
        }, function(response) {
            if (response.success) {
                $input.next('.wpa-status').remove();
                $input.after('<span class="wpa-status" style="color: green; margin-left: 10px;">Saved</span>');
                setTimeout(function() { $('.wpa-status').fadeOut(); }, 2000);
            } else {
                console.error('Error:', response);
            }
        });
    });
});