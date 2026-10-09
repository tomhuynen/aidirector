<?php

declare(strict_types=1);

use App\Ai\Agents\FindingsReporter;
use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Agents\TweakInterpreter;
use App\Ai\Agents\VideoPromptWriter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\ProjectRuleStatus;
use App\Enums\ShotKind;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->project->rules()->create(['category' => 'distance to hazard', 'text' => 'People stand within two steps of the hazard.', 'status' => ProjectRuleStatus::ACTIVE]);
});

it('leaves out what the visual style does not fill in', function () {
    $this->project->forceFill(['style' => ['look' => 'Soft 3D', 'medium' => '', 'mood' => 'calm.', 'palette' => null]])->save();

    expect($this->project->styleBrief())->toBe('Visual style: Soft 3D. Mood: calm.')
        ->and(tap($this->project)->forceFill(['style' => []])->styleBrief())->toBe('');
});

it('gives the learned project rules to the tweaks, the shot review and the video prompt', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');
    $keyframe = Keyframe::factory()->for($shot)->create()->setRelation('shot', $shot);

    expect((string) (new TweakInterpreter($keyframe, []))->instructions())->toContain('People stand within two steps of the hazard.')
        ->and((new ShotReviewer($shot, collect([$keyframe])))->promptFor())->toContain('People stand within two steps of the hazard.')
        ->and((new VideoPromptWriter($shot))->promptFor([$keyframe], 4))->toContain('People stand within two steps of the hazard.');
});

it('does not ask the review of a close-up for faces or whole people', function () {
    $shot = Shot::factory()->for($this->project)->create(['kind' => ShotKind::CLOSE_UP])->load('project');

    expect((new ShotReviewer($shot, collect()))->promptFor())->toContain('never ask for faces, whole people or a wider view');
});

it('keeps left and right and the planned spot in the options of a place and of keyframe 1', function () {
    expect(collect(range(0, 2))->map(fn(int $index) => KeyframeImageBrief::plateVariation($index))->join(' '))
        ->not->toContain('what stood left now stands right')
        ->toContain('what the plan puts on the left of the frame stays on the left')
        ->and(collect(range(0, 2))->map(fn(int $index) => KeyframeImageBrief::variation($index))->join(' '))
        ->not->toContain('different calm part of the same place for the spot');
});

it('has the checks and the chat that reports them name hands by the side of the frame', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');
    $keyframe = Keyframe::factory()->for($shot)->create()->setRelation('shot', $shot);

    expect((string) (new ShotReviewer($shot, collect([$keyframe])))->instructions())->toContain('never as "his left hand" or "the left-side hand"')
        ->and((string) (new KeyframeChecker($keyframe, []))->instructions())->toContain('never as "his left hand" or "the left-side hand"')
        ->and((string) (new FindingsReporter([]))->instructions())->toContain('never shorten it to "the left hand" or "the left-side hand"');
});

it('does not ask for the kind again when the agreed idea already named it', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('When the idea the director agreed to already named the kind')
        ->toContain('never ask it again, go straight on to the keyframes in the same answer');
});

it('has the plan director check its own idea for a change of place before pitching it', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('check your own idea against the rules below, first of all that it plays in one place')
        ->toContain('taking something from where it is found to where it is handed in');
});

it('has the plan director propose a close-up itself when the takeaway turns on a small object', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('Before you propose a kind, check the takeaway against the rules below yourself')
        ->toContain('never wait for the director to ask whether it can be seen');
});
