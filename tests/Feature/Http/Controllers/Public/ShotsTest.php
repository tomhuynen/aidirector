<?php

declare(strict_types=1);

use App\Enums\ShotStatus;
use App\Jobs\GenerateStoryline;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
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
    it('gives an empty plan to fill in when the director writes the keyframes themselves', function () {
        actingAs($this->director, 'director')->post(route('public.shots.store', $this->project), validShot(['manual' => true]));

        $shot = Shot::query()->where('takeaway', 'Sending is quick and final')->firstOrFail();

        expect($shot->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($shot->storylineKeyframes())->toBe([])
            ->and($shot->storyline['mode'])->toBe('manual');
        Queue::assertNotPushed(GenerateStoryline::class);
    });

    it('works out which keyframes a chat message touches before writing them', function () {
        \App\Ai\Agents\PlanChangeInterpreter::fake([['changes' => [['op' => 'insert', 'at' => 1, 'from' => 0, 'instruction' => 'she walks up to the door'], ['op' => 'update', 'at' => 3, 'from' => 0, 'instruction' => 'she looks up']], 'reply' => 'Adding a first keyframe and changing keyframe 2.']]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.changes', [$this->project, $shot]), [
                'message' => 'put a frame before 1 where she walks up to the door, and in frame 2 she looks up',
                'keyframes' => [['title' => 'At the sign', 'description' => 'She reads the sign.'], ['title' => 'At the bin', 'description' => 'She drops it.']],
            ])
            ->assertOk()
            ->assertJsonPath('changes.0', ['op' => 'insert', 'at' => 1, 'from' => 0, 'instruction' => 'she walks up to the door'])
            ->assertJsonPath('changes.1.op', 'update')
            ->assertJsonPath('reply', 'Adding a first keyframe and changing keyframe 2.');

        \App\Ai\Agents\PlanChangeInterpreter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "1. At the sign: She reads the sign.\n2. At the bin: She drops it."));
    });

    it('keeps a rule for the whole shot, rewrites the keyframes and the storyline that break it', function () {
        \App\Ai\Agents\PlanChangeInterpreter::fake([[
            'changes' => [['op' => 'update', 'at' => 2, 'from' => 0, 'instruction' => 'She puts only one foot over the red line, the other stays in the blue lane.']],
            'rule' => 'The visitor never crosses the red line with more than one foot.',
            'storyline' => 'She puts one foot over the red line, sees the load and steps back.',
            'reply' => 'Keeping this rule and changing keyframe 2.',
        ]]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'rules' => ['The crate always hangs above the zone.', 'Removed in the editor.']]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.changes', [$this->project, $shot]), [
                'message' => 'never let her cross the red line with more than one foot',
                'storyline' => 'She steps into the red zone, sees the load and steps back.',
                'rules' => ['The crate always hangs above the zone.'],
                'keyframes' => [
                    ['title' => 'In the lane', 'description' => 'She walks in the blue lane.'],
                    ['title' => 'Wrong zone', 'description' => 'She stands in the red zone.', 'prompt' => 'She stands side-on inside the red zone.', 'mustShow' => 'Both feet are inside the red zone.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('changes.0.at', 2)
            ->assertJsonPath('rule', 'The visitor never crosses the red line with more than one foot.')
            ->assertJsonPath('rules', ['The crate always hangs above the zone.', 'The visitor never crosses the red line with more than one foot.'])
            ->assertJsonPath('storyline', 'She puts one foot over the red line, sees the load and steps back.');

        expect($shot->fresh()->shotRules())->toBe(['The crate always hangs above the zone.', 'The visitor never crosses the red line with more than one foot.']);
        \App\Ai\Agents\PlanChangeInterpreter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Storyline: She steps into the red zone')
            && str_contains($prompt->prompt, '- The crate always hangs above the zone.')
            && str_contains($prompt->prompt, 'Must show: Both feet are inside the red zone.')
            && str_contains($prompt->prompt, 'Image instruction: She stands side-on inside the red zone.'));
    });

    it('writes the asked keyframes in full in a drafted plan and only the director\'s words in their own plan', function () {
        $engineer = \App\Models\Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        \App\Ai\Agents\PlanFrameWriter::fake([
            ['keyframes' => [['position' => 1, 'title' => 'Walks Up', 'description' => 'The Female engineer walks up to the door.', 'prompt' => 'At the door, the Female engineer, back to the camera.', 'must_show' => 'She is two steps from the door.', 'elements' => ['Female engineer']]]],
            ['keyframes' => [['position' => 1, 'title' => 'Walks up', 'description' => 'She walks up to the door.', 'prompt' => '', 'must_show' => '', 'elements' => []]]],
        ]);
        $drafted = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['mode' => 'auto', 'keyframes' => []]]);
        $own = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['mode' => 'manual', 'keyframes' => []]]);
        $body = ['message' => 'put a frame before 1', 'storyline' => 'She goes in.', 'keyframes' => [['title' => '', 'description' => ''], ['title' => 'At the sign', 'description' => 'She reads the sign.']], 'targets' => [['position' => 1, 'instruction' => 'she walks up to the door']]];

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.write', [$this->project, $drafted]), $body)
            ->assertOk()
            ->assertJsonPath('keyframes.0', ['position' => 1, 'title' => 'Walks Up', 'description' => 'The Female engineer walks up to the door.', 'prompt' => 'At the door, the Female engineer, back to the camera.', 'mustShow' => 'She is two steps from the door.', 'elements' => [$engineer->sqid]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.write', [$this->project, $own]), $body)
            ->assertOk()
            ->assertJsonPath('keyframes.0.prompt', '');

        \App\Ai\Agents\PlanFrameWriter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'you write only the keyframes you are given')
            && str_contains($prompt->prompt, "1. (new, to write)\n2. At the sign: She reads the sign."));
        \App\Ai\Agents\PlanFrameWriter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Never add people, objects, poses, directions or details that the director did not give'));
    });

    it('rewrites a keyframe from what it has now, following the shot\'s rules', function () {
        \App\Ai\Agents\PlanFrameWriter::fake([['keyframes' => []]]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['mode' => 'auto', 'keyframes' => []], 'rules' => ['The visitor never crosses the red line with more than one foot.']]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.write', [$this->project, $shot]), [
                'message' => 'never more than one foot over the line',
                'storyline' => 'She puts one foot over the line and steps back.',
                'keyframes' => [['title' => 'Wrong zone', 'description' => 'She stands in the red zone.', 'mustShow' => 'Both feet are inside the red zone.']],
                'targets' => [['position' => 1, 'instruction' => 'Only one foot over the red line.']],
            ])
            ->assertOk();

        \App\Ai\Agents\PlanFrameWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '1. (to rewrite) now: Wrong zone: She stands in the red zone. Must show: Both feet are inside the red zone.')
            && str_contains((string) $prompt->agent->instructions(), "Rules the director set for this shot; the storyline and every keyframe follow them, never break one:\n- The visitor never crosses the red line with more than one foot."));
    });

    it('links the cast and sets the director ticks per keyframe, whatever the text calls them', function () {
        $engineer = \App\Models\Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $hall = \App\Models\Element::factory()->for($this->project)->place()->create(['name' => 'Indoor Assembly Hall']);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.plan', [$this->project, $shot]), [
                'storyline' => 'She drops the cigarette and goes in.',
                'framing' => ['size' => 'full'],
                'keyframes' => [
                    ['title' => 'At the sign', 'description' => 'The person looks at the sign.', 'elements' => [$engineer->sqid, $hall->sqid]],
                    ['title' => 'Empty', 'description' => 'Only the building, the female engineer has gone in.', 'elements' => [$hall->sqid]],
                ],
            ])
            ->assertSessionHasNoErrors();

        expect(array_column($shot->fresh()->storylineKeyframes(), 'elements'))->toBe([['Female engineer', 'Indoor Assembly Hall'], ['Indoor Assembly Hall']]);
    });

    it('saves the plan as the director wrote it, linking the cast it names, until the keyframes are drawn', function () {
        $engineer = \App\Models\Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['framing' => ['size' => 'full', 'spot' => 'At the gate.', 'light' => 'as the visual style', 'seconds' => 6], 'keyframes' => []]]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.plan', [$this->project, $shot]), [
                'storyline' => 'She drops the cigarette in the bin and walks in.',
                'framing' => ['size' => 'medium', 'spot' => 'At the yard gate, the bin by the post.', 'light' => '', 'seconds' => 8],
                'rules' => ['She never drops the cigarette on the ground.', ' '],
                'keyframes' => [
                    ['title' => 'At the bin', 'description' => 'The female engineer holds a cigarette next to the bin.', 'prompt' => 'The female engineer stands left of the bin, the cigarette in her right hand held away from her body.', 'mustShow' => 'The cigarette is in her right hand.'],
                    ['title' => 'Empty gate', 'description' => 'The gate without anyone.', 'prompt' => '', 'mustShow' => ''],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $shot->refresh();

        expect($shot->chosenStoryline()['storyline'])->toBe('She drops the cigarette in the bin and walks in.')
            ->and($shot->shotRules())->toBe(['She never drops the cigarette on the ground.'])
            ->and($shot->storylineKeyframes())->toEqual([
                ['title' => 'At the bin', 'description' => 'The female engineer holds a cigarette next to the bin.', 'prompt' => 'The female engineer stands left of the bin, the cigarette in her right hand held away from her body.', 'must_show' => 'The cigarette is in her right hand.', 'elements' => ['Female engineer']],
                ['title' => 'Empty gate', 'description' => 'The gate without anyone.', 'prompt' => 'The gate without anyone.', 'elements' => []],
            ])
            ->and($shot->storyline['framing'])->toEqual(['size' => 'medium', 'spot' => 'At the yard gate, the bin by the post.', 'light' => 'as the visual style', 'seconds' => 8]);

        \App\Models\Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.plan', [$this->project, $shot]), ['storyline' => 'x', 'framing' => ['size' => 'full'], 'keyframes' => [['title' => 'A', 'description' => 'B']]])
            ->assertSessionHasErrors('keyframes');
    });

    it('appends a new shot at the end of the sequence', function () {
        Shot::factory()->for($this->project)->create(['position' => 1]);

        $response = actingAs($this->director, 'director')->post(route('public.shots.store', $this->project), validShot());

        $shot = Shot::query()->where('takeaway', 'Sending is quick and final')->firstOrFail();

        $response->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->position)->toBe(2)
            ->and($shot->status)->toBe(ShotStatus::STORYLINE_PENDING)
            ->and($shot->title)->toBe('Sending is quick and final')
            ->and($shot->subject)->toBeNull()
            ->and($shot->action)->toBeNull();

        // The planner drafts the plan; nothing is drawn until the director has checked it.
        Queue::assertPushed(GenerateStoryline::class, fn(GenerateStoryline $job) => $job->shot->is($shot) && ! $job->draw);
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

    it('marks the shots in the list that are being generated', function () {
        $idle = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::VIDEO_READY]);
        Shot::factory()->for($this->project)->create(['position' => 2, 'status' => ShotStatus::VIDEO_PENDING]);
        $drawing = Shot::factory()->for($this->project)->create(['position' => 3, 'status' => ShotStatus::KEYFRAMES_READY]);
        Keyframe::factory()->for($drawing)->create(['position' => 1, 'rendering' => true]);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $idle]))
            ->assertInertia(fn($page) => $page
                ->where('siblings.0.busy', false)
                ->where('siblings.1.busy', true)
                ->where('siblings.2.busy', true));
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
            ->assertRedirect(route('public.shots.view', [$this->project, $third]));

        $this->assertModelMissing($second);
        expect($first->fresh()->position)->toBe(1)
            ->and($third->fresh()->position)->toBe(2);
    });

    it('stays in the editor on the shot before when the last one is deleted, or a new shot when none are left', function () {
        [$first, $second] = Shot::factory()->for($this->project)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy', [$this->project, $second]))
            ->assertRedirect(route('public.shots.view', [$this->project, $first]));

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy', [$this->project, $first]))
            ->assertRedirect(route('public.shots.create', $this->project));
    });
});
