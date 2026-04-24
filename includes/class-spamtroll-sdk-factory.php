<?php
/**
 * Factory for constructing a ready-to-use SDK Client from WP settings.
 *
 * @package Spamtroll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Version;

class Spamtroll_Sdk_Factory {

	/**
	 * Build a Spamtroll SDK client from the saved plugin settings.
	 *
	 * Call sites that already have an API key (e.g. AJAX test-connection
	 * with an unsaved key) may pass it in to override what's in the DB.
	 */
	public static function client( ?string $api_key_override = null ): Client {
		$settings = get_option( 'spamtroll_settings', array() );
		$api_key  = $api_key_override !== null
			? $api_key_override
			: ( isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '' );

		$config = new ClientConfig(
			userAgent: 'Spamtroll-WordPress/' . SPAMTROLL_VERSION . ' spamtroll-php-sdk/' . Version::VERSION
		);

		return new Client( $api_key, $config, new Spamtroll_Wp_Http_Client() );
	}
}
