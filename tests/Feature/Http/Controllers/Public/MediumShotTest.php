<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\StorylineWriter;
use App\Ai\Briefs\DirectingRules;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframeReferences;
use App\Enums\ShotKind;
use App\Enums\ShotSize;
use App\Enums\ShotStatus;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;

beforeEach(function () {
    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function mediumShot(Project $project): Shot
{
    return Shot::factory()->for($project)->create([
        'kind' => ShotKind::MEDIUM,
        'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
        'chosen_storyline' => ['storyline' => 'He reaches for the bag and stops.'],
        'storyline' => ['framing' => ['spot' => 'The bench in the hall.', 'seconds' => 3], 'keyframes' => [
            ['title' => 'Reaching', 'description' => 'He reaches toward the bag on the bench.', 'spatial' => ''],
            ['title' => 'Stopped', 'description' => 'His hand hangs still above the bag.', 'spatial' => ''],
        ]],
    ]);
}

it('is a scene framed from the waist up, with its own size and framing', function () {
    expect(ShotKind::MEDIUM->size())->toBe(ShotSize::MEDIUM)
        ->and(ShotSize::MEDIUM->framing())->toContain('from the waist up')
        ->and(ShotKind::MEDIUM->useWhen())->toContain('a hand that reaches for a bag and stops')
        ->and(collect(ShotKind::catalogue())->pluck('value')->all())->toContain('medium');
});

it('plans a medium shot by the rules of a scene, framed at the waist', function () {
    $shot = Shot::factory()->for($this->project)->make(['kind' => ShotKind::MEDIUM]);
    $instructions = (string) (new StorylineWriter($shot))->instructions();

    expect($instructions)
        ->toContain('A medium shot is a scene framed from the waist up')
        ->toContain('waist up')
        ->toContain('the start frame')
        ->not->toContain('For a scene or a medium shot:');
});

it('draws a medium shot cut at the waist, with the face and hands readable', function () {
    $shot = mediumShot($this->project);
    $shot->load('project');

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
        ->toContain('the person from the waist up is the subject')
        ->toContain('The frame is cut at the waist; never the whole figure')
        ->not->toContain('The ground near the feet is plain');

    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2]);

    expect((string) (new KeyframeChecker($keyframe, ['the keyframe to check']))->instructions())->toContain('This keyframe is a medium shot');
});

it('continues from the shot before it by default, like a close-up', function () {
    $before = Shot::factory()->for($this->project)->create(['position' => 1, 'group_key' => 'g']);
    $shot = Shot::factory()->for($this->project)->create(['position' => 2, 'group_key' => 'g', 'kind' => ShotKind::MEDIUM, 'status' => ShotStatus::STORYLINE_READY]);

    expect($shot->settingFrom())->toMatchArray(['keyframe' => Shot::LAST_KEYFRAME])
        ->and($shot->settingFrom()['shot']->is($before))->toBeTrue();
});

it('gives the scene director the sizes and the directing rules with an approved example', function () {
    $brief = DirectingRules::brief();

    expect($brief)
        ->toContain('decisive change')
        ->toContain('Medium shot at eye level')
        ->toContain('The camera never moves')
        ->toContain('Approved example:');
});
