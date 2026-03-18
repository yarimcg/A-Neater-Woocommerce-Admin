/*==========================================================================
 * PAYMENT METHOD ICONS TOGGLE
 *
 * Screen option for orders list: toggle between Icons Only, Text Only, Both.
 * Saves preference via AJAX; fallback to form submit if AJAX fails.
 ==========================================================================*/

(function($) {
    'use strict';

    $(document).ready(function() {
        if (typeof ewneaterPaymentIcons === 'undefined') {
            return;
        }

        var tablenav = $('.tablenav.bottom');
        if (!tablenav.length) {
            return;
        }

        tablenav.css({
            'display': 'flex',
            'flex-wrap': 'wrap',
            'align-items': 'flex-start'
        });

        var showIcons = ewneaterPaymentIcons.show_icons || '1';
        var checkedIcons = showIcons === '1' ? 'checked' : '';
        var checkedText = showIcons === '0' ? 'checked' : '';
        var checkedBoth = showIcons === '2' ? 'checked' : '';

        var toggleHtml = '<div class="ewneater-payment-toggle" style="margin-top: 20px; margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; width: 100%; box-sizing: border-box; flex-basis: 100%; order: 10;">' +
            '<div style="margin-bottom: 10px;"><strong>Payment Method Display:</strong></div>' +
            '<div>' +
            '<label style="margin-right: 20px; display: inline-block; vertical-align: middle;">' +
            '<input type="radio" name="ewneater_payment_display" value="icons" ' + checkedIcons + ' style="margin-right: 5px; width: 16px; height: 16px; vertical-align: middle;"> Icons Only' +
            '</label>' +
            '<label style="margin-right: 20px; display: inline-block; vertical-align: middle;">' +
            '<input type="radio" name="ewneater_payment_display" value="text" ' + checkedText + ' style="margin-right: 5px; width: 16px; height: 16px; vertical-align: middle;"> Text Only' +
            '</label>' +
            '<label style="display: inline-block; vertical-align: middle;">' +
            '<input type="radio" name="ewneater_payment_display" value="both" ' + checkedBoth + ' style="margin-right: 5px; width: 16px; height: 16px; vertical-align: middle;"> Both Icons & Text' +
            '</label>' +
            '</div></div>';

        tablenav.append(toggleHtml);

        function savePaymentMethodDisplay(displayType) {
            var iconValue = displayType === 'icons' ? '1' : (displayType === 'text' ? '0' : '2');

            $.post(ewneaterPaymentIcons.ajaxurl, {
                action: 'save-ewneater-payment-method-icons',
                ewneater_payment_method_icons: iconValue,
                _wpnonce: ewneaterPaymentIcons.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    fallbackSave(iconValue);
                }
            }).fail(function() {
                fallbackSave(iconValue);
            });
        }

        function fallbackSave(iconValue) {
            var form = $('<form method="post" action="">');
            form.append('<input type="hidden" name="action" value="save-ewneater-payment-method-icons">');
            form.append('<input type="hidden" name="ewneater_payment_method_icons" value="' + iconValue + '">');
            form.append('<input type="hidden" name="_wpnonce" value="' + ewneaterPaymentIcons.nonce + '">');
            $('body').append(form);
            form.submit();
        }

        $('input[name="ewneater_payment_display"]').on('change', function() {
            savePaymentMethodDisplay($(this).val());
        });

        $('input[name="ewneater_payment_display"]').on('click', function() {
            setTimeout(function() {
                savePaymentMethodDisplay($('input[name="ewneater_payment_display"]:checked').val());
            }, 100);
        });
    });
})(jQuery);
