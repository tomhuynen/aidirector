<?php

declare(strict_types=1);

use App\Enums\ShotStatus;
use App\Jobs\GenerateStorylineOptions;
use App\Models\Director;
use App\Models\Element;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function validShot(array $overrides = []): array
{
    return [
        'takeaway' => 'Sending is quick and final',
        'notes' => null,
        'preferredElements' => [],
        'purposeOverride' => null,
        'aspectRatioOverride' => null,
        'duration' => null,
        ...$overrides,
    ];
}

describe('create', function () {
    it('appends a new shot at the end of the sequence', function () {
        Shot::factory()->for($this->project)->create(['position' => 1]);

        $response = actingAs($this->director, 'director')->post(route('public.shots.store', $this->project), validShot());

        $shot = Shot::query()->where('takeaway', 'Sending is quick and final')->firstOrFail();

        $response->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->position)->toBe(2)
            ->and($shot->status)->toBe(ShotStatus::OPTIONS_PENDING)
            ->and($shot->title)->toBe('Sending is quick and final')
            ->and($shot->subject)->toBeNull()
            ->and($shot->action)->toBeNull();

        Queue::assertPushed(GenerateStorylineOptions::class, fn(GenerateStorylineOptions $job) => $job->shot->is($shot));
    });

    it('keeps the cast and sets the director wants in the storylines', function () {
        $visitor = Element::factory()->for($this->project)->create();
        $quay = Element::factory()->for($this->project)->place()->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.store', $this->project), validShot([
                'notes' => 'It happens during the morning shift.',
                'preferredElements' => [$quay->sqid, $visitor->sqid],
            ]))
            ->assertSessionHasNoErrors();

        $shot = Shot::query()->firstOrFail();

        expect($shot->preferredElements()->modelKeys())->toEqualCanonicalizing([$visitor->id, $quay->id])
            ->and($shot->brief())
            ->toContain('Takeaway: Sending is quick and final')
            ->toContain('Context from the director: It happens during the morning shift.')
            ->toContain("The director wants these in the shot:\n- ")
            ->toContain($visitor->promptLine())
            ->not->toContain('Subject:');
    });

    it('rejects cast and sets from another project', function () {
        $foreign = Element::factory()->for(Project::factory())->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.store', $this->project), validShot(['preferredElements' => [$foreign->sqid]]))
            ->assertSessionHasErrors('preferredElements');

        expect(Shot::query()->count())->toBe(0);
    });

    it('validates the brief', function (array $overrides, string $field) {
        actingAs($this->director, 'director')
            ->post(route('public.shots.store', $this->project), validShot($overrides))
            ->assertSessionHasErrors($field);
    })->with([
        'missing takeaway' => [['takeaway' => ''], 'takeaway'],
        'unknown purpose override' => [['purposeOverride' => 'poetry'], 'purposeOverride'],
    ]);

    it('forbids adding shots to another director\'s project', function () {
        $other = Project::factory()->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.store', $other), validShot())
            ->assertForbidden();
    });
});

describe('view and update', function () {
    it('shows the shot workspace with its siblings', function () {
        $shots = Shot::factory()->for($this->project)->count(3)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
        )->create();

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shots->first()]))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('shots/view')->has('siblings', 3)->where('siblings.0.duration', 5));
    });

    it('updates an owned shot', function () {
        $shot = Shot::factory()->for($this->project)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.update', [$this->project, $shot]), validShot(['takeaway' => 'Posting is easy', 'duration' => 8]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->fresh())->takeaway->toBe('Posting is easy')->title->toBe('Posting is easy')->duration->toBe(8);
    });

    it('does not resolve a shot through a project it does not belong to', function () {
        $otherProject = Project::factory()->ownedBy($this->director)->create();
        $shot = Shot::factory()->for($otherProject)->create();

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertNotFound();
    });
});

describe('reorder and destroy', function () {
    it('reorders the shots of a project', function () {
        [$first, $second, $third] = Shot::factory()->for($this->project)->count(3)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
        )->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['shots' => [$third->sqid, $first->sqid, $second->sqid]])
            ->assertRedirect(route('public.projects.view', $this->project));

        expect($third->fresh()->position)->toBe(1)
            ->and($first->fresh()->position)->toBe(2)
            ->and($second->fresh()->position)->toBe(3);
    });

    it('rejects an order that does not cover every shot', function () {
        [$first] = Shot::factory()->for($this->project)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['shots' => [$first->sqid]])
            ->assertSessionHasErrors('shots');
    });

    it('destroys a shot and closes the gap', function () {
        [$first, $second, $third] = Shot::factory()->for($this->project)->count(3)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
        )->create();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy', [$this->project, $second]))
            ->assertRedirect(route('public.projects.view', $this->project));

        $this->assertModelMissing($second);
        expect($first->fresh()->position)->toBe(1)
            ->and($third->fresh()->position)->toBe(2);
    });
});
