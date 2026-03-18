<?php
/*==========================================================================
 * WOOCOMMERCE PAST ORDERS DISPLAY
 * 
 * Handles the display and management of past orders across the system:
 * - Shows order history in user profiles and order admin screens
 * - Provides filtering and display of orders by email
 * - Manages responsive layouts for desktop and mobile views
 * - Includes status-based styling and order highlighting
 ==========================================================================*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class EWNeater_Past_Orders {
    
    /*==========================================================================
     * CORE DISPLAY FUNCTIONS
     * Handles the main display logic for past orders
     ==========================================================================*/
    
    /**
     * Display past orders for a given email address
     * @param string $billing_email The email to look up orders for
     * @param int|null $current_order_id Optional current order ID to highlight
     * @return string HTML output of the past orders
     */
    public static function display_past_orders($billing_email, $current_order_id = null) {
        if (!$billing_email) {
            return 'No billing email provided.';
        }

        // Retrieve all past orders for this billing email
        $orders = wc_get_orders([
            'billing_email' => $billing_email,
            'limit'         => 5,  // Limit to 5 orders
            'orderby'       => 'date',
            'order'         => 'DESC',
        ]);

        ob_start();

       // Display past orders if available
        if (!empty($orders)) {
            echo '<ul>';
            foreach ($orders as $order) {
                // Get the creation time of the order
                $time = $order->get_date_created()->getOffsetTimestamp();
            
                // Get order status and determine CSS class
                $status = $order->get_status();
                $status_class = ' class="order-status-' . esc_attr($status) . '"';
            
                // Display each order
                $active_class = ($current_order_id && $current_order_id == $order->get_id()) ? ' class="active"' : '';
                echo '<li' . $active_class . '>' . date('d M Y', $time) .
                    ' <a href="' . admin_url("post.php?post=" . $order->get_id() . "&action=edit") . '">Order #' . $order->get_id() . '</a> ' .
                    '<span' . $status_class . '>' . wc_get_order_status_name($order->get_status()) . '</span></li>';
            }
            
            // Add link to search all orders for this email
            $search_url = admin_url('edit.php?' . http_build_query([
                's' => $billing_email,
                'post_status' => 'all',
                'post_type' => 'shop_order',
                'action' => '-1',
                'm' => '0',
                '_customer_user' => '',
                'paged' => '1',
            ]));
            
            // Get total count of orders for this email
            $total_orders = wc_get_orders([
                'billing_email' => $billing_email,
                'return' => 'ids',
                'limit' => -1,
            ]);
            $total_count = count($total_orders);
            
            echo '</ul>';
            
            // Only show "View all" link if there are more than 5 orders
            if ($total_count > 5) {
                echo '<a href="' . esc_url($search_url) . '">View all ' . $total_count . ' orders for this customer</a>';
            }
        } else {
            echo 'No previous orders.';
        }

        return ob_get_clean();
    }

    /*==========================================================================
     * ADMIN META BOX FUNCTIONS
     * Manages the order meta box display in WooCommerce admin
     ==========================================================================*/
    
    /**
     * Register the Past Orders meta box for orders
     */
    public static function register_order_meta_box() {
        add_meta_box(
            'past_orders',
            'Past Orders',
            [self::class, 'render_order_meta_box'],
            ['shop_order', 'woocommerce_page_wc-orders'],
            'side',
            'core'
        );
    }

    /**
     * Render the Past Orders meta box for orders
     */
    public static function render_order_meta_box() {
        // Retrieve the current order ID using two possible methods
        $order_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['post']) ? (int)$_GET['post'] : 0);

        // Check if a valid order ID is found
        if ($order_id <= 0) {
            echo 'Invalid order ID. Please check the URL format and ensure a valid order ID is present.';
            return;
        }

        // Fetch the order details
        $current_order = wc_get_order($order_id);
        if (!$current_order) {
            echo 'Order could not be retrieved. Please check if the order exists.';
            return;
        }

        // Get the billing email
        $billing_email = $current_order->get_billing_email();
        if (!$billing_email) {
            echo 'No billing email found for this order.';
            return;
        }

        echo self::display_past_orders($billing_email, $order_id);
    }

    /*==========================================================================
     * USER PROFILE FUNCTIONS
     * Handles the display of past orders in user profiles
     ==========================================================================*/
    
    /**
     * Add the Past Orders sidebar to user profile pages
     */
    public static function add_user_profile_sidebar($user) {
        // Only proceed if we're on the user edit page
        $screen = get_current_screen();
        if ($screen->id !== 'user-edit' && $screen->id !== 'profile') {
            return;
        }
        
        // Get the user's billing email
        $billing_email = get_user_meta($user->ID, 'billing_email', true);
        
        // Create the sidebar HTML
        $sidebar_html = '<div class="ewneater-user-sidebar">';
        $sidebar_html .= '<div class="postbox">';
        $sidebar_html .= '<h2 class="hndle"><span>Past Orders</span></h2>';
        $sidebar_html .= '<div class="inside" id="past_orders">';
        $sidebar_html .= self::display_past_orders($billing_email);
        $sidebar_html .= '</div></div></div>';
        
        // Add the sidebar content after the profile form
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                const $form = $("#your-profile");
                if ($form.length) {
                    $form.after(\'' . str_replace(["\n", "'"], ['', "\\'"], $sidebar_html) . '\');
                }
            });
        </script>';
    }

    /*==========================================================================
     * STYLING AND INITIALIZATION
     * Manages styles and plugin initialization
     ==========================================================================*/
    
    /**
     * Add required CSS styles for Past Orders display
     */
    public static function add_styles() {
        echo '
        <style>
            /* Two Column Layout for User Profile */
            .user-edit-php #wpbody-content > .wrap {
                position: relative;  /* Create positioning context */
            }

            .user-edit-php #your-profile {
                width: 65%;
                margin-right: 3%;
                float: left;
            }
            
            .user-edit-php .ewneater-user-sidebar {
                width: 30%;
                position: absolute;
                right: 0;
                top: 0;
                margin-top: 65px; /* Align with the form content */
            }
            
            /* Past Orders Box Styling */
            .ewneater-user-sidebar .postbox {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                min-width: 255px;
            }
            
            .ewneater-user-sidebar .postbox .hndle {
                padding: 8px 12px;
                margin: 0;
                border-bottom: 1px solid #ccd0d4;
            }
            
            .ewneater-user-sidebar .inside {
                padding: 12px;
                margin: 0;
            }
            
            .ewneater-user-sidebar ul {
                margin: 0;
            }
            
            .ewneater-user-sidebar ul li {
                margin: 0;
                padding: 0.3em;
            }

            /* Order Styling */
            #past_orders ul li {
                margin: 0;
                padding: 0.3em;
            }
            #past_orders ul li.active {
                background-color: #CAF0F8;
            }

            /* View All Orders Link */
            #past_orders a[href*="edit.php"] {
                display: block;
                margin-top: 10px;
                text-decoration: none;
            }

            /* Past Orders - Status Colors */
            #past_orders .order-status,
            #past_orders [class*="order-status-"] {
                font-size: 0.9em;
                padding: 2px 3px;
                border-radius: 3px;
            }
                
            #past_orders .order-status-pending { 
                background-color: #f8dda7;
                color: #94660c;
            }
            #past_orders .order-status-processing { 
                background-color: #c6e1c6;
                color: #5b841b;
            }
            #past_orders .order-status-on-hold { 
                background-color: #f8dda7;
                color: #94660c;
            }
            #past_orders .order-status-completed { 
                background-color: #c8d7e1;
                color: #2e4453;
            }
            #past_orders .order-status-cancelled { 
                background-color: #eba3a3;
                color: #761919;
            }
            #past_orders .order-status-refunded { 
                background-color: #e5e5e5;
                color: #777;
            }
            #past_orders .order-status-failed { 
                background-color: #eba3a3;
                color: #761919;
            }
            #past_orders .order-status-draft,
            #past_orders .order-status-checkout-draft { 
                background-color: #e5e5e5;
                color: #777;
            }
            #past_orders .order-status-wholesale { 
                background-color: #fff3cd;
                color: #856404;
            }

            /* Mobile Styles */
            @media screen and (max-width: 782px) {
                .user-edit-php #your-profile {
                    width: 100%;
                    float: none;
                    margin-right: 0;
                    margin-bottom: 20px;
                    order: 2;
                }
                
                .user-edit-php .ewneater-user-sidebar {
                    width: 100%;
                    position: relative;
                    margin-top: 0;
                    order: 1;
                }

                /* Wrap container for flex ordering */
                .user-edit-php #wpbody-content > .wrap {
                    display: flex;
                    flex-direction: column;
                }
            }
        </style>';
    }

    /**
     * Initialize the Past Orders functionality
     */
    public static function init() {
        // Register the meta box for orders
        add_action('add_meta_boxes', [self::class, 'register_order_meta_box']);

        // Add the sidebar to user profile pages
        add_action('show_user_profile', [self::class, 'add_user_profile_sidebar']);
        add_action('edit_user_profile', [self::class, 'add_user_profile_sidebar']);

        // Add required styles
        add_action('admin_head', [self::class, 'add_styles']);
    }
}

// Initialize the Past Orders functionality
EWNeater_Past_Orders::init();
