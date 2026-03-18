<?php
/*==========================================================================
 * WOOCOMMERCE QUICK FIND - ADMIN INTERFACE
 *
 * Search orders, customers, products, and admin menus from any admin page.
 * Press '/' to focus the search box.
 *
 * Features:
 * - Admin menus: embedded in JS for instant client-side filter (no AJAX)
 * - Orders/customers: optional preload for instant results; server search with
 *   pagination and "Show more"
 * - Products: server search and optional preload
 * - Results sorted by type (orders, customers, products, menus); term highlighting
 *
 * USAGE:
 * - Type '/' to focus; enter at least 3 characters to search
 * - ↑/↓ to navigate, Enter to select, Esc to close
 * - Click 'x' or outside to dismiss
 ==========================================================================*/

// Include the Quick Find functionality
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}



class EWNeater_Quick_Find {

    /** Menu items built as late as possible on Quick Find Menus page (so late-registering plugins are included) */
    private static $admin_menu_items_for_display = null;

    /*==========================================================================
     * INITIALIZATION
     *
     * Registers all required WordPress hooks:
     * - Admin bar search input
     * - JavaScript and CSS assets
     * - AJAX search handlers
     * - Results container in footer
     * - Admin menu items
     ==========================================================================*/
    public static function init() {
        // Show QuickFind on admin pages
        if (is_admin()) {
            add_action('admin_bar_menu', [self::class, 'add_search_bar'], 100);
            add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
            add_action('admin_footer', [self::class, 'add_search_results_container']);
        }
        // Show QuickFind on frontend for admins when option is enabled
        elseif (!is_admin() && is_admin_bar_showing() && current_user_can('manage_options') && get_option('ewneater_quick_find_show_on_frontend', 'no') === 'yes') {
            add_action('admin_bar_menu', [self::class, 'add_search_bar'], 100);
            add_action('wp_enqueue_scripts', [self::class, 'enqueue_scripts_frontend']);
            add_action('wp_footer', [self::class, 'add_search_results_container'], 5);
        }

        // AJAX handlers need to be available always
        add_action('wp_ajax_ewneater_quick_find', [self::class, 'handle_search']);
        add_action('wp_ajax_ewneater_quick_find_menus', [self::class, 'handle_search_menus']);

        // Add admin menu items
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_menu', [self::class, 'cache_admin_menu_items'], 9999);

        // Capture admin menus as late as possible on Quick Find Menus page so plugins (e.g. Redirections) that add menus late are included
        add_action('load-ewneater-admin_page_ewneater-quick-find-menus', [self::class, 'capture_admin_menus_late'], 9999);

        // Add AJAX handlers
        add_action('wp_ajax_ewneater_rebuild_quick_find_index', [self::class, 'handle_rebuild_index']);
        add_action('wp_ajax_ewneater_clear_quick_find_index', [self::class, 'handle_clear_index']);
        add_action('wp_ajax_ewneater_toggle_logging', [self::class, 'handle_toggle_logging']);
        add_action('wp_ajax_ewneater_save_quick_find_results_per_section', [self::class, 'handle_save_results_per_section']);
        add_action('wp_ajax_ewneater_save_quick_find_show_on_frontend', [self::class, 'handle_save_show_on_frontend']);

        add_action('wp_ajax_ewneater_rebuild_customer_quick_find', [self::class, 'handle_rebuild_customer_index']);
        add_action('wp_ajax_ewneater_rebuild_order_quick_find', [self::class, 'handle_rebuild_order_index']);

        // Add admin notice
        add_action('admin_notices', function() {
            static $shown = false;
            if ($shown) return;
            $shown = true;

            // Skip on A Neater Admin subpages where the notice would appear before the page header
            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                if ($screen && isset($screen->id)) {
                    $skip_screens = [
                        'a-neater-admin_page_ewneater-search-index',
                        'a-neater-admin_page_ewneater-on-sale-manager',
                    ];
                    if (in_array($screen->id, $skip_screens, true)) {
                        return;
                    }
                }
            }

            global $wpdb;
            $table = $wpdb->prefix . 'ewneater_search_index';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            $meta = get_option('ewneater_quick_find_meta');

            if (!$table_exists) {
                echo '<div class="notice notice-error is-dismissible">
                    <p><strong>Woocommerce Quick Find:</strong> The search index table does not exist. <a href="' . admin_url('admin.php?page=ewneater-search-index') . '" class="button button-small" style="margin-left: 10px;">Create Index</a></p>
                </div>';
            } elseif (!$meta || $meta['status'] !== 'complete') {
                $scheduled_at = 0;
                if ($meta && ($meta['status'] ?? '') === 'pending') {
                    $scheduled_at = (int) ($meta['scheduled_at'] ?? 0);
                    if ($scheduled_at <= 0) {
                        $ts = wp_next_scheduled('ewneater_build_quick_find_index');
                        $scheduled_at = $ts ? (int) $ts : 0;
                    }
                }
                $countdown_attr = $scheduled_at > 0 ? ' data-scheduled-at="' . esc_attr($scheduled_at) . '"' : '';
                echo '<div class="notice notice-warning is-dismissible" id="ewneater-qf-notice">
                    <p><strong>Woocommerce Quick Find:</strong> The search index is being built in the background.';
                if ($scheduled_at > 0) {
                    echo ' <span id="ewneater-qf-countdown"' . $countdown_attr . '></span>';
                } else {
                    echo ' Quick Find will be available once this process is complete.';
                }
                echo ' <a href="' . esc_url(admin_url('admin.php?page=ewneater-search-index')) . '" class="button button-small" style="margin-left: 10px;">View Progress</a></p>
                </div>';
                if ($scheduled_at > 0) {
                    echo '<script>
(function() {
    var el = document.getElementById("ewneater-qf-countdown");
    if (!el) return;
    var scheduledAt = parseInt(el.getAttribute("data-scheduled-at"), 10);
    function fmt(s) { return s < 60 ? s + "s" : Math.floor(s/60) + "m " + (s%60) + "s"; }
    function tick() {
        var rem = scheduledAt - Math.floor(Date.now()/1000);
        if (rem <= 0) { el.textContent = "Starting build..."; clearInterval(iv); return; }
        el.textContent = "Rebuild starts in " + fmt(rem) + ".";
    }
    tick();
    var iv = setInterval(tick, 1000);
})();
</script>';
                }
            }
        });

        // Add new order and customer handling
        add_action('woocommerce_new_order', [self::class, 'handle_new_order']);
        add_action('woocommerce_update_order', [self::class, 'handle_new_order']);
        add_action('user_register', [self::class, 'handle_new_customer']);
        add_action('profile_update', [self::class, 'handle_new_customer']);

        // Add hook for saving order (including when totals are edited)
        add_action('woocommerce_order_after_save', function($order) {
            EWNeater_Quick_Find::handle_new_order($order->get_id());
        });
        
        // Add hook for when order meta is saved (fires when order total is edited in admin)
        add_action('woocommerce_process_shop_order_meta', [self::class, 'handle_new_order'], 20, 2);
        
        // Add hook for when order items are saved (fires when shipping/items are updated)
        add_action('woocommerce_saved_order_items', [self::class, 'handle_new_order'], 20, 2);
        
        // Add hook for WordPress post save (catches all order saves including total edits)
        add_action('save_post_shop_order', function($post_id, $post) {
            // Only update if this is not an autosave or revision
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }
            // Ensure this is actually a shop_order post type
            if (isset($post->post_type) && $post->post_type === 'shop_order') {
                EWNeater_Quick_Find::handle_new_order($post_id);
            }
        }, 20, 2);

        // Remove order from search index when order is trashed or permanently deleted
        // Covers manual delete/trash and WooCommerce 24h auto-delete of draft orders
        add_action('wp_trash_post', [self::class, 'remove_order_from_index_on_trash']);
        add_action('before_delete_post', [self::class, 'remove_order_from_index_on_delete'], 10, 2);

        // Remove user from search index when user is deleted (e.g. after profile merge)
        add_action('deleted_user', [self::class, 'remove_customer_from_index']);

        // Sync Quick Find index when BSF merge customer profiles completes
        add_action('bsf_merge_customer_profiles_complete', [self::class, 'handle_customer_merge'], 10, 2);

        // Status + full progress check (used by both button-triggered builds and page-load polling)
        add_action('wp_ajax_ewneater_check_index_status', function() {
            check_ajax_referer('check_index_status_nonce', 'security');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
            }

            wp_send_json_success(EWNeater_Quick_Find::get_quick_find_progress());
        });
    }

    /*==========================================================================
     * ADMIN MENU
     *
     * Adds the Quick Find Index submenu to the A Neater Admin menu
     * Provides access to search index management and statistics
     ==========================================================================*/
    public static function add_admin_menu() {
        add_submenu_page(
            'ewneater-admin', // Parent slug
            'Search Index', // Page title
            'Quick Find Index', // Menu title
            'manage_options', // Capability
            'ewneater-search-index', // Menu slug
            [self::class, 'display_search_index_page'] // Function to display the page content
        );
    }

    /**
     * Runs on load of Quick Find Menus page at priority 9999 so we capture menus
     * added by plugins that register later (e.g. Redirections under Tools).
     */
    public static function capture_admin_menus_late() {
        self::$admin_menu_items_for_display = self::get_admin_menu_items();
    }

    /*==========================================================================
     * ADMIN MENU CACHE
     *
     * Stores the current user's admin menus for Quick Find search
     ==========================================================================*/
    public static function cache_admin_menu_items() {
        if (!is_admin()) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $cache_timestamp = (int) get_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_updated', true);
        $cache_hash = get_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_hash', true);
        $current_hash = self::get_active_plugins_hash();

        if (!self::is_menu_cache_stale($cache_timestamp, $cache_hash, $current_hash)) {
            return;
        }

        $menu_items = self::get_admin_menu_items();
        if (empty($menu_items)) {
            return;
        }

        update_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache', $menu_items);
        update_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_updated', current_time('timestamp'));
        update_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_hash', $current_hash);
    }

    /*==========================================================================
     * ADMIN MENU CACHE ACCESS
     *
     * Returns cached admin menus or builds them if possible
     ==========================================================================*/
    private static function get_cached_admin_menu_items() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return [];
        }

        $menu_items = get_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache', true);
        $cache_timestamp = (int) get_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_updated', true);
        $cache_hash = get_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_hash', true);
        $current_hash = self::get_active_plugins_hash();

        if (
            empty($menu_items)
            || !is_array($menu_items)
            || self::is_menu_cache_stale($cache_timestamp, $cache_hash, $current_hash)
        ) {
            $menu_items = self::get_admin_menu_items();
            if (!empty($menu_items)) {
                update_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache', $menu_items);
                update_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_updated', current_time('timestamp'));
                update_user_meta($user_id, 'ewneater_quick_find_admin_menu_cache_hash', $current_hash);
            }
        }

        return is_array($menu_items) ? $menu_items : [];
    }

    /*==========================================================================
     * ADMIN MENU CACHE VALIDATION
     *
     * Determines when to rebuild the admin menu cache
     ==========================================================================*/
    private static function is_menu_cache_stale($cache_timestamp, $cache_hash, $current_hash) {
        $cache_timestamp = (int) $cache_timestamp;
        $is_hash_changed = $cache_hash !== $current_hash;

        if ($is_hash_changed) {
            return true;
        }

        if ($cache_timestamp <= 0) {
            return true;
        }

        $week_ago = current_time('timestamp') - WEEK_IN_SECONDS;
        return $cache_timestamp < $week_ago;
    }

    /**
     * Hash used to invalidate menu cache when plugins or plugin version changes.
     * Including plugin version ensures cache rebuilds after URL fixes (e.g. tools.php?page=).
     */
    private static function get_active_plugins_hash() {
        $active_plugins = (array) get_option('active_plugins', []);
        $network_plugins = (array) get_site_option('active_sitewide_plugins', []);

        $network_keys = array_keys($network_plugins);
        $all_plugins = array_merge($active_plugins, $network_keys);
        $all_plugins = array_unique(array_filter($all_plugins));
        sort($all_plugins);

        $version = defined('EWNEATER_VERSION') ? EWNEATER_VERSION : '0';
        return md5(implode('|', $all_plugins) . '|' . $version);
    }

    /*==========================================================================
     * ADMIN MENU VISIBILITY
     *
     * Returns hidden menu keys for Quick Find results
     ==========================================================================*/
    private static function get_hidden_menu_keys() {
        $visibility = get_option('ewneater_quick_find_menu_visibility', []);
        $hidden_items = [];

        if (isset($visibility['hidden']) && is_array($visibility['hidden'])) {
            $hidden_items = $visibility['hidden'];
        }

        return $hidden_items;
    }

    /*==========================================================================
     * ORDER/CUSTOMER RESULT FORMATTING (DRY)
     *
     * Shared helpers for handle_search and get_orders_customers_data_for_js.
     * Return array with 'title' and 'url' for one result row.
     ==========================================================================*/
    private static function format_order_result_for_display($metadata, $entity_id) {
        $shipping_location = ($metadata['city'] ?? '') . ', ' . ($metadata['state'] ?? '');
        $billing_location = $metadata['billing_location'] ?? '';
        $addresses_differ = $metadata['addresses_differ'] ?? false;

        if ($addresses_differ && !empty($billing_location)) {
            $address_display = sprintf(
                '%s <br><span style="color: #d63638;" title="Billing address differs">📍</span> Bill: %s',
                $shipping_location,
                $billing_location
            );
        } else {
            $address_display = $shipping_location;
        }

        $title = sprintf(
            '<div style="display: flex; justify-content: space-between; align-items: center;">
                <div>#%s %s %s</div>
                <div><span style="font-weight: bold;">$%s</span> <span id="past_orders"><span class="order-status-%s">%s</span></span></div>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="color:grey;">%s - %s</div>
                <div style="color:grey;">%s</div>
            </div>',
            $metadata['number'],
            $metadata['first_name'],
            $metadata['last_name'],
            $metadata['total'],
            $metadata['status'],
            wc_get_order_status_name($metadata['status']),
            date('d M Y', strtotime($metadata['date'])),
            date('g:ia', strtotime($metadata['date'])),
            $address_display
        );

        return ['title' => $title, 'url' => get_edit_post_link($entity_id)];
    }

    private static function format_customer_result_for_display($metadata, $entity_id) {
        $user = get_userdata($entity_id);
        $customer_type = '';
        if ($user && in_array('wholesale_buyer', $user->roles)) {
            $customer_type = '<span id="past_orders"><span class="order-status-wholesale">Wholesale</span></span>';
        } elseif ($user && in_array('wholesale_buyer_pending', $user->roles)) {
            $customer_type = '<span id="past_orders"><span class="order-status-wholesale">Wholesale Pending</span></span>';
        } else {
            $customer_type = '<span id="past_orders"><span class="order-status-customer">Customer</span></span>';
        }

        $city = $metadata['city'] ?? '';
        $state = $metadata['state'] ?? '';
        $email = $metadata['email'] ?? ($user ? $user->user_email : '');
        $location_or_email = (trim($city) !== '' || trim($state) !== '')
            ? trim($city . ', ' . $state, ', ')
            : $email;

        $title = sprintf(
            '<div style="display: flex; justify-content: space-between; align-items: center;">
                <div>%s %s %s</div>
                <div>%s</div>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="color:grey;"><span style="font-weight: bold;">%d orders</span> - Since %s</div>
                <div style="color:grey;">%s</div>
            </div>',
            $metadata['first_name'],
            $metadata['last_name'],
            $metadata['company'] ? "({$metadata['company']})" : '',
            $customer_type,
            $metadata['order_count'],
            $metadata['registration_date_formatted'],
            esc_html($location_or_email)
        );

        return ['title' => $title, 'url' => get_edit_user_link($entity_id)];
    }

    /*==========================================================================
     * MENU DATA FOR CLIENT-SIDE SEARCH
     *
     * Returns all visible admin menu items as a JSON-serialisable array so the
     * frontend can filter locally with no AJAX/DB. Same shape as menu results
     * from handle_search_menus (type, id, parent, menu_parent, title, url, slug).
     ==========================================================================*/
    public static function get_menu_data_for_js() {
        $menu_items = self::get_cached_admin_menu_items();
        $hidden = self::get_hidden_menu_keys();
        $out = [];

        if (!empty($menu_items)) {
            foreach ($menu_items as $item) {
                if (in_array($item['key'], $hidden, true)) {
                    continue;
                }
                $parent_display = $item['parent'] ? $item['parent'] : 'Top level';
                $out[] = [
                    'type'        => 'menu',
                    'id'          => $item['key'],
                    'parent'      => $parent_display,
                    'menu_parent'=> $parent_display,
                    'title'       => $item['title'],
                    'url'         => $item['url'],
                    'slug'        => isset($item['slug']) ? $item['slug'] : '',
                ];
            }
        }

        return $out;
    }

    /*==========================================================================
     * ORDER STATUSES FOR CLIENT-SIDE SEARCH
     *
     * Returns WooCommerce order statuses as links to filter the orders list.
     * Used in Quick Find to show status filters (Completed, Processing, Draft, etc.)
     * before order results.
     ==========================================================================*/
    public static function get_order_statuses_for_js() {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order_statuses')) {
            return [];
        }

        $statuses = wc_get_order_statuses();
        if (empty($statuses) || !is_array($statuses)) {
            return [];
        }

        $orders_base = admin_url('edit.php?post_type=shop_order');
        $out = [
            [
                'type'  => 'order_status',
                'title' => __('All Orders', 'a-neater-woocommerce-admin'),
                'url'   => $orders_base,
                'slug'  => 'all',
            ],
        ];

        $display_order = ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-checkout-draft', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'trash'];

        foreach ($display_order as $slug) {
            if (!isset($statuses[$slug])) {
                continue;
            }
            $out[] = [
                'type'  => 'order_status',
                'title' => $statuses[$slug],
                'url'   => add_query_arg('post_status', $slug, $orders_base),
                'slug'  => $slug,
            ];
        }

        foreach ($statuses as $slug => $label) {
            if (in_array($slug, $display_order, true)) {
                continue;
            }
            $out[] = [
                'type'  => 'order_status',
                'title' => $label,
                'url'   => add_query_arg('post_status', $slug, $orders_base),
                'slug'  => $slug,
            ];
        }

        return $out;
    }

    /*==========================================================================
     * ORDERS + CUSTOMERS + PRODUCTS PRELOAD FOR JS
     *
     * Returns latest orders, customers, and products for client-side Quick Find
     * search. Same shape as handle_search results plus 'search' (plain text)
     * for local filtering.
     ==========================================================================*/
    public static function get_orders_customers_data_for_js() {
        $meta = get_option('ewneater_quick_find_meta');
        if (!$meta || empty($meta['status']) || $meta['status'] !== 'complete') {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        $order_limit = 300;
        $customer_limit = 200;
        $product_limit = 200;

        $order_sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE type = 'order' ORDER BY JSON_EXTRACT(metadata, '$.date') DESC LIMIT %d",
            $order_limit
        );
        $orders = $wpdb->get_results($order_sql, ARRAY_A);

        $customer_sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE type = 'user' ORDER BY JSON_EXTRACT(metadata, '$.registration_date') DESC LIMIT %d",
            $customer_limit
        );
        $customers = $wpdb->get_results($customer_sql, ARRAY_A);

        $out = [];

        if ($orders) {
            foreach ($orders as $order) {
                if (get_post_type($order['entity_id']) !== 'shop_order') {
                    continue;
                }
                $metadata = json_decode($order['metadata'], true);
                if (!is_array($metadata)) {
                    continue;
                }
                $formatted = self::format_order_result_for_display($metadata, $order['entity_id']);
                $out[] = [
                    'type'   => 'order',
                    'id'     => (int) $order['entity_id'],
                    'title'  => $formatted['title'],
                    'url'    => $formatted['url'],
                    'search' => isset($order['search_text']) ? $order['search_text'] : '',
                ];
            }
        }

        if ($customers) {
            foreach ($customers as $customer) {
                $metadata = json_decode($customer['metadata'], true);
                if (!is_array($metadata)) {
                    continue;
                }
                $formatted = self::format_customer_result_for_display($metadata, $customer['entity_id']);
                $out[] = [
                    'type'   => 'user',
                    'id'     => (int) $customer['entity_id'],
                    'title'  => $formatted['title'],
                    'url'    => $formatted['url'],
                    'search' => isset($customer['search_text']) ? $customer['search_text'] : '',
                ];
            }
        }

        // Preload products for instant client-side search (from wp_posts; not in search index)
        if (class_exists('WooCommerce')) {
            $posts_table = $wpdb->posts;
            $meta_table = $wpdb->postmeta;
            $product_sql = $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_status
                FROM {$posts_table} p
                LEFT JOIN {$meta_table} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
                ORDER BY (CASE WHEN p.post_status = 'publish' THEN 0 ELSE 1 END), p.post_modified DESC
                LIMIT %d",
                $product_limit
            );
            $products = $wpdb->get_results($product_sql);

            if ($products) {
                foreach ($products as $row) {
                    $product = wc_get_product($row->ID);
                    $sku = $product ? $product->get_sku() : '';
                    $stock_status = $product ? $product->get_stock_status() : 'outofstock';
                    $stock_label = $stock_status === 'instock' ? 'In Stock' : ($stock_status === 'onbackorder' ? 'On backorder' : 'Out of Stock');
                    $stock_class = 'ewneater-stock-' . $stock_status;
                    $status_slug = $row->post_status;
                    $status_label = $status_slug === 'publish' ? 'Published' : ucfirst($status_slug);
                    $status_class = $status_slug === 'publish' ? 'order-status-completed' : 'order-status-' . $status_slug;
                    $title_display = esc_html($row->post_title);
                    if ($sku) {
                        $title_display .= ' <span class="details">(' . esc_html($sku) . ')</span>';
                    }
                    // Hide "In Stock" for now; show Out of Stock / On backorder only
                    // $title_display .= ' <span id="past_orders"><span class="' . esc_attr($stock_class) . '">' . esc_html($stock_label) . '</span></span>';
                    if ($stock_status !== 'instock') {
                        $title_display .= ' <span id="past_orders"><span class="' . esc_attr($stock_class) . '">' . esc_html($stock_label) . '</span></span>';
                    }
                    $title_display .= ' <span id="past_orders"><span class="' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></span>';
                    $search_parts = array_filter([$row->post_title, $sku]);
                    $out[] = [
                        'type'      => 'product',
                        'id'        => (int) $row->ID,
                        'title'     => $title_display,
                        'url'       => get_edit_post_link($row->ID),
                        'view_url'  => get_permalink($row->ID),
                        'search'    => implode(' ', $search_parts),
                    ];
                }
            }
        }

        return $out;
    }

    /*==========================================================================
     * ADMIN MENUS VISIBILITY PAGE
     *
     * Displays all admin menus and submenus with tickbox visibility controls
     * Stores user preferences for showing/hiding items in Quick Find
     ==========================================================================*/
    public static function display_admin_menus_visibility_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $menu_items = self::$admin_menu_items_for_display !== null
            ? self::$admin_menu_items_for_display
            : self::get_admin_menu_items();
        $visibility = get_option('ewneater_quick_find_menu_visibility', []);
        $hidden_items = [];

        if (isset($visibility['hidden']) && is_array($visibility['hidden'])) {
            $hidden_items = $visibility['hidden'];
        }

        $notice = '';

        if (isset($_POST['ewneater_quick_find_menu_visibility_nonce'])) {
            if (!check_admin_referer('ewneater_quick_find_menu_visibility', 'ewneater_quick_find_menu_visibility_nonce')) {
                wp_die('Security check failed');
            }

            $submitted_visibility = [];
            if (isset($_POST['menu_visibility']) && is_array($_POST['menu_visibility'])) {
                $submitted_visibility = array_map('sanitize_text_field', wp_unslash($_POST['menu_visibility']));
            }

            $hidden_items = [];
            foreach ($menu_items as $menu_item) {
                $key = $menu_item['key'];
                if (!isset($submitted_visibility[$key])) {
                    $hidden_items[] = $key;
                }
            }

            $visibility = [
                'hidden' => $hidden_items,
                'updated' => current_time('mysql'),
            ];

            update_option('ewneater_quick_find_menu_visibility', $visibility);
            $notice = 'Menu visibility saved.';
        }

        echo '<div class="wrap ewneater-dashboard-wrap ewneater-admin-page--full-width">';
        if (function_exists('ewneater_admin_page_styles')) {
            ewneater_admin_page_styles();
        }
        echo '<h1 class="ewneater-dash-title">';
        if (function_exists('ewneater_admin_breadcrumb')) {
            ewneater_admin_breadcrumb(__('Quick Find Menus', 'a-neater-woocommerce-admin'));
        } else {
            echo esc_html__('Quick Find Menus', 'a-neater-woocommerce-admin');
        }
        echo '</h1>';
        echo '<p class="ewneater-dash-intro">Select which admin menus and submenus should appear in Quick Find results. If a menu (e.g. Redirections under Tools) does not appear when you search, ensure it is checked below and click Save.</p>';

        if (!empty($notice)) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<form id="ewneater-quick-find-menu-form" method="post" action="">';
        wp_nonce_field('ewneater_quick_find_menu_visibility', 'ewneater_quick_find_menu_visibility_nonce');

        echo '<p class="ewneater-admin-search-wrap ewneater-quick-find-menus-search-wrap" style="margin-bottom: 14px;">';
        echo '<label for="ewneater-quick-find-menus-filter" style="margin-right: 8px; font-weight: 600;">Filter this list:</label>';
        echo '<input type="search" id="ewneater-quick-find-menus-filter" class="ewneater-quick-find-menus-filter" placeholder="Type to hide or show rows by menu, parent, or slug…" style="width: 320px; padding: 6px 10px;" autocomplete="off" />';
        echo '</p>';
        echo '<style>.ewneater-quick-find-menus-table tbody tr.ewneater-filter-hidden { display: none !important; }</style>';

        echo '<table class="ewneater-admin-table widefat striped ewneater-quick-find-menus-table">';
        echo '<thead><tr>';
        echo '<th style="width: 70px;">Show</th>';
        echo '<th>Menu</th>';
        echo '<th>Parent</th>';
        echo '<th>Slug</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $grouped_menu_items = [];
        foreach ($menu_items as $menu_item) {
            $parent_label = $menu_item['parent'] ? $menu_item['parent'] : 'Top level';
            if (!isset($grouped_menu_items[$parent_label])) {
                $grouped_menu_items[$parent_label] = [];
            }
            $grouped_menu_items[$parent_label][] = $menu_item;
        }

        ksort($grouped_menu_items, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($grouped_menu_items as $parent_label => $items) {
            usort($items, function($a, $b) {
                return strcasecmp($a['title'], $b['title']);
            });

            $heading_search = strtolower($parent_label);
            echo '<tr class="ewneater-quick-find-menu-heading" data-search="' . esc_attr($heading_search) . '">';
            echo '<td colspan="4">' . esc_html($parent_label) . '</td>';
            echo '</tr>';

            foreach ($items as $menu_item) {
                $checked = !in_array($menu_item['key'], $hidden_items, true);
                $indent_style = $menu_item['parent'] ? 'padding-left:18px;' : '';
                $row_search = strtolower($menu_item['title'] . ' ' . $menu_item['parent'] . ' ' . $menu_item['slug']);
                echo '<tr class="ewneater-quick-find-menu-row" data-search="' . esc_attr($row_search) . '">';
                echo '<td>';
                echo '<input type="checkbox" name="menu_visibility[' . esc_attr($menu_item['key']) . ']" value="1" ' . checked($checked, true, false) . ' />';
                echo '</td>';
                echo '<td style="' . esc_attr($indent_style) . '">' . esc_html($menu_item['title']) . '</td>';
                echo '<td>' . esc_html($menu_item['parent']) . '</td>';
                echo '<td><code>' . esc_html($menu_item['slug']) . '</code></td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';

        echo '<div class="ewneater-admin-actions ewneater-quick-find-menu-save">';
        echo '<button type="submit" id="ewneater-quick-find-menu-save" class="button button-primary" style="display: none;">Save Changes</button>';
        echo '<button type="submit" class="button button-primary">Save Changes</button>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=ewneater-search-index')) . '" class="ewneater-quick-find-menu-index-link">Quick Find Index</a>';
        echo '</div>';
        echo '<script>
            jQuery(document).ready(function($) {
                var $form = $(\"#ewneater-quick-find-menu-form\");
                var $saveButton = $(\"#ewneater-quick-find-menu-save\");

                if (!$form.length) {
                    return;
                }

                var $checkboxes = $form.find(\"input[type=\\\"checkbox\\\"]\");

                $checkboxes.each(function() {
                    $(this).data(\"initial\", this.checked ? \"1\" : \"0\");
                });

                function isDirty() {
                    var dirty = false;
                    $checkboxes.each(function() {
                        if ($(this).data(\"initial\") !== (this.checked ? \"1\" : \"0\")) {
                            dirty = true;
                            return false;
                        }
                    });
                    return dirty;
                }

                function updateSaveButton() {
                    if (isDirty()) {
                        $saveButton.show().prop(\"disabled\", false);
                    } else {
                        $saveButton.hide().prop(\"disabled\", true);
                    }
                }

                $form.on(\"change\", \"input[type=\\\"checkbox\\\"]\", function() {
                    updateSaveButton();
                });

                updateSaveButton();
            });
        </script>';
        echo '</form>';
        echo '</div>';
    }

    /*==========================================================================
     * ADMIN MENU DATA
     *
     * Builds a list of admin menus and submenus for visibility controls
     ==========================================================================*/
    private static function get_admin_menu_items() {
        global $menu, $submenu;

        $items = [];
        $menu_titles = [];

        if (!is_array($menu)) {
            return $items;
        }

        foreach ($menu as $menu_item) {
            if (!is_array($menu_item) || empty($menu_item[2])) {
                continue;
            }

            $title = self::sanitize_menu_title($menu_item[0]);
            $slug = $menu_item[2];
            $key = self::get_menu_item_key('menu', '', $slug);

            $menu_titles[$slug] = $title;
            $items[] = [
                'key' => $key,
                'title' => $title,
                'parent' => '',
                'slug' => $slug,
                'url' => self::get_admin_menu_url($slug),
            ];
        }

        if (is_array($submenu)) {
            foreach ($submenu as $parent_slug => $submenu_items) {
                if (!is_array($submenu_items)) {
                    continue;
                }

                $parent_title = isset($menu_titles[$parent_slug]) ? $menu_titles[$parent_slug] : $parent_slug;

                foreach ($submenu_items as $submenu_item) {
                    if (!is_array($submenu_item) || empty($submenu_item[2])) {
                        continue;
                    }

                    $title = self::sanitize_menu_title($submenu_item[0]);
                    $slug = $submenu_item[2];
                    $key = self::get_menu_item_key('submenu', $parent_slug, $slug);

                    $items[] = [
                        'key' => $key,
                        'title' => $title,
                        'parent' => $parent_title,
                        'slug' => $slug,
                        'url' => self::get_admin_menu_url($slug, $parent_slug),
                    ];
                }
            }
        }

        return $items;
    }

    /*==========================================================================
     * MENU HELPERS
     *
     * Normalizes menu titles, keys, and URLs
     ==========================================================================*/
    private static function sanitize_menu_title($title) {
        $title = wp_strip_all_tags($title);
        $title = preg_replace('/\s*\(.*?\)\s*/', ' ', $title);
        $title = preg_replace('/&amp;/', '&', $title);

        return trim($title);
    }

    private static function get_menu_item_key($type, $parent_slug, $slug) {
        $key = $type . '_' . $parent_slug . '_' . $slug;
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_]/', '_', $key);
        $key = preg_replace('/_+/', '_', $key);

        return trim($key, '_');
    }

    /**
     * Build admin URL for a menu item.
     * Submenus under Tools (tools.php), Options (options.php), etc. use parent?page=slug.
     * Without this, all such plugins would get wrong links (e.g. /wp-admin/redirection.php
     * instead of /wp-admin/tools.php?page=redirection.php). We pass parent_slug when
     * building submenu items so every plugin under those parents gets the correct URL.
     */
    private static function get_admin_menu_url($slug, $parent_slug = '') {
        if (strpos($slug, 'http://') === 0 || strpos($slug, 'https://') === 0) {
            return $slug;
        }

        $slug = ltrim($slug, '/');

        if (strpos($slug, 'admin.php?page=') === 0) {
            return admin_url($slug);
        }

        // Submenus where slug equals parent (e.g. Appearance > Themes) load the parent file directly
        if ($parent_slug !== '' && $slug === $parent_slug) {
            return admin_url($slug);
        }

        // Submenus under tools.php, options.php, etc. use parent?page=slug (covers Redirections and all similar plugins)
        if ($parent_slug !== '' && preg_match('/\.php$/', $parent_slug) === 1 && $parent_slug !== 'admin.php') {
            return admin_url($parent_slug . '?page=' . $slug);
        }

        if (preg_match('/\.php(\?|$)/', $slug) === 1) {
            return admin_url($slug);
        }

        return admin_url('admin.php?page=' . $slug);
    }

    
    
    /*==========================================================================
     * SEARCH INDEX MANAGEMENT PAGE
     *
     * Displays the search index management interface with:
     * - Current index status and statistics
     * - Manual rebuild options
     * - Latest indexed entries
     * - Cron job scheduling information
     ==========================================================================*/
    public static function display_search_index_page() {
        // Include the logger if not already included
        if (!class_exists('EWNeater_Quick_Find_Logger')) {
            require_once dirname(__FILE__) . '/quickfind-logger.php';
        }

        // Get current index status
        $meta = get_option('ewneater_quick_find_meta');
        $status = $meta ? $meta['status'] : 'not_built';
        $last_updated = $meta ? $meta['last_updated'] : 'Never';

        // Get logging status
        $logging_enabled = get_option('ewneater_quick_find_logging_enabled', 'yes') === 'yes';

        // Get results per section (orders, customers, products)
        $results_per_section = max(1, min(50, (int) get_option('ewneater_quick_find_results_per_section', 3)));

        $show_on_frontend = get_option('ewneater_quick_find_show_on_frontend', 'no') === 'yes';

        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Get counts from the table
        $total_orders = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE type = 'order'");
        $total_customers = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE type = 'user'");

        // Get latest entries or search results
        $admin_index_query = isset($_GET['ewneater_q']) ? sanitize_text_field(wp_unslash($_GET['ewneater_q'])) : '';
        if ($admin_index_query !== '') {
            $like_terms = [];
            $conditions = [];
            
            // Check if search term contains @ (email-like search)
            $is_email_like = strpos($admin_index_query, '@') !== false;
            
            if ($is_email_like) {
                // For email searches, require the full email string to be present
                // This prevents partial matches like "nimbincraftgallery" matching "nimbincraftgalleryonline@gmail.com"
                $exact_like = '%' . $wpdb->esc_like($admin_index_query) . '%';
                $conditions[] = "(search_text LIKE %s OR metadata LIKE %s)";
                $like_terms[] = $exact_like;
                $like_terms[] = $exact_like;
                
                // Build SQL with exact match prioritization
                $sql = "SELECT *, 
                        CASE 
                            WHEN search_text LIKE %s OR metadata LIKE %s THEN 1 
                            ELSE 2 
                        END as match_priority
                        FROM $table 
                        WHERE " . implode(' OR ', $conditions) . " 
                        ORDER BY match_priority ASC, last_updated DESC 
                        LIMIT 100";
                $prepare_args = array_merge([$sql], array_merge($like_terms, [$exact_like, $exact_like]));
            } else {
                // Split search term into individual words for multi-term search
                $search_terms = self::split_search_terms($admin_index_query);
                
                // Build conditions for each term with AND logic
                $term_condition_groups = [];
                
                foreach ($search_terms as $term) {
                    // Build multiple LIKE variants for each term (for currency normalization)
                    $normalized_currency = preg_replace('/[^0-9.,]/', '', $term);
                    $normalized_commas_removed = str_replace(',', '', $normalized_currency);
                    
                    // Check if search contains dollar sign (price search)
                    $has_dollar_sign = strpos($term, '$') !== false;
                    
                    // Check if search is numeric (could be order ID)
                    $is_numeric = is_numeric($term);
                    $numeric_value = $is_numeric ? intval($term) : null;

                    $variants = array_unique(array_filter([
                        $term,
                        $normalized_currency !== $term && $normalized_currency !== '' ? $normalized_currency : null,
                        $normalized_commas_removed !== $normalized_currency && $normalized_commas_removed !== '' ? $normalized_commas_removed : null,
                    ]));

                    // For each term, create OR conditions (variants), but all terms must match (AND)
                    $term_conditions = [];
                    foreach ($variants as $variant) {
                        $term_conditions[] = "(search_text LIKE %s OR metadata LIKE %s)";
                        $like = '%' . $wpdb->esc_like($variant) . '%';
                        $like_terms[] = $like;
                        $like_terms[] = $like;
                    }
                    
                    if (!empty($term_conditions)) {
                        $term_condition_groups[] = '(' . implode(' OR ', $term_conditions) . ')';
                    }
                }

                if (empty($term_condition_groups)) {
                    $term_condition_groups[] = "(search_text LIKE %s OR metadata LIKE %s)";
                    $like = '%' . $wpdb->esc_like($admin_index_query) . '%';
                    $like_terms[] = $like;
                    $like_terms[] = $like;
                }

                // Build priority-based SQL query
                $priority_cases = [];
                $priority_params = [];
                
                // Priority 1: If numeric search, prioritize exact entity_id matches (order ID)
                // Check if first term is numeric
                $first_term = !empty($search_terms) ? $search_terms[0] : $admin_index_query;
                $is_numeric_first = is_numeric($first_term);
                $numeric_value_first = $is_numeric_first ? intval($first_term) : null;
                
                if ($is_numeric_first && $numeric_value_first) {
                    $priority_cases[] = "WHEN entity_id = %d THEN 1";
                    $priority_params[] = $numeric_value_first;
                }
                
                // Priority 2: If search contains $, prioritize price matches in search_text
                // Check if any term contains dollar sign
                $has_dollar_sign_any = false;
                $normalized_commas_removed_any = '';
                foreach ($search_terms as $term_check) {
                    if (strpos($term_check, '$') !== false) {
                        $has_dollar_sign_any = true;
                        $normalized_currency_check = preg_replace('/[^0-9.,]/', '', $term_check);
                        $normalized_commas_removed_any = str_replace(',', '', $normalized_currency_check);
                        break;
                    }
                }
                
                if ($has_dollar_sign_any && $normalized_commas_removed_any !== '') {
                    $price_int = $normalized_commas_removed_any;
                    $price_decimal = $normalized_commas_removed_any . '.00';
                    $price_like_int = '%' . $wpdb->esc_like($price_int) . '%';
                    $price_like_decimal = '%' . $wpdb->esc_like($price_decimal) . '%';
                    $priority_cases[] = "WHEN search_text LIKE %s OR search_text LIKE %s THEN 2";
                    $priority_params[] = $price_like_int;
                    $priority_params[] = $price_like_decimal;
                }
                
                // Priority 3: All other matches
                if (!empty($priority_cases)) {
                    $priority_sql = "CASE " . implode(' ', $priority_cases) . " ELSE 3 END as match_priority";
                    $sql = "SELECT *, " . $priority_sql . " FROM $table WHERE " . implode(' AND ', $term_condition_groups) . " ORDER BY match_priority ASC, last_updated DESC LIMIT 100";
                    $prepare_args = array_merge([$sql], array_merge($priority_params, $like_terms));
                } else {
                    $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $term_condition_groups) . " ORDER BY last_updated DESC LIMIT 100";
                    $prepare_args = array_merge([$sql], $like_terms);
                }
            }
            
            $prepared_sql = call_user_func_array([$wpdb, 'prepare'], $prepare_args);
            $latest_entries = $wpdb->get_results($prepared_sql, ARRAY_A);
        } else {
            $latest_entries = $wpdb->get_results(
                "SELECT * FROM $table ORDER BY last_updated DESC LIMIT 20",
                ARRAY_A
            );
        }

        // Process form submissions
        // Ajax logging toggle is handled separately

        // Formats a seconds value into a human-readable string: e.g. 45s, 3m 12s, 1h 4m
        $format_eta = function(int $seconds): string {
            if ($seconds <= 0) {
                return '';
            }
            if ($seconds < 60) {
                return $seconds . 's';
            }
            if ($seconds < 3600) {
                $m = (int) floor($seconds / 60);
                $s = $seconds % 60;
                return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
            }
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds % 3600) / 60);
            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        };

        // Get current progress for PHP-rendered initial state
        $build_progress    = self::get_quick_find_progress();
        $is_processing     = $build_progress['status'] === 'processing';
        $init_percent      = $build_progress['progress_percent'];
        $init_phase        = $build_progress['phase'];
        $init_processed    = $build_progress['processed'];
        $init_total        = $build_progress['total'];
        $init_eta          = $build_progress['estimated_remaining'];

        // Build the initial status label shown before JS takes over
        if ($is_processing) {
            if ($init_total > 0) {
                $phase_label     = $init_phase === 'customers' ? 'customers' : 'orders';
                $init_status_str = sprintf(
                    'Processing – %s: %s / %s (%.1f%%)',
                    $phase_label,
                    number_format($init_processed),
                    number_format($init_total),
                    $init_percent
                );
            } else {
                $init_status_str = 'Processing';
            }
        } elseif ($status === 'complete') {
            $init_status_str = 'Complete';
        } elseif ($status === 'pending') {
            $scheduled_at   = $build_progress['scheduled_at'] ?? 0;
            $remaining      = $scheduled_at > 0 ? max(0, $scheduled_at - time()) : 0;
            if ($remaining > 0) {
                $init_status_str = $remaining < 60
                    ? sprintf('Pending – Rebuild in %ds', $remaining)
                    : sprintf('Pending – Rebuild in %dm %ds', (int) floor($remaining / 60), $remaining % 60);
            } else {
                $init_status_str = 'Pending – Starting soon…';
            }
        } else {
            $init_status_str = ucfirst($status);
        }

        $init_scheduled_at = ($status === 'pending') ? (int) ($build_progress['scheduled_at'] ?? 0) : 0;

        ?>
        <div class="wrap ewneater-dashboard-wrap ewneater-admin-page--full-width">
        <?php
        if (function_exists('ewneater_admin_page_styles')) {
            ewneater_admin_page_styles();
        }
        ?>
            <h1 class="ewneater-dash-title"><?php
                if (function_exists('ewneater_admin_breadcrumb')) {
                    ewneater_admin_breadcrumb(__('Quick Find Index', 'a-neater-woocommerce-admin'));
                } else {
                    echo esc_html__('Quick Find Index', 'a-neater-woocommerce-admin');
                }
                ?></h1>

            <div class="ewneater-admin-section status-actions-container" style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">

            <?php if ($status === 'not_built' || ($status !== 'processing' && (int)$total_orders === 0 && (int)$total_customers === 0)): ?>
            <div class="ewneater-dash-card card ewneater-index-empty-notice" style="flex: 1 1 100%; border-left-color: #f0b429;">
                <h2 style="color: #92400e; border-bottom-color: #fde68a;">Quick Find is not ready yet</h2>
                <p style="font-size: 14px; margin: 0 0 14px;">The search index is empty. Quick Find needs to index your orders and customers before you can search from the admin bar.</p>
                <p style="margin: 0;">
                    <button id="rebuild-index-cta" class="button button-primary" style="font-size: 14px; height: auto; padding: 8px 18px;">Build Index Now</button>
                    <span style="margin-left: 12px; color: #78350f; font-size: 13px;">This only needs to be done once.</span>
                </p>
            </div>
            <?php endif; ?>
                <div class="ewneater-dash-card card" style="flex: 1;">
                    <h2>Index Status</h2>
                    <strong>Status:</strong> <span id="ewneater-status-text"><?php echo esc_html($init_status_str); ?></span><br>
                    <strong>Last Updated:</strong> <?php echo esc_html($last_updated); ?><br>
                    <strong>Indexed Orders:</strong> <span id="ewneater-indexed-orders"><?php echo number_format($total_orders); ?></span><br>
                    <strong>Indexed Customers:</strong> <span id="ewneater-indexed-customers"><?php echo number_format($total_customers); ?></span><br>

                    <div id="ewneater-index-progress" style="<?php echo $is_processing ? '' : 'display:none;'; ?> margin-top: 14px;">
                        <div class="ewneater-progress-bar-container">
                            <div class="ewneater-progress-bar" id="ewneater-progress-bar" style="width: <?php echo esc_attr($init_percent); ?>%"></div>
                        </div>
                        <div id="ewneater-progress-status" class="ewneater-progress-status"><?php
                            if ($is_processing && $init_total > 0) {
                                $phase_label = $init_phase === 'customers' ? 'customers' : 'orders';
                                $eta_str     = $init_eta > 0 ? ' – Est. ' . $format_eta($init_eta) . ' remaining' : '';
                                echo esc_html(sprintf(
                                    'Indexing %s: %s / %s (%.1f%%)%s',
                                    $phase_label,
                                    number_format($init_processed),
                                    number_format($init_total),
                                    $init_percent,
                                    $eta_str
                                ));
                            }
                        ?></div>
                    </div>

                    <h3 style="margin-top: 15px;">Logging?</h3>
                    <label>
                        <input type="checkbox" id="logging_enabled" <?php checked($logging_enabled); ?>>
                        Enable Logging
                    </label>
                    <div id="logging_status" style="margin-top: 5px; color: #46b450; font-size: 12px; display: none;">
                        ✓ Setting saved
                    </div>
                    <p class="description">When enabled, activities are logged to <a href="<?php echo admin_url('admin.php?page=wc-status&tab=logs'); ?>">WooCommerce > Status > Logs</a>.</p>

                    <h3 style="margin-top: 15px;">Results per section</h3>
                    <label>
                        <select id="results_per_section">
                            <?php
                            $limit_options = [3, 5, 10, 15, 20, 25];
                            if (!in_array($results_per_section, $limit_options, true)) {
                                $limit_options[] = $results_per_section;
                                sort($limit_options);
                            }
                            foreach ($limit_options as $n):
                            ?>
                                <option value="<?php echo (int) $n; ?>" <?php selected($results_per_section, $n); ?>><?php echo (int) $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                        orders / customers / products
                    </label>
                    <div id="results_per_section_status" style="margin-top: 5px; color: #46b450; font-size: 12px; display: none;">
                        ✓ Setting saved
                    </div>
                    <p class="description">How many results to show per section in the Quick Find dropdown.</p>

                    <h3 style="margin-top: 15px;"><?php esc_html_e('Quick Find on public site', 'a-neater-woocommerce-admin'); ?></h3>
                    <label>
                        <input type="checkbox" id="show_on_frontend" <?php checked($show_on_frontend); ?>>
                        <?php esc_html_e('Show Quick Find when browsing the public site (admins only)', 'a-neater-woocommerce-admin'); ?>
                    </label>
                    <div id="show_on_frontend_status" style="margin-top: 5px; color: #46b450; font-size: 12px; display: none;">
                        ✓ <?php esc_html_e('Setting saved', 'a-neater-woocommerce-admin'); ?>
                    </div>
                    <p class="description"><?php esc_html_e('Allows admins to search orders, customers, and products from the frontend without going to wp-admin.', 'a-neater-woocommerce-admin'); ?></p>
                </div>

                <div class="ewneater-dash-card card" style="flex: 1;">
                    <h2>Index Actions</h2>
                    <p class="description">The search index updates in real-time for new orders and customers. Use these buttons to manually rebuild the index if needed.</p>
                    <?php if ($is_processing): ?>
                    <p class="description" style="color: #1f7a4a; font-style: italic;">A build is currently running — buttons will re-enable when complete.</p>
                    <?php endif; ?>
                    <p>
                        <button id="rebuild-index" class="button button-primary index-action-button" <?php disabled($is_processing); ?>>Rebuild Index</button>
                        <button id="clear-index" class="button index-action-button" <?php disabled($is_processing); ?>>Clear Index</button>
                        <hr>
                        <button id="rebuild-orders-index" class="button index-action-button" <?php disabled($is_processing); ?>>Orders Only</button>
                        <button id="rebuild-customer-index" class="button index-action-button" <?php disabled($is_processing); ?>>Customers Only</button>
                    </p>
                </div>
            </div>

            <div class="ewneater-admin-section ewneater-dash-card card" id="latest-entries-table" style="max-width: none; width: 100%;">
                <h2>Latest Updated Entries</h2>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="ewneater-admin-search-wrap ewneater-entries-search-form" style="margin: 8px 0 12px; display: flex; gap: 8px; align-items: center;">
                    <input type="hidden" name="page" value="ewneater-search-index" />
                    <div class="ewneater-entries-search-field" style="position: relative; flex: 1; min-width: 0;">
                        <input type="text" name="ewneater_q" value="<?php echo esc_attr($admin_index_query); ?>" placeholder="Search index (orders & customers)..." class="regular-text ewneater-entries-search-input" style="width: 100%; padding-right: <?php echo $admin_index_query !== '' ? '30px' : '8px'; ?>; box-sizing: border-box;" />
                        <?php if ($admin_index_query !== ''): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=ewneater-search-index')); ?>" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); text-decoration: none; color: #666; font-size: 18px; line-height: 1; cursor: pointer; padding: 2px 4px; display: inline-block; width: 20px; height: 20px; text-align: center;" title="Clear search">×</a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="button">Search</button>
                </form>
                <div style="overflow-x: auto;">
                    <table class="ewneater-admin-table ewneater-quick-find-index-table wp-list-table widefat fixed striped" style="width: 100%; min-width: 600px;">
                        <thead>
                            <tr>
                                <th style="width: 60px; min-width: 60px;">id</th>
                                <th style="width: 80px; min-width: 80px;">type</th>
                                <th style="width: 100px; min-width: 100px;">entity_id</th>
                                <th style="min-width: 200px;">search_text</th>
                                <th style="width: 160px; min-width: 160px;">metadata</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($latest_entries as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['id']); ?></td>
                            <td>
                                <?php
                                $entity_id = intval($row['entity_id']);
                                $type = $row['type'];
                                
                                if ($type === 'order') {
                                    $url = admin_url('post.php?post=' . $entity_id . '&action=edit');
                                    echo '<a href="' . esc_url($url) . '">' . esc_html(ucfirst($type)) . '</a>';
                                } elseif ($type === 'user') {
                                    $url = admin_url('user-edit.php?user_id=' . $entity_id);
                                    echo '<a href="' . esc_url($url) . '">' . esc_html(ucfirst($type)) . '</a>';
                                } else {
                                    echo esc_html(ucfirst($type));
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($row['entity_id']); ?></td>
                            <td><?php echo esc_html($row['search_text']); ?></td>
                            <td>
                                <div class="metadata-preview">
                                    <?php
                                    $metadata = json_decode($row['metadata'], true);
                                    $metadata_keys = array_keys($metadata);
                                    if (!empty($metadata_keys)) {
                                        $key = $metadata_keys[0];
                                        echo esc_html($key . ': ' . $metadata[$key]) . '<br>';
                                    }
                                    ?>
                                    <span class="show-more" onclick="this.nextElementSibling.style.display='block'; this.style.display='none'; this.closest('td').style.width='50%';" style="cursor: pointer; color: blue; text-decoration: underline;">Show more</span>
                                    <div class="metadata-full" style="display: none;">
                                        <?php
                                        for ($i = 1; $i < count($metadata_keys); $i++) {
                                            $key = $metadata_keys[$i];
                                            echo esc_html($key . ': ' . $metadata[$key]) . '<br>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <script type="text/javascript">
            jQuery(document).ready(function($) {
                var initScheduledAt = <?php echo (int) $init_scheduled_at; ?>;

                // Pending countdown: update status text every second until rebuild starts
                if (initScheduledAt > 0) {
                    function fmtCountdown(s) {
                        return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + (s % 60) + 's';
                    }
                    function tickCountdown() {
                        var rem = initScheduledAt - Math.floor(Date.now() / 1000);
                        if (rem <= 0) {
                            $('#ewneater-status-text').text('Pending – Starting soon…');
                            clearInterval(countdownIv);
                            countdownIv = null;
                            startPolling();
                            return;
                        }
                        $('#ewneater-status-text').text(
                            rem < 60 ? 'Pending – Rebuild in ' + rem + 's' : 'Pending – Rebuild in ' + fmtCountdown(rem)
                        );
                    }
                    var countdownIv = setInterval(tickCountdown, 1000);
                    tickCountdown();
                }

                // Scroll to table if there's a search query
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('ewneater_q') && urlParams.get('ewneater_q') !== '') {
                    const tableElement = document.getElementById('latest-entries-table');
                    if (tableElement) {
                        setTimeout(function() {
                            tableElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 100);
                    }
                }
                
                // Shared progress-polling state
                var pollInterval      = null;
                var isPolling         = false;
                var $activeButton     = null;
                var activeOrigText    = '';
                var consecutiveErrors = 0;
                var MAX_ERRORS        = 5;

                // Format seconds into a human-readable ETA: 45s, 3m 12s, 1h 4m
                function formatEta(seconds) {
                    if (seconds <= 0) { return ''; }
                    if (seconds < 60) { return seconds + 's'; }
                    if (seconds < 3600) {
                        var m = Math.floor(seconds / 60);
                        var s = seconds % 60;
                        return s > 0 ? m + 'm ' + s + 's' : m + 'm';
                    }
                    var h = Math.floor(seconds / 3600);
                    var m = Math.floor((seconds % 3600) / 60);
                    return m > 0 ? h + 'h ' + m + 'm' : h + 'h';
                }

                // Build a readable status string from AJAX response data
                function buildStatusText(data) {
                    if (data.status !== 'processing' || data.total <= 0) {
                        return 'Processing';
                    }
                    var phaseLabel = data.phase === 'customers' ? 'customers' : 'orders';
                    var text = 'Indexing ' + phaseLabel + ': ' +
                               data.processed.toLocaleString() + ' / ' +
                               data.total.toLocaleString() +
                               ' (' + data.progress_percent + '%)';
                    if (data.estimated_remaining > 0) {
                        text += ' – Est. ' + formatEta(data.estimated_remaining) + ' remaining';
                    }
                    return text;
                }

                // Show the progress bar and start live polling every 2 seconds
                function startPolling() {
                    if (isPolling) {
                        return;
                    }
                    isPolling = true;
                    $('#ewneater-index-progress').show();
                    pollInterval = setInterval(pollStatus, 2000);
                    pollStatus();
                }

                // Stop polling and hide the progress bar
                function stopPolling(resetButtons) {
                    isPolling = false;
                    clearInterval(pollInterval);
                    pollInterval = null;
                    $('#ewneater-index-progress').hide();
                    $('#ewneater-progress-bar').css('width', '0%');
                    $('#ewneater-progress-status').text('');

                    if (resetButtons) {
                        $('.index-action-button').prop('disabled', false);
                        if ($activeButton) {
                            $activeButton.text(activeOrigText);
                            $activeButton  = null;
                            activeOrigText = '';
                        }
                    }
                }

                // Poll ewneater_check_index_status and update bar + text.
                // Transient network/timeout errors are silently retried; the build
                // runs server-side and is unaffected. After MAX_ERRORS consecutive
                // failures the page reloads so PHP can re-render the latest progress.
                function pollStatus() {
                    $.post(ajaxurl, {
                        action:   'ewneater_check_index_status',
                        security: '<?php echo wp_create_nonce('check_index_status_nonce'); ?>'
                    }, function(response) {
                        if (!response.success) {
                            consecutiveErrors++;
                            if (consecutiveErrors >= MAX_ERRORS) {
                                stopPolling(false);
                                location.reload();
                            }
                            return;
                        }

                        // Successful response — reset error counter
                        consecutiveErrors = 0;

                        var data = response.data;

                        if (data.status === 'complete') {
                            stopPolling(false);
                            location.reload();
                            return;
                        }

                        // Update progress bar width and status text
                        var pct        = data.progress_percent || 0;
                        var statusText = buildStatusText(data);
                        $('#ewneater-progress-bar').css('width', pct + '%');
                        $('#ewneater-progress-status').text(statusText);
                        $('#ewneater-status-text').text('Processing');

                        // Update indexed counts live as each phase progresses
                        if (data.phase === 'orders' && data.processed > 0) {
                            $('#ewneater-indexed-orders').text(data.processed.toLocaleString());
                        } else if (data.phase === 'customers' && data.processed > 0) {
                            $('#ewneater-indexed-customers').text(data.processed.toLocaleString());
                        }
                    }).fail(function() {
                        // Network error or timeout — server is likely mid-batch; keep polling
                        consecutiveErrors++;
                        if (consecutiveErrors >= MAX_ERRORS) {
                            stopPolling(false);
                            location.reload();
                        }
                    });
                }

                // Kick off an index action: fire the AJAX call and start polling.
                // The kick-off request can time out on large datasets (PHP runs long),
                // so treat any failure the same as pollStatus — silently keep polling.
                // The build runs server-side regardless of whether this response arrives.
                function triggerIndexAction(ajaxAction, nonce, $btn) {
                    $activeButton  = $btn;
                    activeOrigText = $btn.text();

                    $('.index-action-button').prop('disabled', true);
                    $btn.text('Processing...');
                    startPolling();

                    $.post(ajaxurl, {
                        action:   ajaxAction,
                        security: nonce
                    }, function(response) {
                        if (!response.success) {
                            // Already running or other server-side rejection — keep polling;
                            // the poll loop will reload when complete or after MAX_ERRORS
                            consecutiveErrors++;
                            if (consecutiveErrors >= MAX_ERRORS) {
                                stopPolling(false);
                                location.reload();
                            }
                        }
                        // On success the poll loop will detect 'complete' and reload
                    }).fail(function() {
                        // Timeout or network hiccup — build may still be running; keep polling
                        consecutiveErrors++;
                        if (consecutiveErrors >= MAX_ERRORS) {
                            stopPolling(false);
                            location.reload();
                        }
                    });
                }

                // Rebuild Index
                $('#rebuild-index').click(function() {
                    if (!confirm('Are you sure you want to rebuild the search index? This may take a few minutes.')) {
                        return;
                    }
                    triggerIndexAction(
                        'ewneater_rebuild_quick_find_index',
                        '<?php echo wp_create_nonce('rebuild_quick_find_index_nonce'); ?>',
                        $(this)
                    );
                });

                // Orders Only
                $('#rebuild-orders-index').click(function() {
                    if (!confirm('Rebuild the order index only? This may take a few minutes...')) {
                        return;
                    }
                    triggerIndexAction(
                        'ewneater_rebuild_order_quick_find',
                        '<?php echo wp_create_nonce('rebuild_order_quick_find_nonce'); ?>',
                        $(this)
                    );
                });

                // Customers Only
                $('#rebuild-customer-index').click(function() {
                    if (!confirm('Rebuild the customer index only? This may take a few minutes...')) {
                        return;
                    }
                    triggerIndexAction(
                        'ewneater_rebuild_customer_quick_find',
                        '<?php echo wp_create_nonce('rebuild_customer_quick_find_nonce'); ?>',
                        $(this)
                    );
                });

                // CTA "Build Index Now" button on the empty-index notice
                $('#rebuild-index-cta').click(function() {
                    $('.ewneater-index-empty-notice').hide();
                    $('#rebuild-index').click();
                });

                // On page load: if PHP says processing, start polling immediately to continue
                <?php if ($is_processing): ?>
                startPolling();
                <?php endif; ?>

                // Clear Index (no polling needed – just reload when done)
                $('#clear-index').click(function() {
                    if (!confirm('Are you sure you want to clear the search index? This will remove all indexed data.')) {
                        return;
                    }

                    const $btn = $(this);
                    $('.index-action-button').prop('disabled', true);
                    $btn.text('Clearing...');

                    $.post(ajaxurl, {
                        action:   'ewneater_clear_quick_find_index',
                        security: '<?php echo wp_create_nonce('clear_quick_find_index_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            $('.index-action-button').prop('disabled', false);
                            $btn.text('Clear Index');
                            alert('Error: ' + response.data);
                        }
                    }).fail(function() {
                        $('.index-action-button').prop('disabled', false);
                        $btn.text('Clear Index');
                        alert('An error occurred while clearing the index.');
                    });
                });

                // Logging toggle Ajax handler
                $('#logging_enabled').change(function() {
                    const isEnabled = $(this).is(':checked');

                    $.post(ajaxurl, {
                        action: 'ewneater_toggle_logging',
                        security: '<?php echo wp_create_nonce('ewneater_toggle_logging_nonce'); ?>',
                        enabled: isEnabled ? 'yes' : 'no'
                    }, function(response) {
                        if (response.success) {
                            $('#logging_status').fadeIn().delay(2000).fadeOut();
                        } else {
                            alert('Error updating logging setting: ' + response.data);
                            $('#logging_enabled').prop('checked', !isEnabled);
                        }
                    }).fail(function() {
                        alert('Failed to update logging setting');
                        $('#logging_enabled').prop('checked', !isEnabled);
                    });
                });

                // Results per section select handler
                $('#results_per_section').change(function() {
                    const $sel = $(this);
                    const value = parseInt($sel.val(), 10);
                    const prevVal = $sel.data('prev-val');
                    $sel.data('prev-val', value);

                    $.post(ajaxurl, {
                        action: 'ewneater_save_quick_find_results_per_section',
                        security: '<?php echo wp_create_nonce('ewneater_save_results_per_section_nonce'); ?>',
                        value: value
                    }, function(response) {
                        if (response.success) {
                            $('#results_per_section_status').fadeIn().delay(2000).fadeOut();
                            var $container = $('#ewneater-quick-find-results');
                            if ($container.length) {
                                $container.attr('data-results-per-section', value);
                            }
                        } else {
                            alert('Error updating results per section');
                            if (typeof prevVal !== 'undefined') $sel.val(prevVal);
                        }
                    }).fail(function() {
                        alert('Failed to update results per section');
                        if (typeof prevVal !== 'undefined') $sel.val(prevVal);
                    });
                });
                $('#results_per_section').data('prev-val', $('#results_per_section').val());

                // Show on frontend toggle Ajax handler
                $('#show_on_frontend').change(function() {
                    const isEnabled = $(this).is(':checked');

                    $.post(ajaxurl, {
                        action: 'ewneater_save_quick_find_show_on_frontend',
                        security: '<?php echo wp_create_nonce('ewneater_save_show_on_frontend_nonce'); ?>',
                        enabled: isEnabled ? 'yes' : 'no'
                    }, function(response) {
                        if (response.success) {
                            $('#show_on_frontend_status').fadeIn().delay(2000).fadeOut();
                        } else {
                            alert('Error updating setting: ' + (response.data || ''));
                            $('#show_on_frontend').prop('checked', !isEnabled);
                        }
                    }).fail(function() {
                        alert('Failed to update setting');
                        $('#show_on_frontend').prop('checked', !isEnabled);
                    });
                });

                // Don't trigger Quick Find when focus is in an editable field
                function isEditableField(el) {
                    if (!el) return false;
                    var $el = $(el);
                    if ($el.is('input, textarea, select')) return true;
                    if (el.isContentEditable) return true;
                    if ($el.closest('[contenteditable="true"]').length) return true;
                    return false;
                }

                $(document).on('keydown', function(e) {
                    if (e.key === '/' && !isEditableField(e.target)) {
                        e.preventDefault();
                        const searchInput = $('#ewneater-quick-find-input');
                        const currentValue = searchInput.val();

                        // Focus the input
                        searchInput.focus();

                        // If there's existing text, select it
                        if (currentValue) {
                            searchInput[0].setSelectionRange(0, currentValue.length);
                        }
                    }
                });

                function updateResultsPosition() {
                    const searchBox = $('#ewneater-quick-find-input');
                    const resultsContainer = $('#ewneater-quick-find-results');
                    if (window.innerWidth > 600 && searchBox.length && resultsContainer.length) {
                        const rect = searchBox[0].getBoundingClientRect();
                        resultsContainer.css({
                            left: rect.left,
                            top: rect.bottom + 2
                        });
                    } else if (resultsContainer.length) {
                        resultsContainer.css({ left: '', top: '' });
                    }
                }

                // Call this on focus, input, resize, and scroll
                searchInput.on('focus input', updateResultsPosition);
                $(window).on('resize scroll', updateResultsPosition);
            });
            </script>
        </div>
        <?php
    }

    /*==========================================================================
     * INDEX REBUILD HANDLERS
     *
     * Handles AJAX requests for:
     * - Full index rebuild
     * - Customer-only index rebuild
     * - Index clearing
     * - New entries checking
     ==========================================================================*/
    public static function handle_rebuild_index() {
        check_ajax_referer('rebuild_quick_find_index_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Prevent concurrent builds
        $meta = get_option('ewneater_quick_find_meta', []);
        if (($meta['status'] ?? '') === 'processing') {
            wp_send_json_error('Index build already in progress.');
        }

        self::build_search_index();

        wp_send_json_success('Quick Find index rebuilt successfully');
    }

    public static function handle_clear_index() {
        check_ajax_referer('clear_quick_find_index_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Delete all search index data
        $wpdb->query("TRUNCATE TABLE $table");

        // Update metadata to reflect cleared state
        $meta = [
            'last_updated' => current_time('mysql'),
            'status' => 'not_built'
        ];
        update_option('ewneater_quick_find_meta', $meta);

        wp_send_json_success('Quick Find index cleared successfully');
    }

    public static function handle_rebuild_customer_index() {
        check_ajax_referer('rebuild_customer_quick_find_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Prevent concurrent builds
        $meta = get_option('ewneater_quick_find_meta', []);
        if (($meta['status'] ?? '') === 'processing') {
            wp_send_json_error('Index build already in progress.');
        }

        self::build_customer_index_only();
        wp_send_json_success('Customer Quick Find index rebuilt successfully');
    }

    public static function handle_rebuild_order_index() {
        check_ajax_referer('rebuild_order_quick_find_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Prevent concurrent builds
        $meta = get_option('ewneater_quick_find_meta', []);
        if (($meta['status'] ?? '') === 'processing') {
            wp_send_json_error('Index build already in progress.');
        }

        self::build_order_index_only();
        wp_send_json_success('Order Quick Find index rebuilt successfully');
    }

    public static function handle_toggle_logging() {
        check_ajax_referer('ewneater_toggle_logging_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $enabled = sanitize_text_field($_POST['enabled']);

        if (!in_array($enabled, ['yes', 'no'])) {
            wp_send_json_error('Invalid value');
        }

        update_option('ewneater_quick_find_logging_enabled', $enabled);
        wp_send_json_success('Logging setting updated');
    }

    public static function handle_save_results_per_section() {
        check_ajax_referer('ewneater_save_results_per_section_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $value = isset($_POST['value']) ? (int) $_POST['value'] : 3;
        $value = max(1, min(50, $value));

        update_option('ewneater_quick_find_results_per_section', $value);
        wp_send_json_success('Results per section updated');
    }

    public static function handle_save_show_on_frontend() {
        check_ajax_referer('ewneater_save_show_on_frontend_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $value = isset($_POST['enabled']) ? sanitize_text_field(wp_unslash($_POST['enabled'])) : 'no';
        if (!in_array($value, ['yes', 'no'], true)) {
            wp_send_json_error('Invalid value');
        }

        update_option('ewneater_quick_find_show_on_frontend', $value);
        wp_send_json_success('Setting saved');
    }




    /*==========================================================================
     * SEARCH INTERFACE
     *
     * Creates and manages the search interface:
     * - Admin bar search input
     * - Results dropdown container
     * - Keyboard navigation
     * - Mobile responsiveness
     ==========================================================================*/
    public static function add_search_bar($admin_bar) {
        // On frontend, only add search bar when option is on and user is admin
        if (!is_admin() && (get_option('ewneater_quick_find_show_on_frontend', 'no') !== 'yes' || !current_user_can('manage_options'))) {
            return;
        }

        // Include the logger if not already included
        if (!class_exists('EWNeater_Quick_Find_Logger')) {
            require_once dirname(__FILE__) . '/quickfind-logger.php';
        }

        EWNeater_Quick_Find_Logger::debug('Quick Find - Adding search bar');
        $admin_bar->add_node([
            'id'    => 'ewneater-quick-find',
            'title' => '<form class="ewneater-quick-find-form" autocomplete="off" onsubmit="return false;" style="display:inline;"><input type="search" id="ewneater-quick-find-input" name="ewneater_quick_find" autocomplete="new-password" inputmode="search" role="searchbox" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="Quick find orders, customers, products & admin menus..."></form>',
            'parent' => 'root-default',
            'priority' => 9,
            'href'  => '#',
            'meta'  => [
                'class' => 'ewneater-quick-find-container'
            ]
        ]);
    }

    public static function add_search_results_container() {
        $menus_url = admin_url('admin.php?page=ewneater-quick-find-menus');
        $orders_url = admin_url('edit.php?post_type=shop_order');
        $customers_url = admin_url('users.php');
        $products_url = admin_url('edit.php?post_type=product');
        $results_per_section = max(1, min(50, (int) get_option('ewneater_quick_find_results_per_section', 3)));
        $version = defined('EWNEATER_QUICKFIND_VERSION') ? EWNEATER_QUICKFIND_VERSION : '0.0.0';
        echo '<div id="ewneater-quick-find-results" style="display: none;" data-menus-url="' . esc_attr($menus_url) . '" data-orders-url="' . esc_attr($orders_url) . '" data-customers-url="' . esc_attr($customers_url) . '" data-products-url="' . esc_attr($products_url) . '" data-results-per-section="' . esc_attr((string) $results_per_section) . '">';
        echo '<span class="ewneater-close-x">&times;</span>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=ewneater-admin')) . '" class="ewneater-version-link">A Neater Admin v' . esc_html($version) . '</a>';
        echo '<div class="results-content"></div>';
        echo '<div class="ewneater-close-bottom" style="display: none;">CLOSE</div>';
        echo '</div>';
    }

    /*==========================================================================
     * FRONTEND ASSETS
     *
     * Loads required JavaScript and CSS with features:
     * - Admin menus embedded in JS for instant client-side filter (no AJAX)
     * - Keyboard navigation (up/down/enter)
     * - Mobile-responsive design
     * - Search term highlighting
     * - Results count display
     * - Cache management
     * - Loading states and error handling
     ==========================================================================*/
    public static function enqueue_scripts( $hook_suffix = '' ) {
        $css_dir = dirname(__DIR__) . '/css';
        wp_enqueue_style(
            'ewneater-quickfind-admin',
            plugins_url('../css/quickfind-admin.css', __FILE__),
            array(),
            filemtime($css_dir . '/quickfind-admin.css')
        );
        if ( $hook_suffix && strpos($hook_suffix, 'ewneater-search-index') !== false ) {
            wp_enqueue_style(
                'ewneater-quickfind-index-admin',
                plugins_url('../css/quickfind-index-admin.css', __FILE__),
                array(),
                filemtime($css_dir . '/quickfind-index-admin.css')
            );
        }

        if ( $hook_suffix && strpos($hook_suffix, 'quick-find-menus') !== false ) {
            wp_enqueue_script(
                'ewneater-quickfind-menus-filter',
                plugins_url('../js/quickfind-menus-filter.js', __FILE__),
                array(),
                filemtime(dirname(__DIR__) . '/js/quickfind-menus-filter.js'),
                true
            );
        }

        $nonce = wp_create_nonce('ewneater-quick-find-nonce');
        $ajaxurl = admin_url('admin-ajax.php');

        // Add meta viewport tag to prevent zooming
        add_action('admin_head', function() {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">';
        });

        // Add inline JavaScript to footer (menu data embedded for instant client-side filter, no AJAX)
        add_action('admin_footer', function() use ($nonce, $ajaxurl) {
            self::output_quickfind_inline_script($nonce, $ajaxurl);
        });
    }

    /*==========================================================================
     * FRONTEND ASSETS
     *
     * Enqueues Quick Find CSS and inline script for use on the public site
     * when the admin bar is shown to admins.
     ==========================================================================*/
    public static function enqueue_scripts_frontend() {
        $css_dir = dirname(__DIR__) . '/css';
        wp_enqueue_style(
            'ewneater-quickfind-admin',
            plugins_url('../css/quickfind-admin.css', __FILE__),
            array(),
            filemtime($css_dir . '/quickfind-admin.css')
        );
        wp_enqueue_script('jquery');
        $nonce = wp_create_nonce('ewneater-quick-find-nonce');
        $ajaxurl = admin_url('admin-ajax.php');
        add_action('wp_footer', function() use ($nonce, $ajaxurl) {
            self::output_quickfind_inline_script($nonce, $ajaxurl);
        }, 10);
    }

    /**
     * Outputs the Quick Find inline script (menu data + search logic).
     * Used by both admin and frontend enqueue.
     */
    private static function output_quickfind_inline_script($nonce, $ajaxurl) {
        $menu_data = self::get_menu_data_for_js();
        $orders_customers_data = self::get_orders_customers_data_for_js();
        $order_statuses_data = self::get_order_statuses_for_js();
?>
<script>
    window.ewneaterQuickFindMenus = <?php echo json_encode($menu_data); ?>;
    window.ewneaterQuickFindOrdersCustomers = <?php echo json_encode($orders_customers_data); ?>;
    window.ewneaterQuickFindOrderStatuses = <?php echo json_encode($order_statuses_data); ?>;
    jQuery(document).ready(function($) {
        const searchInput = $('#ewneater-quick-find-input');
        const resultsContainer = $('#ewneater-quick-find-results');
        let ordersTimeout;
        let selectedIndex = -1;
        let sortedResults = [];

        const EWNEATER_QF_PROMPT_HTML = '<div class="result-item">Type at least 3 characters...<div style="color: #999; font-size: 12px; margin-top: 4px;">↑/↓ navigate • Enter select • Esc close</div></div>';

        // Shared state for totals and "Show more" (updated on each search / merge)
        let ewneaterQuickFindTotals = { total_orders: null, total_customers: null, total_products: null };
        let ewneaterQuickFindLoaded = { orders: 0, customers: 0, products: 0 };
        let ewneaterQuickFindMenusShown = null;
        let ewneaterQuickFindOC = null;
        let ewneaterQuickFindProd = null;
        let ewneaterQuickFindMerge = function() {};
        let ewneaterQuickFindTerm = '';

        // Don't trigger Quick Find when focus is in an editable field
        function isEditableField(el) {
            if (!el) return false;
            var $el = $(el);
            if ($el.is('input, textarea, select')) return true;
            if (el.isContentEditable) return true;
            if ($el.closest('[contenteditable="true"]').length) return true;
            return false;
        }

        // Add keyboard shortcut for search
        $(document).on('keydown', function(e) {
            if (e.key === '/' && !isEditableField(e.target)) {
                e.preventDefault();
                const currentValue = searchInput.val();
                searchInput.focus();
                if (currentValue) {
                    searchInput[0].setSelectionRange(0, currentValue.length);
                }
            }
        });

        // Handle keyboard navigation
        searchInput.on('keydown', function(e) {
            const results = resultsContainer.find('.result-item:not(.count)');
            const maxIndex = results.length - 1;

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateSelection();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (selectedIndex < 0) {
                    selectedIndex = 0;
                } else {
                    selectedIndex = Math.min(selectedIndex + 1, maxIndex);
                }
                updateSelection();
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                const openInNewTab = e.metaKey || e.ctrlKey;
                const toOpen = results.filter('.selected').first();
                let url = toOpen.length ? (toOpen.attr('href') || toOpen.data('url')) : null;
                if (!url) {
                    const firstWithUrl = results.filter(function() {
                        return $(this).attr('href') || $(this).data('url');
                    }).first();
                    url = firstWithUrl.length ? (firstWithUrl.attr('href') || firstWithUrl.data('url')) : null;
                }
                if (!url) {
                    const term = (searchInput.val() || '').trim();
                    if (term.length) {
                        url = '<?php echo admin_url('edit.php?post_type=shop_order&s='); ?>' + encodeURIComponent(term);
                    }
                }
                if (url) {
                    if (openInNewTab) {
                        window.open(url, '_blank');
                    } else {
                        window.location = url;
                    }
                }
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                resultsContainer.hide();
                resultsContainer.find('.ewneater-close-bottom').hide();
                searchInput.blur();
            }
        });

        // Hide results on scroll
        $(window).on('scroll', function() {
            const scrollPosition = window.scrollY;
            const windowHeight = window.innerHeight;
            const documentHeight = document.documentElement.scrollHeight;
            const oneThirdOfPage = documentHeight / 3;

            // Only hide results if we've scrolled more than 1/3 of the page
            if (scrollPosition > oneThirdOfPage) {
                resultsContainer.hide();
            }
        });

        function updateSelection() {
            const results = resultsContainer.find('.result-item:not(.count)');
            results.removeClass('selected');
            if (selectedIndex >= 0) {
                const selected = results.eq(selectedIndex);
                selected.addClass('selected');
                // Show results container when using keyboard navigation
                resultsContainer.show();
                // Scroll into view if needed
                const container = resultsContainer[0];
                const element = selected[0];
                if (element) {
                    if (element.offsetTop < container.scrollTop) {
                        container.scrollTop = element.offsetTop;
                    } else {
                        const offsetBottom = element.offsetTop + element.offsetHeight;
                        const scrollBottom = container.scrollTop + container.offsetHeight;
                        if (offsetBottom > scrollBottom) {
                            container.scrollTop = offsetBottom - container.offsetHeight;
                        }
                    }
                }
            }
        }

        // Update the focus handler
        searchInput.on('focus', function() {
            const term = searchInput.val();

            if (term.length >= 3 && sortedResults.length > 0) {
                // Show existing results
                resultsContainer.removeClass('initial-state');
                resultsContainer.find('.results-content').html(renderResults(sortedResults, term, false, ewneaterQuickFindTotals, false));
                resultsContainer.find('.ewneater-close-bottom').show();
                resultsContainer.show();
            } else if (term.length > 0 && term.length < 3) {
                resultsContainer.addClass('initial-state');
                resultsContainer.find('.results-content').html(EWNEATER_QF_PROMPT_HTML);
                resultsContainer.find('.ewneater-close-bottom').hide();
                resultsContainer.show();
            } else if (term.length === 0) {
                resultsContainer.addClass('initial-state');
                resultsContainer.find('.results-content').html(EWNEATER_QF_PROMPT_HTML);
                resultsContainer.find('.ewneater-close-bottom').hide();
                resultsContainer.show();
            }
        });

        searchInput.on('input', function() {
            clearTimeout(ordersTimeout);
            const term = $(this).val();
            selectedIndex = -1;

            // Clear existing results immediately
            sortedResults = [];
            resultsContainer.find('.results-content').empty();
            ewneaterQuickFindTotals.total_orders = ewneaterQuickFindTotals.total_customers = ewneaterQuickFindTotals.total_products = null;
            ewneaterQuickFindMenusShown = null;

            if (term.length < 3) {
                searchInput.removeClass('searching');
                resultsContainer.addClass('initial-state');
                resultsContainer.find('.results-content').html(EWNEATER_QF_PROMPT_HTML);
                resultsContainer.show();
                return;
            }

            // Show results area with stable count-row structure immediately; fire menu request right away
            resultsContainer.addClass('initial-state');
            resultsContainer.addClass('searching');
            searchInput.addClass('searching');
            resultsContainer.find('.results-content').html('<div class="result-item count ewneater-count-row"><span style="color:#666;font-style:italic;">Searching orders, customers &amp; products…</span></div>');
            resultsContainer.show();

            var menuResults = [];
            var orderStatusResults = [];
            var orderCustomerResults = [];
            var productResults = [];
            var serverAjaxCompleted = false;
            var serverAjaxInProgress = false;
            ewneaterQuickFindOC = orderCustomerResults;
            ewneaterQuickFindProd = productResults;
            ewneaterQuickFindTerm = term;

            function mergeAndRender() {
                var combined = orderStatusResults.concat(orderCustomerResults).concat(productResults).concat(menuResults);
                sortedResults = combined.sort(function(a, b) {
                    var typePriority = { order_status: 0, order: 1, user: 2, product: 3, menu: 4 };
                    var priorityA = typePriority[a.type] || 99;
                    var priorityB = typePriority[b.type] || 99;
                    if (priorityA !== priorityB) return priorityA - priorityB;
                    var termLower = term.toLowerCase();
                    var termInA = (a.title || '').toString().toLowerCase().includes(termLower);
                    var termInB = (b.title || '').toString().toLowerCase().includes(termLower);
                    if (termInA && !termInB) return -1;
                    if (!termInA && termInB) return 1;
                    return 0;
                });
                ewneaterQuickFindLoaded.orders = orderCustomerResults.filter(function(r) { return r.type === 'order'; }).length;
                ewneaterQuickFindLoaded.customers = orderCustomerResults.filter(function(r) { return r.type === 'user'; }).length;
                ewneaterQuickFindLoaded.products = productResults.length;
                ewneaterQuickFindMerge = mergeAndRender;
                var stillLoadingServer = orderCustomerResults.length === 0 && productResults.length === 0 && menuResults.length > 0;
                if (sortedResults.length) {
                    resultsContainer.removeClass('initial-state');
                    resultsContainer.removeClass('searching');
                    searchInput.removeClass('searching');
                    resultsContainer.find('.results-content').html(renderResults(sortedResults, term, stillLoadingServer, ewneaterQuickFindTotals, serverAjaxInProgress));
                    resultsContainer.find('.ewneater-close-bottom').show();
                    resultsContainer.show();
                } else if (serverAjaxCompleted) {
                    resultsContainer.addClass('initial-state');
                    resultsContainer.removeClass('searching');
                    searchInput.removeClass('searching');
                    resultsContainer.find('.results-content').html('<div class="result-item">No results found<div style="color: #999; font-size: 12px; margin-top: 4px;">↑/↓ navigate • Enter select • Esc close</div></div>');
                    resultsContainer.find('.ewneater-close-bottom').hide();
                    resultsContainer.show();
                } else {
                    resultsContainer.find('.results-content').html('<div class="result-item count ewneater-count-row"><span style="color:#666;font-style:italic;">Searching orders, customers &amp; products…</span></div>');
                    resultsContainer.show();
                }
            }

            function doneSearching() {
                if (term === searchInput.val()) {
                    searchInput.removeClass('searching');
                    resultsContainer.removeClass('searching');
                }
            }

            // Order statuses: filter embedded data by term (instant, no AJAX)
            var localOrderStatuses = window.ewneaterQuickFindOrderStatuses;
            if (localOrderStatuses && Array.isArray(localOrderStatuses) && localOrderStatuses.length > 0) {
                var termsStatus = term.trim().toLowerCase().split(/\s+/).filter(Boolean);
                for (var s = 0; s < localOrderStatuses.length; s++) {
                    var statusItem = localOrderStatuses[s];
                    var haystackStatus = ((statusItem.title || '') + ' ' + (statusItem.slug || '')).toLowerCase();
                    var matchStatus = true;
                    for (var ts = 0; ts < termsStatus.length; ts++) {
                        if (haystackStatus.indexOf(termsStatus[ts]) === -1) { matchStatus = false; break; }
                    }
                    if (matchStatus) orderStatusResults.push(statusItem);
                }
                mergeAndRender();
            }

            // Menus: use embedded data if available (instant, no AJAX/DB); otherwise fall back to AJAX
            var localMenus = window.ewneaterQuickFindMenus;
            if (localMenus && Array.isArray(localMenus) && localMenus.length > 0) {
                var terms = term.trim().toLowerCase().split(/\s+/).filter(Boolean);
                for (var i = 0; i < localMenus.length; i++) {
                    var item = localMenus[i];
                    var haystack = ((item.title || '') + ' ' + (item.parent || '') + ' ' + (item.slug || '')).toLowerCase();
                    var match = true;
                    for (var t = 0; t < terms.length; t++) {
                        if (haystack.indexOf(terms[t]) === -1) { match = false; break; }
                    }
                    if (match) menuResults.push(item);
                }
                mergeAndRender();
            } else {
                $.post('<?php echo $ajaxurl; ?>', {
                    action: 'ewneater_quick_find_menus',
                    search_term: term,
                    nonce: '<?php echo $nonce; ?>'
                }).done(function(response) {
                    if (term !== searchInput.val()) return;
                    if (response.success && response.data) menuResults = response.data;
                    mergeAndRender();
                });
            }

            // Orders/customers/products: show preload instantly if available, then run AJAX and add server results to the list
            var localOC = window.ewneaterQuickFindOrdersCustomers;
            if (localOC && Array.isArray(localOC) && localOC.length > 0) {
                var termsOC = term.trim().toLowerCase().split(/\s+/).filter(Boolean);
                for (var j = 0; j < localOC.length; j++) {
                    var oc = localOC[j];
                    var haystackOC = (oc.search || '').toLowerCase();
                    var matchOC = true;
                    for (var u = 0; u < termsOC.length; u++) {
                        if (haystackOC.indexOf(termsOC[u]) === -1) { matchOC = false; break; }
                    }
                    if (matchOC) {
                        if (oc.type === 'product') {
                            productResults.push({ type: oc.type, id: oc.id, title: oc.title, url: oc.url, view_url: oc.view_url });
                        } else {
                            orderCustomerResults.push({ type: oc.type, id: oc.id, title: oc.title, url: oc.url });
                        }
                    }
                }
                mergeAndRender();
            }

            ordersTimeout = setTimeout(function() {
                serverAjaxInProgress = true;
                mergeAndRender();
                $.post('<?php echo $ajaxurl; ?>', {
                    action: 'ewneater_quick_find',
                    search_term: term,
                    nonce: '<?php echo $nonce; ?>',
                    skip_menus: 1
                })
                .done(function(response) {
                    if (term !== searchInput.val()) return;
                    if (response.success && response.data) {
                        var payload = response.data;
                        var serverData = Array.isArray(payload) ? payload : (payload.results || []);
                        if (payload && !Array.isArray(payload)) {
                            ewneaterQuickFindTotals.total_orders = payload.total_orders != null ? payload.total_orders : null;
                            ewneaterQuickFindTotals.total_customers = payload.total_customers != null ? payload.total_customers : null;
                            ewneaterQuickFindTotals.total_products = payload.total_products != null ? payload.total_products : null;
                        }
                        var seenOC = {};
                        var seenProd = {};
                        orderCustomerResults.forEach(function(item) {
                            seenOC[item.type + '_' + item.id] = true;
                        });
                        productResults.forEach(function(item) {
                            seenProd['product_' + item.id] = true;
                        });
                        serverData.forEach(function(item) {
                            if (item.type === 'order' || item.type === 'user') {
                                var key = item.type + '_' + item.id;
                                if (!seenOC[key]) {
                                    seenOC[key] = true;
                                    orderCustomerResults.push(item);
                                }
                            } else if (item.type === 'product') {
                                var pkey = 'product_' + item.id;
                                if (!seenProd[pkey]) {
                                    seenProd[pkey] = true;
                                    productResults.push(item);
                                }
                            }
                        });
                    }
                    mergeAndRender();
                })
                .fail(function() {
                    if (term !== searchInput.val()) return;
                    if (orderCustomerResults.length === 0) {
                        if (menuResults.length) {
                            mergeAndRender();
                        } else {
                            resultsContainer.addClass('initial-state');
                            resultsContainer.find('.results-content').html('<div class="result-item">Search failed</div>');
                            resultsContainer.find('.ewneater-close-bottom').hide();
                        }
                    }
                })
                .always(function() {
                    if (term === searchInput.val()) {
                        serverAjaxInProgress = false;
                        serverAjaxCompleted = true;
                        mergeAndRender();
                        doneSearching();
                    }
                });
            }, 200);
        });

        // Update the close button handlers
        $('.ewneater-close-x, .ewneater-close-bottom').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            resultsContainer.hide();

            // Only clear search input and results on mobile
            if (window.innerWidth <= 782) {  // WordPress mobile breakpoint
                searchInput.val('').blur();
                sortedResults = [];  // Clear stored results
                resultsContainer.find('.results-content').empty();
            } else {
                searchInput.blur();
                // Don't clear results on desktop, they'll be needed when focusing again
            }

            resultsContainer.find('.ewneater-close-bottom').hide();
        });

        // Update click handler to use data-url
        resultsContainer.on('click', '.result-item', function(e) {
            if ($(this).is('a') || $(e.target).closest('a').length) {
                return;
            }
            e.preventDefault();
            const url = $(this).data('url');
            if (url) {
                window.location = url;
            }
        });

        resultsContainer.on('click', '.ewneater-count-link', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const sectionKey = $(this).data('section');
            const $heading = resultsContainer.find('[data-section-heading=\"' + sectionKey + '\"]').first();
            const scrollEl = resultsContainer.find('.results-content')[0];

            if ($heading.length && scrollEl) {
                const scrollRect = scrollEl.getBoundingClientRect();
                const headingRect = $heading[0].getBoundingClientRect();
                const delta = headingRect.top - scrollRect.top;
                scrollEl.scrollTop += delta - 10;
            }
        });

        resultsContainer.on('click', '.ewneater-show-more', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            if ($btn.prop('disabled')) return;
            var section = $btn.data('section');
            var resultsPerSection = parseInt(resultsContainer.attr('data-results-per-section'), 10) || 3;
            if (section === 'menus') {
                ewneaterQuickFindMenusShown = (ewneaterQuickFindMenusShown || resultsPerSection) + resultsPerSection;
                ewneaterQuickFindMerge();
                return;
            }
            var ordersOffset = (section === 'orders') ? ewneaterQuickFindLoaded.orders : 0;
            var customersOffset = (section === 'customers') ? ewneaterQuickFindLoaded.customers : 0;
            var productsOffset = (section === 'products') ? ewneaterQuickFindLoaded.products : 0;
            $btn.prop('disabled', true).text('Loading…');
            $.post('<?php echo $ajaxurl; ?>', {
                action: 'ewneater_quick_find',
                search_term: searchInput.val(),
                nonce: '<?php echo $nonce; ?>',
                skip_menus: 1,
                orders_offset: ordersOffset,
                customers_offset: customersOffset,
                products_offset: productsOffset
            })
            .done(function(response) {
                if (!response.success || !response.data) return;
                var payload = response.data;
                var serverData = Array.isArray(payload) ? payload : (payload.results || []);
                var seenOC = {};
                var seenProd = {};
                if (ewneaterQuickFindOC) {
                    ewneaterQuickFindOC.forEach(function(item) {
                        if (item.type === 'order' || item.type === 'user') seenOC[item.type + '_' + item.id] = true;
                    });
                }
                if (ewneaterQuickFindProd) {
                    ewneaterQuickFindProd.forEach(function(item) { seenProd['product_' + item.id] = true; });
                }
                serverData.forEach(function(item) {
                    if (item.type === 'order' || item.type === 'user') {
                        var key = item.type + '_' + item.id;
                        if (!seenOC[key]) { seenOC[key] = true; ewneaterQuickFindOC.push(item); }
                    } else if (item.type === 'product') {
                        var pkey = 'product_' + item.id;
                        if (!seenProd[pkey]) { seenProd[pkey] = true; ewneaterQuickFindProd.push(item); }
                    }
                });
                ewneaterQuickFindMerge();
            })
            .always(function() {
                $btn.prop('disabled', false).text('Show more');
            });
        });

        // Update document click handler to properly close results
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#ewneater-quick-find-input, #ewneater-quick-find-results').length) {
                resultsContainer.hide();
                resultsContainer.find('.ewneater-close-bottom').hide();
                resultsContainer.find('.results-content').empty();
            }
        });

        // Replace all result rendering with this function:
        function renderResults(sortedResults, term, stillLoadingServer, totals, serverSearchInProgress) {
            const orderStatusItems = [];
            const orderItems = [];
            const userItems = [];
            const productItems = [];
            const menuItems = [];

            sortedResults.forEach(item => {
                if (item.type === 'order_status') orderStatusItems.push(item);
                if (item.type === 'order') orderItems.push(item);
                if (item.type === 'user') userItems.push(item);
                if (item.type === 'product') productItems.push(item);
                if (item.type === 'menu') menuItems.push(item);
            });

            let html = '';
            const countRowContent = stillLoadingServer
                ? '<span style="color:#666;font-style:italic;">Searching orders, customers &amp; products…</span>'
                : `<span class="ewneater-count-link" data-section="orders">${orderItems.length} orders</span>, <span class="ewneater-count-link" data-section="customers">${userItems.length} customers</span>, <span class="ewneater-count-link" data-section="products">${productItems.length} products</span>, <span class="ewneater-count-link" data-section="menus">${menuItems.length} menus</span>`;
            html += '<div class="result-item count ewneater-count-row">' + countRowContent + '</div>';

            const addSection = (label, items, headingClass, sectionKey, totalCount, showBgSearch, sectionUrl, displayLimit) => {
                if (!items.length && (totalCount == null || totalCount === 0)) {
                    return;
                }
                const headingContent = sectionUrl
                    ? `<a href="${sectionUrl}" class="ewneater-section-heading-link">${label}</a>`
                    : label;
                html += `<div class="result-item count result-section ${headingClass}" data-section-heading="${sectionKey}">${headingContent}</div>`;
                items.forEach(item => {
                    let itemClass = 'admin-menu-result';
                    if (item.type === 'order_status') itemClass = 'order-status-result';
                    else if (item.type === 'order') itemClass = 'order-result';
                    else if (item.type === 'user') itemClass = 'customer-result';
                    else if (item.type === 'product') itemClass = 'product-result';
                    if (item.type === 'product' && item.view_url) {
                        html += '<div class="ewneater-product-row">';
                        html += '<a class="result-item ' + itemClass + '" href="' + item.url + '" target="_self">' +
                                highlightTerm(item.title, term) +
                                '</a>';
                        html += '<a href="' + item.view_url + '" target="_blank" rel="noopener" class="ewneater-product-view-link" title="View on public site"><span class="dashicons dashicons-external"></span></a>';
                        html += '</div>';
                    } else {
                        html += '<a class="result-item ' + itemClass + '" href="' + item.url + '" target="_self">' +
                                highlightTerm(item.title, term) +
                                '</a>';
                    }
                });
                var resultsPerSection = displayLimit != null ? displayLimit : (parseInt(resultsContainer.attr('data-results-per-section'), 10) || 3);
                if (totalCount != null && totalCount > resultsPerSection) {
                    const shown = items.length;
                    html += '<div class="result-item count ewneater-section-footer">';
                    html += '<span class="ewneater-showing-count">Showing ' + shown + ' of ' + totalCount + '</span>';
                    if (shown < totalCount) {
                        html += ' <button type="button" class="ewneater-show-more" data-section="' + sectionKey + '">Show more</button>';
                    }
                    html += '</div>';
                }
                if (showBgSearch) {
                    html += '<div class="result-item count ewneater-bg-search-msg ewneater-bg-search-' + sectionKey + '">Updating…</div>';
                }
            };

            const t = totals || {};
            const showBg = !!serverSearchInProgress;
            const ordersUrl = resultsContainer.attr('data-orders-url') || '';
            const customersUrl = resultsContainer.attr('data-customers-url') || '';
            const productsUrl = resultsContainer.attr('data-products-url') || '';
            addSection('Order Statuses', orderStatusItems, 'order-statuses-heading', 'order_statuses', null, false, ordersUrl);

            if (menuItems.length) {
                const menusUrl = resultsContainer.attr('data-menus-url') || '';
                const resultsPerSection = parseInt(resultsContainer.attr('data-results-per-section'), 10) || 3;
                const menusToShow = ewneaterQuickFindMenusShown != null ? ewneaterQuickFindMenusShown : resultsPerSection;
                const groupedMenus = {};
                menuItems.forEach(item => {
                    const parentLabel = item.parent ? item.parent : 'Top level';
                    if (!groupedMenus[parentLabel]) groupedMenus[parentLabel] = [];
                    groupedMenus[parentLabel].push(item);
                });
                const flatMenus = [];
                Object.keys(groupedMenus).sort().forEach(parentLabel => {
                    groupedMenus[parentLabel].forEach(item => flatMenus.push(item));
                });
                const displayMenus = flatMenus.slice(0, menusToShow);
                const groupedDisplay = {};
                displayMenus.forEach(item => {
                    const parentLabel = item.parent ? item.parent : 'Top level';
                    if (!groupedDisplay[parentLabel]) groupedDisplay[parentLabel] = [];
                    groupedDisplay[parentLabel].push(item);
                });
                html += `<div class="result-item count result-section admin-menus-heading" data-section-heading="menus"><span>Admin Menus</span><a href="${menusUrl}" class="ewneater-admin-menus-heading-link">Customise Menus</a></div>`;
                Object.keys(groupedDisplay).sort().forEach(parentLabel => {
                    html += `<div class="result-item count result-section" data-section-heading="menus">${parentLabel}</div>`;
                    groupedDisplay[parentLabel].forEach(item => {
                        const menuParent = item.menu_parent ? item.menu_parent : '';
                        const displayLabel = (menuParent && menuParent !== 'Top level')
                            ? `${menuParent} > ${item.title}`
                            : item.title;
                        html += '<a class="result-item admin-menu-result" href="' + item.url + '" target="_self">' +
                                highlightTerm(displayLabel, term) +
                                '</a>';
                    });
                });
                if (menuItems.length > resultsPerSection && displayMenus.length < menuItems.length) {
                    html += '<div class="result-item count ewneater-section-footer">';
                    html += '<span class="ewneater-showing-count">Showing ' + displayMenus.length + ' of ' + menuItems.length + '</span>';
                    html += ' <button type="button" class="ewneater-show-more" data-section="menus">Show more</button>';
                    html += '</div>';
                }
            }

            addSection('Products', productItems, 'products-heading', 'products', t.total_products, showBg, productsUrl);

            addSection('Orders', orderItems, 'orders-heading', 'orders', t.total_orders, showBg, ordersUrl);
            addSection('Customers', userItems, 'customers-heading', 'customers', t.total_customers, showBg, customersUrl);

            return html;
        }

        function highlightTerm(text, term) {
            if (text == null) return '';
            const str = String(text);
            if (!term) return str;

            // Split into words so "yari nsw" highlights both "yari" and "nsw"
            const words = term.trim().split(/\s+/).filter(Boolean);
            if (!words.length) return str;

            // Normalize for comparison: remove $, commas, optional .00 cents (for currency matching)
            const normalizeForMatch = (str) => {
                return str.toString().replace(/[$,]/g, '').replace(/\.00$/, '').trim();
            };

            function highlightOneWord(html, word, allowCurrencyNormalize) {
                const escapedWord = word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const parts = html.split(/(<span class="highlight">.*?<\/span>)/);

                for (let i = 0; i < parts.length; i++) {
                    if (parts[i].includes('<span class="highlight">')) continue;

                    // Only highlight within text content – never inside HTML tags/attributes
                    const subParts = parts[i].split(/(<[^>]+>)/);
                    for (let j = 0; j < subParts.length; j++) {
                        if (!subParts[j].startsWith('<')) {
                            subParts[j] = subParts[j].replace(new RegExp('(' + escapedWord + ')', 'gi'), '<span class="highlight">$1</span>');
                        }
                    }
                    parts[i] = subParts.join('');

                    if (allowCurrencyNormalize && (word.includes('$') || word.includes(','))) {
                        const normalizedTerm = normalizeForMatch(word);
                        if (normalizedTerm !== '') {
                            const subParts = parts[i].split(/(<[^>]+>)/);
                            for (let j = 0; j < subParts.length; j++) {
                                if (!subParts[j].startsWith('<')) {
                                    subParts[j] = subParts[j].replace(/(\$?[\d,]+\.?\d*)/g, function(match) {
                                        const normalizedMatch = normalizeForMatch(match);
                                        return normalizedMatch === normalizedTerm ? '<span class="highlight">' + match + '</span>' : match;
                                    });
                                }
                            }
                            parts[i] = subParts.join('');
                        }
                    }
                }
                return parts.join('');
            }

            let result = str;
            const singleWord = words.length === 1;
            for (let w = 0; w < words.length; w++) {
                result = highlightOneWord(result, words[w], singleWord);
            }
            return result;
        }

        function updateResultsPosition() {
            const searchBox = $('#ewneater-quick-find-input');
            const resultsContainer = $('#ewneater-quick-find-results');
            if (window.innerWidth > 600 && searchBox.length && resultsContainer.length) {
                const rect = searchBox[0].getBoundingClientRect();
                resultsContainer.css({
                    left: rect.left,
                    top: rect.bottom + 2
                });
            } else if (resultsContainer.length) {
                resultsContainer.css({ left: '', top: '' });
            }
        }

        // Call this on focus, input, resize, and scroll
        searchInput.on('focus input', updateResultsPosition);
        $(window).on('resize scroll', updateResultsPosition);
    });
</script>
<?php
    }

    /*==========================================================================
     * MULTI-TERM SEARCH HELPER
     *
     * Splits search term into individual words while preserving special patterns:
     * - Email addresses (kept intact)
     * - Price values with $ (kept intact)
     * - Other terms split by spaces for AND logic
     ==========================================================================*/
    private static function split_search_terms($search_term) {
        // Check if search term contains @ (email-like search)
        $is_email_like = strpos($search_term, '@') !== false;
        
        if ($is_email_like) {
            // For email searches, don't split - return as single term
            return [$search_term];
        }
        
        // Split by spaces, but preserve price patterns (e.g., "$1,942.50")
        $terms = [];
        $parts = preg_split('/\s+/', trim($search_term));
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $terms[] = $part;
            }
        }
        
        return empty($terms) ? [$search_term] : $terms;
    }

    /*==========================================================================
     * SEARCH HANDLING
     *
     * Processes search requests with capabilities:
     * - Searches orders by number, customer details, total
     * - Searches customers by name, company, location
     * - Supports multi-term searches (AND logic)
     * - Caches results for 2 hours
     * - Prioritizes exact order number matches
     * - Formats results with dates, totals, and locations
     * - Limits to 15 orders and 3 customers max
     ==========================================================================*/
    public static function handle_search() {
        // Include the logger if not already included
        if (!class_exists('EWNeater_Quick_Find_Logger')) {
            require_once dirname(__FILE__) . '/quickfind-logger.php';
        }

        EWNeater_Quick_Find_Logger::info('Quick Find - Search request received');

        if (!isset($_POST['nonce']) || !isset($_POST['search_term'])) {
            EWNeater_Quick_Find_Logger::error('Quick Find - Missing POST data - nonce or search_term');
            wp_send_json_error('Missing required data');
            return;
        }

        if (!check_ajax_referer('ewneater-quick-find-nonce', 'nonce', false)) {
            EWNeater_Quick_Find_Logger::error('Quick Find - Invalid nonce');
            wp_send_json_error('Security check failed');
            return;
        }

        $search_term = sanitize_text_field($_POST['search_term']);
        $skip_menus = !empty($_POST['skip_menus']);
        $orders_offset = isset($_POST['orders_offset']) ? max(0, (int) $_POST['orders_offset']) : 0;
        $customers_offset = isset($_POST['customers_offset']) ? max(0, (int) $_POST['customers_offset']) : 0;
        $products_offset = isset($_POST['products_offset']) ? max(0, (int) $_POST['products_offset']) : 0;
        $limit = (int) get_option('ewneater_quick_find_results_per_section', 3);
        $limit = max(1, min(50, $limit));
        EWNeater_Quick_Find_Logger::info(sprintf('Quick Find - Searching for term: "%s" (skip_menus=%s)', $search_term, $skip_menus ? 'yes' : 'no'));

        $results = [];
        $order_count = 0;
        $customer_count = 0;
        $product_count = 0;
        $menu_count = 0;
        $total_orders = 0;
        $total_customers = 0;
        $total_products = 0;

        // Get metadata
        $meta = get_option('ewneater_quick_find_meta');
        if (!$meta) {
            EWNeater_Quick_Find_Logger::error('Quick Find - Search index not built yet');
            wp_send_json_error('EWNeater: Quick Find - Search index not built yet.');
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Split search term into individual words for multi-term search
        $search_terms = self::split_search_terms($search_term);

        // Process orders with multi-term AND logic
        $order_conditions = [];
        $order_like_terms = [];
        
        foreach ($search_terms as $term) {
            // Build multiple LIKE variants for each term (for currency normalization)
            $normalized_currency = preg_replace('/[^0-9.,]/', '', $term);
            $normalized_commas_removed = str_replace(',', '', $normalized_currency);

            $variants = array_unique(array_filter([
                $term,
                $normalized_currency !== $term && $normalized_currency !== '' ? $normalized_currency : null,
                $normalized_commas_removed !== $normalized_currency && $normalized_commas_removed !== '' ? $normalized_commas_removed : null,
            ]));

            // For each term, create OR conditions (variants), but all terms must match (AND)
            $term_conditions = [];
            foreach ($variants as $variant) {
                $term_conditions[] = "search_text LIKE %s";
                $order_like_terms[] = '%' . $wpdb->esc_like($variant) . '%';
            }
            
            // Each term must match (AND logic), but variants within a term use OR
            if (!empty($term_conditions)) {
                $order_conditions[] = '(' . implode(' OR ', $term_conditions) . ')';
            }
        }

        // Fallback if for some reason we produced no conditions
        if (empty($order_conditions)) {
            $order_conditions[] = "search_text LIKE %s";
            $order_like_terms[] = '%' . $wpdb->esc_like($search_term) . '%';
        }

        // Total matching orders (for "X of Y" and Show more)
        $order_count_sql = "SELECT COUNT(*) FROM $table WHERE type = 'order' AND " . implode(' AND ', $order_conditions);
        $order_count_prepare = array_merge([$order_count_sql], $order_like_terms);
        $total_orders = (int) $wpdb->get_var(call_user_func_array([$wpdb, 'prepare'], $order_count_prepare));

        $sql = "SELECT * FROM $table WHERE type = 'order' AND " . implode(' AND ', $order_conditions) . " ORDER BY JSON_EXTRACT(metadata, '$.date') DESC LIMIT %d OFFSET %d";
        $prepare_args = array_merge([$sql], $order_like_terms, [$limit, $orders_offset]);
        $prepared_sql = call_user_func_array([$wpdb, 'prepare'], $prepare_args);
        $orders = $wpdb->get_results($prepared_sql, ARRAY_A);

        if ($orders) {
            foreach ($orders as $order) {
                if (get_post_type($order['entity_id']) !== 'shop_order') {
                    continue;
                }
                $order_count++;
                $metadata = json_decode($order['metadata'], true);
                $formatted = self::format_order_result_for_display($metadata, $order['entity_id']);
                $results[] = [
                    'type'  => 'order',
                    'id'    => $order['entity_id'],
                    'title' => $formatted['title'],
                    'url'   => $formatted['url'],
                ];
            }
        }

        // Process customers with multi-term AND logic
        $customer_conditions = [];
        $customer_like_terms = [];
        
        foreach ($search_terms as $term) {
            $customer_conditions[] = "search_text LIKE %s";
            $customer_like_terms[] = '%' . $wpdb->esc_like($term) . '%';
        }

        if (empty($customer_conditions)) {
            $customer_conditions[] = "search_text LIKE %s";
            $customer_like_terms[] = '%' . $wpdb->esc_like($search_term) . '%';
        }

        // Total matching customers (for "X of Y" and Show more)
        $customer_count_sql = "SELECT COUNT(*) FROM $table WHERE type = 'user' AND " . implode(' AND ', $customer_conditions);
        $customer_count_prepare = array_merge([$customer_count_sql], $customer_like_terms);
        $total_customers = (int) $wpdb->get_var(call_user_func_array([$wpdb, 'prepare'], $customer_count_prepare));

        $customer_sql = "SELECT * FROM $table WHERE type = 'user' AND " . implode(' AND ', $customer_conditions) . " ORDER BY JSON_EXTRACT(metadata, '$.registration_date') DESC LIMIT %d OFFSET %d";
        $customer_prepare_args = array_merge([$customer_sql], $customer_like_terms, [$limit, $customers_offset]);
        $customer_prepared_sql = call_user_func_array([$wpdb, 'prepare'], $customer_prepare_args);
        $customers = $wpdb->get_results($customer_prepared_sql, ARRAY_A);

        if ($customers) {
            foreach ($customers as $customer) {
                $customer_count++;
                $metadata = json_decode($customer['metadata'], true);
                $formatted = self::format_customer_result_for_display($metadata, $customer['entity_id']);
                $results[] = [
                    'type'  => 'user',
                    'id'    => $customer['entity_id'],
                    'title' => $formatted['title'],
                    'url'   => $formatted['url'],
                ];
            }
        }

        // Process products with multi-term AND logic (title + SKU); active first, then inactive, LIMIT 10 OFFSET
        if (class_exists('WooCommerce') && $skip_menus) {
            $product_conditions = [];
            $product_like_terms = [];
            foreach ($search_terms as $term) {
                $product_conditions[] = "(p.post_title LIKE %s OR COALESCE(pm.meta_value, '') LIKE %s)";
                $like_val = '%' . $wpdb->esc_like($term) . '%';
                $product_like_terms[] = $like_val;
                $product_like_terms[] = $like_val;
            }
            if (!empty($product_conditions)) {
                $product_where = implode(' AND ', $product_conditions);
                $posts_table = $wpdb->posts;
                $meta_table = $wpdb->postmeta;

                // Total matching products (for "X of Y" and Show more)
                $product_count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$posts_table} p
                    LEFT JOIN {$meta_table} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                    WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
                    AND {$product_where}";
                $product_count_prepare = call_user_func_array([$wpdb, 'prepare'], array_merge([$product_count_sql], $product_like_terms));
                $total_products = (int) $wpdb->get_var($product_count_prepare);

                // Single query: publish first, then by modified date
                $product_sql = "SELECT DISTINCT p.ID, p.post_title, p.post_status
                    FROM {$posts_table} p
                    LEFT JOIN {$meta_table} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                    WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
                    AND {$product_where}
                    ORDER BY (CASE WHEN p.post_status = 'publish' THEN 0 ELSE 1 END), p.post_modified DESC
                    LIMIT %d OFFSET %d";
                $product_prepare_args = array_merge([$product_sql], $product_like_terms, [$limit, $products_offset]);
                $product_prepared = call_user_func_array([$wpdb, 'prepare'], $product_prepare_args);
                $all_products = $wpdb->get_results($product_prepared);

                foreach ($all_products as $row) {
                    $product_count++;
                    $product = wc_get_product($row->ID);
                    $sku = $product ? $product->get_sku() : '';
                    $stock_status = $product ? $product->get_stock_status() : 'outofstock';
                    $stock_label = $stock_status === 'instock' ? 'In Stock' : ($stock_status === 'onbackorder' ? 'On backorder' : 'Out of Stock');
                    $stock_class = 'ewneater-stock-' . $stock_status;
                    $status_slug = $row->post_status;
                    $status_label = $status_slug === 'publish' ? 'Published' : ucfirst($status_slug);
                    $status_class = $status_slug === 'publish' ? 'order-status-completed' : 'order-status-' . $status_slug;
                    $title_display = esc_html($row->post_title);
                    if ($sku) {
                        $title_display .= ' <span class="details">(' . esc_html($sku) . ')</span>';
                    }
                    // Hide "In Stock" for now; show Out of Stock / On backorder only
                    // $title_display .= ' <span id="past_orders"><span class="' . esc_attr($stock_class) . '">' . esc_html($stock_label) . '</span></span>';
                    if ($stock_status !== 'instock') {
                        $title_display .= ' <span id="past_orders"><span class="' . esc_attr($stock_class) . '">' . esc_html($stock_label) . '</span></span>';
                    }
                    $title_display .= ' <span id="past_orders"><span class="' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></span>';
                    $results[] = [
                        'type'     => 'product',
                        'id'       => (int) $row->ID,
                        'title'    => $title_display,
                        'url'      => get_edit_post_link($row->ID),
                        'view_url' => get_permalink($row->ID),
                    ];
                }
            }
        }

        // Process admin menus with multi-term AND logic (skipped when frontend requests orders+customers only)
        if (!$skip_menus) {
            $menu_items = self::get_cached_admin_menu_items();
            $hidden_menu_keys = self::get_hidden_menu_keys();

            if (!empty($menu_items)) {
                foreach ($menu_items as $menu_item) {
                    if (in_array($menu_item['key'], $hidden_menu_keys, true)) {
                        continue;
                    }

                    $search_haystack = strtolower(trim(
                        $menu_item['title'] . ' ' . $menu_item['parent'] . ' ' . $menu_item['slug']
                    ));

                    $matches_all_terms = true;
                    foreach ($search_terms as $term) {
                        $term = strtolower($term);
                        if ($term === '') {
                            continue;
                        }

                        if (strpos($search_haystack, $term) === false) {
                            $matches_all_terms = false;
                            break;
                        }
                    }

                    if (!$matches_all_terms) {
                        continue;
                    }

                    $menu_count++;
                    $parent_display = $menu_item['parent'] ? $menu_item['parent'] : 'Top level';
                    $results[] = [
                        'type'  => 'menu',
                        'id'    => $menu_item['key'],
                        'parent' => $parent_display,
                        'menu_parent' => $parent_display,
                        'title' => esc_html($menu_item['title']),
                        'url'   => $menu_item['url'],
                    ];
                }
            }
        }

        // Log the search results summary
        EWNeater_Quick_Find_Logger::info(sprintf(
            'Quick Find - Found %d orders, %d customers, %d products, and %d menus for term "%s"',
            $order_count,
            $customer_count,
            $product_count,
            $menu_count,
            $search_term
        ));

        wp_send_json_success([
            'results'              => $results,
            'total_orders'         => $total_orders,
            'total_customers'      => $total_customers,
            'total_products'       => $total_products,
            'results_per_section'  => $limit,
        ]);
    }

    /*==========================================================================
     * MENUS-ONLY SEARCH (FAST PATH)
     *
     * Returns only admin menu matches so the UI can show them immediately
     * while orders/customers search runs in parallel.
     ==========================================================================*/
    public static function handle_search_menus() {
        if (!isset($_POST['nonce']) || !isset($_POST['search_term'])) {
            wp_send_json_error('Missing required data');
            return;
        }

        if (!check_ajax_referer('ewneater-quick-find-nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        $search_term = sanitize_text_field($_POST['search_term']);
        $search_terms = self::split_search_terms($search_term);

        $menu_items = self::get_cached_admin_menu_items();
        $hidden_menu_keys = self::get_hidden_menu_keys();
        $results = [];

        if (!empty($menu_items)) {
            foreach ($menu_items as $menu_item) {
                if (in_array($menu_item['key'], $hidden_menu_keys, true)) {
                    continue;
                }

                $search_haystack = strtolower(trim(
                    $menu_item['title'] . ' ' . $menu_item['parent'] . ' ' . $menu_item['slug']
                ));

                $matches_all_terms = true;
                foreach ($search_terms as $term) {
                    $term = strtolower($term);
                    if ($term === '') {
                        continue;
                    }
                    if (strpos($search_haystack, $term) === false) {
                        $matches_all_terms = false;
                        break;
                    }
                }

                if (!$matches_all_terms) {
                    continue;
                }

                $parent_display = $menu_item['parent'] ? $menu_item['parent'] : 'Top level';
                $results[] = [
                    'type'       => 'menu',
                    'id'         => $menu_item['key'],
                    'parent'     => $parent_display,
                    'menu_parent'=> $parent_display,
                    'title'      => esc_html($menu_item['title']),
                    'url'        => $menu_item['url'],
                ];
            }
        }

        wp_send_json_success($results);
    }

    private static function matches_search_term($item, $search_term) {
        $fields = ['number', 'first_name', 'last_name', 'company', 'city', 'state', 'display_name'];
        foreach ($fields as $field) {
            if (isset($item[$field]) && stripos($item[$field], $search_term) !== false) {
                return true;
            }
        }
        return false;
    }

    /*==========================================================================
     * INDEX BUILDING
     *
     * Manages the search index with features:
     * - Full index rebuild (orders and customers)
     * - Order-only rebuild
     * - Customer-only rebuild
     * - Database table creation
     * - Metadata management
     * - Performance logging
     * - Hourly automatic updates
     * - Manual rebuild options
     * - Index clearing
     * - New entries checking
     ==========================================================================*/

    public static function build_search_index() {
        // Include the logger if not already included
        if (!class_exists('EWNeater_Quick_Find_Logger')) {
            require_once dirname(__FILE__) . '/quickfind-logger.php';
        }

        EWNeater_Quick_Find_Logger::info('Quick Find - Starting search index build...');
        $start_time = microtime(true);

        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Clear existing index
        $wpdb->query("TRUNCATE TABLE $table");

        // Get total counts upfront so progress can be tracked
        $total_orders = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_status NOT IN ('wc-cancelled', 'wc-failed', 'trash', 'draft')
        ");

        // Initialize metadata with full progress context
        $meta = [
            'status'          => 'processing',
            'phase'           => 'orders',
            'progress'        => 0,
            'total_orders'    => $total_orders,
            'total_customers' => 0,
            'start_time'      => current_time('mysql'),
            'last_updated'    => current_time('mysql'),
        ];
        update_option('ewneater_quick_find_meta', $meta);

        // Build orders index in chunks
        self::build_order_index_in_chunks($wpdb, $table);

        // Build customers index
        self::build_customer_index($wpdb, $table);

        // Update index metadata to Complete
        $meta = get_option('ewneater_quick_find_meta', []);
        $meta['status']       = 'complete';
        $meta['phase']        = '';
        $meta['last_updated'] = current_time('mysql');
        update_option('ewneater_quick_find_meta', $meta);

        // Log the completion
        $end_time = microtime(true);
        $execution_time = gmdate(($end_time - $start_time) < 60 ? 's\s' : 'i\m\i\n', $end_time - $start_time);
        EWNeater_Quick_Find_Logger::info("Quick Find - Search index update completed in {$execution_time} seconds");
    }

    private static function build_order_index_in_chunks($wpdb, $table) {
        $batch_size  = 1000;
        $offset      = 0;
        $batch_num   = 0;
        $chunk_start = microtime(true);

        // Get total order count — also write it into meta so progress helper can use it
        $total_orders = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_status NOT IN ('wc-cancelled', 'wc-failed', 'trash', 'draft')
        ");

        $meta = get_option('ewneater_quick_find_meta', []);
        $meta['phase']        = 'orders';
        $meta['total_orders'] = $total_orders;
        $meta['progress']     = 0;
        $meta['start_time']   = $meta['start_time'] ?? current_time('mysql');
        update_option('ewneater_quick_find_meta', $meta);

        EWNeater_Quick_Find_Logger::info("Quick Find - Starting chunked order index build for {$total_orders} orders");

        while (true) {
            $batch_start_time = microtime(true);

            // Get orders in batches
            $orders = $wpdb->get_col($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'shop_order'
                AND post_status NOT IN ('wc-cancelled', 'wc-failed', 'trash', 'draft')
                ORDER BY ID ASC
                LIMIT %d OFFSET %d
            ", $batch_size, $offset));

            if (empty($orders)) {
                break;
            }

            // Process this batch without individual logging
            foreach ($orders as $order_id) {
                self::build_order_index($wpdb, $table, $order_id, false, true);
            }

            $batch_num++;
            $offset += $batch_size;
            $batch_time       = microtime(true) - $batch_start_time;
            $processed        = min($offset, $total_orders);
            $progress_percent = $total_orders > 0 ? min(($processed / $total_orders) * 100, 100) : 0;
            $elapsed          = microtime(true) - $chunk_start;
            $est_remaining    = ($processed > 0 && $total_orders > $processed)
                ? round($elapsed / $processed * ($total_orders - $processed))
                : 0;

            // Log batch completion
            EWNeater_Quick_Find_Logger::info(sprintf(
                "Quick Find - Completed batch %d: processed %d/%d orders (%.1f%%) in %.2fs",
                $batch_num,
                $processed,
                $total_orders,
                $progress_percent,
                $batch_time
            ));

            // Update progress in metadata so the UI can poll it
            $meta = get_option('ewneater_quick_find_meta', []);
            $meta['progress']          = $processed;
            $meta['estimated_remaining'] = $est_remaining;
            $meta['last_updated']      = current_time('mysql');
            update_option('ewneater_quick_find_meta', $meta);

            // Clear object cache to prevent memory buildup
            wp_cache_flush();
        }

        EWNeater_Quick_Find_Logger::info("Quick Find - Chunked order index build completed");
    }

    public static function build_customer_index_only() {
        $start_time = microtime(true);
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Signal processing state so the UI can show a progress bar
        $meta = get_option('ewneater_quick_find_meta', []);
        $meta['status']     = 'processing';
        $meta['phase']      = 'customers';
        $meta['progress']   = 0;
        $meta['start_time'] = current_time('mysql');
        $meta['last_updated'] = current_time('mysql');
        update_option('ewneater_quick_find_meta', $meta);

        // Remove only customer rows
        $wpdb->delete($table, ['type' => 'user']);

        // Build customers index using the helper method
        self::build_customer_index($wpdb, $table);

        // Update index metadata to Complete
        $meta = get_option('ewneater_quick_find_meta', []);
        $meta['status']       = 'complete';
        $meta['phase']        = '';
        $meta['last_updated'] = current_time('mysql');
        update_option('ewneater_quick_find_meta', $meta);

        // Log the completion
        $end_time = microtime(true);
        $execution_time = gmdate(($end_time - $start_time) < 60 ? 's\s' : 'i\m\i\n', $end_time - $start_time);
        EWNeater_Quick_Find_Logger::info("Quick Find - Customer index update completed in {$execution_time} seconds");
    }

    public static function build_order_index_only() {
        $start_time = microtime(true);
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Get total orders now so progress helper has context immediately
        $total_orders = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_status NOT IN ('wc-cancelled', 'wc-failed', 'trash', 'draft')
        ");

        // Signal processing state so the UI can show a progress bar
        $meta = get_option('ewneater_quick_find_meta', []);
        $meta['status']       = 'processing';
        $meta['phase']        = 'orders';
        $meta['progress']     = 0;
        $meta['total_orders'] = $total_orders;
        $meta['start_time']   = current_time('mysql');
        $meta['last_updated'] = current_time('mysql');
        update_option('ewneater_quick_find_meta', $meta);

        // Remove only order rows
        $wpdb->delete($table, ['type' => 'order']);

        // Build orders index using the helper method
        self::build_order_index($wpdb, $table, null, false, true);

        // Update index metadata to Complete
        $meta = get_option('ewneater_quick_find_meta', []);
        $meta['status']       = 'complete';
        $meta['phase']        = '';
        $meta['last_updated'] = current_time('mysql');
        update_option('ewneater_quick_find_meta', $meta);

        // Log the completion
        $end_time = microtime(true);
        $execution_time = gmdate(($end_time - $start_time) < 60 ? 's\s' : 'i\m\i\n', $end_time - $start_time);
        EWNeater_Quick_Find_Logger::info("Quick Find - Order index update completed in {$execution_time} seconds");
    }

    private static function build_order_index($wpdb, $table, $order_id = null, $is_hook = false, $batch_rebuild = false) {
        // Only log detailed info for single order updates via hooks
        // Include the logger if not already included
        if (!class_exists('EWNeater_Quick_Find_Logger')) {
            require_once dirname(__FILE__) . '/quickfind-logger.php';
        }

        if ($is_hook) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [HOOK] Updating order for order_id: $order_id");
        } else if ($order_id && !$batch_rebuild) {
            // Individual order processing (not during batch rebuild)
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] Processing order: $order_id");
        } else if (!$order_id) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] Starting batch order index build");
        }

        // Set memory limit if possible
        @ini_set('memory_limit', '512M');

        // Set script timeout to 0 (no limit)
        @set_time_limit(0);

        if ($order_id) {
            // For single orders, get it directly
            $order = wc_get_order($order_id);
            if (!$order) {
                if ($is_hook) {
                    EWNeater_Quick_Find_Logger::error("Quick Find - [HOOK] Order not found: $order_id");
                } else {
                    EWNeater_Quick_Find_Logger::error("Quick Find - [INDEX] Order not found: $order_id");
                }
                return;
            }
            $orders = [$order_id];
            $total_orders = 1;
        } else {
            // Get total orders count for full rebuild
            $args = [
                'return' => 'ids',
                'orderby' => 'date',
                'order' => 'ASC',
                'post_type' => 'shop_order',
                'post_status' => 'any',
                'limit' => -1
            ];
            $orders = wc_get_orders($args);
            $total_orders = count($orders);
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] Found $total_orders orders to process");
        }

        if (empty($orders)) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] No orders found to process");
            return;
        }

        // Process in batches of 1000
        $batch_size = 1000;
        $batches = array_chunk($orders, $batch_size);
        $total_batches = count($batches);
        $start_time = microtime(true);

        foreach ($batches as $batch_num => $batch) {
            $batch_start_time = microtime(true);

            // Only log batch progress for full rebuilds
            if (!$order_id) {
                EWNeater_Quick_Find_Logger::info(sprintf(
                    "Quick Find - [INDEX] Processing order batch %d of %d",
                    $batch_num + 1,
                    $total_batches
                ));
            }

            // Start transaction for this batch
            $wpdb->query('START TRANSACTION');

            try {
                $batch_processed = 0;
                foreach ($batch as $oid) {
                    $order = wc_get_order($oid);
                    if (!$order || $order instanceof WC_Order_Refund) {
                        continue;
                    }

                    // Skip draft orders without customer details
                    // Note: Draft orders always have state and total, but these are auto-generated
                    // and should not be considered as meaningful customer information
                    $status = $order->get_status();
                    if ($status === 'checkout-draft') {
                        $has_customer_info = (
                            !empty($order->get_billing_first_name()) ||
                            !empty($order->get_billing_last_name()) ||
                            !empty($order->get_billing_company()) ||
                            !empty($order->get_billing_email())
                        );

                        if (!$has_customer_info) {
                            continue;
                        }
                    }

                    // Get order data
                    $order_number = $order->get_order_number();
                    $customer_id = $order->get_customer_id();
                    $user = $customer_id ? get_userdata($customer_id) : null;
                    $first_name = $order->get_billing_first_name();
                    $last_name = $order->get_billing_last_name();
                    $company = $order->get_billing_company();
                    $city = $order->get_shipping_city();
                    $state = $order->get_shipping_state();
                    // Get net payment (total after refunds) instead of original total
                    $total = $order->get_total() - $order->get_total_refunded();
                    $status = $order->get_status();
                    $date = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '';
                    $billing_email = $order->get_billing_email();

                    // Check if the order already exists in the search index to preserve previous prices
                    $existing_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, metadata FROM $table WHERE type = 'order' AND entity_id = %d",
                        $oid
                    ));
                    
                    // Extract previous prices from existing metadata if available
                    $previous_prices = [];
                    if ($existing_row && !empty($existing_row->metadata)) {
                        $existing_metadata = json_decode($existing_row->metadata, true);
                        if ($existing_metadata) {
                            // Get current total from existing metadata
                            if (isset($existing_metadata['total'])) {
                                $previous_total = (float) $existing_metadata['total'];
                                $previous_prices[] = number_format($previous_total, 2, '.', '');
                            }
                            // Get any previous prices stored in metadata
                            if (isset($existing_metadata['previous_prices']) && is_array($existing_metadata['previous_prices'])) {
                                foreach ($existing_metadata['previous_prices'] as $prev_price) {
                                    $prev_price_plain = number_format((float) $prev_price, 2, '.', '');
                                    if (!in_array($prev_price_plain, $previous_prices)) {
                                        $previous_prices[] = $prev_price_plain;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Prepare total formats - keep original format and plain format for searchability
                    $total_plain = number_format((float) $total, 2, '.', '');  // e.g. 1919.50 (plain format for searching)
                    
                    // Add current price to previous prices if it's different (avoid duplicates)
                    if (!in_array($total_plain, $previous_prices)) {
                        $previous_prices[] = $total_plain;
                    }
                    
                    // Build search text with all previous prices for comprehensive searching
                    $price_search_text = implode(' ', $previous_prices);

                    // Create searchable text - include both shipping and billing addresses for search
                    $search_text = sprintf(
                        '%s %s %s %s %s %s %s %s %s %s',
                        $order_number,
                        $first_name,
                        $last_name,
                        $company,
                        $city,
                        $state,
                        $price_search_text,  // Include all previous prices for searching
                        $order->get_billing_city(),
                        $order->get_billing_state(),
                        $order->get_billing_country(),
                        $billing_email
                    );

                    // Check if billing and shipping addresses differ
                    $shipping_address = $city . ', ' . $state;
                    $billing_address = $order->get_billing_city() . ', ' . $order->get_billing_state();
                    $addresses_differ = ($shipping_address !== $billing_address &&
                                       !empty($order->get_billing_city()) &&
                                       !empty($city));

                    // Create metadata with previous prices stored
                    $metadata = json_encode([
                        'number' => $order_number,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'company' => $company,
                        'city' => $city,
                        'state' => $state,
                        'total' => $total,
                        'total_formatted' => number_format((float) $total, 2, '.', ','),  // Formatted with commas for display
                        'previous_prices' => array_map('floatval', $previous_prices),  // Store all previous prices
                        'status' => $status,
                        'date' => $date,
                        'billing_city' => $order->get_billing_city(),
                        'billing_state' => $order->get_billing_state(),
                        'billing_location' => $order->get_billing_city() ? $order->get_billing_city() . ', ' . $order->get_billing_state() : '',
                        'billing_email' => $billing_email,
                        'addresses_differ' => $addresses_differ
                    ]);

                    // Update or insert the order into the search index
                    if ($existing_row) {
                        $wpdb->update(
                            $table,
                            [
                                'search_text' => $search_text,
                                'metadata' => $metadata,
                                'last_updated' => current_time('mysql')
                            ],
                            [
                                'type' => 'order',
                                'entity_id' => $oid
                            ]
                        );
                    } else {
                        $wpdb->insert(
                            $table,
                            [
                                'type' => 'order',
                                'entity_id' => $oid,
                                'search_text' => $search_text,
                                'metadata' => $metadata,
                                'last_updated' => current_time('mysql')
                            ]
                        );
                    }

                    if ($wpdb->last_error) {
                        throw new Exception("Database error for order_id $oid: " . $wpdb->last_error);
                    }

                    $batch_processed++;
                }

                // Commit transaction for this batch
                $wpdb->query('COMMIT');

                // Only log detailed progress for full rebuilds and batch rebuilds
                if (!$order_id || $batch_rebuild) {
                    $processed = ($batch_num + 1) * $batch_size;
                    $progress = min($processed, $total_orders);
                    $batch_time = microtime(true) - $batch_start_time;
                    $total_time = microtime(true) - $start_time;
                    $avg_time_per_order = $total_time / $progress;
                    $estimated_remaining = $avg_time_per_order * ($total_orders - $progress);

                    EWNeater_Quick_Find_Logger::info(sprintf(
                        "Quick Find - [INDEX] Processed batch %d/%d: %d of %d orders (%.1f%%) - Est. remaining: %.2fs",
                        $batch_num + 1,
                        count($batches),
                        $progress,
                        $total_orders,
                        ($progress / $total_orders) * 100,
                        $estimated_remaining
                    ));
                }

            } catch (Exception $e) {
                // Rollback transaction on error
                $wpdb->query('ROLLBACK');
                error_log("EWNeater: Quick Find - Error in batch " . ($batch_num + 1) . ": " . $e->getMessage());
                continue;
            }
        }

        // Log completion
        $total_time = microtime(true) - $start_time;
        if ($is_hook) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [HOOK] Completed order update for order_id: $order_id");
        } else {
            EWNeater_Quick_Find_Logger::info(sprintf(
                "Quick Find - [INDEX] Completed order index build. Processed %d orders in %.2f seconds",
                $total_orders,
                $total_time
            ));
        }
    }

    private static function build_customer_index($wpdb, $table, $user_id = null, $is_hook = false) {
        // Only log detailed info for single customer updates via hooks
        // Include the logger if not already included
        if (!class_exists('EWNeater_Quick_Find_Logger')) {
            require_once dirname(__FILE__) . '/quickfind-logger.php';
        }

        if ($is_hook) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [HOOK] Updating customer for user_id: $user_id");
        } else if ($user_id) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] Processing customer: $user_id");
        } else {
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] Starting batch customer index build");
        }

        // Set memory limit if possible
        @ini_set('memory_limit', '512M');

        // Set script timeout to 0 (no limit)
        @set_time_limit(0);

        $args = [
            'role__in' => ['customer', 'wholesale_buyer', 'wholesale_buyer_pending'],
            'fields' => ['ID'],
            'number' => -1,
            'orderby' => 'registered',
            'order' => 'ASC'
        ];

        // Include specific user if user_id is provided
        if ($user_id) {
            $args['include'] = [$user_id];
        }

        // Execute the user query
        $user_query = new WP_User_Query($args);
        $all_customers = $user_query->get_results();
        $total_customers = count($all_customers);

        // Only log total count for full rebuilds
        if (!$user_id) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] Found $total_customers customers to process");
        }

        if (empty($all_customers)) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [INDEX] No customers found to process");
            return;
        }

        // For full rebuilds, switch meta to customer phase so the UI can track progress
        if (!$user_id) {
            $meta = get_option('ewneater_quick_find_meta', []);
            $meta['phase']           = 'customers';
            $meta['total_customers'] = $total_customers;
            $meta['progress']        = 0;
            $meta['last_updated']    = current_time('mysql');
            update_option('ewneater_quick_find_meta', $meta);
        }

        // Process in batches of 1000
        $batch_size = 1000;
        $batches = array_chunk($all_customers, $batch_size);
        $total_batches = count($batches);
        $start_time = microtime(true);

        foreach ($batches as $batch_num => $batch) {
            $batch_start_time = microtime(true);

            // Only log batch progress for full rebuilds
            if (!$user_id) {
                EWNeater_Quick_Find_Logger::info(sprintf(
                    "Quick Find - [INDEX] Processing customer batch %d of %d",
                    $batch_num + 1,
                    $total_batches
                ));
            }

            // Start transaction for this batch
            $wpdb->query('START TRANSACTION');

            try {
                $batch_processed = 0;
                foreach ($batch as $user_obj) {
                $user = get_userdata($user_obj->ID);
                if (!$user) {
                    continue;
                }

                // Get customer data
                $first_name = $user->first_name;
                $last_name = $user->last_name;
                
                // Prefer explicit user company meta/field, then billing, then shipping
                $explicit_company_meta = get_user_meta($user->ID, 'company', true);
                $explicit_company_prop = isset($user->company) ? $user->company : '';
                $billing_company  = get_user_meta($user->ID, 'billing_company', true);
                $shipping_company = get_user_meta($user->ID, 'shipping_company', true);
                $company = '';
                foreach ([$explicit_company_meta, $explicit_company_prop, $billing_company, $shipping_company] as $candidate_company) {
                    if (!empty($candidate_company)) { $company = $candidate_company; break; }
                }
                $city = $user ? ($user->city ?? get_user_meta($user->ID, 'shipping_city', true)) : get_user_meta($user->ID, 'shipping_city', true);
                $state = $user ? ($user->state ?? get_user_meta($user->ID, 'shipping_state', true)) : get_user_meta($user->ID, 'shipping_state', true);

                $search_text = sprintf(
                    '%s %s %s %s %s %s',
                    $user->display_name,
                    $user->user_email,
                    $first_name,
                    $last_name,
                    $company,
                    $city,
                    $state
                );

                // Create metadata for the user
                $metadata = json_encode([
                    'display_name' => $user->display_name,
                    'email' => $user->user_email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'company' => $company,
                    'city' => $city,
                    'state' => $state,
                    'order_count' => count(wc_get_orders([
                        'customer' => $user->ID,
                        'return' => 'ids',
                        'limit' => -1,
                    ])),
                        'registration_date' => strtotime($user->user_registered),
                        'registration_date_formatted' => date('d M Y', strtotime($user->user_registered))
                ]);

                // Check if the user already exists in the search index
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE type = 'user' AND entity_id = %d",
                    $user->ID
                ));

                // Update or insert the user into the search index
                if ($existing) {
                    $wpdb->update(
                        $table,
                        [
                            'search_text' => $search_text,
                            'metadata' => $metadata,
                            'last_updated' => current_time('mysql')
                        ],
                        [
                            'type' => 'user',
                            'entity_id' => $user->ID
                        ]
                    );
                } else {
                    $wpdb->insert(
                        $table,
                        [
                            'type' => 'user',
                            'entity_id' => $user->ID,
                            'search_text' => $search_text,
                            'metadata' => $metadata,
                            'last_updated' => current_time('mysql')
                        ]
                    );
                    }

                    if ($wpdb->last_error) {
                        throw new Exception("Database error for user_id {$user->ID}: " . $wpdb->last_error);
                    }

                    $batch_processed++;
            }

                // Commit transaction for this batch
                $wpdb->query('COMMIT');

                // Only log detailed progress for full rebuilds
                if (!$user_id) {
                    $processed   = min(($batch_num + 1) * $batch_size, $total_customers);
                    $batch_time  = microtime(true) - $batch_start_time;
                    $total_time  = microtime(true) - $start_time;
                    $avg_time    = $processed > 0 ? $total_time / $processed : 0;
                    $estimated_remaining = $avg_time > 0 ? round($avg_time * ($total_customers - $processed)) : 0;

                    EWNeater_Quick_Find_Logger::info(sprintf(
                        "Quick Find - [INDEX] Processed %d of %d customers (%.1f%%) - Est. remaining: %.2fs",
                        $processed,
                        $total_customers,
                        $total_customers > 0 ? ($processed / $total_customers) * 100 : 0,
                        $estimated_remaining
                    ));

                    // Update meta so the UI progress bar stays current between page refreshes
                    $meta = get_option('ewneater_quick_find_meta', []);
                    $meta['progress']            = $processed;
                    $meta['estimated_remaining'] = $estimated_remaining;
                    $meta['last_updated']        = current_time('mysql');
                    update_option('ewneater_quick_find_meta', $meta);
                }

            } catch (Exception $e) {
                // Rollback transaction on error
                $wpdb->query('ROLLBACK');
                error_log("EWNeater: Quick Find - Error in batch " . ($batch_num + 1) . ": " . $e->getMessage());
                continue;
            }
        }

        // Log completion
        $total_time = microtime(true) - $start_time;
        if ($user_id) {
            EWNeater_Quick_Find_Logger::info("Quick Find - [HOOK] Completed customer update for user_id: $user_id");
        } else {
            EWNeater_Quick_Find_Logger::info(sprintf(
                "Quick Find - [INDEX] Completed customer index build. Processed %d customers in %.2f seconds",
                $total_customers,
                $total_time
            ));
        }
    }

    /*==========================================================================
     * GET BUILD PROGRESS
     *
     * Returns current indexing progress from ewneater_quick_find_meta:
     * - status, phase (orders|customers), progress_percent
     * - processed count and total for the active phase
     * - estimated seconds remaining (based on elapsed time and rate)
     * - last_updated timestamp
     *
     * Used by the AJAX status handler and PHP page render so both button-
     * triggered builds and page refreshes show accurate live progress.
     ==========================================================================*/
    public static function get_quick_find_progress() {
        $meta = get_option('ewneater_quick_find_meta', []);

        if (empty($meta)) {
            return [
                'status'              => 'not_built',
                'phase'               => '',
                'progress_percent'    => 0,
                'processed'           => 0,
                'total'               => 0,
                'estimated_remaining' => 0,
                'last_updated'        => null,
            ];
        }

        $status = $meta['status'] ?? 'unknown';
        $phase  = $meta['phase']  ?? '';

        // Determine processed/total for the current phase
        if ($phase === 'orders') {
            $processed = (int) ($meta['progress']     ?? 0);
            $total     = (int) ($meta['total_orders'] ?? 0);
        } elseif ($phase === 'customers') {
            $processed = (int) ($meta['progress']        ?? 0);
            $total     = (int) ($meta['total_customers'] ?? 0);
        } else {
            $processed = 0;
            $total     = 0;
        }

        $progress_percent = ($total > 0) ? round(min($processed / $total * 100, 100), 1) : 0;

        // ETA: use stored value from build loop, or compute from start_time
        $estimated_remaining = (int) ($meta['estimated_remaining'] ?? 0);
        if ($estimated_remaining === 0 && $status === 'processing' && isset($meta['start_time']) && $processed > 0 && $total > $processed) {
            $elapsed = time() - strtotime($meta['start_time']);
            $rate    = $elapsed > 0 ? $processed / $elapsed : 0;
            $estimated_remaining = $rate > 0 ? (int) round(($total - $processed) / $rate) : 0;
        }

        $scheduled_at = 0;
        if ($status === 'pending') {
            $scheduled_at = (int) ($meta['scheduled_at'] ?? 0);
            if ($scheduled_at <= 0) {
                $ts = wp_next_scheduled('ewneater_build_quick_find_index');
                $scheduled_at = $ts ? (int) $ts : 0;
            }
        }

        return [
            'status'              => $status,
            'scheduled_at'        => $scheduled_at,
            'phase'               => $phase,
            'progress_percent'    => $progress_percent,
            'processed'           => $processed,
            'total'               => $total,
            'estimated_remaining' => $estimated_remaining,
            'last_updated'        => $meta['last_updated'] ?? null,
        ];
    }

    public static function create_search_index_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL,
            entity_id bigint(20) NOT NULL,
            search_text text NOT NULL,
            metadata text NOT NULL,
            last_updated datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY entity_id (entity_id),
            KEY last_updated (last_updated)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /*==========================================================================
     * REMOVE ORDER FROM INDEX ON TRASH/DELETE
     *
     * Keeps search index in sync when orders are removed (manual or WooCommerce
     * 24h auto-delete of draft orders). before_delete_post fires for both.
     ==========================================================================*/
    public static function remove_order_from_index_on_trash($post_id) {
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }
        self::remove_order_from_index($post_id);
    }

    public static function remove_order_from_index_on_delete($post_id, $post = null) {
        if ($post === null) {
            if (get_post_type($post_id) !== 'shop_order') {
                return;
            }
        } elseif (!isset($post->post_type) || $post->post_type !== 'shop_order') {
            return;
        }
        self::remove_order_from_index($post_id);
    }

    private static function remove_order_from_index($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';
        $wpdb->delete($table, ['type' => 'order', 'entity_id' => (int) $order_id], ['%s', '%d']);
    }

    /*==========================================================================
     * REMOVE CUSTOMER FROM INDEX ON USER DELETE
     *
     * Keeps search index in sync when users are deleted (e.g. after merging
     * duplicate customer profiles into one account).
     ==========================================================================*/
    public static function remove_customer_from_index($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';
        $wpdb->delete($table, ['type' => 'user', 'entity_id' => (int) $user_id], ['%s', '%d']);
    }

    /*==========================================================================
     * HANDLE CUSTOMER MERGE (BSF Merge Customer Profiles)
     *
     * When another plugin merges customer profiles: remove source user from
     * index (they are deleted) and refresh target user's index with merged data.
     ==========================================================================*/
    public static function handle_customer_merge($target_id, $source_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Source user is deleted by merge plugin; clean up any orphaned index row
        $wpdb->delete($table, ['type' => 'user', 'entity_id' => (int) $source_id], ['%s', '%d']);

        // Refresh target user's index with updated order count and metadata
        self::build_customer_index($wpdb, $table, (int) $target_id, true);
    }

    public static function handle_new_order($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';

        // Clear order cache to ensure we get fresh data after updates
        wc_delete_shop_order_transients($order_id);
        
        // Always update the order index for this order (including guest orders)
        self::build_order_index($wpdb, $table, $order_id, true);

        // Guest Order Indexing:
        // Only update the customer index if this order is associated with a registered user (customer_id).
        // Do NOT index guest customers (i.e., do not create a 'user' index for guest orders).
        // This prevents phantom/duplicate customer entries for guest checkouts.
        $order = wc_get_order($order_id);
        if ($order) {
            $customer_id = $order->get_customer_id();
            if ($customer_id) {
                // Update customer index for registered user only
                self::build_customer_index($wpdb, $table, $customer_id, true);
            }
        }
    }

    public static function handle_new_customer($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ewneater_search_index';
        self::build_customer_index($wpdb, $table, $user_id, true);
    }
}
