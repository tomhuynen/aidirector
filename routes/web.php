<?php

declare(strict_types=1);

use App\Http\Middleware\Admin\HandleInertiaRequests as HandleAdminInertiaRequests;
use App\Http\Middleware\Admin\IdentifyTenant as IdentifyAdminTenant;
use App\Http\Middleware\Delay;
use App\Http\Middleware\Public\HandleInertiaRequests as HandlePublicInertiaRequests;
use App\Http\Middleware\Public\IdentifyTenant as IdentifyPublicTenant;
use Illuminate\Support\Facades\Config;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

Route::middleware([
    'auth',
    IdentifyAdminTenant::class,
])->group(function () {
    Route::inertiaTable();
});

Route::middleware([
    IdentifyPublicTenant::class,
    HandlePublicInertiaRequests::class,
    NeedsTenant::class,
    Delay::class,
])
    ->as('public.')
    ->group(function () {
        require __DIR__ . '/public.php';
    });

Route::prefix('admin')
    ->as('admin.')
    ->middleware([
        'auth',
        IdentifyAdminTenant::class,
        HandleAdminInertiaRequests::class,
        NeedsTenant::class,
        Delay::class,
    ])
    ->group(function () {
        require __DIR__ . '/admin.php';
    });

Route::domain(Config::get('fortify.domain', null))
    ->prefix(Config::get('fortify.prefix'))
    ->middleware(Config::get('fortify.middleware', ['web']))
    ->as('admin.auth.')
    ->group(function () {
        require __DIR__ . '/admin.auth.php';
    });
