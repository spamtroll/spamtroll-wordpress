<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * The fail-open matrix again, this time asking *why*.
 *
 * tests/Unit/FailOpenMatrixTest.php proves the content gets through, which
 * was already true before the fix. What was not true is that the plugin knew
 * which of the fourteen things had happened: a 403 and a malformed body and a
 * timeout all ended in the same `return $commentdata`, so nothing could tell
 * the admin, open a circuit, or decline to cache. Every row now carries a
 * reason, and every row costs one HTTP attempt instead of up to three.
 */

$rows = array_map(static fn (array $row): array => [ $row ], ApiFixtures::matrix());

it('names the failure and spends one attempt on it', function (array $row): void {
    $env = WpEnv::boot();
    $env->willRespond($row['outcome'], 6);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'Some content ' . $row['reason'],
        'comment_author' => 'someone',
        'comment_author_email' => 'someone@example.com',
    ]);

    $verdict = $env->lastVerdict();

    expect($verdict)->not->toBeNull();
    expect($verdict->reason)->toBe($row['reason']);
    expect($verdict->action)->toBe(Spamtroll_Verdict::ACTION_ALLOW);
    expect($env->requestCount())->toBeLessThanOrEqual($row['maxCalls']);
})->with($rows);

it('names a successful scan as scanned', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('blocked', 16.0)), 3);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.78';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'buy watches',
        'comment_author_email' => 'a@example.com',
    ]);

    $verdict = $env->lastVerdict();
    expect($verdict->reason)->toBe(Spamtroll_Verdict::REASON_SCANNED);
    expect($verdict->action)->toBe(Spamtroll_Verdict::ACTION_BLOCK);
    expect($verdict->submission_id)->toBe('sub-1234');
});

it('writes nothing to the log for a scan that never happened', function (array $row): void {
    $env = WpEnv::boot();
    $env->willRespond($row['outcome'], 6);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.79';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'Some content ' . $row['reason'],
        'comment_author_email' => 'someone@example.com',
    ]);

    // "Scanned, safe" is a claim, and the plugin used to make it for an empty
    // body, a captive portal's HTML, and every 402.
    expect($env->loggedRows())->toBe([]);
})->with($rows);
