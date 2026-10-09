<?php

declare(strict_types=1);

use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\RenderEstimates;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
    $this->shot = Shot::factory()->for($this->project)->create();
});

it('falls back to defaults while nothing was made yet', function () {
    $estimates = new RenderEstimates();

    expect($estimates->keyframeSeconds())->toBe(RenderEstimates::KEYFRAME_DEFAULT)
        ->and($estimates->videoSeconds())->toBe(RenderEstimates::VIDEO_DEFAULT)
        ->and($estimates->elementSeconds())->toBe(['draw' => RenderEstimates::ELEMENT_DEFAULT, 'edit' => RenderEstimates::ELEMENT_EDIT_DEFAULT]);
});

it('times drawing an element and changing it apart, by the model each runs on, and ignores a slow outlier', function () {
    config(['pipeline.models.image' => 'quick-model', 'pipeline.models.image_edit' => 'edit-model']);
    $element = App\Models\Element::factory()->for($this->project)->create();
    $make = fn(string $model, int $ms) => $element->generations()->create(['director_id' => $this->director->id, 'kind' => 'image', 'provider' => 'openrouter', 'model' => $model, 'duration_ms' => $ms]);

    $make('quick-model', 9_000);
    $make('quick-model', 10_000);
    $make('quick-model', 300_000);
    $make('edit-model', 100_000);

    expect((new RenderEstimates())->elementSeconds())->toBe(['draw' => 10, 'edit' => 100]);
});

it('learns the usual times from the latest successful generations', function () {
    $keyframe = Keyframe::factory()->for($this->shot)->create();
    $make = fn($owner, string $kind, ?int $ms, ?string $error = null) => $owner->generations()->create(['director_id' => $this->director->id, 'kind' => $kind, 'provider' => 'openrouter', 'model' => 'x', 'duration_ms' => $ms, 'error' => $error]);

    $make($keyframe, 'image', 30_000);
    $make($keyframe, 'image', 50_000);
    $make($keyframe, 'image', 900_000, 'Timed out');
    $make($keyframe, 'text', 8_000);
    $make($this->shot, 'video', 120_000);
    $make($this->shot, 'video', null);

    $estimates = new RenderEstimates();

    expect($estimates->keyframeSeconds())->toBe(48)
        ->and($estimates->videoSeconds())->toBe(120);
});

it('gives the editor the usual times and when the video was submitted', function () {
    $this->shot->forceFill(['video_submitted_at' => now()->subMinute()])->save();

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $this->shot]))
        ->assertInertia(fn($page) => $page
            ->where('shot.renderSeconds', ['keyframe' => RenderEstimates::KEYFRAME_DEFAULT, 'video' => RenderEstimates::VIDEO_DEFAULT])
            ->where('shot.videoSubmittedAt', now()->subMinute()->toIso8601String()));
});
