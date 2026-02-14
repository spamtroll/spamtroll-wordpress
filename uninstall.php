<?php
/**
 * Spamtroll Uninstall
 *
 * Cleans up all plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package Spamtroll
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin settings.
delete_option( 'spamtroll_settings' );

// Drop the logs table.
global $wpdb;
$table_name = $wpdb->prefix . 'spamtroll_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'spamtroll_cleanup_logs' );
