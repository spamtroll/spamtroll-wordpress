<?php

declare(strict_types=1);

/*
 * Rules that are easier to enforce than to remember.
 *
 * Fail-open is a property of Spamtroll_Verdict: its constructor is private,
 * and the only path to an action other than `allow` is from_response(), which
 * needs a response the API actually answered. That guarantee survives exactly
 * as long as nobody adds a second way to build one, so the shape of the class
 * is worth pinning down.
 */

arch('the verdict cannot be constructed from outside itself')
    ->expect('Spamtroll_Verdict')
    ->toBeFinal();

it('keeps the verdict constructor private', function (): void {
    $constructor = (new ReflectionClass(Spamtroll_Verdict::class))->getConstructor();

    expect($constructor)->not->toBeNull();
    expect($constructor->isPrivate())->toBeTrue();
});

it('offers exactly two ways to make a verdict, only one of which can refuse content', function (): void {
    $factories = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            (new ReflectionClass(Spamtroll_Verdict::class))->getMethods(ReflectionMethod::IS_STATIC),
            static fn (ReflectionMethod $m): bool => 'Spamtroll_Verdict' === $m->getDeclaringClass()->getName()
                && $m->isPublic()
                && 'self' === (string) $m->getReturnType(),
        ),
    );

    sort($factories);
    expect($factories)->toBe([ 'allow', 'from_response' ]);
});

it('never lets the scanner build a blocking verdict by hand', function (): void {
    $source = (string) file_get_contents(SPAMTROLL_TEST_INCLUDES_DIR . '/class-spamtroll-scanner.php');

    // Everything that is not a scan the API answered has to reach
    // Spamtroll_Verdict::allow(), because there is nowhere else to reach.
    expect($source)->not->toContain('new Spamtroll_Verdict');
});

it('gates every error_log call behind WP_DEBUG', function (): void {
    foreach ((array) glob(SPAMTROLL_TEST_INCLUDES_DIR . '/class-spamtroll-*.php') as $file) {
        $source = (string) file_get_contents((string) $file);
        $direct = preg_match_all('/error_log\(/', $source);
        $gated = preg_match_all("/defined\('WP_DEBUG'\) && WP_DEBUG\)\s*\{\s*error_log\(/", $source);

        expect($direct)->toBe(
            $gated,
            basename((string) $file) . ' calls error_log() outside a WP_DEBUG guard',
        );
    }
});

it('guards every include against direct access', function (): void {
    foreach ((array) glob(SPAMTROLL_TEST_INCLUDES_DIR . '/class-spamtroll-*.php') as $file) {
        expect((string) file_get_contents((string) $file))->toContain("if (! defined('ABSPATH'))");
    }
});
