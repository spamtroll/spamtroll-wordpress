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

class Spamtroll_Wp_Http_Client implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'sslverify' => true,
            'headers' => $headers,
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

        return new HttpResponse($status, $raw, []);
    }
}
