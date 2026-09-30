<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Storage::fake(Disk::LOCAL->value);
});

it('stores the file on the configured disk by default', function () {
    Config::set('uploads.disk', Disk::LOCAL->value);

    $upload = Upload::fromFile(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'));

    expect($upload->disk)->toBe(Disk::LOCAL)
        ->and($upload->isImage())->toBeFalse()
        ->and($upload->extension())->toBe('pdf');

    Storage::disk(Disk::LOCAL->value)->assertExists($upload->path);
    Storage::disk(Disk::TENANT->value)->assertDirectoryEmpty('uploads');
});

it('stores the file on an explicit disk', function () {
    $upload = Upload::fromFile(UploadedFile::fake()->image('photo.png'), Disk::LOCAL);

    expect($upload->disk)->toBe(Disk::LOCAL);

    Storage::disk(Disk::LOCAL->value)->assertExists($upload->path);
});

it('removes the file from disk when deleted', function () {
    $upload = Upload::fromFile(UploadedFile::fake()->image('photo.png'));
    $path = $upload->path;

    $upload->delete();

    Storage::disk(Disk::TENANT->value)->assertMissing($path);
    expect(Upload::query()->count())->toBe(0);
});

it('lists expired uploads', function () {
    $fresh = Upload::factory()->create();
    $expired = Upload::factory()->expired()->create();

    expect(Upload::expired()->pluck('id')->all())->toBe([$expired->id]);
});

it('is cleaned up by app:cleanup once expired', function () {
    $fresh = Upload::fromFile(UploadedFile::fake()->image('fresh.png'));
    $expired = Upload::fromFile(UploadedFile::fake()->image('old.png'));
    $expired->forceFill(['created_at' => now()->subHours((int) Config::get('uploads.expires_after_hours') + 1)])->save();

    artisan('app:cleanup')->assertSuccessful();

    expect(Upload::query()->pluck('id')->all())->toBe([$fresh->id]);

    Storage::disk(Disk::TENANT->value)->assertExists($fresh->path);
    Storage::disk(Disk::TENANT->value)->assertMissing($expired->path);
});
