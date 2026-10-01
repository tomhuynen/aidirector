<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ElementSuggestionStatus;
use App\Models\ElementRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ElementSuggestion>
 */
class ElementSuggestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'element_round_id' => ElementRound::factory(),
            'position' => 1,
            'name' => 'Visitor',
            'description' => 'A first-time visitor in a hi-vis vest and white helmet, carrying a visitor badge.',
            'status' => ElementSuggestionStatus::PENDING,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn(): array => ['status' => ElementSuggestionStatus::READY]);
    }
}
