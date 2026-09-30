<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Uploads\StoreController as UploadStoreController;
use App\Http\Controllers\Uploads\ViewController as UploadViewController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

/*
 * Uploads: storing needs a signed URL or an authorised user (see StoreRequest),
 * viewing needs the signed link from the upload resource.
 */
Route::prefix('uploads')
    ->name('uploads.')
    ->group(function () {
        Route::post('/', [UploadStoreController::class, 'store'])->name('store');
        Route::get('{upload}', [UploadViewController::class, 'view'])->middleware('signed')->name('view');
    });
