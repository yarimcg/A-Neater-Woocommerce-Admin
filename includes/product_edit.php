<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
	exit;
}

/*==========================================================================
 * PRODUCT EDIT HEADER ENHANCEMENTS
 * 
 * Adds:
 * - Product name after "Edit Product" with proper spacing and entity handling
 * - Persistent "View product" link on the right, opening in a new tab
 ==========================================================================*/
add_action('admin_head', function () {
	$screen = get_current_screen();
	if ($screen && $screen->id === 'product') {
		$post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
		if (!$post_id) {
			return;
		}

		$permalink = get_permalink($post_id);
		// Decode entities on the PHP side, then safely emit as a JS string
		$product_title = wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES);
		?>
		<style>
			.ewneater-view-product-link {
				margin-left: 10px;
				font-size: 13px;
				font-weight: 500;
				text-decoration: none;
				display: inline-flex;
				align-items: center;
				gap: 2px;
			}
			.ewneater-view-product-link .dashicons {
				font-size: 14px;
				line-height: 1;
				position: relative;
				top: 2px;
			}
		</style>
		<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function() {
			var heading = document.querySelector('.woocommerce-layout__header-heading, h1.woocommerce-layout__header-heading') ||
						  document.querySelector('#wpbody-content .wrap h1') ||
						  document.querySelector('#wpbody-content h1');
			if (!heading) return;

			var productName = <?php echo wp_json_encode($product_title); ?>;
			var productUrl = <?php echo wp_json_encode(esc_url($permalink)); ?>;

			// Add product name after "Edit Product"
			if (!heading.querySelector('.ewneater-product-name')) {
				var nameSpan = document.createElement('span');
				nameSpan.className = 'ewneater-product-name';
				// Force a non-breaking space after the dash to ensure visible gap
				nameSpan.appendChild(document.createTextNode('\u00A0- ' + productName));
				heading.appendChild(nameSpan);
			}

			// Right-aligned "View product" link
			if (!heading.querySelector('.ewneater-view-product-link')) {
				var link = document.createElement('a');
				link.className = 'ewneater-view-product-link';
				link.href = productUrl;
				link.target = '_blank';
				link.rel = 'noopener';
				link.title = 'Preview product in a new tab';
				link.setAttribute('aria-label', 'Preview product in a new tab');
				link.appendChild(document.createTextNode('View product'));

				var icon = document.createElement('span');
				icon.className = 'dashicons dashicons-external';
				link.appendChild(icon);

				heading.appendChild(link);
			}
		});
		</script>
		<?php
	}
});



