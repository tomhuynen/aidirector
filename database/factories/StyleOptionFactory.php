<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StyleOptionStatus;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StyleOption>
 */
class StyleOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'round' => 1,
            'position' => 1,
            'style' => [
                'name' => 'Soft 3D cartoon',
                'look' => 'Rounded, simplified shapes with soft shading',
                'medium' => '3D render',
                'mood' => 'Friendly and calm',
                'palette' => 'Navy, warm grey, red accent',
                'lighting' => 'Soft overcast daylight',
            ],
            'prompt' => 'A 2x2 style sheet.',
            'status' => StyleOptionStatus::PENDING,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn(): array => ['status' => StyleOptionStatus::READY]);
    }

    public function failed(string $error = 'Failed'): static
    {
        return $this->state(fn(): array => ['status' => StyleOptionStatus::FAILED, 'error' => $error]);
    }

    public function childOf(StyleOption $parent): static
    {
        return $this->state(fn(): array => [
            'project_id' => $parent->project_id,
            'parent_id' => $parent->id,
            'round' => $parent->round + 1,
        ]);
    }
}
