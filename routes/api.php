<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\TokenController;
use Illuminate\Support\Facades\Route;

Route::as('api.')->group(function () {
    Route::prefix('auth')->as('auth.')->group(function () {
        Route::post('/token', [TokenController::class, 'create'])->name('token.create');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('auth')->as('auth.')->group(function () {
            Route::get('/me', [MeController::class, 'view'])->name('me.view');
        });
    });

});
