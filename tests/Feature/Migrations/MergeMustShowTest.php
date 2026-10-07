<?php

declare(strict_types=1);

use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Support\Facades\DB;

it('appends the old must show line to the plan and keyframe descriptions once', function () {
    $shot = Shot::factory()->create(['storyline' => ['mode' => 'auto', 'keyframes' => [
        ['title' => 'Stop', 'description' => 'She stops at the line', 'must_show' => 'Both feet are behind the yellow line.'],
        ['title' => 'Look', 'description' => 'She looks up. Both feet are behind the yellow line.', 'must_show' => 'Both feet are behind the yellow line.'],
        ['title' => 'Leave', 'description' => 'She leaves.', 'must_show' => ''],
        ['title' => 'Gone', 'description' => 'The hall is empty.'],
    ]]]);
    $first = Keyframe::factory()->for($shot)->create(['position' => 1, 'description' => 'She stops at the line']);

    $migration = require database_path('migrations/tenant/2026_10_06_120000_merge_must_show_into_keyframe_descriptions.php');
    DB::usingConnection('tenant', fn() => $migration->up());

    expect($shot->fresh()->storyline)->toBe(['mode' => 'auto', 'keyframes' => [
        ['title' => 'Stop', 'description' => 'She stops at the line. Both feet are behind the yellow line.'],
        ['title' => 'Look', 'description' => 'She looks up. Both feet are behind the yellow line.'],
        ['title' => 'Leave', 'description' => 'She leaves.'],
        ['title' => 'Gone', 'description' => 'The hall is empty.'],
    ]])
        ->and($first->fresh()->description)->toBe('She stops at the line. Both feet are behind the yellow line.');
});
