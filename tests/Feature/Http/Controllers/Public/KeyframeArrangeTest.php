<?php

declare(strict_types=1);

use App\Enums\ShotStatus;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Collection;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A shot with three drawn keyframes, each matching its planned keyframe.
 *
 * @return array{0: Shot, 1: Collection<int, Keyframe>}
 */
function arrangedShot(Project $project, array $attributes = []): array
{
    $plans = collect(['Arrive', 'Notice', 'Leave'])->map(fn(string $title) => [
        'title' => $title,
        'description' => "{$title} description",
        'prompt' => "{$title} prompt",
    ]);

    $shot = Shot::factory()->for($project)->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'storyline' => ['keyframes' => $plans->all()],
        ...$attributes,
    ]);

    $keyframes = $plans->map(fn(array $plan, int $index) => Keyframe::factory()->for($shot)->create([
        'position' => $index + 1,
        'title' => $plan['title'],
        'description' => $plan['description'],
    ]));

    return [$shot, $keyframes];
}

describe('reorder', function () {
    it('puts the keyframes and their plan in the dragged order', function () {
        [$shot, [$arrive, $notice, $leave]] = arrangedShot($this->project);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.reorder', [$this->project, $shot]), ['keyframes' => [$leave->sqid, $arrive->sqid, $notice->sqid]])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->keyframes()->pluck('title')->all())->toBe(['Leave', 'Arrive', 'Notice'])
            ->and(array_column($shot->fresh()->storylineKeyframes(), 'prompt'))->toBe(['Leave prompt', 'Arrive prompt', 'Notice prompt']);
    });

    it('rejects an order that does not match the keyframes', function () {
        [$shot, [$arrive, $notice]] = arrangedShot($this->project);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.reorder', [$this->project, $shot]), ['keyframes' => [$notice->sqid, $arrive->sqid]])
            ->assertSessionHasErrors('keyframes');

        expect($shot->keyframes()->pluck('title')->all())->toBe(['Arrive', 'Notice', 'Leave']);
    });

    it('waits while a keyframe is being drawn', function () {
        [$shot, [$arrive, $notice, $leave]] = arrangedShot($this->project);
        $notice->forceFill(['rendering' => true])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.reorder', [$this->project, $shot]), ['keyframes' => [$leave->sqid, $notice->sqid, $arrive->sqid]])
            ->assertSessionHasErrors('keyframes');

        expect($shot->keyframes()->pluck('title')->all())->toBe(['Arrive', 'Notice', 'Leave']);
    });

    it('forbids reordering another director\'s keyframes', function () {
        $other = Project::factory()->create();
        [$shot, $keyframes] = arrangedShot($other);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.reorder', [$other, $shot]), ['keyframes' => $keyframes->pluck('sqid')->reverse()->values()->all()])
            ->assertForbidden();
    });
});

describe('destroy', function () {
    it('deletes a keyframe and moves the later ones up with their plan', function () {
        [$shot, [$arrive, $notice, $leave]] = arrangedShot($this->project);

        actingAs($this->director, 'director')
            ->delete(route('public.shots.keyframes.destroy', [$this->project, $shot, $notice]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect(Keyframe::query()->find($notice->id))->toBeNull()
            ->and($shot->keyframes()->get(['title', 'position'])->map->only('title', 'position')->all())->toBe([
                ['title' => 'Arrive', 'position' => 1],
                ['title' => 'Leave', 'position' => 2],
            ])
            ->and(array_column($shot->fresh()->storylineKeyframes(), 'title'))->toBe(['Arrive', 'Leave']);
    });

    it('keeps the last keyframe of a shot', function () {
        [$shot, [$arrive, $notice, $leave]] = arrangedShot($this->project);
        $notice->delete();
        $leave->delete();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.keyframes.destroy', [$this->project, $shot, $arrive]))
            ->assertSessionHasErrors('keyframe');

        expect($shot->keyframes()->count())->toBe(1);
    });

    it('waits until the keyframes are drawn', function () {
        [$shot, [$arrive]] = arrangedShot($this->project, ['status' => ShotStatus::KEYFRAMES_PENDING]);

        actingAs($this->director, 'director')
            ->delete(route('public.shots.keyframes.destroy', [$this->project, $shot, $arrive]))
            ->assertSessionHasErrors('keyframe');

        expect($shot->keyframes()->count())->toBe(3);
    });

    it('does not delete a keyframe through another shot', function () {
        [$shot] = arrangedShot($this->project);
        [, [$foreign]] = arrangedShot($this->project);

        actingAs($this->director, 'director')
            ->delete(route('public.shots.keyframes.destroy', [$this->project, $shot, $foreign]))
            ->assertNotFound();

        expect(Keyframe::query()->find($foreign->id))->not->toBeNull();
    });

    it('offers the delete and reorder links in the editor', function () {
        [$shot, [$arrive]] = arrangedShot($this->project);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('shot.links.keyframesReorder', route('public.shots.keyframes.reorder', [$this->project, $shot]))
                ->where('keyframes.0.links.destroy', route('public.shots.keyframes.destroy', [$this->project, $shot, $arrive])));
    });
});
