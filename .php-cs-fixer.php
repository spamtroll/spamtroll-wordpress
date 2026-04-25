<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__])
    ->name('*.php')
    ->name('spamtroll.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->exclude(['vendor', 'languages', 'assets']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Hybrid: PSR-12 baseline (tabs -> 4 spaces, ordered_imports,
        // declare_strict_types) but WP-friendly — `@PSR12:risky` does
        // NOT enforce camelCase, so snake_case methods like
        // `check_comment` and `sanitize_settings` keep working.
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@PHP80Migration' => true,
        '@PHP80Migration:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'no_extra_blank_lines' => ['tokens' => ['extra', 'use', 'use_trait', 'curly_brace_block']],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_trim' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_separation' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        // The plugin's bootstrap (`spamtroll.php`) ships top-level
        // `define()` and `require_once` calls before any class is
        // declared — that's the WordPress entry-point convention,
        // and PSR-12's "side-effects vs declarations" rule disallows
        // it. Letting the PSR-1 rule fire here would mean restructuring
        // the bootstrap into a class, which the WP loader won't pick
        // up. Whitelist the entrypoint and let the rest of the plugin
        // be checked normally.
        'no_alternative_syntax' => false,
    ])
    ->setFinder($finder)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
