<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\StyleOptionStatus;
use App\Jobs\GenerateStyleOption;
use App\Models\Generation;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->project = Project::factory()->create();
    $this->project->addMedia(UploadedFile::fake()->image('tug.jpg', 640, 480))
        ->withCustomProperties([Project::CAPTION => 'A grey tug.'])
        ->toMediaCollection(Project::CONTENT_REFERENCES);
    $this->project->addMedia(UploadedFile::fake()->image('yard.jpg', 640, 480))->toMediaCollection(Project::CONTENT_REFERENCES);

    $this->option = StyleOption::factory()->for($this->project)->create(['prompt' => 'A 2x2 style sheet in Soft 3D cartoon.']);
});

it('renders the sheet from the reference photos and stores it', function () {
    $png = base64_encode(UploadedFile::fake()->image('sheet.png', 1600, 900)->getContent());
    Image::fake([$png]);

    (new GenerateStyleOption($this->option))->handle();

    $option = $this->option->fresh();

    expect($option->status)->toBe(StyleOptionStatus::READY)
        ->and($option->error)->toBeNull()
        ->and($option->render())->not->toBeNull()
        ->and($option->render()->file_name)->toBe('style-sheet-' . $option->sqid . '.png')
        ->and($option->render()->hasGeneratedConversion(StyleOption::THUMBNAIL))->toBeTrue();

    $generation = Generation::query()->where('kind', 'image')->firstOrFail();

    expect($generation->generatable)->toBeInstanceOf(StyleOption::class)
        ->and($generation->director_id)->toBe($this->project->director_id)
        ->and($generation->error)->toBeNull();

    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->prompt === 'A 2x2 style sheet in Soft 3D cartoon.'
        && $prompt->attachments->count() === 2
        && $prompt->size === '1:1');
});

it('marks the option failed and logs the error', function () {
    (new GenerateStyleOption($this->option))->failed(new RuntimeException('Model down'));

    $option = $this->option->fresh();

    expect($option->status)->toBe(StyleOptionStatus::FAILED)
        ->and($option->error)->not->toBeNull()
        ->and(Generation::query()->where('kind', 'image')->firstOrFail()->error)->toBe('Model down');
});
