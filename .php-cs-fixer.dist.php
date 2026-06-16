<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/test');

return (new Config())
    ->setFinder($finder)
    ->setParallelConfig(ParallelConfigFactory::sequential())
    ->setUsingCache(false)
    // native_function_invocation and declare_strict_types are classed as risky
    ->setRiskyAllowed(true)
    ->setRules([
        'declare_strict_types' => true,
        'blank_line_after_opening_tag' => true,
        'increment_style' => ['style' => 'pre'],
        'native_function_invocation' => [
            'scope' => 'namespaced',
            /** only the functions that get special opcodes */
            //'include' => ['@compiler_optimized'],
            /** also removes backslashes from functions without special opcodes */
            //'strict' => true,
            'include' => ['@all'],
            // prefix all global php functions even if there is no fast-path performance gain
            'strict' => false,
        ],
        'native_constant_invocation' => [
            //'scope' => 'namespaced',
            'scope' => 'all',
            'fix_built_in' => true,
            /** limit to specific constants */
            //'include' => ['E_WARNING', 'CURLE_OK'],
            'strict' => false,
        ],
    ]);
