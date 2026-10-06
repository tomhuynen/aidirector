<?php

declare(strict_types=1);

use App\Ai\Agents\ShotReviewer;
use App\Enums\ShotStatus;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;

it('says which keyframe each image is, also when a keyframe has no image', function () {
    $shot = Shot::factory()->for(Project::factory())->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'storyline' => ['keyframes' => [
            ['title' => 'At the door', 'description' => 'She stands at the door.'],
            ['title' => 'Notices', 'description' => 'She sees the sign.'],
            ['title' => 'Bin', 'description' => 'The cigarette goes in the bin.'],
        ]],
    ]);
    $first = Keyframe::factory()->for($shot)->create(['position' => 1]);
    $third = Keyframe::factory()->for($shot)->create(['position' => 3]);

    $prompt = (new ShotReviewer($shot, collect([$first, $third])))->promptFor();

    expect($prompt)->toContain('Image 1 is keyframe 1. Image 2 is keyframe 3.');
});
