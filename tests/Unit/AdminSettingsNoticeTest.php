<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/*
 * Regression: the custom top-level admin menu added by Spamtroll_Admin
 * does NOT inherit WordPress's automatic "Settings saved." notice, which
 * is only rendered on options-*.php pages. Without an explicit
 * settings_errors() call, users got zero feedback after submitting the
 * settings form. This test pins the fix in place.
 */

beforeEach(function (): void {
    // Common WP function stubs every render_settings_page() call needs.
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('get_admin_page_title')->justReturn('Spamtroll');
    Functions\when('settings_fields')->justReturn(null);
    Functions\when('do_settings_sections')->justReturn(null);
    Functions\when('submit_button')->justReturn(null);
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_html__')->returnArg();
    Functions\when('__')->returnArg();
});

afterEach(function (): void {
    // Don't leak the $_GET state between tests.
    unset($_GET['settings-updated']);
});

it('seeds and prints the saved notice when settings-updated is set', function (): void {
    $_GET['settings-updated'] = 'true';

    $added = [];
    Functions\when('add_settings_error')->alias(function ($setting, $code, $message, $type = 'error') use (&$added): void {
        $added[] = compact('setting', 'code', 'message', 'type');
    });
    Functions\when('settings_errors')->alias(function (string $setting) use (&$added): void {
        // Echo the seeded message so the test can assert on the rendered HTML.
        foreach ($added as $row) {
            if ($row['setting'] === $setting) {
                echo '<div class="notice notice-' . $row['type'] . '"><p>' . $row['message'] . '</p></div>';
            }
        }
    });

    $admin = new Spamtroll_Admin();
    ob_start();
    $admin->render_settings_page();
    $html = (string) ob_get_clean();

    expect($added)->toHaveCount(1);
    expect($added[0]['setting'])->toBe('spamtroll_settings_group');
    expect($added[0]['type'])->toBe('updated');
    expect($html)->toContain('Settings saved.');
});

it('does not seed the saved notice when settings-updated is absent', function (): void {
    $added = [];
    Functions\when('add_settings_error')->alias(function ($setting, $code, $message, $type = 'error') use (&$added): void {
        $added[] = compact('setting', 'code', 'message', 'type');
    });
    Functions\when('settings_errors')->justReturn(null);

    $admin = new Spamtroll_Admin();
    ob_start();
    $admin->render_settings_page();
    ob_end_clean();

    expect($added)->toBe([]);
});

it('always calls settings_errors for the spamtroll group regardless of state', function (): void {
    $printed = [];
    Functions\when('add_settings_error')->justReturn(null);
    Functions\when('settings_errors')->alias(function (string $setting) use (&$printed): void {
        $printed[] = $setting;
    });

    $admin = new Spamtroll_Admin();
    ob_start();
    $admin->render_settings_page();
    ob_end_clean();

    expect($printed)->toBe(['spamtroll_settings_group']);
});
