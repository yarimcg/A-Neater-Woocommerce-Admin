<?php
/*==========================================================================
 * WOOCOMMERCE QUICK FIND - CORE FUNCTIONALITY
 *
 * This file contains core functionality for the Quick Find feature that's
 * shared between admin and frontend code. It includes:
 *
 * - Database table creation and management
 * - Order and customer indexing functions
 * - Utility methods for searching and processing
 *
 * This core file is included by both admin-only and frontend components.
 ==========================================================================*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the logger if not already included
if (!class_exists('EWNeater_Quick_Find_Logger')) {
    require_once dirname(__FILE__) . '/quickfind-logger.php';
}

class EWNeater_Quick_Find_Core {

    /*==========================================================================
     * DATABASE FUNCTIONS
     *
     * Functions for creating and managing the search index table
     ==========================================================================*/

    /**
     * Creates the search index table if it doesn't exist
     */
    public static function create_search_index_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Check if table already exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                object_id bigint(20) NOT NULL,
                object_type varchar(20) NOT NULL,
                search_text text NOT NULL,
                display_text text NOT NULL,
                additional_data longtext,
                date_created datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                PRIMARY KEY  (id),
                KEY object_id (object_id),
                KEY object_type (object_type),
                FULLTEXT KEY search_text (search_text)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            // Log table creation
            EWNeater_Quick_Find_Logger::info('Quick Find - Created search index table');
        }
    }

    /*==========================================================================
     * INDEX BUILDING FUNCTIONS
     *
     * Functions for building and maintaining the search index
     ==========================================================================*/

    /**
     * Builds the full search index (orders and customers)
     */
    public static function build_search_index() {
        EWNeater_Quick_Find_Logger::info('Quick Find - Starting search index build...');
        $start_time = microtime(true);

        // Ensure table exists
        self::create_search_index_table();

        // Clear existing index
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';
        $wpdb->query("TRUNCATE TABLE $table");

        // Build order index
        self::build_order_index_only();

        // Build customer index
        self::build_customer_index_only();

        // Update meta information
        $end_time = microtime(true);
        $build_time = round($end_time - $start_time, 2);

        $meta = array(
            'last_build' => current_time('mysql'),
            'build_time' => $build_time,
            'count_orders' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE object_type = 'order'"),
            'count_customers' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE object_type = 'customer'"),
        );

        update_option('ewneater_quick_find_meta', $meta);

        EWNeater_Quick_Find_Logger::info(sprintf('Quick Find - Index build complete. Time: %s seconds', $build_time));

        return $meta;
    }

    /**
     * Builds only the customer portion of the search index
     */
    public static function build_customer_index_only() {
        $start_time = microtime(true);
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Clear existing customer entries
        $wpdb->query("DELETE FROM $table WHERE object_type = 'customer'");

        // Get all customers who have placed orders, including those without purchases and guest orders
        $customer_ids = $wpdb->get_col("
            SELECT DISTINCT customer_id
            FROM {$wpdb->prefix}wc_orders
            WHERE customer_id > 0
            UNION
            SELECT DISTINCT user_id
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
            WHERE um.meta_key = 'billing_email'
            UNION
            SELECT DISTINCT -1 * order_id as customer_id
            FROM {$wpdb->prefix}wc_orders
            WHERE customer_id = 0
        ");

        $count = 0;
        foreach ($customer_ids as $customer_id) {
            self::handle_new_customer($customer_id);
            $count++;
        }

        $end_time = microtime(true);
        $build_time = round($end_time - $start_time, 2);

        EWNeater_Quick_Find_Logger::info(sprintf('Quick Find - Customer index build complete. %d customers indexed in %s seconds', $count, $build_time));

        return array(
            'count' => $count,
            'time' => $build_time
        );
    }

    /**
     * Builds only the order portion of the search index
     */
    public static function build_order_index_only() {
        $start_time = microtime(true);
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Clear existing order entries
        $wpdb->query("DELETE FROM $table WHERE object_type = 'order'");

        // Get orders (use WooCommerce's data store)
        $args = array(
            'limit' => -1,
            'type' => 'shop_order',
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'ids',
        );

        $orders = wc_get_orders($args);
        $count = 0;

        foreach ($orders as $order_id) {
            self::handle_new_order($order_id);
            $count++;
        }

        $end_time = microtime(true);
        $build_time = round($end_time - $start_time, 2);

        EWNeater_Quick_Find_Logger::info(sprintf('Quick Find - Order index build complete. %d orders indexed in %s seconds', $count, $build_time));

        return array(
            'count' => $count,
            'time' => $build_time
        );
    }

    /*==========================================================================
     * INDIVIDUAL INDEXING FUNCTIONS
     *
     * Functions for indexing individual orders and customers
     ==========================================================================*/

    /**
     * Indexes a single order
     */
    public static function handle_new_order($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Skip draft orders that have no meaningful customer information
        // Note: Draft orders always have state and total, but these are auto-generated
        // and should not be considered as meaningful customer information
        if ($order->get_status() === 'checkout-draft') {
            $has_customer_info = (
                !empty($order->get_billing_first_name()) ||
                !empty($order->get_billing_last_name()) ||
                !empty($order->get_billing_company()) ||
                !empty($order->get_billing_email())
            );

            if (!$has_customer_info) {
                // Remove any existing entry for this draft order
                $wpdb->delete($table, array(
                    'object_id' => $order_id,
                    'object_type' => 'order'
                ));

                // Log that we're skipping this draft order
                EWNeater_Quick_Find_Logger::info(sprintf('Quick Find - Skipping draft order #%s with no customer details (only state/total present)', $order->get_order_number()));

                return false;
            }
        }

        // Remove existing entry if any
        $wpdb->delete($table, array(
            'object_id' => $order_id,
            'object_type' => 'order'
        ));

        // Build search text - include both shipping and billing addresses for search
        $search_text_parts = array(
            '#' . $order->get_order_number(),
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $order->get_billing_company(),
            $order->get_shipping_city(),
            $order->get_shipping_state(),
            $order->get_shipping_country(),
            $order->get_billing_city(),
            $order->get_billing_state(),
            $order->get_billing_country(),
            $order->get_status(),
            $order->get_total()
        );

        // Clean and join search text
        $search_text = implode(' ', array_filter($search_text_parts));

        // Build display text
        $display_text = sprintf(
            '#%s - %s %s',
            $order->get_order_number(),
            $order->get_billing_first_name(),
            $order->get_billing_last_name()
        );

        if ($order->get_billing_company()) {
            $display_text .= ' (' . $order->get_billing_company() . ')';
        }

        // Check if billing and shipping addresses differ
        $shipping_address = $order->get_shipping_city() . ', ' . $order->get_shipping_state();
        $billing_address = $order->get_billing_city() . ', ' . $order->get_billing_state();
        $addresses_differ = ($shipping_address !== $billing_address &&
                           !empty($order->get_billing_city()) &&
                           !empty($order->get_shipping_city()));

        // Additional data for display
        $additional_data = array(
            'status' => $order->get_status(),
            'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
            'total' => $order->get_formatted_order_total(),
            'location' => $order->get_shipping_city() ? $order->get_shipping_city() . ', ' . $order->get_shipping_state() : '',
            'country' => $order->get_shipping_country(),
            'billing_location' => $order->get_billing_city() ? $order->get_billing_city() . ', ' . $order->get_billing_state() : '',
            'addresses_differ' => $addresses_differ
        );

        // Insert into index
        $wpdb->insert($table, array(
            'object_id' => $order_id,
            'object_type' => 'order',
            'search_text' => $search_text,
            'display_text' => $display_text,
            'additional_data' => maybe_serialize($additional_data),
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : current_time('mysql')
        ));

        return true;
    }

    /**
     * Indexes a single customer
     */
    public static function handle_new_customer($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Get user
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // Remove existing entry if any
        $wpdb->delete($table, array(
            'object_id' => $user_id,
            'object_type' => 'customer'
        ));

        // Get customer data
        $customer = new WC_Customer($user_id);

        // Build search text
        $search_text_parts = array(
            $user->display_name,
            $user->user_email,
            $customer->get_first_name(),
            $customer->get_last_name(),
            $customer->get_billing_company(),
            $customer->get_shipping_city(),
            $customer->get_shipping_state(),
            $customer->get_shipping_country()
        );

        // Clean and join search text
        $search_text = implode(' ', array_filter($search_text_parts));

        // Build display text
        $display_text = $user->display_name;

        if ($customer->get_billing_company()) {
            $display_text .= ' (' . $customer->get_billing_company() . ')';
        }

        // Additional data for display
        $additional_data = array(
            'email' => $user->user_email,
            'orders_count' => wc_get_customer_order_count($user_id),
            'total_spent' => wc_price($customer->get_total_spent()),
            'location' => $customer->get_shipping_city() ? $customer->get_shipping_city() . ', ' . $customer->get_shipping_state() : '',
            'country' => $customer->get_shipping_country(),
            'last_order' => self::get_customer_last_order_date($user_id)
        );

        // Insert into index
        $wpdb->insert($table, array(
            'object_id' => $user_id,
            'object_type' => 'customer',
            'search_text' => $search_text,
            'display_text' => $display_text,
            'additional_data' => maybe_serialize($additional_data),
            'date_created' => current_time('mysql')
        ));

        return true;
    }

    /**
     * Gets the date of a customer's last order
     */
    private static function get_customer_last_order_date($user_id) {
        $customer_orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        if (!empty($customer_orders)) {
            $order = reset($customer_orders);
            return $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '';
        }

        return '';
    }


}

// Ensure the search index table exists
add_action('init', array('EWNeater_Quick_Find_Core', 'create_search_index_table'));
