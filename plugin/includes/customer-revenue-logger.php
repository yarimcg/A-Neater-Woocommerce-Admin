<?php
/*==========================================================================
 * CUSTOMER REVENUE LOGGER
 *
 * Provides comprehensive logging functionality for customer revenue operations:
 * - WooCommerce log file integration
 * - Multiple log levels (ERROR, WARNING, INFO, DEBUG)
 * - Performance and memory tracking
 * - Data source monitoring and debugging
 ==========================================================================*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class EWNeater_Customer_Revenue_Logger {

    /*==========================================================================
     * CLASS CONSTANTS
     ==========================================================================*/
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_INFO = 'INFO';
    const LEVEL_DEBUG = 'DEBUG';

    const LOG_FILE = 'ewneater-customer-revenue';
    const MAX_LOG_ENTRIES = 100;
    const LOG_OPTION = 'ewneater_customer_revenue_log';

    // Throttling constants and cache
    const THROTTLE_WINDOW = 30; // seconds
    private static $throttle_cache = [];

    // Batch logging
    private static $batch_logs = [];
    private static $batch_timer = null;
    const BATCH_WINDOW = 60; // seconds

    /*==========================================================================
     * LOG LEVEL METHODS
     ==========================================================================*/
    public static function error($message, $context = []) {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    public static function warning($message, $context = []) {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    public static function info($message, $context = []) {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /*==========================================================================
     * MULTI-EMAIL CUSTOMER LOGGING
     *
     * Specialized logging for customer email consolidation scenarios:
     * - When billing email differs from logged-in user account
     * - When wholesale customers use different billing emails
     * - Customer type reclassification events
     ==========================================================================*/
    public static function log_multi_email_customer($billing_email, $user_email, $customer_data, $scenario = '') {
        $context = [
            'billing_email' => $billing_email,
            'user_account_email' => $user_email,
            'customer_id' => $customer_data['customer_id'] ?? 0,
            'customer_name' => $customer_data['customer_name'] ?? 'Unknown',
            'customer_type' => $customer_data['customer_type'] ?? 'guest',
            'scenario' => $scenario ?: 'Multi-email customer detected',
            'consolidation_type' => $billing_email !== $user_email ? 'different_emails' : 'same_email'
        ];

        $message = sprintf(
            'Multi-email customer: %s (billing: %s, account: %s) - %s',
            $customer_data['customer_name'] ?? 'Unknown',
            $billing_email,
            $user_email,
            $customer_data['customer_type'] ?? 'guest'
        );

        self::log(self::LEVEL_INFO, $message, $context);
    }

    public static function debug($message, $context = []) {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /*==========================================================================
     * CORE LOGGING FUNCTION
     *
     * Handles all logging operations with multiple destinations:
     * - WooCommerce logger for persistent storage
     * - In-memory logs for quick admin access
     * - WordPress debug.log integration
     ==========================================================================*/
    public static function log($level, $message, $context = []) {
        // Check if logging is enabled
        $logging_enabled = get_option('ewneater_customer_revenue_logging_enabled', 'yes') === 'yes';
        if (!$logging_enabled) {
            return;
        }

        // Format message for logging with local timezone
        $local_time = current_time('Y-m-d H:i:s T');
        $context_str = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        $log_message = "[{$local_time}] [{$level}] {$message}{$context_str}";

        // Log to WooCommerce logger if available
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $log_level = strtolower($level);

            // Map our levels to WC levels
            switch ($log_level) {
                case 'error':
                    $wc_level = 'error';
                    break;
                case 'warning':
                    $wc_level = 'warning';
                    break;
                case 'info':
                    $wc_level = 'info';
                    break;
                case 'debug':
                default:
                    $wc_level = 'debug';
                    break;
            }

            $logger->log($wc_level, $log_message, array('source' => self::LOG_FILE));
        }

        // Store in-memory logs for quick admin access
        $logs = get_option(self::LOG_OPTION, []);

        // Create log entry with proper timezone
        $entry = [
            'timestamp' => current_time('mysql'),
            'timestamp_formatted' => current_time('Y-m-d H:i:s T'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'timezone' => wp_timezone_string()
        ];

        // Add to logs
        array_unshift($logs, $entry);

        // Limit log size
        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_LOG_ENTRIES);
        }

        // Save logs
        update_option(self::LOG_OPTION, $logs);

        // Log to WordPress debug.log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("EWNeater Customer Revenue [{$level}]: {$message}{$context_str}");
        }
    }

    /*==========================================================================
     * LOG RETRIEVAL AND MANAGEMENT
     ==========================================================================*/
    public static function get_logs($limit = null) {
        $logs = get_option(self::LOG_OPTION, []);

        if ($limit && is_numeric($limit)) {
            return array_slice($logs, 0, $limit);
        }

        return $logs;
    }

    public static function get_logs_by_level($level, $limit = null) {
        $logs = self::get_logs();
        $filtered = array_filter($logs, function($log) use ($level) {
            return $log['level'] === $level;
        });

        if ($limit && is_numeric($limit)) {
            return array_slice($filtered, 0, $limit);
        }

        return $filtered;
    }

    public static function clear_logs() {
        // Clear in-memory logs
        delete_option(self::LOG_OPTION);

        // Clear WooCommerce log files for this source
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();

            // Get the log directory
            $log_dir = WC_LOG_DIR;
            if (is_dir($log_dir)) {
                $files = glob($log_dir . 'ewneater-customer-revenue*.log');
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
            }
        }

        self::info('All customer revenue logs cleared (in-memory and WooCommerce files)');
    }

    /*==========================================================================
     * LOGGING TOGGLE FUNCTIONS
     ==========================================================================*/
    public static function toggle_logging($enabled) {
        $status = $enabled ? 'yes' : 'no';
        update_option('ewneater_customer_revenue_logging_enabled', $status);

        if ($enabled) {
            self::info('Customer Revenue logging enabled');
        } else {
            self::info('Customer Revenue logging disabled');
        }

        return $status;
    }

    public static function is_logging_enabled() {
        return get_option('ewneater_customer_revenue_logging_enabled', 'yes') === 'yes';
    }

    /*==========================================================================
     * LOG STATISTICS
     ==========================================================================*/
    public static function get_log_stats() {
        $logs = self::get_logs();
        $stats = [
            'total' => count($logs),
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'debug' => 0,
            'last_entry' => null
        ];

        // Count in-memory logs
        foreach ($logs as $log) {
            switch ($log['level']) {
                case self::LEVEL_ERROR:
                    $stats['error']++;
                    break;
                case self::LEVEL_WARNING:
                    $stats['warning']++;
                    break;
                case self::LEVEL_INFO:
                    $stats['info']++;
                    break;
                case self::LEVEL_DEBUG:
                    $stats['debug']++;
                    break;
            }
        }

        // Count WooCommerce log files entries
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();

            // Get the log directory
            $log_dir = WC_LOG_DIR;
            if (is_dir($log_dir)) {
                $files = glob($log_dir . 'ewneater-customer-revenue*.log');
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        $file_lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        $stats['total'] += count($file_lines);
                    }
                }
            }
        }

        if (!empty($logs)) {
            $stats['last_entry'] = $logs[0]['timestamp'];
        }

        return $stats;
    }

    /*==========================================================================
     * SPECIALIZED LOGGING FUNCTIONS
     ==========================================================================*/
    public static function log_data_source($email, $source, $order_count = null, $revenue = null, $order_id = null, $customer_name = null) {
        $context = [
            'email' => $email,
            'source' => $source,
        ];

        if ($order_count !== null) {
            $context['order_count'] = $order_count;
        }

        if ($revenue !== null) {
            $context['revenue'] = $revenue;
        }

        if ($order_id !== null) {
            $context['order_id'] = $order_id;
        }

        if ($customer_name !== null) {
            $context['customer_name'] = $customer_name;
        }

        $message = "Customer data loaded from {$source} for {$email}";

        if ($source === 'direct_calculation') {
            self::warning($message, $context);
        } else {
            self::info($message, $context);
        }
    }

    /*==========================================================================
     * BATCH LOGGING HELPERS
     ==========================================================================*/
    public static function flush_batch() {
        if (empty(self::$batch_logs)) {
            return;
        }

        $count = count(self::$batch_logs);

        if ($count === 1) {
            // Single log - process normally
            $log = reset(self::$batch_logs);
            self::debug($log['message'], $log['context']);
        } else {
            // Multiple logs - create summary
            $customers = [];
            $orders = [];

            foreach (self::$batch_logs as $log) {
                if (isset($log['context']['email'])) {
                    $customers[$log['context']['email']] = true;
                }
                if (isset($log['context']['order_id'])) {
                    $orders[$log['context']['order_id']] = true;
                }
            }

            $summary = sprintf(
                'Batch processed %d customer revenue updates (%d customers, %d orders)',
                $count,
                count($customers),
                count($orders)
            );

            self::info($summary, [
                'batch_size' => $count,
                'unique_customers' => count($customers),
                'unique_orders' => count($orders),
                'customers' => array_keys($customers),
                'orders' => array_keys($orders)
            ]);
        }

        // Clear batch
        self::$batch_logs = [];
        self::$batch_timer = null;
    }

    private static function add_to_batch($key, $message, $context) {
        // Initialize batch timer if not set
        if (self::$batch_timer === null) {
            self::$batch_timer = time();
        }

        // Add to batch
        self::$batch_logs[$key] = [
            'message' => $message,
            'context' => $context,
            'timestamp' => time()
        ];

        // Check if batch should be flushed
        if ((time() - self::$batch_timer) >= self::BATCH_WINDOW || count(self::$batch_logs) >= 10) {
            self::flush_batch();
        }
    }

    /*==========================================================================
     * LOG THROTTLING HELPERS
     ==========================================================================*/
    private static function should_throttle_log($throttle_key) {
        $current_time = time();

        // Clean old throttle entries
        self::cleanup_throttle_cache();

        // Check if this log was already processed recently
        if (isset(self::$throttle_cache[$throttle_key])) {
            $last_logged = self::$throttle_cache[$throttle_key];
            if (($current_time - $last_logged) < self::THROTTLE_WINDOW) {
                return true; // Should throttle
            }
        }

        return false; // Should not throttle
    }

    private static function mark_log_processed($throttle_key) {
        self::$throttle_cache[$throttle_key] = time();
    }

    private static function cleanup_throttle_cache() {
        $current_time = time();
        $cutoff = $current_time - (self::THROTTLE_WINDOW * 2);

        foreach (self::$throttle_cache as $key => $timestamp) {
            if ($timestamp < $cutoff) {
                unset(self::$throttle_cache[$key]);
            }
        }
    }

    public static function log_index_operation($operation, $details = []) {
        $context = array_merge([
            'operation' => $operation,
            'timestamp' => current_time('mysql')
        ], $details);

        switch ($operation) {
            case 'index_build_start':
                self::info('Customer revenue index build started', $context);
                break;
            case 'index_build_complete':
                self::info('Customer revenue index build completed', $context);
                break;
            case 'index_build_failed':
                self::error('Customer revenue index build failed', $context);
                break;
            case 'customer_updated':
                self::debug('Customer data updated', $context);
                break;
            case 'customer_added':
                self::debug('New customer added to index', $context);
                break;
            case 'batch_processed':
                self::debug('Batch processed', $context);
                break;
            default:
                self::info("Index operation: {$operation}", $context);
        }
    }

    public static function log_order_processing($order_id, $email, $action, $details = []) {
        // Create throttle key based on order and customer
        $throttle_key = $order_id . '_' . $email . '_' . $action;

        // Check if we should throttle this log
        if (self::should_throttle_log($throttle_key)) {
            return; // Skip logging to prevent spam
        }

        $context = array_merge([
            'order_id' => $order_id,
            'email' => $email,
            'action' => $action
        ], $details);

        $message = "Order {$order_id} - {$action} for customer {$email}";

        // Add to batch instead of immediate logging
        self::add_to_batch($throttle_key, $message, $context);

        // Mark this log as processed
        self::mark_log_processed($throttle_key);
    }

    public static function log_performance($operation, $duration, $records_processed = null) {
        $context = [
            'operation' => $operation,
            'duration_seconds' => round($duration, 3),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];

        if ($records_processed !== null) {
            $context['records_processed'] = $records_processed;
            $context['records_per_second'] = $duration > 0 ? round($records_processed / $duration, 2) : 0;
        }

        if ($duration > 5) {
            self::warning("Slow operation detected: {$operation}", $context);
        } else {
            self::debug("Performance: {$operation}", $context);
        }
    }

    /*==========================================================================
     * LOG DISPLAY AND EXPORT
     ==========================================================================*/
    public static function display_logs_html($limit = 50) {
        $logs = self::get_logs($limit);
        $stats = self::get_log_stats();

        if (empty($logs)) {
            return '<p>No logs available.</p>';
        }

        $html = '<div class="customer-revenue-logs">';

        // Log statistics summary
        $html .= '<div class="log-stats" style="margin-bottom: 20px; padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa;">';
        $html .= '<h4>Log Statistics</h4>';
        $html .= '<p>Total Entries: <strong>' . $stats['total'] . '</strong> | ';
        $html .= 'Errors: <strong>' . $stats['error'] . '</strong> | ';
        $html .= 'Warnings: <strong>' . $stats['warning'] . '</strong> | ';
        $html .= 'Info: <strong>' . $stats['info'] . '</strong> | ';
        $html .= 'Debug: <strong>' . $stats['debug'] . '</strong></p>';
        if ($stats['last_entry']) {
            $html .= '<p>Last Entry: <strong>' . $stats['last_entry'] . '</strong></p>';
        }
        $html .= '</div>';

        // Log entries table
        $html .= '<table class="wp-list-table widefat fixed striped">';
        $html .= '<thead><tr>';
        $html .= '<th style="width: 150px;">Timestamp</th>';
        $html .= '<th style="width: 80px;">Level</th>';
        $html .= '<th>Message</th>';
        $html .= '<th style="width: 100px;">Memory</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($logs as $log) {
            $level_class = 'log-level-' . strtolower($log['level']);
            $memory = self::format_bytes($log['memory_usage']);

            $html .= '<tr class="' . $level_class . '">';
            $html .= '<td>' . esc_html($log['timestamp']) . '</td>';
            $html .= '<td><span class="log-level-badge ' . $level_class . '">' . esc_html($log['level']) . '</span></td>';
            $html .= '<td>';
            $html .= '<div class="log-message">' . esc_html($log['message']) . '</div>';

            if (!empty($log['context'])) {
                $html .= '<details style="margin-top: 5px;"><summary style="cursor: pointer; font-size: 12px; color: #666;">Context</summary>';
                $html .= '<pre style="font-size: 11px; background: #f5f5f5; padding: 5px; margin: 5px 0; overflow-x: auto;">' . esc_html(wp_json_encode($log['context'], JSON_PRETTY_PRINT)) . '</pre>';
                $html .= '</details>';
            }

            $html .= '</td>';
            $html .= '<td>' . $memory . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        // Log display styling
        $html .= '<style>
            .log-level-badge {
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
            }
            .log-level-error { background-color: #dc3232; color: white; }
            .log-level-warning { background-color: #ffb900; color: #333; }
            .log-level-info { background-color: #00a0d2; color: white; }
            .log-level-debug { background-color: #82878c; color: white; }
            .log-message { font-family: monospace; }
            .customer-revenue-logs pre { font-size: 11px; }
        </style>';

        return $html;
    }

    /*==========================================================================
     * UTILITY FUNCTIONS
     ==========================================================================*/
    private static function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public static function export_logs() {
        $logs = self::get_logs();
        $stats = self::get_log_stats();

        return wp_json_encode([
            'exported_at' => current_time('mysql'),
            'statistics' => $stats,
            'logs' => $logs
        ], JSON_PRETTY_PRINT);
    }

    /*==========================================================================
     * INITIALIZATION
     ==========================================================================*/
    public static function init() {
        // Register shutdown hook to flush any remaining batch logs
        if (function_exists('add_action')) {
            add_action('shutdown', [__CLASS__, 'flush_batch']);
        }
    }
}

/*==========================================================================
 * INITIALIZE LOGGER
 ==========================================================================*/
EWNeater_Customer_Revenue_Logger::init();
