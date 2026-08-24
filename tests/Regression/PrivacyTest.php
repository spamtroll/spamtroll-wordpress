<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * WORDPRESS.md §2.8 — the scan log is personal data and has to behave like it.
 *
 * The table holds an IP address, an email address and up to 500 characters of
 * whatever the visitor typed. None of it was reachable from Tools → Export
 * Personal Data or Erase Personal Data, so a site owner honouring a
 * subject-access request handed over an export that silently omitted this
 * table, and an erasure that silently kept it.
 */

it('registers an exporter and an eraser with WordPress', function (): void {
    WpEnv::boot();
    $privacy = new Spamtroll_Privacy();

    expect($privacy->register_exporter([]))->toHaveKey(Spamtroll_Privacy::EXPORTER_ID);
    expect($privacy->register_eraser([]))->toHaveKey(Spamtroll_Privacy::ERASER_ID);
});

it('exports the rows recorded for one address', function (): void {
    WpEnv::boot();
    $GLOBALS['wpdb']->rows = [
        [
            'id' => '7',
            'created_at' => '2026-08-24 10:00:00',
            'content_type' => 'comment',
            'ip_address' => '198.51.100.60',
            'email' => 'subject@example.com',
            'status' => 'safe',
            'spam_score' => '0.0100',
            'action_taken' => 'allow',
            'content_preview' => 'a comment they wrote',
        ],
    ];

    $export = (new Spamtroll_Privacy())->export('subject@example.com');

    expect($export['done'])->toBeTrue();
    expect($export['data'])->toHaveCount(1);
    expect($export['data'][0]['item_id'])->toBe('spamtroll-log-7');

    $values = array_column($export['data'][0]['data'], 'value');
    expect($values)->toContain('198.51.100.60');
    expect($values)->toContain('a comment they wrote');
});

it('erases the rows recorded for one address', function (): void {
    WpEnv::boot();

    $result = (new Spamtroll_Privacy())->erase('subject@example.com');

    expect($result['items_removed'])->toBeTrue();
    expect($result['items_retained'])->toBeFalse();
    expect($GLOBALS['wpdb']->deletes[0]['where'])->toBe([ 'email' => 'subject@example.com' ]);
});

it('stores the email in a column instead of burying it in the content preview', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.61';

    (new Spamtroll_Scanner())->check_registration(new WP_Error(), 'newuser', 'newuser@example.com');

    $rows = $env->loggedRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['email'])->toBe('newuser@example.com');
    // A value that cannot be queried cannot be erased — and the preview used
    // to be "newuser newuser@example.com".
    expect($rows[0]['content_preview'])->toBe('newuser');
});

it('lets the site owner change how long records are kept', function (): void {
    WpEnv::boot([ 'log_retention_days' => 7 ]);

    // The setting existed in the README and in the option, but sanitize
    // pinned it to 30 on every save, so it could never be anything else.
    expect(Spamtroll_Settings::retention_days())->toBe(7);
});
