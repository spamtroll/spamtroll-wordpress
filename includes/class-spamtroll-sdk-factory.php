<?php

declare(strict_types=1);
/**
 * Factory for constructing a ready-to-use SDK Client from WP settings.
 *
 * @package Spamtroll
 */

if (! defined('ABSPATH')) {
    exit;
}

use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Version;

class Spamtroll_Sdk_Factory
{
    /**
     * Attempts allowed for one scan.
     *
     * One, not the SDK's three. Retrying is a bet that the next attempt will
     * differ from the last, and on this path it almost never does: 401, 403
     * and 429 are the server stating its own condition, and a connection
     * failure inside a three-second budget will not have resolved itself a
     * few hundred milliseconds later. Meanwhile the cost of the bet is paid
     * by a visitor sitting in front of a frozen comment form. The scan fails
     * open, so a missed retry costs one unscanned comment; the retries cost
     * every visitor during an outage.
     */
    public const MAX_RETRIES = 1;

    /**
     * Build a Spamtroll SDK client from the saved plugin settings.
     *
     * Call sites that already have an API key (e.g. AJAX test-connection
     * with an unsaved key) may pass it in to override what's in the DB.
     *
     * @param string|null $api_key_override Key to use instead of the stored one.
     * @param Spamtroll_Wp_Http_Client|null $http Transport to reuse, so the caller can read
     *                                            its response headers after the call.
     */
    public static function client(?string $api_key_override = null, ?Spamtroll_Wp_Http_Client $http = null): Client
    {
        $api_key = $api_key_override ?? Spamtroll_Settings::string('api_key');

        $config = new ClientConfig(
            baseUrl: Spamtroll_Settings::api_url(),
            timeout: Spamtroll_Settings::timeout(),
            maxRetries: self::MAX_RETRIES,
            userAgent: 'Spamtroll-WordPress/' . SPAMTROLL_VERSION . ' spamtroll-php-sdk/' . Version::VERSION,
        );

        return new Client($api_key, $config, $http ?? new Spamtroll_Wp_Http_Client());
    }
}
