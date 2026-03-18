<?php
/**
 * Orders List Page UI Enhancements
 *
 * Adds layout and filter customisations for the WooCommerce Orders list page (edit-shop_order):
 * - Header breadcrumb: Orders > Status (e.g. Orders > Processing) with Orders linking to All Orders
 * - Wrap bulk actions in left-container, move search box into tablenav
 * - Reorder status filters: Mine to end; Pending payments before Refunded; Drafts before Pending payments; Trash after Failed
 * - Change search button text to "GO"
 * - Sort user role dropdown with top roles first, label "Filter by Customers.."
 *
 * Only loads on the WooCommerce orders list page (edit-shop_order screen).
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/*==========================================================================
 * ORDERS LIST HEADER BREADCRUMB
 *
 * Adds "Orders > Status" to woocommerce-layout__header. "Orders" links to All Orders.
 * Follows same pattern as Edit Order (shop_order) header enhancement.
 ==========================================================================*/
add_action('admin_head', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-shop_order') {
        return;
    }

    $post_status = isset($_GET['post_status']) ? sanitize_text_field(wp_unslash($_GET['post_status'])) : '';
    $status_label = '';
    if ($post_status && function_exists('wc_get_order_status_name')) {
        $status_label = wc_get_order_status_name($post_status);
    }

    $all_orders_url = admin_url('edit.php?post_type=shop_order');
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        var heading = document.querySelector('.woocommerce-layout__header-heading, h1.woocommerce-layout__header-heading') ||
            document.querySelector('.wrap h1.wp-heading-inline') ||
            document.querySelector('#wpbody-content .wrap h1');
        if (!heading) return;
        if (heading.querySelector('.ewneater-orders-breadcrumb')) return;

        var allOrdersUrl = <?php echo wp_json_encode(esc_url($all_orders_url)); ?>;
        var statusLabel = <?php echo wp_json_encode($status_label); ?>;

        var breadcrumb = document.createElement('span');
        breadcrumb.className = 'ewneater-orders-breadcrumb';
        var link = document.createElement('a');
        link.href = allOrdersUrl;
        link.textContent = <?php echo wp_json_encode(__('Orders', 'a-neater-woocommerce-admin')); ?>;
        breadcrumb.appendChild(link);
        if (statusLabel) {
            breadcrumb.appendChild(document.createTextNode(' \u00a0\u003e ' + statusLabel));
        }
        heading.innerHTML = '';
        heading.appendChild(breadcrumb);
    });
    </script>
    <?php
});

add_action('admin_head', function () {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'edit-shop_order') { ?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Wrap bulk actions in a container
			$(".tablenav.top .bulkactions").wrap("<div class=\'left-container\'></div>");

			// Move search box outside and after tablenav
			var $searchBox = $(".search-box");
			$searchBox.appendTo(".tablenav.top");

			// Move "Mine" to the end; "Pending Payments" before Refunded; "Drafts" before Pending payments; "Trash" after Failed
			var $subsubsub = $(".subsubsub");

			// Move "Mine" to the end
			var $mineItem = $subsubsub.find("li a").filter(function() {
				return $(this).text().indexOf("Mine") >= 0;
			}).parent();

			if($mineItem.length) {
				$mineItem.appendTo($subsubsub);
			}

			// Move "Pending Payments" before Refunded
			var $pendingPayments = $subsubsub.find("li a").filter(function() {
				return $(this).text().indexOf("Pending payments") >= 0;
			}).parent();

			var $refunded = $subsubsub.find("li a").filter(function() {
				return $(this).text().indexOf("Refunded") >= 0;
			}).parent();

			if($pendingPayments.length && $refunded.length) {
				$pendingPayments.insertBefore($refunded);
			}

			// Move "Drafts" before Pending payments
			var $drafts = $subsubsub.find("li a").filter(function() {
				return $(this).text().indexOf("Drafts") >= 0;
			}).parent();

			if($drafts.length && $pendingPayments.length) {
				$drafts.insertBefore($pendingPayments);
			}

			var $failed = $subsubsub.find("li a").filter(function() {
				return $(this).text().indexOf("Failed") >= 0;
			}).parent();

			// Move "Trash" after Failed
			var $trash = $subsubsub.find("li a").filter(function() {
				return $(this).text().indexOf("Trash") >= 0;
			}).parent();

			if($trash.length && $failed.length) {
				$trash.insertAfter($failed);
			}

			// Fix search button text
			var $searchButton = $("input[type=submit][value=\'Search orders\']");
			if($searchButton.length) {
				$searchButton.val("GO");
			}
		});

		// Sort user roles dropdown while keeping specific roles at top
		jQuery(document).ready(function($) {
			// Get the role select dropdown
			var $roleSelect = $('select[name="_user_role"]');
			if ($roleSelect.length) {
				// Store all options
				var $options = $roleSelect.find('option');
				var $firstOption = $options.first();
				$firstOption.text('Filter by Customers..'); // Change the text of the first option
				var $topOptions = $();
				var $otherOptions = $();

				// Define the roles that should stay at the top (in your desired order)
				var topRoles = ['wholesale_buyer', 'wholesale_buyer_pending', 'customer', 'guest'];

				// Separate options into top and other
				$options.each(function(i) {
					var $option = $(this);
					var roleValue = $option.val();

					// Always keep the first option at the top
					if (i === 0) return;

					if (topRoles.includes(roleValue)) {
						$topOptions = $topOptions.add($option);
					} else {
						$otherOptions = $otherOptions.add($option);
					}
				});

				// Sort top options by the order in topRoles
				$topOptions = $topOptions.sort(function(a, b) {
					return topRoles.indexOf($(a).val()) - topRoles.indexOf($(b).val());
				});

				// Sort other options alphabetically
				$otherOptions = $otherOptions.sort(function(a, b) {
					return $(a).text().localeCompare($(b).text());
				});

				// Clear and rebuild the select
				$roleSelect.empty();
				$roleSelect.append($firstOption); // Add the modified first option
				$topOptions.each(function() {
					$roleSelect.append($(this));
				});
				if ($topOptions.length && $otherOptions.length) {
					$roleSelect.append('<option disabled>──────────</option>');
				}
				$otherOptions.each(function() {
					$roleSelect.append($(this));
				});

				// Get user role from URL if present
				var urlParams = new URLSearchParams(window.location.search);
				var userRole = urlParams.get('_user_role');

				// Set the selected value if user role is found in URL
				if (userRole) {
					$roleSelect.val(userRole);
				} else {
					// Default to first option if no role in URL
					$roleSelect.val('');
				}
			}
		});
		</script>
		<?php }
});
