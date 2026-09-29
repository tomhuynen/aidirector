<?php

declare(strict_types=1);

use App\Enums\ShotStatus;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function validShot(array $overrides = []): array
{
    return [
        'title' => 'Posting the letter',
        'subject' => 'A businessman in a navy suit',
        'action' => 'Walks to the mailbox and posts an envelope',
        'takeaway' => 'Sending is quick and final',
        'notes' => null,
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

        $shot = Shot::query()->where('title', 'Posting the letter')->firstOrFail();

        $response->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->position)->toBe(2)
            ->and($shot->status)->toBe(ShotStatus::DRAFT);
    });

    it('validates the structured intent', function (array $overrides, string $field) {
        actingAs($this->director, 'director')
            ->post(route('public.shots.store', $this->project), validShot($overrides))
            ->assertSessionHasErrors($field);
    })->with([
        'missing subject' => [['subject' => ''], 'subject'],
        'missing action' => [['action' => ''], 'action'],
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
            ->assertInertia(fn($page) => $page->component('shots/view')->has('siblings', 3));
    });

    it('updates an owned shot', function () {
        $shot = Shot::factory()->for($this->project)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.update', [$this->project, $shot]), validShot(['title' => 'Renamed', 'duration' => 8]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->fresh())->title->toBe('Renamed')->duration->toBe(8);
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
