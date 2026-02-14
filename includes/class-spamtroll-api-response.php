<?php
/**
 * Spamtroll API Response
 *
 * @package Spamtroll
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper for Spamtroll API responses with score normalization.
 */
class Spamtroll_Api_Response {

	/**
	 * Success status.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	public $http_code;

	/**
	 * Response data.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Error message.
	 *
	 * @var string|null
	 */
	public $error;

	/**
	 * Scan result data (extracted from nested response).
	 *
	 * @var array
	 */
	protected $scan_data;

	/**
	 * Constructor.
	 *
	 * @param bool        $success   Success status.
	 * @param int         $http_code HTTP status code.
	 * @param array       $data      Response data.
	 * @param string|null $error     Error message.
	 */
	public function __construct( $success, $http_code, $data = array(), $error = null ) {
		$this->success   = $success;
		$this->http_code = $http_code;
		$this->data      = $data;
		$this->error     = $error;

		// Extract nested data from API response format: {success: true, data: {...}}.
		$this->scan_data = isset( $data['data'] ) ? $data['data'] : $data;
	}

	/**
	 * Check if content is spam.
	 *
	 * @return bool
	 */
	public function is_spam() {
		if ( ! $this->success ) {
			return false;
		}

		$status = isset( $this->scan_data['status'] ) ? $this->scan_data['status'] : 'safe';
		return 'blocked' === $status;
	}

	/**
	 * Get status (blocked, suspicious, safe).
	 *
	 * @return string
	 */
	public function get_status() {
		return isset( $this->scan_data['status'] ) ? $this->scan_data['status'] : 'safe';
	}

	/**
	 * Get spam score normalized to 0-1 range.
	 * API uses 0-15+ scale, we normalize to 0-1.
	 *
	 * @return float
	 */
	public function get_spam_score() {
		$raw_score = (float) ( isset( $this->scan_data['spam_score'] ) ? $this->scan_data['spam_score'] : 0.0 );
		return min( 1.0, max( 0.0, $raw_score / 15.0 ) );
	}

	/**
	 * Get raw spam score (API native scale).
	 *
	 * @return float
	 */
	public function get_raw_spam_score() {
		return (float) ( isset( $this->scan_data['spam_score'] ) ? $this->scan_data['spam_score'] : 0.0 );
	}

	/**
	 * Get detection symbols.
	 *
	 * @return array
	 */
	public function get_symbols() {
		$symbols = isset( $this->scan_data['symbols'] ) ? $this->scan_data['symbols'] : array();
		return array_map(
			function ( $s ) {
				return is_array( $s ) ? ( isset( $s['name'] ) ? $s['name'] : '' ) : $s;
			},
			$symbols
		);
	}

	/**
	 * Get full symbol details.
	 *
	 * @return array
	 */
	public function get_symbol_details() {
		return isset( $this->scan_data['symbols'] ) ? $this->scan_data['symbols'] : array();
	}

	/**
	 * Get threat categories.
	 *
	 * @return array
	 */
	public function get_threat_categories() {
		return isset( $this->scan_data['threat_categories'] ) ? $this->scan_data['threat_categories'] : array();
	}

	/**
	 * Get request ID.
	 *
	 * @return string|null
	 */
	public function get_request_id() {
		return isset( $this->data['request_id'] ) ? $this->data['request_id'] : null;
	}

	/**
	 * Check if response indicates valid API connection.
	 *
	 * @return bool
	 */
	public function is_connection_valid() {
		return $this->success && $this->http_code >= 200 && $this->http_code < 300;
	}

	/**
	 * Get API usage data (for account/usage endpoint).
	 *
	 * @return array
	 */
	public function get_usage_data() {
		return array(
			'requests_today'     => isset( $this->data['requests_today'] ) ? $this->data['requests_today'] : 0,
			'requests_limit'     => isset( $this->data['requests_limit'] ) ? $this->data['requests_limit'] : 0,
			'requests_remaining' => isset( $this->data['requests_remaining'] ) ? $this->data['requests_remaining'] : 0,
		);
	}
}
