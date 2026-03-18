<?php
/*==========================================================================
 * ENHANCED USER PURCHASE HISTORY DISPLAY
 * 
 * Displays detailed purchase history for users including:
 * - Product variations
 * - Expandable item list
 * - Top 10 items with load more functionality
 ==========================================================================*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class EWNeater_Enhanced_User_Purchase_History {
    private static $items_per_page = 10;
    private static $initial_display = 5;

    /**
     * Initialize the enhanced purchase history display
     */
    public static function init() {
        // Add display hooks
        add_action('edit_user_profile', [self::class, 'display_enhanced_purchase_history'], 20);
        add_action('show_user_profile', [self::class, 'display_enhanced_purchase_history'], 20);
        add_action('admin_head', [self::class, 'add_inline_styles']);
        add_action('admin_footer', [self::class, 'add_inline_scripts']);
    }

    /**
     * Add inline styles
     */
    public static function add_inline_styles() {
        $screen = get_current_screen();
        if (!in_array($screen->id, ['user-edit', 'profile'])) {
            return;
        }
        ?>
        <style type="text/css">
            .purchase-history-items {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }

            .purchase-history-item {
                display: flex;
                gap: 15px;
            }

            .product-image img {
                width: 60px;
                height: 60px;
                object-fit: cover;
            }

            .product-details {
                flex: 1;
            }

            .product-details h3 {
                margin: 0 0 5px 0;
                font-size: 14px;
            }

            .product-details h3 a {
                text-decoration: none;
                color: #0073aa;
            }

            .product-variations {
                font-size: 12px;
                color: #666;
                margin-top: 5px;
            }

            .load-more-container {
                text-align: center;
                margin-top: 20px;
                grid-column: 1 / -1;
            }

            .load-more-items {
                padding: 5px 15px;
            }

            .items-count {
                text-align: center;
                color: #666;
                margin-bottom: 15px;
                font-size: 13px;
            }

            @media screen and (max-width: 782px) {
                .purchase-history-items {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }

    /**
     * Add inline scripts
     */
    public static function add_inline_scripts() {
        $screen = get_current_screen();
        if (!in_array($screen->id, ['user-edit', 'profile'])) {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.load-more-items').on('click', function() {
                    const $button = $(this);
                    const $container = $button.closest('.ew-toggle-content');
                    const $hiddenItems = $container.find('.purchase-history-item.hidden');
                    
                    // Show next 10 items
                    $hiddenItems.slice(0, 10).removeClass('hidden');
                    
                    // Hide button if no more items
                    if ($container.find('.purchase-history-item.hidden').length === 0) {
                        $button.hide();
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Display the enhanced purchase history
     */
    public static function display_enhanced_purchase_history($user) {
        $billing_email = get_user_meta($user->ID, 'billing_email', true);
        if (!$billing_email) {
            echo '<p>No purchase history available.</p>';
            return;
        }

        global $wpdb;

        // Update pagination logic to show 10 items
        $items_per_page = 10;
        $current_page = isset($_GET['purchase_history_page']) ? (int) $_GET['purchase_history_page'] : 1;
        if ($current_page < 1) $current_page = 1;
        $limit = $items_per_page * $current_page;
        $offset = 0;

        // Get total number of products for this user
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

        // Get top purchased items (limit increases with each page)
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
                $limit,
                $offset
            )
        );

        $content = '';
        if ($results) {
            $content .= '<div class="purchase-history-items">';
            foreach ($results as $product) {
                $product_obj = wc_get_product($product->product_id);
                if (!$product_obj) continue;

                $admin_url = get_edit_post_link($product->product_id);
                $public_url = get_permalink($product->product_id);
                
                $content .= '<div class="purchase-history-item">';
                $content .= '<div class="product-image">';
                $content .= '<a href="' . esc_url($public_url) . '" title="View ' . esc_attr($product_obj->get_name()) . ' on public website">';
                $content .= '<img src="' . esc_url($product_obj->get_image_id() ? wp_get_attachment_image_url($product_obj->get_image_id(), 'thumbnail') : wc_placeholder_img_src()) . '" 
                          alt="' . esc_attr($product_obj->get_name()) . '">';
                $content .= '</a></div>';
                
                $content .= '<div class="product-details">';
                $content .= '<h3><a href="' . esc_url($admin_url) . '" title="Edit product in Admin">' . 
                          esc_html($product_obj->get_name()) . '</a></h3>';

                // --- VARIATION BREAKDOWN ---
                $variation_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT 
                            order_item_meta_variation.meta_value AS variation_id,
                            SUM(order_item_meta_qty.meta_value) AS variation_qty
                         FROM {$wpdb->prefix}woocommerce_order_items AS order_items
                         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_product 
                            ON order_items.order_item_id = order_item_meta_product.order_item_id
                         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_qty 
                            ON order_items.order_item_id = order_item_meta_qty.order_item_id
                         LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_variation 
                            ON order_items.order_item_id = order_item_meta_variation.order_item_id
                         INNER JOIN {$wpdb->prefix}posts AS orders 
                            ON order_items.order_id = orders.ID
                         INNER JOIN {$wpdb->prefix}postmeta AS order_email 
                            ON orders.ID = order_email.post_id
                         WHERE order_item_meta_product.meta_key = '_product_id'
                         AND order_item_meta_qty.meta_key = '_qty'
                         AND order_item_meta_product.meta_value = %d
                         AND order_email.meta_key = '_billing_email'
                         AND order_email.meta_value = %s
                         AND orders.post_status IN ('wc-completed', 'wc-processing')
                         GROUP BY order_item_meta_variation.meta_value
                         ORDER BY variation_qty DESC",
                        $product->product_id,
                        $billing_email
                    )
                );

                $variation_display = [];
                $total_qty = 0;
                foreach ($variation_rows as $var) {
                    if ($var->variation_id) {
                        $variation_obj = wc_get_product($var->variation_id);
                        if ($variation_obj && $variation_obj->is_type('variation')) {
                            $attributes = [];
                            foreach ($variation_obj->get_variation_attributes() as $attr_value) {
                                // Only show the value, not the attribute name
                                $attributes[] = $attr_value;
                            }
                            $attr_string = implode(', ', $attributes);
                            $variation_display[] = trim($attr_string) . ' x ' . esc_html($var->variation_qty);
                            $total_qty += (int)$var->variation_qty;
                        }
                    }
                }
                if (!empty($variation_display)) {
                    $content .= '<div class="product-variations">' . implode('<br>', $variation_display) . '<br>= ' . esc_html($total_qty) . ' total</div>';
                }
                // --- END VARIATION BREAKDOWN ---

                $content .= '</div></div>';
            }
            $content .= '</div>';

            // Add items count display
            $showing_items = min($limit, $total_products);
            $content .= '<div class="items-count">Showing ' . esc_html($showing_items) . ' of ' . esc_html($total_products) . ' products</div>';
            
            // Update load more section
            if ($limit < $total_products) {
                $next_page = $current_page + 1;
                $load_more_url = add_query_arg('purchase_history_page', $next_page) . '#purchase-history';
                $content .= '<div class="load-more-container">';
                $content .= '<a href="' . esc_url($load_more_url) . '" class="button load-more-items">Load 10 more</a>';
                $content .= '</div>';
            }
        } else {
            $content .= '<p>No purchase history available.</p>';
        }

        // Use the admin toggler module to render the content
        echo \EWNeater_Admin_Toggle::render(
            'purchase_history',
            'Purchase History <span style="font-size: 0.8em; font-weight: normal;">(' . esc_html($total_products) . ' products)</span>',
            $content,
            [
                'icon' => 'dashicons-arrow-down-alt2',
                'container_class' => 'purchase-history-container'
            ]
        );
    }
}

// Initialize the enhanced purchase history display
EWNeater_Enhanced_User_Purchase_History::init();
