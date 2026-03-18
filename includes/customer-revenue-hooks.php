<?php
/*==========================================================================
 * CUSTOMER REVENUE HOOKS
 *
 * Handles automatic updates of customer revenue data:
 * - Order creation, updates, and deletion tracking
 * - User registration and role changes
 * - Bulk operations and status changes
 * - Daily maintenance and cleanup
 ==========================================================================*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class EWNeater_Customer_Revenue_Hooks {

    // Track recently processed orders to prevent duplicates
    private static $processed_orders = [];
    private static $processing_lock = [];
    const DEDUP_WINDOW = 60; // 60 seconds to prevent duplicate processing

    /*==========================================================================
     * HOOK INITIALIZATION
     ==========================================================================*/
    public static function init() {
        // Load circuit breaker protection
        if (file_exists(plugin_dir_path(__FILE__) . 'customer-revenue-circuit-breaker.php')) {
            require_once plugin_dir_path(__FILE__) . 'customer-revenue-circuit-breaker.php';
        }
        // Order lifecycle hooks - simplified to reduce duplicates
        add_action('woocommerce_order_status_changed', [__CLASS__, 'handle_order_status_change'], 999, 4);
        add_action('woocommerce_new_order', [__CLASS__, 'handle_new_order'], 999, 2);
        // Remove redundant hooks that cause duplicate processing
        // add_action('woocommerce_checkout_order_processed', [__CLASS__, 'handle_order_processed'], 999, 3);
        // add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'handle_order_update'], 999, 2);
        add_action('woocommerce_update_order', [__CLASS__, 'handle_order_update'], 999, 1);

        // Order deletion and restoration hooks - delayed execution
        add_action('wp_trash_post', [__CLASS__, 'handle_order_trash'], 999);
        add_action('before_delete_post', [__CLASS__, 'handle_order_delete'], 999);
        add_action('untrash_post', [__CLASS__, 'handle_order_untrash'], 999);

        // User management hooks - delayed execution
        add_action('user_register', [__CLASS__, 'handle_user_register'], 999, 1);
        add_action('profile_update', [__CLASS__, 'handle_user_update'], 999, 2);

        // Bulk operation hooks - delayed execution
        add_action('woocommerce_order_bulk_action', [__CLASS__, 'handle_bulk_action'], 999, 3);

        // Daily maintenance scheduling
        add_action('ewneater_daily_cleanup', [__CLASS__, 'daily_cleanup']);
        if (!wp_next_scheduled('ewneater_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ewneater_daily_cleanup');
        }
    }

    /*==========================================================================
     * ORDER STATUS CHANGE HANDLERS
     ==========================================================================*/
    public static function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // Check deduplication first
        if (!self::should_process_order($order_id, 'status_change')) {
            return;
        }

        // Define statuses that affect revenue calculation
        $revenue_statuses = ['processing', 'completed', 'on-hold', 'pending'];
        $excluded_statuses = ['refunded', 'cancelled', 'failed', 'trash'];

        $old_counts = in_array($old_status, $revenue_statuses);
        $new_counts = in_array($new_status, $revenue_statuses);

        // Skip if moving to an excluded status (refunded orders shouldn't be processed)
        if (in_array($new_status, $excluded_statuses)) {
            if (class_exists('EWNeater_Customer_Revenue_Logger')) {
                EWNeater_Customer_Revenue_Logger::debug('Order status changed to excluded status', [
                    'order_id' => $order_id,
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'note' => 'Revenue tracking skipped for excluded status'
                ]);
            }
            return;
        }

        // Update customer data only if revenue impact changes
        if ($old_counts !== $new_counts) {
            self::update_customer_from_order($order_id);
            self::mark_order_processed($order_id, 'status_change');
        }
    }

    /*==========================================================================
     * NEW ORDER HANDLERS
     ==========================================================================*/
    public static function handle_new_order($order_id, $order = null) {
        // Check deduplication first
        if (!self::should_process_order($order_id, 'new_order')) {
            return;
        }

        // Check if WordPress and WooCommerce functions are available
        if (!function_exists('wc_get_order') || !did_action('woocommerce_init')) {
            // Schedule for later execution if WooCommerce isn't ready
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 30, 'ewneater_delayed_order_update', [$order_id]);
            }
            return;
        }

        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if ($order && $order->get_billing_email()) {
            // Skip refunded, cancelled, and failed orders
            $status = $order->get_status();
            if (!in_array($status, ['refunded', 'cancelled', 'failed', 'trash'])) {
                // Use delayed execution to avoid blocking checkout
                self::schedule_delayed_update($order_id);
                self::mark_order_processed($order_id, 'new_order');
            }
        }
    }

    /*==========================================================================
     * ORDER UPDATE HANDLERS
     ==========================================================================*/
    public static function handle_order_processed($order_id, $posted_data, $order) {
        // Check if WordPress and WooCommerce functions are available
        if (!function_exists('wc_get_order') || !did_action('woocommerce_init')) {
            return;
        }

        if ($order && $order->get_billing_email()) {
            // Use delayed execution to avoid blocking checkout
            self::schedule_delayed_update($order_id);
        }
    }

    public static function handle_order_update($order_id, $post = null) {
        // Check deduplication first
        if (!self::should_process_order($order_id, 'update')) {
            return;
        }

        // Prevent infinite loops during updates
        if (defined('EWNEATER_UPDATING_CUSTOMER_REVENUE')) {
            return;
        }

        // Check if WordPress and WooCommerce functions are available
        if (!function_exists('wc_get_order') || !did_action('woocommerce_init')) {
            return;
        }

        $order = wc_get_order($order_id);
        if ($order && $order->get_billing_email()) {
            // Skip refunded, cancelled, and failed orders
            $status = $order->get_status();
            if (!in_array($status, ['refunded', 'cancelled', 'failed', 'trash'])) {
                self::update_customer_from_order($order_id);
                self::mark_order_processed($order_id, 'update');
            }
        }
    }

    /*==========================================================================
     * ORDER DELETION HANDLERS
     ==========================================================================*/
    public static function handle_order_trash($post_id) {
        // Check if WordPress functions are available
        if (!function_exists('get_post_type')) {
            return;
        }

        if (get_post_type($post_id) === 'shop_order') {
            self::update_customer_from_order($post_id);
        }
    }

    public static function handle_order_delete($post_id) {
        // Order deleted. Customer revenue is updated via order status/update hooks.
    }

    /*==========================================================================
     * USER MANAGEMENT HANDLERS
     ==========================================================================*/
    public static function handle_order_untrash($post_id) {
        // Check if WordPress functions are available
        if (!function_exists('get_post_type')) {
            return;
        }

        if (get_post_type($post_id) === 'shop_order') {
            self::update_customer_from_order($post_id);
        }
    }

    public static function handle_user_register($user_id) {
        // Check if WordPress functions are available
        if (!function_exists('get_userdata')) {
            return;
        }

        $user = get_userdata($user_id);
        if ($user && $user->user_email) {
            // Update customer type for existing orders
            self::update_customer_by_email($user->user_email);
        }
    }

    public static function handle_user_update($user_id, $old_user_data) {
        // Check if WordPress functions are available
        if (!function_exists('get_userdata')) {
            return;
        }

        $user = get_userdata($user_id);
        if ($user && $user->user_email) {
            // Update customer type for role changes
            self::update_customer_by_email($user->user_email);

            // Handle email address changes
            if ($old_user_data->user_email !== $user->user_email) {
                self::update_customer_by_email($old_user_data->user_email);
            }
        }
    }

    /*==========================================================================
     * BULK OPERATION HANDLERS
     ==========================================================================*/
    public static function handle_bulk_action($redirect_to, $action, $post_ids) {
        // Check if WordPress functions are available
        if (!function_exists('get_post_type')) {
            return;
        }

        if (in_array($action, ['mark_processing', 'mark_on-hold', 'mark_completed', 'mark_cancelled', 'mark_refunded'])) {
            foreach ($post_ids as $post_id) {
                if (get_post_type($post_id) === 'shop_order') {
                    self::update_customer_from_order($post_id);
                }
            }
        }
    }

    /*==========================================================================
     * MAINTENANCE AND CLEANUP
     ==========================================================================*/
    public static function daily_cleanup() {
        global $wpdb;

        // Update customers with recent order activity
        $recent_order_emails = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_billing_email'
             AND pm.meta_value != ''
             AND p.post_type = 'shop_order'
             AND p.post_modified >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );

        foreach ($recent_order_emails as $email) {
            self::update_customer_by_email($email);
        }
    }

    /*==========================================================================
     * PRIVATE UPDATE HELPERS
     ==========================================================================*/
    public static function update_customer_from_order($order_id) {
        // Check circuit breaker first
        if (function_exists('ewneater_revenue_can_execute') && !ewneater_revenue_can_execute()) {
            return false;
        }

        // Prevent infinite update loops
        if (!defined('EWNEATER_UPDATING_CUSTOMER_REVENUE')) {
            define('EWNEATER_UPDATING_CUSTOMER_REVENUE', true);
        }

        // Check if the core class exists and is available
        if (!class_exists('EWNeater_Customer_Revenue_Core')) {
            error_log('EWNeater Customer Revenue: Core class not found during order update');
            if (function_exists('ewneater_revenue_circuit_breaker')) {
                ewneater_revenue_circuit_breaker()->record_failure('Core class not found');
            }
            return false;
        }

        // Use circuit breaker for safe execution
        if (function_exists('ewneater_revenue_execute_safely')) {
            return ewneater_revenue_execute_safely(function($order_id) {
                return EWNeater_Customer_Revenue_Core::update_customer_from_order($order_id);
            }, $order_id);
        } else {
            // Fallback without circuit breaker
            try {
                return EWNeater_Customer_Revenue_Core::update_customer_from_order($order_id);
            } catch (Exception $e) {
                error_log('EWNeater Customer Revenue Update Error: ' . $e->getMessage());
                return false;
            } catch (Error $e) {
                error_log('EWNeater Customer Revenue Fatal Error: ' . $e->getMessage());
                return false;
            }
        }
    }

    private static function update_customer_by_email($email) {
        if (empty($email)) {
            return;
        }

        // Check circuit breaker first
        if (function_exists('ewneater_revenue_can_execute') && !ewneater_revenue_can_execute()) {
            return false;
        }

        // Check if the core class exists and is available
        if (!class_exists('EWNeater_Customer_Revenue_Core')) {
            error_log('EWNeater Customer Revenue: Core class not found during email update');
            if (function_exists('ewneater_revenue_circuit_breaker')) {
                ewneater_revenue_circuit_breaker()->record_failure('Core class not found');
            }
            return;
        }

        // Prevent infinite update loops
        if (!defined('EWNEATER_UPDATING_CUSTOMER_REVENUE')) {
            define('EWNEATER_UPDATING_CUSTOMER_REVENUE', true);
        }

        // Use circuit breaker for safe execution
        if (function_exists('ewneater_revenue_execute_safely')) {
            ewneater_revenue_execute_safely(function() use ($email) {
                global $wpdb;

                // Check if database is available
                if (!$wpdb || !$wpdb->ready) {
                    throw new Exception('Database not ready');
                }

                // Find any order for this customer to trigger update
                $order_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE pm.meta_key = '_billing_email'
                     AND pm.meta_value = %s
                     AND p.post_type = 'shop_order'
                     LIMIT 1",
                    $email
                ));

                if ($order_id) {
                    return EWNeater_Customer_Revenue_Core::update_customer_from_order($order_id);
                }
                return true;
            });
        } else {
            // Fallback without circuit breaker
            try {
                global $wpdb;

                // Check if database is available
                if (!$wpdb || !$wpdb->ready) {
                    error_log('EWNeater Customer Revenue: Database not ready');
                    return;
                }

                // Find any order for this customer to trigger update
                $order_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE pm.meta_key = '_billing_email'
                     AND pm.meta_value = %s
                     AND p.post_type = 'shop_order'
                     LIMIT 1",
                    $email
                ));

                if ($order_id) {
                    EWNeater_Customer_Revenue_Core::update_customer_from_order($order_id);
                }
            } catch (Exception $e) {
                error_log('EWNeater Customer Revenue Update Error: ' . $e->getMessage());
            } catch (Error $e) {
                error_log('EWNeater Customer Revenue Fatal Error: ' . $e->getMessage());
            }
        }
    }

    /*==========================================================================
     * DEDUPLICATION UTILITIES
     ==========================================================================*/
    private static function should_process_order($order_id, $context = 'general') {
        $key = $order_id . '_' . $context;
        $current_time = time();

        // Clean old entries
        self::cleanup_processed_orders();

        // Check if we've processed this recently
        if (isset(self::$processed_orders[$key])) {
            $last_processed = self::$processed_orders[$key];
            if (($current_time - $last_processed) < self::DEDUP_WINDOW) {
                return false; // Skip - processed recently
            }
        }

        // Check if currently being processed (lock)
        if (isset(self::$processing_lock[$key])) {
            return false;
        }

        // Set processing lock
        self::$processing_lock[$key] = $current_time;

        return true;
    }

    private static function mark_order_processed($order_id, $context = 'general') {
        $key = $order_id . '_' . $context;
        $current_time = time();

        self::$processed_orders[$key] = $current_time;

        // Remove processing lock
        unset(self::$processing_lock[$key]);
    }

    private static function cleanup_processed_orders() {
        $current_time = time();
        $cutoff = $current_time - (self::DEDUP_WINDOW * 2); // Keep double the window for safety

        foreach (self::$processed_orders as $key => $timestamp) {
            if ($timestamp < $cutoff) {
                unset(self::$processed_orders[$key]);
            }
        }

        foreach (self::$processing_lock as $key => $timestamp) {
            if ($timestamp < $cutoff) {
                unset(self::$processing_lock[$key]);
            }
        }
    }

    /*==========================================================================
     * DELAYED UPDATE UTILITIES
     ==========================================================================*/
    private static function schedule_delayed_update($order_id) {
        // Schedule update for 30 seconds later to avoid blocking checkout
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 30, 'ewneater_delayed_order_update', [$order_id]);
        } else {
            // Fallback to immediate update if scheduling isn't available
            self::update_customer_from_order($order_id);
        }
    }

    /*==========================================================================
     * BULK UPDATE UTILITIES
     ==========================================================================*/
    public static function force_update_all_customers() {
        global $wpdb;

        $emails = $wpdb->get_col(
            "SELECT DISTINCT customer_email FROM {$wpdb->prefix}ewneater_customer_revenue"
        );

        foreach ($emails as $email) {
            self::update_customer_by_email($email);
        }

        return count($emails);
    }

    /*==========================================================================
     * HEALTH CHECK AND DIAGNOSTICS
     ==========================================================================*/
    public static function update_customers_for_date_range($start_date, $end_date) {
        global $wpdb;

        $emails = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_billing_email'
             AND pm.meta_value != ''
             AND p.post_type = 'shop_order'
             AND p.post_date BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));

        foreach ($emails as $email) {
            self::update_customer_by_email($email);
        }

        return count($emails);
    }

    public static function health_check() {
        global $wpdb;

        // Count indexed customers
        $index_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ewneater_customer_revenue"
        );

        // Count actual unique customers in orders
        $actual_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_billing_email'
             AND pm.meta_value != ''
             AND p.post_type = 'shop_order'
             AND p.post_status NOT IN ('wc-cancelled', 'wc-failed', 'trash', 'draft')"
        );

        $health_ratio = $actual_count > 0 ? ($index_count / $actual_count) : 0;

        return [
            'index_count' => $index_count,
            'actual_count' => $actual_count,
            'health_ratio' => $health_ratio,
            'is_healthy' => $health_ratio >= 0.95,
            'missing_customers' => max(0, $actual_count - $index_count)
        ];
    }
}

/*==========================================================================
 * DELAYED UPDATE HOOK
 ==========================================================================*/
add_action('ewneater_delayed_order_update', function($order_id) {
    if (class_exists('EWNeater_Customer_Revenue_Hooks')) {
        EWNeater_Customer_Revenue_Hooks::update_customer_from_order($order_id);
    }
});

/*==========================================================================
 * HOOK INITIALIZATION
 ==========================================================================*/
// Initialize hooks only after WordPress is fully loaded
add_action('init', function() {
    if (class_exists('EWNeater_Customer_Revenue_Hooks')) {
        EWNeater_Customer_Revenue_Hooks::init();
    }
}, 20);
