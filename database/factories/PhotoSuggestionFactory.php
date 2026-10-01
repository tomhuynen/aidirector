<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PhotoSuggestion>
 */
class PhotoSuggestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'batch' => 1,
            'position' => 1,
            'query' => 'Damen tug boat',
            'image_url' => 'https://images.example/' . fake()->uuid() . '.jpg',
            'thumbnail_url' => 'https://thumbs.example/' . fake()->uuid() . '.jpg',
            'source_url' => 'https://www.damen.com/vessels/tugs',
            'title' => 'Damen Stan Tug 1606',
            'domain' => 'www.damen.com',
            'width' => 1600,
            'height' => 900,
            'from_website' => true,
        ];
    }

    public function picked(): static
    {
        return $this->state(fn(): array => ['picked_at' => now()]);
    }
}
