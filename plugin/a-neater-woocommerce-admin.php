<?php
/*
 * Plugin Name:       A Neater Woocommerce Admin
 * Plugin URI:        https://www.evolvedwebsites.com.au/plugins/neater-wp/
 * Description:       Modernise the WooCommerce admin with a clean interface, smart customer insights, lightning-fast order search, and manual On Sale category control
 * Version:           12.4.6
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Evolved Websites Pty Ltd
 * Author URI:        https://www.evolvedwebsites.com.au/plugins/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://www.evolvedwebsites.com.au/plugins/neater-wp/
 * Text Domain:       a-neater-woocommerce-admin
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * Bootstrap: helpers, Quick Find, Customer Revenue, On Sale Manager; admin menu,
 * order columns, asset enqueue. CSS/JS in css/ and js/; conditional loading by screen.
 */

// Prevent direct file access
if (!defined("ABSPATH")) {
    exit();
}

// Define plugin version constant
if (!defined("EWNEATER_VERSION")) {
    define("EWNEATER_VERSION", "12.4.6");
}

// Define Quick Find version constant (kept in sync with plugin version)
if (!defined("EWNEATER_QUICKFIND_VERSION")) {
    define("EWNEATER_QUICKFIND_VERSION", EWNEATER_VERSION);
}

/**
 * ADMIN BAR TOOLBAR ALLOWLIST
 * Hide all root-default items by default; show only allowlisted ones.
 * Preferences come from Toolbar Icons settings page (ewneater_get_toolbar_visible_ids).
 */
function ewneater_admin_bar_allowlist_css()
{
    if (!is_admin() && !is_admin_bar_showing()) {
        return;
    }
    $visible = ewneater_get_toolbar_visible_ids();
    $desktop_ids = $visible['desktop'];
    $mobile_ids = $visible['mobile'];

    $desktop_selectors = [];
    foreach ($desktop_ids as $id) {
        $desktop_selectors[] = '#wp-admin-bar-root-default>#wp-admin-bar-' . $id;
    }
    $desktop_css = !empty($desktop_selectors)
        ? implode(',', $desktop_selectors) . '{display:block!important}'
        : '';

    $mobile_hide = [];
    foreach (array_diff($desktop_ids, $mobile_ids) as $id) {
        $mobile_hide[] = '#wp-admin-bar-root-default>#wp-admin-bar-' . $id;
    }
    $mobile_css = !empty($mobile_hide)
        ? '@media screen and (max-width:782px){' . implode(',', $mobile_hide) . '{display:none!important}}'
        : '';

    echo '<style id="ewneater-admin-bar-allowlist">' .
        '#wp-admin-bar-root-default > li{display:none!important}' .
        $desktop_css .
        $mobile_css .
        '</style>';
}
add_action("admin_head", "ewneater_admin_bar_allowlist_css");

/**
 *
 * MAIN FUNCTIONALITY:
 *
 * 1. ADMIN INTERFACE CLEANUP
 *    - Hides unnecessary user profile fields
 *    - Streamlines the admin interface for WooCommerce
 *    - Customizes order table appearance and styling
 *
 * 2. ENHANCED ORDER MANAGEMENT
 *    - Adds custom "Tags" column showing customer status (Guest, New, Returning, Wholesale)
 *    - Adds "Cust. Orders" column showing total order count with dynamic font sizing
 *    - Adds "Cust. Revenue" column (hidden by default) showing total customer spend
 *    - Provides quick access to customer order history and revenue metrics
 *
 * 3. CUSTOM ADMIN MENU INTEGRATION
 *    - Adds "A Neater Admin" menu to admin sidebar
 *    - Provides quick access to settings and dashboard
 *
 * 4. REVIEWS MANAGEMENT
 *    - Adds dedicated "Reviews" menu item in admin sidebar
 *    - Shows notification bubbles for pending product reviews
 *    - Streamlines review management workflow
 *    - Integrates with WooCommerce product review system
 *
 * 5. QUICK FIND SEARCH
 *    - Provides instant search for orders and customers from any admin page
 *    - Press '/' to activate search from anywhere in the admin
 *    - Shows up to 15 orders and 3 customers in search results
 *    - Displays order status, date, total, and location at a glance
 *    - Updates search index hourly for optimal performance
 *    - Features keyboard navigation and result highlighting
 *    - Mobile-responsive design with touch-friendly interface
 *
 * 6. ON SALE MANAGER
 *    - Manual approval workflow for the Sale product category
 *    - Lists all products with a sale price set (all statuses)
 *    - Checkbox approval per product; approved + on sale = in Sale category
 *    - Auto-syncs on product save and variation save
 *    - Re-sync All button for bulk operations (imports, restores, first setup)
 *    - Sale category created automatically if missing
 */

/**
 * ADMIN MENU CUSTOMISATION
 * Adds "A Neater Admin" to the sidebar with subpages for dashboard, Quick Find, Customer Revenue, On Sale Manager, and Reviews.
 */

// Include helper functions (pending reviews cache, company email, wholesale detection)
require_once plugin_dir_path(__FILE__) . "includes/helpers.php";

add_action("transition_comment_status", "ewneater_clear_pending_reviews_cache", 10, 0);

// Include core QuickFind functionality that needs to run regardless of admin status
require_once plugin_dir_path(__FILE__) . "includes/quickfind-core.php";

// Include QuickFind hooks for frontend order processing
require_once plugin_dir_path(__FILE__) . "includes/quickfind-hooks.php";

// Include core Customer Revenue functionality that needs to run regardless of admin status
require_once plugin_dir_path(__FILE__) . "includes/customer-revenue-core.php";

// Include Customer Revenue hooks for frontend order processing
require_once plugin_dir_path(__FILE__) . "includes/customer-revenue-hooks.php";

// Include Customer Revenue logger
require_once plugin_dir_path(__FILE__) . "includes/customer-revenue-logger.php";

// Include Customer Revenue circuit breaker for error protection
require_once plugin_dir_path(__FILE__) .
    "includes/customer-revenue-circuit-breaker.php";

// Include Toolbar Icons settings (needed for ewneater_get_toolbar_visible_ids on admin + frontend)
require_once plugin_dir_path(__FILE__) . "includes/toolbar-icons.php";

// Ensure these plugin functions only run in the WordPress admin area
if (is_admin()) {
    // Include the All Orders functionality
    require_once plugin_dir_path(__FILE__) . "includes/orders_edit.php";
    require_once plugin_dir_path(__FILE__) . "includes/orders-list-ui.php";
    require_once plugin_dir_path(__FILE__) . "includes/reviews-list-ui.php";
    require_once plugin_dir_path(__FILE__) . "includes/users-list-ui.php";
    require_once plugin_dir_path(__FILE__) . "includes/order-edit-header.php";

    // Include the Past Orders functionality
    require_once plugin_dir_path(__FILE__) . "includes/past-orders.php";

    // Include the Top Purchased Items functionality
    require_once plugin_dir_path(__FILE__) . "includes/top-purchased-items.php";

    // Include the Quick Find customers and orders admin functionality
    require_once plugin_dir_path(__FILE__) . "includes/quickfind.php";

    // Include the Top Purchased Items Users functionality
    require_once plugin_dir_path(__FILE__) .
        "includes/top-purchased-items-users.php";

    // Include the Customer Revenue admin functionality
    require_once plugin_dir_path(__FILE__) . "includes/customer-revenue.php";

    // Ensure customer revenue logger is available in admin
    if (!class_exists("EWNeater_Customer_Revenue_Logger")) {
        require_once plugin_dir_path(__FILE__) .
            "includes/customer-revenue-logger.php";
    }

    // Include the Admin Toggler Module
    require_once plugin_dir_path(__FILE__) .
        "includes/admin-toggler-module.php";

    // Include the Dashboard page
    require_once plugin_dir_path(__FILE__) . "includes/dashboard.php";

    // Include the On Sale Manager
    require_once plugin_dir_path(__FILE__) . "includes/on-sale-manager.php";

    // Add 'A Neater Admin' Sidebar Menu with Subpages
    function ewneater_admin_menu()
    {
        // Add the top-level menu with list-view icon (or use URL to custom image, e.g. plugin_dir_url(__FILE__) . 'assets/icon.svg')
        add_menu_page(
            "A Neater Admin", // Page title
            "A Neater Admin", // Menu title
            "manage_options", // Capability
            "ewneater-admin", // Menu slug
            "", // Function to display the top-level page (if needed)
            "dashicons-yes-alt", // Icon for the top-level menu
            2 // Position in the menu order
        );

        // Add Dashboard as first submenu (same slug as parent so parent link opens dashboard)
        add_submenu_page(
            "ewneater-admin",
            __("Dashboard", "a-neater-woocommerce-admin"),
            __("Dashboard", "a-neater-woocommerce-admin"),
            "manage_options",
            "ewneater-admin",
            "ewneater_display_dashboard_page"
        );

        // Add submenu for search index management
        add_submenu_page(
            "ewneater-admin", // Parent slug
            __("Quick Find Index", "a-neater-woocommerce-admin"), // Page title
            __("Quick Find Index", "a-neater-woocommerce-admin"), // Menu title
            "manage_options", // Capability
            "ewneater-search-index", // Menu slug
            ["EWNeater_Quick_Find", "display_search_index_page"] // Function to display the page content
        );

        // Add submenu for Quick Find admin menus
        add_submenu_page(
            "ewneater-admin", // Parent slug
            __("Quick Find Menus", "a-neater-woocommerce-admin"), // Page title
            __("Quick Find Menus", "a-neater-woocommerce-admin"), // Menu title
            "manage_options", // Capability
            "ewneater-quick-find-menus", // Menu slug
            ["EWNeater_Quick_Find", "display_admin_menus_visibility_page"] // Function to display the page content
        );

        add_submenu_page(
            "ewneater-admin",
            __("Toolbar Icons", "a-neater-woocommerce-admin"),
            __("Toolbar Icons", "a-neater-woocommerce-admin"),
            "manage_options",
            "ewneater-toolbar-icons",
            "ewneater_toolbar_display_page"
        );

        // Add submenu for customer revenue
        $customer_revenue_hook = add_submenu_page(
            "ewneater-admin", // Parent slug
            __("Customer Revenue", "a-neater-woocommerce-admin"), // Page title
            __("Customer Revenue", "a-neater-woocommerce-admin"), // Menu title
            "manage_options", // Capability
            "ewneater-customer-revenue", // Menu slug
            "ewneater_display_customer_revenue_page" // Function to display the page content
        );

        // Add submenu for On Sale Manager
        add_submenu_page(
            "ewneater-admin",
            __("On Sale Manager", "a-neater-woocommerce-admin"),
            __("On Sale Manager", "a-neater-woocommerce-admin"),
            "manage_options",
            "ewneater-on-sale-manager",
            "bsf_on_sale_manager_page"
        );

        // Add submenu for Reviews (WooCommerce product reviews; redirects to that page)
        $pending_reviews_count = ewneater_get_pending_reviews_count();
        $reviews_menu_title = __("Reviews", "a-neater-woocommerce-admin");
        if ($pending_reviews_count > 0) {
            $reviews_menu_title .=
                ' <span class="awaiting-mod">' .
                number_format_i18n($pending_reviews_count) .
                "</span>";
        }
        add_submenu_page(
            "ewneater-admin",
            __("Reviews", "a-neater-woocommerce-admin"),
            $reviews_menu_title,
            "manage_woocommerce",
            "ewneater-reviews",
            "ewneater_reviews_redirect"
        );
    }
    add_action("admin_menu", "ewneater_admin_menu");

    /**
     * Redirect to WooCommerce product reviews page (used by Reviews submenu).
     */
    function ewneater_reviews_redirect()
    {
        wp_safe_redirect(admin_url("edit.php?post_type=product&page=product-reviews"));
        exit;
    }

    /**
     * Output breadcrumb for A Neater Admin submenu pages: "[icon] A Neater Admin » [title]" with link to Dashboard
     *
     * @param string $current_title The submenu page title to show after the separator
     */
    function ewneater_admin_breadcrumb($current_title)
    {
        $url = admin_url("admin.php?page=ewneater-admin");
        $label = __("A Neater Admin", "a-neater-woocommerce-admin");
        $icon = '<span class="dashicons dashicons-yes-alt ewneater-title-icon" aria-hidden="true"></span>';
        echo $icon . ' <a href="' . esc_url($url) . '">' . esc_html($label) . '</a> &raquo; ' . esc_html($current_title);
    }

    /**
     * Shared styles for A Neater Admin pages – now loaded from css/admin-dashboard.css
     */
    function ewneater_admin_page_styles()
    {
    }

    /**
     * TEMPLATE REDIRECT
     * Override the default customer-history.php template with a custom template.
     */
    add_filter(
        "wc_get_template",
        function ($template, $template_name, $args) {
            if ($template_name === "order/customer-history.php") {
                return plugin_dir_path(__FILE__) .
                    "woocommerce/templates/order/customer-history.php";
            }

            return $template;
        },
        10,
        3
    );

    /**
     * ADMIN HEAD STYLES
     * Add custom CSS styles to hide unnecessary user fields and adjust order table appearance in the WooCommerce admin area.
     */
    function a_neater_woocommerce_admin_head()
    {
        echo '
		<style>
		/*  Hide unnecessary User fields */

		/* Visual Editor */
		#your-profile .user-rich-editing-wrap,
		/* Admin Color Scheme */
		#your-profile .user-admin-color-wrap,
		/* Keyboard Shortcuts */
		#your-profile .user-comment-shortcuts-wrap,
		/* Application Passwords */
		#your-profile #application-passwords-section,
		/* Elementor Notes */
		#your-profile #e-notes,
		#your-profile #e-notes + table.form-table,
		.user-syntax-highlighting-wrap,
		.show-admin-bar,
		.user-language-wrap,
		.user-nickname-wrap,
		.user-display-name-wrap,
		.user-url-wrap,
		.user-description-wrap,
		.yoast-settings,
		#wc_payment_gateway_square_user_settings
		{
			display: none;
		}

		/* Order Styling */
		#past_orders ul li {
			margin: 0;
			padding: 0.3em;
		}
		#past_orders ul li.active {
			background-color: #CAF0F8;
		}

		#order_data p.order_number,
		.ewneater-order-number-box {
			float: left;
			margin-right: 10px;
		}

		/* Order status colours (additional) – main scheme in css/orders-admin.css */

		/* Past Orders - Status Colors */
		#past_orders .order-status,
		#past_orders [class*="order-status-"] {
			font-size: 0.9em;
			padding: 2px 3px;
			border-radius: 3px;
		}

		#past_orders .order-status.status-pending,
		#past_orders .order-status-pending {
			background-color: #f8dda7;
			color: #94660c;
		}
		#past_orders .order-status.status-processing,
		#past_orders .order-status-processing {
			background-color: #c6e1c6;
			color: #5b841b;
		}
		#past_orders .order-status.status-on-hold,
		#past_orders .order-status-on-hold {
			background-color: #f8dda7;
			color: #94660c;
		}
		#past_orders .order-status.status-completed,
		#past_orders .order-status-completed {
			background-color: #c8d7e1;
			color: #2e4453;
		}
		#past_orders .order-status.status-cancelled,
		#past_orders .order-status-cancelled {
			background-color: #eba3a3;
			color: #761919;
		}
		#past_orders .order-status.status-refunded,
		#past_orders .order-status-refunded {
			background-color: #e5e5e5;
			color: #777;
		}
		#past_orders .order-status.status-failed,
		#past_orders .order-status-failed {
			background-color: #eba3a3;
			color: #761919;
		}
		#past_orders .order-status.status-draft,
		#past_orders .order-status-draft,
		#past_orders .order-status.status-checkout-draft,
		#past_orders .order-status-checkout-draft {
			background-color: #e5e5e5;
			color: #777;
		}
		#past_orders .order-status.status-wholesale,
		#past_orders .order-status-wholesale {
			background-color: #fff3cd;
			color: #856404;
		}
		#past_orders .order-status-private {
			background-color: #e5e5e5;
			color: #777;
		}

		/* Adjust the width of the Tags column */
		.post-type-shop_order .wp-list-table th.column-ewneater_custom_column {
			width: 16ch;
		}

		/* Adjust the width of the Status column */
		.post-type-shop_order .wp-list-table .column-order_status {
			width: 10ch;
		}

		/* Adjust the width of the Total column */
		.post-type-shop_order .wp-list-table .column-order_total {
			width: 6ch;
		}
		.post-type-shop_order .wp-list-table .column-order_total .tips del,
        .post-type-shop_order .wp-list-table .column-order_total .woocommerce-Price-amount.amount {
			white-space: nowrap;
		}

		/* Adjust the width of the Order Number column */
		.post-type-shop_order .wp-list-table .column-order_number {
			width: 22ch;
		}

		/* Adjust the width of the Login As Customer column */
		.post-type-shop_order .wp-list-table .column-login_as_customer {
			width: 5ch;
		}

		/* Adjust the width of the Ship To column */
		.post-type-shop_order .wp-list-table .column-shipping_address {
			width: 19ch;
		}
		</style>';
    }
    add_action("admin_head", "a_neater_woocommerce_admin_head");

    // ================================
    // EDIT / SINGLE ORDER SECTION
    // ================================
    // Order Data h2: output customer + total (order-edit-heading.js appends to h2)
    add_action(
        "woocommerce_admin_order_data_after_order_details",
        function ($order) {
            // Get the customer's full name using proper getter method
            $customer_full_name = $order->get_formatted_billing_full_name();

            // Get net payment (total after refunds) instead of original total
            $total = $order->get_total() - $order->get_total_refunded();

            // Data for order-edit-heading.js (appends customer + total to Order Data h2)
            echo '<div id="order-total-container" data-order-total="' .
                esc_attr(wc_price($total)) .
                '" data-raw-total="' .
                esc_attr($total) .
                '" data-customer-name="' .
                esc_attr($customer_full_name) .
                '"></div>';
        },
        20
    );

    // Product Edit screen enhancements
    require_once plugin_dir_path(__FILE__) . "includes/product_edit.php";

    // Add a custom column "Tags" to the WooCommerce orders list table.
    add_filter("manage_edit-shop_order_columns", function ($columns) {
        $new_columns = [];
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key == "order_status") {
                $new_columns["ewneater_custom_column"] = array(
                    "title" => "Tags"
                );
            }
        }
        return $new_columns;
    });


    // Display custom "Tags" such as Guest, New Customer etc in the column of the orders list table.
    add_action(
        "manage_shop_order_posts_custom_column",
        function ($column, $post_id) {
            if ($column == "ewneater_custom_column") {
                $order = wc_get_order($post_id);
                $user = $order->get_user();

                $billing_email = $order->get_billing_email();

                // Black Sheep Farm Oils indicator - check for company email
                $is_blacksheep_order = ewneater_is_company_email($billing_email);
                if ($is_blacksheep_order) {
                    echo '<mark class="order-status guest ewneater-order-number-box">';
                    echo "<span>🐑 Black Sheep</span>";
                    echo "</mark>";
                }

                if (!$is_blacksheep_order && $billing_email) {
                    $order_status = $order ? $order->get_status() : "";
                    if (
                        in_array($order_status, [
                            "failed",
                            "cancelled",
                            "trash",
                            "refunded",
                            "pending",
                        ])
                    ) {
                        // For failed/cancelled/etc, show N/A or 0, skip all lookups/logs
                        $order_count = 0;
                        echo __("N/A", "a-neater-woocommerce-admin");
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

                        if (is_array($order_count) && count($order_count) > 1) {
                            echo '<mark class="order-status returning_customer ewneater-order-number-box">';
                            echo "<span> Return Customer </span>";
                            echo "</mark>";
                        } elseif (
                            is_array($order_count) &&
                            count($order_count) === 1
                        ) {
                            echo '<mark class="order-status new_customer ewneater-order-number-box">';
                            echo "<span> New Customer </span>";
                            echo "</mark>";
                        } else {
                            echo __("N/A", "a-neater-woocommerce-admin");
                        }
                    }
                } elseif (!$is_blacksheep_order) {
                    echo __("N/A", "a-neater-woocommerce-admin");
                }

                // Use enhanced customer detection logic
                if (
                    !$is_blacksheep_order &&
                    class_exists("EWNeater_Customer_Revenue_Core")
                ) {
                    $customer_data = EWNeater_Customer_Revenue_Core::calculate_customer_data(
                        $billing_email
                    );
                    if ($customer_data) {
                        $customer_type = $customer_data["customer_type"];

                        if ($customer_type === "wholesale") {
                            echo '<mark class="order-status status-on-hold ewneater-order-number-box">';
                            echo "<span> Wholesale </span>";
                            echo "</mark>";
                        } elseif ($customer_type === "registered") {
                            echo '<mark class="order-status status-processing ewneater-order-number-box">';
                            echo "<span> Customer </span>";
                            echo "</mark>";
                        } elseif ($customer_type === "guest") {
                            echo '<mark class="order-status guest ewneater-order-number-box">';
                            echo "<span> Guest </span>";
                            echo "</mark>";
                        }
                    } else {
                        // Fallback to old logic if customer data not available
                        if ($order->get_customer_id() == 0) {
                            echo '<mark class="order-status guest ewneater-order-number-box">';
                            echo "<span> Guest </span>";
                            echo "</mark>";
                        } elseif (
                            $user &&
                            ewneater_is_wholesale_user($user)
                        ) {
                            echo '<mark class="order-status status-on-hold ewneater-order-number-box">';
                            echo "<span> Wholesale </span>";
                            echo "</mark>";
                        }
                    }
                } else {
                    // Fallback to old logic if core class not available
                    if ($order->get_customer_id() == 0) {
                        echo '<mark class="order-status guest ewneater-order-number-box">';
                        echo "<span> Guest </span>";
                        echo "</mark>";
                    }
                    if (
                        $user &&
                        ewneater_is_wholesale_user($user)
                    ) {
                        echo '<mark class="order-status status-on-hold ewneater-order-number-box">';
                        echo "<span> Wholesale </span>";
                        echo "</mark>";
                    }
                }

                if (
                    !$is_blacksheep_order &&
                    is_object($user) &&
                    esc_attr(
                        get_the_author_meta("wholesaler_postpay", $user->ID)
                    ) == "yes"
                ) {
                    echo '<mark class="order-status status-cancelled ewneater-order-number-box">';
                    echo "<span> 30 day account </span>";
                    echo "</mark>";
                }
            }
        },
        25,
        2
    );

    // Display custom "Tags" such as Guest, New Customer etc on order details page
    add_action(
        "woocommerce_admin_order_data_after_payment_info",
        function ($order) {
            $user = $order->get_user();
            $billing_email = $order->get_billing_email();

            // Black Sheep Farm Oils indicator - check for company email
            $is_blacksheep_order = ewneater_is_company_email($billing_email);
            if ($is_blacksheep_order) {
                echo '<mark class="order-status guest ewneater-order-number-box">';
                echo "<span>🐑 Black Sheep Customer</span>";
                echo "</mark>";
            }

            if (!$is_blacksheep_order && $billing_email) {
                $order_status = $order ? $order->get_status() : "";
                if (
                    in_array($order_status, [
                        "failed",
                        "cancelled",
                        "trash",
                        "refunded",
                        "pending",
                    ])
                ) {
                    // For failed/cancelled/etc, show N/A or 0, skip all lookups/logs
                    $order_count = 0;
                    echo __("N/A", "a-neater-woocommerce-admin");
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
                    if (count($order_count) > 1) {
                        echo '<mark class="order-status returning_customer ewneater-order-number-box">';
                        echo "<span> Return Customer </span>";
                        echo "</mark>";
                    } else {
                        echo '<mark class="order-status new_customer ewneater-order-number-box">';
                        echo "<span> New Customer </span>";
                        echo "</mark>";
                    }
                }
            }
            // Use enhanced customer detection logic for order details page
            if (
                !$is_blacksheep_order &&
                class_exists("EWNeater_Customer_Revenue_Core")
            ) {
                $customer_data = EWNeater_Customer_Revenue_Core::calculate_customer_data(
                    $billing_email
                );
                if ($customer_data) {
                    $customer_type = $customer_data["customer_type"];

                    if ($customer_type === "wholesale") {
                        echo '<mark class="order-status status-on-hold ewneater-order-number-box">';
                        echo "<span> Wholesale </span>";
                        echo "</mark>";
                    } elseif ($customer_type === "registered") {
                        echo '<mark class="order-status status-processing ewneater-order-number-box">';
                        echo "<span> Customer </span>";
                        echo "</mark>";
                    } elseif ($customer_type === "guest") {
                        echo '<mark class="order-status guest ewneater-order-number-box">';
                        echo "<span> Guest </span>";
                        echo "</mark>";
                    }
                } else {
                    // Fallback to old logic if customer data not available
                    if (
                        is_object($user) &&
                        isset($user->roles) &&
                        ewneater_is_wholesale_user($user)
                    ) {
                        echo '<mark class="order-status status-on-hold ewneater-order-number-box">';
                        echo "<span> Wholesale </span>";
                        echo "</mark>";
                    } elseif (
                        is_object($order) &&
                        $order->get_customer_id() == 0
                    ) {
                        echo '<mark class="order-status guest ewneater-order-number-box">';
                        echo "<span> Guest </span>";
                        echo "</mark>";
                    }
                }
            } else {
                // Fallback to old logic if core class not available
                if (
                    is_object($user) &&
                    isset($user->roles) &&
                    ewneater_is_wholesale_user($user)
                ) {
                    echo '<mark class="order-status status-on-hold ewneater-order-number-box">';
                    echo "<span> Wholesale </span>";
                    echo "</mark>";
                }
                if (is_object($order) && $order->get_customer_id() == 0) {
                    echo '<mark class="order-status guest ewneater-order-number-box">';
                    echo "<span> Guest </span>";
                    echo "</mark>";
                }
            }

            // Keep the 30 day account logic separate as it's different
            if (
                !$is_blacksheep_order &&
                $user &&
                esc_attr(
                    get_the_author_meta("wholesaler_postpay", $user->ID)
                ) == "yes"
            ) {
                echo '<mark class="order-status ewneater-order-number-box status-cancelled">';
                echo "<span> 30 day account </span>";
                echo "</mark>";
            }
        },
        10,
        1
    );

    // Add Black Sheep indicator and explanatory text under billing email field
    add_action(
        "woocommerce_admin_order_data_after_billing_address",
        function ($order) {
            $billing_email = $order->get_billing_email();
            $is_company_email = ewneater_is_company_email($billing_email);

            if ($is_company_email) {
                echo '<div class="ewneater-customer-info-box">';
                echo '<p class="ewneater-customer-info-text">🐑 <em>Customer does not have their own email, purchased using Black Sheep Farm account</em></p>';
                echo "</div>";
            }
        },
        10,
        1
    );

    /*==========================================================================
     * ALL ORDERS
     *
     * Enhances the WooCommerce orders list by:
     * - Adding "# Orders" and "Revenue" columns
     * - Adjusting font size dynamically based on order counts
     * - Hiding the "Revenue" column by default for all users
     ==========================================================================*/

    // Add "# Orders", "Revenue", and "Payment Method" columns to WooCommerce orders list.
    add_filter("manage_edit-shop_order_columns", function ($columns) {
        // Remove default columns we want to reorder
        unset($columns["customer_orders_count"]);
        unset($columns["customer_revenue"]);
        unset($columns["payment_method"]);
        unset($columns["ewneater_custom_column"]);

        // Re-add the columns in the desired order
        $new_columns = [];
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === "order_status") {
                $new_columns["ewneater_custom_column"] = "Tags";
            }
            if ($key === "order_total") {
                $new_columns["customer_orders_count"] = "Cust. Orders";
                $new_columns["customer_revenue"] = "Cust. Revenue";
                $new_columns["payment_method"] = "Pay";
            }
        }
        return $new_columns;
    });

    // Hide the Revenue and Payment Method columns by default for all users.
    add_filter(
        "default_hidden_columns",
        function ($hidden, $screen) {
            if ($screen->id === "edit-shop_order") {
                $hidden[] = "customer_revenue";
                $hidden[] = "payment_method";
            }
            return $hidden;
        },
        10,
        2
    );

    // Display content for the "# Orders" column with dynamic font size.
    add_action(
        "manage_shop_order_posts_custom_column",
        function ($column, $post_id) {
            if ($column === "customer_orders_count") {
                $order = wc_get_order($post_id);
                $user = $order->get_user();
                $billing_email = get_post_meta(
                    $post_id,
                    "_billing_email",
                    true
                );
                $is_company_email = ewneater_is_company_email($billing_email);
                $is_wholesale = ewneater_is_wholesale_user($user);

                if ($billing_email) {
                    $order_status = $order ? $order->get_status() : "";
                    if (
                        in_array($order_status, [
                            "failed",
                            "cancelled",
                            "trash",
                            "refunded",
                            "pending",
                        ])
                    ) {
                        // For failed/cancelled/etc, show N/A or 0, skip all lookups/logs
                        $order_count = 0;
                        echo __("N/A", "a-neater-woocommerce-admin");
                    } else {
                        // Get customer data from the new SQL table
                        $customer_name = $order
                            ? $order->get_formatted_billing_full_name()
                            : "";
                        $customer_data = EWNeater_Customer_Revenue_Core::get_customer_data(
                            $billing_email,
                            $post_id,
                            $customer_name
                        );
                        $order_count = $customer_data
                            ? $customer_data->total_orders
                            : 0;

                        // If no data found, fallback to direct calculation and update index
                        if (!$customer_data) {
                            EWNeater_Customer_Revenue_Logger::log_data_source(
                                $billing_email,
                                "direct_calculation_fallback",
                                null,
                                null
                            );
                            EWNeater_Customer_Revenue_Core::update_customer_from_order(
                                $post_id
                            );
                            $customer_data = EWNeater_Customer_Revenue_Core::get_customer_data(
                                $billing_email
                            );
                            $order_count = $customer_data
                                ? $customer_data->total_orders
                                : 0;
                        }

                        // Different font size rules for different order types
                        if ($is_company_email) {
                            $base_font_size = 11; // Smaller base size for company
                            $increment_step = 1; // Smaller increment for company
                            $max_font_size = 14; // Smaller max for company
                            $style_extra =
                                "background-color: #000; color: #fff; border: 1px dashed #666666;";
                        } elseif ($is_wholesale) {
                            $base_font_size = 12; // Regular base size for wholesale
                            $increment_step = 2; // Regular increment for wholesale
                            $max_font_size = 16; // Regular max for wholesale
                            $style_extra =
                                "background-color: #f8dda7; color: #94660c; border: 1px dashed #d4a147;";
                        } else {
                            $base_font_size = 12; // Original base size for regular
                            $increment_step = 2; // Original increment for regular
                            $max_font_size = 16; // Original max for regular
                            $style_extra = "";
                        }

                        $font_size =
                            $base_font_size +
                            floor(($order_count - 1) / 5) * $increment_step;
                        if ($font_size > $max_font_size) {
                            $font_size = $max_font_size;
                        }

                        echo '<span class="order-count-button" data-font-size="' . esc_attr($font_size) . '">' .
                            ($is_company_email ? "🐑 " : "") .
                            ($is_wholesale ? "⚡" : "") .
                            esc_html($order_count) .
                            "</span>";
                    }
                } else {
                    echo __("N/A", "a-neater-woocommerce-admin");
                }
            }
        },
        10,
        2
    );

    // Display content for the "Revenue" column with dynamic font size.
    add_action(
        "manage_shop_order_posts_custom_column",
        function ($column, $post_id) {
            if ($column === "customer_revenue") {
                $order = wc_get_order($post_id);
                $user = $order->get_user();
                $billing_email = get_post_meta(
                    $post_id,
                    "_billing_email",
                    true
                );
                $is_company_email = ewneater_is_company_email($billing_email);
                $is_wholesale = ewneater_is_wholesale_user($user);

                if ($billing_email) {
                    $order_status = $order ? $order->get_status() : "";
                    if (
                        in_array($order_status, [
                            "failed",
                            "cancelled",
                            "trash",
                            "refunded",
                            "pending",
                        ])
                    ) {
                        // For failed/cancelled/etc, show N/A or 0, skip all lookups/logs
                        $total_revenue = 0;
                        echo __("N/A", "a-neater-woocommerce-admin");
                    } else {
                        // Get customer data from the new SQL table
                        $customer_name = $order
                            ? $order->get_formatted_billing_full_name()
                            : "";
                        $customer_data = EWNeater_Customer_Revenue_Core::get_customer_data(
                            $billing_email,
                            $post_id,
                            $customer_name
                        );
                        $total_revenue = $customer_data
                            ? $customer_data->total_revenue
                            : 0;

                        // If no data found, fallback to direct calculation and update index
                        if (!$customer_data) {
                            EWNeater_Customer_Revenue_Logger::log_data_source(
                                $billing_email,
                                "direct_calculation_fallback",
                                null,
                                null
                            );
                            EWNeater_Customer_Revenue_Core::update_customer_from_order(
                                $post_id
                            );
                            $customer_data = EWNeater_Customer_Revenue_Core::get_customer_data(
                                $billing_email
                            );
                            $total_revenue = $customer_data
                                ? $customer_data->total_revenue
                                : 0;
                        }

                        // Format large numbers more compactly
                        $formatted_revenue = $total_revenue;
                        if ($total_revenue >= 1000) {
                            if ($total_revenue >= 1000000) {
                                $formatted_revenue =
                                    number_format($total_revenue / 1000000, 1) .
                                    "M";
                            } else {
                                $formatted_revenue =
                                    number_format($total_revenue / 1000, 1) .
                                    "K";
                            }
                        }

                        // Different font size rules for different order types
                        if ($is_company_email) {
                            $base_font_size = 11;
                            $increment_step = 1;
                            $max_font_size = 14;
                            $style_extra =
                                "background-color: #000; color: #fff; border: 1px dashed #666666;";
                        } elseif ($is_wholesale) {
                            $base_font_size = 12;
                            $increment_step = 2;
                            $max_font_size = 16;
                            $style_extra =
                                "background-color: #f8dda7; color: #94660c; border: 1px dashed #d4a147;";
                        } else {
                            $base_font_size = 12;
                            $increment_step = 2;
                            $max_font_size = 16;
                            $style_extra = "";
                        }

                        $font_size =
                            $base_font_size +
                            floor($total_revenue / 1000) * $increment_step;
                        if ($font_size > $max_font_size) {
                            $font_size = $max_font_size;
                        }

                        if ($total_revenue > 0) {
                            echo '<span class="revenue-count-button" data-font-size="' . esc_attr($font_size) . '">' .
                                ($is_company_email ? "🐑 " : "") .
                                ($is_wholesale ? "⚡" : "") .
                                '$' .
                                $formatted_revenue .
                                "</span>";
                        } else {
                            echo __("N/A", "a-neater-woocommerce-admin");
                        }
                    }
                }
            }
        },
        10,
        2
    );

    // Display content for the "Payment Method" column.
    add_action(
        "manage_shop_order_posts_custom_column",
        function ($column, $post_id) {
            if ($column === "payment_method") {
                $order = wc_get_order($post_id);
                if (!$order) {
                    echo __("N/A", "a-neater-woocommerce-admin");
                    return;
                }

                $payment_method = $order->get_payment_method();
                $payment_method_title = $order->get_payment_method_title();

                if (empty($payment_method)) {
                    echo __("N/A", "a-neater-woocommerce-admin");
                    return;
                }

                // Get user preference for icon display
                $show_icons = get_user_meta(get_current_user_id(), 'ewneater_payment_method_icons', true);
                if ($show_icons === '') {
                    $show_icons = '1'; // Default to showing icons
                }

                // Format payment method display with Font Awesome icons
                $display_text = '';
                $icon_html = '';
                
                switch (strtolower($payment_method)) {
                    case 'apple_pay':
                    case 'applepay':
                        $display_text = 'Apple Pay';
                        $icon_html = '<i class="fab fa-apple-pay" style="color: #000; margin-right: 6px;"></i>';
                        break;
                    case 'credit_card':
                    case 'stripe':
                    case 'stripe_cc':
                        $display_text = 'Visa';
                        $icon_html = '<i class="fab fa-cc-visa" style="color: #1A1F71; margin-right: 6px;"></i>';
                        break;
                    case 'paypal':
                        $display_text = 'PayPal';
                        $icon_html = '<i class="fab fa-paypal" style="color: #003087; margin-right: 6px;"></i>';
                        break;
                    case 'bacs':
                    case 'bank_transfer':
                        $display_text = 'Bank Deposit';
                        $icon_html = '<i class="fas fa-university" style="color: #28a745; margin-right: 6px;"></i>';
                        break;
                    case 'cod':
                    case 'cash_on_delivery':
                        $display_text = 'Cash on Delivery';
                        $icon_html = '<i class="fas fa-money-bill-wave" style="color: #ffc107; margin-right: 6px;"></i>';
                        break;
                    case 'cheque':
                    case 'check':
                        $display_text = 'Cheque';
                        $icon_html = '<i class="fas fa-file-invoice" style="color: #6c757d; margin-right: 6px;"></i>';
                        break;
                    default:
                        // Check if payment method title contains card details and strip them
                        if ($payment_method_title) {
                            if (stripos($payment_method_title, 'apple pay') !== false) {
                                $display_text = 'Apple Pay';
                                $icon_html = '<i class="fab fa-apple-pay" style="color: #000; margin-right: 6px;"></i>';
                            } elseif (stripos($payment_method_title, 'visa') !== false) {
                                $display_text = 'Visa';
                                $icon_html = '<i class="fab fa-cc-visa" style="color: #1A1F71; margin-right: 6px;"></i>';
                            } elseif (stripos($payment_method_title, 'mastercard') !== false) {
                                $display_text = 'MasterCard';
                                $icon_html = '<i class="fab fa-cc-mastercard" style="color: #EB001B; margin-right: 6px;"></i>';
                            } elseif (stripos($payment_method_title, 'amex') !== false || stripos($payment_method_title, 'american express') !== false) {
                                $display_text = 'Amex';
                                $icon_html = '<i class="fab fa-cc-amex" style="color: #006FCF; margin-right: 6px;"></i>';
                            } else {
                                $display_text = $payment_method_title;
                                $icon_html = '<i class="fas fa-credit-card" style="color: #6c757d; margin-right: 6px;"></i>';
                            }
                        } else {
                            $display_text = ucwords(str_replace('_', ' ', $payment_method));
                            $icon_html = '<i class="fas fa-credit-card" style="color: #6c757d; margin-right: 6px;"></i>';
                        }
                        break;
                }

                // Display based on user preference
                if ($show_icons === '1') {
                    // Icons only
                    echo '<span class="payment-method-display">' . 
                         $icon_html . 
                         '</span>';
                } elseif ($show_icons === '2') {
                    // Both icons and text
                    echo '<span class="payment-method-display">' . 
                         $icon_html . 
                         esc_html($display_text) . 
                         '</span>';
                } else {
                    // Text only
                    echo '<span class="payment-method-display">' . 
                         esc_html($display_text) . 
                         '</span>';
                }
            }
        },
        10,
        2
    );

    // Ensure columns are visible in Screen Options.
    add_filter("manage_shop_order_posts_columns", function ($columns) {
        $columns["ewneater_custom_column"] = "Tags";
        $columns["customer_orders_count"] = "Cust. Orders";
        $columns["customer_revenue"] = "Cust. Revenue";
        $columns["payment_method"] = "Payment Method";
        return $columns;
    });

    // Add Screen Options for Payment Method Icons
    add_action("admin_init", function () {
        // Add screen option for payment method icons
        add_screen_option("ewneater_payment_method_icons", [
            "label" => "Show Payment Method Icons",
            "default" => true,
            "option" => "ewneater_payment_method_icons"
        ]);
    });

    // Handle Screen Options save
    add_action("wp_ajax_save-ewneater-payment-method-icons", function () {
        // Check nonce - try both possible nonce actions
        $nonce_valid = false;
        if (isset($_POST['_wpnonce'])) {
            $nonce_valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'ewneater_payment_method_icons') ||
                          wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'ewneater_payment_method_icons_nonce');
        }
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed - nonce expired');
            return;
        }

        if (!current_user_can("manage_options")) {
            wp_send_json_error("Unauthorized");
            return;
        }

        $user_id = get_current_user_id();
        $show_icons = isset($_POST["ewneater_payment_method_icons"]) ? sanitize_text_field($_POST["ewneater_payment_method_icons"]) : "1";
        
        $result = update_user_meta($user_id, "ewneater_payment_method_icons", $show_icons);
        
        if ($result !== false) {
            wp_send_json_success(['message' => 'Preference saved successfully', 'value' => $show_icons]);
        } else {
            wp_send_json_error('Failed to save preference');
        }
    });

    // Add custom CSS for styling the new columns.
    add_action("admin_head", function () {
        // Log when All Orders page loads (only once per page load)
        $screen = get_current_screen();
        if (
            $screen &&
            $screen->id === "edit-shop_order" &&
            !isset($_GET["action"])
        ) {
            if (class_exists("EWNeater_Customer_Revenue_Logger")) {
                EWNeater_Customer_Revenue_Logger::info(
                    "All Orders page loaded - customer revenue data will be processed for each order"
                );
            }
        }

        // Add Font Awesome CSS
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';

        // Add column-primary class to Tags column td elements for mobile visibility
        echo '<style>
            .wp-list-table td.column-ewneater_custom_column {
                position: relative;
            }
            @media screen and (max-width: 782px) {
                .wp-list-table td.column-ewneater_custom_column::before {
                    content: "Tags: ";
                    position: absolute;
                    left: 10px;
                    display: block;
                    overflow: hidden;
                    width: 32%;
                    white-space: nowrap;
                    text-overflow: ellipsis;
                    font-weight: 600;
                    color: #666;
                }
            }
        </style>';

        // CSS is now loaded from external file for better performance
    });

    // Top-level Reviews menu (WooCommerce product reviews; also available under A Neater Admin)
    function ewneater_add_reviews_menu()
    {
        $pending_reviews_count = ewneater_get_pending_reviews_count();
        $menu_title = __("Reviews", "a-neater-woocommerce-admin");
        if ($pending_reviews_count > 0) {
            $menu_title .=
                ' <span class="awaiting-mod">' .
                number_format_i18n($pending_reviews_count) .
                "</span>";
        }
        add_menu_page(
            __("Reviews", "a-neater-woocommerce-admin"),
            $menu_title,
            "manage_woocommerce",
            "edit.php?post_type=product&page=product-reviews",
            "",
            "dashicons-star-filled",
            55
        );
    }
    add_action("admin_menu", "ewneater_add_reviews_menu");

    /* Dashboard CSS + menu badge – load on all admin (sidebar awaiting-mod) */
    add_action("admin_enqueue_scripts", function ($hook) {
        wp_enqueue_style(
            "ewneater-admin-menu",
            plugins_url("css/admin-dashboard.css", __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . "css/admin-dashboard.css")
        );
    }, 5);
} // end is_admin() to ensure the plugin functions only run in the WordPress admin area

// Admin bar allowlist on frontend (when admin bar is shown)
add_action("wp_head", "ewneater_admin_bar_allowlist_css");

// Load Quick Find on frontend for admins when option is enabled (deferred to init when pluggable functions exist)
add_action('init', function () {
    if (is_admin()) {
        return;
    }
    if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
        return;
    }
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        return;
    }
    if (get_option('ewneater_quick_find_show_on_frontend', 'no') !== 'yes') {
        return;
    }
    require_once plugin_dir_path(__FILE__) . 'includes/quickfind.php';
}, 0);

// PLUGIN ACTIVATION: create table for Quick Find functionality
function ewneater_activate()
{
    // Include the logger if not already included
    require_once plugin_dir_path(__FILE__) . "includes/quickfind-logger.php";

    EWNeater_Quick_Find_Logger::info("Plugin ACTIVATED");

    // Ensure the quickfind.php file is loaded
    require_once plugin_dir_path(__FILE__) . "includes/quickfind.php";

    // Create the search index table for Quick Find functionality
    EWNeater_Quick_Find::create_search_index_table();
    EWNeater_Quick_Find_Logger::info("Created Quick Find search index table");

    // Initialize metadata with 'pending' status and scheduled build time
    $scheduled_at = time() + 10;
    $meta = [
        "status"       => "pending",
        "last_updated" => current_time("mysql"),
        "scheduled_at" => $scheduled_at,
    ];
    update_option("ewneater_quick_find_meta", $meta);

    // Schedule one-time event to build index after plugin activation
    // This runs 10 seconds after activation to ensure all hooks are registered
    if (!wp_next_scheduled("ewneater_build_quick_find_index")) {
        wp_schedule_single_event($scheduled_at, "ewneater_build_quick_find_index");
        EWNeater_Quick_Find_Logger::info(
            "Scheduled initial Quick Find index build"
        );
    }

    // Create customer revenue table
    EWNeater_Customer_Revenue_Core::create_customer_revenue_table();
    EWNeater_Quick_Find_Logger::info("Created Customer Revenue table");
    EWNeater_Customer_Revenue_Logger::info(
        "Plugin activated - Customer Revenue system initialized"
    );

    // Clear any existing progress metadata
    delete_option("ewneater_customer_revenue_meta");

    // Schedule one-time event to build customer revenue index
    if (!wp_next_scheduled("ewneater_build_customer_revenue_index")) {
        wp_schedule_single_event(
            time() + 15,
            "ewneater_build_customer_revenue_index"
        );
        EWNeater_Quick_Find_Logger::info(
            "Scheduled initial Customer Revenue index build"
        );
        EWNeater_Customer_Revenue_Logger::info(
            "Scheduled initial Customer Revenue index build"
        );
    }
}
register_activation_hook(__FILE__, "ewneater_activate");

// Hook up the index building function to the scheduled event
add_action("ewneater_build_quick_find_index", function () {
    require_once plugin_dir_path(__FILE__) . "includes/quickfind.php";
    EWNeater_Quick_Find::build_search_index();
});

// Hook up the customer revenue index building function to the scheduled event
add_action("ewneater_build_customer_revenue_index", function () {
    require_once plugin_dir_path(__FILE__) .
        "includes/customer-revenue-core.php";
    require_once plugin_dir_path(__FILE__) .
        "includes/customer-revenue-logger.php";
    EWNeater_Customer_Revenue_Logger::info(
        "Starting scheduled customer revenue index build"
    );
    $result = EWNeater_Customer_Revenue_Core::build_customer_revenue_index();
    EWNeater_Customer_Revenue_Logger::info(
        "Scheduled index build completed",
        $result
    );
});

// PLUGIN DEACTIVATION: cleanup for Quick Find functionality
function ewneater_deactivate()
{
    // Include the logger if not already included
    require_once plugin_dir_path(__FILE__) . "includes/quickfind-logger.php";

    EWNeater_Quick_Find_Logger::info("Plugin DEACTIVATED");

    global $wpdb;

    // Drop the search index table for Quick Find
    $table = $wpdb->prefix . "ewneater_search_index";
    $wpdb->query("DROP TABLE IF EXISTS $table");
    EWNeater_Quick_Find_Logger::info("Removed search index table");

    // Drop the customer revenue table
    $customer_revenue_table = $wpdb->prefix . "ewneater_customer_revenue";
    $wpdb->query("DROP TABLE IF EXISTS $customer_revenue_table");
    EWNeater_Quick_Find_Logger::info("Removed customer revenue table");

    // Clear customer revenue logs and metadata
    delete_option("ewneater_customer_revenue_log");
    delete_option("ewneater_customer_revenue_meta");
    EWNeater_Quick_Find_Logger::info("Cleared customer revenue logs");

    // Remove the scheduled events if they exist
    wp_clear_scheduled_hook("ewneater_build_quick_find_index");
    wp_clear_scheduled_hook("ewneater_build_customer_revenue_index");
    wp_clear_scheduled_hook("ewneater_daily_cleanup");

    // Remove the metadata
    delete_option("ewneater_quick_find_meta");
    EWNeater_Quick_Find_Logger::info("Removed Quick Find metadata");
}
register_deactivation_hook(__FILE__, "ewneater_deactivate");

// Standalone function for customer revenue page
function ewneater_display_customer_revenue_page()
{
    // Log that the function is being called
    if (class_exists("EWNeater_Customer_Revenue_Logger")) {
        EWNeater_Customer_Revenue_Logger::info(
            "Customer Revenue page function called",
            [
                "customer_revenue_class_exists" => class_exists(
                    "EWNeater_Customer_Revenue"
                ),
                "current_user_can_manage" => current_user_can("manage_options"),
                "screen_id" => get_current_screen()
                    ? get_current_screen()->id
                    : "unknown",
            ]
        );
    }

    if (class_exists("EWNeater_Customer_Revenue")) {
        EWNeater_Customer_Revenue::display_customer_revenue_page();
    } else {
        echo '<div class="wrap ewneater-dashboard-wrap">';
        ewneater_admin_page_styles();
        echo '<h1 class="ewneater-dash-title">';
        ewneater_admin_breadcrumb(__("Customer Revenue", "a-neater-woocommerce-admin"));
        echo '</h1>';
        echo '<div class="notice notice-error"><p><strong>Error:</strong> Customer Revenue class not found.</p></div>';
        echo "<p><strong>Debug Information:</strong></p>";
        echo "<ul>";
        echo "<li>EWNeater_Customer_Revenue class exists: " .
            (class_exists("EWNeater_Customer_Revenue") ? "Yes" : "No") .
            "</li>";
        echo "<li>EWNeater_Customer_Revenue_Core class exists: " .
            (class_exists("EWNeater_Customer_Revenue_Core") ? "Yes" : "No") .
            "</li>";
        echo "<li>EWNeater_Customer_Revenue_Logger class exists: " .
            (class_exists("EWNeater_Customer_Revenue_Logger") ? "Yes" : "No") .
            "</li>";
        echo "<li>Current user can manage options: " .
            (current_user_can("manage_options") ? "Yes" : "No") .
            "</li>";
        echo "<li>Include path: " .
            plugin_dir_path(__FILE__) .
            "includes/</li>";
        echo "</ul>";
        echo "</div>";
    }
}

// Initialize functionality after all includes are loaded
add_action(
    "init",
    function () {
        // Initialize Quick Find functionality
        if (class_exists("EWNeater_Quick_Find")) {
            EWNeater_Quick_Find::init();
        }
    },
    1
);

/* Initialize Customer Revenue functionality only on specific admin pages */
add_action("admin_init", function () {
    $screen = get_current_screen();

    // Only load Customer Revenue on specific pages
    if (
        $screen &&
        ($screen->id === "a-neater-admin_page_ewneater-customer-revenue" ||
            $screen->id === "edit-shop_order" ||
            strpos($screen->id, "ewneater") !== false)
    ) {
        if (class_exists("EWNeater_Customer_Revenue")) {
            EWNeater_Customer_Revenue::init();
        }
    }

    // Also check for WooCommerce orders page using $_GET parameters
    if (
        isset($_GET["post_type"]) &&
        $_GET["post_type"] === "shop_order" &&
        isset($_GET["page"]) === false &&
        basename($_SERVER["PHP_SELF"]) === "edit.php"
    ) {
        if (class_exists("EWNeater_Customer_Revenue")) {
            EWNeater_Customer_Revenue::init();
        }
    }
});

/* Debug: log A Neater Admin page loads when Customer Revenue Logger active */
add_action("admin_head", function () {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, "ewneater") !== false) {
        if (class_exists("EWNeater_Customer_Revenue_Logger")) {
            EWNeater_Customer_Revenue_Logger::debug("Admin page loaded", [
                "screen_id" => $screen->id,
                "base" => $screen->base,
                "page_hook" => $screen->id,
                "customer_revenue_class_exists" => class_exists(
                    "EWNeater_Customer_Revenue"
                ),
            ]);
        }
    }
});

/*==========================================================================
 * ORDERS ADMIN ASSET ENQUEUE
 *
 * - orders-admin.css + orders-admin.js: edit-shop_order, shop_order, product_page_product-reviews
 * - order-edit-heading.js: single order edit only (customer + total in Order Data h2)
 * - payment-method-icons.js: orders list only (Icons / Text / Both toggle)
 ==========================================================================*/
add_action("admin_enqueue_scripts", function ($hook) {
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $order_screens = ["edit-shop_order", "shop_order"];
    if (function_exists("wc_get_page_screen_id")) {
        $wc_order_screen = wc_get_page_screen_id("shop-order");
        if ($wc_order_screen && !in_array($wc_order_screen, $order_screens, true)) {
            $order_screens[] = $wc_order_screen;
        }
    }

    $list_table_styled_screens = array_merge($order_screens, ["product_page_product-reviews", "users"]);

    if (in_array($screen->id, $list_table_styled_screens, true)) {
        wp_enqueue_style(
            "ewneater-orders-admin",
            plugins_url("css/orders-admin.css", __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . "css/orders-admin.css")
        );
        wp_enqueue_script(
            "ewneater-orders-admin",
            plugins_url("js/orders-admin.js", __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . "js/orders-admin.js"),
            true
        );
        if ($screen->id === "product_page_product-reviews") {
            $placeholder = __("Search reviews...", "a-neater-woocommerce-admin");
        } elseif ($screen->id === "users") {
            $placeholder = __("Search users...", "a-neater-woocommerce-admin");
        } else {
            $placeholder = __("Search orders...", "a-neater-woocommerce-admin");
        }
        wp_localize_script("ewneater-orders-admin", "ewneaterOrdersAdmin", [
            "searchPlaceholder" => $placeholder,
        ]);
    }

    $order_edit_screens = ["shop_order"];
    if (function_exists("wc_get_page_screen_id")) {
        $wc_order_screen = wc_get_page_screen_id("shop-order");
        if ($wc_order_screen && !in_array($wc_order_screen, $order_edit_screens, true)) {
            $order_edit_screens[] = $wc_order_screen;
        }
    }
    if (in_array($screen->id, $order_edit_screens, true)) {
        wp_enqueue_script(
            "ewneater-order-edit-heading",
            plugins_url("js/order-edit-heading.js", __FILE__),
            ["jquery"],
            filemtime(plugin_dir_path(__FILE__) . "js/order-edit-heading.js"),
            true
        );
    }

    if ($screen->id === "edit-shop_order" && !isset($_GET["action"])) {
        wp_enqueue_script(
            "ewneater-payment-method-icons",
            plugins_url("js/payment-method-icons.js", __FILE__),
            ["jquery"],
            filemtime(plugin_dir_path(__FILE__) . "js/payment-method-icons.js"),
            true
        );
        wp_localize_script("ewneater-payment-method-icons", "ewneaterPaymentIcons", [
            "ajaxurl" => admin_url("admin-ajax.php"),
            "nonce" => wp_create_nonce("ewneater_payment_method_icons"),
            "show_icons" => get_user_meta(get_current_user_id(), "ewneater_payment_method_icons", true) ?: "1",
        ]);
    }
});
