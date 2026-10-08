<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineWriter;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\ShotStatus;
use App\Jobs\GenerateStoryline;
use App\Jobs\UpdateElementImage;
use App\Models\Director;
use App\Models\Element;
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

function shotNeedingElements(Project $project): Shot
{
    return Shot::factory()->for($project)->create([
        'status' => ShotStatus::STORYLINE_READY,
        'storyline' => [
            'keyframes' => [['title' => 'Design', 'description' => 'In the Design office, she draws a hull.', 'elements' => ['Design office']]],
            'new_elements' => [['name' => 'Design office', 'type' => 'place', 'description' => 'A bright office with a large drawing table under the windows.']],
        ],
    ]);
}

it('keeps the places and objects the plan needs that the cast and sets do not have yet', function () {
    Queue::fake();
    Element::factory()->for($this->project)->create(['name' => 'Shipbuilding Hall', 'type' => ElementType::PLACE]);
    StorylineWriter::fake([[
        'title' => 'Vessel Life', 'kind' => 'montage', 'storyline' => 'Designed, then built.',
        'split' => ['needed' => false, 'parts' => []],
        'new_elements' => [
            ['name' => 'Design office', 'type' => 'place', 'description' => 'A bright office with a large drawing table.'],
            ['name' => 'Shipbuilding hall', 'type' => 'place', 'description' => 'Already in the cast.'],
        ],
        'framing' => ['spot' => '', 'light' => 'as the visual style', 'seconds' => 6],
        'keyframes' => [['title' => 'Design', 'description' => 'In the Design office, she draws a hull.', 'spatial' => '', 'elements' => ['Design office']]],
    ]]);
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_PENDING]);

    (new GenerateStoryline($shot))->handle();

    expect($shot->fresh()->storyline['new_elements'])->toEqual([['name' => 'Design office', 'type' => 'place', 'description' => 'A bright office with a large drawing table.']]);
    StorylineWriter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Give every new place or object the story turns on')
        && str_contains((string) $prompt->agent->instructions(), 'Never present such an object as part of another item in the cast and sets'));
});

it('adds them to the cast and sets with their picture drawn, and waits for it before drawing the shot', function () {
    Queue::fake();
    $shot = shotNeedingElements($this->project);

    actingAs($this->director, 'director')
        ->post(route('public.shots.plan.elements', [$this->project, $shot]))
        ->assertSessionHasNoErrors();

    $office = $this->project->elements()->where('name', 'Design office')->firstOrFail();

    expect($office)->type->toBe(ElementType::PLACE)->rendering->toBeTrue()
        ->and($shot->fresh()->storyline)->not->toHaveKey('new_elements')
        ->and($shot->fresh()->storyline['added_elements'])->toBe([$office->sqid]);
    Queue::assertPushed(UpdateElementImage::class, fn(UpdateElementImage $job) => $job->element->is($office));

    // Drawing waits for the picture and starts by itself once it is there.
    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.generate', [$this->project, $shot]))
        ->assertSessionHasNoErrors();

    expect($shot->fresh()->waitsToDraw())->toBeTrue()
        ->and($shot->fresh()->waitsFor())->toBe('the pictures of the new cast and sets');

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.addedElements', [$office->sqid])->where('shot.newElements', []));
});

it('leaves them out when the director says so', function () {
    $shot = shotNeedingElements($this->project);

    actingAs($this->director, 'director')
        ->delete(route('public.shots.plan.elements', [$this->project, $shot]))
        ->assertSessionHasNoErrors();

    expect($shot->fresh()->storyline)->not->toHaveKey('new_elements')
        ->and($this->project->elements()->count())->toBe(0);
});
