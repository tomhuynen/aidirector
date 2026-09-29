<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShotStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shot>
 */
class ShotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'position' => 1,
            'title' => fake()->sentence(3),
            'subject' => 'A businessman in a navy suit',
            'action' => 'Walks to a red mailbox and posts a white envelope',
            'takeaway' => 'Sending the letter is easy and final',
            'notes' => null,
            'status' => ShotStatus::DRAFT,
        ];
    }
}
