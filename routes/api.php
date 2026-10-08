<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\TokenController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ShotController;
use App\Http\Middleware\Api\EnsureDirector;
use App\Http\Middleware\Public\IdentifyTenant;
use Illuminate\Support\Facades\Route;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

Route::as('api.')->group(function () {
    Route::prefix('auth')->as('auth.')->group(function () {
        Route::post('/token', [TokenController::class, 'create'])->name('token.create');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('auth')->as('auth.')->group(function () {
            Route::get('/me', [MeController::class, 'view'])->name('me.view');
        });
    });

    /*
     * Read-only access for another application, such as the e-learning
     * platform, to the director's projects and the files of their shots.
     * The tenant comes from the domain; the token is the director's.
     */
    Route::prefix('v1')
        ->as('v1.')
        ->middleware([IdentifyTenant::class, NeedsTenant::class, 'auth:sanctum', EnsureDirector::class])
        ->group(function () {
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('projects/{project}/shots', [ShotController::class, 'index'])->name('projects.shots.index');
            Route::get('projects/{project}/shots/{shot}/assets', [ShotController::class, 'assets'])->scopeBindings()->name('projects.shots.assets');
        });
});
