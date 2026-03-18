<?php
/*==========================================================================
 * WOOCOMMERCE QUICK FIND - LOGGER
 *
 * This file contains a centralized logging class for the Quick Find feature.
 * It handles:
 *
 * - Logging to WooCommerce logs instead of using error_log
 * - Option to enable/disable logging
 * - Standardized log context and formatting
 *
 * This file is included by all components that need to log information.
 ==========================================================================*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class EWNeater_Quick_Find_Logger {
    /**
     * Log context for WC_Logger
     */
    const LOG_CONTEXT = array('source' => 'ewneater-quick-find');

    /**
     * Option name for the logging enabled setting
     */
    const LOGGING_ENABLED_OPTION = 'ewneater_quick_find_logging_enabled';

    /**
     * Initialize logging settings
     */
    public static function init() {
        // Set default logging state if not already set
        if (get_option(self::LOGGING_ENABLED_OPTION) === false) {
            update_option(self::LOGGING_ENABLED_OPTION, 'yes');
        }
    }

    /**
     * Check if logging is enabled
     *
     * @return bool Whether logging is enabled
     */
    public static function is_logging_enabled() {
        return get_option(self::LOGGING_ENABLED_OPTION, 'yes') === 'yes';
    }

    /**
     * Enable logging
     */
    public static function enable_logging() {
        update_option(self::LOGGING_ENABLED_OPTION, 'yes');
    }

    /**
     * Disable logging
     */
    public static function disable_logging() {
        update_option(self::LOGGING_ENABLED_OPTION, 'no');
    }

    /**
     * Log an info message to WooCommerce logs
     *
     * @param string $message The message to log
     */
    public static function info($message) {
        if (!self::is_logging_enabled()) {
            return;
        }

        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->info($message, self::LOG_CONTEXT);
        }
    }

    /**
     * Log an error message to WooCommerce logs
     *
     * @param string $message The message to log
     */
    public static function error($message) {
        if (!self::is_logging_enabled()) {
            return;
        }

        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->error($message, self::LOG_CONTEXT);
        }
    }

    /**
     * Log a warning message to WooCommerce logs
     *
     * @param string $message The message to log
     */
    public static function warning($message) {
        if (!self::is_logging_enabled()) {
            return;
        }

        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->warning($message, self::LOG_CONTEXT);
        }
    }

    /**
     * Log a debug message to WooCommerce logs
     *
     * @param string $message The message to log
     */
    public static function debug($message) {
        if (!self::is_logging_enabled()) {
            return;
        }

        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->debug($message, self::LOG_CONTEXT);
        }
    }

    /**
     * Get the URL to view logs in WooCommerce
     *
     * @return string URL to WooCommerce logs
     */
    public static function get_logs_url() {
        return admin_url('admin.php?page=wc-status&tab=logs');
    }
}

// Initialize the logger
add_action('init', array('EWNeater_Quick_Find_Logger', 'init'));
