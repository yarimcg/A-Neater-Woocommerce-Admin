<?php
/**
 * Users List Page UI Enhancements
 *
 * Adds layout customisations for the WordPress Users list page (users):
 * - Wrap bulk actions in left-container, move search box into tablenav (matches Orders layout)
 * - Change search button text to "GO"
 *
 * Only loads on the Users list page.
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/*==========================================================================
 * USERS PAGE BODY CLASS (for scoped CSS)
 ==========================================================================*/
add_filter('admin_body_class', function ($classes) {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'users') {
        $classes .= ' ewneater-users-page ';
    }
    return $classes;
});

/*==========================================================================
 * USERS LIST LAYOUT (match Orders: bulk actions + search in tablenav)
 ==========================================================================*/
add_action('admin_head', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'users') {
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
        var $searchButton = $("input[type=submit][value='Search Users']");
        if ($searchButton.length) {
            $searchButton.val("GO");
        }
    });
    </script>
    <?php
});
