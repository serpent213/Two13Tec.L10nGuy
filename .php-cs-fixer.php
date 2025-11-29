<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->path(['Classes/', 'Tests/'])
;

return (new PhpCsFixer\Config())
    ->setFinder($finder)
;
