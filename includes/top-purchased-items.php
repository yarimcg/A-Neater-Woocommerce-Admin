<?php
/*==========================================================================
 * TOP PURCHASED ITEMS FUNCTIONALITY
 * 
 * Handles the display of frequently purchased items for customers including:
 * - Meta box registration for orders and user profiles
 * - Integration with WooCommerce order pages
 * - Customer purchase history tracking
 ==========================================================================*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/*==========================================================================
 * META BOX REGISTRATION
 ==========================================================================*/
function ewneater_register_purchase_history_meta_box() {
    // For orders page
    add_meta_box(
        'ewneater_customer_purchase_history',
        __('Top Purchased Items', 'a-neater-woocommerce-admin'),
        'ewneater_display_purchase_history_meta_box',
        ['shop_order', 'woocommerce_page_wc-orders'],
        'normal',
        'default'
    );

    // For user profile page
    add_meta_box(
        'ewneater_customer_purchase_history',
        __('Top Purchased Items', 'a-neater-woocommerce-admin'),
        'ewneater_display_purchase_history_meta_box_user',
        'user-edit',
        'side',
        'core'
    );
}
add_action('add_meta_boxes', 'ewneater_register_purchase_history_meta_box');

/**
 * Add screen options for the meta box
 */
function ewneater_add_screen_options() {
    $screen = get_current_screen();

    if ($screen->id === 'shop_order') {
        add_screen_option('per_page', [
            'label'   => 'Top Purchased Items',
            'default' => 4,
            'option'  => 'ewneater_items_per_page'
        ]);
    }
}
add_action('admin_head', 'ewneater_add_screen_options');

/**
 * Handle screen options saving
 */
function ewneater_set_screen_option($status, $option, $value) {
    if ($option == 'ewneater_items_per_page') {
        return (int) $value;
    }
    return $status;
}
add_filter('set-screen-option', 'ewneater_set_screen_option', 10, 3);

/*==========================================================================
 * META BOX DISPLAY HANDLERS
 ==========================================================================*/
function ewneater_display_purchase_history_meta_box($post) {
    $billing_email = get_post_meta($post->ID, '_billing_email', true);
    ewneater_display_purchase_history_meta_box_common($billing_email, false);
}

function ewneater_display_purchase_history_meta_box_user($user) {
    $billing_email = get_user_meta($user->ID, 'billing_email', true);
    echo '<h2 id="customer-top-purchased-items">Customer Top Purchased Items</h2>';
    ewneater_display_purchase_history_meta_box_common($billing_email, true);
}

function ewneater_display_purchase_history_meta_box_common($billing_email, $is_user_page) {
    global $wpdb;

    if (!$billing_email) {
        return '<p>No purchase history available.</p>';
    }

    // Determine default items per page
    $default_items_per_page = $is_user_page ? 10 : 4;
    $items_per_page = get_user_option('ewneater_items_per_page', get_current_user_id()) ?: $default_items_per_page;
    $current_page = isset($_GET['purchase_history_page']) ? (int) $_GET['purchase_history_page'] : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // Get order statistics
    $total_orders = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}posts AS posts
             INNER JOIN {$wpdb->prefix}postmeta AS postmeta ON posts.ID = postmeta.post_id
             WHERE postmeta.meta_key = '_billing_email'
             AND postmeta.meta_value = %s
             AND posts.post_status IN ('wc-completed', 'wc-processing')",
            $billing_email
        )
    );

	// Get first order date
	$first_order_date = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MIN(posts.post_date) FROM {$wpdb->prefix}posts AS posts
			INNER JOIN {$wpdb->prefix}postmeta AS postmeta ON posts.ID = postmeta.post_id
			WHERE postmeta.meta_key = '_billing_email'
			AND postmeta.meta_value = %s
			AND posts.post_status IN ('wc-completed', 'wc-processing')",
			$billing_email
		)
	);

	// Calculate time since first order
	if ($first_order_date) {
		$first_date = new DateTime($first_order_date);
		$now = new DateTime();
		$interval = $first_date->diff($now);
		$years = $interval->y + ($interval->m / 12);
		$time_since_first_order = number_format($years, 1) . ' years';
	}

    // Get top purchased items
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT order_item_meta_product.meta_value AS product_id, 
                    SUM(order_item_meta_qty.meta_value) AS product_qty
             FROM {$wpdb->prefix}woocommerce_order_items AS order_items
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_product 
                ON order_items.order_item_id = order_item_meta_product.order_item_id
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_qty 
                ON order_items.order_item_id = order_item_meta_qty.order_item_id
             INNER JOIN {$wpdb->prefix}posts AS orders 
                ON order_items.order_id = orders.ID
             INNER JOIN {$wpdb->prefix}postmeta AS order_email 
                ON orders.ID = order_email.post_id
             WHERE order_item_meta_product.meta_key = '_product_id'
             AND order_item_meta_qty.meta_key = '_qty'
             AND order_email.meta_key = '_billing_email'
             AND order_email.meta_value = %s
             AND orders.post_status IN ('wc-completed', 'wc-processing')
             GROUP BY order_item_meta_product.meta_value
             ORDER BY product_qty DESC
             LIMIT %d OFFSET %d",
            $billing_email,
            $items_per_page,
            $offset
        )
    );

    // Get total unique products purchased
    $total_products = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT order_item_meta_product.meta_value)
             FROM {$wpdb->prefix}woocommerce_order_items AS order_items
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_product 
                ON order_items.order_item_id = order_item_meta_product.order_item_id
             INNER JOIN {$wpdb->prefix}posts AS orders 
                ON order_items.order_id = orders.ID
             INNER JOIN {$wpdb->prefix}postmeta AS order_email 
                ON orders.ID = order_email.post_id
             WHERE order_item_meta_product.meta_key = '_product_id'
             AND order_email.meta_key = '_billing_email'
             AND order_email.meta_value = %s
             AND orders.post_status IN ('wc-completed', 'wc-processing')",
            $billing_email
        )
    );

	// Start building output
	$output = '<div class="purchase-history-grid">';
	
	if ($total_orders > 1) {
		$output .= '<div style="color: gray;">' . esc_html($total_orders) . ' orders since ' . 
				  esc_html(date('d M Y', strtotime($first_order_date))) . 
				  ' (' . $time_since_first_order . ')</div>';
	}

	if ($results) {
		$output .= '<ul id="purchase-history-list" style="margin: 0; padding: 0;">';
		foreach ($results as $product) {
			$product_obj = wc_get_product($product->product_id);
			if (!$product_obj) continue;

			$admin_url = get_edit_post_link($product->product_id);
			$public_url = get_permalink($product->product_id);
			
			$output .= '<li class="purchase-history-item">';
			$output .= '<a href="' . esc_url($public_url) . '" title="View ' . esc_attr($product_obj->get_name()) . ' on public website">';
			$output .= '<img src="' . esc_url($product_obj->get_image_id() ? wp_get_attachment_image_url($product_obj->get_image_id(), 'thumbnail') : wc_placeholder_img_src()) . '" 
					  alt="' . esc_attr($product_obj->get_name()) . '">';
			$output .= '</a>';
			
			$output .= '<div>';
			$output .= '<strong><a href="' . esc_url($admin_url) . '" title="Edit product in Admin">' . 
					  esc_html($product_obj->get_name()) . '</a></strong><br>';
			$output .= '<small style="font-size: 13px;">x ' . esc_html($product->product_qty) . '</small>';
			$output .= '</div>';
			$output .= '</li>';
		}
		$output .= '</ul>';

        // Add "View more" link if there are more items
        if ($total_products > $items_per_page) {
            $remaining_items = $total_products - $items_per_page;
            $user_id = email_exists($billing_email);
            if ($user_id) {
                $user = get_userdata($user_id);
                $user_name = $user ? $user->display_name : 'this customer';
                $user_edit_url = get_edit_user_link($user_id) . '#purchase-history';
                $output .= '<a href="' . esc_url($user_edit_url) . '">';
                $output .= sprintf(__('View %d more items for %s', 'a-neater-woocommerce-admin'), $remaining_items, esc_html($user_name));
                $output .= '</a>';
            }
        }
	} else {
		$output .= '<p>No purchase history available.</p>';
	}
	
	$output .= '</div>';
	
	return $output;
}

function display_pagination($wpdb, $billing_email, $items_per_page, $current_page) {
    // Get total items
    $total_items = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT order_item_meta_product.meta_value)
             FROM {$wpdb->prefix}woocommerce_order_items AS order_items
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_product 
                ON order_items.order_item_id = order_item_meta_product.order_item_id
             INNER JOIN {$wpdb->prefix}posts AS orders 
                ON order_items.order_id = orders.ID
             INNER JOIN {$wpdb->prefix}postmeta AS order_email 
                ON orders.ID = order_email.post_id
             WHERE order_item_meta_product.meta_key = '_product_id'
             AND order_email.meta_key = '_billing_email'
             AND order_email.meta_value = %s
             AND orders.post_status IN ('wc-completed', 'wc-processing')",
            $billing_email
        )
    );

    $total_pages = ceil($total_items / $items_per_page);

    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base' => add_query_arg('purchase_history_page', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo;'),
            'next_text' => __('&raquo;'),
            'total' => $total_pages,
            'current' => $current_page
        ]);
        echo '</div></div>';
    }
}

/*==========================================================================
 * USER PROFILE INTEGRATION
 ==========================================================================*/
class EWNeater_Top_Purchased_Items {
    /**
     * Initialize the Top Purchased Items functionality
     */
    public static function init() {
        // Register the meta box for orders
        add_action('add_meta_boxes', [self::class, 'register_order_meta_box']);

        // Add the sidebar to user profile pages
        //add_action('show_user_profile', [self::class, 'add_to_user_profile_sidebar']);
        //add_action('edit_user_profile', [self::class, 'add_to_user_profile_sidebar']);

        // Add required styles (if needed)
        add_action('admin_head', [self::class, 'add_styles']);
    }

    /**
     * Register the meta box for orders
     */
    public static function register_order_meta_box() {
        add_meta_box(
            'ewneater_customer_purchase_history',
            __('Top Purchased Items', 'a-neater-woocommerce-admin'),
            [self::class, 'display_purchase_history_meta_box'],
            ['shop_order', 'woocommerce_page_wc-orders'],
            'normal',
            'default'
        );
    }

    /**
     * Add required styles
     */
    public static function add_styles() {
        ?>
        <style type="text/css">
            /* Basic styling for items */
            .purchase-history-grid {
                margin: 10px 0;
            }
            .purchase-history-item {
                display: flex;
                align-items: center;
                margin-bottom: 10px;
                border-bottom: 1px solid #ddd;
                padding-bottom: 10px;
            }
            .purchase-history-item:last-child {
                border-bottom: none;
            }
            .purchase-history-item img {
                width: 50px;
                height: 50px;
                margin-right: 10px;
            }
            .purchase-history-item a {
                text-decoration: none;
                color: inherit;
            }

            /* Remove move cursor and allow text selection from user profile meta boxes */
            .user-edit-php .postbox .hndle,
            .profile-php .postbox .hndle {
                cursor: default;
                user-select: text;
                -webkit-user-select: text;
                -moz-user-select: text;
                -ms-user-select: text;
            }

            /* Keep move cursor for order page meta boxes */
            .post-type-shop_order .postbox .hndle,
            .woocommerce_page_wc-orders .postbox .hndle {
                cursor: move;
            }
        </style>
        <?php
    }

    /**
     * Display the meta box content for orders
     */
    public static function display_purchase_history_meta_box($post) {
        $billing_email = get_post_meta($post->ID, '_billing_email', true);
        // Echo the output since meta boxes expect echo not return
        echo ewneater_display_purchase_history_meta_box_common($billing_email, false);
    }

    /**
     * Add the Purchase History to user profile sidebar
     */
    public static function add_to_user_profile_sidebar($user) {
        // Only proceed if we're on the user edit page
        $screen = get_current_screen();
        if ($screen->id !== 'user-edit' && $screen->id !== 'profile') {
            return;
        }
        
        // Get the user's billing email
        $billing_email = get_user_meta($user->ID, 'billing_email', true);
        
        // Debug output
        $output = ewneater_display_purchase_history_meta_box_common($billing_email, true);
        
        // Create the sidebar HTML
        $sidebar_html = '<div class="postbox">';
        $sidebar_html .= '<h2 class="hndle"><span>Top Purchased Items</span></h2>';
        $sidebar_html .= '<div class="inside" id="purchase_history">';
        $sidebar_html .= $output ? $output : 'No purchase history available.';
        $sidebar_html .= '</div></div>';
        
        // Add the sidebar content after the past orders
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                const $sidebar = $(".ewneater-user-sidebar");
                if ($sidebar.length) {
                    $sidebar.append(\'' . str_replace(["\n", "'"], ['', "\\'"], $sidebar_html) . '\');
                }
            });
        </script>';
    }
}

// Initialize the Top Purchased Items functionality
EWNeater_Top_Purchased_Items::init();

