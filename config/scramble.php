<?php

declare(strict_types=1);

use App\Http\Middleware\Admin\IdentifyTenant;
use App\Support\Scramble\Extensions\ArrayableExtension;
use App\Support\Scramble\Extensions\CacheTypeInfer;
use App\Support\Scramble\Extensions\CallbackExtension;
use App\Support\Scramble\Extensions\CommonStaticMethodExtension;
use App\Support\Scramble\Extensions\EnumHelpersExtension;
use App\Support\Scramble\Extensions\InertiaExtension;
use App\Support\Scramble\Extensions\InertiaSharedDataExtension;
use App\Support\Scramble\Extensions\ProgramScheduleTypeInfer;
use App\Support\Scramble\Extensions\ReflectionMethodReturnTypeExtension;
use App\Support\Scramble\Extensions\TableTypeInfer;

return [
    /*
     * Your API path. By default, all routes starting with this path will be added to the docs.
     * If you need to change this behavior, you can add your custom routes resolver using `Scramble::routes()`.
     */
    'api_path' => '',

    'middleware' => [
        IdentifyTenant::class,
        'web',
    ],

    'extensions' => [
        InertiaExtension::class,
        InertiaSharedDataExtension::class,
        CallbackExtension::class,
        ArrayableExtension::class,
        EnumHelpersExtension::class,
        CacheTypeInfer::class,
        TableTypeInfer::class,
        ProgramScheduleTypeInfer::class,
        CommonStaticMethodExtension::class,
        ReflectionMethodReturnTypeExtension::class,
    ],
];
