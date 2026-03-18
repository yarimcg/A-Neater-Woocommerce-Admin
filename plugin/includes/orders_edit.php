<?php

/**
 * Mobile-Friendly WooCommerce Orders Page Enhancement
 * 
 * This file improves the WooCommerce orders admin page and Order Edit page with:
 * - Responsive mobile-first design with optimized layouts
 * - Enhanced search with improved visibility and usability
 * - Horizontal scrollable status filters with modern styling
 * - Streamlined navigation and bulk action controls
 * - Refined button and input styling across all devices
 * - Touch-optimized interface for mobile/tablet use
 * 
 * Only loads on the WooCommerce orders admin page (edit-shop_order screen) and Order Edit page (shop_order screen)
 */
add_action('admin_head', function() {
	$screen = get_current_screen();
	if ($screen && ($screen->id === 'edit-shop_order' || $screen->id === 'shop_order')) {
		echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">';
	}
});

/*==========================================================================
 * ADD TAGS DATA TO ORDER COLUMN FOR MOBILE VIEW
 * 
 * Adds order total and tags data to the Order column for mobile display:
 * - Order total with small-screen-only class
 * - Black Sheep Farm Oils indicator for company email orders
 * - Guest indicator for orders without registered users
 * - New Customer indicator for users with only 1 order
 * All tags are wrapped with small-screen-only class for mobile-only display
 ==========================================================================*/

add_filter('manage_shop_order_posts_custom_column', function($column, $post_id) {
	if ($column === 'order_number') {
		$order = wc_get_order($post_id);
		if ($order) {
			$total = $order->get_formatted_order_total();
			echo '<div class="order_total small-screen-only">' . $total . '</div>';

			// Add Tags data for mobile view
			$user = $order->get_user();
			$billing_email = $order->get_billing_email();
			
			// Black Sheep Farm Oils indicator - check for company email
			$is_blacksheep_order = ewneater_is_company_email($billing_email);
			if ($is_blacksheep_order) {
				echo '<div class="order_tags small-screen-only">';
				echo '<mark class="order-status guest ewneater-order-number-box">';
				echo "<span>🐑 Black Sheep</span>";
				echo "</mark>";
				echo '</div>';
			}
			
			// // Guest indicator
			// if (!$user) {
			//     echo '<div class="order_tags small-screen-only">';
			//     echo '<mark class="order-status guest ewneater-order-number-box">';
			//     echo "<span>Guest</span>";
			//     echo "</mark>";
			//     echo '</div>';
			// }

			// New Customer indicator - using same logic as desktop version
			if (!$is_blacksheep_order && $billing_email) {
				$order_status = $order ? $order->get_status() : "";
				if (in_array($order_status, [
					"failed",
					"cancelled",
					"trash",
					"refunded",
					"pending",
				])) {
					// For failed/cancelled/etc, show N/A or 0, skip all lookups/logs
					$order_count = 0;
				} else {
					$order_count = wc_get_orders([
						"customer" => $billing_email,
						"limit" => -1,
						"return" => "ids",
						"status" => [
							"wc-processing",
							"wc-completed",
							"wc-on-hold",
						],
					]);

					if (is_array($order_count) && count($order_count) === 1) {
						echo '<div class="order_tags small-screen-only">';
						echo '<mark class="order-status new_customer ewneater-order-number-box">';
						echo "<span>New Customer</span>";
						echo "</mark>";
						echo '</div>';
					}
				}
			}
		}
	}
}, 20, 2);


