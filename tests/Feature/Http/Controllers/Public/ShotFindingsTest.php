<?php

declare(strict_types=1);

use App\Ai\Agents\FindingsReporter;
use App\Ai\Agents\PlanDirector;
use App\Ai\Agents\ShotReviewer;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\ReviewShot;
use App\Jobs\TweakKeyframeImage;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\ReviewerVerdict;
use App\Models\Shot;
use App\Support\Decisions\FindingsReport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A drawn shot of three keyframes where the check found something in keyframe 2 and the review a note on keyframe 3.
 */
function shotWithFindings(Project $project): Shot
{
    $shot = Shot::factory()->for($project)->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'plan_chat' => [['role' => 'assistant', 'text' => 'The plan is written.']],
        'storyline' => ['keyframes' => [
            ['title' => 'At the line', 'description' => 'She waits.'],
            ['title' => 'Beside it', 'description' => 'She stands beside the line.'],
            ['title' => 'Away', 'description' => 'She walks away.'],
        ]],
        'keyframe_review' => ['clear' => false, 'notes' => [['text' => 'She is too small to read on a phone.', 'keyframes' => [3]]]],
    ]);

    foreach ([1, 2, 3] as $position) {
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => $position]);
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        $render = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName("k{$position}.png")
            ->withCustomProperties($position === 2 ? [Keyframe::CHECK_ISSUES => ['She stands on the yellow line instead of beside it.']] : [])
            ->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();
    }

    return $shot->load('project');
}

it('tells the director in the chat what the checks and the review found, once', function () {
    $shot = shotWithFindings($this->project);
    FindingsReporter::fake(fn() => ['intro' => 'I looked at the new keyframes and noticed two things.', 'items' => [
        ['sources' => [1], 'note' => 'She is too small to read on a phone.'],
        ['sources' => [2], 'note' => 'She stands on the yellow line instead of beside it.'],
    ]]);

    app(FindingsReport::class)->report($shot);
    app(FindingsReport::class)->report($shot->fresh()->load('project'));

    $chat = $shot->fresh()->plan_chat;

    expect($chat)->toHaveCount(2)
        ->and($chat[1]['text'])->toBe("I looked at the new keyframes and noticed two things.\n1. Keyframe 3: She is too small to read on a phone.\n2. Keyframe 2: She stands on the yellow line instead of beside it.\nShall I fix them? Say which ones, or that they are fine.")
        ->and(collect($chat[1]['findings'])->pluck('keyframes')->all())->toBe([[3], [2]]);
});

it('fixes the findings the director agrees with in the chat and leaves the others, keeping the verdicts', function () {
    $shot = shotWithFindings($this->project);
    FindingsReporter::fake(fn() => ['intro' => 'Two things.', 'items' => [
        ['sources' => [1], 'note' => 'She is too small.'],
        ['sources' => [2], 'note' => 'She stands on the line.'],
    ]]);
    app(FindingsReport::class)->report($shot);
    Bus::fake();
    PlanDirector::fake([[
        'reply' => 'I fix 2 and leave 1.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [], 'remove_shots' => [],
        'changes' => [], 'choice' => ['action' => 'none', 'option' => 0], 'proposal' => null,
        'verdicts' => [['finding' => 2, 'verdict' => 'fix'], ['finding' => 1, 'verdict' => 'ignore']],
    ]]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'fix 2, 1 is fine'])
        ->assertOk()
        ->assertJsonPath('messages.3.changes', ['Fixing keyframe 2', 'Left as it is: finding 1']);

    PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "Findings waiting for an answer:\n1. Keyframe 3: She is too small.\n2. Keyframe 2: She stands on the line."));
    Bus::assertDispatched(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->position === 2 && $job->fromCheck && str_contains($job->instruction, 'She stands on the yellow line instead of beside it.'));

    $shot->refresh();

    expect(collect($shot->plan_chat[1]['findings'])->pluck('verdict', 'n')->all())->toBe([1 => 'ignore', 2 => 'fix'])
        ->and(ReviewerVerdict::query()->orderBy('reviewer')->get(['reviewer', 'keyframe', 'verdict', 'via'])->toArray())->toBe([
            ['reviewer' => 'check', 'keyframe' => 2, 'verdict' => 'fixed', 'via' => 'chat'],
            ['reviewer' => 'review', 'keyframe' => 3, 'verdict' => 'dismissed', 'via' => 'chat'],
        ])
        ->and(app(FindingsReport::class)->waiting($shot, $shot->keyframes()->with('media')->get()))->toBeNull();
});

it('keeps the verdict when a finding is fixed or dismissed in the list of decisions', function () {
    $shot = shotWithFindings($this->project);
    Bus::fake();

    actingAs($this->director, 'director')->post(route('public.shots.issues.dismiss', [$this->project, $shot, 'keyframe-3']));
    actingAs($this->director, 'director')->post(route('public.shots.issues.fix', [$this->project, $shot, 'keyframe-2']));

    expect(ReviewerVerdict::query()->orderBy('id')->get(['reviewer', 'verdict', 'via'])->toArray())->toBe([
        ['reviewer' => 'review', 'verdict' => 'dismissed', 'via' => 'decisions'],
        ['reviewer' => 'check', 'verdict' => 'fixed', 'via' => 'decisions'],
    ]);
});

it('waits with the review until the keyframe checks are done', function () {
    Config::set('pipeline.keyframe_check', true);
    $shot = shotWithFindings($this->project);
    $shot->keyframes()->where('position', 3)->update(['render_stage' => Keyframe::STAGE_CHECKING]);
    Bus::fake([ReviewShot::class]);
    ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

    (new ReviewShot($shot))->handle(app(KeyframePainter::class));

    Bus::assertDispatched(ReviewShot::class, fn(ReviewShot $job) => $job->waited === 1);
    ShotReviewer::assertNeverPrompted();
});
