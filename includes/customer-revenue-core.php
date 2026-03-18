<?php
/*==========================================================================
 * CUSTOMER REVENUE CORE - DATABASE AND INDEX MANAGEMENT
 *
 * Handles core functionality for managing customer revenue and order data:
 * - Database table creation and schema management
 * - Index building and data calculation
 * - Customer data retrieval and updates
 * - Performance optimization and batch processing
 ==========================================================================*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Include the logger
require_once plugin_dir_path(__FILE__) . 'customer-revenue-logger.php';

class EWNeater_Customer_Revenue_Core {

    /*==========================================================================
     * CLASS CONSTANTS
     ==========================================================================*/
    const TABLE_NAME = 'ewneater_customer_revenue';

    /*==========================================================================
     * INITIALIZATION
     ==========================================================================*/
    public static function init() {
        add_action('init', [__CLASS__, 'create_customer_revenue_table']);
    }

    /*==========================================================================
     * DATABASE TABLE CREATION
     *
     * Creates the customer revenue table with proper indexes:
     * - Customer email and ID tracking
     * - Order counts and revenue totals
     * - Date tracking and update timestamps
     ==========================================================================*/
    public static function create_customer_revenue_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_email varchar(255) NOT NULL,
            customer_id bigint(20) DEFAULT 0,
            customer_name varchar(255) DEFAULT '',
            customer_type varchar(50) DEFAULT 'guest',
            total_orders int(11) DEFAULT 0,
            total_revenue decimal(10,2) DEFAULT 0.00,
            last_order_date datetime DEFAULT NULL,
            first_order_date datetime DEFAULT NULL,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY customer_email (customer_email),
            KEY customer_id (customer_id),
            KEY customer_type (customer_type),
            KEY total_orders (total_orders),
            KEY total_revenue (total_revenue),
            KEY last_order_date (last_order_date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /*==========================================================================
     * INDEX BUILDING
     *
     * Builds complete customer revenue index from order data:
     * - Processes customers from oldest to newest
     * - Batch processing for performance
     * - Real-time progress tracking with metadata updates
     ==========================================================================*/
    public static function build_customer_revenue_index() {
        $start_time = microtime(true);
        global $wpdb;

        EWNeater_Customer_Revenue_Logger::log_index_operation('index_build_start', [
            'table' => self::TABLE_NAME,
            'start_time' => current_time('mysql')
        ]);

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Clear existing data
        $wpdb->query("TRUNCATE TABLE $table_name");
        EWNeater_Customer_Revenue_Logger::info('Cleared existing customer revenue index');

        // Get all unique billing emails from orders, ordered by first order date (oldest first)
        $customer_emails = $wpdb->get_results("
            SELECT DISTINCT pm.meta_value as billing_email,
                   MIN(p.post_date) as first_order_date
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_billing_email'
            AND pm.meta_value != ''
            AND p.post_type = 'shop_order'
            AND p.post_status NOT IN ('wc-cancelled', 'wc-failed', 'trash', 'draft')
            GROUP BY pm.meta_value
            ORDER BY MIN(p.post_date) ASC
        ");

        $total_customers = count($customer_emails);
        EWNeater_Customer_Revenue_Logger::info("Found {$total_customers} unique customers to process");

        // Initialize progress tracking
        $meta = [
            'status' => 'building',
            'total_customers' => $total_customers,
            'processed_customers' => 0,
            'start_time' => current_time('mysql'),
            'last_updated' => current_time('mysql')
        ];
        update_option('ewneater_customer_revenue_meta', $meta);

        $processed = 0;
        $batch_size = 50;
        $batch_data = [];

        foreach ($customer_emails as $email_data) {
            $email = $email_data->billing_email;
            $customer_data = self::calculate_customer_data($email);

            if ($customer_data) {
                $batch_data[] = $customer_data;

                if (count($batch_data) >= $batch_size) {
                    self::insert_batch_data($batch_data);
                    $processed += count($batch_data);

                    // Update progress metadata
                    $meta['processed_customers'] = $processed;
                    $meta['last_updated'] = current_time('mysql');
                    update_option('ewneater_customer_revenue_meta', $meta);

                    EWNeater_Customer_Revenue_Logger::info("Processed batch: {$processed}/{$total_customers} customers (" . round(($processed / $total_customers) * 100, 1) . "%)");

                    $batch_data = [];
                }
            }
        }

        // Insert remaining batch data
        if (!empty($batch_data)) {
            self::insert_batch_data($batch_data);
            $processed += count($batch_data);
        }

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        // Update final metadata
        $meta['status'] = 'complete';
        $meta['processed_customers'] = $processed;
        $meta['duration'] = $duration;
        $meta['last_updated'] = current_time('mysql');
        update_option('ewneater_customer_revenue_meta', $meta);

        EWNeater_Customer_Revenue_Logger::log_index_operation('index_build_complete', [
            'customers_processed' => $processed,
            'duration_seconds' => $duration,
            'customers_per_second' => $duration > 0 ? round($processed / $duration, 2) : 0
        ]);

        EWNeater_Customer_Revenue_Logger::log_performance('index_build', $duration, $processed);

        return [
            'success' => true,
            'processed' => $processed,
            'total' => $total_customers,
            'duration' => $duration,
            'customers_per_second' => $duration > 0 ? round($processed / $duration, 2) : 0,
            'message' => "Customer revenue index rebuilt successfully. Processed {$processed} customers in {$duration} seconds."
        ];
    }

    /*==========================================================================
     * CUSTOMER DATA CALCULATION
     *
     * Calculates comprehensive customer metrics:
     * - Total orders and revenue from valid order statuses
     * - Customer type determination (guest/registered/wholesale/company)
     * - Order date ranges and customer information
     ==========================================================================*/
    public static function calculate_customer_data($email) {
        global $wpdb;

        if (empty($email) || !$wpdb) {
            return false;
        }

        // Additional safety check for database connection
        if (!$wpdb->ready) {
            error_log('EWNeater Customer Revenue: Database not ready');
            return false;
        }

        // Get order data for this customer with error handling
        try {
            $order_data = $wpdb->get_row($wpdb->prepare("
                SELECT
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN total_meta.meta_value IS NOT NULL THEN CAST(total_meta.meta_value AS DECIMAL(10,2)) ELSE 0 END) as total_revenue,
                    MIN(p.post_date) as first_order_date,
                    MAX(p.post_date) as last_order_date
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                LEFT JOIN {$wpdb->postmeta} total_meta ON total_meta.post_id = p.ID AND total_meta.meta_key = '_order_total'
                WHERE pm.meta_key = '_billing_email'
                AND pm.meta_value = %s
                AND p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending')
            ", $email));

            // Check for database errors
            if ($wpdb->last_error) {
                error_log('EWNeater Customer Revenue DB Error: ' . $wpdb->last_error);
                return false;
            }
        } catch (Exception $e) {
            error_log('EWNeater Customer Revenue Exception: ' . $e->getMessage());
            return false;
        }

        if (!$order_data || $order_data->total_orders == 0) {
            // Log more detailed information about why calculation failed
            if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                EWNeater_Customer_Revenue_Logger::warning('Customer data calculation failed - no valid orders found', [
                    'email' => $email,
                    'order_data_exists' => !empty($order_data),
                    'total_orders' => $order_data ? $order_data->total_orders : 'null',
                    'total_revenue' => $order_data ? $order_data->total_revenue : 'null',
                ]);
            }
            return false;
        }

        // Get customer name and details
        $customer_info = self::get_customer_info($email);

        return [
            'customer_email' => $email,
            'customer_id' => $customer_info['customer_id'],
            'customer_name' => $customer_info['customer_name'],
            'customer_type' => $customer_info['customer_type'],
            'total_orders' => intval($order_data->total_orders),
            'total_revenue' => floatval($order_data->total_revenue ?: 0),
            'first_order_date' => $order_data->first_order_date,
            'last_order_date' => $order_data->last_order_date,
        ];
    }

    /*==========================================================================
     * ENHANCED CUSTOMER INFORMATION RETRIEVAL
     *
     * Retrieves customer details with enhanced logic for multiple emails:
     * - First checks if billing email matches a registered user
     * - Then checks if any orders with this billing email have logged-in customers
     * - Prioritizes logged-in user information over billing email
     * - Handles wholesale customers who use different billing emails
     ==========================================================================*/
    private static function get_enhanced_customer_info($email) {
        global $wpdb;

        // Validate email parameter
        if (empty($email) || !is_string($email)) {
            error_log('EWNeater Customer Revenue: Invalid email parameter in get_enhanced_customer_info');
            return [
                'customer_id' => 0,
                'customer_name' => 'Unknown Customer',
                'customer_type' => 'guest'
            ];
        }

        // Initialize defaults
        $customer_id = 0;
        $customer_name = '';
        $customer_type = 'guest';

        // Check if WordPress functions are available
        if (!function_exists('get_user_by')) {
            error_log('EWNeater Customer Revenue: get_user_by function not available');
            return self::get_customer_info_fallback($email);
        }

        // Check database availability
        if (!$wpdb || !$wpdb->ready) {
            error_log('EWNeater Customer Revenue: Database not ready in get_enhanced_customer_info');
            return self::get_customer_info_fallback($email);
        }

        try {
            // First, check if the billing email directly matches a registered user
            $user = get_user_by('email', $email);

            if (!$user) {
                // If no direct match, check if orders with this billing email have logged-in customers
                $logged_in_customer_id = $wpdb->get_var($wpdb->prepare("
                    SELECT DISTINCT p.post_author
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order'
                        AND p.post_status IN ('wc-completed', 'wc-processing')
                        AND pm.meta_key = '_billing_email'
                        AND pm.meta_value = %s
                        AND p.post_author > 0
                    ORDER BY p.post_date DESC
                    LIMIT 1
                ", $email));

                if ($logged_in_customer_id) {
                    $user = get_user_by('id', $logged_in_customer_id);

                    // Log this consolidation for tracking
                    if ($user && class_exists('EWNeater_Customer_Revenue_Logger')) {
                        EWNeater_Customer_Revenue_Logger::info('Multi-email customer detected', [
                            'billing_email' => $email,
                            'user_account_email' => $user->user_email,
                            'user_id' => $user->ID,
                            'user_roles' => $user->roles,
                            'scenario' => 'Billing email differs from logged-in user account'
                        ]);
                    }
                }
            }

            if ($user) {
                $customer_id = $user->ID;
                $customer_name = $user->display_name ?: trim(($user->first_name . ' ' . $user->last_name));

                // Ensure we have a name
                if (empty($customer_name)) {
                    $customer_name = $user->user_login;
                }

                // Check for wholesale role
                if (is_array($user->roles) && in_array('wholesale_buyer', $user->roles)) {
                    $customer_type = 'wholesale';
                } else {
                    $customer_type = 'registered';
                }
            } else {
                // Get name from most recent order with error handling
                $name_data = $wpdb->get_row($wpdb->prepare("
                    SELECT
                        fname.meta_value as first_name,
                        lname.meta_value as last_name
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    LEFT JOIN {$wpdb->postmeta} fname ON fname.post_id = p.ID AND fname.meta_key = '_billing_first_name'
                    LEFT JOIN {$wpdb->postmeta} lname ON lname.post_id = p.ID AND lname.meta_key = '_billing_last_name'
                    WHERE pm.meta_key = '_billing_email'
                    AND pm.meta_value = %s
                    AND p.post_type = 'shop_order'
                    ORDER BY p.post_date DESC
                    LIMIT 1
                ", $email));

                // Check for database errors
                if ($wpdb->last_error) {
                    error_log('EWNeater Customer Revenue DB Error in get_customer_info: ' . $wpdb->last_error);
                }

                if ($name_data) {
                    $customer_name = trim(($name_data->first_name ?: '') . ' ' . ($name_data->last_name ?: ''));
                }
            }

            // Check for company email (specific to your business logic)
            if (function_exists('ewneater_is_company_email') && ewneater_is_company_email($email)) {
                $customer_type = 'company';
            }

        } catch (Exception $e) {
            error_log('EWNeater Customer Revenue Exception in get_customer_info: ' . $e->getMessage());
        } catch (Error $e) {
            error_log('EWNeater Customer Revenue Fatal Error in get_customer_info: ' . $e->getMessage());
        }

        // Ensure we always return a valid customer name
        if (empty($customer_name)) {
            if (!empty($email) && strpos($email, '@') !== false) {
                $customer_name = 'Customer (' . substr($email, 0, strpos($email, '@')) . ')';
            } else {
                $customer_name = 'Unknown Customer';
            }
        }

        return [
            'customer_id' => $customer_id,
            'customer_name' => $customer_name,
            'customer_type' => $customer_type
        ];
    }

    /*==========================================================================
     * FALLBACK CUSTOMER INFORMATION (Original Logic)
     *
     * Original customer info retrieval logic as fallback:
     * - WordPress user lookup and role checking
     * - Guest customer name extraction from orders
     * - Special company email detection
     ==========================================================================*/
    private static function get_customer_info_fallback($email) {
        global $wpdb;

        // Validate email parameter
        if (empty($email) || !is_string($email)) {
            error_log('EWNeater Customer Revenue: Invalid email parameter in get_customer_info_fallback');
            return [
                'customer_id' => 0,
                'customer_name' => 'Unknown Customer',
                'customer_type' => 'guest'
            ];
        }

        // Initialize defaults
        $customer_id = 0;
        $customer_name = '';
        $customer_type = 'guest';

        try {
            // Check if customer is registered
            $user = get_user_by('email', $email);
            if ($user) {
                $customer_id = $user->ID;
                $customer_name = $user->display_name ?: trim(($user->first_name . ' ' . $user->last_name));

                // Ensure we have a name
                if (empty($customer_name)) {
                    $customer_name = $user->user_login;
                }

                // Check for wholesale role
                if (is_array($user->roles) && in_array('wholesale_buyer', $user->roles)) {
                    $customer_type = 'wholesale';
                } else {
                    $customer_type = 'registered';
                }
            } else {
                // Get name from most recent order with error handling
                $name_data = $wpdb->get_row($wpdb->prepare("
                    SELECT
                        fname.meta_value as first_name,
                        lname.meta_value as last_name
                    FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    LEFT JOIN {$wpdb->postmeta} fname ON p.ID = fname.post_id AND fname.meta_key = '_billing_first_name'
                    LEFT JOIN {$wpdb->postmeta} lname ON p.ID = lname.post_id AND lname.meta_key = '_billing_last_name'
                    WHERE pm.meta_key = '_billing_email'
                        AND pm.meta_value = %s
                        AND p.post_type = 'shop_order'
                        AND p.post_status IN ('wc-completed', 'wc-processing')
                    ORDER BY p.post_date DESC
                    LIMIT 1
                ", $email));

                if ($name_data && ($name_data->first_name || $name_data->last_name)) {
                    $customer_name = trim(($name_data->first_name ?: '') . ' ' . ($name_data->last_name ?: ''));
                }

                // Check if any orders with this billing email were placed by logged-in wholesale customers
                $wholesale_user_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT p.post_author)
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    JOIN {$wpdb->users} u ON p.post_author = u.ID
                    JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                    WHERE p.post_type = 'shop_order'
                        AND p.post_status IN ('wc-completed', 'wc-processing')
                        AND pm.meta_key = '_billing_email'
                        AND pm.meta_value = %s
                        AND p.post_author > 0
                        AND um.meta_key = 'wp_capabilities'
                        AND um.meta_value LIKE %s
                ", $email, '%wholesale_buyer%'));

                // If wholesale users have used this billing email, classify as wholesale
                if ($wholesale_user_count > 0) {
                    $customer_type = 'wholesale';

                    if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                        EWNeater_Customer_Revenue_Logger::info('Guest orders reclassified as wholesale', [
                            'billing_email' => $email,
                            'wholesale_user_count' => $wholesale_user_count,
                            'reason' => 'Billing email used by wholesale customers'
                        ]);
                    }
                } else {
                    // Keep as guest unless it's a special company email
                    $customer_type = 'guest';
                }
            }

            // Special handling for company emails
            if (function_exists('ewneater_is_company_email') && ewneater_is_company_email($email)) {
                $customer_type = 'company';
            }

        } catch (Exception $e) {
            error_log('EWNeater Customer Revenue: Error in get_customer_info_fallback - ' . $e->getMessage());
        }

        // Final fallback if no name found
        if (empty($customer_name)) {
            $customer_name = 'Unknown Customer';
        }

        return [
            'customer_id' => $customer_id,
            'customer_name' => $customer_name,
            'customer_type' => $customer_type
        ];
    }

    /*==========================================================================
     * CUSTOMER CONSOLIDATION LOGIC
     *
     * Handles cases where same customer uses multiple emails:
     * - Detects when billing email differs from logged-in user email
     * - Logs consolidation attempts for tracking
     * - Provides consolidated customer data
     ==========================================================================*/
    public static function log_customer_consolidation($billing_email, $user_email, $customer_data) {
        if (class_exists('EWNeater_Customer_Revenue_Logger')) {
            EWNeater_Customer_Revenue_Logger::info('Customer email consolidation detected', [
                'billing_email' => $billing_email,
                'user_account_email' => $user_email,
                'customer_id' => $customer_data['customer_id'],
                'customer_name' => $customer_data['customer_name'],
                'customer_type' => $customer_data['customer_type'],
                'consolidation_reason' => 'Different billing email than user account'
            ]);
        }
    }

    /*==========================================================================
     * LEGACY CUSTOMER INFORMATION (For Backwards Compatibility)
     ==========================================================================*/
    private static function get_customer_info($email) {
        // For backwards compatibility, use the enhanced version
        return self::get_enhanced_customer_info($email);
    }

    /*==========================================================================
     * BATCH DATA INSERTION
     *
     * Efficiently inserts customer data in batches:
     * - Prepares bulk SQL statements
     * - Handles multiple customer records at once
     ==========================================================================*/
    public static function insert_batch_data($batch_data) {
        global $wpdb;

        if (empty($batch_data)) {
            return;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $values = [];
        $placeholders = [];

        foreach ($batch_data as $data) {
            $placeholders[] = "(%s, %d, %s, %s, %d, %f, %s, %s)";
            $values = array_merge($values, [
                $data['customer_email'],
                $data['customer_id'],
                $data['customer_name'],
                $data['customer_type'],
                $data['total_orders'],
                $data['total_revenue'],
                $data['first_order_date'],
                $data['last_order_date']
            ]);
        }

        $sql = "INSERT INTO $table_name
                (customer_email, customer_id, customer_name, customer_type, total_orders, total_revenue, first_order_date, last_order_date)
                VALUES " . implode(', ', $placeholders);

        $wpdb->query($wpdb->prepare($sql, $values));
    }

    /*==========================================================================
     * ORDER-BASED CUSTOMER UPDATES
     *
     * Updates customer data when orders change:
     * - Recalculates metrics for affected customer
     * - Handles new customers and existing updates
     * - Provides logging for tracking changes
     ==========================================================================*/
    public static function update_customer_from_order($order_id) {
        // Check if WordPress and WooCommerce functions are available
        if (!function_exists('wc_get_order') || !did_action('woocommerce_init')) {
            error_log('EWNeater Customer Revenue: WooCommerce not fully initialized');
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                EWNeater_Customer_Revenue_Logger::warning('Order not found', ['order_id' => $order_id]);
            }
            return false;
        }

        $email = $order->get_billing_email();
        if (!$email) {
            if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                EWNeater_Customer_Revenue_Logger::warning('Order has no billing email', ['order_id' => $order_id]);
            }
            return false;
        }

        $customer_data = self::calculate_customer_data($email);
        if (!$customer_data) {
            // Get order status for better error context
            $order_status = $order->get_status();
            $excluded_statuses = ['refunded', 'cancelled', 'failed', 'trash'];

            if (in_array($order_status, $excluded_statuses)) {
                // This is expected - don't log as warning
                if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                    EWNeater_Customer_Revenue_Logger::debug('Skipping revenue calculation for excluded order status', [
                        'order_id' => $order_id,
                        'email' => $email,
                        'status' => $order_status,
                        'note' => 'Orders with this status are excluded from revenue calculations'
                    ]);
                }
            } else {
                // This is unexpected - log as warning
                if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                    EWNeater_Customer_Revenue_Logger::warning('Could not calculate customer data for valid order', [
                        'order_id' => $order_id,
                        'email' => $email,
                        'status' => $order_status,
                        'note' => 'This may indicate a data issue or customer with only excluded orders'
                    ]);
                }
            }
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if customer exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE customer_email = %s",
            $email
        ));

        if ($exists) {
            // Get existing data to check for changes
            $existing_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE customer_email = %s",
                $email
            ));

            // Check if data actually changed
            $has_changes = !$existing_data ||
                $existing_data->customer_id != $customer_data['customer_id'] ||
                $existing_data->customer_name !== $customer_data['customer_name'] ||
                $existing_data->customer_type !== $customer_data['customer_type'] ||
                $existing_data->total_orders != $customer_data['total_orders'] ||
                abs($existing_data->total_revenue - $customer_data['total_revenue']) > 0.01 ||
                $existing_data->first_order_date !== $customer_data['first_order_date'] ||
                $existing_data->last_order_date !== $customer_data['last_order_date'];

            if (!$has_changes) {
                // No changes needed, skip update and logging
                return true;
            }

            // Update existing record
            $result = $wpdb->update(
                $table_name,
                [
                    'customer_id' => $customer_data['customer_id'],
                    'customer_name' => $customer_data['customer_name'],
                    'customer_type' => $customer_data['customer_type'],
                    'total_orders' => $customer_data['total_orders'],
                    'total_revenue' => $customer_data['total_revenue'],
                    'first_order_date' => $customer_data['first_order_date'],
                    'last_order_date' => $customer_data['last_order_date'],
                ],
                ['customer_email' => $email],
                ['%d', '%s', '%s', '%d', '%f', '%s', '%s'],
                ['%s']
            );

            // Only log individual updates when not bulk building and data actually changed
            if (function_exists('get_option') && !get_option('ewneater_customer_revenue_meta')) {
                if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                    EWNeater_Customer_Revenue_Logger::log_order_processing($order_id, $email, 'updated', [
                        'orders' => $customer_data['total_orders'],
                        'revenue' => $customer_data['total_revenue'],
                        'customer_type' => $customer_data['customer_type']
                    ]);
                }
            }
        } else {
            // Insert new record
            $result = $wpdb->insert(
                $table_name,
                $customer_data,
                ['%s', '%d', '%s', '%s', '%d', '%f', '%s', '%s']
            );

            // Only log individual updates when not bulk building
            if (function_exists('get_option') && !get_option('ewneater_customer_revenue_meta')) {
                if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                    EWNeater_Customer_Revenue_Logger::log_order_processing($order_id, $email, 'created', [
                        'orders' => $customer_data['total_orders'],
                        'revenue' => $customer_data['total_revenue'],
                        'customer_type' => $customer_data['customer_type']
                    ]);
                }
            }
        }

        return $result !== false;
    }

    /*==========================================================================
     * CUSTOMER DATA RETRIEVAL
     *
     * Retrieves customer data from index with logging:
     * - Direct SQL table lookup
     * - Data source tracking for performance monitoring
     ==========================================================================*/
    public static function get_customer_data($email, $order_id = null, $customer_name = null) {
        if (empty($email)) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $customer_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE customer_email = %s",
            $email
        ));

        if ($customer_data) {
            // EWNeater_Customer_Revenue_Logger::log_data_source($email, 'sql_table',
            //     $customer_data->total_orders, $customer_data->total_revenue);
        } else {
            EWNeater_Customer_Revenue_Logger::log_data_source($email, 'not_found', null, null, $order_id, $customer_name);
        }

        return $customer_data;
    }

    /*==========================================================================
     * FILTERED CUSTOMER QUERIES
     *
     * Retrieves customers with advanced filtering:
     * - Date range filtering
     * - Customer type filtering
     * - Search by name or email
     * - Sorting and pagination support
     ==========================================================================*/
    public static function get_customers($args = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $defaults = [
            'orderby' => 'total_revenue',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0,
            'date_from' => '',
            'date_to' => '',
            'customer_type' => '',
            'search' => ''
        ];

        $args = wp_parse_args($args, $defaults);

        $where_conditions = ['1=1'];
        $where_values = [];

        // Date filtering
        if (!empty($args['date_from'])) {
            $where_conditions[] = 'last_order_date >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_conditions[] = 'last_order_date <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        // Customer type filtering
        if (!empty($args['customer_type'])) {
            $where_conditions[] = 'customer_type = %s';
            $where_values[] = $args['customer_type'];
        }

        // Search filtering
        if (!empty($args['search'])) {
            $where_conditions[] = '(customer_name LIKE %s OR customer_email LIKE %s)';
            $search_term = '%' . $args['search'] . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Validate orderby
        $allowed_orderby = ['total_orders', 'total_revenue', 'customer_name', 'last_order_date', 'first_order_date'];
        if (!in_array($args['orderby'], $allowed_orderby)) {
            $args['orderby'] = 'total_revenue';
        }

        // Validate order
        $args['order'] = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM $table_name WHERE $where_clause ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d";
        $where_values[] = intval($args['limit']);
        $where_values[] = intval($args['offset']);

        if (!empty($where_values)) {
            return $wpdb->get_results($wpdb->prepare($sql, $where_values));
        } else {
            return $wpdb->get_results($sql);
        }
    }

    /*==========================================================================
     * CUSTOMER COUNT FOR PAGINATION
     *
     * Returns total customer count matching filters:
     * - Same filtering logic as get_customers
     * - Used for pagination calculations
     ==========================================================================*/
    public static function get_customers_count($args = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $where_conditions = ['1=1'];
        $where_values = [];

        // Date filtering
        if (!empty($args['date_from'])) {
            $where_conditions[] = 'last_order_date >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_conditions[] = 'last_order_date <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        // Customer type filtering
        if (!empty($args['customer_type'])) {
            $where_conditions[] = 'customer_type = %s';
            $where_values[] = $args['customer_type'];
        }

        // Search filtering
        if (!empty($args['search'])) {
            $where_conditions[] = '(customer_name LIKE %s OR customer_email LIKE %s)';
            $search_term = '%' . $args['search'] . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where_conditions);
        $sql = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";

        if (!empty($where_values)) {
            return $wpdb->get_var($wpdb->prepare($sql, $where_values));
        } else {
            return $wpdb->get_var($sql);
        }
    }

    /*==========================================================================
     * INDEX CLEARING
     *
     * Clears all customer revenue data:
     * - Truncates the entire table
     * - Provides logging and statistics
     ==========================================================================*/
    public static function clear_customer_revenue_index() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $cleared_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        $deleted = $wpdb->query("TRUNCATE TABLE $table_name");

        // Clear progress metadata
        delete_option('ewneater_customer_revenue_meta');

        EWNeater_Customer_Revenue_Logger::info('Customer revenue index cleared', [
            'records_cleared' => $cleared_count
        ]);

        return [
            'success' => true,
            'cleared_count' => $cleared_count,
            'message' => "Customer revenue index cleared successfully. Removed {$cleared_count} customer records."
        ];
    }

    /*==========================================================================
     * CUSTOMER TYPE STATISTICS
     *
     * Retrieves statistics for specific customer types:
     * - Filtered by customer type array
     * - Returns counts, revenue totals, and averages
     ==========================================================================*/
    public static function get_customer_type_stats($customer_types = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        if (empty($customer_types)) {
            return (object) [
                'total_customers' => 0,
                'total_orders' => 0,
                'total_revenue' => 0,
                'avg_revenue' => 0
            ];
        }

        // Build WHERE clause for customer types
        $placeholders = implode(',', array_fill(0, count($customer_types), '%s'));
        $where_clause = "customer_type IN ($placeholders)";

        $sql = "
            SELECT
                COUNT(*) as total_customers,
                SUM(total_orders) as total_orders,
                SUM(total_revenue) as total_revenue,
                AVG(total_revenue) as avg_revenue
            FROM $table_name
            WHERE $where_clause
        ";

        $result = $wpdb->get_row($wpdb->prepare($sql, $customer_types));

        return $result ?: (object) [
            'total_customers' => 0,
            'total_orders' => 0,
            'total_revenue' => 0,
            'avg_revenue' => 0
        ];
    }

    /*==========================================================================
     * STATISTICS AND METADATA
     *
     * Retrieves comprehensive index statistics:
     * - Total customer and order counts
     * - Revenue totals and averages
     * - Last update timestamp
     ==========================================================================*/
    public static function get_index_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total_customers,
                SUM(total_orders) as total_orders,
                SUM(total_revenue) as total_revenue,
                AVG(total_revenue) as avg_revenue,
                MAX(last_updated) as last_updated
            FROM $table_name
        ");

        return $stats;
    }

    /*==========================================================================
     * PROGRESS TRACKING
     *
     * Gets current index building progress:
     * - Real-time progress percentage
     * - Processing status and metadata
     * - Time estimates and completion status
     ==========================================================================*/
    public static function get_build_progress() {
        $meta = get_option('ewneater_customer_revenue_meta', []);

        if (empty($meta)) {
            return [
                'status' => 'not_started',
                'progress_percent' => 0,
                'processed' => 0,
                'total' => 0,
                'estimated_remaining' => 0
            ];
        }

        $progress_percent = 0;
        if ($meta['total_customers'] > 0) {
            $progress_percent = round(($meta['processed_customers'] / $meta['total_customers']) * 100, 1);
        }

        // Calculate estimated time remaining
        $estimated_remaining = 0;
        if ($meta['status'] === 'building' && $meta['processed_customers'] > 0) {
            $elapsed = time() - strtotime($meta['start_time']);
            $rate = $meta['processed_customers'] / $elapsed;
            $remaining_customers = $meta['total_customers'] - $meta['processed_customers'];
            $estimated_remaining = $rate > 0 ? round($remaining_customers / $rate) : 0;
        }

        return [
            'status' => $meta['status'] ?? 'unknown',
            'progress_percent' => $progress_percent,
            'processed' => $meta['processed_customers'] ?? 0,
            'total' => $meta['total_customers'] ?? 0,
            'estimated_remaining' => $estimated_remaining,
            'start_time' => $meta['start_time'] ?? null,
            'last_updated' => $meta['last_updated'] ?? null
        ];
    }

    /*==========================================================================
     * FORCE CONTINUE INDEX BUILD
     *
     * Continues index building from where it left off:
     * - Checks current progress and continues from that point
     * - Handles stuck or timed-out index builds
     * - Maintains existing progress tracking
     ==========================================================================*/
    public static function force_continue_index_build() {
        global $wpdb;
        $start_time = microtime(true);

        EWNeater_Customer_Revenue_Logger::info('Force continue index build initiated');

        // Get current progress
        $meta = get_option('ewneater_customer_revenue_meta', []);

        if (empty($meta) || !isset($meta['status'])) {
            return [
                'success' => false,
                'message' => 'No index build in progress to continue'
            ];
        }

        if ($meta['status'] !== 'building') {
            return [
                'success' => false,
                'message' => 'Index is not currently building (status: ' . $meta['status'] . ')'
            ];
        }

        $processed = intval($meta['processed_customers'] ?? 0);
        $total = intval($meta['total_customers'] ?? 0);

        if ($processed >= $total) {
            // Mark as complete and return
            $meta['status'] = 'complete';
            $meta['last_updated'] = current_time('mysql');
            update_option('ewneater_customer_revenue_meta', $meta);

            return [
                'success' => true,
                'message' => 'Index was already complete'
            ];
        }

        EWNeater_Customer_Revenue_Logger::info("Continuing index build from customer $processed of $total");

        try {
            $table_name = $wpdb->prefix . self::TABLE_NAME;
            $batch_size = 50; // Smaller batch for continuation

            // Get all unique billing emails from orders, ordered by first order date (oldest first)
            $customer_emails = $wpdb->get_results("
                SELECT DISTINCT pm.meta_value as billing_email,
                       MIN(p.post_date) as first_order_date
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_billing_email'
                    AND pm.meta_value != ''
                    AND p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-completed', 'wc-processing')
                GROUP BY pm.meta_value
                ORDER BY first_order_date ASC
                LIMIT 999999 OFFSET $processed
            ");

            if (empty($customer_emails)) {
                // No more customers to process
                $meta['status'] = 'complete';
                $meta['processed_customers'] = $total;
                $meta['last_updated'] = current_time('mysql');
                update_option('ewneater_customer_revenue_meta', $meta);

                return [
                    'success' => true,
                    'message' => 'Index continuation completed successfully'
                ];
            }

            $batch_data = [];
            $continued_processed = 0;

            foreach ($customer_emails as $customer_email_data) {
                $email = $customer_email_data->billing_email;
                $customer_data = self::calculate_customer_data($email);

                if ($customer_data) {
                    $batch_data[] = $customer_data;
                    $continued_processed++;

                    // Process in batches
                    if (count($batch_data) >= $batch_size) {
                        self::insert_batch_data($batch_data);
                        $processed += count($batch_data);

                        // Update progress metadata
                        $meta['processed_customers'] = $processed;
                        $meta['last_updated'] = current_time('mysql');
                        update_option('ewneater_customer_revenue_meta', $meta);

                        EWNeater_Customer_Revenue_Logger::info("Force continue batch: {$processed}/{$total} customers (" . round(($processed / $total) * 100, 1) . "%)");

                        $batch_data = [];

                        // Time limit check (30 seconds)
                        if ((microtime(true) - $start_time) > 30) {
                            break;
                        }
                    }
                }
            }

            // Process remaining batch
            if (!empty($batch_data)) {
                self::insert_batch_data($batch_data);
                $processed += count($batch_data);

                $meta['processed_customers'] = $processed;
                $meta['last_updated'] = current_time('mysql');
            }

            // Check if we're done
            if ($processed >= $total) {
                $meta['status'] = 'complete';
                EWNeater_Customer_Revenue_Logger::info('Force continue completed - index build finished');
            }

            update_option('ewneater_customer_revenue_meta', $meta);

            $message = "Continued processing $continued_processed customers. Progress: $processed/$total (" . round(($processed / $total) * 100, 1) . "%)";

            return [
                'success' => true,
                'message' => $message
            ];

        } catch (Exception $e) {
            EWNeater_Customer_Revenue_Logger::error('Force continue index build failed', [
                'error' => $e->getMessage(),
                'processed' => $processed,
                'total' => $total
            ]);

            return [
                'success' => false,
                'message' => 'Force continue failed: ' . $e->getMessage()
            ];
        }
    }

    /*==========================================================================
     * MARK INDEX COMPLETE
     *
     * Marks the index as complete when it's stuck but actually finished:
     * - Forces completion status when processed >= total
     * - Updates metadata to complete state
     * - Logs completion event
     ==========================================================================*/
    public static function mark_index_complete() {
        $meta = get_option('ewneater_customer_revenue_meta', []);

        if (!empty($meta)) {
            $meta['status'] = 'complete';
            $meta['last_updated'] = current_time('mysql');
            update_option('ewneater_customer_revenue_meta', $meta);

            EWNeater_Customer_Revenue_Logger::info('Index manually marked as complete', [
                'processed' => $meta['processed_customers'] ?? 0,
                'total' => $meta['total_customers'] ?? 0,
                'reason' => 'Stuck index detected as complete'
            ]);
        }
    }

}

/*==========================================================================
 * CLASS INITIALIZATION
 ==========================================================================*/
EWNeater_Customer_Revenue_Core::init();
