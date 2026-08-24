<?php

declare(strict_types=1);

/*
 * Retry-After arrives in whichever of RFC 7231's two forms the answering
 * layer prefers: the backend's limiter writes seconds from a Redis TTL, an
 * intervening CDN or WAF may well write a date.
 */

it('reads delta-seconds', function (): void {
    expect(Spamtroll_Retry_After::parse('120'))->toBe(120);
});

it('reads an HTTP-date', function (): void {
    $now = 1_756_000_000;
    $header = gmdate('D, d M Y H:i:s', $now + 90) . ' GMT';

    expect(Spamtroll_Retry_After::parse($header, $now))->toBe(90);
});

it('clamps an absurd delay to an hour', function (): void {
    expect(Spamtroll_Retry_After::parse('999999'))->toBe(Spamtroll_Retry_After::MAX_SECONDS);
});

it('clamps a delay in the past to one second', function (): void {
    $now = 1_756_000_000;
    $header = gmdate('D, d M Y H:i:s', $now - 500) . ' GMT';

    expect(Spamtroll_Retry_After::parse($header, $now))->toBe(Spamtroll_Retry_After::MIN_SECONDS);
});

it('refuses a value strtotime would misread as a relative offset', function (): void {
    // strtotime('-30') is "thirty units ago" and strtotime('1.5') is a clock
    // time; both would come back as a confident, wrong delay.
    expect(Spamtroll_Retry_After::parse('-30'))->toBeNull();
    expect(Spamtroll_Retry_After::parse('1.5'))->toBeNull();
});

it('refuses nothing at all', function (): void {
    expect(Spamtroll_Retry_After::parse(null))->toBeNull();
    expect(Spamtroll_Retry_After::parse('   '))->toBeNull();
    expect(Spamtroll_Retry_After::parse('soon please'))->toBeNull();
});
