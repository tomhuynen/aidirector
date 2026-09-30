<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Keyframe>
 */
class KeyframeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shot_id' => Shot::factory(),
            'position' => 1,
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }
}
