<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Models\Director;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * In this project the public upload routes sit behind the director login,
 * so the public cases act as a director.
 */
beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->upload = Upload::fromFile(UploadedFile::fake()->image('photo.png', 20, 20));

    actingAs(Director::factory()->create(), 'director');
});

it('streams the file from a signed public link', function () {
    $url = URL::temporarySignedRoute('public.uploads.view', now()->addMinutes(5), ['upload' => $this->upload]);

    get($url)
        ->assertSuccessful()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('content-disposition', 'inline; filename=photo.png');
});

it('rejects an unsigned public link', function () {
    get(route('public.uploads.view', $this->upload))
        ->assertForbidden();
});

it('rejects an expired public link', function () {
    $url = URL::temporarySignedRoute('public.uploads.view', now()->subMinute(), ['upload' => $this->upload]);

    get($url)
        ->assertForbidden();
});

it('streams the file from a signed admin link for a signed-in user', function () {
    $url = URL::temporarySignedRoute('admin.uploads.view', now()->addMinutes(5), ['upload' => $this->upload]);

    actingAs(User::factory()->create())
        ->get($url)
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename=photo.png');
});

it('returns not found for an unknown upload', function () {
    $url = URL::temporarySignedRoute('public.uploads.view', now()->addMinutes(5), ['upload' => 'nope']);

    get($url)
        ->assertNotFound();
});
