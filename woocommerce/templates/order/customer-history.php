<?php
/**
 * Display the Customer History metabox.
 *
 * This template is used to display the customer history metabox on the edit order screen.
 *
 * @see     Automattic\WooCommerce\Internal\Admin\Orders\MetaBoxes\CustomerHistory
 * @package WooCommerce\Templates
 * @version 8.7.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Variables used in this file.
 *
 * @var int   $orders_count   The number of paid orders placed by the current customer.
 * @var float $total_spend   The total money spent by the current customer.
 * @var float $avg_order_value The average money spent by the current customer.
 */
?>

<div class="customer-history order-attribution-metabox">
<?php

	$my_orders_count = 0;
	$my_total_spend = 0;
	$my_avg_order_value = 0;
	$diff_time = 0;
	$diff_time_html = 'N/A';

    $current_order = new WC_Order(get_the_ID());
	$billing_email = $current_order->get_billing_email();

	if ($billing_email) {
		$orders = wc_get_orders([
			'billing_email' => $billing_email,
			'limit' => -1,
		]);
		$first_time = current_time('timestamp');
		for ($i = 0; $i < count($orders); $i++) {
			$order = $orders[$i];
			if ($order->has_status(array('cancelled', 'failed'))) {
				continue;
			}

			$my_orders_count++;
			$my_total_spend += $order->get_total();

			$time = $order->get_date_created()->getOffsetTimestamp();
			if ($time < $first_time) {
				$first_time = $time;
			}
		}

		if ($my_orders_count > 0) {
			$my_avg_order_value = $my_total_spend / $my_orders_count;
		}

		$diff_time = current_time('timestamp') - $first_time;
		if ($diff_time > 365 * 86400) {
			$diff_time_html = number_format($diff_time / 365 / 86400, 1) . ' years';
		} elseif ($diff_time > 30 * 86400) {
			$diff_time_html = number_format($diff_time / 30 / 86400, 1) . ' months';
		} else {
			$diff_time_html = number_format($diff_time / 86400, 0) . ' days';
		}
		$diff_time_html .= ' (since ' . date('d M Y', $first_time) . ')';
	}
?>

	<h4>
		<?php
		esc_html_e( 'Customer age', 'woocommerce' );
		echo wp_kses_post(
			wc_help_tip(
				__( 'Time since the first non-cancelled, non-failed order for this billing email.', 'woocommerce' )
			)
		);
		?>
	</h4>

	<span class="order-attribution-total-orders">
		<?php echo esc_html( $diff_time_html ); ?>
	</span>

	<h4>
		<?php
		esc_html_e( 'Total orders', 'woocommerce' );
		echo wp_kses_post(
			wc_help_tip(
				__( 'Total number of non-cancelled, non-failed orders for this billing email, including the current one.', 'woocommerce' )
			)
		);
		?>
	</h4>

	<span class="order-attribution-total-orders">
		<?php echo esc_html( $my_orders_count ); ?>
	</span>

	<h4>
		<?php
		esc_html_e( 'Total revenue', 'woocommerce' );
		echo wp_kses_post(
			wc_help_tip(
				__( "This is the Customer Lifetime Value, or the total amount you have earned from this billing email's orders.", 'woocommerce' )
			)
		);
		?>
	</h4>
	<span class="order-attribution-total-spend">
		<?php echo wp_kses_post( wc_price( $my_total_spend ) ); ?>
	</span>

	<h4><?php esc_html_e( 'Average order value', 'woocommerce' ); ?></h4>
	<span class="order-attribution-average-order-value">
		<?php echo wp_kses_post( wc_price( $my_avg_order_value ) ); ?>
	</span>

	<h4>
		<?php
		esc_html_e( 'Average order daterange', 'woocommerce' );
		echo wp_kses_post(
			wc_help_tip(
				__( 'Average gap since the first non-cancelled, non-failed orders for this billing email.', 'woocommerce' )
			)
		);
		?>
	</h4>

	<span class="order-attribution-total-orders">
		<?php echo ($my_orders_count > 0 ? 'Every ' . number_format($diff_time / 86400 / $my_orders_count, 0) . ' days' : 'N/A'); ?>
	</span>
</div>
