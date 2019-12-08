<?php

$config = PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => false,
        'concat_space' => ['spacing'=>'one'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'php_unit_no_expectation_annotation' => ['target' => 'newest'],
        'php_unit_expectation' => ['target' => 'newest'],
        'no_empty_phpdoc' => true,
        'no_extra_blank_lines' => true,
        'ordered_imports' => true,
        'no_superfluous_phpdoc_tags' => true,
        'no_blank_lines_after_phpdoc' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_trim' => true,
        'phpdoc_align' => ['align'=>'vertical'],
        // 'native_function_invocation' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__.'/src')
            ->in(__DIR__.'/tests')
            ->name('*.php')
    )
;

return $config;
