<?php
/**
 * Reviews List Page UI Enhancements
 *
 * Adds layout customisations for the WooCommerce Product Reviews page (product_page_product-reviews):
 * - Wrap bulk actions in left-container, move search box into tablenav (matches Orders layout)
 * - Change search button text to "GO"
 *
 * Only loads on the WooCommerce product reviews page.
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/*==========================================================================
 * REVIEWS PAGE BODY CLASS (for scoped CSS)
 ==========================================================================*/
add_filter('admin_body_class', function ($classes) {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'product_page_product-reviews') {
        $classes .= ' ewneater-reviews-page ';
    }
    return $classes;
});

/*==========================================================================
 * REVIEWS LIST LAYOUT (match Orders: bulk actions + search in tablenav)
 ==========================================================================*/
add_action('admin_head', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'product_page_product-reviews') {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Wrap bulk actions in a container
        $(".tablenav.top .bulkactions").wrap("<div class='left-container'></div>");

        // Move search box into tablenav
        var $searchBox = $(".search-box");
        $searchBox.appendTo(".tablenav.top");

        // Change search button text to GO
        var $searchButton = $("input[type=submit][value='Search Reviews']");
        if ($searchButton.length) {
            $searchButton.val("GO");
        }
    });
    </script>
    <?php
});
