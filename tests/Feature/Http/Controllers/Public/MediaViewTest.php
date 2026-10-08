<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Models\Director;
use App\Models\Project;
use App\Support\Media\LocalMediaFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Queue::fake();
    config(['media-library.disk_name' => Disk::TENANT_CLOUD->value]);
    Storage::fake(Disk::TENANT_CLOUD->value);
    Storage::disk(Disk::TENANT_CLOUD->value)->buildTemporaryUrlsUsing(
        fn(string $path, DateTimeInterface $expiration, array $options) => 'https://bucket.test/' . $path . '?expires=' . $expiration->getTimestamp() . '&' . http_build_query($options, encoding_type: PHP_QUERY_RFC3986),
    );

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
    $this->cover = $this->project->addMedia(UploadedFile::fake()->image('cover.jpg', 40, 30))->toMediaCollection(Project::COVER);
});

it('hands cloud media over as a presigned link to the file in storage', function () {
    actingAs($this->director, 'director')
        ->get($this->cover->signedUrl())
        ->assertRedirect()
        ->assertHeader('Cache-Control', 'max-age=3300, private')
        ->assertRedirectContains('https://bucket.test/')
        ->assertRedirectContains('expires=' . now()->addHour()->getTimestamp());
});

it('names a cloud download through the presigned link', function () {
    $url = URL::temporarySignedRoute('public.media.view', now()->addMinute(), ['media' => $this->cover, 'download' => 'SH010 Security.jpg']);

    actingAs($this->director, 'director')
        ->get($url)
        ->assertRedirectContains('ResponseContentDisposition=' . rawurlencode('attachment; filename="SH010 Security.jpg"'));
});

it('copies cloud media to a local file for image tools and removes it afterwards', function () {
    $files = new LocalMediaFiles();
    $path = $files->path($this->cover);

    expect(LocalMediaFiles::isLocalDisk(Disk::TENANT_CLOUD->value))->toBeFalse()
        ->and(LocalMediaFiles::isLocalDisk(Disk::TENANT->value))->toBeTrue()
        ->and($path)->toStartWith(storage_path('app/tmp/media-'))->toEndWith('.jpg')
        ->and(getimagesize($path))->toMatchArray([0 => 40, 1 => 30])
        ->and($files->path($this->cover))->toBe($path);

    unset($files);

    expect(file_exists($path))->toBeFalse();
});
