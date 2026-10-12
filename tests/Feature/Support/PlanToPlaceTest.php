<?php

declare(strict_types=1);

use App\Ai\Agents\PlanPlaceAligner;
use App\Enums\Disk;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\PlanToPlace;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->shot = Shot::factory()->for(Project::factory())->create(['storyline' => ['keyframes' => [
        ['title' => 'Fire', 'description' => 'The bin burns on the right of the frame.', 'spatial' => '', 'handled' => []],
        ['title' => 'Away', 'description' => 'She walks away to the left of the frame.', 'spatial' => 'Two steps from the bin.', 'handled' => []],
    ]]]);
    Keyframe::factory()->for($this->shot)->create(['position' => 1, 'description' => 'The bin burns on the right of the frame.']);
    Keyframe::factory()->for($this->shot)->create(['position' => 2, 'description' => 'She walks away to the left of the frame.']);
    $image = imagecreatetruecolor(9, 16);
    ob_start();
    imagepng($image);
    $this->place = $this->shot->addMediaFromString((string) ob_get_clean())->usingFileName('place.png')->toMediaCollection(Shot::PLATE);
});

it('matches left and right in the plan to the chosen place once, and says what changed', function () {
    PlanPlaceAligner::fake([[
        'keyframes' => [
            ['description' => 'The bin burns on the left of the frame by the pillar.', 'spatial' => ''],
            ['description' => 'She walks away to the right of the frame.', 'spatial' => 'Two steps from the bin.'],
        ],
        'note' => 'The bin stands on the left by the pillar, so she now walks away to the right.',
    ]]);

    app(PlanToPlace::class)->align($this->shot, $this->place);
    app(PlanToPlace::class)->align($this->shot->fresh(), $this->place);

    $shot = $this->shot->fresh();

    expect(array_column($shot->storylineKeyframes(), 'description'))->toBe(['The bin burns on the left of the frame by the pillar.', 'She walks away to the right of the frame.'])
        ->and($shot->storylineKeyframes()[1]['spatial'])->toBe('Two steps from the bin.')
        ->and($shot->storylineKeyframes()[1]['handled'])->toBe([])
        ->and($shot->keyframes()->where('position', 2)->value('description'))->toBe('She walks away to the right of the frame.')
        ->and(collect($shot->plan_chat)->last()['text'])->toBe('I matched the plan to the chosen place: The bin stands on the left by the pillar, so she now walks away to the right.');
    PlanPlaceAligner::assertPrompted(fn($prompt) => count($prompt->attachments) === 1 && str_contains($prompt->prompt, '2. She walks away to the left of the frame.'));
    PlanPlaceAligner::assertPromptedTimes(1);
});

it('keeps the plan when the answer does not have the same keyframes', function () {
    PlanPlaceAligner::fake([['keyframes' => [['description' => 'Only one.', 'spatial' => '']], 'note' => 'Changed.']]);

    app(PlanToPlace::class)->align($this->shot, $this->place);

    expect($this->shot->fresh()->storylineKeyframes()[0]['description'])->toBe('The bin burns on the right of the frame.')
        ->and($this->shot->fresh()->plan_chat)->toBeNull();
});

it('checks the plan against the layout of the place, and says what cannot work from this camera', function () {
    App\Ai\Agents\PlaceMapper::fake([[
        'map' => 'The lane runs away from the camera; the booth stands on its right.',
        'things' => [['name' => 'Guard booth', 'side' => 'right', 'distance' => 'middle', 'faces' => 'its window faces the lane']],
        'routes' => [['name' => 'Entrance lane', 'runs' => 'from the near middle away from the camera to the barrier']],
    ]]);
    PlanPlaceAligner::fake([[
        'keyframes' => [
            ['description' => 'The bin burns on the right of the frame.', 'spatial' => ''],
            ['description' => 'She walks away to the left of the frame.', 'spatial' => 'Two steps from the bin.'],
        ],
        'note' => '',
        'problem' => 'The truck in the lane hides the guard and his booth; a camera across the lane would show both.',
    ]]);

    app(PlanToPlace::class)->align($this->shot, $this->place);
    app(PlanToPlace::class)->align($this->shot->fresh(), $this->place->fresh());

    $last = collect($this->shot->fresh()->plan_chat)->last();

    expect($this->place->fresh()->getCustomProperty(App\Support\Images\PlaceLayout::KEY)['routes'][0]['name'])->toBe('Entrance lane')
        ->and($last['text'])->toBe('From this camera one thing cannot work: The truck in the lane hides the guard and his booth; a camera across the lane would show both.')
        ->and($last['answers'])->toBe(['Fix the plan', 'Use another camera', 'Keep it']);
    PlanPlaceAligner::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "Routes through the place:\n- Entrance lane: from the near middle away from the camera to the barrier")
        && str_contains((string) $prompt->agent->instructions(), 'Seen: from this camera, nothing that must be seen is hidden behind something nearer the camera'));
    App\Ai\Agents\PlaceMapper::assertPromptedTimes(1);
});
