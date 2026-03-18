<?php
/*==========================================================================
 * A NEATER ADMIN DASHBOARD
 *
 * Renders the main Dashboard page for the plugin:
 * - Plugin overview and version
 * - Feature summary in card grid (admin interface, order list, Quick Find, etc.)
 * - Shortcuts to Quick Find Index/Menus, Customer Revenue, Settings
 * - Quick Find keyboard shortcut callout
 ==========================================================================*/

// Prevent direct file access
if (!defined("ABSPATH")) {
    exit;
}

/*==========================================================================
 * DASHBOARD DISPLAY
 ==========================================================================*/

/**
 * Display the Dashboard page (plugin overview and feature summary)
 */
function ewneater_display_dashboard_page()
{
    $version = defined("EWNEATER_VERSION") ? EWNEATER_VERSION : "";
    $index_url = admin_url("admin.php?page=ewneater-search-index");
    $menus_url = admin_url("admin.php?page=ewneater-quick-find-menus");
    $toolbar_url = admin_url("admin.php?page=ewneater-toolbar-icons");
    $revenue_url  = admin_url("admin.php?page=ewneater-customer-revenue");
    $on_sale_url  = admin_url("admin.php?page=ewneater-on-sale-manager");
    $reviews_url  = admin_url("admin.php?page=ewneater-reviews");
    ?>
	<div class="wrap ewneater-dashboard-wrap">
		<?php
		if (function_exists("ewneater_admin_page_styles")) {
			ewneater_admin_page_styles();
		}
		?>
		<h1 class="ewneater-dash-title"><?php
			if (function_exists("ewneater_admin_breadcrumb")) {
				ewneater_admin_breadcrumb(__("Dashboard", "a-neater-woocommerce-admin"));
			} else {
				echo esc_html(__("A Neater Admin", "a-neater-woocommerce-admin"));
			}
			?> <span class="ewneater-dash-version"><?php echo $version ? "v" . esc_html($version) : ""; ?></span></h1>
		<p class="ewneater-dash-intro"><?php esc_html_e("Modernises the WooCommerce admin with a clean interface, smart customer insights, and lightning-fast order search.", "a-neater-woocommerce-admin"); ?></p>

		<div class="ewneater-dash-shortcut">
			<strong><?php esc_html_e("Quick Find shortcut:", "a-neater-woocommerce-admin"); ?></strong> <?php esc_html_e("Press", "a-neater-woocommerce-admin"); ?> <kbd>/</kbd> <?php esc_html_e("anywhere in the admin to search orders and customers.", "a-neater-woocommerce-admin"); ?>
		</div>

		<div class="ewneater-dash-grid">
			<div class="ewneater-dash-card">
				<h3><span class="dashicons dashicons-admin-generic ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("Admin interface", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Hides unnecessary user profile fields", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Streamlines WooCommerce admin", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Custom order table styling", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Collapsible sections (toggle state saved per user)", "a-neater-woocommerce-admin"); ?></li>
				</ul>
			</div>
			<div class="ewneater-dash-card ewneater-dash-card--toolbar">
				<h3><span class="dashicons dashicons-visibility ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("Toolbar Icons", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Show or hide admin bar toolbar icons", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Separate settings for desktop and mobile", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Hides unknown plugin menus on new sites by default", "a-neater-woocommerce-admin"); ?></li>
				</ul>
				<div class="ewneater-dash-links">
					<a href="<?php echo esc_url($toolbar_url); ?>" class="button button-secondary"><?php esc_html_e("Toolbar Icons", "a-neater-woocommerce-admin"); ?></a>
				</div>
			</div>
			<div class="ewneater-dash-card">
				<h3><span class="dashicons dashicons-list-view ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("Order list enhancements", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Tags column: Guest, New, Returning, Wholesale", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Cust. Orders column (order count)", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Cust. Revenue column (optional)", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Payment method column (Pay) with optional icons", "a-neater-woocommerce-admin"); ?></li>
				</ul>
			</div>
			<div class="ewneater-dash-card">
				<h3><span class="dashicons dashicons-cart ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("Orders & Product screens", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Mobile-friendly orders list and order edit layout", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Search placeholder, scrollable status filters", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Product edit: product name in header, View product link", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Custom customer history template on order edit", "a-neater-woocommerce-admin"); ?></li>
				</ul>
			</div>
			<div class="ewneater-dash-card ewneater-dash-card--quickfind">
				<h3><span class="dashicons dashicons-search ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("Quick Find", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Instant search for orders and customers", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Press / from any admin page", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Up to 15 orders, 3 customers", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Keyboard navigation, mobile-friendly", "a-neater-woocommerce-admin"); ?></li>
				</ul>
				<div class="ewneater-dash-links">
					<a href="<?php echo esc_url($index_url); ?>" class="button button-secondary"><?php esc_html_e("Quick Find Index", "a-neater-woocommerce-admin"); ?></a>
					<a href="<?php echo esc_url($menus_url); ?>" class="button button-secondary"><?php esc_html_e("Quick Find Menus", "a-neater-woocommerce-admin"); ?></a>
				</div>
			</div>
			<div class="ewneater-dash-card ewneater-dash-card--revenue">
				<h3><span class="dashicons dashicons-money-alt ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("Customer revenue & reviews", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Customer Revenue page for spend/order data", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Past orders by email on order/user screens", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Top Purchased Items meta box", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Customer purchase history at a glance", "a-neater-woocommerce-admin"); ?></li>
				</ul>
				<div class="ewneater-dash-links">
					<a href="<?php echo esc_url($revenue_url); ?>" class="button button-secondary"><?php esc_html_e("Customer Revenue", "a-neater-woocommerce-admin"); ?></a>
				</div>
			</div>
			<div class="ewneater-dash-card ewneater-dash-card--on-sale">
				<h3><span class="dashicons dashicons-tag ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("On Sale Manager", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Lists all products with a sale price set", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Approve which products appear in the Sale category", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Syncs the Sale category on save", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Filter by status, visibility, on-sale state", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Re-sync all products any time", "a-neater-woocommerce-admin"); ?></li>
				</ul>
				<div class="ewneater-dash-links">
					<a href="<?php echo esc_url($on_sale_url); ?>" class="button button-secondary"><?php esc_html_e("On Sale Manager", "a-neater-woocommerce-admin"); ?></a>
				</div>
			</div>
			<div class="ewneater-dash-card ewneater-dash-card--reviews">
				<h3><span class="dashicons dashicons-star-filled ewneater-dash-card-icon" aria-hidden="true"></span><?php esc_html_e("Reviews", "a-neater-woocommerce-admin"); ?></h3>
				<ul>
					<li><?php esc_html_e("Shows pending review count in the main menu so no reviews are missed.", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Useful since WordPress moved the Comments / Reviews area.", "a-neater-woocommerce-admin"); ?></li>
					<li><?php esc_html_e("Opens WooCommerce product reviews.", "a-neater-woocommerce-admin"); ?></li>
				</ul>
				<div class="ewneater-dash-links">
					<a href="<?php echo esc_url($reviews_url); ?>" class="button button-secondary"><?php esc_html_e("Reviews", "a-neater-woocommerce-admin"); ?></a>
				</div>
			</div>
		</div>
	</div>
	<?php
}
