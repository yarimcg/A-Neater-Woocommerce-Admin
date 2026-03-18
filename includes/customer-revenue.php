<?php
/*==========================================================================
 * CUSTOMER REVENUE ADMIN PAGE
 *
 * Provides admin interface for viewing and managing customer revenue data:
 * - Sortable tables with filtering options
 * - Index management and rebuilding
 * - Real-time progress tracking
 * - WooCommerce logging integration
 ==========================================================================*/

// Prevent direct file access
if (!defined("ABSPATH")) {
    exit();
}

class EWNeater_Customer_Revenue
{
    /*==========================================================================
     * INITIALIZATION
     ==========================================================================*/
    public static function init()
    {
        // Register AJAX handlers
        add_action("wp_ajax_ewneater_rebuild_customer_revenue_index", [
            __CLASS__,
            "handle_rebuild_index",
        ]);
        add_action("wp_ajax_ewneater_clear_customer_revenue_index", [
            __CLASS__,
            "handle_clear_index",
        ]);
        add_action("wp_ajax_ewneater_clear_customer_revenue_logs", [
            __CLASS__,
            "handle_clear_logs",
        ]);
        add_action("wp_ajax_ewneater_check_customer_revenue_status", [
            __CLASS__,
            "handle_check_status",
        ]);
        add_action("wp_ajax_ewneater_get_customer_revenue_progress", [
            __CLASS__,
            "handle_get_progress",
        ]);
        add_action("wp_ajax_ewneater_toggle_customer_revenue_logging", [
            __CLASS__,
            "handle_toggle_logging",
        ]);
        add_action("admin_enqueue_scripts", [__CLASS__, "enqueue_scripts"]);
        add_action("admin_menu", [__CLASS__, "add_detailed_analytics_menu"]);

        // Add analytics AJAX handlers
        add_action("wp_ajax_ewneater_get_analytics_data", [
            __CLASS__,
            "handle_get_analytics_data",
        ]);
        add_action("wp_ajax_ewneater_get_detailed_analytics", [
            __CLASS__,
            "handle_get_detailed_analytics",
        ]);

        // Add force continue AJAX handler
        add_action("wp_ajax_ewneater_force_continue_index", [
            __CLASS__,
            "handle_force_continue_index",
        ]);

        add_action("wp_ajax_ewneater_check_new_customer_orders", [
            __CLASS__,
            "handle_check_new_orders",
        ]);
    }

    /*==========================================================================
     * MAIN DISPLAY FUNCTION
     *
     * Handles customer revenue admin page display:
     * - Index status and management controls
     * - Customer data filtering and sorting
     * - Real-time progress tracking
     * - WooCommerce logging integration
     ==========================================================================*/
    public static function display_customer_revenue_page()
    {
        // Log page access
        if (class_exists("EWNeater_Customer_Revenue_Logger")) {
            EWNeater_Customer_Revenue_Logger::info(
                "Customer Revenue admin page loaded"
            );
        }

        // Process form submissions
        if (isset($_POST["action"])) {
            switch ($_POST["action"]) {
                case "rebuild_index":
                    if (
                        wp_verify_nonce(
                            sanitize_text_field(wp_unslash($_POST["_wpnonce"] ?? '')),
                            "ewneater_rebuild_index"
                        )
                    ) {
                        $result = EWNeater_Customer_Revenue_Core::build_customer_revenue_index();
                        echo '<div class="notice notice-success"><p>' .
                            esc_html($result["message"]) .
                            "</p></div>";
                    }
                    break;
                case "clear_index":
                    if (
                        wp_verify_nonce(
                            sanitize_text_field(wp_unslash($_POST["_wpnonce"] ?? '')),
                            "ewneater_clear_index"
                        )
                    ) {
                        $result = EWNeater_Customer_Revenue_Core::clear_customer_revenue_index();
                        echo '<div class="notice notice-success"><p>' .
                            esc_html($result["message"]) .
                            "</p></div>";
                    }
                    break;
                case "clear_logs":
                    if (
                        wp_verify_nonce(
                            sanitize_text_field(wp_unslash($_POST["_wpnonce"] ?? '')),
                            "ewneater_clear_logs"
                        )
                    ) {
                        EWNeater_Customer_Revenue_Logger::clear_logs();
                        echo '<div class="notice notice-success"><p>Logs cleared successfully.</p></div>';
                    }
                    break;
            }
        }

        // Extract filter parameters from request
        $current_page = isset($_GET["paged"])
            ? max(1, intval($_GET["paged"]))
            : 1;
        $per_page = 25;
        $orderby = isset($_GET["orderby"])
            ? sanitize_text_field($_GET["orderby"])
            : "total_revenue";
        $order = isset($_GET["order"])
            ? sanitize_text_field($_GET["order"])
            : "DESC";
        $timeframe = isset($_GET["timeframe"])
            ? sanitize_text_field($_GET["timeframe"])
            : "all";
        $customer_type = isset($_GET["customer_type"])
            ? sanitize_text_field($_GET["customer_type"])
            : "";
        $search = isset($_GET["search"])
            ? sanitize_text_field($_GET["search"])
            : "";

        // Convert timeframe to date range
        $date_from = "";
        $date_to = "";

        switch ($timeframe) {
            case "last_month":
                $date_from = date("Y-m-01", strtotime("-1 month"));
                $date_to = date("Y-m-t", strtotime("-1 month"));
                break;
            case "this_month":
                $date_from = date("Y-m-01");
                $date_to = date("Y-m-t");
                break;
            case "last_3_months":
                $date_from = date("Y-m-01", strtotime("-3 months"));
                $date_to = date("Y-m-t");
                break;
            case "last_year":
                $date_from = date("Y-01-01", strtotime("-1 year"));
                $date_to = date("Y-12-31", strtotime("-1 year"));
                break;
            case "this_year":
                $date_from = date("Y-01-01");
                $date_to = date("Y-12-31");
                break;
        }

        // Retrieve filtered customer data
        $args = [
            "orderby" => $orderby,
            "order" => $order,
            "limit" => $per_page,
            "offset" => ($current_page - 1) * $per_page,
            "date_from" => $date_from,
            "date_to" => $date_to,
            "customer_type" => $customer_type,
            "search" => $search,
        ];

        $customers = EWNeater_Customer_Revenue_Core::get_customers($args);
        $total_customers = EWNeater_Customer_Revenue_Core::get_customers_count(
            $args
        );
        $total_pages = ceil($total_customers / $per_page);

        // Get current index statistics
        $stats = EWNeater_Customer_Revenue_Core::get_index_stats();

        // Get log count for Clear Logs button
        $log_stats = EWNeater_Customer_Revenue_Logger::get_log_stats();
        $log_count = $log_stats["total"] ?? 0;
        ?>
        <div class="wrap ewneater-dashboard-wrap ewneater-admin-page--full-width">
        <?php
        if (function_exists('ewneater_admin_page_styles')) {
            ewneater_admin_page_styles();
        }
        ?>
            <h1 class="ewneater-dash-title"><?php
                if (function_exists('ewneater_admin_breadcrumb')) {
                    ewneater_admin_breadcrumb(__('Customer Revenue', 'a-neater-woocommerce-admin'));
                } else {
                    echo esc_html__('Customer Revenue', 'a-neater-woocommerce-admin');
                }
                ?></h1>

            <!-- Feature Description -->
            <div class="ewneater-dash-intro ewneater-admin-section ewneater-customer-revenue-description">
                <p><strong>Enhanced Customer Analytics:</strong> Analyze customer behavior, track revenue trends, and identify your most valuable customers with advanced filtering and sorting capabilities.
                <a href="#" id="show-more-info" style="color: #00a32a; text-decoration: none;">Show more...</a></p>

                <div id="detailed-info" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <h4 style="margin-top: 0;">🎯 How This System Works:</h4>

                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 300px;">
                            <h5>📊 Analytics Features:</h5>
                            <ul style="margin: 10px 0;">
                                <li>📈 <strong>Top Customer Charts</strong> - Visual ranking of your best customers</li>
                                <li>🎛️ <strong>Date Range Filtering</strong> - Analyze any time period</li>
                                <li>📋 <strong>Customer Segmentation</strong> - Separate views for customers, guests, and wholesalers</li>
                                <li>💰 <strong>Revenue Tracking</strong> - Total revenue, order counts, and averages</li>
                                <li>⚡ <strong>Trend Indicators</strong> - See rising/falling customer performance</li>
                            </ul>
                        </div>

                        <div style="flex: 1; min-width: 300px;">
                            <h5>🔧 System Intelligence:</h5>
                            <ul style="margin: 10px 0;">
                                <li>🤖 <strong>Auto-Classification</strong> - Automatically detects customer types</li>
                                <li>📧 <strong>Multi-Email Handling</strong> - Links orders from different billing emails to same customer</li>
                                <li>⚙️ <strong>Real-time Updates</strong> - Analytics update as new orders come in</li>
                                <li>🏪 <strong>Wholesale Detection</strong> - Identifies wholesale customers regardless of billing email</li>
                                <li>📝 <strong>Comprehensive Logging</strong> - Full audit trail of all activities</li>
                            </ul>
                        </div>
                    </div>

                    <div style="margin-top: 15px; padding: 10px; background: #e8f5e8; border-radius: 4px;">
                        <strong>💡 Pro Tip:</strong> The system automatically handles complex scenarios like wholesale customers using personal emails for billing,
                        ensuring accurate customer classification and complete revenue tracking across all your customer segments.
                    </div>
                </div>
            </div>


            <?php if (!$stats || $stats->total_customers == 0): ?>
                <div class="notice notice-warning">
                    <p><strong>Customer Revenue Index is empty!</strong> Please build the index to start tracking customer revenue and order data.</p>
                </div>
            <?php endif; ?>

            <!-- Customer Analytics Section -->
            <div class="ewneater-admin-section analytics-container">
                <!-- Customers & Guests Analytics -->
                <div class="ewneater-dash-card card analytics-card" style="flex: 1;">
                    <h2>
                        <span class="analytics-icon">👥</span>
                        Customers Analytics
                        <button type="button" class="button button-small analytics-refresh" data-type="customers" style="float: right; margin-top: -3px;">
                            Refresh
                        </button>
                    </h2>

                    <div class="analytics-controls">
                        <label for="customers-date-range">Date Range:</label>
                        <select id="customers-date-range" class="analytics-date-range" data-type="customers">
                            <option value="1">Last 1 day</option>
                            <option value="7">Last 7 days</option>
                            <option value="30" selected>Last 30 days</option>
                            <option value="90">Last 90 days</option>
                            <option value="365">Last year</option>
                            <option value="all">All time</option>
                        </select>
                    </div>

                    <div class="analytics-summary">
                        <div class="analytics-stat">
                            <span class="stat-label">Customers:</span>
                            <span class="stat-value" id="customers-top-count">-</span>
                        </div>
                        <div class="analytics-stat">
                            <span class="stat-label">Total Revenue:</span>
                            <span class="stat-value" id="customers-total-revenue">-</span>
                        </div>
                        <div class="analytics-stat">
                            <span class="stat-label">Avg. Order Value:</span>
                            <span class="stat-value" id="customers-avg-order">-</span>
                        </div>
                    </div>

                    <div class="analytics-chart-container">
                        <canvas id="customers-chart" width="400" height="200"></canvas>
                    </div>

                    <div class="top-customers-list" id="customers-list-container">
                        <div class="loading-indicator" style="text-align: center; padding: 20px;">
                            <span class="spinner is-active"></span> Loading customer data...
                        </div>
                    </div>

                    <div class="analytics-actions">
                        <a href="<?php echo admin_url(
                            "admin.php?page=ewneater-detailed-analytics&type=customers"
                        ); ?>" class="button button-secondary">
                            View Full Details
                        </a>
                        <!-- <button type="button" class="button button-secondary analytics-trends" data-type="customers">
                            View Trends
                        </button> -->
                    </div>
                </div>

                <!-- Wholesalers Analytics -->
                <div class="ewneater-dash-card card analytics-card" style="flex: 1;">
                    <h2>
                        <span class="analytics-icon">⚡</span>
                        Wholesalers Analytics
                        <button type="button" class="button button-small analytics-refresh" data-type="wholesale" style="float: right; margin-top: -3px;">
                            Refresh
                        </button>
                    </h2>

                    <div class="analytics-controls">
                        <label for="wholesale-date-range">Date Range:</label>
                        <select id="wholesale-date-range" class="analytics-date-range" data-type="wholesale">
                            <option value="1">Last 1 day</option>
                            <option value="7">Last 7 days</option>
                            <option value="30" selected>Last 30 days</option>
                            <option value="90">Last 90 days</option>
                            <option value="365">Last year</option>
                            <option value="all">All time</option>
                        </select>
                    </div>

                    <div class="analytics-summary">
                        <div class="analytics-stat">
                            <span class="stat-label">Wholesalers:</span>
                            <span class="stat-value" id="wholesale-top-count">-</span>
                        </div>
                        <div class="analytics-stat">
                            <span class="stat-label">Total Revenue:</span>
                            <span class="stat-value" id="wholesale-total-revenue">-</span>
                        </div>
                        <div class="analytics-stat">
                            <span class="stat-label">Avg. Order Value:</span>
                            <span class="stat-value" id="wholesale-avg-order">-</span>
                        </div>
                    </div>

                    <div class="analytics-chart-container">
                        <canvas id="wholesale-chart" width="400" height="200"></canvas>
                    </div>

                    <div class="top-customers-list" id="wholesale-list-container">
                        <div class="loading-indicator" style="text-align: center; padding: 20px;">
                            <span class="spinner is-active"></span> Loading wholesale data...
                        </div>
                    </div>

                    <div class="analytics-actions">
                        <a href="<?php echo admin_url(
                            "admin.php?page=ewneater-detailed-analytics&type=wholesale"
                        ); ?>" class="button button-secondary">
                            View Full Details
                        </a>
                        <!-- <button type="button" class="button button-secondary analytics-trends" data-type="wholesale">
                            View Trends
                        </button> -->
                    </div>
                </div>
            </div>



            <!-- Index Status Section -->
            <?php
            // Get customer type breakdown stats
            $customers_guests_stats = EWNeater_Customer_Revenue_Core::get_customer_type_stats(
                ["guest", "registered", "customer"]
            );
            $wholesale_stats = EWNeater_Customer_Revenue_Core::get_customer_type_stats(
                ["wholesale", "company"]
            );
            ?>
            <div class="ewneater-admin-section status-actions-container">
                <!-- Customers & Guests Index Stats -->
                <div class="ewneater-dash-card card">
                    <h2>
                        <span class="analytics-icon">👥</span>
                        Customers & Guests Index
                    </h2>
                    <strong>Status:</strong> <?php
                    $progress = EWNeater_Customer_Revenue_Core::get_build_progress();
                    if ($progress["status"] === "building") {
                        echo "Processing (" .
                            round($progress["progress_percent"], 1) .
                            "%)";
                    } elseif ($stats && $stats->total_customers > 0) {
                        echo "Complete";
                    } else {
                        echo "Empty";
                    }
                    ?><br>
                    <?php if ($stats && $stats->last_updated): ?>
                        <strong>Last Updated:</strong> <?php echo esc_html(
                            $stats->last_updated
                        ); ?><br>
                    <?php endif; ?>
                    <strong>Indexed Customers:</strong> <?php echo esc_html(
                        number_format(
                            $customers_guests_stats->total_customers ?? 0
                        )
                    ); ?><br>
                    <strong>Total Orders:</strong> <?php echo esc_html(
                        number_format(
                            $customers_guests_stats->total_orders ?? 0
                        )
                    ); ?><br>
                    <strong>Total Revenue:</strong> $<?php echo esc_html(
                        number_format(
                            $customers_guests_stats->total_revenue ?? 0,
                            2
                        )
                    ); ?><br>
                    <strong>Average Revenue per Customer:</strong> $<?php echo esc_html(
                        number_format(
                            $customers_guests_stats->avg_revenue ?? 0,
                            2
                        )
                    ); ?><br>
                    <strong>Average Order Value:</strong> $<?php echo esc_html(
                        number_format(
                            ($customers_guests_stats->total_orders ?? 0) > 0
                                ? ($customers_guests_stats->total_revenue ??
                                        0) /
                                    ($customers_guests_stats->total_orders ?? 1)
                                : 0,
                            2
                        )
                    ); ?><br>
                </div>

                <!-- Wholesalers Index Stats -->
                <div class="ewneater-dash-card card">
                    <h2>
                        <span class="analytics-icon">⚡</span>
                        Wholesalers Index
                    </h2>
                    <strong>Status:</strong> <?php
                    $progress = EWNeater_Customer_Revenue_Core::get_build_progress();
                    if ($progress["status"] === "building") {
                        echo "Processing (" .
                            round($progress["progress_percent"], 1) .
                            "%)";
                    } elseif ($stats && $stats->total_customers > 0) {
                        echo "Complete";
                    } else {
                        echo "Empty";
                    }
                    ?><br>
                    <?php if ($stats && $stats->last_updated): ?>
                        <strong>Last Updated:</strong> <?php echo esc_html(
                            $stats->last_updated
                        ); ?><br>
                    <?php endif; ?>
                    <strong>Indexed Customers:</strong> <?php echo esc_html(
                        number_format($wholesale_stats->total_customers ?? 0)
                    ); ?><br>
                    <strong>Total Orders:</strong> <?php echo esc_html(
                        number_format($wholesale_stats->total_orders ?? 0)
                    ); ?><br>
                    <strong>Total Revenue:</strong> $<?php echo esc_html(
                        number_format($wholesale_stats->total_revenue ?? 0, 2)
                    ); ?><br>
                    <strong>Average Revenue per Customer:</strong> $<?php echo esc_html(
                        number_format($wholesale_stats->avg_revenue ?? 0, 2)
                    ); ?><br>
                    <strong>Average Order Value:</strong> $<?php echo esc_html(
                        number_format(
                            ($wholesale_stats->total_orders ?? 0) > 0
                                ? ($wholesale_stats->total_revenue ?? 0) /
                                    ($wholesale_stats->total_orders ?? 1)
                                : 0,
                            2
                        )
                    ); ?><br>
                </div>
            </div>

            <!-- Index Actions Section -->
            <div class="ewneater-admin-section status-actions-container">
                <div class="ewneater-dash-card card" style="flex: 2;">
                    <h2>Index Actions</h2>
                    <p>The customer revenue index updates in real-time for new orders and customers. Use these buttons to manually rebuild the index if needed.</p>

                    <div class="index-actions">
                        <button type="button" id="rebuild-index-btn" class="button button-primary">
                            Rebuild Index
                        </button>
                        <button type="button" id="force-continue-btn" class="button" style="background-color: #dc3545; border-color: #dc3545; color: #fff; font-weight: bold;">
                            🚨 Force Continue Index
                        </button>

                        <button type="button" id="check-new-orders-btn" class="button button-secondary">
                            Check for New Orders
                        </button>
                        <button type="button" id="clear-index-btn" class="button button-secondary">
                            Clear Index
                        </button>
                    </div>

                    <div id="index-progress" style="display: none; margin-top: 15px;">
                        <div class="progress-bar-container">
                            <div class="progress-bar" id="progress-bar"></div>
                        </div>
                        <div id="progress-status" class="progress-status"></div>
                    </div>

                    <div class="index-actions" style="margin-top: 10px;">
                        <?php if ($log_count > 0): ?>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field("ewneater_clear_logs"); ?>
                            <input type="hidden" name="action" value="clear_logs">
                            <button type="submit" class="button button-secondary" onclick="return confirm('This will clear all <?php echo $log_count; ?> customer revenue log entries. Continue?')">
                                Clear Logs (<?php echo $log_count; ?>)
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <h2>Logging Settings</h2>
                    <label>
                        <input type="checkbox" id="logging_enabled" <?php checked(
                            EWNeater_Customer_Revenue_Logger::is_logging_enabled()
                        ); ?>>
                        Enable Logging
                    </label>
                    <div id="logging_status" style="margin-top: 5px; color: #46b450; font-size: 12px; display: none;">
                        ✓ Setting saved
                    </div>
                    <p class="description">When enabled, activities are logged to <a href="<?php echo admin_url(
                        "admin.php?page=wc-status&tab=logs"
                    ); ?>">WooCommerce > Status > Logs</a>.</p>
                </div>
            </div>


            <!-- Customer Data Section -->
            <?php if ($stats && $stats->total_customers > 0): ?>
            <h2>Customer Revenue Data</h2>

            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" class="alignleft actions" id="customer-filter-form">
                    <input type="hidden" name="page" value="ewneater-customer-revenue">

                    <select name="timeframe">
                        <option value="all" <?php selected(
                            $timeframe,
                            "all"
                        ); ?>>All Time</option>
                        <option value="this_month" <?php selected(
                            $timeframe,
                            "this_month"
                        ); ?>>This Month</option>
                        <option value="last_month" <?php selected(
                            $timeframe,
                            "last_month"
                        ); ?>>Last Month</option>
                        <option value="last_3_months" <?php selected(
                            $timeframe,
                            "last_3_months"
                        ); ?>>Last 3 Months</option>
                        <option value="this_year" <?php selected(
                            $timeframe,
                            "this_year"
                        ); ?>>This Year</option>
                        <option value="last_year" <?php selected(
                            $timeframe,
                            "last_year"
                        ); ?>>Last Year</option>
                    </select>

                    <select name="customer_type">
                        <option value="" <?php selected(
                            $customer_type,
                            ""
                        ); ?>>All Customer Types</option>
                        <option value="customer" <?php selected(
                            $customer_type,
                            "customer"
                        ); ?>>Customers</option>
                        <option value="wholesale" <?php selected(
                            $customer_type,
                            "wholesale"
                        ); ?>>Wholesalers</option>
                        <option value="guest" <?php selected(
                            $customer_type,
                            "guest"
                        ); ?>>Guests</option>
                        <option value="company" <?php selected(
                            $customer_type,
                            "company"
                        ); ?>>Company</option>
                    </select>

                    <input type="search" name="search" value="<?php echo esc_attr(
                        $search
                    ); ?>" placeholder="Search customers...">

                    <button type="submit" class="button">Filter</button>
                </form>

                <?php if ($timeframe !== "all" || $customer_type || $search): ?>
                <div class="alignright actions">
                    <a href="<?php echo admin_url(
                        "admin.php?page=ewneater-customer-revenue"
                    ); ?>" class="button button-secondary" style="color: #666; border-color: #ccc;">
                        Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Results Table -->
            <table class="ewneater-admin-table wp-list-table widefat fixed striped" id="customer-revenue-table">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column">
                            <a href="<?php echo esc_url(
                                self::get_sort_url("customer_name", $order)
                            ); ?>">
                                Customer Name
                                <?php self::display_sort_indicator(
                                    "customer_name",
                                    $orderby,
                                    $order
                                ); ?>
                            </a>
                        </th>
                        <th scope="col" class="manage-column">Email</th>
                        <th scope="col" class="manage-column">Type</th>
                        <th scope="col" class="manage-column">
                            <a href="<?php echo esc_url(
                                self::get_sort_url("total_orders", $order)
                            ); ?>">
                                Total Orders
                                <?php self::display_sort_indicator(
                                    "total_orders",
                                    $orderby,
                                    $order
                                ); ?>
                            </a>
                        </th>
                        <th scope="col" class="manage-column">
                            <a href="<?php echo esc_url(
                                self::get_sort_url("total_revenue", $order)
                            ); ?>">
                                Total Revenue
                                <?php self::display_sort_indicator(
                                    "total_revenue",
                                    $orderby,
                                    $order
                                ); ?>
                            </a>
                        </th>
                        <th scope="col" class="manage-column">
                            <a href="<?php echo esc_url(
                                self::get_sort_url("first_order_date", $order)
                            ); ?>">
                                First Order
                                <?php self::display_sort_indicator(
                                    "first_order_date",
                                    $orderby,
                                    $order
                                ); ?>
                            </a>
                        </th>
                        <th scope="col" class="manage-column">
                            <a href="<?php echo esc_url(
                                self::get_sort_url("last_order_date", $order)
                            ); ?>">
                                Last Order
                                <?php self::display_sort_indicator(
                                    "last_order_date",
                                    $orderby,
                                    $order
                                ); ?>
                            </a>
                        </th>
                        <th scope="col" class="manage-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 20px;">
                            <?php if (
                                !$stats ||
                                $stats->total_customers == 0
                            ): ?>
                                No customer data found. <a href="#" onclick="document.querySelector('input[value=rebuild_index]').closest('form').submit(); return false;">Rebuild the index</a> to populate data.
                            <?php else: ?>
                                No customers match your current filters.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html(
                                        $customer->customer_name
                                    ); ?></strong>
                                    <?php if ($customer->customer_id > 0): ?>
                                        <br><small>ID: <?php echo esc_html(
                                            $customer->customer_id
                                        ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(
                                    $customer->customer_email
                                ); ?></td>
                                <td>
                                    <span class="customer-type-badge customer-type-<?php echo esc_attr(
                                        $customer->customer_type
                                    ); ?>">
                                        <?php
                                        $type_labels = [
                                            "guest" => "Guest",
                                            "registered" => "Customer",
                                            "wholesale" => "Wholesaler",
                                            "company" => "Company",
                                        ];
                                        echo esc_html(
                                            $type_labels[
                                                $customer->customer_type
                                            ] ??
                                                ucfirst(
                                                    $customer->customer_type
                                                )
                                        );
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html(
                                        $customer->total_orders
                                    ); ?></strong>
                                </td>
                                <td>
                                    <strong>$<?php echo esc_html(
                                        number_format(
                                            $customer->total_revenue,
                                            2
                                        )
                                    ); ?></strong>
                                </td>
                                <td>
                                    <?php echo $customer->first_order_date
                                        ? esc_html(
                                            date(
                                                "Y-m-d",
                                                strtotime(
                                                    $customer->first_order_date
                                                )
                                            )
                                        )
                                        : "N/A"; ?>
                                </td>
                                <td>
                                    <?php echo $customer->last_order_date
                                        ? esc_html(
                                            date(
                                                "Y-m-d",
                                                strtotime(
                                                    $customer->last_order_date
                                                )
                                            )
                                        )
                                        : "N/A"; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url(
                                        "edit.php?post_type=shop_order&s=" .
                                            urlencode($customer->customer_email)
                                    ); ?>" class="button button-small">
                                        View Orders
                                    </a>
                                    <?php if ($customer->customer_id > 0): ?>
                                        <a href="<?php echo admin_url(
                                            "user-edit.php?user_id=" .
                                                $customer->customer_id
                                        ); ?>" class="button button-small">
                                            Edit User
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html(
                            number_format($total_customers)
                        ); ?> customers</span>
                        <?php echo paginate_links([
                            "base" => add_query_arg("paged", "%#%"),
                            "format" => "",
                            "current" => $current_page,
                            "total" => $total_pages,
                            "prev_text" => "&laquo;",
                            "next_text" => "&raquo;",
                        ]); ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 40px;">
                    <h3>No Customer Data Available</h3>
                    <p>The customer revenue index is empty. Click "Rebuild Index" above to populate data from your existing orders.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Admin styles moved to includes/css/customer-revenue-admin.css -->

        <script>
        jQuery(document).ready(function($) {
            // Handle logging toggle
            $('#logging_enabled').change(function() {
                const isEnabled = $(this).is(':checked');

                $.post(ajaxurl, {
                    action: 'ewneater_toggle_customer_revenue_logging',
                    security: '<?php echo wp_create_nonce(
                        "ewneater_toggle_logging_nonce"
                    ); ?>',
                    enabled: isEnabled ? 'yes' : 'no'
                }, function(response) {
                    if (response.success) {
                        $('#logging_status').show().fadeOut(3000);
                    } else {
                        alert('Error updating logging setting: ' + response.data);
                        // Revert checkbox state on error
                        $('#logging_enabled').prop('checked', !isEnabled);
                    }
                }).fail(function() {
                    alert('Failed to update logging setting');
                    // Revert checkbox state on error
                    $('#logging_enabled').prop('checked', !isEnabled);
                });
            });

            // Handle filter form submission - scroll to table after page reload
            $(document).ready(function() {
                // Check if we have filter parameters and customer data exists
                var hasFilters = window.location.search.includes('timeframe=') ||
                               window.location.search.includes('customer_type=') ||
                               window.location.search.includes('search=');

                if (hasFilters && $('#customer-revenue-table').length && $('tbody tr').length > 0) {
                    setTimeout(function() {
                        $('html, body').animate({
                            scrollTop: $('#customer-revenue-table').offset().top - 130
                        }, 800);
                    }, 300);
                }
            });
            var progressInterval;
            var isBuilding = false;

            // Rebuild Index
            $('#rebuild-index-btn').click(function() {
                if (!confirm('This will rebuild the entire customer revenue index. This may take a few minutes. Continue?')) {
                    return;
                }

                startProgress();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ewneater_rebuild_customer_revenue_index',
                        security: '<?php echo wp_create_nonce(
                            "ewneater_rebuild_index"
                        ); ?>'
                    },
                    success: function(response) {
                        stopProgress();
                        if (response.success) {
                            alert('Index rebuilt successfully! ' + response.message);
                            location.reload();
                        } else {
                            alert('Error rebuilding index: ' + response.data);
                        }
                    },
                    error: function() {
                        stopProgress();
                        alert('An error occurred while rebuilding the index.');
                        $('#rebuild-index-btn').prop('disabled', false).text('Rebuild Index');
                    }
                });
            });

            // Force Continue Index
            $('#force-continue-btn').click(function() {
                if (!confirm('This will force continue the index build from where it left off. Continue?')) {
                    return;
                }

                startProgress();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ewneater_force_continue_index',
                        security: '<?php echo wp_create_nonce(
                            "ewneater_force_continue_index"
                        ); ?>'
                    },
                    success: function(response) {
                        stopProgress();
                        if (response.success) {
                            alert('Index continuation completed! ' + response.message);
                            location.reload();
                        } else {
                            alert('Error continuing index: ' + response.data);
                        }
                    },
                    error: function() {
                        stopProgress();
                        alert('An error occurred while continuing the index.');
                        $('#force-continue-btn').prop('disabled', false).text('Force Continue');
                    }
                });
            });




            // Check for New Orders
            $('#check-new-orders-btn').click(function() {
                const $btn = $(this);
                $btn.prop('disabled', true).text('Checking...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ewneater_check_new_customer_orders',
                        security: '<?php echo wp_create_nonce(
                            "ewneater_check_new_orders"
                        ); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('Check for New Orders');
                        if (response.success) {
                            if (response.data.too_many) {
                                alert(response.data.message + ' Please use the Rebuild Index button instead.');
                            } else {
                                alert(response.data.message);
                                if (response.data.status_updated) {
                                    alert('Index status updated to Complete! Page will reload.');
                                    location.reload();
                                } else if (response.data.processed > 0) {
                                    setTimeout(function() { location.reload(); }, 1500);
                                }
                            }
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error occurred'));
                        }
                    },
                    error: function() {
                        alert('An error occurred while checking for new orders.');
                    }
                });
            });

            // Clear Index
            $('#clear-index-btn').click(function() {
                if (!confirm('This will clear all customer revenue data. Continue?')) {
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ewneater_clear_customer_revenue_index',
                        security: '<?php echo wp_create_nonce(
                            "ewneater_clear_index"
                        ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Index cleared successfully!');
                            location.reload();
                        } else {
                            alert('Error clearing index: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred while clearing the index.');
                    }
                });
            });

            function startProgress() {
                isBuilding = true;
                $('#index-progress').show();
                $('#rebuild-index-btn').prop('disabled', true).text('Building...');
                $('#clear-index-btn').prop('disabled', true);
                $('#force-continue-btn').prop('disabled', false).text('🚨 Force Continue Index').show();
                $('#check-complete-btn').prop('disabled', false).show();
                lastProgressTime = Date.now();
                lastProgressPercent = 0;

                // Check progress every 2 seconds
                progressInterval = setInterval(function() {
                    checkProgress();
                }, 2000);

                // Initial progress check
                checkProgress();
            }

            function checkProgress() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ewneater_get_customer_revenue_progress',
                        security: '<?php echo wp_create_nonce(
                            "ewneater_get_progress"
                        ); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var data = response.data;
                            var percent = data.progress_percent || 0;
                            var processed = data.processed || 0;
                            var total = data.total || 0;
                            var remaining = data.estimated_remaining || 0;

                            $('#progress-bar').css('width', percent + '%');

                            var statusText = 'Processing customers: ' + processed + '/' + total + ' (' + percent + '%)';
                            if (remaining > 0) {
                                statusText += ' - Est. ' + remaining + 's remaining';
                            }

                            $('#progress-status').text(statusText);

                            // Detect if progress is stuck (show warning after 1 minute of no progress)
                            var currentTime = Date.now();
                            if (percent === lastProgressPercent) {
                                // No progress made, check if stuck
                                if (currentTime - lastProgressTime > 60000) { // 1 minute
                                    statusText += ' - ⚠️ STUCK - Use Force Continue';
                                    $('#progress-status').text(statusText);
                                    $('#force-continue-btn').pulse();
                                }
                            } else {
                                // Progress made, update tracking
                                lastProgressTime = currentTime;
                                lastProgressPercent = percent;
                            }

                            // Stop if completed
                            if (data.status === 'complete' || percent >= 100) {
                                stopProgress();
                                location.reload();
                            }
                        }
                    },
                    error: function() {
                        $('#progress-status').text('Error checking progress...');
                    }
                });
            }

            function stopProgress() {
                isBuilding = false;
                clearInterval(progressInterval);
                $('#index-progress').hide();
                $('#rebuild-index-btn').prop('disabled', false).text('Rebuild Index');
                $('#clear-index-btn').prop('disabled', false);
                $('#force-continue-btn').prop('disabled', false).text('🚨 Force Continue Index').hide();
                $('#check-complete-btn').prop('disabled', false).hide();
                $('#progress-bar').css('width', '0%');
                lastProgressTime = Date.now();
                lastProgressPercent = 0;
            }

            // Check if index is currently building
            function checkIndexStatus() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ewneater_get_customer_revenue_progress',
                        security: '<?php echo wp_create_nonce(
                            "ewneater_get_progress"
                        ); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data && response.data.status === 'building') {
                            startProgress();

                            // Check if stuck on page load
                            var lastUpdated = new Date(response.data.last_updated);
                            var now = new Date();
                            var minutesSinceUpdate = (now - lastUpdated) / (1000 * 60);

                            // Always show buttons when building, regardless of time
                            $('#force-continue-btn').show();
                            $('#check-complete-btn').show();
                        }
                    }
                });
            }

            // Check status on page load
            checkIndexStatus();

            // Auto-build index if empty
            <?php if (!$stats || $stats->total_customers == 0): ?>
            setTimeout(function() {
                if (!isBuilding && confirm('Customer revenue index is empty. Would you like to build it now?')) {
                    $('#rebuild-index-btn').click();
                }
            }, 1000);
            <?php endif; ?>

            // Check if index is already stuck on page load
            $(document).ready(function() {
                checkIndexStatus();

                // Always show Force Continue and Check Complete buttons if index is building
                setTimeout(function() {
                    if ($('#index-progress').is(':visible')) {
                        $('#force-continue-btn').show();
                        $('#check-complete-btn').show();
                    }
                }, 1000);
            });

            // Add pulse animation for attention
            $.fn.pulse = function() {
                return this.animate({ opacity: 0.5 }, 500)
                          .animate({ opacity: 1 }, 500)
                          .animate({ opacity: 0.5 }, 500)
                          .animate({ opacity: 1 }, 500);
            };

            // Show/hide detailed info
            $('#show-more-info').click(function(e) {
                e.preventDefault();
                var $detailsDiv = $('#detailed-info');
                var $link = $(this);

                if ($detailsDiv.is(':visible')) {
                    $detailsDiv.slideUp();
                    $link.text('Show more...');
                } else {
                    $detailsDiv.slideDown();
                    $link.text('Show less...');
                }
            });

            // Customer Analytics functionality
            let analyticsCharts = {};

            // Progress tracking variables for stuck detection
            let lastProgressTime = Date.now();
            let lastProgressPercent = 0;
            let stuckCheckInterval = null;

            // Initialize analytics on page load
            initializeAnalytics();

            function initializeAnalytics() {
                // Load initial data for both analytics boxes
                loadAnalyticsData('customers', 30);
                loadAnalyticsData('wholesale', 30);
            }

            // Handle date range changes
            $('.analytics-date-range').change(function() {
                const type = $(this).data('type');
                const days = $(this).val();
                loadAnalyticsData(type, days);
            });

            // Handle refresh buttons
            $('.analytics-refresh').click(function() {
                const type = $(this).data('type');
                const days = $('#' + type + '-date-range').val();
                loadAnalyticsData(type, days);
            });

            // Handle show more details
            $('.analytics-show-more').click(function() {
                const type = $(this).data('type');
                showAnalyticsModal(type);
            });

            // Handle trends view
            $('.analytics-trends').click(function() {
                const type = $(this).data('type');
                showTrendsModal(type);
            });

            function loadAnalyticsData(type, days) {
                const container = $('#' + type + '-list-container');
                const chartCanvas = $('#' + type + '-chart');

                // Show loading state
                container.html('<div class="loading-indicator" style="text-align: center; padding: 20px;"><span class="spinner is-active"></span> Loading ' + type + ' data...</div>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ewneater_get_analytics_data',
                        security: '<?php echo wp_create_nonce(
                            "ewneater_analytics_nonce"
                        ); ?>',
                        customer_type: type === 'customers' ? 'customers_guests' : 'wholesale',
                        days: days
                    },
                    success: function(response) {
                        if (response.success) {
                            updateAnalyticsDisplay(type, response.data);
                            renderChart(type, response.data.chart_data);
                        } else {
                            container.html('<div style="text-align: center; padding: 20px; color: #d63638;">Error loading data: ' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        container.html('<div style="text-align: center; padding: 20px; color: #d63638;">Failed to load analytics data</div>');
                    }
                });
            }

            function updateAnalyticsDisplay(type, data) {
                // Update summary statistics
                $('#' + type + '-top-count').text((typeof data.top_customers_count !== 'undefined' && data.top_customers_count !== null) ? data.top_customers_count : '0');
                $('#' + type + '-total-revenue').text('$' + (data.total_revenue || '0.00'));
                $('#' + type + '-avg-order').text('$' + (data.avg_order_value || '0.00'));

                // Update customer list
                const container = $('#' + type + '-list-container');
                if (data.customers && data.customers.length > 0) {
                    let html = '';
                    data.customers.forEach(function(customer, index) {
                        const trendIcon = getTrendIcon(customer.trend);
                        const profileLink = (customer.user_id && customer.user_id > 0)
                            ? `<a href='${ajaxurl.replace('admin-ajax.php','user-edit.php')}?user_id=${customer.user_id}' class='customer-profile-link' target='_blank' rel='noopener'>${escapeHtml(customer.name)}</a>`
                            : escapeHtml(customer.name);
                        html += `
                            <div class="customer-item">
                                <div class="customer-info">
                                    <div class="customer-name">${profileLink}</div>
                                    <div class="customer-email">${escapeHtml(customer.email)}</div>
                                </div>
                                <div class="customer-stats">
                                    <div class="customer-revenue">$${customer.total_revenue}</div>
                                    <div class="customer-orders">${customer.total_orders} orders</div>
                                </div>
                                <div class="trend-indicator ${customer.trend}">${trendIcon}</div>
                            </div>
                        `;
                    });
                    container.html(html);
                } else {
                    container.html('<div style="text-align: center; padding: 20px; color: #666;">No ' + type + ' data available for this period</div>');
                }
            }

            function renderChart(type, chartData) {
                const canvas = document.getElementById(type + '-chart');
                const ctx = canvas.getContext('2d');

                // Clear previous chart
                if (analyticsCharts[type]) {
                    analyticsCharts[type].destroy();
                }

                // Simple bar chart implementation
                if (chartData && chartData.length > 0) {
                    drawSimpleChart(ctx, chartData, type);
                } else {
                    // Draw placeholder
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.fillStyle = '#999';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText('No chart data available', canvas.width / 2, canvas.height / 2);
                }
            }

            function drawSimpleChart(ctx, data, type) {
                const canvas = ctx.canvas;
                const width = canvas.width;
                const height = canvas.height;
                const padding = 40;
                const chartWidth = width - (padding * 2);
                const chartHeight = height - (padding * 2);

                // Clear canvas
                ctx.clearRect(0, 0, width, height);

                if (data.length === 0) return;

                // Find max value for scaling
                const maxValue = Math.max(...data.map(d => d.value));
                const barWidth = chartWidth / data.length;

                // Set colors based on type
                const colors = type === 'customers' ?
                    ['#0073aa', '#00a0d0', '#2271b1', '#135e96', '#0085ba'] :
                    ['#dba617', '#ffb900', '#e5b100', '#cc9e00', '#b8890b'];

                // Draw bars
                data.forEach((item, index) => {
                    const barHeight = (item.value / maxValue) * chartHeight;
                    const x = padding + (index * barWidth) + (barWidth * 0.1);
                    const y = padding + chartHeight - barHeight;
                    const actualBarWidth = barWidth * 0.8;

                    // Draw bar
                    ctx.fillStyle = colors[index % colors.length];
                    ctx.fillRect(x, y, actualBarWidth, barHeight);

                    // Draw value on top
                    ctx.fillStyle = '#333';
                    ctx.font = '11px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText('$' + item.value, x + actualBarWidth / 2, y - 5);

                    // Draw label at bottom
                    ctx.fillText(item.label, x + actualBarWidth / 2, height - 10);
                });
            }

            function getTrendIcon(trend) {
                switch(trend) {
                    case 'trend-up': return '📈';
                    case 'trend-down': return '📉';
                    case 'trend-stable': return '➡️';
                    default: return '➡️';
                }
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Handle trends
            $('.analytics-trends').click(function() {
                const type = $(this).data('type');
                showTrendsModal(type);
            });

            function showTrendsModal(type) {
                alert('Trends analysis for ' + type + ' - Coming soon!');
            }
        });
        </script>

        <style>
        /* Analytics Container - 2 columns on desktop */
        .analytics-container {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .analytics-container .analytics-card {
            flex: 1;
            min-width: 0; /* Prevents flex items from overflowing */
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .analytics-container {
                flex-direction: column;
                gap: 15px;
            }
        }

        /* Customer type badges */
        .customer-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: #fff;
        }

        .customer-type-guest {
            background: #666;
        }

        .customer-type-registered {
            background: #0073aa;
        }

        .customer-type-customer {
            background: #00a32a;
        }

        .customer-type-wholesale {
            background: #d63638;
        }

        .customer-type-company {
            background: #8f2ce6;
        }

        .load-more-analytics {
            min-width: 150px;
            padding: 8px 16px;
        }

        .load-more-analytics:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Refresh button loading state */
        #refresh-detailed-analytics:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .refresh-spinner .spinner {
            visibility: visible;
            width: 20px;
            height: 20px;
        }

        .refresh-spinner {
            display: flex;
            align-items: center;
        }

        /* Load more button styling */
        .load-more-container {
            clear: both;
            width: 100%;
            margin-top: 20px !important;
            padding: 10px 0;
            text-align: center;
            border-top: 1px solid #ddd;
            background: #f9f9f9;
        }

        #load-more-customers {
            min-width: 200px;
            padding: 10px 20px;
            font-size: 14px;
            border-radius: 3px;
        }

        #load-more-customers:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Ensure table rows maintain proper styling - force consistency */
        .detailed-analytics .sortable-table {
            border-collapse: collapse;
            width: 100%;
        }

        .detailed-analytics .sortable-table tbody tr {
            background-color: #ffffff;
            border-bottom: 1px solid #ddd;
        }

        .detailed-analytics .sortable-table tbody tr.alternate,
        .detailed-analytics .sortable-table tbody tr:nth-child(odd) {
            background-color: #f9f9f9;
        }

        .detailed-analytics .sortable-table tbody tr:hover {
            background-color: #f5f5f5;
        }

        /* Customer profile links */
        .customer-profile-link {
            color: #0073aa;
            text-decoration: none;
            font-weight: 600;
        }

        .customer-profile-link:hover {
            color: #005a87;
            text-decoration: underline;
        }

        /* Summary Stats Styles */
        .analytics-summary-stats {
            margin-bottom: 30px;
        }

        .stats-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            flex: 1;
            min-width: 200px;
        }

        .stat-icon {
            font-size: 30px;
            opacity: 0.8;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #23282d;
        }

        /* Sortable Table Styles */
        .sortable-header {
            cursor: pointer;
            position: relative;
            user-select: none;
        }

        .sortable-header:hover {
            background: #f0f0f1;
        }

        .sortable-header:hover .sort-indicator:after {
            opacity: 0.8;
        }

        .sort-indicator {
            position: relative;
            display: inline-block;
            margin-left: 8px;
            width: 12px;
            height: 12px;
        }

        .sort-indicator:before {
            content: "↕";
            position: absolute;
            top: -2px;
            left: 0;
            opacity: 0.7;
            font-size: 14px;
            color: #666;
            font-weight: bold;
            line-height: 1;
        }

        .sortable-header.sorted-desc .sort-indicator:before {
            content: "↓";
            opacity: 1;
            color: #0073aa;
            font-weight: bold;
        }

        .sortable-header.sorted-asc .sort-indicator:before {
            content: "↑";
            opacity: 1;
            color: #0073aa;
            font-weight: bold;
        }

        .sortable-header:hover .sort-indicator:before {
            opacity: 1;
        }

        /* Fallback text indicators for sorting */
        .sortable-header.sorted-desc .sort-indicator:after {
            content: " DESC";
            position: absolute;
            left: 15px;
            top: -2px;
            font-size: 8px;
            color: #0073aa;
            opacity: 1;
            font-weight: bold;
        }

        .sortable-header.sorted-asc .sort-indicator:after {
            content: " ASC";
            position: absolute;
            left: 15px;
            top: -2px;
            font-size: 8px;
            color: #0073aa;
            opacity: 1;
            font-weight: bold;
        }

        .row-number-column {
            width: 30px;
            text-align: center;
            font-weight: bold;
            color: #666;
        }

        .graph-column {
            width: 35px;
            text-align: center;
            font-size: 16px;
        }

        .orders-column {
            width: 60px;
            text-align: center;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .stats-grid {
                gap: 15px;
            }

            .stat-box {
                padding: 15px;
                min-width: 150px;
            }

            .stat-icon {
                font-size: 24px;
            }

            .stat-value {
                font-size: 18px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                flex-direction: column;
                gap: 10px;
            }

            .stat-box {
                min-width: unset;
            }
        }

        /* Existing CSS */
        .customer-revenue {
            color: #23282d !important;
        }
        </style>
    <?php
    }

    /*==========================================================================
     * TABLE SORTING HELPERS
     ==========================================================================*/
    private static function get_sort_url($column, $current_order)
    {
        $order = $current_order === "ASC" ? "DESC" : "ASC";
        $args = array_merge($_GET, [
            "orderby" => $column,
            "order" => $order,
            "paged" => 1,
        ]);
        return admin_url("admin.php?" . http_build_query($args));
    }

    private static function display_sort_indicator(
        $column,
        $current_orderby,
        $current_order
    ) {
        $classes = "sort-indicator";
        if ($column === $current_orderby) {
            $classes .= " active";
            $classes .=
                $current_order === "ASC" ? " sorted-asc" : " sorted-desc";
        }
        echo '<span class="' . esc_attr($classes) . '"></span>';
    }

    /*==========================================================================
     * AJAX HANDLERS
     ==========================================================================*/
    public static function handle_rebuild_index()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_rebuild_index", "security");

        $result = EWNeater_Customer_Revenue_Core::build_customer_revenue_index();
        wp_send_json($result);
    }

    public static function handle_clear_index()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_clear_index", "security");

        $result = EWNeater_Customer_Revenue_Core::clear_customer_revenue_index();
        wp_send_json($result);
    }

    public static function handle_clear_logs()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_clear_logs", "security");

        EWNeater_Customer_Revenue_Logger::clear_logs();
        wp_send_json([
            "success" => true,
            "message" => "Logs cleared successfully",
        ]);
    }

    public static function handle_toggle_logging()
    {
        check_ajax_referer("ewneater_toggle_logging_nonce", "security");

        if (!current_user_can("manage_options")) {
            wp_send_json_error("Insufficient permissions");
        }

        $enabled = sanitize_text_field($_POST["enabled"]);

        if (!in_array($enabled, ["yes", "no"])) {
            wp_send_json_error("Invalid value");
        }

        update_option("ewneater_customer_revenue_logging_enabled", $enabled);
        wp_send_json_success("Logging setting updated");
    }

    public static function handle_check_status()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_check_status", "security");

        // Check if rebuild is scheduled or in progress
        $building =
            wp_next_scheduled("ewneater_build_customer_revenue_index") !==
            false;

        wp_send_json([
            "success" => true,
            "building" => $building,
            "message" => $building
                ? "Index is being built"
                : "Index is not building",
        ]);
    }

    /*==========================================================================
     * PROGRESS TRACKING AJAX HANDLER
     ==========================================================================*/
    public static function handle_get_progress()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_get_progress", "security");

        $progress = EWNeater_Customer_Revenue_Core::get_build_progress();
        wp_send_json_success($progress);
    }

    /*==========================================================================
     * ANALYTICS AJAX HANDLERS
     ==========================================================================*/
    public static function handle_get_analytics_data()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_analytics_nonce", "security");

        $customer_type = sanitize_text_field($_POST["customer_type"]);
        $days = intval($_POST["days"]);

        // Determine customer types to include
        $types = [];
        if ($customer_type === "customers_guests") {
            $types = ["guest", "registered", "customer"];
        } elseif ($customer_type === "wholesale") {
            $types = ["wholesale"];
        }

        // Calculate date range
        $date_from = "";
        $date_to = "";
        if ($days !== "all" && $days > 0) {
            $date_from = date("Y-m-d", strtotime("-{$days} days"));
        }

        // Get analytics data
        $analytics_data = self::get_analytics_data(
            $types,
            $date_from,
            $date_to,
            10
        );

        wp_send_json_success($analytics_data);
    }

    public static function add_detailed_analytics_menu()
    {
        add_submenu_page(
            "ewneater-customer-revenue",
            "Detailed Analytics",
            "Detailed Analytics",
            "manage_options",
            "ewneater-detailed-analytics",
            [__CLASS__, "display_detailed_analytics_page"]
        );
    }

    public static function display_detailed_analytics_page()
    {
        $customer_type = isset($_GET["type"])
            ? sanitize_text_field($_GET["type"])
            : "customers";
        $days = isset($_GET["days"]) ? intval($_GET["days"]) : 30;
        $limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 25;
        ?>
        <div class="wrap">
            <h1><?php echo $customer_type === "customers"
                ? "Detailed Customers & Guests Analytics"
                : "Detailed Wholesalers Analytics"; ?></h1>

            <div class="detailed-analytics-controls" style="margin-bottom: 20px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label for="detailed-date-range">Date Range:</label>
                    <select id="detailed-date-range">
                        <option value="1" <?php selected(
                            $days,
                            1
                        ); ?>>Last 1 day</option>
                        <option value="7" <?php selected(
                            $days,
                            7
                        ); ?>>Last 7 days</option>
                        <option value="30" <?php selected(
                            $days,
                            30
                        ); ?>>Last 30 days</option>
                        <option value="90" <?php selected(
                            $days,
                            90
                        ); ?>>Last 90 days</option>
                        <option value="365" <?php selected(
                            $days,
                            365
                        ); ?>>Last year</option>
                        <option value="all" <?php selected(
                            $days,
                            "all"
                        ); ?>>All time</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>

                <div id="custom-date-range" style="display: none; align-items: center; gap: 8px;">
                    <label for="date-from">From:</label>
                    <input type="date" id="date-from" style="padding: 3px 8px;">
                    <label for="date-to">To:</label>
                    <input type="date" id="date-to" style="padding: 3px 8px;">
                </div>

                <button type="button" class="button button-secondary" id="refresh-detailed-analytics">
                    <span class="refresh-text">Refresh</span>
                    <span class="refresh-spinner" style="display: none;">
                        <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Loading...
                    </span>
                </button>
                <a href="<?php echo admin_url(
                    "admin.php?page=ewneater-customer-revenue"
                ); ?>" style="text-decoration: none; color: #0073aa;">← Back to Overview</a>
            </div>

            <!-- Summary Stats Boxes -->
            <div id="summary-stats" class="ewneater-admin-section analytics-summary-stats" style="display: none; margin-bottom: 20px;">
                <div class="stats-grid">
                    <div class="ewneater-dash-card stat-box">
                        <div class="stat-icon">💰</div>
                        <div class="stat-content">
                            <div class="stat-label">Total Revenue</div>
                            <div class="stat-value" id="total-revenue">$0.00</div>
                        </div>
                    </div>
                    <div class="ewneater-dash-card stat-box">
                        <div class="stat-icon">📊</div>
                        <div class="stat-content">
                            <div class="stat-label">Average Order Value</div>
                            <div class="stat-value" id="avg-order-value">$0.00</div>
                        </div>
                    </div>
                    <div class="ewneater-dash-card stat-box">
                        <div class="stat-icon">👥</div>
                        <div class="stat-content">
                            <div class="stat-label">Total Customers</div>
                            <div class="stat-value" id="total-customers">0</div>
                        </div>
                    </div>
                    <div class="ewneater-dash-card stat-box">
                        <div class="stat-icon">📦</div>
                        <div class="stat-content">
                            <div class="stat-label">Total Orders</div>
                            <div class="stat-value" id="total-orders">0</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="detailed-analytics-content">
                <?php
                // Get URL parameters for sorting and filtering
                $sort_by = isset($_GET["sort_by"])
                    ? sanitize_text_field($_GET["sort_by"])
                    : "total_revenue";
                $sort_order = isset($_GET["sort_order"])
                    ? sanitize_text_field($_GET["sort_order"])
                    : "desc";
                $date_from = isset($_GET["date_from"])
                    ? sanitize_text_field($_GET["date_from"])
                    : "";
                $date_to = isset($_GET["date_to"])
                    ? sanitize_text_field($_GET["date_to"])
                    : "";

                // Determine customer types to include
                $types = [];
                if ($customer_type === "customers") {
                    $types = ["guest", "registered", "customer"];
                } elseif ($customer_type === "wholesale") {
                    $types = ["wholesale"];
                }

                // Calculate date range
                $date_from_calc = "";
                $date_to_calc = "";

                if ($days === "custom" && $date_from && $date_to) {
                    $date_from_calc = $date_from;
                    $date_to_calc = $date_to;
                } elseif ($days !== "all" && $days > 0) {
                    $date_from_calc = date("Y-m-d", strtotime("-{$days} days"));
                }

                // Get detailed analytics data
                $detailed_data = self::get_detailed_analytics_data(
                    $types,
                    $date_from_calc,
                    $date_to_calc,
                    $limit,
                    $sort_by,
                    $sort_order
                );

                echo $detailed_data["html"];

                if ($detailed_data["summary"]) {
                    echo '<script>
                    jQuery(document).ready(function($) {
                        $("#total-revenue").text("$' . esc_js($detailed_data["summary"]["total_revenue"]) . '");
                        $("#avg-order-value").text("$' . esc_js($detailed_data["summary"]["avg_order_value"]) . '");
                        $("#total-customers").text("' . esc_js($detailed_data["summary"]["total_customers"]) . '");
                        $("#total-orders").text("' . esc_js($detailed_data["summary"]["total_orders"]) . '");
                        $("#summary-stats").show();

                        // Scroll to position only when load more action was used
                        const urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.get("load_more") === "1") {
                            setTimeout(function() {
                                // Get scroll position from sessionStorage
                                const scrollToRow = sessionStorage.getItem(\'ewneater_scroll_to_row\');
                                if (scrollToRow) {
                                    const target = $(\'#\' + scrollToRow);
                                    if (target.length) {
                                        $("html, body").animate({
                                            scrollTop: target.offset().top - 100
                                        }, 500);
                                    }
                                    // Clean up sessionStorage
                                    sessionStorage.removeItem(\'ewneater_scroll_to_row\');
                                }
                                // Clean up the load_more parameter
                                urlParams.delete("load_more");
                                const newUrl = window.location.pathname + "?" + urlParams.toString();
                                window.history.replaceState({}, "", newUrl);
                            }, 100);
                        }
                    });
                    </script>';
                }
                ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const customerType = '<?php echo $customer_type; ?>';
            let currentSortBy = '<?php echo esc_js(
                $sort_by ?? "total_revenue"
            ); ?>';
            let currentSortOrder = '<?php echo esc_js(
                $sort_order ?? "desc"
            ); ?>';

            function loadDetailedAnalytics() {
                // Show loading indicator
                $('#refresh-detailed-analytics').find('.refresh-text').hide();
                $('#refresh-detailed-analytics').find('.refresh-spinner').show();
                $('#refresh-detailed-analytics').prop('disabled', true);

                // Build URL with current parameters
                const url = new URL(window.location);
                url.searchParams.set('days', $('#detailed-date-range').val());
                url.searchParams.set('sort_by', currentSortBy);
                url.searchParams.set('sort_order', currentSortOrder);

                // Add custom date parameters if selected
                if ($('#detailed-date-range').val() === 'custom') {
                    url.searchParams.set('date_from', $('#date-from').val());
                    url.searchParams.set('date_to', $('#date-to').val());
                }

                // Redirect to refresh the page
                window.location.href = url.toString();
            }

            // Initial load - get data from server-rendered content

            // Refresh button
            $('#refresh-detailed-analytics').click(function() {
                const button = $(this);
                const days = $('#detailed-date-range').val();

                // Validate custom date range if selected
                if (days === 'custom') {
                    const dateFrom = $('#date-from').val();
                    const dateTo = $('#date-to').val();

                    if (!dateFrom || !dateTo) {
                        alert('Please select both start and end dates.');
                        return;
                    }

                    if (new Date(dateFrom) > new Date(dateTo)) {
                        alert('Start date cannot be after end date.');
                        return;
                    }
                }

                // Show loading indicator
                button.find('.refresh-text').hide();
                button.find('.refresh-spinner').show();
                button.prop('disabled', true);

                loadDetailedAnalytics();
            });

            // Date range change
            $('#detailed-date-range').change(function() {
                const days = $(this).val();
                if (days === 'custom') {
                    $('#custom-date-range').show();
                } else {
                    $('#custom-date-range').hide();
                    const url = new URL(window.location);
                    url.searchParams.set('days', days);
                    window.history.pushState({}, '', url);
                    loadDetailedAnalytics();
                }
            });

            // Load more button
            $(document).on('click', '#load-more-customers', function() {
                const button = $(this);
                const currentLimit = parseInt('<?php echo $limit; ?>');
                const newLimit = currentLimit + 25;

                // Build URL with increased limit
                const url = new URL(window.location);
                url.searchParams.set('limit', newLimit);

                // Store scroll position in sessionStorage instead of URL hash
                const currentRowCount = button.closest('.load-more-container').prev('table').find('tbody tr').length;
                const targetRow = 'row-' + (currentRowCount + 1); // Point to the first new row
                sessionStorage.setItem('ewneater_scroll_to_row', targetRow);
                url.searchParams.set('load_more', '1');

                // Redirect to refresh the page with more data
                window.location.href = url.toString();
            });

            // Column sorting
            $(document).on('click', '.sortable-header', function() {
                const $this = $(this);
                const sortBy = $this.data('sort');

                // Toggle sort order if same column, otherwise default based on column type
                if (currentSortBy === sortBy) {
                    currentSortOrder = currentSortOrder === 'desc' ? 'asc' : 'desc';
                } else {
                    currentSortBy = sortBy;
                    // Default to DESC for numeric and date columns, ASC for text columns
                    const descColumns = ['total_revenue', 'total_orders', 'first_order_date', 'last_order_date'];
                    currentSortOrder = descColumns.includes(sortBy) ? 'desc' : 'asc';
                }

                // Show loading state
                $('#detailed-analytics-content').html('<div class="loading-indicator" style="text-align: center; padding: 40px;"><span class="spinner is-active"></span> Sorting data...</div>');

                // Build URL with new sort parameters
                const url = new URL(window.location);
                url.searchParams.set('sort_by', currentSortBy);
                url.searchParams.set('sort_order', currentSortOrder);

                // Redirect to refresh the page with new sort
                window.location.href = url.toString();
            });
        });
        </script>
        <?php
    }

    public static function handle_get_detailed_analytics()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_analytics_nonce", "security");

        $customer_type = sanitize_text_field($_POST["customer_type"]);
        $days = sanitize_text_field($_POST["days"]);

        // Determine customer types to include
        $types = [];
        if ($customer_type === "customers_guests") {
            $types = ["guest", "registered", "customer"];
        } elseif ($customer_type === "wholesale") {
            $types = ["wholesale"];
        }

        // Calculate date range
        $date_from = "";
        $date_to = "";

        if ($days === "custom") {
            // Use custom date range
            $date_from = isset($_POST["date_from"])
                ? sanitize_text_field($_POST["date_from"])
                : "";
            $date_to = isset($_POST["date_to"])
                ? sanitize_text_field($_POST["date_to"])
                : "";
        } elseif ($days !== "all" && $days > 0) {
            $date_from = date("Y-m-d", strtotime("-{$days} days"));
        }

        // Get pagination and sorting parameters
        $offset = isset($_POST["offset"]) ? intval($_POST["offset"]) : 0;
        $per_page = isset($_POST["per_page"]) ? intval($_POST["per_page"]) : 25;
        $sort_by = isset($_POST["sort_by"])
            ? sanitize_text_field($_POST["sort_by"])
            : "total_revenue";
        $sort_order = isset($_POST["sort_order"])
            ? sanitize_text_field($_POST["sort_order"])
            : "desc";

        // When sorting changes, reset offset to 0
        if ($offset > 0 && isset($_POST["sort_by"])) {
            $offset = 0;
        }

        // Get detailed analytics data
        $detailed_data = self::get_detailed_analytics_data(
            $types,
            $date_from,
            $date_to,
            $per_page,
            $sort_by,
            $sort_order
        );

        wp_send_json_success($detailed_data);
    }

    /*==========================================================================
     * CHECK NEW ORDERS HANDLER
     ==========================================================================*/
    public static function handle_check_new_orders()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_check_new_orders", "security");

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . "ewneater_customer_revenue";

            // Get all unique customer emails from orders
            $all_customer_emails = $wpdb->get_col("
                SELECT DISTINCT pm.meta_value
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_billing_email'
                AND pm.meta_value != ''
                AND p.post_type = 'shop_order'
                AND p.post_status NOT IN ('wc-cancelled', 'wc-failed', 'trash', 'draft')
            ");

            // Get existing customers in the index
            $indexed_emails = $wpdb->get_col(
                "SELECT DISTINCT email FROM {$table_name}"
            );

            // Find missing customers
            $missing_emails = array_diff($all_customer_emails, $indexed_emails);
            $missing_count = count($missing_emails);

            // Get current status for debugging
            $current_meta = get_option("ewneater_customer_revenue_meta", []);

            EWNeater_Customer_Revenue_Logger::info(
                "Check new orders requested",
                [
                    "total_customers" => count($all_customer_emails),
                    "indexed_customers" => count($indexed_emails),
                    "missing_customers" => $missing_count,
                    "current_status" => $current_meta["status"] ?? "unknown",
                    "processed_in_meta" =>
                        $current_meta["processed_customers"] ?? 0,
                    "total_in_meta" => $current_meta["total_customers"] ?? 0,
                ]
            );

            // Check if too many missing entries
            if ($missing_count > 100) {
                wp_send_json_success([
                    "too_many" => true,
                    "processed" => 0,
                    "status_updated" => false,
                    "message" => sprintf(
                        "Found %d customers not in the index. This is more than the recommended limit of 100.",
                        $missing_count
                    ),
                ]);
                return;
            }

            // Process missing customers
            $processed = 0;
            $batch_data = [];

            foreach ($missing_emails as $email) {
                $customer_data = EWNeater_Customer_Revenue_Core::calculate_customer_data(
                    $email
                );
                if ($customer_data) {
                    $batch_data[] = $customer_data;
                    $processed++;
                }
            }

            // Insert batch data if any
            if (!empty($batch_data)) {
                EWNeater_Customer_Revenue_Core::insert_batch_data($batch_data);
            }

            // Check if we should mark the index as complete
            $status_updated = false;
            $meta = get_option("ewneater_customer_revenue_meta", []);

            // Check if index should be marked complete
            $should_complete = false;

            if ($missing_count == 0) {
                // No missing customers, should be complete
                $should_complete = true;
            } elseif (
                $meta["status"] === "building" &&
                $processed >= $missing_count
            ) {
                // Successfully processed all missing customers
                $should_complete = true;
            } elseif (
                $meta["status"] === "building" &&
                isset($meta["processed_customers"]) &&
                isset($meta["total_customers"])
            ) {
                // Check if previously building index is actually complete
                if ($meta["processed_customers"] >= $meta["total_customers"]) {
                    $should_complete = true;
                }
            }

            if ($should_complete) {
                // All customers are now indexed, mark as complete
                $meta["status"] = "complete";
                $meta["last_updated"] = current_time("mysql");
                update_option("ewneater_customer_revenue_meta", $meta);
                $status_updated = true;

                EWNeater_Customer_Revenue_Logger::info(
                    "Index marked as complete after processing new orders",
                    [
                        "processed_customers" => $processed,
                        "total_missing" => $missing_count,
                        "previous_status" => $meta["status"] ?? "unknown",
                    ]
                );
            } else {
                // Update last_updated timestamp
                $meta["last_updated"] = current_time("mysql");
                update_option("ewneater_customer_revenue_meta", $meta);
            }

            $message = "";
            if ($missing_count == 0) {
                $message =
                    "No new customers found. All customers are already indexed.";
                if ($status_updated) {
                    $message .= " Index status updated to Complete.";
                }
            } else {
                $message = sprintf(
                    "Added %d new customers to the index.",
                    $processed
                );
                if ($status_updated) {
                    $message .= " Index is now complete!";
                }
            }

            // Add debug info to message for troubleshooting
            $debug_info = sprintf(
                " (Total: %d, Indexed: %d, Added: %d)",
                count($all_customer_emails),
                count($indexed_emails),
                $processed
            );
            $message .= $debug_info;

            wp_send_json_success([
                "too_many" => false,
                "processed" => $processed,
                "status_updated" => $status_updated,
                "message" => $message,
            ]);
        } catch (Exception $e) {
            EWNeater_Customer_Revenue_Logger::error("Check new orders failed", [
                "error" => $e->getMessage(),
            ]);
            wp_send_json_error(
                "Error checking for new orders: " . $e->getMessage()
            );
        }
    }

    /*==========================================================================
     * FORCE CONTINUE INDEX HANDLER
     ==========================================================================*/
    public static function handle_force_continue_index()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Insufficient permissions");
        }

        check_ajax_referer("ewneater_force_continue_index", "security");

        // Force continue the index build from where it left off
        $result = EWNeater_Customer_Revenue_Core::force_continue_index_build();

        if ($result["success"]) {
            wp_send_json_success($result["message"]);
        } else {
            wp_send_json_error($result["message"]);
        }
    }

    /*==========================================================================
     * ANALYTICS DATA FUNCTIONS
     ==========================================================================*/
    private static function get_analytics_data(
        $customer_types,
        $date_from = "",
        $date_to = "",
        $limit = 10
    ) {
        global $wpdb;
        $table_name =
            $wpdb->prefix . EWNeater_Customer_Revenue_Core::TABLE_NAME;

        // Build WHERE clause for customer filtering
        $where_conditions = [];
        $where_values = [];

        if (!empty($customer_types)) {
            $placeholders = implode(
                ",",
                array_fill(0, count($customer_types), "%s")
            );
            $where_conditions[] = "customer_type IN ($placeholders)";
            $where_values = array_merge($where_values, $customer_types);
        }

        if (!empty($date_from)) {
            $where_conditions[] = "last_order_date >= %s";
            $where_values[] = $date_from;
        }

        if (!empty($date_to)) {
            $where_conditions[] = "last_order_date <= %s";
            $where_values[] = $date_to;
        }

        $where_clause = !empty($where_conditions)
            ? "WHERE " . implode(" AND ", $where_conditions)
            : "";

        // Use a separate array for the count query (no LIMIT)
        $count_where_values = $where_values;

        // Get total count of customers in the timeframe (for display)
        $total_customers_query = "
            SELECT COUNT(*) as total_count
            FROM $table_name
            $where_clause
        ";
        $total_customers_result = $wpdb->get_row(
            $wpdb->prepare($total_customers_query, $count_where_values)
        );
        $total_customers_in_period = intval(
            $total_customers_result->total_count ?? 0
        );

        // Get all customers in the period (up to a reasonable max for performance)
        $customers_query = "
            SELECT customer_name, customer_email, customer_type, total_orders, total_revenue, last_order_date
            FROM $table_name
            $where_clause
            ORDER BY last_order_date DESC
            LIMIT %d
        ";
        $customer_where_values = $where_values;
        $max_customers = 500; // Reasonable max for performance
        $customer_where_values[] = $max_customers;

        $customers = $wpdb->get_results(
            $wpdb->prepare($customers_query, $customer_where_values)
        );

        // Calculate actual revenue within date range for these customers
        $processed_customers = [];
        $total_revenue_in_period = 0;
        $total_orders_in_period = 0;

        foreach ($customers as $customer) {
            // Calculate revenue and orders within the date range
            $period_data = self::calculate_customer_period_data(
                $customer->customer_email,
                $date_from,
                $date_to
            );

            $user_id = 0;
            if (
                $customer->customer_email &&
                $customer->customer_type !== "guest"
            ) {
                $user = get_user_by("email", $customer->customer_email);
                if ($user) {
                    $user_id = $user->ID;
                }
            }
            $processed_customers[] = [
                "name" => $customer->customer_name ?: "Guest Customer",
                "email" => $customer->customer_email,
                "type" => $customer->customer_type,
                "total_orders" => $period_data["orders"],
                "total_revenue" => number_format($period_data["revenue"], 2),
                "trend" => self::calculate_customer_trend(
                    $customer->customer_email,
                    $date_from,
                    $date_to
                ),
                "user_id" => $user_id,
                "period_revenue" => $period_data["revenue"], // for sorting
            ];

            $total_revenue_in_period += $period_data["revenue"];
            $total_orders_in_period += $period_data["orders"];
        }

        // Sort by period revenue descending
        usort($processed_customers, function ($a, $b) {
            return $b["period_revenue"] <=> $a["period_revenue"];
        });

        // Take top 10 for display
        $top_customers = array_slice($processed_customers, 0, $limit);

        // Generate chart data
        $chart_data = [];
        $chart_customers = array_slice($top_customers, 0, 5);
        foreach ($chart_customers as $customer) {
            $chart_data[] = [
                "label" =>
                    substr($customer["name"], 0, 10) .
                    (strlen($customer["name"]) > 10 ? "..." : ""),
                "value" => floatval(
                    str_replace(",", "", $customer["total_revenue"])
                ),
            ];
        }

        return [
            "customers" => $top_customers,
            "top_customers_count" => $total_customers_in_period, // Show total count, not just top 10
            "total_revenue" => number_format($total_revenue_in_period, 2),
            "avg_order_value" =>
                $total_orders_in_period > 0
                    ? number_format(
                        $total_revenue_in_period / $total_orders_in_period,
                        2
                    )
                    : "0.00",
            "chart_data" => $chart_data,
        ];
    }

    /**
     * Calculate customer revenue and orders within a specific date range
     */
    public static function calculate_customer_period_data(
        $customer_email,
        $date_from = "",
        $date_to = ""
    ) {
        global $wpdb;

        $where_conditions = [
            "pm.meta_key = '_billing_email'",
            "pm.meta_value = %s",
            "p.post_type = 'shop_order'",
            "p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')",
        ];
        $where_values = [$customer_email];

        if (!empty($date_from)) {
            $where_conditions[] = "p.post_date >= %s";
            $where_values[] = $date_from;
        }

        if (!empty($date_to)) {
            $where_conditions[] = "p.post_date <= %s";
            $where_values[] = $date_to . " 23:59:59";
        }

        $where_clause = implode(" AND ", $where_conditions);

        $query = "
            SELECT
                COUNT(DISTINCT p.ID) as order_count,
                SUM(pm_total.meta_value) as total_revenue
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
            WHERE $where_clause
        ";

        $result = $wpdb->get_row($wpdb->prepare($query, $where_values));

        return [
            "orders" => intval($result->order_count ?? 0),
            "revenue" => floatval($result->total_revenue ?? 0),
        ];
    }

    private static function get_detailed_analytics_data(
        $customer_types,
        $date_from = "",
        $date_to = "",
        $per_page = 25,
        $sort_by = "total_revenue",
        $sort_order = "desc"
    ) {
        global $wpdb;
        $table_name =
            $wpdb->prefix . EWNeater_Customer_Revenue_Core::TABLE_NAME;

        // Build WHERE clause for customer filtering
        $where_conditions = [];
        $where_values = [];

        if (!empty($customer_types)) {
            $placeholders = implode(
                ",",
                array_fill(0, count($customer_types), "%s")
            );
            $where_conditions[] = "customer_type IN ($placeholders)";
            $where_values = array_merge($where_values, $customer_types);
        }

        if (!empty($date_from)) {
            $where_conditions[] = "last_order_date >= %s";
            $where_values[] = $date_from;
        }

        if (!empty($date_to)) {
            $where_conditions[] = "last_order_date <= %s";
            $where_values[] = $date_to;
        }

        $where_clause = !empty($where_conditions)
            ? "WHERE " . implode(" AND ", $where_conditions)
            : "";

        // Get total count of customers in the timeframe
        $total_customers_query = "
            SELECT COUNT(*) as total_count
            FROM $table_name
            $where_clause
        ";
        $total_customers_result = $wpdb->get_row(
            $wpdb->prepare($total_customers_query, $where_values)
        );
        $total_customers_in_period = intval(
            $total_customers_result->total_count ?? 0
        );

        // Build ORDER BY clause
        $allowed_sort_columns = [
            "customer_name" => "customer_name",
            "customer_email" => "customer_email",
            "customer_type" => "customer_type",
            "total_orders" => "total_orders",
            "total_revenue" => "total_revenue",
            "first_order_date" => "first_order_date",
            "last_order_date" => "last_order_date",
        ];

        $sort_column = isset($allowed_sort_columns[$sort_by])
            ? $allowed_sort_columns[$sort_by]
            : "total_revenue";
        $sort_direction = strtoupper($sort_order) === "ASC" ? "ASC" : "DESC";

        // Get all customers for detailed view (we'll sort and paginate after period calculation)
        $query = "
            SELECT customer_name, customer_email, customer_type, total_orders, total_revenue,
                   first_order_date, last_order_date
            FROM $table_name
            $where_clause
        ";

        $customers = $wpdb->get_results($wpdb->prepare($query, $where_values));

        // Calculate period-specific data for each customer
        $processed_customers = [];
        $total_revenue_in_period = 0;
        $total_orders_in_period = 0;

        foreach ($customers as $customer) {
            $period_data = self::calculate_customer_period_data(
                $customer->customer_email,
                $date_from,
                $date_to
            );

            $user_id = 0;
            if (
                $customer->customer_email &&
                $customer->customer_type !== "guest"
            ) {
                $user = get_user_by("email", $customer->customer_email);
                if ($user) {
                    $user_id = $user->ID;
                }
            }
            $processed_customers[] = (object) [
                "customer_name" => $customer->customer_name,
                "customer_email" => $customer->customer_email,
                "customer_type" => $customer->customer_type,
                "total_orders" => $period_data["orders"],
                "total_revenue" => $period_data["revenue"],
                "first_order_date" => $customer->first_order_date,
                "last_order_date" => $customer->last_order_date,
                "user_id" => $user_id,
            ];

            $total_revenue_in_period += $period_data["revenue"];
            $total_orders_in_period += $period_data["orders"];
        }

        // Sort the processed customers by the actual period data
        usort($processed_customers, function ($a, $b) use (
            $sort_by,
            $sort_order
        ) {
            $value_a = null;
            $value_b = null;

            switch ($sort_by) {
                case "customer_name":
                    $value_a = strtolower($a->customer_name);
                    $value_b = strtolower($b->customer_name);
                    break;
                case "customer_email":
                    $value_a = strtolower($a->customer_email);
                    $value_b = strtolower($b->customer_email);
                    break;
                case "customer_type":
                    $value_a = $a->customer_type;
                    $value_b = $b->customer_type;
                    break;
                case "total_orders":
                    $value_a = $a->total_orders;
                    $value_b = $b->total_orders;
                    break;
                case "total_revenue":
                    $value_a = $a->total_revenue;
                    $value_b = $b->total_revenue;
                    break;
                case "first_order_date":
                    $value_a = strtotime($a->first_order_date);
                    $value_b = strtotime($b->first_order_date);
                    break;
                case "last_order_date":
                    $value_a = strtotime($a->last_order_date);
                    $value_b = strtotime($b->last_order_date);
                    break;
                default:
                    $value_a = $a->total_revenue;
                    $value_b = $b->total_revenue;
            }

            // Handle comparison
            if ($value_a == $value_b) {
                return 0;
            }

            if ($sort_order === "asc") {
                return $value_a < $value_b ? -1 : 1;
            } else {
                return $value_a > $value_b ? -1 : 1;
            }
        });

        // Apply pagination to sorted results
        $total_processed = count($processed_customers);
        $processed_customers = array_slice($processed_customers, 0, $per_page);
        $has_more = $per_page < $total_processed;

        // Get summary statistics for first load
        $summary = null;
        $avg_order_value =
            $total_orders_in_period > 0
                ? $total_revenue_in_period / $total_orders_in_period
                : 0;

        $summary = [
            "total_customers" => number_format($total_processed),
            "total_orders" => number_format($total_orders_in_period),
            "total_revenue" => number_format($total_revenue_in_period, 2),
            "avg_order_value" => number_format($avg_order_value, 2),
        ];

        // Build HTML for detailed view
        $html = '<div class="detailed-analytics">';
        $html .= "<h3>Top Customers</h3>";
        $html .=
            '<table class="wp-list-table widefat fixed striped sortable-table">';
        $html .= "<thead><tr>";
        $html .= '<th class="row-number-column">#</th>';
        $html .= '<th class="graph-column"></th>';
        $html .=
            '<th class="sortable-header ' .
            ($sort_by === "customer_name" ? "sorted-" . $sort_order : "") .
            '" data-sort="customer_name" title="Click to sort by customer name">Customer Name<span class="sort-indicator"></span></th>';
        $html .=
            '<th class="sortable-header ' .
            ($sort_by === "customer_email" ? "sorted-" . $sort_order : "") .
            '" data-sort="customer_email" title="Click to sort by email">Email<span class="sort-indicator"></span></th>';
        $html .=
            '<th class="sortable-header ' .
            ($sort_by === "customer_type" ? "sorted-" . $sort_order : "") .
            '" data-sort="customer_type" title="Click to sort by customer type">Type<span class="sort-indicator"></span></th>';
        $html .=
            '<th class="sortable-header ' .
            ($sort_by === "total_orders" ? "sorted-" . $sort_order : "") .
            '" data-sort="total_orders" title="Click to sort by order count">Orders<span class="sort-indicator"></span></th>';
        $html .=
            '<th class="sortable-header ' .
            ($sort_by === "total_revenue" ? "sorted-" . $sort_order : "") .
            '" data-sort="total_revenue" title="Click to sort by revenue">Revenue<span class="sort-indicator"></span></th>';
        $html .=
            '<th class="sortable-header ' .
            ($sort_by === "first_order_date" ? "sorted-" . $sort_order : "") .
            '" data-sort="first_order_date" title="Click to sort by first order date">First Order<span class="sort-indicator"></span></th>';
        $html .=
            '<th class="sortable-header ' .
            ($sort_by === "last_order_date" ? "sorted-" . $sort_order : "") .
            '" data-sort="last_order_date" title="Click to sort by last order date">Last Order<span class="sort-indicator"></span></th>';
        $html .= "</tr></thead><tbody>";

        foreach ($processed_customers as $index => $customer) {
            $row_id = 'id="row-' . ($index + 1) . '"';
            $html .= str_replace(
                "<tr>",
                "<tr " . $row_id . ">",
                self::build_customer_row($customer, $index + 1)
            );
        }

        $html .= "</tbody></table>";

        if ($has_more) {
            $html .=
                '<div class="load-more-container" style="text-align: center; margin-top: 20px;">';
            $html .=
                '<button type="button" class="button button-secondary" id="load-more-customers">Load More Customers</button>';
            $html .= "</div>";
        }

        $html .= "</div>";

        return [
            "html" => $html,
            "has_more" => $has_more,
            "summary" => $summary,
        ];
    }

    private static function build_customer_row($customer, $row_number = null)
    {
        $type_class = "customer-type-" . $customer->customer_type;
        $html = "<tr>";

        // Row number column
        if ($row_number !== null) {
            $html .=
                '<td class="row-number-column">' .
                intval($row_number) .
                "</td>";
        }

        // Graph icon column
        $graph_icon = self::get_customer_graph_icon($customer->total_revenue);
        $html .= '<td class="graph-column">' . $graph_icon . "</td>";

        // Build customer name with profile link using user_id if present
        $customer_name = $customer->customer_name ?: "Guest Customer";
        $profile_link = "";

        if (isset($customer->user_id) && $customer->user_id > 0) {
            $profile_url = admin_url(
                "user-edit.php?user_id=" . $customer->user_id
            );
            $profile_link =
                '<a href="' .
                esc_url($profile_url) .
                '" class="customer-profile-link" target="_blank">' .
                esc_html($customer_name) .
                "</a>";
        } else {
            $profile_link = "<strong>" . esc_html($customer_name) . "</strong>";
        }

        $html .= "<td>" . $profile_link . "</td>";
        $html .= "<td>" . esc_html($customer->customer_email) . "</td>";
        $html .=
            '<td><span class="customer-type-badge ' .
            esc_attr($type_class) .
            '">' .
            esc_html(ucfirst($customer->customer_type)) .
            "</span></td>";
        $html .= "<td>" . esc_html($customer->total_orders) . "</td>";
        $html .=
            '<td><strong>$' .
            esc_html(number_format($customer->total_revenue, 2)) .
            "</strong></td>";
        $html .=
            "<td>" .
            esc_html(
                $customer->first_order_date
                    ? date("M j, Y", strtotime($customer->first_order_date))
                    : "N/A"
            ) .
            "</td>";
        $html .=
            "<td>" .
            esc_html(
                $customer->last_order_date
                    ? date("M j, Y", strtotime($customer->last_order_date))
                    : "N/A"
            ) .
            "</td>";
        $html .= "</tr>";

        // Validate HTML structure before returning
        if (
            substr_count($html, "<tr>") !== 1 ||
            substr_count($html, "</tr>") !== 1
        ) {
            error_log("Invalid TR structure in build_customer_row: " . $html);
            return "<tr><td colspan='9'>Error: Invalid row data</td></tr>";
        }

        return $html;
    }

    private static function get_customer_graph_icon($revenue)
    {
        if ($revenue >= 5000) {
            return "📈"; // High revenue
        } elseif ($revenue >= 1000) {
            return "📊"; // Medium revenue
        } elseif ($revenue >= 100) {
            return "📉"; // Low revenue
        } else {
            return "⚫"; // Minimal revenue
        }
    }

    private static function calculate_customer_trend(
        $email,
        $date_from,
        $date_to = ""
    ) {
        // Simple trend calculation - compare recent vs older performance
        // This is a basic implementation - can be enhanced with more sophisticated analysis
        global $wpdb;

        if (empty($date_from)) {
            return "trend-stable";
        }

        // Get recent orders vs older orders for trend analysis
        $recent_date = date("Y-m-d", strtotime("-15 days"));

        $recent_revenue = $wpdb->get_var(
            $wpdb->prepare(
                "
            SELECT SUM(pm.meta_value)
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
            WHERE pm.meta_key = '_order_total'
            AND pm2.meta_key = '_billing_email'
            AND pm2.meta_value = %s
            AND p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s" .
                    (!empty($date_to)
                        ? " AND p.post_date <= '" .
                            esc_sql($date_to . " 23:59:59") .
                            "'"
                        : "") .
                    "
        ",
                $email,
                $recent_date
            )
        );

        $older_revenue = $wpdb->get_var(
            $wpdb->prepare(
                "
            SELECT SUM(pm.meta_value)
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
            WHERE pm.meta_key = '_order_total'
            AND pm2.meta_key = '_billing_email'
            AND pm2.meta_value = %s
            AND p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date < %s
            AND p.post_date >= %s" .
                    (!empty($date_to)
                        ? " AND p.post_date <= '" .
                            esc_sql($date_to . " 23:59:59") .
                            "'"
                        : "") .
                    "
        ",
                $email,
                $recent_date,
                $date_from
            )
        );

        $recent_revenue = floatval($recent_revenue);
        $older_revenue = floatval($older_revenue);

        if ($recent_revenue > $older_revenue * 1.2) {
            return "trend-up";
        } elseif ($recent_revenue < $older_revenue * 0.8) {
            return "trend-down";
        } else {
            return "trend-stable";
        }
    }

    /*==========================================================================
     * SCRIPT ENQUEUING
     ==========================================================================*/
    public static function enqueue_scripts($hook)
    {
        if (
            strpos($hook, "ewneater-customer-revenue") === false &&
            strpos($hook, "ewneater-detailed-analytics") === false
        ) {
            return;
        }
        wp_enqueue_script("jquery");
        wp_enqueue_style(
            "ewneater-customer-revenue-admin",
            plugins_url("../css/customer-revenue-admin.css", __FILE__),
            [],
            filemtime(dirname(__DIR__) . "/css/customer-revenue-admin.css")
        );
    }
}

/*==========================================================================
 * CLASS INITIALIZATION
 ==========================================================================*/
EWNeater_Customer_Revenue::init();
