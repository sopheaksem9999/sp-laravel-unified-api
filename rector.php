<?php

use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\Config\RectorConfig;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withSkip([
        DisallowedEmptyRuleFixerRector::class,
        SimplifyEmptyCheckOnEmptyArrayRector::class,
        __DIR__ . '/vendor/*',
    ])
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRules([
        DeclareStrictTypesRector::class,
    ])
    ->withPhpSets(php84: true)
    ->withImportNames(importNames: true, removeUnusedImports: true)
    ->withAttributesSets(doctrine: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        doctrineCodeQuality: true,
        typeDeclarationDocblocks: true,
    )
;
