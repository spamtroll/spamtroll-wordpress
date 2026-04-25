<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

it('returns an empty array when the option is missing', function (): void {
    Functions\when('get_option')->justReturn(false);

    expect(Spamtroll_Settings::all())->toBe([]);
});

it('returns an empty array when the option is not an array', function (): void {
    Functions\when('get_option')->justReturn('not-an-array');

    expect(Spamtroll_Settings::all())->toBe([]);
});

it('exposes typed string accessor', function (): void {
    Functions\when('get_option')->justReturn(['api_key' => 'abc', 'numeric' => 42]);

    expect(Spamtroll_Settings::string('api_key'))->toBe('abc');
    expect(Spamtroll_Settings::string('numeric'))->toBe('42');
    expect(Spamtroll_Settings::string('missing', 'fallback'))->toBe('fallback');
});

it('exposes typed int accessor', function (): void {
    Functions\when('get_option')->justReturn(['retention' => '30', 'pi' => '3.14', 'flag' => 'yes']);

    expect(Spamtroll_Settings::int('retention'))->toBe(30);
    expect(Spamtroll_Settings::int('pi'))->toBe(3);
    expect(Spamtroll_Settings::int('flag', 99))->toBe(99); // non-numeric → default
    expect(Spamtroll_Settings::int('missing', 7))->toBe(7);
});

it('exposes typed float accessor', function (): void {
    Functions\when('get_option')->justReturn(['threshold' => '0.7']);

    expect(Spamtroll_Settings::float('threshold'))->toBe(0.7);
    expect(Spamtroll_Settings::float('missing', 0.4))->toBe(0.4);
});

it('exposes typed bool accessor', function (): void {
    Functions\when('get_option')->justReturn([
        'true_bool' => true,
        'false_bool' => false,
        'one' => 1,
        'zero' => 0,
        'string_one' => '1',
        'string_zero' => '0',
        'garbage' => 'maybe',
    ]);

    expect(Spamtroll_Settings::bool('true_bool'))->toBeTrue();
    expect(Spamtroll_Settings::bool('false_bool'))->toBeFalse();
    expect(Spamtroll_Settings::bool('one'))->toBeTrue();
    expect(Spamtroll_Settings::bool('zero'))->toBeFalse();
    expect(Spamtroll_Settings::bool('string_one'))->toBeTrue();
    expect(Spamtroll_Settings::bool('string_zero'))->toBeFalse();
    expect(Spamtroll_Settings::bool('garbage', true))->toBeTrue(); // non-bool/non-numeric → default
    expect(Spamtroll_Settings::bool('missing', true))->toBeTrue();
});

it('returns an empty list when stringList key is missing or wrong type', function (): void {
    Functions\when('get_option')->justReturn(['list' => 'not-array']);

    expect(Spamtroll_Settings::stringList('list'))->toBe([]);
    expect(Spamtroll_Settings::stringList('missing'))->toBe([]);
});

it('coerces stringList items to strings and skips non-scalar entries', function (): void {
    Functions\when('get_option')->justReturn([
        'roles' => ['administrator', 'editor', 42, ['nested'], null, 'subscriber'],
    ]);

    expect(Spamtroll_Settings::stringList('roles'))->toBe([
        'administrator',
        'editor',
        '42',
        'subscriber',
    ]);
});
