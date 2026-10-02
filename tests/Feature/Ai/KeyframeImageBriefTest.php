<?php

declare(strict_types=1);

use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframeReferences;
use App\Enums\ShotStatus;
use App\Models\Element;
use App\Models\Project;
use App\Models\Shot;
use Laravel\Ai\Files\StoredImage;

/**
 * @param  array{size: string, spot: string, light?: string}|null  $framing
 */
function framedShot(?array $framing): Shot
{
    return Shot::factory()->for(Project::factory())->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'storyline' => array_filter([
            'framing' => $framing,
            'keyframes' => [['title' => 'Key handed over', 'description' => 'The guard hands over a key.', 'prompt' => 'The guard hands over a key.']],
        ]),
    ])->load('project');
}

it('frames the keyframe as the planner chose and places it at the spot', function () {
    $shot = framedShot(['size' => 'close-up', 'spot' => 'The reception counter, the key cabinet softly behind.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('Framing: Close-up at eye level')
        ->toContain('Spot: The reception counter, the key cabinet softly behind.')
        ->toContain('never put a separate wall, panel or backdrop in front of the place')
        ->not->toContain('from head to feet');
});

it('uses a full shot without a spot for plans made before framing existed', function () {
    $shot = framedShot(null);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('Framing: Full shot at eye level')
        ->not->toContain('Spot:');
});

it('uses the place reference for its look and not its viewpoint, unless the shot is wide', function (string $size, string $expected) {
    $shot = framedShot(['size' => $size, 'spot' => 'Against a hall facade.']);
    $halls = Element::factory()->for($shot->project)->place()->create(['name' => 'Blue Damen Halls']);

    $brief = KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(
        elements: [$halls],
        elementImages: [['element' => $halls, 'image' => new StoredImage('halls.png')]],
    ));

    expect($brief)
        ->toContain('The first attached image shows what Blue Damen Halls looks like')
        ->toContain('Use it for the look of the place, not for the viewpoint')
        ->toContain($expected);
})->with([
    'medium' => ['medium', 'the camera stands inside the place at the spot described, at eye level'],
    'wide' => ['wide', 'a similar overview fits this wide shot'],
]);

it('keeps the framing when the planned keyframes change', function () {
    $shot = framedShot(['size' => 'medium', 'spot' => 'Against a hall facade.']);

    $shot->replacePlannedKeyframes([...$shot->storylineKeyframes(), ['title' => 'Nod', 'description' => 'He nods.', 'prompt' => 'He nods.']]);

    expect($shot->fresh()->storylineFraming()['spot'])->toBe('Against a hall facade.')
        ->and($shot->fresh()->storylineKeyframes())->toHaveCount(2);
});

it('puts the action in the centre and keeps the place\'s own logos and signs', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('Composition: the action is the subject.')
        ->toContain('together in the centre of the frame, large and clear')
        ->toContain('Keep the background simple and subdued')
        ->toContain('vehicles, containers and machines stand on open ground at their real size, never on or against a wall')
        ->toContain('Logos, signs and markings that belong to the place stay exactly as they are.')
        ->not->toContain('No text, captions, logos or watermarks');
});

it('keeps every fixed part of the place in later keyframes', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(first: new StoredImage('first.png'))))
        ->toContain('such as logos, signs, doors, windows and parked vehicles, stays in the same position and looks the same; nothing appears or disappears');
});

it('describes elements without a picture in words', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);
    $crate = Element::factory()->for($shot->project)->create(['type' => 'object', 'name' => 'Spare parts crate', 'description' => 'A blue wooden crate.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(elements: [$crate])))
        ->toContain('- Spare parts crate (object): A blue wooden crate.');
});

it('lets the light of the story override the style', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door.', 'light' => 'dusk, low warm evening light, torch on']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('Light: dusk, low warm evening light, torch on. This overrides the lighting in the visual style.');
});

it('keeps the style\'s light when the story names none', function (?string $light) {
    $shot = framedShot(array_filter(['size' => 'full', 'spot' => 'At the side door.', 'light' => $light]));

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->not->toContain('Light:');
})->with([
    'as the style' => ['as the visual style'],
    'missing' => [null],
]);
