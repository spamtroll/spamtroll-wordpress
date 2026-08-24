<?php

declare(strict_types=1);
/**
 * Spamtroll Uninstall
 *
 * Cleans up all plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package Spamtroll
 *
 * @since   0.1.0
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/**
 * Remove everything this plugin created on the current site.
 *
 * Uninstalling used to leave the quota log, every cached verdict and — on
 * multisite — every subsite's table and settings behind, because
 * delete_option() and DROP TABLE both act on whichever blog happens to be
 * current. For a plugin that stores IP addresses, email addresses and
 * excerpts of what visitors wrote, "mostly deleted" is not deleted.
 */
function spamtroll_uninstall_site(): void
{
    global $wpdb;

    foreach (
        [
            'spamtroll_settings',
            'spamtroll_quota_skipped_log',
            'spamtroll_circuit',
            'spamtroll_api_health',
            'spamtroll_db_version',
        ] as $option
    ) {
        delete_option($option);
    }

    // Cached verdicts. Transients live in wp_options on sites without a
    // persistent object cache, and there is no core API for deleting a
    // family of them by prefix.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_spamtroll_scan_') . '%',
            $wpdb->esc_like('_transient_timeout_spamtroll_scan_') . '%',
        ),
    );
    wp_cache_flush();

    $table_name = $wpdb->prefix . 'spamtroll_logs';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

    wp_clear_scheduled_hook('spamtroll_cleanup_logs');
    wp_clear_scheduled_hook('spamtroll_send_feedback');
}

if (is_multisite()) {
    foreach (get_sites([ 'fields' => 'ids', 'number' => 0 ]) as $blog_id) {
        switch_to_blog((int) $blog_id);
        spamtroll_uninstall_site();
        restore_current_blog();
    }
} else {
    spamtroll_uninstall_site();
}
