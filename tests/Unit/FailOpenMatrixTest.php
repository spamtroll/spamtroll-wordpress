<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * The fail-open gate.
 *
 * CLAUDE.md calls this policy critical, and until now it had no test at all:
 * the whole guarantee rested on two catch blocks and the reviewer's memory.
 * Any refactor of check_comment() could have turned fail-open into
 * fail-closed and CI would have applauded.
 *
 * Every row of WORDPRESS.md §1 runs through the real transport adapter, the
 * real SDK and the real scanner, with wp_remote_request() as the only seam.
 * The assertion is the promise itself, stated three ways: the comment data
 * comes back untouched, the approval status is left as the caller set it,
 * and a registration collects no error.
 *
 * This file is expected to pass against the pre-fix code as well — that is
 * the point. dev/prove-regression.sh runs it both ways to show the fix did
 * not buy its behaviour by giving fail-open up.
 */

// Pest spreads a dataset row into arguments; wrapping each row keeps the
// matrix a single associative parameter and keeps the labels readable.
$rows = array_map(static fn (array $row): array => [ $row ], ApiFixtures::matrix());

it('lets a comment through', function (array $row): void {
    $env = WpEnv::boot();
    $env->willRespond($row['outcome'], 5);

    $commentdata = [
        'comment_content' => 'Buy cheap watches at example.com',
        'comment_author' => 'spammer',
        'comment_author_email' => 'spam@example.com',
    ];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

    $scanner = new Spamtroll_Scanner();
    $returned = $scanner->check_comment($commentdata);

    expect($returned)->toBe($commentdata);
    expect($scanner->filter_comment_approved(1, $commentdata))->toBe(1);
})->with($rows);

it('lets a registration through', function (array $row): void {
    $env = WpEnv::boot();
    $env->willRespond($row['outcome'], 5);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

    $errors = new WP_Error();
    $returned = (new Spamtroll_Scanner())->check_registration($errors, 'spammer', 'spam@example.com');

    expect($returned->get_error_codes())->toBe([]);
})->with($rows);

it('lets a comment through when the plugin is not configured', function (): void {
    WpEnv::boot([ 'api_key' => '' ]);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

    $commentdata = [ 'comment_content' => 'hello', 'comment_author_email' => 'a@example.com' ];
    $scanner = new Spamtroll_Scanner();

    expect($scanner->check_comment($commentdata))->toBe($commentdata);
    expect($scanner->filter_comment_approved(1, $commentdata))->toBe(1);
});

it('never turns an approved comment into a rejection when the API misbehaves', function (array $row): void {
    $env = WpEnv::boot();
    $env->willRespond($row['outcome'], 5);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

    $commentdata = [ 'comment_content' => 'hello there', 'comment_author_email' => 'a@example.com' ];
    $scanner = new Spamtroll_Scanner();
    $scanner->check_comment($commentdata);

    // 0 is "hold for moderation"; anything the API could not answer must not
    // even reach that, let alone 'spam'.
    expect($scanner->filter_comment_approved(0, $commentdata))->toBe(0);
})->with($rows);
