<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Ai\Agents\StorylineWriter;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Shots\KeyframeStaging;

it('builds the description from the fields, with the spatial fact once', function () {
    $staging = KeyframeStaging::fromArray([
        'summary' => 'She films the vessel',
        'place' => 'A clear quay edge beside the moored naval vessel.',
        'cast' => [
            ['name' => 'Helmeted visitor', 'stands' => 'left', 'faces' => 'away', 'looks_at' => 'the naval vessel', 'holds' => 'the Smartphone', 'hand' => 'right', 'doing' => 'raises it to chest height, aimed at the vessel.'],
            ['name' => 'Damen host', 'stands' => 'right', 'faces' => 'left', 'looks_at' => 'the Helmeted visitor', 'holds' => '', 'hand' => 'right', 'doing' => ''],
        ],
    ]);
    $keyframe = (new Keyframe(['spatial' => 'The Smartphone points at the vessel.']))->restage($staging);

    expect($keyframe->fullDescription())->toBe(
        'She films the vessel. A clear quay edge beside the moored naval vessel. '
        . 'The Helmeted visitor stands on the left, has the back to the camera, looks at the naval vessel, holds the Smartphone in the right hand and raises it to chest height, aimed at the vessel. '
        . 'The Damen host stands on the right, is side-on facing left and looks at the Helmeted visitor. '
        . 'The Smartphone points at the vessel.',
    )
        ->and($keyframe->staged()->cast[1]['hand'])->toBe('');
});

it('keeps only known values and people with a name', function () {
    $staging = KeyframeStaging::fromArray(['summary' => ' Waits ', 'cast' => [['name' => 'Guard', 'stands' => 'behind', 'faces' => 'camera'], ['name' => ' '], 'not a person']]);

    expect($staging->summary)->toBe('Waits')
        ->and($staging->cast)->toBe([['name' => 'Guard', 'stands' => '', 'faces' => 'camera', 'looks_at' => '', 'holds' => '', 'hand' => '', 'doing' => '']])
        ->and($staging->describe())->toBe('Waits. The Guard faces the camera.');
});

it('changes one of the cast, adds a new one and leaves it as it is without a name', function () {
    $staging = new KeyframeStaging('Waits', '', [KeyframeStaging::member(['name' => 'Guard', 'stands' => 'left'])]);

    $turned = $staging->withMember(['name' => 'guard', 'faces' => 'right']);
    $added = $staging->withMember(['name' => 'Driver', 'stands' => 'right']);

    expect($turned->cast)->toHaveCount(1)
        ->and($turned->cast[0])->toMatchArray(['name' => 'guard', 'stands' => '', 'faces' => 'right'])
        ->and(array_column($added->cast, 'name'))->toBe(['Guard', 'Driver'])
        ->and($staging->withMember([]))->toBe($staging);
});

it('has the planners write the staging fields instead of a description', function () {
    $shot = Shot::factory()->create();
    $rules = 'Summary: what happens in this keyframe in one short plain line';

    expect((string) (new StorylineWriter($shot))->instructions())->toContain($rules)->not->toContain('- Description:')
        ->and((string) (new PlanDirector($shot, []))->instructions())->toContain($rules);
});

it('describes a turn three-quarters away from or toward the camera', function () {
    $staging = new KeyframeStaging('', '', [
        KeyframeStaging::member(['name' => 'Helmeted visitor', 'faces' => 'away-right', 'looks_at' => 'the naval vessel']),
        KeyframeStaging::member(['name' => 'Damen host', 'faces' => 'camera-left']),
    ]);

    expect($staging->describe())->toBe('The Helmeted visitor is turned three-quarters away from the camera, facing into the frame to the right and looks at the naval vessel. The Damen host is turned three-quarters toward the camera, facing the left of the frame.');
});

it('faces the body to what the hands work on and turns the head to what they look at', function () {
    expect(App\Ai\Briefs\StagingRules::rules())
        ->toContain('The body faces what their hands work on or where they go')
        ->toContain('the body turns with it only when it lies further round than a head can turn')
        ->not->toContain('The body turns toward what they look at');
});

it('puts something out of view on the side of the frame where it is in the place', function () {
    $shot = Shot::factory()->create();
    $rule = 'Something out of view is at the edge of the frame on the side where it is in the place';

    expect(App\Ai\Briefs\StagingRules::rules())->toContain($rule)
        ->and((string) (new StorylineWriter($shot))->instructions())->toContain($rule)
        ->and((string) (new PlanDirector($shot, []))->instructions())->toContain($rule)->toContain('it stays out of view, at the edge of the frame the staging rules give for it');
});

it('allows any natural pose, in the staging, an adjustment and the check', function () {
    $keyframe = Keyframe::factory()->create();

    expect(App\Ai\Briefs\StagingRules::rules())->toContain(App\Ai\Briefs\BodyRules::NATURAL_POSE)
        ->and((string) (new App\Ai\Agents\KeyframeChecker($keyframe, ['keyframe 1', 'the keyframe to check']))->instructions())
        ->toContain(App\Ai\Briefs\BodyRules::NATURAL_POSE)
        ->toContain('is a high issue in category person, named by who it is and what is unnatural')
        ->toContain('Allowed changes, never report these: a natural pose or gesture')
        ->and((string) (new App\Ai\Agents\TweakInterpreter($keyframe, []))->instructions())
        ->toContain(App\Ai\Briefs\BodyRules::NATURAL_POSE)
        ->not->toContain('the feet and stance unless the change needs them');
});

it('notes an unnatural pose in keyframe 1', function () {
    $reading = App\Support\Shots\KeyframeReading::fromArray(['cast' => [
        ['name' => 'Security guard', 'box' => ['top' => 100, 'left' => 100, 'bottom' => 900, 'right' => 300], 'facing' => 'side-on', 'pose' => 'looks back', 'placed_right' => true, 'looks_right' => true, 'pose_right' => false, 'problem' => ''],
    ]], ['Security guard']);

    expect($reading->problems())->toBe(['Security guard stands in an unnatural pose.']);
});
