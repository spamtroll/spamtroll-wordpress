<?php
/**
 * Spamtroll Logger
 *
 * @package Spamtroll
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database logger for spam scan results.
 */
class Spamtroll_Logger {

	/**
	 * Get the log table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'spamtroll_logs';
	}

	/**
	 * Create the log table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			content_type VARCHAR(50) NOT NULL DEFAULT '',
			content_id BIGINT(20) UNSIGNED DEFAULT NULL,
			ip_address VARCHAR(46) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'safe',
			spam_score DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
			raw_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
			symbols TEXT DEFAULT NULL,
			threat_categories TEXT DEFAULT NULL,
			action_taken VARCHAR(20) NOT NULL DEFAULT 'allow',
			content_preview TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY content_type (content_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a scan result.
	 *
	 * @param array $entry Log entry data.
	 * @return bool True on success, false on failure.
	 */
	public static function log( $entry ) {
		global $wpdb;

		try {
			$data = array(
				'user_id'           => isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : null,
				'content_type'      => isset( $entry['content_type'] ) ? sanitize_text_field( $entry['content_type'] ) : '',
				'content_id'        => isset( $entry['content_id'] ) ? absint( $entry['content_id'] ) : null,
				'ip_address'        => isset( $entry['ip_address'] ) ? sanitize_text_field( $entry['ip_address'] ) : null,
				'status'            => isset( $entry['status'] ) ? sanitize_text_field( $entry['status'] ) : 'safe',
				'spam_score'        => isset( $entry['spam_score'] ) ? floatval( $entry['spam_score'] ) : 0.0,
				'raw_score'         => isset( $entry['raw_score'] ) ? floatval( $entry['raw_score'] ) : 0.0,
				'symbols'           => isset( $entry['symbols'] ) ? wp_json_encode( $entry['symbols'] ) : null,
				'threat_categories' => isset( $entry['threat_categories'] ) ? wp_json_encode( $entry['threat_categories'] ) : null,
				'action_taken'      => isset( $entry['action_taken'] ) ? sanitize_text_field( $entry['action_taken'] ) : 'allow',
				'content_preview'   => isset( $entry['content_preview'] ) ? mb_substr( sanitize_text_field( $entry['content_preview'] ), 0, 500 ) : null,
				'created_at'        => current_time( 'mysql' ),
			);

			$formats = array( '%d', '%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s' );

			return (bool) $wpdb->insert( self::get_table_name(), $data, $formats );
		} catch ( Exception $e ) {
			// Never block on logging errors.
			error_log( 'Spamtroll Logger error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Clean up old log entries.
	 *
	 * @param int $retention_days Number of days to retain logs.
	 * @return int Number of rows deleted.
	 */
	public static function cleanup( $retention_days = 30 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$days       = absint( $retention_days );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	/**
	 * Get recent log entries.
	 *
	 * @param array $args Query arguments.
	 * @return array {
	 *     @type array $logs  Log entries.
	 *     @type int   $total Total count.
	 * }
	 */
	public static function get_recent_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'per_page' => 20,
			'page'     => 1,
		);
		$args     = wp_parse_args( $args, $defaults );

		$table_name = self::get_table_name();
		$where      = '1=1';
		$params     = array();

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_text_field( $args['status'] );
		}

		$per_page = absint( $args['per_page'] );
		$offset   = ( absint( $args['page'] ) - 1 ) * $per_page;

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE {$where}", $params ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					array_merge( $params, array( $per_page, $offset ) )
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE {$where}" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'logs'  => is_array( $logs ) ? $logs : array(),
			'total' => $total,
		);
	}

	/**
	 * Get a single log entry.
	 *
	 * @param int $id Log entry ID.
	 * @return array|null Log entry or null.
	 */
	public static function get_log( $id ) {
		global $wpdb;
		$table_name = self::get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);
	}

	/**
	 * Delete a log entry.
	 *
	 * @param int $id Log entry ID.
	 * @return bool True on success.
	 */
	public static function delete_log( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::get_table_name(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}
}
