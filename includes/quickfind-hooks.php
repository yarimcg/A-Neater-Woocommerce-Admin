<?php
/*==========================================================================
 * WOOCOMMERCE QUICK FIND - FRONTEND HOOKS
 *
 * This file contains hooks for the Quick Find feature that need to run on
 * the frontend (not just in admin). It primarily handles:
 *
 * - Order indexing for various order creation paths
 * - Customer indexing for new customers
 * - Logging for debugging purposes
 *
 * This file is loaded even when is_admin() is false to ensure orders created
 * through frontend checkout are properly indexed.
 ==========================================================================*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the quickfind functionality if not already included
if (!class_exists('EWNeater_Quick_Find')) {
    require_once dirname(__FILE__) . '/quickfind.php';
}

// Include the logger if not already included
if (!class_exists('EWNeater_Quick_Find_Logger')) {
    require_once dirname(__FILE__) . '/quickfind-logger.php';
}

/*==========================================================================
 * ORDER INDEXING HOOKS
 *
 * This section handles order indexing for all possible order creation paths:
 *
 * 1. Block-based Checkout (WooCommerce Blocks)
 *    - Uses woocommerce_store_api_checkout_order_processed
 *    - Fires when an order is created through the block-based checkout
 *    - Primary hook for modern WooCommerce stores
 *
 * 2. Legacy/Classic Checkout
 *    - Uses woocommerce_new_order for initial order creation
 *    - Uses woocommerce_checkout_order_created as backup
 *    - Ensures compatibility with older checkout methods
 *
 * 3. Programmatic Order Creation
 *    - Uses wp_insert_post as a catch-all
 *    - Catches orders created via:
 *      * Admin interface
 *      * REST API
 *      * Custom code
 *      * Import tools
 *    - Only triggers on new orders (!$update)
 *
 * Each hook includes:
 * - Class existence check for safety
 * - Order ID extraction
 * - Index update via handle_new_order()
 * - Detailed logging for debugging
 ==========================================================================*/

// === BEGIN: Order Indexing Hooks ===

// Block-based checkout
add_action('woocommerce_store_api_checkout_order_processed', function($order) {
    if (class_exists('EWNeater_Quick_Find')) {
        EWNeater_Quick_Find::handle_new_order($order->get_id());
    }
}, 10, 1);

// Legacy/classic checkout
add_action('woocommerce_new_order', function($order_id) {
    if (class_exists('EWNeater_Quick_Find')) {
        EWNeater_Quick_Find::handle_new_order($order_id);
    }
}, 10, 1);

// Legacy/classic checkout
add_action('woocommerce_checkout_order_created', function($order) {
    if (class_exists('EWNeater_Quick_Find')) {
        EWNeater_Quick_Find::handle_new_order($order->get_id());
    }
}, 10, 1);

// Fallback catch-all for programmatic order creation
add_action('wp_insert_post', function($post_id, $post, $update) {
    if (
        !$update &&
        $post->post_type === 'shop_order' &&
        class_exists('EWNeater_Quick_Find')
    ) {
        EWNeater_Quick_Find::handle_new_order($post_id);
    }
}, 10, 3);

// REST API order creation
add_action('woocommerce_rest_insert_shop_order', function($order, $request, $creating) {
    if ($creating && class_exists('EWNeater_Quick_Find')) {
        EWNeater_Quick_Find::handle_new_order($order->get_id());
    }
}, 10, 3);

// === END: Order Indexing Hooks ===

// === BEGIN: Customer Indexing Hooks ===

// Index new customers when they register
add_action('user_register', function($user_id) {
    // Only index if they're a customer
    if (class_exists('EWNeater_Quick_Find') && wc_customer_bought_product('', $user_id, '')) {
        EWNeater_Quick_Find::handle_new_customer($user_id);
    }
}, 10, 1);

// === END: Customer Indexing Hooks ===

// === BEGIN: Logging Setup ===

// Order Events
add_action('woocommerce_store_api_checkout_order_processed', function($order) {
    if (class_exists('EWNeater_Quick_Find_Logger')) {
        EWNeater_Quick_Find_Logger::info(
            sprintf('Block checkout order processed: Order ID %d', $order->get_id())
        );
    }
});

add_action('woocommerce_new_order', function($order_id) {
    if (class_exists('EWNeater_Quick_Find_Logger')) {
        EWNeater_Quick_Find_Logger::info(
            sprintf('New order created: Order ID %d', $order_id)
        );
    }
});

add_action('woocommerce_checkout_order_created', function($order) {
    if (class_exists('EWNeater_Quick_Find_Logger')) {
        EWNeater_Quick_Find_Logger::info(
            sprintf('Checkout order created: Order ID %d', $order->get_id())
        );
    }
});

add_action('wp_insert_post', function($post_id, $post, $update) {
    if (!$update && $post->post_type === 'shop_order' && class_exists('EWNeater_Quick_Find_Logger')) {
        EWNeater_Quick_Find_Logger::info(
            sprintf('Programmatic order created: Order ID %d', $post_id)
        );
    }
}, 10, 3);

add_action('woocommerce_rest_insert_shop_order', function($order, $request, $creating) {
    if ($creating && class_exists('EWNeater_Quick_Find_Logger')) {
        EWNeater_Quick_Find_Logger::info(
            sprintf('REST API order created: Order ID %d', $order->get_id())
        );
    }
}, 10, 3);

// === END: Logging Setup ===
