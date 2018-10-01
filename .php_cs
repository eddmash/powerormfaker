<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR);

return PhpCsFixer\Config::create()
    ->setRules(array(
        '@Symfony' => true,
        'no_alternative_syntax'=>true,
        'blank_line_before_statement'=>false
        )
    )
    ->setFinder($finder);