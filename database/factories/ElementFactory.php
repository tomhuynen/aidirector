<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ElementType;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Element>
 */
class ElementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => ElementType::PERSON,
            'name' => 'Mark, the visitor',
            'description' => 'A middle-aged man with short dark hair in a navy suit, white shirt and blue tie, with a visitor badge.',
        ];
    }

    public function place(): static
    {
        return $this->state(fn(): array => [
            'type' => ElementType::PLACE,
            'name' => 'Main gate',
            'description' => 'The shipyard main gate: a grey steel gatehouse with a yellow barrier and a blue sign.',
        ]);
    }

    public function object(): static
    {
        return $this->state(fn(): array => [
            'type' => ElementType::OBJECT,
            'name' => 'Stan Tug',
            'description' => 'A red and white harbour tug with a black hull and a white wheelhouse.',
        ]);
    }
}
