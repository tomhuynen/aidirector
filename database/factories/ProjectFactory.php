<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Models\Director;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'director_id' => Director::factory(),
            'title' => fake()->sentence(3),
            'purpose' => fake()->randomElement(ProjectPurpose::cases()),
            'description' => fake()->paragraph(),
            'style' => [
                'look' => 'Clean 3D cartoon, soft shading, muted colours',
                'palette' => 'Navy, red accent, warm grey background',
                'medium' => '3D illustration',
                'mood' => 'Friendly and calm',
                'references' => [],
            ],
            'aspect_ratio' => AspectRatio::LANDSCAPE,
            'default_duration' => 5,
        ];
    }

    public function ownedBy(Director $director): static
    {
        return $this->state(fn(): array => ['director_id' => $director->id]);
    }

    public function archived(): static
    {
        return $this->state(fn(): array => ['archived_at' => now()]);
    }
}
