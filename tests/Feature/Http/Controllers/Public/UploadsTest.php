<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Models\Director;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
});

it('lets a director stage an upload', function () {
    $director = Director::factory()->create();

    $response = actingAs($director, 'director')
        ->postJson(route('public.uploads.store'), ['file' => UploadedFile::fake()->image('tug.jpg', 300, 200)])
        ->assertSuccessful();

    $upload = Upload::query()->firstOrFail();

    expect($response->json('id'))->toBe($upload->sqid)
        ->and($response->json('url'))->toContain(route('public.uploads.view', $upload));
});

it('requires a signed-in director', function () {
    postJson(route('public.uploads.store'), ['file' => UploadedFile::fake()->image('tug.jpg')])
        ->assertUnauthorized();
});
