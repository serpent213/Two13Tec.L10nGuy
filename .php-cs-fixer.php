<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->path(['Classes/', 'Tests/'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        'no_unused_imports' => true,

        // Optional: Nice-to-have sane defaults
        'ordered_imports' => ['sort_algorithm' => 'alpha'], // Sort imports alphabetically
        'array_syntax' => ['syntax' => 'short'],            // Force [] instead of array()
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder)
;
