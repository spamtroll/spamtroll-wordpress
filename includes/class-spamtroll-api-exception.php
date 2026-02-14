<?php
/**
 * Spamtroll API Exception
 *
 * @package Spamtroll
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception class for Spamtroll API errors.
 */
class Spamtroll_Api_Exception extends RuntimeException {

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	public $http_code;

	/**
	 * API error code.
	 *
	 * @var string|null
	 */
	public $api_error_code;

	/**
	 * Response data.
	 *
	 * @var array|null
	 */
	public $response_data;

	/**
	 * Constructor.
	 *
	 * @param string         $message        Error message.
	 * @param int            $http_code      HTTP status code.
	 * @param string|null    $api_error_code API error code.
	 * @param array|null     $response_data  Response data.
	 * @param Throwable|null $previous       Previous exception.
	 */
	public function __construct(
		$message,
		$http_code = 0,
		$api_error_code = null,
		$response_data = null,
		$previous = null
	) {
		parent::__construct( $message, $http_code, $previous );

		$this->http_code      = $http_code;
		$this->api_error_code = $api_error_code;
		$this->response_data  = $response_data;
	}

	/**
	 * Create exception from HTTP response.
	 *
	 * @param int        $http_code HTTP status code.
	 * @param array|null $data      Response data.
	 * @return static
	 */
	public static function from_response( $http_code, $data = null ) {
		$message        = isset( $data['error'] ) ? $data['error'] : ( isset( $data['message'] ) ? $data['message'] : 'Unknown API error' );
		$api_error_code = isset( $data['code'] ) ? $data['code'] : null;

		return new static( $message, $http_code, $api_error_code, $data );
	}

	/**
	 * Create connection failed exception.
	 *
	 * @param string $error Connection error message.
	 * @return static
	 */
	public static function connection_failed( $error ) {
		return new static( 'Connection failed: ' . $error, 0 );
	}

	/**
	 * Create timeout exception.
	 *
	 * @return static
	 */
	public static function timeout() {
		return new static( 'Request timed out', 0 );
	}

	/**
	 * Create invalid API key exception.
	 *
	 * @return static
	 */
	public static function invalid_api_key() {
		return new static( 'Invalid API key', 401, 'INVALID_API_KEY' );
	}

	/**
	 * Create not configured exception.
	 *
	 * @return static
	 */
	public static function not_configured() {
		return new static( 'API key not configured', 0, 'NOT_CONFIGURED' );
	}
}
