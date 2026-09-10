<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Transform\Rector\String_\StringToClassConstantRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withoutParallel()
    ->withCache(__DIR__ . '/storage/framework/cache/rector')
    ->withComposerBased(laravel: true)
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap',
        __DIR__ . '/config',
        __DIR__ . '/public',
        __DIR__ . '/resources',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_LEGACY_FACTORIES_TO_CLASSES,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_IF_HELPERS,
    ])
    ->withPreparedSets(
        deadCode: true,
        naming: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withImportNames(true)
    ->withSkip([
        StringToClassConstantRector::class,
        __DIR__ . '/bootstrap/cache',
        __DIR__ . '/app/Http/Controllers/Auth/AuthenticatedSessionController.php',
        // This service is large enough that Rector currently stalls while processing it.
        __DIR__ . '/app/Services/MonitoringResultService.php',
    ]);
