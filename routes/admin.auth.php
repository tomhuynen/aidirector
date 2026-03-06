<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Auth\AuthenticateUsingPasskeyController;
use App\Http\Controllers\Admin\Auth\InviteController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController;

Route::middleware('guest')->group(function () {
    Route::post('invite/{user}', [InviteController::class, 'store'])->name('invite.store');
    Route::get('invite/{user}', [InviteController::class, 'view'])->name('invite');

    Route::post('passkeys/login', AuthenticateUsingPasskeyController::class);
    Route::namespace('\Spatie\LaravelPasskeys\Http\Controllers')->group(function () {
        Route::get('passkeys/options/auth', GeneratePasskeyAuthenticationOptionsController::class);
    });
});
