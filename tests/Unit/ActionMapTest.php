<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\WpEnv;

/*
 * The table itself. Its whole point is that an unlisted value has no branch
 * to fall into — it simply is not in the table.
 */

it('maps every verdict for every preset', function (string $preset, string $status, string $expected): void {
    WpEnv::boot([ 'sensitivity' => $preset ]);

    expect((new Spamtroll_Action_Map())->for_status($status))->toBe($expected);
})->with([
    [ 'lenient', 'blocked', 'moderate' ],
    [ 'lenient', 'suspicious', 'allow' ],
    [ 'lenient', 'safe', 'allow' ],
    [ 'balanced', 'blocked', 'block' ],
    [ 'balanced', 'suspicious', 'moderate' ],
    [ 'balanced', 'safe', 'allow' ],
    [ 'strict', 'blocked', 'block' ],
    [ 'strict', 'suspicious', 'block' ],
    [ 'strict', 'safe', 'allow' ],
]);

it('allows a verdict it has never heard of', function (): void {
    WpEnv::boot([ 'sensitivity' => 'strict' ]);

    // An older site must not start rejecting comments over a word a newer
    // backend introduced.
    expect((new Spamtroll_Action_Map())->for_status('quarantined'))->toBe('allow');
    expect((new Spamtroll_Action_Map())->for_status(''))->toBe('allow');
});

it('falls back to balanced for a preset nobody offered', function (): void {
    WpEnv::boot([ 'sensitivity' => 'paranoid' ]);

    expect((new Spamtroll_Action_Map())->preset())->toBe('balanced');
});

it('describes exactly the presets it accepts', function (): void {
    WpEnv::boot();

    expect(array_keys(Spamtroll_Action_Map::describe()))->toBe(Spamtroll_Action_Map::presets());
});
