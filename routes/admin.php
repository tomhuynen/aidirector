<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Accounts\DestroyController as AccountDestroyController;
use App\Http\Controllers\Admin\Accounts\IndexController as AccountIndexController;
use App\Http\Controllers\Admin\Accounts\InviteController as AccountInviteController;
use App\Http\Controllers\Admin\Accounts\UpdateController as AccountUpdateController;
use App\Http\Controllers\Admin\Accounts\ViewController as AccountViewController;
use App\Http\Controllers\Admin\Activities\IndexController as ActivityIndexController;
use App\Http\Controllers\Admin\Activities\ViewController as ActivityViewController;
use App\Http\Controllers\Admin\Config\SettingsController;
use App\Http\Controllers\Admin\Dashboard\IndexController as DashboardIndexController;
use App\Http\Controllers\Admin\Settings\Passkeys\CreateController as PasskeysCreateController;
use App\Http\Controllers\Admin\Settings\Passkeys\DeleteController as PasskeysDeleteController;
use App\Http\Controllers\Admin\Settings\Passkeys\OptionsController as PasskeysOptionsController;
use App\Http\Controllers\Admin\Settings\PasswordController;
use App\Http\Controllers\Admin\Settings\ProfileController;
use App\Http\Controllers\Admin\Settings\SecurityController;
use App\Http\Controllers\Admin\System\DatabaseController;
use App\Http\Controllers\Admin\System\ServerController;
use App\Http\Controllers\Admin\Tenants\DestroyController as TenantDestroyController;
use App\Http\Controllers\Admin\Tenants\IndexController as TenantIndexController;
use App\Http\Controllers\Admin\Tenants\SwitchController as TenantSwitchController;
use App\Http\Controllers\Admin\Tenants\UpdateController as TenantUpdateController;
use App\Http\Controllers\Admin\Tenants\ViewController as TenantViewController;
use App\Http\Controllers\Admin\Widgets\ActionController as WidgetActionController;
use App\Http\Controllers\Uploads\StoreController as UploadStoreController;
use App\Http\Controllers\Uploads\ViewController as UploadViewController;
use App\Support\Search\Http\Controllers\Search\IndexController as SearchIndexController;
use App\Support\Search\Http\Controllers\Search\ViewController as SearchViewController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [DashboardIndexController::class, 'index'])->name('dashboard.index');

Route::prefix('accounts')
    ->name('accounts.')
    ->group(function () {
        Route::get('create', [AccountUpdateController::class, 'update'])->name('create');
        Route::post('create', [AccountUpdateController::class, 'store'])->name('store');
        Route::get('{account}/update', [AccountUpdateController::class, 'update'])->name('update');
        Route::post('{account}/update', [AccountUpdateController::class, 'store']);
        Route::get('{account}/invite', [AccountInviteController::class, 'invite'])->name('invite');
        Route::get('{account}', [AccountViewController::class, 'view'])->name('view');
        Route::get('/', [AccountIndexController::class, 'index'])->name('index');
        Route::delete('{account}', [AccountDestroyController::class, 'destroy'])->name('destroy');
    });

Route::prefix('activities')
    ->name('activities.')
    ->group(function () {
        Route::get('/', [ActivityIndexController::class, 'index'])->name('index');
        Route::get('{activity}', [ActivityViewController::class, 'view'])->name('view');
    });

Route::prefix('uploads')
    ->name('uploads.')
    ->group(function () {
        Route::post('/', [UploadStoreController::class, 'store'])->name('store');
        Route::get('{upload}', [UploadViewController::class, 'view'])->middleware('signed')->name('view');
    });

Route::prefix('config')
    ->name('config.')
    ->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    });

Route::prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::redirect('', '/admin/settings/profile');

        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');

        Route::get('security', [SecurityController::class, 'view'])->name('security.view');

        Route::name('passkeys.')
            ->prefix('passkeys')
            ->group(function () {
                Route::get('options/create', [PasskeysOptionsController::class, 'create'])->name('options.create');
                Route::post('create', [PasskeysCreateController::class, 'store'])->name('store');
                Route::delete('{passkey}', [PasskeysDeleteController::class, 'destroy'])->name('destroy');
            });

        Route::get('appearance', fn() => Inertia::render('settings/appearance'))->name('appearance');
    });

Route::prefix('tenants')
    ->name('tenants.')
    ->group(function () {
        Route::get('/', [TenantIndexController::class, 'index'])->name('index');
        Route::get('create', [TenantUpdateController::class, 'update'])->name('create');
        Route::post('create', [TenantUpdateController::class, 'store'])->name('store');
        Route::get('{tenant}', [TenantViewController::class, 'view'])->name('view');
        Route::delete('{tenant}', [TenantDestroyController::class, 'destroy'])->name('destroy');
        Route::get('{tenant}/update', [TenantUpdateController::class, 'update'])->name('update');
        Route::post('{tenant}/update', [TenantUpdateController::class, 'store']);
        Route::patch('{tenant}/switch', [TenantSwitchController::class, 'update'])->name('switch');
    });

Route::prefix('system')
    ->name('system.')
    ->group(function () {
        Route::redirect('', '/admin/system/server');

        Route::post('server', [ServerController::class, 'store'])->name('server.store');
        Route::get('server', [ServerController::class, 'index'])->name('server');
        Route::get('database', [DatabaseController::class, 'index'])->name('database');
        Route::get('/database/download/{name}', [DatabaseController::class, 'download'])->name('database.download');
    });

Route::prefix('widgets')
    ->name('widgets.')
    ->group(function () {
        Route::post('action/execute', [WidgetActionController::class, 'execute'])->name('action.execute');
    });

Route::prefix('search')
    ->name('search.')
    ->group(function () {
        Route::get('/{entity}', [SearchIndexController::class, 'index'])->name('index');
        Route::get('/{entity}/{id}', [SearchViewController::class, 'view'])->name('view');
    });

Route::impersonate();
