<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\ProjectRuleStatus;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateStoryline;
use App\Jobs\TweakKeyframeImage;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Decisions\DecisionQueue;
use App\Support\Decisions\ShotIssues;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * @return list<array{title: string, storyline: string}>
 */
function decisionStorylines(): array
{
    return [
        ['title' => 'Calm', 'storyline' => 'A welder checks the sign before lighting up and walks on.'],
        ['title' => 'Hesitation', 'storyline' => 'A worker reaches for a cigarette, sees the sign and puts it away.'],
    ];
}

it('queues what waits for the director, oldest first', function () {
    $render = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::KEYFRAMES_READY, 'updated_at' => now()->subMinutes(5)]);
    Keyframe::factory()->for($render)->create();
    $storyline = Shot::factory()->for($this->project)->create([
        'position' => 2,
        'status' => ShotStatus::OPTIONS_READY,
        'storyline_options' => decisionStorylines(),
        'updated_at' => now()->subMinutes(10),
    ]);
    $failed = Shot::factory()->for($this->project)->create(['position' => 3, 'voice_over' => 'Never smoke here.', 'status' => ShotStatus::DRAFT, 'storyline_error' => 'The storyteller timed out.', 'updated_at' => now()->subMinute()]);
    Shot::factory()->for($this->project)->create(['position' => 4, 'status' => ShotStatus::STORYLINE_PENDING]);
    $rule = $this->project->rules()->create(['category' => 'distance', 'text' => 'People stand close to the hazard.', 'status' => ProjectRuleStatus::SUGGESTED]);
    $rule->forceFill(['created_at' => now()->subMinutes(3)])->save();
    $this->project->rules()->create(['category' => 'x', 'text' => 'Kept', 'status' => ProjectRuleStatus::ACTIVE]);

    $decisions = app(DecisionQueue::class)->for($this->project);

    expect($decisions->pluck('id')->all())->toBe([
        "storyline-{$storyline->sqid}",
        "render-{$render->sqid}",
        "rule-{$rule->sqid}",
        "attention-{$failed->sqid}",
    ])
        ->and($decisions[0]['options'])->toEqual(decisionStorylines())
        ->and($decisions[1]['keyframes'])->toHaveCount(1)
        ->and($decisions[1]['renderUrl'])->toBe(route('public.shots.video.generate', [$this->project, $render]))
        ->and($decisions[2]['rule']['text'])->toBe('People stand close to the hazard.')
        ->and($decisions[3]['message'])->toBe('The storyteller timed out.')
        ->and($decisions[3]['shot']['voiceOver'])->toBe('Never smoke here.');
});

it('asks to render only once the keyframes are reviewed together', function () {
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'reviewing' => true]);
    Keyframe::factory()->for($shot)->create();

    expect(app(DecisionQueue::class)->count($this->project))->toBe(0);

    $shot->update(['reviewing' => false]);

    expect(app(DecisionQueue::class)->for($this->project)->first()['type'])->toBe(DecisionQueue::RENDER);
});

it('asks to draw a shot that is planned but not drawn yet', function () {
    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::STORYLINE_READY,
        'chosen_storyline' => ['title' => 'Gate Bin', 'storyline' => 'She drops the cigarette in the bin.'],
        'storyline' => ['keyframes' => [['title' => 'At the gate', 'description' => 'She holds a cigarette.'], ['title' => 'In the bin', 'description' => 'The cigarette lies in the bin.']]],
    ]);

    $decision = app(DecisionQueue::class)->for($this->project)->first();

    expect($decision)
        ->type->toBe(DecisionQueue::PLAN)
        ->plan->toBe([['title' => 'At the gate', 'description' => 'She holds a cigarette.'], ['title' => 'In the bin', 'description' => 'The cigarette lies in the bin.']])
        ->drawUrl->toBe(route('public.shots.keyframes.generate', [$this->project, $shot]))
        ->and($decision['shot']['storyline'])->toBe('She drops the cigarette in the bin.');

    actingAs($this->director, 'director')
        ->post($decision['drawUrl'], ['return' => 'decisions'])
        ->assertRedirect(route('public.projects.decisions', $this->project));

    expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING);
    Queue::assertPushed(GenerateKeyframes::class);
});

it('leaves out shots whose keyframes are still being drawn', function () {
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
    Keyframe::factory()->for($shot)->create(['rendering' => true]);

    expect(app(DecisionQueue::class)->count($this->project))->toBe(0);
});

it('shows the decisions page and counts the decisions on the project and in the editor', function () {
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::OPTIONS_READY, 'storyline_options' => decisionStorylines()]);

    actingAs($this->director, 'director')
        ->get(route('public.projects.decisions', $this->project))
        ->assertSuccessful()
        ->assertInertia(fn($page) => $page
            ->component('projects/decisions')
            ->has('decisions', 1)
            ->where('decisions.0.type', DecisionQueue::STORYLINE)
            ->where('decisions.0.chooseUrl', route('public.shots.storyline.choose', [$this->project, $shot])));

    actingAs($this->director, 'director')
        ->get(route('public.projects.view', $this->project))
        ->assertInertia(fn($page) => $page
            ->where('decisionsCount', 1)
            ->where('project.links.decisions', route('public.projects.decisions', $this->project)));

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('decisionsCount', 1));
});

it('returns to the decisions after a choice made there', function () {
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::OPTIONS_READY, 'storyline_options' => decisionStorylines()]);

    actingAs($this->director, 'director')
        ->post(route('public.shots.storyline.choose', [$this->project, $shot]), ['option' => 0, 'return' => 'decisions'])
        ->assertRedirect(route('public.projects.decisions', $this->project));

    expect($shot->fresh()->status)->toBe(ShotStatus::STORYLINE_PENDING);
});

it('explains running out of credits instead of the provider reply', function () {
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
    Keyframe::factory()->for($shot)->create(['position' => 2, 'render_error' => 'HTTP request returned status code 402: {"error":{"message":"Insufficient credits."}}']);

    expect(app(DecisionQueue::class)->for($this->project)->first())
        ->type->toBe(DecisionQueue::ATTENTION)
        ->message->toContain('out of credits')
        ->retryUrl->toBe(route('public.shots.retry', [$this->project, $shot]));
});

describe('retry', function () {
    it('draws the failed keyframes again and returns to the decisions', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
        $failed = Keyframe::factory()->for($shot)->create(['position' => 2, 'render_error' => 'Gateway error']);
        $fine = Keyframe::factory()->for($shot)->create(['position' => 3]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.retry', [$this->project, $shot]), ['return' => 'decisions'])
            ->assertRedirect(route('public.projects.decisions', $this->project));

        expect($failed->fresh())->rendering->toBeTrue()->render_error->toBeNull();
        Queue::assertPushed(GenerateKeyframeImage::class, 1);
        Queue::assertPushed(GenerateKeyframeImage::class, fn(GenerateKeyframeImage $job) => $job->keyframe->is($failed));
        expect($fine->fresh()->rendering)->toBeFalse();
    });

    it('draws the options for keyframe 1 again when none could be drawn', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY]);
        Keyframe::factory()->for($shot)->create(['position' => 1, 'render_error' => 'No option could be drawn.']);

        actingAs($this->director, 'director')->post(route('public.shots.retry', [$this->project, $shot]));

        expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING);
        Queue::assertPushed(GenerateKeyframes::class);
    });

    it('plans the shot again when that failed', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::DRAFT, 'storyline_error' => 'Timed out']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.retry', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->fresh())->status->toBe(ShotStatus::STORYLINE_PENDING)->storyline_error->toBeNull();
        Queue::assertPushed(GenerateStoryline::class);
    });

    it('refuses when nothing failed', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.retry', [$this->project, $shot]))
            ->assertSessionHasErrors('retry');

        Queue::assertNothingPushed();
    });
});

/**
 * A shot ready to render whose four keyframes are drawn, with the given review notes.
 *
 * @param  list<string>  $notes
 */
function reviewedShot(Project $project, array $notes): Shot
{
    Storage::fake(Disk::TENANT->value);

    $shot = Shot::factory()->for($project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'keyframe_review' => ['clear' => $notes === [], 'notes' => $notes]]);

    foreach (range(1, 4) as $position) {
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        Keyframe::factory()->for($shot)->create(['position' => $position])
            ->addMediaFromString((string) ob_get_clean())->usingFileName("k{$position}.png")->toMediaCollection(Keyframe::RENDERS);
    }

    return $shot;
}

describe('issues', function () {
    it('groups the issues by the keyframes they name', function () {
        $shot = reviewedShot($this->project, [
            'In keyframes 1 through 3 a padlock already hangs on the panel.',
            'In keyframes 2 and 4 she still stands on the line.',
            'The point of the shot is unclear.',
        ]);
        $shot->keyframes()->where('position', 2)->first()->render()->setCustomProperty(Keyframe::CHECK_WARNING, 'The hand points at the valve')->save();

        $groups = app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get());

        // Keyframe 1 is what the others match, so a note naming it with others changes the others.
        expect(array_column($groups, 'key'))->toBe(['keyframe-2', 'keyframe-3', 'keyframe-4', 'shot'])
            ->and($groups[0]['issues'])->toBe([
                'In keyframes 1 through 3 a padlock already hangs on the panel.',
                'In keyframes 2 and 4 she still stands on the line.',
                'The check could not see that the hand points at the valve',
            ])
            ->and(array_column($groups, 'fixable'))->toBe([true, true, true, false]);
    });

    it('groups notes by the keyframes the reviewer gives and shows them per keyframe in the editor', function () {
        $shot = reviewedShot($this->project, []);
        $shot->update(['keyframe_review' => ['clear' => false, 'notes' => [
            ['text' => 'She is not there yet, so noticing the sign cannot be seen.', 'keyframes' => [1]],
            ['text' => 'The floor line runs at another angle than in keyframe 1.', 'keyframes' => [3, 9]],
            ['text' => 'The point of the shot is unclear.', 'keyframes' => []],
        ]]]);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->has('shot.issueGroups', 3)
                ->where('shot.issueGroups.0.position', 1)
                ->where('shot.issueGroups.0.issues', ['She is not there yet, so noticing the sign cannot be seen.'])
                ->where('shot.issueGroups.0.fixUrl', route('public.shots.issues.fix', [$this->project, $shot, 'keyframe-1']))
                ->where('shot.issueGroups.1.position', 3)
                ->where('shot.issueGroups.1.issues', ['The floor line runs at another angle than in keyframe 1.'])
                ->where('shot.issueGroups.2.position', null)
                ->where('shot.issueGroups.2.fixUrl', null)
                ->where('shot.issueGroups.2.dismissUrl', route('public.shots.issues.dismiss', [$this->project, $shot, 'shot'])));
    });

    it('changes the other keyframes, not keyframe 1, when the reviewer names keyframe 1 with others', function () {
        $shot = reviewedShot($this->project, []);
        $shot->update(['keyframe_review' => ['clear' => false, 'notes' => [
            ['text' => 'The floor line runs at another angle.', 'keyframes' => [1, 3]],
        ]]]);

        $groups = app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get());

        expect(array_column($groups, 'position'))->toBe([3]);
    });

    it('redraws one keyframe with all its issues and keeps a shared note for the other keyframes', function () {
        $shot = reviewedShot($this->project, ['In keyframes 2 and 3 the padlock already hangs.']);
        $shot->keyframes()->where('position', 2)->first()->render()->setCustomProperty(Keyframe::CHECK_WARNING, 'The hand points at the valve')->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix', [$this->project, $shot, 'keyframe-2']), ['return' => 'decisions'])
            ->assertRedirect(route('public.projects.decisions', $this->project));

        Queue::assertPushed(TweakKeyframeImage::class, 1);
        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->position === 2
            && $job->fromCheck
            && str_contains($job->instruction, 'the padlock already hangs')
            && str_contains($job->instruction, 'The hand points at the valve'));

        $groups = app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get());

        expect(array_column($groups, 'key'))->toBe(['keyframe-3']);
    });

    it('dismisses the issues of a keyframe or of the whole shot without redrawing anything', function () {
        $shot = reviewedShot($this->project, ['In keyframe 4 the bulge is subtle.', 'The point is unclear.']);
        $shot->update(['video_error' => 'The video model failed.']);

        actingAs($this->director, 'director')->post(route('public.shots.issues.dismiss', [$this->project, $shot, 'keyframe-4']));
        actingAs($this->director, 'director')->post(route('public.shots.issues.dismiss', [$this->project, $shot, 'shot']));

        Queue::assertNotPushed(TweakKeyframeImage::class);
        expect($shot->fresh()->video_error)->toBeNull()
            ->and(app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get()))->toBe([]);
    });

    it('fixes every keyframe at once and leaves the issues of the whole shot', function () {
        $shot = reviewedShot($this->project, [
            'In keyframes 1 and 2 she looks almost the same.',
            'In keyframes 4 and 5 the helmet gains the word DAMEN.',
            'The point is unclear.',
        ]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix-all', [$this->project, $shot]), ['return' => 'decisions'])
            ->assertRedirect(route('public.projects.decisions', $this->project));

        Queue::assertPushed(TweakKeyframeImage::class, 2);
        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->position === 2);
        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->position === 4);
        expect(array_column(app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get()), 'key'))->toBe(['shot']);
        // The editor shows only what is left.
        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.keyframeReview', ['clear' => false, 'notes' => ['The point is unclear.']]));
    });

    it('offers Fix all on the shot and in the decision only when a keyframe can be redrawn', function () {
        $shot = reviewedShot($this->project, ['In keyframe 3 the bin is gone.']);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.fixableIssues', true)->where('shot.links.issuesFixAll', route('public.shots.issues.fix-all', [$this->project, $shot])));

        expect(app(DecisionQueue::class)->for($this->project)->first()['fixAllUrl'])->toBe(route('public.shots.issues.fix-all', [$this->project, $shot]));

        $shot->update(['keyframe_review' => ['clear' => false, 'notes' => ['The point is unclear.']]]);

        expect(app(DecisionQueue::class)->for($this->project)->first()['fixAllUrl'])->toBeNull();
        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix-all', [$this->project, $shot]))
            ->assertSessionHasErrors('issue');
    });

    it('dismisses every issue at once without redrawing anything', function () {
        $shot = reviewedShot($this->project, ['In keyframe 3 the bin is gone.', 'The point is unclear.']);
        $shot->keyframes()->where('position', 2)->first()->render()->setCustomProperty(Keyframe::CHECK_WARNING, 'The hand points at the valve')->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.dismiss-all', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        Queue::assertNotPushed(TweakKeyframeImage::class);
        expect(app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get()))->toBe([]);
        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.keyframeReview', ['clear' => true, 'notes' => []]));
    });

    it('offers Fix all after the video is rendered too, so the video can be made again', function () {
        $shot = reviewedShot($this->project, ['In keyframe 3 the floor line runs at another angle than in keyframe 1.']);
        $shot->update(['status' => ShotStatus::VIDEO_READY]);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.fixableIssues', true));

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix-all', [$this->project, $shot]))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->position === 3);
    });

    it('refuses issues that are gone', function () {
        $shot = reviewedShot($this->project, []);

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix', [$this->project, $shot, 'keyframe-1']))
            ->assertSessionHasErrors('issue');
    });
});

it('forbids another director\'s decisions', function () {
    $other = Project::factory()->create();

    actingAs($this->director, 'director')
        ->get(route('public.projects.decisions', $other))
        ->assertForbidden();
});
