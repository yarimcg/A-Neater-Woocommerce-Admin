<?php
/*==========================================================================
 * ORDER EDIT PAGE HEADER ENHANCEMENTS
 *
 * Single order edit screen (post.php?post=X or wc-orders&action=edit&id=X):
 * - "Order" link back to orders list (preserves filters when context-aware)
 * - Last/Next order navigation (context-aware: within status/search/customer when from list)
 * - Retry logic for late-rendering header (iOS Safari compatibility)
 * - Supports legacy CPT and HPOS order storage
 ==========================================================================*/

// Prevent direct file access
if (!defined("ABSPATH")) {
    exit;
}

if (!function_exists("ewneater_get_orders_list_context")) {
    /**
     * GET ORDERS LIST CONTEXT
     * Returns sanitized list filter params from URL or referrer for context-aware prev/next nav.
     * Priority: $_GET params > HTTP_REFERER (if valid orders list) > empty.
     */
    function ewneater_get_orders_list_context()
    {
        $allowed_keys = ["post_status", "s", "m", "_customer_user", "orderby", "order"];
        $context = [];

        /* 1. Read from $_GET first */
        foreach ($allowed_keys as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $val = sanitize_text_field(wp_unslash($_GET[$key]));
                if ($val !== "") {
                    $context[$key] = $val;
                }
            }
        }

        if (!empty($context)) {
            return ewneater_normalize_list_context($context);
        }

        /* 2. Fallback: parse referrer if it points to orders list */
        $referer = isset($_SERVER["HTTP_REFERER"]) ? esc_url_raw(wp_unslash($_SERVER["HTTP_REFERER"])) : "";
        if ($referer === "") {
            return [];
        }

        $referer_host = wp_parse_url($referer, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($referer_host === null || $site_host === null || $referer_host !== $site_host) {
            return [];
        }

        $is_legacy_list = strpos($referer, "edit.php") !== false
            && strpos($referer, "post_type=shop_order") !== false;
        $is_hpos_list = strpos($referer, "page=wc-orders") !== false
            && strpos($referer, "action=edit") === false;

        if (!$is_legacy_list && !$is_hpos_list) {
            return [];
        }

        $query = wp_parse_url($referer, PHP_URL_QUERY);
        if ($query === null || $query === "") {
            return [];
        }

        parse_str($query, $params);
        foreach ($allowed_keys as $key) {
            if (isset($params[$key]) && is_string($params[$key])) {
                $val = sanitize_text_field(wp_unslash($params[$key]));
                if ($val !== "") {
                    $context[$key] = $val;
                }
            }
        }

        /* HPOS uses "status" instead of "post_status" */
        if ($is_hpos_list && isset($params["status"]) && is_string($params["status"])) {
            $val = sanitize_text_field(wp_unslash($params["status"]));
            if ($val !== "") {
                $context["post_status"] = $val;
            }
        }

        return ewneater_normalize_list_context($context);
    }
}

if (!function_exists("ewneater_normalize_list_context")) {
    /**
     * NORMALIZE LIST CONTEXT
     * Maps post_status to wc_get_orders format; omits invalid/empty values.
     */
    function ewneater_normalize_list_context($context)
    {
        $out = [];

        if (isset($context["post_status"]) && $context["post_status"] !== "" && $context["post_status"] !== "all") {
            $status = $context["post_status"];
            if (strpos($status, "wc-") !== 0) {
                $status = "wc-" . $status;
            }
            $out["post_status"] = $status;
        }

        if (isset($context["s"]) && $context["s"] !== "") {
            $out["s"] = $context["s"];
        }
        if (isset($context["m"]) && $context["m"] !== "" && preg_match("/^\d{6}$/", $context["m"])) {
            $out["m"] = $context["m"];
        }
        if (isset($context["_customer_user"]) && $context["_customer_user"] !== "" && is_numeric($context["_customer_user"])) {
            $out["_customer_user"] = (int) $context["_customer_user"];
        }
        if (isset($context["orderby"]) && $context["orderby"] !== "") {
            $out["orderby"] = sanitize_key($context["orderby"]);
        }
        if (isset($context["order"]) && in_array(strtoupper($context["order"]), ["ASC", "DESC"], true)) {
            $out["order"] = strtoupper($context["order"]);
        }

        return $out;
    }
}

if (!function_exists("ewneater_get_order_edit_context")) {
    /**
     * GET CURRENT ORDER EDIT CONTEXT
     * Returns [order_id, screen_id] when on single order edit; null otherwise.
     * Handles both legacy (post.php?post=X) and HPOS (admin.php?page=wc-orders&action=edit&id=X).
     */
    function ewneater_get_order_edit_context()
    {
        $screen = get_current_screen();
        if (!$screen) {
            return null;
        }

        $order_screen_id = function_exists("wc_get_page_screen_id")
            ? wc_get_page_screen_id("shop-order")
            : "shop_order";

        if ($screen->id !== $order_screen_id) {
            return null;
        }

        $order_id = 0;
        if (isset($_GET["post"]) && is_numeric($_GET["post"])) {
            $order_id = (int) $_GET["post"];
        } elseif (
            isset($_GET["action"]) &&
            $_GET["action"] === "edit" &&
            isset($_GET["id"]) &&
            is_numeric($_GET["id"])
        ) {
            $order_id = (int) $_GET["id"];
        }

        return $order_id > 0 ? [$order_id, $screen->id] : null;
    }
}

if (!function_exists("ewneater_get_adjacent_order_ids")) {
    /**
     * GET ADJACENT ORDER IDS
     * Returns [prev_id, next_id] for orders before/after current by date.
     * When $list_context is provided, restricts to same filtered set (status, search, etc.).
     */
    function ewneater_get_adjacent_order_ids($current_order_id, $list_context = [])
    {
        $order = wc_get_order($current_order_id);
        if (!$order) {
            return [null, null];
        }

        $date_created = $order->get_date_created();
        if (!$date_created) {
            return [null, null];
        }

        $date_str = $date_created->format("Y-m-d H:i:s");
        $orderby = isset($list_context["orderby"]) ? $list_context["orderby"] : "date";
        $sort_order = isset($list_context["order"]) ? $list_context["order"] : "DESC";

        $base_args = [
            "limit" => 1,
            "return" => "ids",
            "exclude" => [$current_order_id],
        ];

        /* Merge list context into wc_get_orders args */
        if (!empty($list_context)) {
            if (isset($list_context["post_status"])) {
                $base_args["status"] = [$list_context["post_status"]];
            }
            if (isset($list_context["s"]) && $list_context["s"] !== "") {
                $base_args["search"] = $list_context["s"];
            }
            if (isset($list_context["_customer_user"])) {
                $base_args["customer"] = (int) $list_context["_customer_user"];
            }
            if (isset($list_context["m"]) && preg_match("/^\d{6}$/", $list_context["m"])) {
                $y = substr($list_context["m"], 0, 4);
                $mo = substr($list_context["m"], 4, 2);
                $base_args["date_created"] = "{$y}-{$mo}-01...{$y}-{$mo}-31 23:59:59";
            }
        }

        $base_args["orderby"] = $orderby;
        $base_args["order"] = $sort_order;

        /* wc_get_orders: << = before date, >> = after date */
        $prev_orders = wc_get_orders(array_merge($base_args, [
            "date_created" => "<<{$date_str}",
            "order" => "DESC",
        ]));

        $next_orders = wc_get_orders(array_merge($base_args, [
            "date_created" => ">>{$date_str}",
            "order" => "ASC",
        ]));

        return [
            !empty($prev_orders) ? (int) $prev_orders[0] : null,
            !empty($next_orders) ? (int) $next_orders[0] : null,
        ];
    }
}

if (!function_exists("ewneater_get_order_edit_url")) {
    /**
     * GET ORDER EDIT URL
     * Returns URL for order edit page. Uses post.php for legacy CPT, wc-orders for HPOS.
     * When $context_params provided, appends them so prev/next nav preserves list context.
     */
    function ewneater_get_order_edit_url($order_id = null, $context_params = [])
    {
        $allowed_keys = ["post_status", "s", "m", "_customer_user", "orderby", "order"];
        $context_params = array_intersect_key($context_params, array_flip($allowed_keys));
        $context_params = array_filter($context_params, function ($v) {
            return $v !== "" && $v !== null;
        });

        $use_hpos = class_exists("Automattic\WooCommerce\Utilities\OrderUtil")
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($use_hpos) {
            if ($order_id) {
                $base = admin_url("admin.php?page=wc-orders&action=edit&id=" . $order_id);
                return !empty($context_params) ? add_query_arg($context_params, $base) : $base;
            }
        }
        if ($order_id) {
            $base = admin_url("post.php?post=" . $order_id . "&action=edit");
            return !empty($context_params) ? add_query_arg($context_params, $base) : $base;
        }
        /* Link to orders list: edit.php?post_status=XXX&post_type=shop_order */
        $list_params = array_merge(["post_type" => "shop_order"], $context_params);
        return admin_url("edit.php?" . http_build_query($list_params));
    }
}

/*==========================================================================
 * FILTER EDIT LINKS: APPEND LIST CONTEXT WHEN CLICKING FROM ORDERS LIST
 ==========================================================================*/
add_filter("get_edit_post_link", function ($url, $post_id, $context) {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== "edit-shop_order") {
        return $url;
    }
    if (get_post_type($post_id) !== "shop_order") {
        return $url;
    }

    $allowed_keys = ["post_status", "s", "m", "_customer_user", "orderby", "order"];
    $params = [];
    foreach ($allowed_keys as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key])) {
            $val = sanitize_text_field(wp_unslash($_GET[$key]));
            if ($val !== "") {
                $params[$key] = $val;
            }
        }
    }
    if (empty($params)) {
        return $url;
    }

    return add_query_arg($params, $url);
}, 10, 3);

/*==========================================================================
 * ADMIN HEAD: INJECT HEADER TOTAL + PREV/NEXT NAVIGATION
 ==========================================================================*/
add_action("admin_head", function () {
    $context = ewneater_get_order_edit_context();
    if (!$context) {
        return;
    }

    list($order_id,) = $context;
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    /* List is date DESC (newest first): Last = row above (newer), Next = row below (older) */
    $list_context = ewneater_get_orders_list_context();
    list($older_id, $newer_id) = ewneater_get_adjacent_order_ids($order_id, $list_context);
    $prev_url = $newer_id ? ewneater_get_order_edit_url($newer_id, $list_context) : "";
    $next_url = $older_id ? ewneater_get_order_edit_url($older_id, $list_context) : "";
    $all_orders_url = ewneater_get_order_edit_url(null, $list_context);

    $customer_name = $order->get_formatted_billing_full_name();
    $total = $order->get_total() - $order->get_total_refunded();
    $order_total_html = wc_price($total);
    ?>
	<script type="text/javascript">
	(function() {
		/* Data from PHP; no DOM dependency for order details (fixes iOS) */
		var ewneaterOrderData = {
			orderId: <?php echo (int) $order_id; ?>,
			customerName: <?php echo wp_json_encode($customer_name); ?>,
			orderTotalHtml: <?php echo wp_json_encode($order_total_html); ?>,
			prevUrl: <?php echo wp_json_encode($prev_url); ?>,
			nextUrl: <?php echo wp_json_encode($next_url); ?>,
			prevLabel: <?php echo wp_json_encode(__("Last", "a-neater-woocommerce-admin")); ?>,
			nextLabel: <?php echo wp_json_encode(__("Next", "a-neater-woocommerce-admin")); ?>,
			allOrdersUrl: <?php echo wp_json_encode(esc_url_raw($all_orders_url)); ?>,
			orderLinkText: <?php echo wp_json_encode(__("Orders", "a-neater-woocommerce-admin")); ?>
		};

		function ewneaterInjectHeaderContent() {
			var header = document.querySelector('.woocommerce-layout__header');
			if (!header) return false;

			var h1 = header.querySelector('.woocommerce-layout__header-heading, h1.woocommerce-layout__header-heading');
			if (h1 && !h1.querySelector('.ewneater-order-heading-link')) {
				var orderLink = document.createElement('a');
				orderLink.href = ewneaterOrderData.allOrdersUrl;
				orderLink.className = 'ewneater-order-heading-link';
				orderLink.textContent = ewneaterOrderData.orderLinkText;
				h1.innerHTML = '';
				h1.appendChild(orderLink);

				var totalEl = document.createElement('span');
				totalEl.className = 'ewneater-header-total';
				totalEl.innerHTML = " #" + ewneaterOrderData.orderId + " - " + ewneaterOrderData.customerName + " " + ewneaterOrderData.orderTotalHtml;
				h1.appendChild(totalEl);
				window.addEventListener('scroll', function() {
					totalEl.classList.toggle('show', window.scrollY >= 50);
				}, { passive: true });
			}

			/* Disabled: Last/Next nav arrows hidden – IDs are non-sequential and not useful
			if (!header.querySelector('.ewneater-order-nav')) {
				var nav = document.createElement('div');
				nav.className = 'ewneater-order-nav';
				if (ewneaterOrderData.prevUrl) {
					var prevLink = document.createElement('a');
					prevLink.href = ewneaterOrderData.prevUrl;
					prevLink.className = 'ewneater-order-nav-btn ewneater-order-nav-prev';
					prevLink.setAttribute('aria-label', ewneaterOrderData.prevLabel);
					prevLink.innerHTML = '<span class="ewneater-nav-arrow" aria-hidden="true">←</span><span class="ewneater-nav-text">' + ewneaterOrderData.prevLabel + '</span>';
					nav.appendChild(prevLink);
				}
				if (ewneaterOrderData.nextUrl) {
					var nextLink = document.createElement('a');
					nextLink.href = ewneaterOrderData.nextUrl;
					nextLink.className = 'ewneater-order-nav-btn ewneater-order-nav-next';
					nextLink.setAttribute('aria-label', ewneaterOrderData.nextLabel);
					nextLink.innerHTML = '<span class="ewneater-nav-text">' + ewneaterOrderData.nextLabel + '</span><span class="ewneater-nav-arrow" aria-hidden="true">→</span>';
					nav.appendChild(nextLink);
				}
				if (nav.children.length) {
					header.appendChild(nav);
				}
			}
			*/
			return true;
		}

		/* Retry injection for late-rendering header (iOS Safari) */
		function ewneaterRetryHeaderInjection(attempt) {
			if (ewneaterInjectHeaderContent()) return;
			var delays = [0, 100, 300, 600, 1200];
			if (attempt < delays.length) {
				setTimeout(function() { ewneaterRetryHeaderInjection(attempt + 1); }, delays[attempt]);
			}
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function() { ewneaterRetryHeaderInjection(0); });
		} else {
			ewneaterRetryHeaderInjection(0);
		}
		window.addEventListener('load', function() { ewneaterInjectHeaderContent(); });
	})();
	</script>
	<?php
});
