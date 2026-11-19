<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/../shared/src'])
    ->exclude(['fixtures']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        // Longueur de ligne et commentaires libres : la lisibilité prime sur l'alignement
        'phpdoc_summary' => false,
        'phpdoc_to_comment' => false,
        'yoda_style' => true,
    ])
    ->setRiskyAllowed(false)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setFinder($finder);
