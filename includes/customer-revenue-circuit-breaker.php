<?php
/*==========================================================================
 * CUSTOMER REVENUE CIRCUIT BREAKER
 *
 * Emergency protection system to prevent cascading failures:
 * - Monitors error rates and failure patterns
 * - Temporarily disables revenue tracking when errors exceed threshold
 * - Provides automatic recovery mechanism
 * - Logs all circuit breaker activities
 ==========================================================================*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class EWNeater_Customer_Revenue_Circuit_Breaker {

    const OPTION_PREFIX = 'ewneater_revenue_breaker_';
    const ERROR_THRESHOLD = 10; // Maximum errors before circuit opens
    const TIME_WINDOW = 300; // 5 minutes in seconds
    const RECOVERY_TIME = 900; // 15 minutes before attempting recovery

    private static $instance = null;
    private $is_open = false;
    private $error_count = 0;
    private $last_failure_time = 0;

    /*==========================================================================
     * SINGLETON PATTERN
     ==========================================================================*/
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_state();
    }

    /*==========================================================================
     * STATE MANAGEMENT
     ==========================================================================*/
    private function load_state() {
        if (!function_exists('get_option')) {
            return;
        }

        $state = get_option(self::OPTION_PREFIX . 'state', [
            'is_open' => false,
            'error_count' => 0,
            'last_failure_time' => 0,
            'last_reset_time' => time()
        ]);

        $this->is_open = $state['is_open'];
        $this->error_count = $state['error_count'];
        $this->last_failure_time = $state['last_failure_time'];

        // Auto-reset if enough time has passed
        $this->check_recovery();
    }

    private function save_state() {
        if (!function_exists('update_option')) {
            return;
        }

        update_option(self::OPTION_PREFIX . 'state', [
            'is_open' => $this->is_open,
            'error_count' => $this->error_count,
            'last_failure_time' => $this->last_failure_time,
            'last_reset_time' => time()
        ]);
    }

    /*==========================================================================
     * CIRCUIT BREAKER LOGIC
     ==========================================================================*/
    public function is_open() {
        $this->check_recovery();
        return $this->is_open;
    }

    public function can_execute() {
        return !$this->is_open();
    }

    public function record_success() {
        if ($this->error_count > 0) {
            $this->error_count = max(0, $this->error_count - 1);
            $this->save_state();

            if ($this->is_open && $this->error_count < (self::ERROR_THRESHOLD / 2)) {
                $this->close_circuit();
            }
        }
    }

    public function record_failure($error_message = '') {
        $current_time = time();

        // Reset error count if we're outside the time window
        if ($current_time - $this->last_failure_time > self::TIME_WINDOW) {
            $this->error_count = 0;
        }

        $this->error_count++;
        $this->last_failure_time = $current_time;

        // Log the failure
        $this->log_failure($error_message);

        // Open circuit if threshold exceeded
        if ($this->error_count >= self::ERROR_THRESHOLD && !$this->is_open) {
            $this->open_circuit();
        }

        $this->save_state();
    }

    private function open_circuit() {
        $this->is_open = true;

        // Log circuit opening
        error_log('EWNeater Customer Revenue: Circuit breaker OPENED - too many failures');

        if (class_exists('EWNeater_Customer_Revenue_Logger')) {
            EWNeater_Customer_Revenue_Logger::critical('Circuit breaker opened', [
                'error_count' => $this->error_count,
                'threshold' => self::ERROR_THRESHOLD,
                'recovery_time' => self::RECOVERY_TIME . ' seconds'
            ]);
        }

        // Schedule recovery check
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + self::RECOVERY_TIME, 'ewneater_revenue_circuit_recovery');
        }
    }

    private function close_circuit() {
        $this->is_open = false;
        $this->error_count = 0;

        // Log circuit closing
        error_log('EWNeater Customer Revenue: Circuit breaker CLOSED - service recovered');

        if (class_exists('EWNeater_Customer_Revenue_Logger')) {
            EWNeater_Customer_Revenue_Logger::info('Circuit breaker closed - service recovered');
        }
    }

    private function check_recovery() {
        if ($this->is_open && (time() - $this->last_failure_time) > self::RECOVERY_TIME) {
            $this->close_circuit();
            $this->save_state();
        }
    }

    /*==========================================================================
     * LOGGING AND MONITORING
     ==========================================================================*/
    private function log_failure($error_message) {
        $log_entry = sprintf(
            'Customer Revenue failure #%d: %s',
            $this->error_count,
            $error_message ?: 'Unknown error'
        );

        error_log('EWNeater Customer Revenue: ' . $log_entry);

        if (class_exists('EWNeater_Customer_Revenue_Logger')) {
            EWNeater_Customer_Revenue_Logger::warning('Circuit breaker failure recorded', [
                'error_count' => $this->error_count,
                'threshold' => self::ERROR_THRESHOLD,
                'error_message' => $error_message,
                'time_to_threshold' => self::ERROR_THRESHOLD - $this->error_count
            ]);
        }
    }

    /*==========================================================================
     * EXECUTION WRAPPER
     ==========================================================================*/
    public function execute($callback, $order_id = null) {
        if (!$this->can_execute()) {
            error_log('EWNeater Customer Revenue: Operation blocked by circuit breaker');
            return false;
        }

        try {
            $result = call_user_func($callback, $order_id);
            $this->record_success();
            return $result;
        } catch (Exception $e) {
            $this->record_failure($e->getMessage());
            return false;
        } catch (Error $e) {
            $this->record_failure('Fatal error: ' . $e->getMessage());
            return false;
        }
    }

    /*==========================================================================
     * MANUAL CONTROLS
     ==========================================================================*/
    public function manual_reset() {
        $this->close_circuit();
        $this->save_state();

        error_log('EWNeater Customer Revenue: Circuit breaker manually reset');

        if (class_exists('EWNeater_Customer_Revenue_Logger')) {
            EWNeater_Customer_Revenue_Logger::info('Circuit breaker manually reset');
        }
    }

    public function manual_open() {
        $this->open_circuit();
        $this->save_state();

        error_log('EWNeater Customer Revenue: Circuit breaker manually opened');
    }

    /*==========================================================================
     * STATUS REPORTING
     ==========================================================================*/
    public function get_status() {
        return [
            'is_open' => $this->is_open,
            'error_count' => $this->error_count,
            'error_threshold' => self::ERROR_THRESHOLD,
            'last_failure_time' => $this->last_failure_time,
            'recovery_time_remaining' => $this->is_open ?
                max(0, self::RECOVERY_TIME - (time() - $this->last_failure_time)) : 0,
            'time_window' => self::TIME_WINDOW,
            'recovery_time' => self::RECOVERY_TIME
        ];
    }

    public function get_health_status() {
        $status = $this->get_status();

        if ($status['is_open']) {
            return 'circuit_open';
        } elseif ($status['error_count'] > (self::ERROR_THRESHOLD * 0.5)) {
            return 'degraded';
        } elseif ($status['error_count'] > 0) {
            return 'recovering';
        } else {
            return 'healthy';
        }
    }

    /*==========================================================================
     * CLEANUP
     ==========================================================================*/
    public static function cleanup() {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_PREFIX . 'state');
        }

        // Clear any scheduled recovery events
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('ewneater_revenue_circuit_recovery');
        }
    }
}

/*==========================================================================
 * RECOVERY HOOK
 ==========================================================================*/
add_action('ewneater_revenue_circuit_recovery', function() {
    $breaker = EWNeater_Customer_Revenue_Circuit_Breaker::get_instance();
    $breaker->manual_reset();
});

/*==========================================================================
 * INTEGRATION HELPERS
 ==========================================================================*/
function ewneater_revenue_circuit_breaker() {
    return EWNeater_Customer_Revenue_Circuit_Breaker::get_instance();
}

function ewneater_revenue_can_execute() {
    return EWNeater_Customer_Revenue_Circuit_Breaker::get_instance()->can_execute();
}

function ewneater_revenue_execute_safely($callback, $order_id = null) {
    return EWNeater_Customer_Revenue_Circuit_Breaker::get_instance()->execute($callback, $order_id);
}
