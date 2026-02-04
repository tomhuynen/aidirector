<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = new Configuration();

return $config
    ->ignoreErrorsOnPath(__DIR__ . '/app/Providers/AppServiceProvider.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPath(__DIR__ . '/app/Providers/TelescopeServiceProvider.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPath(__DIR__ . '/app/Providers/ScrambleServiceProvider.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPath(__DIR__ . '/app/Support/Admin/Server/Backup.php', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPath(__DIR__ . '/app/Support/Scramble/Extensions/InertiaExtension.php', [ErrorType::DEV_DEPENDENCY_IN_PROD, ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPath(__DIR__ . '/app/Support/Scramble/Extensions/ArrayableExtension.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPath(__DIR__ . '/app/Support/Scramble/Extensions/CallbackExtension.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPath(__DIR__ . '/app/Support/Scramble/Extensions/CacheTypeInfer.php', [ErrorType::DEV_DEPENDENCY_IN_PROD, ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackages([
        'league/flysystem-aws-s3-v3',
        'league/flysystem-path-prefixing',
        'laravel/tinker',
        'kirschbaum-development/eloquent-power-joins',
        'opcodesio/log-viewer',
    ], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackages([
        'guzzlehttp/psr7',
        'nesbot/carbon',
        'pestphp/pest-plugin-arch',
        'symfony/http-foundation',
        'symfony/process',
    ], [ErrorType::SHADOW_DEPENDENCY])
    ->disableExtensionsAnalysis();
