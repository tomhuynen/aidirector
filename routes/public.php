<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Public\Auth\ForgotPasswordController;
use App\Http\Controllers\Public\Auth\LoginController;
use App\Http\Controllers\Public\Auth\RegisterController;
use App\Http\Controllers\Public\Auth\ResetPasswordController;
use App\Http\Controllers\Public\Media\ViewController as MediaViewController;
use App\Http\Controllers\Public\Projects\ChatController as ProjectChatController;
use App\Http\Controllers\Public\Projects\CreateController as ProjectCreateController;
use App\Http\Controllers\Public\Projects\DestroyController as ProjectDestroyController;
use App\Http\Controllers\Public\Projects\IndexController as ProjectIndexController;
use App\Http\Controllers\Public\Projects\Style\PinController as StylePinController;
use App\Http\Controllers\Public\Projects\Style\RoundController as StyleRoundController;
use App\Http\Controllers\Public\Projects\UpdateController as ProjectUpdateController;
use App\Http\Controllers\Public\Projects\ViewController as ProjectViewController;
use App\Http\Controllers\Public\Shots\DestroyController as ShotDestroyController;
use App\Http\Controllers\Public\Shots\ReorderController as ShotReorderController;
use App\Http\Controllers\Public\Shots\Storyline\ChooseController as StorylineChooseController;
use App\Http\Controllers\Public\Shots\Storyline\GenerateController as StorylineGenerateController;
use App\Http\Controllers\Public\Shots\Storyline\SuggestController as StorylineSuggestController;
use App\Http\Controllers\Public\Shots\UpdateController as ShotUpdateController;
use App\Http\Controllers\Public\Shots\ViewController as ShotViewController;
use App\Http\Controllers\Uploads\StoreController as UploadStoreController;
use App\Http\Controllers\Uploads\ViewController as UploadViewController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::name('auth.')->group(function () {
    Route::middleware('guest:director')->group(function () {
        Route::get('login', [LoginController::class, 'view'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
        Route::get('register', [RegisterController::class, 'view'])->name('register');
        Route::post('register', [RegisterController::class, 'store'])->name('register.store');
        Route::get('forgot-password', [ForgotPasswordController::class, 'view'])->name('forgot-password');
        Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:6,1')->name('forgot-password.store');
        Route::get('reset-password/{token}', [ResetPasswordController::class, 'view'])->name('reset-password');
        Route::post('reset-password', [ResetPasswordController::class, 'store'])->middleware('throttle:6,1')->name('reset-password.store');
    });

    Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth:director')->name('logout');
});

Route::middleware('auth:director')->group(function () {
    Route::prefix('projects')
        ->name('projects.')
        ->group(function () {
            Route::get('/', [ProjectIndexController::class, 'index'])->name('index');
            Route::get('create', [ProjectCreateController::class, 'view'])->name('create');
            Route::post('create', [ProjectUpdateController::class, 'store'])->name('store');
            Route::post('create/chat', [ProjectChatController::class, 'store'])->name('chat');
            Route::get('{project}', [ProjectViewController::class, 'view'])->name('view');
            Route::get('{project}/update', [ProjectUpdateController::class, 'update'])->name('update');
            Route::post('{project}/update', [ProjectUpdateController::class, 'store']);
            Route::delete('{project}', [ProjectDestroyController::class, 'destroy'])->name('destroy');
        });

    Route::prefix('projects/{project}/style')
        ->name('projects.style.')
        ->scopeBindings()
        ->group(function () {
            Route::post('rounds', [StyleRoundController::class, 'store'])->name('round');
            Route::get('rounds/{round}', [StyleRoundController::class, 'index'])->whereNumber('round')->name('options');
            Route::post('{styleOption}/pin', [StylePinController::class, 'store'])->name('pin');
        });

    Route::get('media/{media}/{conversion?}', [MediaViewController::class, 'view'])->middleware('signed')->name('media.view');

    Route::prefix('projects/{project}/shots')
        ->name('shots.')
        ->scopeBindings()
        ->group(function () {
            Route::get('create', [ShotUpdateController::class, 'update'])->name('create');
            Route::post('create', [ShotUpdateController::class, 'store'])->name('store');
            Route::post('reorder', [ShotReorderController::class, 'store'])->name('reorder');
            Route::get('{shot}', [ShotViewController::class, 'view'])->name('view');
            Route::post('{shot}/storyline/suggest', [StorylineSuggestController::class, 'store'])->name('storyline.suggest');
            Route::post('{shot}/storyline/choose', [StorylineChooseController::class, 'store'])->name('storyline.choose');
            Route::post('{shot}/storyline/generate', [StorylineGenerateController::class, 'store'])->name('storyline.generate');
            Route::delete('{shot}/storyline/choose', [StorylineChooseController::class, 'destroy'])->name('storyline.reopen');
            Route::get('{shot}/update', [ShotUpdateController::class, 'update'])->name('update');
            Route::post('{shot}/update', [ShotUpdateController::class, 'store']);
            Route::delete('{shot}', [ShotDestroyController::class, 'destroy'])->name('destroy');
        });

    /*
     * Staging uploads for chats and forms. Directors store them through the
     * upload policy; viewing needs the signed link from the upload resource.
     */
    Route::prefix('uploads')
        ->name('uploads.')
        ->group(function () {
            Route::post('/', [UploadStoreController::class, 'store'])->name('store');
            Route::get('{upload}', [UploadViewController::class, 'view'])->middleware('signed')->name('view');
        });
});
