<?php
/*==========================================================================
 * PLUGIN HELPER FUNCTIONS
 *
 * Shared utilities used across the plugin:
 * - Pending reviews count (cached)
 * - Company email detection (filterable)
 * - Wholesale user detection
 ==========================================================================*/

if (!defined("ABSPATH")) {
    exit;
}

if (!function_exists("ewneater_get_pending_reviews_count")) {
    /**
     * Get count of pending product reviews. Result is cached via transient.
     *
     * @return int
     */
    function ewneater_get_pending_reviews_count()
    {
        $cache_key = "ewneater_pending_reviews_count";
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}comments WHERE comment_approved = '0' AND comment_type = 'review'"
        );

        set_transient($cache_key, $count, 5 * MINUTE_IN_SECONDS);

        return $count;
    }
}

if (!function_exists("ewneater_clear_pending_reviews_cache")) {
    /**
     * Clear the pending reviews count cache. Call on transition_comment_status.
     */
    function ewneater_clear_pending_reviews_cache()
    {
        delete_transient("ewneater_pending_reviews_count");
    }
}

if (!function_exists("ewneater_is_company_email")) {
    /**
     * Check if email is a known company email (e.g. for special display).
     * Filterable via ewneater_company_emails.
     *
     * @param string $email
     * @return bool
     */
    function ewneater_is_company_email($email)
    {
        $company_emails = apply_filters("ewneater_company_emails", [
            "hi@blacksheepfarmoils.com.au",
            "hi@blacksheepfarmoils.com",
        ]);

        return !empty($email) && in_array($email, (array) $company_emails, true);
    }
}

if (!function_exists("ewneater_is_wholesale_user")) {
    /**
     * Check if user has wholesale_buyer role.
     *
     * @param WP_User|null $user
     * @return bool
     */
    function ewneater_is_wholesale_user($user)
    {
        return $user && in_array("wholesale_buyer", (array) $user->roles, true);
    }
}
