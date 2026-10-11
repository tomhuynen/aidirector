<?php

declare(strict_types=1);

use App\Ai\Agents\FindingsReporter;
use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\PlanDirector;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Agents\TweakInterpreter;
use App\Ai\Agents\VideoPromptWriter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\ProjectRuleStatus;
use App\Enums\ShotKind;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\FirstKeyframeChoice;

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
        ->toContain('Check your own idea in rules_check before you pitch it')
        ->toContain('taking something from where it is found to where it is handed in');
});

it('has the plan director propose a close-up itself when the takeaway turns on a small object', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('Check the kind in rules_check before you propose it')
        ->toContain('never wait for the director to ask whether it can be seen');
});

it('has the plan director check its answer against the numbered rules before it writes the reply', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');
    $director = new App\Ai\Agents\PlanDirector($shot, []);

    expect(array_key_first($director->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory())))->toBe('rules_check')
        ->and(App\Ai\Briefs\VideoLimitsBrief::brief())->toStartWith('1. The place changes within the shot')
        ->and((string) $director->instructions())->toContain('Fill rules_check first, in every answer')
        ->toContain('When rules is not empty, the reply never proposes it as it is');
});

it('has the plan director keep the story when it proposes the keyframes or works around a rule', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');
    $director = new App\Ai\Agents\PlanDirector($shot, []);

    expect($director->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory())['rules_check']->toArray()['properties'])->toHaveKey('story')
        ->and((string) $director->instructions())
        ->toContain('who does what and how they feel')
        ->toContain('leave out one that only repeats the one before')
        ->toContain('Never solve a rule by dropping what makes the story')
        ->toContain('When the story has no recognisable moment or turn, or a keyframe changes nothing, rework it before the reply.');
});

it('has the shot review catch someone who would walk through a person between two keyframes', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new ShotReviewer($shot, collect()))->instructions())->toContain('Nobody passes through someone else');
});

it('has the plan director go on to the next step in the same answer once the director agrees', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('until the plan is written, every answer ends with the next question or proposal')
        ->toContain('its kind is the one in the agreed shots, so never ask it again, go straight on to its keyframes')
        ->not->toContain('Then go on with this shot only, from step 3');
});

it('never lets a check or a tweak move the camera of one keyframe in a scene', function () {
    $scene = Shot::factory()->for($this->project)->create(['kind' => ShotKind::SCENE])->load('project');
    $montage = Shot::factory()->for($this->project)->create(['kind' => ShotKind::MONTAGE])->load('project');
    $keyframe = Keyframe::factory()->for($scene)->create()->setRelation('shot', $scene);
    $still = Keyframe::factory()->for($montage)->create()->setRelation('shot', $montage);

    expect((string) (new App\Ai\Agents\KeyframeChecker($keyframe, [], placeIsFixed: true))->instructions())->toContain('never ask for a closer or wider view, a crop')
        ->and((string) (new App\Ai\Agents\KeyframeChecker($keyframe, []))->instructions())->toContain('never ask for a closer or wider view, a crop')
        ->and((string) (new TweakInterpreter($keyframe, []))->instructions())->toContain('The camera never moves in this shot')
        ->and((string) (new TweakInterpreter($still, []))->instructions())->not->toContain('The camera never moves in this shot');
});

it('has the plan director pitch a short sequence of shots on its own and translate film language instead of dropping it', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');
    $director = new App\Ai\Agents\PlanDirector($shot, []);

    expect((string) $director->instructions())
        ->toContain('is told as a short sequence of up to five shots')
        ->toContain('Pitch such a sequence on your own at the idea step')
        ->toContain('a camera that tracks or pushes in becomes a static shot where the person moves')
        ->and($director->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory())['shots']->toArray()['maxItems'])->toBe(5);
});

it('has the plan director carry a change for the whole sequence to its other shots right away', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('it holds for the whole sequence: give the shots again in that same answer')
        ->toContain('Never ask whether to change the other shots too');
});

it('has the plan director say from the start that a shot waits for the shot it continues from', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())->toContain("When this shot's setting is not there yet, such as the keyframes of the shot it continues from, say so in your first answer");
});

it('has the plan say which objects someone handles in each keyframe', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\StorylineWriter($shot))->instructions())->toContain('- Handled: the objects of the cast and sets, by their exact names, that someone changes in this keyframe')
        ->and(App\Support\Shots\ShotPlan::apply($shot, ['kind' => 'scene', 'storyline' => 'She walks past.', 'seconds' => 4, 'keyframes' => [['title' => 'Past', 'description' => 'She walks past the bin.', 'handled' => ['waste BIN', 'Unknown thing'], 'elements' => []]]]))->not->toBeNull()
        ->and($shot->fresh()->storylineKeyframes()[0]['handled'])->toBe([]);
});

it('has the plan director offer a new place when the director wants another kind of place, not more options', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('so more options never give another kind of place')
        ->toContain('offer to make a new place in new_elements, type place')
        ->toContain('give the plan again in the proposal with the new place in the keyframes\' elements instead of the old one');
});

it('has the plan director prefer side views over a camera that looks straight back', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())->toContain('Prefer views from the side or at an angle over a camera that looks straight back');
});

it('has a tweak keep someone who backs away facing what they back away from', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');
    $keyframe = Keyframe::factory()->for($shot)->create()->setRelation('shot', $shot);

    expect((string) (new TweakInterpreter($keyframe, []))->instructions())
        ->toContain('When someone backs away from something, they still face it: body, face and feet point toward it');
});

it('has the plan director draw the others again when they failed, not keyframe 1', function () {
    $shot = Shot::factory()->for($this->project)->create(['storyline_error' => 'The other keyframes could not be rendered. Please try again.'])->load('project');
    Keyframe::factory()->for($shot)->count(3)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $choosing = ['step' => FirstKeyframeChoice::CONFIRM, 'count' => 1];

    expect((new PlanDirector($shot, [], choosing: $choosing))->promptFor('keyframe 3 try again'))
        ->toContain('so keyframe 2 and 3 are not drawn. Trying again, drawing the others, or retrying any later keyframe')
        ->and((new PlanDirector($shot->fresh()->forceFill(['storyline_error' => null])->load('project'), [], choosing: $choosing))->promptFor('ok'))
        ->not->toContain('Drawing the others stopped with an error');
});

it('has the planner show where a person comes in, and the review catch someone who appears out of nothing', function () {
    $shot = Shot::factory()->for($this->project)->create()->load('project');

    expect((string) (new App\Ai\Agents\StorylineWriter($shot))->instructions())->toContain('- Entrances: a person who is not in keyframe 1 is seen coming in')
        ->and((string) (new ShotReviewer($shot, collect()))->instructions())->toContain('Nobody appears out of nothing');
});
