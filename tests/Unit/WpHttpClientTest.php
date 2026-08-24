<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;
use Spamtroll\Sdk\Http\HttpResponse;

it('returns an HttpResponse when wp_remote_request succeeds', function (): void {
    Functions\when('wp_remote_request')->justReturn(['ok' => true]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{"hello":"world"}');
    Functions\when('wp_remote_retrieve_headers')->justReturn([ 'Retry-After' => '30', 'X-RateLimit-Limit' => '100' ]);

    $client = new Spamtroll_Wp_Http_Client();
    $response = $client->send('GET', 'https://api.example/x', ['X-Foo' => 'bar'], null, 5);

    expect($response)->toBeInstanceOf(HttpResponse::class)
        ->and($response->statusCode)->toBe(200)
        ->and($response->body)->toBe('{"hello":"world"}');
});

it('exposes the response headers the SDK throws away', function (): void {
    // Client::dispatch() collapses a response into [success, code, decoded,
    // error]; Retry-After and the X-RateLimit-* family never cross that
    // boundary, and the circuit breaker needs them.
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_request')->justReturn(['ok' => true]);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(429);
    Functions\when('wp_remote_retrieve_body')->justReturn('{"error":true}');
    Functions\when('wp_remote_retrieve_headers')->justReturn([ 'Retry-After' => [ '30', '120' ] ]);

    $client = new Spamtroll_Wp_Http_Client();
    $client->send('POST', 'https://api.example/x', [], '{}', 5);

    expect($client->get_last_headers())->toBe([ 'retry-after' => '120' ]);
});

it('shrinks a per-attempt timeout to whatever is left of the budget', function (): void {
    $seen = 0;
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$seen) {
        $seen = $args['timeout'];
        return ['ok' => true];
    });
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{}');
    Functions\when('wp_remote_retrieve_headers')->justReturn([]);

    $client = new Spamtroll_Wp_Http_Client();
    $client->set_deadline(hrtime(true) + 1_000_000_000);
    $client->send('POST', 'https://api.example/x', [], '{}', 30);

    expect($seen)->toBeLessThanOrEqual(1);
});

it('throws TimeoutException when WP_Error message mentions a timeout', function (): void {
    Functions\when('wp_remote_request')->justReturn('does-not-matter');
    Functions\when('is_wp_error')->justReturn(true);

    $error = Mockery::mock();
    $error->shouldReceive('get_error_message')->andReturn('Request timed out after 5 seconds');
    Functions\when('wp_remote_request')->justReturn($error);

    $client = new Spamtroll_Wp_Http_Client();

    $client->send('POST', 'https://api.example/x', [], '{}', 5);
})->throws(TimeoutException::class);

it('throws ConnectionException for any other WP_Error', function (): void {
    $error = Mockery::mock();
    $error->shouldReceive('get_error_message')->andReturn('Could not resolve host');
    Functions\when('wp_remote_request')->justReturn($error);
    Functions\when('is_wp_error')->justReturn(true);

    $client = new Spamtroll_Wp_Http_Client();

    $client->send('GET', 'https://nope.example/', [], null, 5);
})->throws(ConnectionException::class);
