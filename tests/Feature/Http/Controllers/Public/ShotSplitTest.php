<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineWriter;
use App\Enums\Disk;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateStoryline;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * @return array<string, mixed>
 */
function planWithSplit(bool $needed): array
{
    return [
        'title' => 'Visible Badge',
        'kind' => 'scene',
        'split' => ['needed' => $needed, 'parts' => $needed ? [
            ['takeaway' => 'Wear your access badge visibly.', 'kind' => 'scene'],
            ['takeaway' => 'Report a damaged badge straight away.', 'kind' => 'close-up'],
        ] : []],
        'storyline' => 'She clips her badge on and later reports a damaged one.',
        'framing' => ['spot' => 'At the reception counter.', 'light' => 'as the visual style', 'seconds' => 7],
        'keyframes' => [['title' => 'Badge On', 'description' => 'She clips the badge on.', 'spatial' => '', 'elements' => []]],
    ];
}

it('keeps the planner\'s proposal to split a two-message takeaway and shows it on the plan', function () {
    Queue::fake();
    StorylineWriter::fake([planWithSplit(true)]);
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_PENDING, 'takeaway' => 'Wear your access badge visibly and report loss or damage straight away.']);

    (new GenerateStoryline($shot, draw: false))->handle();

    expect($shot->fresh()->storyline['split']['parts'][1])->toEqual(['takeaway' => 'Report a damaged badge straight away.', 'kind' => 'close-up']);
    StorylineWriter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'One message per shot'));

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.split.parts.0.takeaway', 'Wear your access badge visibly.'));
});

it('splits the shot: this one keeps the first message, a new one right after gets the second, both planned again', function () {
    Queue::fake();
    $after = Shot::factory()->for($this->project)->create(['position' => 2, 'title' => 'After']);
    $shot = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => [], 'split' => GenerateStoryline::splitFrom(planWithSplit(true)['split'])]]);

    actingAs($this->director, 'director')
        ->post(route('public.shots.plan.split', [$this->project, $shot]))
        ->assertSessionHasNoErrors();

    $next = Shot::query()->where('position', 2)->firstOrFail();

    expect($shot->fresh())->takeaway->toBe('Wear your access badge visibly.')->kind->toBe(ShotKind::SCENE)->status->toBe(ShotStatus::STORYLINE_PENDING)->storyline->toBeNull()
        ->and($next->takeaway)->toBe('Report a damaged badge straight away.')
        ->and($next->kind)->toBe(ShotKind::CLOSE_UP)
        ->and($after->fresh()->position)->toBe(3);
    Queue::assertPushed(GenerateStoryline::class, 2);
    Queue::assertPushed(GenerateStoryline::class, fn(GenerateStoryline $job) => $job->keepKind && ! $job->draw && $job->shot->is($next));
});

it('keeps the shot as one and does not propose the split again on a revision', function () {
    Queue::fake();
    StorylineWriter::fake([planWithSplit(true)]);
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => [['title' => 'A', 'description' => 'B']], 'split' => GenerateStoryline::splitFrom(planWithSplit(true)['split'])]]);

    actingAs($this->director, 'director')
        ->delete(route('public.shots.plan.split', [$this->project, $shot]))
        ->assertSessionHasNoErrors();

    (new GenerateStoryline($shot->fresh(), 'make her smile'))->handle();

    expect($shot->fresh()->storyline['split'])->toBe(['dismissed' => true]);

    // Nothing is split once keyframes are drawn.
    $drawn = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['keyframes' => [], 'split' => GenerateStoryline::splitFrom(planWithSplit(true)['split'])]]);
    Keyframe::factory()->for($drawn)->create();

    actingAs($this->director, 'director')
        ->post(route('public.shots.plan.split', [$this->project, $drawn]))
        ->assertSessionHasErrors('split');
});
