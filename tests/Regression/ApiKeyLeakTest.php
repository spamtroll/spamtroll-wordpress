<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * The API key must not leave the host it was issued for.
 *
 * wp_remote_request() follows five redirects by default, and Requests replays
 * the request headers on each hop — X-API-Key included. A redirect this
 * client never sees, from a hijacked DNS record or a compromised
 * intermediary, would hand the platform's key to whatever host answered.
 * Platform keys have no TTL, so the leak is permanent.
 *
 * There is no legitimate redirect on this API: every endpoint answers
 * directly. A 3xx is therefore something to fail open on, not to chase.
 */

it('never follows a redirect on a scan', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 2);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.70';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'hello there',
        'comment_author_email' => 'a@example.com',
    ]);

    expect($env->requests)->toHaveCount(1);
    expect($env->requests[0]['args']['redirection'])->toBe(0);
    expect($env->requests[0]['args']['headers']['X-API-Key'])->toBe('test-key');
});

it('never follows a redirect when sending feedback either', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, [ 'success' => true, 'data' => [] ]), 2);

    (new Spamtroll_Feedback())->send('sub-abc-123', 'spam');

    expect($env->requests[0]['args']['redirection'])->toBe(0);
});

it('caps how much of a misrouted response it will read', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 2);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.71';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'hello there',
        'comment_author_email' => 'a@example.com',
    ]);

    expect($env->requests[0]['args']['limit_response_size'])
        ->toBe(Spamtroll_Wp_Http_Client::MAX_RESPONSE_BYTES);
});

it('fails open on a redirect rather than chasing it', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::wpResponse(302, '', [ 'location' => 'https://evil.example/scan' ]), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.72';

    $commentdata = [ 'comment_content' => 'hello there', 'comment_author_email' => 'a@example.com' ];
    $scanner = new Spamtroll_Scanner();

    expect($scanner->check_comment($commentdata))->toBe($commentdata);
    expect($scanner->filter_comment_approved(1, $commentdata))->toBe(1);
    expect($env->lastVerdict()->reason)->toBe(Spamtroll_Verdict::REASON_API_ERROR);
});
