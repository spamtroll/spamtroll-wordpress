<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\WpEnv;

/*
 * A single timeout is ordinary internet weather; three in a row is an outage.
 * Everything else is the server stating its own condition, and repeating the
 * request cannot change the answer — so those open on the first occurrence.
 */

it('tolerates two transport failures and opens on the third', function (): void {
    WpEnv::boot();
    $breaker = new Spamtroll_Circuit_Breaker();

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_TRANSPORT);
    expect($breaker->is_open())->toBeFalse();

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_TRANSPORT);
    expect($breaker->is_open())->toBeFalse();

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_TRANSPORT);
    expect($breaker->is_open())->toBeTrue();
    expect($breaker->reason())->toBe(Spamtroll_Circuit_Breaker::KIND_TRANSPORT);
});

it('opens immediately on an authentication refusal', function (): void {
    WpEnv::boot();
    $breaker = new Spamtroll_Circuit_Breaker();

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_AUTH);

    expect($breaker->is_open())->toBeTrue();
    expect($breaker->reason())->toBe(Spamtroll_Circuit_Breaker::KIND_AUTH);
});

it('honours the cooldown the server asked for', function (): void {
    WpEnv::boot();
    $now = 1_756_000_000;
    $breaker = new Spamtroll_Circuit_Breaker(static fn (): int => $now);

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_RATE_LIMITED, 120);

    expect($breaker->open_until())->toBe($now + 120);
});

it('will not let a misconfigured proxy switch scanning off for a day', function (): void {
    WpEnv::boot();
    $now = 1_756_000_000;
    $breaker = new Spamtroll_Circuit_Breaker(static fn (): int => $now);

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_RATE_LIMITED, 86_400);

    expect($breaker->open_until())->toBe($now + Spamtroll_Retry_After::MAX_SECONDS);
});

it('closes again once the cooldown has passed', function (): void {
    WpEnv::boot();
    $now = 1_756_000_000;
    $breaker = new Spamtroll_Circuit_Breaker(static function () use (&$now): int {
        return $now;
    });

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_RATE_LIMITED, 60);
    expect($breaker->is_open())->toBeTrue();

    $now += 61;
    expect($breaker->is_open())->toBeFalse();
    expect($breaker->reason())->toBe('');
});

it('forgets everything on reset, which is what Test Connection does', function (): void {
    $env = WpEnv::boot();
    $breaker = new Spamtroll_Circuit_Breaker();

    $breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_AUTH);
    $breaker->reset();

    expect($breaker->is_open())->toBeFalse();
    expect($env->options)->not->toHaveKey(Spamtroll_Circuit_Breaker::OPTION_KEY);
});

it('stores its state outside the object cache', function (): void {
    $env = WpEnv::boot();

    (new Spamtroll_Circuit_Breaker())->record_failure(Spamtroll_Circuit_Breaker::KIND_AUTH);

    // A transient lives in the object cache on sites that have one, and a
    // cache flush during an incident would reopen the floodgates.
    expect($env->options)->toHaveKey(Spamtroll_Circuit_Breaker::OPTION_KEY);
    expect($env->transients)->toBe([]);
});
