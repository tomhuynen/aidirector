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

it('frames a full shot, whatever an older plan chose, and places it at the spot', function () {
    $shot = framedShot(['size' => 'close-up', 'spot' => 'The reception counter, the key cabinet softly behind.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('Framing: Full shot at eye level')
        ->toContain('Spot: The reception counter, the key cabinet softly behind.')
        ->toContain('never put a separate wall, panel or backdrop in front of the place')
        ->not->toContain('Close-up');
});

it('uses a full shot without a spot for plans made before framing existed', function () {
    $shot = framedShot(null);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('Framing: Full shot at eye level')
        ->not->toContain('Spot:');
});

it('uses the place reference for its look and not its viewpoint', function () {
    $shot = framedShot(['spot' => 'Against a hall facade.']);
    $halls = Element::factory()->for($shot->project)->place()->create(['name' => 'Blue Damen Halls']);

    $brief = KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(
        elements: [$halls],
        elementImages: [['element' => $halls, 'image' => new StoredImage('halls.png')]],
    ));

    expect($brief)
        ->toContain('The first attached image shows what Blue Damen Halls looks like')
        ->toContain('Use it for the look of the place, not for the viewpoint')
        ->toContain('the camera stands inside the place at the spot described, at eye level');
});

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

it('draws later keyframes as an edit of keyframe 1, so every fixed part of the place stays', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(first: new StoredImage('first.png'))))
        ->toStartWith('Edit the first attached image. It is keyframe 1 of this shot')
        ->toContain('The place itself stays exactly as it is in the first image')
        ->toContain('The state of things follows the story: open or closed')
        ->toContain('a handset lifted off its hook leaves the cradle empty')
        ->toContain('never add a second copy of anyone')
        ->toContain('Logos and lettering on clothing and helmets stay exactly as in the pictures of the people.')
        ->not->toContain('Framing:')
        ->not->toContain('Visual style:');
});

it('describes elements without a picture in words', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);
    $crate = Element::factory()->for($shot->project)->create(['type' => 'object', 'name' => 'Spare parts crate', 'description' => 'A blue wooden crate.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(elements: [$crate])))
        ->toContain('- Spare parts crate (object): A blue wooden crate.');
});

it('always keeps the style\'s light, also when an older plan named one', function (?string $light) {
    $shot = framedShot(array_filter(['size' => 'full', 'spot' => 'At the side door.', 'light' => $light]));

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->not->toContain('Light:');
})->with([
    'as the style' => ['as the visual style'],
    'named by an older plan' => ['dusk, low warm evening light, torch on'],
    'missing' => [null],
]);

it('adds the people that keyframe 1 does not show yet', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(first: new StoredImage('first.png'), firstShowsCast: false, castNames: ['Female engineer'])))
        ->toContain('Female engineer is not in the first image yet: add them into it')
        ->not->toContain('never add a second copy of anyone');
});

it('takes the people from their pictures and the state of things from the keyframe before', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);
    $engineer = Element::factory()->for($shot->project)->create(['type' => 'person', 'name' => 'Female engineer']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(
        elementImages: [['element' => $engineer, 'image' => new StoredImage('engineer.png')]],
        first: new StoredImage('first.png'),
        previous: new StoredImage('previous.png'),
    )))
        ->toContain('The second attached image is the picture of Female engineer (person). Draw Female engineer exactly like it')
        ->toContain('The third attached image is the keyframe directly before this one. Carry over the state and position of every object from it');
});

it('never lets the image model write labels on cards, badges or permits', function () {
    expect(KeyframeImageBrief::tweak('the permit lies on the dashboard'))
        ->toContain('never write words, labels, numbers or ID details on cards, badges, permits')
        ->and(KeyframeImageBrief::tweakOnPlate('the permit lies on the dashboard'))
        ->toContain('never write words, labels, numbers or ID details on cards, badges, permits');
});

it('plans around what the image and video models cannot do, and has the plan director explain it and propose another way', function () {
    $shot = Shot::factory()->create();

    expect(App\Ai\Briefs\VideoLimitsBrief::limits())->toHaveCount(16)
        ->and((string) (new App\Ai\Agents\StorylineWriter($shot->load('project')))->instructions())
        ->toContain('What the image and video models cannot do in one shot')
        ->toContain('The place changes within the shot, such as driving through a gate')
        ->toContain('One person does two things with their hands at the same time')
        ->and((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('tell the director in plain words why it will not work')
        ->toContain('Never write a plan that runs into one of these limits');
});

it('tells two pictured people apart, so the image model does not swap their places', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the meeting-room door.']);
    $host = Element::factory()->for($shot->project)->create(['name' => 'Damen host', 'description' => 'A friendly employee in a navy jacket and high-visibility vest. They guide visitors.']);
    $visitor = Element::factory()->for($shot->project)->create(['name' => 'Helmeted visitor', 'description' => 'A smiling woman with long blond hair and a striped shirt. She is a visitor.']);
    $pictured = fn(Element ...$people) => array_map(fn(Element $person) => ['element' => $person, 'image' => new StoredImage("{$person->id}.png")], $people);

    $onPlace = KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(elements: [$host, $visitor], elementImages: $pictured($host, $visitor), first: new StoredImage('place.png'), firstIsPlate: true));
    $fresh = KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(elements: [$host, $visitor], elementImages: $pictured($host, $visitor)));

    foreach ([$onPlace, $fresh] as $prompt) {
        expect($prompt)
            ->toContain('Who is who: tell the people apart by their pictures, and give each one exactly the place, pose and action the description gives that name; never swap them.')
            ->toContain('- Damen host: A friendly employee in a navy jacket and high-visibility vest.')
            ->toContain('- Helmeted visitor: A smiling woman with long blond hair and a striped shirt.')
            ->not->toContain('They guide visitors');
    }

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(elements: [$host], elementImages: $pictured($host))))->not->toContain('Who is who');
});

it('takes where people stand from the description, not from the keyframe before', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the side door of the hall.']);

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences(first: new StoredImage('place.png'), previous: new StoredImage('before.png'), firstIsPlate: true)))
        ->toContain('keep the people looking the same as there. Where each person stands, which way they face and what they do comes only from this keyframe\'s description, never from that image.');
});

it('frames a scene planned as a medium shot closer, also its empty place', function () {
    $shot = framedShot(['spot' => 'The reception counter.', 'size' => 'medium']);

    expect($shot->shotSize())->toBe(App\Enums\ShotSize::MEDIUM)
        ->and(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('Framing: Medium shot at eye level')
        ->and(KeyframeImageBrief::plate($shot, new KeyframeReferences(), 0))
        ->toContain('Framing: Medium shot at eye level')
        ->toContain('seen from the knees up');
});

it('keeps a close-up a close-up, whatever size its plan names', function () {
    $shot = framedShot(['spot' => 'Her chest.', 'size' => 'medium']);
    $shot->forceFill(['kind' => App\Enums\ShotKind::CLOSE_UP])->save();

    expect($shot->shotSize())->toBe(App\Enums\ShotSize::CLOSE_UP);
});

it('names hands by the side of the frame and never lets arms cross', function () {
    $shot = framedShot(['size' => 'full', 'spot' => 'At the route panel.']);
    $keyframe = App\Models\Keyframe::factory()->for($shot)->create();

    expect((string) (new App\Ai\Agents\StorylineWriter($shot))->instructions())
        ->toContain('such as "the hand on the left of the frame", never as "his left hand"')
        ->toContain('a hand never reaches across to the other side, and arms never cross')
        ->and((string) (new App\Ai\Agents\PlanDirector($shot, []))->instructions())
        ->toContain('never by a person\'s own left or right')
        ->toContain('A request that makes the arms cross or a hand reach across to the other side of the body cannot be drawn')
        ->and((string) (new App\Ai\Agents\TweakInterpreter($keyframe, []))->instructions())
        ->toContain('have the hand on that side do it instead');
});
