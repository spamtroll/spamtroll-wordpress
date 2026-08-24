<?php

declare(strict_types=1);
/**
 * Spamtroll SDK HTTP adapter for WordPress.
 *
 * Routes SDK requests through wp_remote_* so WordPress HTTP filters
 * (http_request_args, pre_http_request, proxy settings, SSL overrides)
 * still apply to every Spamtroll API call.
 *
 * @package Spamtroll
 */

if (! defined('ABSPATH')) {
    exit;
}

use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;
use Spamtroll\Sdk\Http\HttpClientInterface;
use Spamtroll\Sdk\Http\HttpResponse;

/**
 * The SDK transport, plus the two things the SDK's own interface cannot say.
 *
 * `Client::dispatch()` collapses a response into [success, code, decoded,
 * error], so the HTTP headers never cross that boundary — `Retry-After` and
 * the `X-RateLimit-*` family are simply unreachable from `checkSpam()`. Its
 * retry loop is likewise blind to wall-clock time: it counts attempts, not
 * seconds, so three attempts at a five-second timeout is a fifteen-second
 * request no matter how long the visitor has been waiting.
 *
 * Both gaps are closed here rather than in the SDK, because this adapter
 * belongs to the plugin while the SDK is shared by six of them. When the SDK
 * grows a deadline of its own, `set_deadline()` becomes a thin forward and
 * the check in `send()` can go.
 */
class Spamtroll_Wp_Http_Client implements HttpClientInterface
{
    /**
     * Largest response body worth reading, in bytes.
     *
     * A scan response is a few kilobytes; the 402 body with its usage block
     * is the biggest thing the API sends. 256 KiB is generous enough that no
     * real answer is truncated and small enough that a misrouted one cannot
     * be a memory event.
     */
    public const MAX_RESPONSE_BYTES = 262144;

    /**
     * Headers of the most recent response, keyed by lowercased name.
     *
     * @var array<string, string>
     */
    private array $last_headers = [];

    /**
     * Monotonic deadline in nanoseconds, or null when unbounded.
     */
    private ?float $deadline = null;

    /**
     * Sets a hard wall-clock deadline for subsequent calls.
     *
     * A per-request timeout bounds one attempt; this bounds the whole scan,
     * retries included. It is the difference between "the API is slow" and
     * "the visitor's comment form has been frozen for sixteen seconds".
     *
     * @param float|null $deadline A value from hrtime(true), or null to remove it.
     */
    public function set_deadline(?float $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * Headers of the most recent response, keyed by lowercased name.
     *
     * @return array<string, string>
     */
    public function get_last_headers(): array
    {
        return $this->last_headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $this->last_headers = [];

        // Refusing before the socket opens is what makes the budget a budget.
        // ConnectionException rather than a bespoke type because the SDK
        // already treats it as retryable-then-fatal, and the plugin already
        // fails open on it.
        if (null !== $this->deadline) {
            $remaining_ns = $this->deadline - hrtime(true);
            if ($remaining_ns <= 0) {
                throw ConnectionException::fromMessage('Spamtroll latency budget exhausted before the request was sent');
            }
            // Never let one attempt overrun what is left of the budget.
            $timeout = max(1, min($timeout, (int) ceil($remaining_ns / 1_000_000_000)));
        }

        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'sslverify' => true,
            'headers' => $headers,
            // Do not follow redirects. wp_remote_request() follows five by
            // default, and Requests replays the request headers on each hop —
            // including X-API-Key. A redirect this client cannot see, from a
            // hijacked DNS entry or a compromised intermediary, would hand the
            // platform's key to whatever host answered. Platform keys have no
            // TTL, so that leak is permanent. There is no legitimate redirect
            // on this API: every endpoint answers directly, and a 3xx is
            // therefore something to fail open on, not to chase.
            'redirection' => 0,
            // A body larger than this is not a scan result. Without a cap, a
            // misrouted response streams into a PHP string while a visitor
            // waits on a comment form.
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
        ];

        if ('POST' === $method && null !== $body) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $lower = strtolower($message);
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                throw TimeoutException::afterSeconds($timeout);
            }
            throw ConnectionException::fromMessage($message);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $this->last_headers = self::flatten_headers(wp_remote_retrieve_headers($response));

        return new HttpResponse($status, $raw, $this->last_headers);
    }

    /**
     * Normalizes whatever wp_remote_retrieve_headers() handed back.
     *
     * It returns a Requests dictionary on a real response and a plain array
     * on a filtered one, and a repeated header arrives as a list.
     *
     * @param mixed $headers
     *
     * @return array<string, string>
     */
    private static function flatten_headers($headers): array
    {
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            /** @var array<string, mixed> $headers */
            $headers = $headers->getAll();
        }
        if (! is_array($headers)) {
            return [];
        }

        $flat = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $value = end($value);
            }
            if (is_scalar($value)) {
                $flat[ strtolower($name) ] = (string) $value;
            }
        }

        return $flat;
    }
}
