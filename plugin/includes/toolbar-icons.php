<?php
/**
 * Toolbar Icons Settings
 *
 * Provides an admin UI to show/hide admin bar toolbar icons with desktop and mobile
 * columns. Saves preferences to the database.
 *
 * @package A_Neater_Woocommerce_Admin
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Icon IDs that must always be visible (cannot be hidden).
 * menu-toggle: required for mobile – opens the admin bar menu.
 * wp-logo: core WordPress branding – About WordPress link.
 *
 * @return string[]
 */
function ewneater_toolbar_required_ids()
{
    return ['menu-toggle', 'wp-logo'];
}

/**
 * Default visible IDs when no option has been saved (matches previous hardcoded allowlist).
 */
function ewneater_toolbar_default_visible_ids()
{
    return [
        'desktop' => [
            'menu-toggle',
            'wp-logo',
            'site-name',
            'woocommerce-site-visibility-badge',
            'updates',
            'comments',
            'new-content',
            'ewneater-quick-find',
            'wp-rocket',
        ],
        'mobile' => [
            'menu-toggle',
            'site-name',
            'ewneater-quick-find',
        ],
    ];
}

/**
 * Returns visible toolbar IDs from option or defaults.
 *
 * @return array{desktop: string[], mobile: string[]}
 */
function ewneater_get_toolbar_visible_ids()
{
    $option = get_option('ewneater_toolbar_icons_visibility', []);
    $defaults = ewneater_toolbar_default_visible_ids();

    $desktop = isset($option['desktop']) && is_array($option['desktop'])
        ? $option['desktop']
        : $defaults['desktop'];
    $mobile = isset($option['mobile']) && is_array($option['mobile'])
        ? $option['mobile']
        : $defaults['mobile'];

    $required = ewneater_toolbar_required_ids();
    $desktop = array_values(array_unique(array_merge($required, array_filter($desktop, 'is_string'))));
    $mobile = array_values(array_unique(array_merge($required, array_filter($mobile, 'is_string'))));

    return [
        'desktop' => $desktop,
        'mobile' => $mobile,
    ];
}

/**
 * Captured admin bar nodes (root-default direct children).
 *
 * @var array<int, object>|null
 */
$ewneater_toolbar_nodes_cache = null;

/**
 * Captures root-default admin bar nodes. Runs on every admin page load so the
 * Toolbar Icons settings page has a full list when displayed.
 */
function ewneater_toolbar_capture_nodes($admin_bar)
{
    global $ewneater_toolbar_nodes_cache;

    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    if (!$admin_bar || !method_exists($admin_bar, 'get_nodes')) {
        $ewneater_toolbar_nodes_cache = [];
        return;
    }

    $nodes = $admin_bar->get_nodes();
    if (!is_array($nodes)) {
        $ewneater_toolbar_nodes_cache = [];
        return;
    }

    /*
     * Top-level items: core adds them with no parent (false); _bind() later assigns
     * them to the root-default group. So we must accept parent 'root-default', 'root',
     * or false/empty to list all items that appear in #wp-admin-bar-root-default.
     */
    $root_default = [];
    foreach ($nodes as $node) {
        if (!is_object($node) || !isset($node->id)) {
            continue;
        }
        if ($node->id === 'root') {
            continue;
        }
        $parent = isset($node->parent) ? $node->parent : '';
        if ($parent !== 'root-default' && $parent !== 'root' && $parent !== false && $parent !== '') {
            continue;
        }
        $root_default[] = $node;
    }

    /* Preserve toolbar order (same order as admin_bar_menu callbacks add nodes). */
    $ewneater_toolbar_nodes_cache = $root_default;
}
add_action('admin_bar_menu', 'ewneater_toolbar_capture_nodes', 9999);

/**
 * Returns the plugin/source name for a toolbar node ID.
 * Filterable via 'ewneater_toolbar_plugin_for_node'.
 *
 * @param string $node_id Admin bar node ID.
 * @return string Human-readable plugin name.
 */
function ewneater_toolbar_get_plugin_for_node($node_id)
{
    $map = [
        'menu-toggle' => 'WordPress',
        'wp-logo' => 'WordPress',
        'site-name' => 'WordPress',
        'updates' => 'WordPress',
        'comments' => 'WordPress',
        'new-content' => 'WordPress',
        'ewneater-quick-find' => 'A Neater Admin',
        'wp-rocket' => 'WP Rocket',
    ];
    if (strpos($node_id, 'woocommerce-') === 0) {
        return 'WooCommerce';
    }
    if (strpos($node_id, 'elementor') === 0) {
        return 'Elementor';
    }
    if (strpos($node_id, 'updraft') === 0) {
        return 'UpdraftPlus';
    }
    if (strpos($node_id, 'bfwc_') === 0) {
        return 'Bold Subscriptions';
    }
    if (isset($map[$node_id])) {
        return $map[$node_id];
    }
    $result = apply_filters('ewneater_toolbar_plugin_for_node', '', $node_id);
    return is_string($result) && $result !== '' ? $result : __('Plugin or theme', 'a-neater-woocommerce-admin');
}

/**
 * Returns the dashicon class for a toolbar node ID (for use in Icon column).
 * Filterable via 'ewneater_toolbar_icon_class_for_node'.
 *
 * @param string $node_id Admin bar node ID.
 * @return string Dashicon class (e.g. 'dashicons-menu') or empty string.
 */
function ewneater_toolbar_get_icon_class($node_id)
{
    $map = [
        'menu-toggle' => 'dashicons-menu',
        'wp-logo' => 'dashicons-wordpress',
        'site-name' => 'dashicons-admin-home',
        'updates' => 'dashicons-update',
        'comments' => 'dashicons-admin-comments',
        'new-content' => 'dashicons-plus-alt',
        'ewneater-quick-find' => 'dashicons-search',
    ];
    if (strpos($node_id, 'woocommerce-') === 0) {
        return 'dashicons-cart';
    }
    if (strpos($node_id, 'wp-rocket') === 0) {
        return 'dashicons-performance';
    }
    if (isset($map[$node_id])) {
        return $map[$node_id];
    }
    $result = apply_filters('ewneater_toolbar_icon_class_for_node', '', $node_id);
    return is_string($result) && $result !== '' ? $result : 'dashicons-admin-generic';
}

/**
 * Displays the Toolbar Icons settings page.
 */
function ewneater_toolbar_display_page()
{
    global $ewneater_toolbar_nodes_cache;

    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'a-neater-woocommerce-admin'));
    }

    $nodes = $ewneater_toolbar_nodes_cache;
    if (!is_array($nodes)) {
        $nodes = [];
    }

    $option = get_option('ewneater_toolbar_icons_visibility', []);
    $defaults = ewneater_toolbar_default_visible_ids();
    $desktop_visible = isset($option['desktop']) && is_array($option['desktop'])
        ? $option['desktop']
        : $defaults['desktop'];
    $mobile_visible = isset($option['mobile']) && is_array($option['mobile'])
        ? $option['mobile']
        : $defaults['mobile'];

    $notice = '';

    if (isset($_POST['ewneater_toolbar_icons_nonce'])) {
        if (!check_admin_referer('ewneater_toolbar_icons', 'ewneater_toolbar_icons_nonce')) {
            wp_die(__('Security check failed', 'a-neater-woocommerce-admin'));
        }

        $known_ids = [];
        foreach ($nodes as $node) {
            $known_ids[$node->id] = true;
        }

        $submitted_desktop = [];
        $submitted_mobile = [];
        $allowed = ['both', 'desktop', 'mobile', 'none'];
        if (isset($_POST['toolbar_visibility']) && is_array($_POST['toolbar_visibility'])) {
            foreach ($_POST['toolbar_visibility'] as $id => $value) {
                $id = sanitize_text_field(wp_unslash($id));
                $value = isset($value) ? sanitize_text_field(wp_unslash($value)) : 'none';
                if (!isset($known_ids[$id]) || !in_array($value, $allowed, true)) {
                    continue;
                }
                if ($value === 'both' || $value === 'desktop') {
                    $submitted_desktop[] = $id;
                }
                if ($value === 'both' || $value === 'mobile') {
                    $submitted_mobile[] = $id;
                }
            }
        }

        $required = ewneater_toolbar_required_ids();
        $submitted_desktop = array_values(array_unique(array_merge($required, $submitted_desktop)));
        $submitted_mobile = array_values(array_unique(array_merge($required, $submitted_mobile)));

        update_option('ewneater_toolbar_icons_visibility', [
            'desktop' => $submitted_desktop,
            'mobile' => $submitted_mobile,
            'updated' => current_time('mysql'),
        ]);

        $desktop_visible = $submitted_desktop;
        $mobile_visible = $submitted_mobile;
        $notice = __('Toolbar visibility saved.', 'a-neater-woocommerce-admin');
    }

    echo '<div class="wrap ewneater-dashboard-wrap ewneater-admin-page--full-width ewneater-page--toolbar-icons">';
    if (function_exists('ewneater_admin_page_styles')) {
        ewneater_admin_page_styles();
    }
    echo '<h1 class="ewneater-dash-title">';
    if (function_exists('ewneater_admin_breadcrumb')) {
        ewneater_admin_breadcrumb(__('Toolbar Icons', 'a-neater-woocommerce-admin'));
    } else {
        echo esc_html__('Toolbar Icons', 'a-neater-woocommerce-admin');
    }
    echo '</h1>';

    /* IDs that are currently hidden (not shown on desktop or mobile) for preview-mode styling */
    $hidden_ids = [];
    foreach ($nodes as $node) {
        $id = $node->id;
        if (!in_array($id, $desktop_visible, true) && !in_array($id, $mobile_visible, true)) {
            $hidden_ids[] = $id;
        }
    }

    echo '<h2 class="ewneater-toolbar-table-title">' . esc_html__('Icons visible in the toolbar', 'a-neater-woocommerce-admin') . '</h2>';
    echo '<p class="ewneater-dash-intro ewneater-toolbar-table-desc">' . esc_html__('This table controls which admin bar icons are shown. Turn on Interactive Mode to click toolbar icons to show or hide them; or toggle Visible/Hidden per row. When Visible, use Desktop and Mobile to control each viewport. Save changes when done.', 'a-neater-woocommerce-admin') . '</p>';

    if (!empty($notice)) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }

    echo '<form id="ewneater-toolbar-icons-form" method="post" action="">';
    wp_nonce_field('ewneater_toolbar_icons', 'ewneater_toolbar_icons_nonce');

    echo '<div class="ewneater-toolbar-actions-row">';
    echo '<button type="submit" id="ewneater-toolbar-icons-save-top" class="button button-primary ewneater-toolbar-save-btn" disabled aria-disabled="true">' . esc_html__('Save Changes', 'a-neater-woocommerce-admin') . '</button>';
    echo '<label class="ewneater-toolbar-toggle" title="' . esc_attr__('Click toolbar icons to show or hide them', 'a-neater-woocommerce-admin') . '">';
    echo '<input type="checkbox" id="ewneater-toolbar-preview-toggle" />';
    echo '<span class="ewneater-toolbar-toggle-slider"></span>';
    echo '<span class="ewneater-toolbar-toggle-label">' . esc_html__('Interactive Mode', 'a-neater-woocommerce-admin') . '</span>';
    echo '</label>';
    echo '<input type="search" id="ewneater-toolbar-icons-filter" class="ewneater-toolbar-icons-filter" placeholder="' . esc_attr__('Filter…', 'a-neater-woocommerce-admin') . '" autocomplete="off" aria-label="' . esc_attr__('Filter toolbar icons', 'a-neater-woocommerce-admin') . '" />';
    echo '</div>';
    echo '<style>.ewneater-toolbar-icons-table tbody tr.ewneater-filter-hidden { display: none !important; }</style>';

    echo '<table class="ewneater-admin-table widefat striped ewneater-toolbar-icons-table ewneater-quick-find-menus-table">';
    echo '<thead><tr>';
    echo '<th class="ewneater-toolbar-icon-col">' . esc_html__('Icon', 'a-neater-woocommerce-admin') . '</th>';
    echo '<th class="ewneater-toolbar-plugin-col">' . esc_html__('Plugin', 'a-neater-woocommerce-admin') . '</th>';
    echo '<th class="ewneater-toolbar-visibility-col">' . esc_html__('Visible in toolbar', 'a-neater-woocommerce-admin') . '</th>';
    echo '<th>' . esc_html__('ID', 'a-neater-woocommerce-admin') . '</th>';
    echo '<th class="ewneater-toolbar-unsaved-col" scope="col" aria-label="' . esc_attr__('Unsaved', 'a-neater-woocommerce-admin') . '"></th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($nodes as $node) {
        $id = $node->id;
        $title = isset($node->title) ? wp_strip_all_tags($node->title) : $id;
        if (strlen($title) > 60) {
            $title = substr($title, 0, 57) . '...';
        }
        $plugin_name = ewneater_toolbar_get_plugin_for_node($id);
        $row_search = strtolower($title . ' ' . $id . ' ' . $plugin_name);

        $on_desktop = in_array($id, $desktop_visible, true);
        $on_mobile = in_array($id, $mobile_visible, true);
        if ($on_desktop && $on_mobile) {
            $current_value = 'both';
        } elseif ($on_desktop) {
            $current_value = 'desktop';
        } elseif ($on_mobile) {
            $current_value = 'mobile';
        } else {
            $current_value = 'none';
        }

        $radios_name = 'toolbar_visibility[' . esc_attr($id) . ']';
        $is_visible = $current_value !== 'none';
        $is_required = in_array($id, ewneater_toolbar_required_ids(), true);
        $row_hidden_class = ($current_value === 'none' && !$is_required) ? ' ewneater-toolbar-row--hidden' : '';
        $row_required_attr = $is_required ? ' data-required="1"' : '';
        echo '<tr class="ewneater-toolbar-icons-row ewneater-quick-find-menu-row' . $row_hidden_class . ($is_required ? ' ewneater-toolbar-row--required' : '') . '" data-search="' . esc_attr($row_search) . '" data-node-id="' . esc_attr($id) . '"' . $row_required_attr . '>';
        $icon_class = ewneater_toolbar_get_icon_class($id);
        $icon_html = $icon_class ? '<span class="ewneater-toolbar-icon-preview dashicons ' . esc_attr($icon_class) . '" aria-hidden="true"></span>' : '';
        echo '<td class="ewneater-toolbar-icon-cell">' . $icon_html . '<span class="ewneater-toolbar-icon-label">' . esc_html($title) . '</span></td>';
        echo '<td class="ewneater-toolbar-plugin-cell" title="' . esc_attr(sprintf(__('Added by: %s', 'a-neater-woocommerce-admin'), $plugin_name)) . '">';
        echo '<span class="ewneater-toolbar-plugin-badge">' . esc_html($plugin_name) . '</span>';
        echo '</td>';
        echo '<td class="ewneater-toolbar-pills-cell">';
        echo '<div class="ewneater-toolbar-pills-wrap">';
        if ($is_required) {
            echo '<input type="hidden" name="' . $radios_name . '" value="both" />';
            echo '<span class="ewneater-pill ewneater-pill--required" title="' . esc_attr__('Required – cannot be hidden (needed for mobile menu and WordPress branding)', 'a-neater-woocommerce-admin') . '">' . esc_html__('Always visible', 'a-neater-woocommerce-admin') . '</span>';
        } else {
            echo '<input type="radio" name="' . $radios_name . '" value="both" id="' . esc_attr($id . '-both') . '" ' . checked($current_value, 'both', false) . ' class="ewneater-toolbar-radio-sr" />';
            echo '<input type="radio" name="' . $radios_name . '" value="desktop" id="' . esc_attr($id . '-desktop') . '" ' . checked($current_value, 'desktop', false) . ' class="ewneater-toolbar-radio-sr" />';
            echo '<input type="radio" name="' . $radios_name . '" value="mobile" id="' . esc_attr($id . '-mobile') . '" ' . checked($current_value, 'mobile', false) . ' class="ewneater-toolbar-radio-sr" />';
            echo '<input type="radio" name="' . $radios_name . '" value="none" id="' . esc_attr($id . '-none') . '" ' . checked($current_value, 'none', false) . ' class="ewneater-toolbar-radio-sr" />';
            echo '<div class="ewneater-toolbar-pills ewneater-pill-single-row">';
            $label_visible = esc_attr__('Visible', 'a-neater-woocommerce-admin');
            $label_hidden = esc_attr__('Hidden', 'a-neater-woocommerce-admin');
            $tip_visible = esc_attr__('Click to hide this icon in the toolbar', 'a-neater-woocommerce-admin');
            $tip_hidden = esc_attr__('Click to show this icon in the toolbar', 'a-neater-woocommerce-admin');
            $tip_desktop_on = esc_attr__('Click to hide on desktop', 'a-neater-woocommerce-admin');
            $tip_desktop_off = esc_attr__('Click to show on desktop', 'a-neater-woocommerce-admin');
            $tip_mobile_on = esc_attr__('Click to hide on mobile', 'a-neater-woocommerce-admin');
            $tip_mobile_off = esc_attr__('Click to show on mobile', 'a-neater-woocommerce-admin');
            echo '<button type="button" class="ewneater-pill ewneater-pill-toggle' . ($is_visible ? ' ewneater-pill--selected' : '') . '" data-value="' . esc_attr($current_value) . '" data-label-visible="' . $label_visible . '" data-label-hidden="' . $label_hidden . '" data-tip-visible="' . $tip_visible . '" data-tip-hidden="' . $tip_hidden . '" title="' . ($is_visible ? $tip_visible : $tip_hidden) . '" aria-pressed="' . ($is_visible ? 'true' : 'false') . '" aria-label="' . esc_attr__('Toggle visibility', 'a-neater-woocommerce-admin') . '">' . ($is_visible ? esc_html__('Visible', 'a-neater-woocommerce-admin') : esc_html__('Hidden', 'a-neater-woocommerce-admin')) . '</button>';
            $desktop_on = $current_value === 'both' || $current_value === 'desktop';
            $mobile_on = $current_value === 'both' || $current_value === 'mobile';
            echo '<div class="ewneater-pill-sub-row' . ($is_visible ? '' : ' ewneater-pill-sub-row--hidden') . '">';
            echo '<button type="button" class="ewneater-pill ewneater-pill--sub ewneater-pill-channel' . ($desktop_on ? ' ewneater-pill--selected' : '') . '" data-channel="desktop" data-tip-on="' . $tip_desktop_on . '" data-tip-off="' . $tip_desktop_off . '" title="' . ($desktop_on ? $tip_desktop_on : $tip_desktop_off) . '" aria-pressed="' . ($desktop_on ? 'true' : 'false') . '">' . esc_html__('Desktop', 'a-neater-woocommerce-admin') . '</button>';
            echo '<button type="button" class="ewneater-pill ewneater-pill--sub ewneater-pill-channel' . ($mobile_on ? ' ewneater-pill--selected' : '') . '" data-channel="mobile" data-tip-on="' . $tip_mobile_on . '" data-tip-off="' . $tip_mobile_off . '" title="' . ($mobile_on ? $tip_mobile_on : $tip_mobile_off) . '" aria-pressed="' . ($mobile_on ? 'true' : 'false') . '">' . esc_html__('Mobile', 'a-neater-woocommerce-admin') . '</button>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</td>';
        echo '<td><code>' . esc_html($id) . '</code></td>';
        echo '<td class="ewneater-toolbar-unsaved-cell"><span class="ewneater-toolbar-unsaved-icon dashicons dashicons-marker" aria-hidden="true" title="' . esc_attr__('Unsaved changes', 'a-neater-woocommerce-admin') . '"></span></td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '<div class="ewneater-admin-actions ewneater-toolbar-icons-save ewneater-quick-find-menu-save">';
    echo '<button type="submit" id="ewneater-toolbar-icons-save" class="button button-primary ewneater-toolbar-save-btn" disabled aria-disabled="true">' . esc_html__('Save Changes', 'a-neater-woocommerce-admin') . '</button>';
    echo '</div>';

    $hidden_ids_js = wp_json_encode(array_values($hidden_ids));
    echo '<script type="text/javascript">var ewneaterToolbarHiddenIds = ' . $hidden_ids_js . ';</script>';

    echo '<script>
        jQuery(document).ready(function($) {
            var $form = $("#ewneater-toolbar-icons-form");
            var $saveButtons = $(".ewneater-toolbar-save-btn");
            var $filter = $("#ewneater-toolbar-icons-filter");
            var $toggle = $("#ewneater-toolbar-preview-toggle");
            var hiddenIds = typeof ewneaterToolbarHiddenIds !== "undefined" ? ewneaterToolbarHiddenIds : [];
            var previewStyleId = "ewneater-toolbar-preview-style";
            var bannerId = "ewneater-preview-mode-banner";

            if (!$form.length) return;

            function getRowValue(id) {
                var $inp = $form.find("input[name=\"toolbar_visibility[" + id + "]\"]");
                return $inp.filter(":checked").val() || $inp.val() || "none";
            }

            function getVisibilityFromForm() {
                var desktop = [], mobile = [];
                $form.find("tr[data-node-id]").each(function() {
                    var id = $(this).data("node-id");
                    var val = getRowValue(id);
                    if (val === "both" || val === "desktop") desktop.push(id);
                    if (val === "both" || val === "mobile") mobile.push(id);
                });
                return { desktop: desktop, mobile: mobile };
            }

            function updateToolbarPreview() {
                var v = getVisibilityFromForm();
                var base = "#wp-admin-bar-root-default > li { display: none !important; } ";
                var desktopSelectors = v.desktop.map(function(id) {
                    return "#wp-admin-bar-root-default > #wp-admin-bar-" + id;
                }).join(", ");
                var mobileSelectors = v.mobile.map(function(id) {
                    return "#wp-admin-bar-root-default > #wp-admin-bar-" + id;
                }).join(", ");
                var style = base;
                if (desktopSelectors) {
                    style += "@media (min-width: 783px) { " + desktopSelectors + " { display: block !important; } } ";
                }
                if (mobileSelectors) {
                    style += "@media (max-width: 782px) { " + mobileSelectors + " { display: block !important; } }";
                }
                var $el = $("#" + previewStyleId);
                if (!$el.length) {
                    $el = $("<style>").attr("id", previewStyleId).appendTo("head");
                }
                $el.text(style);
            }

            function getHiddenIdsFromForm() {
                var hidden = [];
                $form.find("tr[data-node-id]").each(function() {
                    var id = $(this).data("node-id");
                    if ($(this).data("required")) return;
                    var val = getRowValue(id);
                    if (val === "none") hidden.push(id);
                });
                return hidden;
            }

            function updatePreviewHiddenHighlight() {
                document.querySelectorAll("#wp-admin-bar-root-default > li").forEach(function(li) {
                    var id = li.id.replace("wp-admin-bar-", "");
                    var hidden = getHiddenIdsFromForm().indexOf(id) !== -1;
                    if (hidden) li.classList.add("ewneater-toolbar-preview-hidden");
                    else li.classList.remove("ewneater-toolbar-preview-hidden");
                });
            }

            function setInteractiveMode(on) {
                if (on) {
                    document.documentElement.classList.add("ewneater-toolbar-preview-all", "ewneater-toolbar-interactive");
                    updatePreviewHiddenHighlight();
                    if (!document.getElementById(bannerId)) {
                        var b = document.createElement("div");
                        b.id = bannerId;
                        b.className = "ewneater-preview-mode-banner";
                        b.setAttribute("aria-live", "polite");
                        b.textContent = "INTERACTIVE MODE — Click toolbar icons to show or hide them. Save changes when done.";
                        var adminBar = document.getElementById("wpadminbar");
                        if (adminBar && adminBar.parentNode) {
                            adminBar.parentNode.insertBefore(b, adminBar.nextSibling);
                        } else {
                            document.body.insertBefore(b, document.body.firstChild);
                        }
                    }
                    $(document).on("click.ewneaterInteractive", "#wp-admin-bar-root-default > li", function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var id = this.id.replace("wp-admin-bar-", "");
                        var $row = $form.find("tr[data-node-id=\"" + id + "\"]");
                        if (!$row.length || $row.data("required")) return;
                        var current = getRowValue(id);
                        var newVal = (current === "none" || !current) ? "both" : "none";
                        setRowValue($row, newVal);
                    });
                } else {
                    document.documentElement.classList.remove("ewneater-toolbar-preview-all", "ewneater-toolbar-interactive");
                    document.querySelectorAll(".ewneater-toolbar-preview-hidden").forEach(function(el) { el.classList.remove("ewneater-toolbar-preview-hidden"); });
                    $(document).off("click.ewneaterInteractive");
                    var b = document.getElementById(bannerId);
                    if (b) b.remove();
                }
            }

            if ($toggle.length) {
                $toggle.on("change", function() {
                    setInteractiveMode(this.checked);
                });
            }

            var $radios = $form.find("input[type=\"radio\"]");
            $radios.each(function() {
                var name = $(this).attr("name");
                $(this).data("initial", $(this).val());
            });
            $form.find("tr[data-node-id]").each(function() {
                var id = $(this).data("node-id");
                $(this).data("initialValue", getRowValue(id));
            });

            function isDirty() {
                var dirty = false;
                $form.find("tr[data-node-id]").each(function() {
                    var id = $(this).data("node-id");
                    var current = getRowValue(id);
                    if ($(this).data("initialValue") !== current) {
                        dirty = true;
                        return false;
                    }
                });
                return dirty;
            }

            function updateSaveButton() {
                if (isDirty()) {
                    $saveButtons.prop("disabled", false).attr("aria-disabled", "false").removeClass("ewneater-toolbar-save-btn--dimmed");
                } else {
                    $saveButtons.prop("disabled", true).attr("aria-disabled", "true").addClass("ewneater-toolbar-save-btn--dimmed");
                }
            }

            function updateUnsavedIndicators() {
                $form.find("tr[data-node-id]").each(function() {
                    var $row = $(this);
                    var id = $row.data("node-id");
                    var current = getRowValue(id);
                    var dirty = $row.data("initialValue") !== current;
                    $row.toggleClass("ewneater-toolbar-row--dirty", dirty).toggleClass("ewneater-toolbar-row--hidden", current === "none");
                });
            }

            function setRowValue($row, value) {
                var id = $row.data("node-id");
                var $radio = $form.find("input[name=\"toolbar_visibility[" + id + "]\"][value=\"" + value + "\"]");
                if (!$radio.length) return;
                $radio.prop("checked", true);
                var $wrap = $row.find(".ewneater-toolbar-pills-wrap");
                var isVisible = value !== "none";
                var $toggleBtn = $wrap.find(".ewneater-pill-toggle");
                $toggleBtn.toggleClass("ewneater-pill--selected", isVisible).attr("aria-pressed", isVisible ? "true" : "false").attr("title", isVisible ? $toggleBtn.data("tip-visible") : $toggleBtn.data("tip-hidden")).data("value", value);
                $toggleBtn.text(isVisible ? $toggleBtn.data("label-visible") : $toggleBtn.data("label-hidden"));
                $wrap.find(".ewneater-pill-sub-row").toggleClass("ewneater-pill-sub-row--hidden", !isVisible);
                var desktopOn = value === "both" || value === "desktop";
                var mobileOn = value === "both" || value === "mobile";
                var $desktopBtn = $wrap.find(".ewneater-pill-channel[data-channel=\"desktop\"]");
                var $mobileBtn = $wrap.find(".ewneater-pill-channel[data-channel=\"mobile\"]");
                $desktopBtn.toggleClass("ewneater-pill--selected", desktopOn).attr("aria-pressed", desktopOn ? "true" : "false").attr("title", desktopOn ? $desktopBtn.data("tip-on") : $desktopBtn.data("tip-off"));
                $mobileBtn.toggleClass("ewneater-pill--selected", mobileOn).attr("aria-pressed", mobileOn ? "true" : "false").attr("title", mobileOn ? $mobileBtn.data("tip-on") : $mobileBtn.data("tip-off"));
                $row.toggleClass("ewneater-toolbar-row--hidden", value === "none");
                updateSaveButton();
                updateUnsavedIndicators();
                updateToolbarPreview();
                if ($toggle.length && $toggle.prop("checked")) updatePreviewHiddenHighlight();
            }

            $form.on("click", ".ewneater-pill-toggle", function(e) {
                e.preventDefault();
                var $row = $(this).closest("tr[data-node-id]");
                var id = $row.data("node-id");
                var current = $form.find("input[name=\"toolbar_visibility[" + id + "]\"]:checked").val();
                var newVal = (current === "none" || !current) ? "both" : "none";
                setRowValue($row, newVal);
            });
            $form.on("click", ".ewneater-pill-channel", function(e) {
                e.preventDefault();
                var $btn = $(this);
                var channel = $btn.data("channel");
                var $row = $btn.closest("tr[data-node-id]");
                var id = $row.data("node-id");
                var current = $form.find("input[name=\"toolbar_visibility[" + id + "]\"]:checked").val();
                var desktopOn = current === "both" || current === "desktop";
                var mobileOn = current === "both" || current === "mobile";
                if (channel === "desktop") desktopOn = !desktopOn;
                else mobileOn = !mobileOn;
                var newVal = desktopOn && mobileOn ? "both" : desktopOn ? "desktop" : mobileOn ? "mobile" : "none";
                setRowValue($row, newVal);
            });

            $form.on("change", "input[type=\"radio\"]", function() {
                updateSaveButton();
                updateUnsavedIndicators();
                updateToolbarPreview();
                if ($toggle.length && $toggle.prop("checked")) updatePreviewHiddenHighlight();
            });
            updateSaveButton();
            updateUnsavedIndicators();
            updateToolbarPreview();

            if ($filter.length) {
                $filter.on("input", function() {
                    var term = $(this).val().toLowerCase();
                    $(".ewneater-toolbar-icons-row").each(function() {
                        var $row = $(this);
                        if (!term || $row.data("search").indexOf(term) !== -1) {
                            $row.removeClass("ewneater-filter-hidden");
                        } else {
                            $row.addClass("ewneater-filter-hidden");
                        }
                    });
                });
            }
        });
    </script>';
    echo '</form>';
    echo '</div>';
}
