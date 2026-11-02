<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@PHP82Migration' => true,
        'declare_strict_types' => true,
        'final_class' => false,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'strict_comparison' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__ . '/src')
            ->in(__DIR__ . '/packages')
            ->exclude('vendor')
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
    );
