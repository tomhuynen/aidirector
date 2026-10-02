<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Public\Auth\ForgotPasswordController;
use App\Http\Controllers\Public\Auth\LoginController;
use App\Http\Controllers\Public\Auth\RegisterController;
use App\Http\Controllers\Public\Auth\ResetPasswordController;
use App\Http\Controllers\Public\Media\ViewController as MediaViewController;
use App\Http\Controllers\Public\Notifications\IndexController as NotificationIndexController;
use App\Http\Controllers\Public\Notifications\ReadController as NotificationReadController;
use App\Http\Controllers\Public\Projects\ChatController as ProjectChatController;
use App\Http\Controllers\Public\Projects\CoverController as ProjectCoverController;
use App\Http\Controllers\Public\Projects\CreateController as ProjectCreateController;
use App\Http\Controllers\Public\Projects\DestroyController as ProjectDestroyController;
use App\Http\Controllers\Public\Projects\Elements\DestroyController as ElementDestroyController;
use App\Http\Controllers\Public\Projects\Elements\PickController as ElementPickController;
use App\Http\Controllers\Public\Projects\Elements\RoundController as ElementRoundController;
use App\Http\Controllers\Public\Projects\Elements\StoreController as ElementStoreController;
use App\Http\Controllers\Public\Projects\Elements\UpdateController as ElementUpdateController;
use App\Http\Controllers\Public\Projects\Elements\VersionController as ElementVersionController;
use App\Http\Controllers\Public\Projects\Elements\ViewController as ElementViewController;
use App\Http\Controllers\Public\Projects\FormatController as ProjectFormatController;
use App\Http\Controllers\Public\Projects\IndexController as ProjectIndexController;
use App\Http\Controllers\Public\Projects\Style\PinController as StylePinController;
use App\Http\Controllers\Public\Projects\Style\RoundController as StyleRoundController;
use App\Http\Controllers\Public\Projects\ViewController as ProjectViewController;
use App\Http\Controllers\Public\Shots\DestroyController as ShotDestroyController;
use App\Http\Controllers\Public\Shots\Keyframes\DestroyController as KeyframeDestroyController;
use App\Http\Controllers\Public\Shots\Keyframes\FirstController as FirstKeyframeController;
use App\Http\Controllers\Public\Shots\Keyframes\GenerateController as KeyframesGenerateController;
use App\Http\Controllers\Public\Shots\Keyframes\ImageController as KeyframeImageController;
use App\Http\Controllers\Public\Shots\Keyframes\RenderController as KeyframeRenderController;
use App\Http\Controllers\Public\Shots\Keyframes\ReorderController as KeyframeReorderController;
use App\Http\Controllers\Public\Shots\Keyframes\StoreController as KeyframeStoreController;
use App\Http\Controllers\Public\Shots\Keyframes\TweakController as KeyframeTweakController;
use App\Http\Controllers\Public\Shots\Keyframes\UpdateController as KeyframeUpdateController;
use App\Http\Controllers\Public\Shots\ReorderController as ShotReorderController;
use App\Http\Controllers\Public\Shots\Storyline\ChooseController as StorylineChooseController;
use App\Http\Controllers\Public\Shots\Storyline\GenerateController as StorylineGenerateController;
use App\Http\Controllers\Public\Shots\Storyline\SuggestController as StorylineSuggestController;
use App\Http\Controllers\Public\Shots\UpdateController as ShotUpdateController;
use App\Http\Controllers\Public\Shots\Video\GenerateController as VideoGenerateController;
use App\Http\Controllers\Public\Shots\ViewController as ShotViewController;
use App\Http\Controllers\Uploads\StoreController as UploadStoreController;
use App\Http\Controllers\Uploads\ViewController as UploadViewController;
use App\Models\Keyframe;
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
    Route::get('notifications', [NotificationIndexController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationReadController::class, 'store'])->name('notifications.read');

    Route::prefix('projects')
        ->name('projects.')
        ->group(function () {
            Route::get('/', [ProjectIndexController::class, 'index'])->name('index');
            Route::get('create', [ProjectCreateController::class, 'view'])->name('create');
            Route::post('create/chat', [ProjectChatController::class, 'store'])->name('chat');
            Route::get('{project}', [ProjectViewController::class, 'view'])->name('view');
            Route::get('{project}/setup', [ProjectCreateController::class, 'resume'])->name('setup');
            Route::get('{project}/cover', [ProjectCoverController::class, 'show'])->name('cover.view');
            Route::post('{project}/format', [ProjectFormatController::class, 'store'])->name('format');
            Route::delete('{project}', [ProjectDestroyController::class, 'destroy'])->name('destroy');
        });

    Route::prefix('projects/{project}/elements')
        ->name('projects.elements.')
        ->scopeBindings()
        ->group(function () {
            Route::get('create', [ElementViewController::class, 'create'])->name('create');
            Route::post('/', [ElementStoreController::class, 'store'])->name('store');
            Route::get('{element}', [ElementViewController::class, 'view'])->name('view');
            Route::post('{element}', [ElementUpdateController::class, 'store'])->name('update');
            Route::post('{element}/version', [ElementVersionController::class, 'store'])->name('version');
            Route::delete('{element}', [ElementDestroyController::class, 'destroy'])->name('destroy');
        });

    Route::prefix('projects/{project}/elements/rounds')
        ->name('projects.elements.rounds.')
        ->scopeBindings()
        ->group(function () {
            Route::get('{elementRound}', [ElementRoundController::class, 'show'])->name('view');
            Route::post('{elementRound}/pick', [ElementPickController::class, 'store'])->name('pick');
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
            Route::post('{shot}/keyframes', [KeyframeStoreController::class, 'store'])->name('keyframes.store');
            Route::post('{shot}/keyframes/reorder', [KeyframeReorderController::class, 'store'])->name('keyframes.reorder');
            Route::delete('{shot}/keyframes/{keyframe}', [KeyframeDestroyController::class, 'destroy'])->name('keyframes.destroy');
            Route::post('{shot}/keyframes/generate', [KeyframesGenerateController::class, 'store'])->name('keyframes.generate');
            Route::post('{shot}/keyframes/first/choose', [FirstKeyframeController::class, 'choose'])->name('keyframes.first.choose');
            Route::post('{shot}/keyframes/first/more', [FirstKeyframeController::class, 'more'])->name('keyframes.first.more');
            Route::post('{shot}/keyframes/{keyframe}/update', [KeyframeUpdateController::class, 'store'])->name('keyframes.update');
            Route::post('{shot}/keyframes/{keyframe}/tweak', [KeyframeTweakController::class, 'store'])->name('keyframes.tweak');
            Route::post('{shot}/keyframes/{keyframe}/render', [KeyframeRenderController::class, 'store'])->name('keyframes.render');
            Route::get('{shot}/keyframes/{keyframe}/image/{conversion?}', [KeyframeImageController::class, 'view'])
                ->whereIn('conversion', [Keyframe::THUMBNAIL])
                ->name('keyframes.image');
            Route::post('{shot}/video/generate', [VideoGenerateController::class, 'store'])->name('video.generate');
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
