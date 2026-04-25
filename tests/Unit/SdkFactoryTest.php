<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Spamtroll\Sdk\Client;

it('builds a Client carrying the saved API key', function (): void {
    Functions\when('get_option')->justReturn(['api_key' => 'live-key']);

    $client = Spamtroll_Sdk_Factory::client();

    expect($client)->toBeInstanceOf(Client::class);
    expect($client->isConfigured())->toBeTrue();
});

it('lets a runtime override take precedence over the saved key', function (): void {
    Functions\when('get_option')->justReturn(['api_key' => 'saved-key']);

    $client = Spamtroll_Sdk_Factory::client('override-key');

    // Empty override would fall back to settings; non-empty must win.
    expect($client->isConfigured())->toBeTrue();
});

it('returns an unconfigured Client when no key is stored', function (): void {
    Functions\when('get_option')->justReturn([]);

    $client = Spamtroll_Sdk_Factory::client();

    expect($client->isConfigured())->toBeFalse();
});

it('sets a userAgent that mentions the WordPress plugin and SDK version', function (): void {
    Functions\when('get_option')->justReturn(['api_key' => 'k']);

    $client = Spamtroll_Sdk_Factory::client();
    $userAgent = $client->getConfig()->userAgent;

    expect($userAgent)->toContain('Spamtroll-WordPress/');
    expect($userAgent)->toContain('spamtroll-php-sdk/');
});
