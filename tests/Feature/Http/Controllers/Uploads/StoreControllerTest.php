<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Models\Director;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->file = UploadedFile::fake()->image('avatar.png', 400, 400);
});

/*
 * In this project the public upload route sits behind the director login
 * (see UploadsTest for the guard), so these tests act as a director.
 */
describe('public route', function () {
    beforeEach(function () {
        actingAs(Director::factory()->create(), 'director');
    });

    it('stores a file on the configured disk and returns the upload', function () {
        $response = postJson(URL::signedRoute('public.uploads.store'), ['file' => $this->file])
            ->assertSuccessful()
            ->assertJsonStructure(['id', 'name', 'extension', 'mimeType', 'size', 'isImage', 'url']);

        $upload = Upload::query()->firstOrFail();

        expect($upload->disk)->toBe(Disk::TENANT)
            ->and($upload->name)->toBe('avatar.png')
            ->and($upload->mime_type)->toBe('image/png')
            ->and($upload->size)->toBe($this->file->getSize())
            ->and($upload->path)->toStartWith("uploads/{$upload->id}/")
            ->and($response->json('id'))->toBe($upload->sqid)
            ->and($response->json('isImage'))->toBeTrue()
            ->and($response->json('url'))->toContain(route('public.uploads.view', $upload));

        Storage::disk(Disk::TENANT->value)->assertExists($upload->path);
    });

    it('rejects files that are not allowed', function (UploadedFile $file) {
        postJson(URL::signedRoute('public.uploads.store'), ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        expect(Upload::query()->count())->toBe(0);
    })->with([
        'unsupported type' => fn() => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        'too large' => fn() => UploadedFile::fake()->image('huge.png')->size(intdiv((int) Config::get('uploads.max_file_size'), 1024) + 1),
    ]);

    it('requires a file', function () {
        postJson(URL::signedRoute('public.uploads.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    });
});

describe('admin route', function () {
    it('rejects users without the upload ability', function () {
        actingAs(User::factory()->create())
            ->postJson(route('admin.uploads.store'), ['file' => $this->file])
            ->assertForbidden();
    });

    it('stores a file for an authorised user without a signature', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('admin.uploads.store');

        $response = actingAs($user)
            ->postJson(route('admin.uploads.store'), ['file' => $this->file])
            ->assertSuccessful();

        $upload = Upload::query()->firstOrFail();

        expect($response->json('id'))->toBe($upload->sqid)
            ->and($response->json('url'))->toContain(route('admin.uploads.view', $upload));
    });

    it('requires a signed-in user', function () {
        postJson(route('admin.uploads.store'), ['file' => $this->file])
            ->assertUnauthorized();
    });
});
