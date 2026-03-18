/*==========================================================================
 * ORDER EDIT - ORDER DATA HEADING
 *
 * Appends customer name and total to the Order Data section h2.
 ==========================================================================*/

(function($) {
    'use strict';

    function updateOrderDetailsHeading() {
        var orderTotal = $('#order-total-container').data('order-total');
        var customerName = $('#order-total-container').data('customer-name');

        if (orderTotal && customerName) {
            var orderDetailsHeading = $('.woocommerce-order-data > h2:first, .woocommerce-order-data h2, .woocommerce-order-data__heading, h2.woocommerce-order-data__heading');

            if (orderDetailsHeading.length) {
                orderDetailsHeading.find('.ewneater-order-info, .ewneater-order-info-total').remove();

                if (orderDetailsHeading.find('.ewneater-order-info').length === 0) {
                    var customerSpan = $('<span class="ewneater-order-info"> - ' + customerName + '</span>');
                    var totalSpan = $('<span class="ewneater-order-info-total">&nbsp;' + orderTotal + '</span>');
                    orderDetailsHeading.append(customerSpan);
                    orderDetailsHeading.append(totalSpan);
                }

                $('.ewneater-order-info-total').addClass('show');
            }
        }
    }

    $(document).ready(function() {
        updateOrderDetailsHeading();
        setTimeout(updateOrderDetailsHeading, 500);

        $(document).ajaxComplete(function() {
            setTimeout(updateOrderDetailsHeading, 100);
        });

        $(window).on('load', function() {
            setTimeout(updateOrderDetailsHeading, 100);
        });
    });
})(jQuery);
