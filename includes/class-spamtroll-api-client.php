<?php
/**
 * Spamtroll API Client
 *
 * @package Spamtroll
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP client for communicating with the Spamtroll API.
 */
class Spamtroll_Api_Client {

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * Timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key  API key (loads from settings if null).
	 * @param string|null $base_url Base URL (loads from settings if null).
	 * @param int|null    $timeout  Timeout in seconds (loads from settings if null).
	 */
	/**
	 * Pinned production API base URL. No longer configurable from
	 * wp-admin — admins only paste an API key.
	 */
	const API_BASE_URL = 'https://api.spamtroll.io/api/v1';

	/**
	 * Default request timeout (seconds).
	 */
	const DEFAULT_TIMEOUT = 5;

	public function __construct( $api_key = null, $base_url = null, $timeout = null ) {
		$settings = get_option( 'spamtroll_settings', array() );

		$this->api_key  = $api_key !== null ? $api_key : ( isset( $settings['api_key'] ) ? $settings['api_key'] : '' );
		$this->base_url = rtrim( $base_url !== null ? $base_url : self::API_BASE_URL, '/' );
		$this->timeout  = $timeout !== null ? $timeout : self::DEFAULT_TIMEOUT;
	}

	/**
	 * Check if API is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->api_key );
	}

	/**
	 * Test API connection.
	 *
	 * @return Spamtroll_Api_Response
	 * @throws Spamtroll_Api_Exception On connection failure.
	 */
	public function test_connection() {
		return $this->request( 'GET', '/scan/status' );
	}

	/**
	 * Get account usage statistics.
	 *
	 * @return Spamtroll_Api_Response
	 * @throws Spamtroll_Api_Exception On connection failure.
	 */
	public function get_account_usage() {
		return $this->request( 'GET', '/account/usage' );
	}

	/**
	 * Check content for spam.
	 *
	 * @param string      $content    Content to check.
	 * @param string      $source     Source type (comment, registration).
	 * @param string|null $ip_address IP address.
	 * @param string|null $username   Username.
	 * @param string|null $email      Email address.
	 * @return Spamtroll_Api_Response
	 * @throws Spamtroll_Api_Exception On connection failure.
	 */
	public function check_spam( $content, $source = 'comment', $ip_address = null, $username = null, $email = null ) {
		$data = array(
			'content' => $content,
			'source'  => $source,
		);

		if ( $ip_address ) {
			$data['ip_address'] = $ip_address;
		}

		if ( $username ) {
			$data['username'] = $username;
		}

		if ( $email ) {
			$data['email'] = $email;
		}

		return $this->request( 'POST', '/scan/check', $data );
	}

	/**
	 * Make an API request.
	 *
	 * @param string     $method   HTTP method (GET or POST).
	 * @param string     $endpoint API endpoint path.
	 * @param array|null $data     Request body data for POST requests.
	 * @return Spamtroll_Api_Response
	 * @throws Spamtroll_Api_Exception On connection or API errors.
	 */
	protected function request( $method, $endpoint, $data = null ) {
		if ( ! $this->is_configured() ) {
			throw Spamtroll_Api_Exception::not_configured();
		}

		$url  = $this->base_url . $endpoint;
		$args = array(
			'timeout'   => $this->timeout,
			'sslverify' => true,
			'headers'   => array(
				'X-API-Key'    => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'Spamtroll-WordPress/' . SPAMTROLL_VERSION,
			),
		);

		if ( 'POST' === $method && null !== $data ) {
			$args['body'] = wp_json_encode( $data );
			$response     = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			if ( false !== strpos( strtolower( $error_message ), 'timed out' ) || false !== strpos( strtolower( $error_message ), 'timeout' ) ) {
				throw Spamtroll_Api_Exception::timeout();
			}
			throw Spamtroll_Api_Exception::connection_failed( $error_message );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $body, true );

		if ( $http_code >= 200 && $http_code < 300 ) {
			return new Spamtroll_Api_Response( true, $http_code, is_array( $decoded ) ? $decoded : array() );
		}

		$error_message = 'API error';
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['error'] ) ) {
				$error_message = is_array( $decoded['error'] ) ? wp_json_encode( $decoded['error'] ) : (string) $decoded['error'];
			} elseif ( isset( $decoded['message'] ) ) {
				$error_message = is_array( $decoded['message'] ) ? wp_json_encode( $decoded['message'] ) : (string) $decoded['message'];
			}
		}

		return new Spamtroll_Api_Response(
			false,
			$http_code,
			is_array( $decoded ) ? $decoded : array(),
			$error_message
		);
	}
}
