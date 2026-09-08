<?php

declare(strict_types=1);

// PSR-12 plus a handful of rules that catch something. Deliberately NOT
// @Symfony: it rewrites 204 files, and 90% of that is yoda_style and
// concat_space — taste, paid for in git blame.

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__.'/packages/content-blocks/src',
        __DIR__.'/packages/content-blocks/tests',
        __DIR__.'/packages/content-blocks-kit/src',
        __DIR__.'/packages/content-blocks-kit/tests',
        __DIR__.'/packages/content-blocks-i18n/src',
        __DIR__.'/packages/content-blocks-i18n/tests',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_line_throw' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'fully_qualified_strict_types' => true,
        'no_useless_concat_operator' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
