<?php

declare(strict_types=1);

use Brain\Monkey;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()
    ->beforeEach(function (): void {
        Monkey\setUp();
    })
    ->afterEach(function (): void {
        Monkey\tearDown();
        unset($GLOBALS['wpdb']);
    })
    ->in('Unit', 'Regression');
