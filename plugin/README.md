# A Neater Woocommerce Admin

**Version:** 12.4.8

Modernises the WooCommerce admin with a clean interface, smart customer insights, lightning-fast order search, manual control over the Sale product category, and configurable admin bar toolbar icons.

**Requires:** WordPress 6.4+, PHP 7.4+, WooCommerce

## Features

### Admin interface
- Hides unnecessary user profile fields (Visual Editor, Admin Color Scheme, Keyboard Shortcuts, Application Passwords, Elementor Notes, syntax highlighting, display name, URL, description, Yoast, Square gateway, etc.)
- Custom order table styling; order status colour scheme; past order status styling
- Collapsible sections on user profile (toggle state saved per user via Admin Toggler module)

### Order list enhancements
- **Tags** column: Guest, New, Returning, Wholesale, Black Sheep (company email), 30 day account; mobile shows order total and tags inline
- **Cust. Orders** column with dynamic font sizing
- **Cust. Revenue** column (hidden by default, toggle via Screen Options)
- **Pay** column: payment method with optional icons (Screen Options: Icons only / Text only / Both)
- Header breadcrumb: Orders > Status (Orders links to All Orders)
- Status filter reorder: Mine at end; Pending payments before Refunded; Drafts before Pending; Trash after Failed
- Search button text changed to "GO"; search placeholder "Search orders..."; Enter submits search
- Filter by Customers dropdown: top roles (wholesale, customer, guest) first; label "Filter by Customers.."
- Bulk actions layout; search box moved into tablenav

### Orders & Product screens
- Mobile-friendly orders list and order edit; touch-optimized; horizontal scrollable status filters
- Order edit: sidebar above main content on small screens; header with order #, customer name, total; Prev/Next order navigation (HPOS and legacy CPT support)
- Product edit: product name in header; View product link (opens in new tab)
- Custom customer history template on order edit (overrides WooCommerce default)

### Quick Find
- Instant search for orders, customers, products, and admin menus from any admin page
- Press `/` to focus; ↑/↓ navigate, Enter select, Esc close
- Admin menus: client-side filter (embedded, no AJAX)
- Orders/customers: optional preload for instant results; server search with pagination and "Show more"
- Products: server search and optional preload (SKU, stock status)
- Order status quick links before results (All Orders, Pending, Processing, etc.)
- **Quick Find Index**: rebuild/clear index; check new entries (missing orders/customers); toggle logging
- **Quick Find Menus**: choose which admin menu items appear in search; filterable table
- Optional: show Quick Find on frontend (admin bar) when enabled in Quick Find Index settings

### Customer Revenue & purchase history
- **Customer Revenue** page: sortable table (name, email, type, orders, revenue, first/last order); timeframe filters (All, This Month, Last Month, Last 3 Months, This Year, Last Year); customer type (All, Customers, Wholesalers, Guests, Company); search; index rebuild/clear; progress tracking; WooCommerce logs
- Past orders by email on order edit and user profile (links to Order #)
- **Top Purchased Items** meta box on order edit and user profile
- Enhanced user purchase history: product variations; expandable list; top 10 with load more

### On Sale Manager
- Manual approval workflow for the **Sale** product category (`/product-category/sale/`)
- Lists all products with a sale price set (all statuses: published, draft, private, pending, scheduled)
- Checkbox per product to approve/exclude from the Sale category; shift-click range select; click anywhere on a row to toggle
- Approved + currently on sale → added to Sale category; not approved or not on sale → removed automatically
- Auto-syncs on product save and variation save (no manual intervention needed for day-to-day changes)
- Filter panel: Status, Visibility, Currently On Sale, In Sale Category
- **Re-sync All** button to bulk-sync every product — useful after imports, restores, or first setup
- Sale category created automatically if it doesn't exist

### Toolbar Icons
- Control which admin bar items show on desktop and mobile (allowlist; root-default items hidden by default)
- Settings page: checkboxes per item, separate Desktop and Mobile columns; required items (e.g. menu-toggle, wp-logo) always visible
- Quick Find can be shown in the toolbar; preferences saved to options

### Reviews
- Pending review count in A Neater Admin menu; redirects to WooCommerce product reviews
- Standalone top-level Reviews menu (with pending count) linking to WooCommerce product reviews

### Users & Reviews list pages
- Users list and Product Reviews list: same layout as Orders (bulk actions in left container, search in tablenav, search button "GO", placeholder "Search users..." / "Search reviews...")

### A Neater Admin menu
- Dashboard, Quick Find Index, Quick Find Menus, Toolbar Icons, Customer Revenue, On Sale Manager, Reviews

## File structure

- `a-neater-woocommerce-admin.php` – Main plugin bootstrap, menu registration, asset enqueue, admin bar allowlist
- `includes/helpers.php` – Shared helpers (pending reviews count with cache, company email, wholesale user detection)
- `includes/orders_edit.php` – Orders list tags column; mobile layout; viewport
- `includes/orders-list-ui.php` – Orders list UI (status filter reorder, search, bulk actions)
- `includes/order-edit-header.php` – Order edit header (Order link, Prev/Next nav, customer/total)
- `includes/past-orders.php` – Past orders by email on order edit and user profile
- `includes/users-list-ui.php` – Users list layout (tablenav, GO button, search placeholder)
- `includes/reviews-list-ui.php` – Product reviews list layout (tablenav, GO button, search placeholder)
- `includes/quickfind-core.php` – Quick Find index and search logic (runs admin + frontend)
- `includes/quickfind-hooks.php` – Quick Find hooks for order processing
- `includes/quickfind-logger.php` – Quick Find logging
- `includes/quickfind.php` – Quick Find admin UI and search
- `includes/top-purchased-items.php` – Top Purchased Items meta box (order edit)
- `includes/top-purchased-items-users.php` – Top Purchased Items meta box (user profile)
- `includes/customer-revenue-core.php` – Customer revenue table and calculations
- `includes/customer-revenue-hooks.php` – Customer revenue hooks for order processing
- `includes/customer-revenue-logger.php` – Customer revenue logging
- `includes/customer-revenue-circuit-breaker.php` – Error protection for revenue updates
- `includes/customer-revenue.php` – Customer Revenue admin page
- `includes/toolbar-icons.php` – Toolbar Icons settings page and allowlist logic
- `includes/admin-toggler-module.php` – Collapsible sections (user profile)
- `includes/dashboard.php` – Dashboard page
- `includes/on-sale-manager.php` – On Sale Manager page, approval workflow, auto-sync hooks
- `includes/product_edit.php` – Product edit header (name, View product link)
- `woocommerce/templates/order/customer-history.php` – Custom customer history template
- `css/admin-dashboard.css` – Dashboard, A Neater Admin subpages, menu badge
- `css/orders-admin.css` – Orders list and order edit styles
- `css/on-sale-manager-admin.css` – On Sale Manager page styles
- `css/admin-toggler.css` – Toggler module styles
- `css/customer-revenue-admin.css` – Customer Revenue page styles
- `css/quickfind-admin.css` – Quick Find modal and UI
- `css/quickfind-index-admin.css` – Quick Find Index page styles
- `js/orders-admin.js` – Search placeholder, scrollable filters, mobile reorder
- `js/order-edit-heading.js` – Order Data h2 (customer name + total)
- `js/payment-method-icons.js` – Payment method display toggle (orders list)
- `js/admin-toggler.js` – Toggler collapse/expand and AJAX save
- `js/quickfind-menus-filter.js` – Quick Find Menus page filterable table

**Filters:** `ewneater_company_emails` – override company email list for special display (default: Black Sheep Farm emails)

## Author

Evolved Websites Pty Ltd – https://www.evolvedwebsites.com.au/plugins/

License: GPL v2 or later
