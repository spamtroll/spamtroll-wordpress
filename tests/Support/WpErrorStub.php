<?php

declare(strict_types=1);

namespace Spamtroll\Tests\Support;

/**
 * Stands in for WP_Error where the plugin only ever reads the message.
 *
 * `wp_remote_request()` reports transport failures — DNS, refused
 * connections, timeouts — as a WP_Error, and the transport adapter turns the
 * message into the SDK's ConnectionException or TimeoutException. That
 * string is the whole contract, so the double is the whole string.
 */
final class WpErrorStub
{
    public function __construct(private readonly string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}
