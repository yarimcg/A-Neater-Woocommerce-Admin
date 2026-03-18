<?php
/*==========================================================================
 * ON SALE MANAGER
 *
 * Provides a manual approval workflow for the "sale" product category:
 * - Lists all products that currently have a sale price set
 * - Shows a checkbox per product to include/exclude from the sale category
 * - Saves approved product IDs to the bsf_sale_approved_products option
 * - Syncs the sale category for every product on save
 ==========================================================================*/

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*==========================================================================
 * ASSET ENQUEUE
 ==========================================================================*/

add_action( 'admin_enqueue_scripts', 'bsf_on_sale_manager_enqueue_assets' );

/*--------------------------------------------------------------------------
 * Enqueue page CSS on the On Sale Manager screen only
 --------------------------------------------------------------------------*/
function bsf_on_sale_manager_enqueue_assets( $hook ) {
	if ( strpos( $hook, 'ewneater-on-sale-manager' ) === false ) {
		return;
	}
	wp_enqueue_style(
		'bsf-on-sale-manager-admin',
		plugins_url( '../css/on-sale-manager-admin.css', __FILE__ ),
		[],
		filemtime( dirname( __DIR__ ) . '/css/on-sale-manager-admin.css' )
	);
}

/*==========================================================================
 * AUTO-SYNC HOOKS
 *
 * Keep the Sale category in sync whenever a product or variation is saved,
 * without bypassing the manual approval gate.
 *
 * bsf_sync_product_sale_category() checks BOTH approval status AND
 * is_on_sale(), so:
 *   - Approved + on sale    → added to Sale category automatically
 *   - Approved + not on sale → removed from Sale category automatically
 *   - Not approved           → never added, removed if somehow present
 ==========================================================================*/

add_action( 'woocommerce_update_product', 'bsf_osm_sync_on_product_save', 20 );

/*--------------------------------------------------------------------------
 * Sync a simple or variable product after it is saved
 --------------------------------------------------------------------------*/
function bsf_osm_sync_on_product_save( $product_id ) {
	bsf_sync_product_sale_category( $product_id );
}

add_action( 'woocommerce_save_product_variation', 'bsf_osm_sync_on_variation_save', 20, 1 );

/*--------------------------------------------------------------------------
 * Sync the parent variable product after any of its variations are saved,
 * since is_on_sale() for the parent depends on its variation sale prices
 --------------------------------------------------------------------------*/
function bsf_osm_sync_on_variation_save( $variation_id ) {
	$variation = wc_get_product( $variation_id );
	if ( ! $variation ) {
		return;
	}
	$parent_id = $variation->get_parent_id();
	if ( $parent_id ) {
		bsf_sync_product_sale_category( $parent_id );
	}
}

/*==========================================================================
 * HELPERS
 ==========================================================================*/

/*--------------------------------------------------------------------------
 * Return the "sale" product_cat term. Creates the category if it doesn't
 * exist yet. Guard against redeclaration — the theme may define this too.
 --------------------------------------------------------------------------*/
if ( ! function_exists( 'bsf_get_sale_product_cat_term' ) ) {
	function bsf_get_sale_product_cat_term() {
		$term = get_term_by( 'slug', 'sale', 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term;
		}
		// Create the Sale category on first use
		$result = wp_insert_term( 'Sale', 'product_cat', array( 'slug' => 'sale' ) );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		return get_term( $result['term_id'], 'product_cat' );
	}
}

/*--------------------------------------------------------------------------
 * Add or remove a single product from the sale category based on whether
 * it is both approved and currently on sale.
 *
 * Approved + on sale  → add to sale category
 * Not approved OR not on sale → remove from sale category
 *
 * Guard against redeclaration — the theme may define this too.
 --------------------------------------------------------------------------*/
if ( ! function_exists( 'bsf_sync_product_sale_category' ) ) {
function bsf_sync_product_sale_category( $product_id ) {
	$term = bsf_get_sale_product_cat_term();
	if ( ! $term ) {
		return;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return;
	}

	$approved    = get_option( 'bsf_sale_approved_products', array() );
	$approved    = array_map( 'intval', (array) $approved );
	$is_approved = in_array( (int) $product_id, $approved, true );
	$is_on_sale  = $product->is_on_sale();

	if ( $is_approved && $is_on_sale ) {
		wp_set_object_terms( $product_id, $term->term_id, 'product_cat', true );
	} else {
		wp_remove_object_terms( $product_id, $term->term_id, 'product_cat' );
	}
}
} // end function_exists bsf_sync_product_sale_category

/*==========================================================================
 * SAVE HANDLER
 ==========================================================================*/

/*--------------------------------------------------------------------------
 * Process form submission: save approved products and sync categories
 --------------------------------------------------------------------------*/
function bsf_on_sale_manager_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'bsf_on_sale_save' );

	// Submitted checkboxes are product IDs; cast to int for clean storage
	$submitted = isset( $_POST['bsf_on_sale_approved'] ) ? (array) $_POST['bsf_on_sale_approved'] : array();
	$approved  = array_map( 'intval', $submitted );
	$approved  = array_values( array_filter( $approved ) );

	update_option( 'bsf_sale_approved_products', $approved );

	// Re-sync all products (all statuses) so unchecked items are removed
	// from the Sale category regardless of their publish status
	if ( function_exists( 'wc_get_products' ) ) {
		$all_ids = wc_get_products( array(
			'status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'limit'  => -1,
			'return' => 'ids',
		) );
		foreach ( $all_ids as $product_id ) {
			bsf_sync_product_sale_category( $product_id );
		}
	}
}

/*--------------------------------------------------------------------------
 * Bulk sync all products against the current approval list.
 * Returns an array with counts: synced, added, removed.
 --------------------------------------------------------------------------*/
function bsf_on_sale_manager_bulk_sync() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	check_admin_referer( 'bsf_osm_bulk_sync' );

	if ( ! function_exists( 'wc_get_products' ) ) {
		return false;
	}

	$sale_term = bsf_get_sale_product_cat_term();
	if ( ! $sale_term ) {
		return false;
	}

	$approved = get_option( 'bsf_sale_approved_products', array() );
	$approved = array_map( 'intval', (array) $approved );

	$all_ids = wc_get_products( array(
		'status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
		'limit'  => -1,
		'return' => 'ids',
	) );

	$counts = array( 'synced' => 0, 'added' => 0, 'removed' => 0 );

	foreach ( $all_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			continue;
		}

		$was_in_cat  = has_term( $sale_term->term_id, 'product_cat', $product_id );
		$is_approved = in_array( (int) $product_id, $approved, true );
		$is_on_sale  = $product->is_on_sale();

		if ( $is_approved && $is_on_sale ) {
			wp_set_object_terms( $product_id, $sale_term->term_id, 'product_cat', true );
			if ( ! $was_in_cat ) {
				$counts['added']++;
			}
		} else {
			wp_remove_object_terms( $product_id, $sale_term->term_id, 'product_cat' );
			if ( $was_in_cat ) {
				$counts['removed']++;
			}
		}
		$counts['synced']++;
	}

	return $counts;
}

/*==========================================================================
 * ADMIN PAGE RENDER
 ==========================================================================*/

/*--------------------------------------------------------------------------
 * Reusable bulk sync form — rendered at top (first-time) or bottom (normal)
 --------------------------------------------------------------------------*/
function bsf_osm_render_bulk_sync_form() {
	?>
	<div class="bsf-osm-bulk-sync-wrap">
		<h2 class="bsf-osm-bulk-sync-heading">
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php esc_html_e( 'Re-sync All Products', 'a-neater-woocommerce-admin' ); ?>
		</h2>
		<p class="bsf-osm-bulk-sync-desc">
			<?php esc_html_e( 'Loops through every product and updates the Sale category to match your approved selections. Safe to run any time — useful after importing products, restoring a backup, or if the Sale category looks out of sync.', 'a-neater-woocommerce-admin' ); ?>
		</p>
		<ul class="bsf-osm-bulk-sync-notes">
			<li><?php esc_html_e( 'Approved + currently on sale → added to Sale category', 'a-neater-woocommerce-admin' ); ?></li>
			<li><?php esc_html_e( 'Not approved, or not on sale → removed from Sale category', 'a-neater-woocommerce-admin' ); ?></li>
			<li><?php esc_html_e( 'On large catalogues this may take a few seconds.', 'a-neater-woocommerce-admin' ); ?></li>
		</ul>
		<form method="post" action="">
			<?php wp_nonce_field( 'bsf_osm_bulk_sync' ); ?>
			<input type="submit" name="bsf_osm_bulk_sync" class="button button-primary" value="<?php esc_attr_e( 'Re-sync All', 'a-neater-woocommerce-admin' ); ?>" />
		</form>
	</div>
	<?php
}

/*--------------------------------------------------------------------------
 * Query eligible products: published products that have a sale price set.
 * Variable products are included when any variation has a sale price.
 --------------------------------------------------------------------------*/
function bsf_on_sale_manager_get_eligible_products() {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	// Query all statuses so Draft, Private, Pending etc. still appear in the list
	$all_ids  = wc_get_products( array( 'status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'limit' => -1, 'return' => 'ids' ) );
	$eligible = array();

	foreach ( $all_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			continue;
		}

		if ( $product->is_type( 'variable' ) ) {
			// Include variable products that have at least one variation with a sale price
			$has_sale_price = false;
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation && '' !== $variation->get_sale_price() ) {
					$has_sale_price = true;
					break;
				}
			}
			if ( $has_sale_price ) {
				$eligible[] = $product;
			}
		} else {
			// Simple products: check for a sale price
			if ( '' !== $product->get_sale_price() ) {
				$eligible[] = $product;
			}
		}
	}

	return $eligible;
}

/*--------------------------------------------------------------------------
 * Main admin page callback
 --------------------------------------------------------------------------*/
function bsf_on_sale_manager_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorised.' );
	}

	// Check if Sale category exists BEFORE any processing (get_term_by won't create it)
	$sale_cat_existed = (bool) get_term_by( 'slug', 'sale', 'product_cat' );

	// Process save
	$saved = false;
	if ( isset( $_POST['bsf_on_sale_save'] ) ) {
		bsf_on_sale_manager_save();
		$saved = true;
	}

	// Process bulk sync
	$bulk_synced = false;
	$bulk_counts = null;
	if ( isset( $_POST['bsf_osm_bulk_sync'] ) ) {
		$bulk_counts = bsf_on_sale_manager_bulk_sync();
		$bulk_synced = ( false !== $bulk_counts );
	}

	// Show first-time UI when the category didn't exist before this page load
	// and the user hasn't just run a sync (which would have created it)
	$show_first_time_ui = ( ! $sale_cat_existed && ! $bulk_synced );

	$approved = get_option( 'bsf_sale_approved_products', array() );
	$approved = array_map( 'intval', (array) $approved );
	$products = bsf_on_sale_manager_get_eligible_products();

	// Get the sale term (creates it if needed, e.g. after bulk sync)
	$sale_term    = bsf_get_sale_product_cat_term();
	$sale_term_id = $sale_term ? (int) $sale_term->term_id : 0;

	?>
	<div class="wrap ewneater-dashboard-wrap ewneater-admin-page--full-width bsf-osm-wrap">
		<?php
		if ( function_exists( 'ewneater_admin_page_styles' ) ) {
			ewneater_admin_page_styles();
		}
		?>
		<h1 class="ewneater-dash-title">
			<?php
			if ( function_exists( 'ewneater_admin_breadcrumb' ) ) {
				ewneater_admin_breadcrumb( __( 'On Sale Manager', 'a-neater-woocommerce-admin' ) );
			} else {
				esc_html_e( 'On Sale Manager', 'a-neater-woocommerce-admin' );
			}
			?>
		</h1>
		<?php
		$sale_term_link = $sale_term ? get_term_link( $sale_term, 'product_cat' ) : false;
		if ( $sale_term_link && ! is_wp_error( $sale_term_link ) ) :
		?>
		<div class="ewneater-page-actions">
			<a href="<?php echo esc_url( $sale_term_link ); ?>" target="_blank" class="bsf-osm-view-sale-link">
				<?php esc_html_e( 'View Sale Category', 'a-neater-woocommerce-admin' ); ?> &rarr;
			</a>
		</div>
		<?php endif; ?>

		<?php if ( $show_first_time_ui ) : ?>

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'No Sale category found.', 'a-neater-woocommerce-admin' ); ?></strong>
					<?php esc_html_e( 'Use Re-sync All below to create the Sale category and populate it based on your approved products.', 'a-neater-woocommerce-admin' ); ?>
				</p>
			</div>

			<?php bsf_osm_render_bulk_sync_form(); ?>

		<?php else : ?>

		<p class="bsf-osm-intro">
			<?php esc_html_e( 'Products listed below have a sale price set in WooCommerce. Use the checkboxes to approve which products should appear in the', 'a-neater-woocommerce-admin' ); ?>
			<strong><?php esc_html_e( 'Sale', 'a-neater-woocommerce-admin' ); ?></strong>
			<?php esc_html_e( 'category. Unapproved products will not be added to the Sale category even if they have a discounted price.', 'a-neater-woocommerce-admin' ); ?>
		</p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'On Sale approvals saved and category synced.', 'a-neater-woocommerce-admin' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $bulk_synced && $bulk_counts ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Bulk sync complete.', 'a-neater-woocommerce-admin' ); ?></strong>
					<?php
					printf(
						/* translators: 1: total synced, 2: added, 3: removed */
						esc_html__( '%1$d products checked — %2$d added to Sale category, %3$d removed.', 'a-neater-woocommerce-admin' ),
						(int) $bulk_counts['synced'],
						(int) $bulk_counts['added'],
						(int) $bulk_counts['removed']
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $products ) ) : ?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'No products with a sale price found.', 'a-neater-woocommerce-admin' ); ?></p>
			</div>
		<?php else : ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'bsf_on_sale_save' ); ?>

			<div class="bsf-osm-toolbar">
				<input type="submit" name="bsf_on_sale_save" class="button button-primary bsf-osm-save-top" value="<?php esc_attr_e( 'Save & Sync', 'a-neater-woocommerce-admin' ); ?>" />
				<span class="bsf-osm-toolbar-sep"></span>
				<button type="button" class="button bsf-osm-select-all"><?php esc_html_e( 'Select All', 'a-neater-woocommerce-admin' ); ?></button>
				<button type="button" class="button bsf-osm-filter-toggle" id="bsf-osm-filter-toggle" aria-expanded="false">
					&#9654; <?php esc_html_e( 'Filter', 'a-neater-woocommerce-admin' ); ?>
				</button>
				<span class="bsf-osm-filters-showing"></span>
				<span class="bsf-osm-count"><?php echo count( $products ); ?> <?php echo count( $products ) !== 1 ? esc_html__( 'products', 'a-neater-woocommerce-admin' ) : esc_html__( 'product', 'a-neater-woocommerce-admin' ); ?> <?php esc_html_e( 'with a sale price', 'a-neater-woocommerce-admin' ); ?></span>
			</div>

			<div class="bsf-osm-filters" id="bsf-osm-filters" hidden>
				<div class="bsf-osm-filters-body">

					<div class="bsf-osm-filter-group">
						<div class="bsf-osm-filter-group-label"><?php esc_html_e( 'Status', 'a-neater-woocommerce-admin' ); ?></div>
						<label><input type="checkbox" data-filter="status" value="published" /> <?php esc_html_e( 'Published', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="status" value="draft" /> <?php esc_html_e( 'Draft', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="status" value="pending" /> <?php esc_html_e( 'Pending', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="status" value="private" /> <?php esc_html_e( 'Private', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="status" value="scheduled" /> <?php esc_html_e( 'Scheduled', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="status" value="trash" /> <?php esc_html_e( 'Trash', 'a-neater-woocommerce-admin' ); ?></label>
					</div>

					<div class="bsf-osm-filter-group">
						<div class="bsf-osm-filter-group-label"><?php esc_html_e( 'Visibility', 'a-neater-woocommerce-admin' ); ?></div>
						<label><input type="checkbox" data-filter="visibility" value="public" /> <?php esc_html_e( 'Public', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="visibility" value="privately" /> <?php esc_html_e( 'Privately', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="visibility" value="password protected" /> <?php esc_html_e( 'Password Protected', 'a-neater-woocommerce-admin' ); ?></label>
					</div>

					<div class="bsf-osm-filter-group">
						<div class="bsf-osm-filter-group-label"><?php esc_html_e( 'Currently On Sale', 'a-neater-woocommerce-admin' ); ?></div>
						<label><input type="checkbox" data-filter="on-sale" value="1" /> <?php esc_html_e( 'Yes', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="on-sale" value="0" /> <?php esc_html_e( 'No', 'a-neater-woocommerce-admin' ); ?></label>
					</div>

					<div class="bsf-osm-filter-group">
						<div class="bsf-osm-filter-group-label"><?php esc_html_e( 'In Sale Category', 'a-neater-woocommerce-admin' ); ?></div>
						<label><input type="checkbox" data-filter="in-cat" value="1" /> <?php esc_html_e( 'Yes', 'a-neater-woocommerce-admin' ); ?></label>
						<label><input type="checkbox" data-filter="in-cat" value="0" /> <?php esc_html_e( 'No', 'a-neater-woocommerce-admin' ); ?></label>
					</div>

					<div class="bsf-osm-filter-reset-wrap">
						<button type="button" class="bsf-osm-filters-reset">&#10005; <?php esc_html_e( 'Reset filters', 'a-neater-woocommerce-admin' ); ?></button>
					</div>

				</div>
			</div>

			<div class="bsf-osm-scroll-wrap">
			<table class="wp-list-table widefat striped bsf-osm-table">
				<thead>
					<tr>
						<th class="col-check"><?php esc_html_e( 'Show in Sale Category', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-product"><?php esc_html_e( 'Product', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-price"><?php esc_html_e( 'Regular Price', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-price"><?php esc_html_e( 'Sale Price', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-tags"><?php esc_html_e( 'Tags', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-categories"><?php esc_html_e( 'Categories', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-status"><?php esc_html_e( 'Status', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-visibility"><?php esc_html_e( 'Visibility', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-active"><?php esc_html_e( 'Currently On Sale', 'a-neater-woocommerce-admin' ); ?></th>
						<th class="col-cat"><?php esc_html_e( 'In Sale Category', 'a-neater-woocommerce-admin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $products as $product ) :
					$pid          = $product->get_id();
					$is_approved  = in_array( $pid, $approved, true );
					$is_on_sale   = $product->is_on_sale();
					$in_sale_cat  = $sale_term_id && has_term( $sale_term_id, 'product_cat', $pid );
					$edit_url     = get_edit_post_link( $pid );
					$post_status  = get_post_status( $pid );
					$post         = get_post( $pid );

					// Human-readable status label
					$status_labels = array(
						'publish' => __( 'Published', 'a-neater-woocommerce-admin' ),
						'draft'   => __( 'Draft', 'a-neater-woocommerce-admin' ),
						'pending' => __( 'Pending', 'a-neater-woocommerce-admin' ),
						'private' => __( 'Private', 'a-neater-woocommerce-admin' ),
						'future'  => __( 'Scheduled', 'a-neater-woocommerce-admin' ),
						'trash'   => __( 'Trash', 'a-neater-woocommerce-admin' ),
					);
					$status_label = isset( $status_labels[ $post_status ] ) ? $status_labels[ $post_status ] : ucfirst( $post_status );
					$is_published = ( 'publish' === $post_status );

					// Visibility
					if ( 'private' === $post_status ) {
						$visibility       = __( 'Privately', 'a-neater-woocommerce-admin' );
						$visibility_class = 'bsf-osm-badge--private';
					} elseif ( ! empty( $post->post_password ) ) {
						$visibility       = __( 'Password Protected', 'a-neater-woocommerce-admin' );
						$visibility_class = 'bsf-osm-badge--password';
					} else {
						$visibility       = __( 'Public', 'a-neater-woocommerce-admin' );
						$visibility_class = 'bsf-osm-badge--public';
					}

					// Product tags
					$tag_terms  = get_the_terms( $pid, 'product_tag' );
					$tag_names  = ( $tag_terms && ! is_wp_error( $tag_terms ) )
						? array_map( function( $t ) { return $t->name; }, $tag_terms )
						: array();

					// Product categories (exclude the "sale" slug — that's our internal category)
					$cat_terms  = get_the_terms( $pid, 'product_cat' );
					$cat_names  = array();
					if ( $cat_terms && ! is_wp_error( $cat_terms ) ) {
						foreach ( $cat_terms as $ct ) {
							if ( 'sale' !== $ct->slug ) {
								$cat_names[] = $ct->name;
							}
						}
					}

					if ( $product->is_type( 'variable' ) ) {
						$regular_price = wc_price( $product->get_variation_regular_price( 'min' ) ) . ' – ' . wc_price( $product->get_variation_regular_price( 'max' ) );
						$sale_price    = wc_price( $product->get_variation_sale_price( 'min' ) ) . ' – ' . wc_price( $product->get_variation_sale_price( 'max' ) );
					} else {
						$regular_price = $product->get_regular_price() ? wc_price( $product->get_regular_price() ) : '—';
						$sale_price    = $product->get_sale_price() ? wc_price( $product->get_sale_price() ) : '—';
					}
					?>
					<tr
						class="<?php echo $is_approved ? 'bsf-osm-approved' : ''; ?>"
						data-on-sale="<?php echo $is_on_sale ? '1' : '0'; ?>"
						data-in-cat="<?php echo $in_sale_cat ? '1' : '0'; ?>"
						data-status="<?php echo esc_attr( strtolower( $status_label ) ); ?>"
						data-visibility="<?php echo esc_attr( strtolower( $visibility ) ); ?>"
					>
						<td class="col-check">
							<label class="bsf-osm-checkbox-label">
								<input
									type="checkbox"
									name="bsf_on_sale_approved[]"
									value="<?php echo esc_attr( $pid ); ?>"
									<?php checked( $is_approved ); ?>
								/>
							</label>
						</td>
						<td class="col-product">
							<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank">
								<?php echo esc_html( $product->get_name() ); ?>
							</a>
							<span class="bsf-osm-id">#<?php echo esc_html( $pid ); ?></span>
						</td>
						<td class="col-price"><?php echo wp_kses_post( $regular_price ); ?></td>
						<td class="col-price bsf-osm-sale-price"><?php echo wp_kses_post( $sale_price ); ?></td>
						<td class="col-tags">
							<?php if ( ! empty( $tag_names ) ) : ?>
								<?php foreach ( $tag_names as $tag ) : ?>
									<span class="bsf-osm-tag"><?php echo esc_html( $tag ); ?></span>
								<?php endforeach; ?>
							<?php else : ?>
								<span class="bsf-osm-no-tags">—</span>
							<?php endif; ?>
						</td>
						<td class="col-categories">
							<?php if ( ! empty( $cat_names ) ) : ?>
								<?php foreach ( $cat_names as $cat ) : ?>
									<span class="bsf-osm-tag bsf-osm-cat"><?php echo esc_html( $cat ); ?></span>
								<?php endforeach; ?>
							<?php else : ?>
								<span class="bsf-osm-no-tags">—</span>
							<?php endif; ?>
						</td>
						<td class="col-status">
							<span class="bsf-osm-badge <?php echo $is_published ? 'bsf-osm-badge--published' : 'bsf-osm-badge--unpublished'; ?>">
								<?php echo esc_html( $status_label ); ?>
							</span>
						</td>
						<td class="col-visibility">
							<span class="bsf-osm-badge <?php echo esc_attr( $visibility_class ); ?>">
								<?php echo esc_html( $visibility ); ?>
							</span>
						</td>
						<td class="col-active">
							<?php if ( $is_on_sale ) : ?>
								<span class="bsf-osm-badge bsf-osm-badge--yes"><?php esc_html_e( 'Yes', 'a-neater-woocommerce-admin' ); ?></span>
							<?php else : ?>
								<span class="bsf-osm-badge bsf-osm-badge--no"><?php esc_html_e( 'No', 'a-neater-woocommerce-admin' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="col-cat">
							<?php if ( $in_sale_cat ) : ?>
								<span class="bsf-osm-badge bsf-osm-badge--yes"><?php esc_html_e( 'Yes', 'a-neater-woocommerce-admin' ); ?></span>
							<?php else : ?>
								<span class="bsf-osm-badge bsf-osm-badge--no"><?php esc_html_e( 'No', 'a-neater-woocommerce-admin' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div><!-- /.bsf-osm-scroll-wrap -->

			<div class="bsf-osm-submit">
				<input type="submit" name="bsf_on_sale_save" class="button button-primary button-large" value="<?php esc_attr_e( 'Save & Sync', 'a-neater-woocommerce-admin' ); ?>" />
				<p class="bsf-osm-save-help">
					<?php esc_html_e( 'Saves your checkbox selections and immediately updates the', 'a-neater-woocommerce-admin' ); ?>
					<strong><?php esc_html_e( 'Sale', 'a-neater-woocommerce-admin' ); ?></strong>
					<?php esc_html_e( 'product category. Checked products are added to the Sale category (only if they are currently on sale). Unchecked products are removed. Changes take effect on the front end straight away.', 'a-neater-woocommerce-admin' ); ?>
				</p>
			</div>
		</form>

		<?php endif; // empty products check ?>

		<?php bsf_osm_render_bulk_sync_form(); ?>

		<?php endif; // $show_first_time_ui else ?>

	</div>

	<script>
		(function() {
			var wrap = document.querySelector('.bsf-osm-wrap');
			if ( ! wrap ) return;

			/*------------------------------------------------------------------
			 * TOOLBAR: Select All / Deselect All
			 ------------------------------------------------------------------*/

			// Only target the approval checkboxes (name="bsf_on_sale_approved[]")
			function getApprovalCheckboxes() {
				return Array.from( wrap.querySelectorAll('input[name="bsf_on_sale_approved[]"]') );
			}

			// Returns only approval checkboxes whose row is currently visible
			function getVisibleApprovalCheckboxes() {
				return getApprovalCheckboxes().filter(function(cb) {
					return ! cb.closest('tr').classList.contains('bsf-osm-row-hidden');
				});
			}

			var selectAllBtn = wrap.querySelector('.bsf-osm-select-all');

			function updateSelectAllLabel() {
				var visible    = getVisibleApprovalCheckboxes();
				var allChecked = visible.length > 0 && visible.every(function(cb) { return cb.checked; });
				selectAllBtn.textContent = allChecked ? '<?php echo esc_js( __( 'Deselect All', 'a-neater-woocommerce-admin' ) ); ?>' : '<?php echo esc_js( __( 'Select All', 'a-neater-woocommerce-admin' ) ); ?>';
			}

			selectAllBtn.addEventListener('click', function() {
				var visible    = getVisibleApprovalCheckboxes();
				var allChecked = visible.every(function(cb) { return cb.checked; });
				visible.forEach(function(cb) {
					cb.checked = ! allChecked;
					if ( cb.checked ) {
						cb.closest('tr').classList.add('bsf-osm-approved');
					} else {
						cb.closest('tr').classList.remove('bsf-osm-approved');
					}
				});
				updateSelectAllLabel();
			});

			/*------------------------------------------------------------------
			 * APPROVAL CHECKBOXES: row highlight + shift-click range select
			 ------------------------------------------------------------------*/

			var lastChecked = null;
			var checkboxes  = getApprovalCheckboxes();

			/*------------------------------------------------------------------
			 * ROW CLICK: clicking anywhere on a row toggles the checkbox.
			 * Skip clicks on links and the checkbox itself (already handled).
			 ------------------------------------------------------------------*/
			allRows.forEach(function(row) {
				row.style.cursor = 'pointer';
				row.addEventListener('click', function(e) {
					// Let links open normally
					if ( e.target.tagName === 'A' ) return;
					// Let the checkbox handle its own click to avoid double-toggle
					if ( e.target.tagName === 'INPUT' && e.target.type === 'checkbox' ) return;

					var cb = row.querySelector('input[name="bsf_on_sale_approved[]"]');
					if ( ! cb ) return;

					cb.checked = ! cb.checked;

					// Dispatch a click event so the existing handler runs (row highlight, shift-select, select-all label)
					cb.dispatchEvent( new MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: e.shiftKey }) );
				});
			});

			checkboxes.forEach(function(cb) {
				cb.addEventListener('click', function(e) {
					if ( cb.checked ) {
						cb.closest('tr').classList.add('bsf-osm-approved');
					} else {
						cb.closest('tr').classList.remove('bsf-osm-approved');
					}

					// Shift+click: apply same state to the range
					if ( e.shiftKey && lastChecked && lastChecked !== cb ) {
						var from  = checkboxes.indexOf( lastChecked );
						var to    = checkboxes.indexOf( cb );
						var start = Math.min( from, to );
						var end   = Math.max( from, to );
						for ( var i = start; i <= end; i++ ) {
							checkboxes[i].checked = cb.checked;
							if ( cb.checked ) {
								checkboxes[i].closest('tr').classList.add('bsf-osm-approved');
							} else {
								checkboxes[i].closest('tr').classList.remove('bsf-osm-approved');
							}
						}
					}

					lastChecked = cb;
					updateSelectAllLabel();
				});
			});

			/*------------------------------------------------------------------
			 * FILTER PANEL: toggle open/close
			 ------------------------------------------------------------------*/

			var filterPanel   = wrap.querySelector('#bsf-osm-filters');
			var filterToggle  = wrap.querySelector('#bsf-osm-filter-toggle');
			var showingLabel  = wrap.querySelector('.bsf-osm-filters-showing');

			filterToggle.addEventListener('click', function() {
				var isOpen = ! filterPanel.hidden;
				filterPanel.hidden = isOpen;
				filterToggle.setAttribute('aria-expanded', ! isOpen);
				if ( isOpen ) {
					filterToggle.innerHTML = '&#9654; <?php echo esc_js( __( 'Filter', 'a-neater-woocommerce-admin' ) ); ?>';
					filterToggle.classList.remove('is-active');
				} else {
					filterToggle.innerHTML = '&#9660; <?php echo esc_js( __( 'Filter', 'a-neater-woocommerce-admin' ) ); ?>';
					filterToggle.classList.add('is-active');
				}
			});

			/*------------------------------------------------------------------
			 * FILTER PANEL: apply filters
			 ------------------------------------------------------------------*/

			var allRows = Array.from( wrap.querySelectorAll('.bsf-osm-table tbody tr') );

			function applyFilters() {
				// Build active filter sets per group
				var groups    = ['status', 'visibility', 'on-sale', 'in-cat'];
				var active    = {};
				var anyActive = false;

				groups.forEach(function(group) {
					var checked = Array.from(
						filterPanel.querySelectorAll('input[data-filter="' + group + '"]:checked')
					).map(function(cb) { return cb.value; });
					active[group] = checked;
					if ( checked.length ) anyActive = true;
				});

				// Update group label highlight
				groups.forEach(function(group) {
					var groupEl = filterPanel.querySelector(
						'input[data-filter="' + group + '"]'
					).closest('.bsf-osm-filter-group');
					if ( active[group].length ) {
						groupEl.classList.add('has-active');
					} else {
						groupEl.classList.remove('has-active');
					}
				});

				// Show/hide rows
				var visible = 0;
				allRows.forEach(function(row) {
					var pass = true;

					if ( active.status.length && active.status.indexOf( row.dataset.status ) === -1 ) {
						pass = false;
					}
					if ( active.visibility.length && active.visibility.indexOf( row.dataset.visibility ) === -1 ) {
						pass = false;
					}
					if ( active['on-sale'].length && active['on-sale'].indexOf( row.dataset.onSale ) === -1 ) {
						pass = false;
					}
					if ( active['in-cat'].length && active['in-cat'].indexOf( row.dataset.inCat ) === -1 ) {
						pass = false;
					}

					if ( pass ) {
						row.classList.remove('bsf-osm-row-hidden');
						visible++;
					} else {
						row.classList.add('bsf-osm-row-hidden');
					}
				});

				// Update showing label
				if ( anyActive ) {
					showingLabel.textContent = '<?php echo esc_js( __( 'Showing', 'a-neater-woocommerce-admin' ) ); ?> ' + visible + ' <?php echo esc_js( __( 'of', 'a-neater-woocommerce-admin' ) ); ?> ' + allRows.length;
				} else {
					showingLabel.textContent = '';
				}
			}

			// Listen to filter checkbox changes
			filterPanel.querySelectorAll('input[data-filter]').forEach(function(cb) {
				cb.addEventListener('change', function() {
					applyFilters();
					updateSelectAllLabel();
				});
			});

			// Reset button
			filterPanel.querySelector('.bsf-osm-filters-reset').addEventListener('click', function(e) {
				e.stopPropagation();
				filterPanel.querySelectorAll('input[data-filter]').forEach(function(cb) {
					cb.checked = false;
				});
				applyFilters();
				updateSelectAllLabel();
			});

		})();
	</script>
	<?php
}
