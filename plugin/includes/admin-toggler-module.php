<?php
/*
 * ==========================================================================
 * ADMIN TOGGLER MODULE
 * 
 * Provides a modular, reusable toggle component for use in WordPress admin
 * screens. Allows sections to be collapsed/expanded with state saved per user.
 * 
 * USAGE EXAMPLES:
 * --------------------------------------------------------------------------
 * 1. Include this file in your plugin or theme:
 *      require_once plugin_dir_path(__FILE__) . 'admin-toggler-module.php';
 * 
 * 2. Output a toggle section anywhere in the admin (e.g. user profile, settings page):
 *      $content = '<p>This is your toggle content!</p>';
 *      echo EWNeater_Admin_Toggle::render('my_unique_toggle', 'My Toggle Title', $content);
 * 
 * 3. You can use multiple toggles on the same page, just use a unique $toggle_id for each:
 *      echo EWNeater_Admin_Toggle::render('section1', 'Section 1', $section1_content);
 *      echo EWNeater_Admin_Toggle::render('section2', 'Section 2', $section2_content);
 * 
 * 4. Optional: Customize icon or add extra classes:
 *      echo EWNeater_Admin_Toggle::render(
 *          'advanced_settings',
 *          'Advanced Settings',
 *          $advanced_content,
 *          [
 *              'icon' => 'dashicons-admin-generic',
 *              'container_class' => 'my-custom-container',
 *              'header_class' => 'my-header',
 *              'content_class' => 'my-content'
 *          ]
 *      );
 * 
 * ==========================================================================
 */

/**
 * Modular Toggle Component for WordPress Admin
 */
// Check if class already exists before declaring it
if (!class_exists('EWNeater_Admin_Toggle')) {
    class EWNeater_Admin_Toggle {
        /**
         * Initialize the toggle component: adds styles, scripts, and AJAX handler
         */
        public static function init() {
            add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
            add_action('wp_ajax_save_toggle_state', [self::class, 'save_toggle_state']);
        }

        /**
         * Enqueue CSS and JS for the toggle component
         */
        public static function enqueue_assets() {
            $screen = get_current_screen();
            if (!$screen || !in_array($screen->id, ['user-edit', 'profile'])) {
                return;
            }

            $base = dirname(__FILE__) . '/../a-neater-woocommerce-admin.php';
            $plugin_dir = plugin_dir_path($base);

            wp_enqueue_style(
                'ewneater-admin-toggler',
                plugins_url('css/admin-toggler.css', $base),
                [],
                filemtime($plugin_dir . 'css/admin-toggler.css')
            );

            wp_enqueue_script(
                'ewneater-admin-toggler',
                plugins_url('js/admin-toggler.js', $base),
                ['jquery'],
                filemtime($plugin_dir . 'js/admin-toggler.js'),
                true
            );

            wp_localize_script('ewneater-admin-toggler', 'ewneaterAdminToggler', [
                'nonce' => wp_create_nonce('save_toggle_state'),
            ]);
        }

        /**
         * AJAX handler: Save toggle state (collapsed/expanded) per user and section
         */
        public static function save_toggle_state() {
            check_ajax_referer('save_toggle_state', 'nonce');
            
            $user_id = get_current_user_id();
            if (!$user_id) {
                wp_send_json_error('User not logged in');
                return;
            }

            $toggle_id = isset($_POST['toggle_id']) ? sanitize_text_field($_POST['toggle_id']) : '';
            $collapsed = isset($_POST['collapsed']) ? sanitize_text_field($_POST['collapsed']) : '0';
            
            update_user_meta($user_id, 'ew_toggle_state_' . $toggle_id, $collapsed);
            
            wp_send_json_success();
        }

        /**
         * Render a toggle section
         * 
         * @param string $toggle_id   Unique identifier for the toggle (per section)
         * @param string $title       Title of the toggle section
         * @param string $content     Content to display inside the toggle
         * @param array  $args        (Optional) Additional arguments: icon, classes
         * @return string             HTML output for the toggle section
         */
        public static function render($toggle_id, $title, $content, $args = []) {
            $defaults = [
                'icon' => 'dashicons-arrow-down-alt2',
                'container_class' => '',
                'header_class' => '',
                'content_class' => ''
            ];
            $args = wp_parse_args($args, $defaults);

            // Check saved state for this user and toggle
            $collapsed = get_user_meta(get_current_user_id(), 'ew_toggle_state_' . $toggle_id, true) === '1';
            $header_class = $collapsed ? 'collapsed' : '';
            $content_class = $collapsed ? 'collapsed' : '';

            $output = sprintf(
                '<div class="ew-toggle-container %s" data-toggle-id="%s">',
                esc_attr($args['container_class']),
                esc_attr($toggle_id)
            );
            $output .= sprintf(
                '<h2 class="ew-toggle-header %s"><span class="toggle-icon dashicons %s"></span>%s</h2>',
                esc_attr(trim($args['header_class'] . ' ' . $header_class)),
                esc_attr($args['icon']),
                wp_kses_post($title)
            );
            $output .= sprintf(
                '<div class="ew-toggle-content %s">%s</div>',
                esc_attr(trim($args['content_class'] . ' ' . $content_class)),
                $content
            );
            $output .= '</div>';
            return $output;
        }
    }

    // Initialize the toggle component on admin load
    EWNeater_Admin_Toggle::init();

} // End of class

?>
