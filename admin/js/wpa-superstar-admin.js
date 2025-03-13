jQuery(document).ready(function($) {
    $('.wpa-superstar-toggle input').on('change', function() {
        var option = $(this).attr('name');
        var value = $(this).is(':checked') ? 1 : 0;
        $.post(wpaSuperstar.ajaxurl, {
            action: 'wpa_superstar_update_option',
            option: option,
            value: value,
            nonce: wpaSuperstar.nonce
        }, function(response) {
            console.log(response);
        });
    });
});