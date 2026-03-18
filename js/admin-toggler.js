/*==========================================================================
 * ADMIN TOGGLER
 *
 * Collapsible sections with state saved per user via AJAX.
 ==========================================================================*/

(function($) {
    'use strict';

    $(document).ready(function() {
        if (typeof ewneaterAdminToggler === 'undefined' || !ewneaterAdminToggler.nonce) {
            return;
        }

        $('.ew-toggle-header').on('click', function(e) {
            e.preventDefault();
            var $header = $(this);
            var $container = $header.closest('.ew-toggle-container');
            var $content = $header.next('.ew-toggle-content');
            var toggleId = $container.data('toggle-id');

            $header.toggleClass('collapsed');
            $content.toggleClass('collapsed');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'save_toggle_state',
                    toggle_id: toggleId,
                    collapsed: $header.hasClass('collapsed') ? '1' : '0',
                    nonce: ewneaterAdminToggler.nonce
                }
            });
        });
    });
})(jQuery);
