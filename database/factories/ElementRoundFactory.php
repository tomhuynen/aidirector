<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ElementRoundStatus;
use App\Enums\ElementType;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ElementRound>
 */
class ElementRoundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => ElementType::PERSON,
            'brief' => 'Visitors, contractors and the security guard at the Damen shipyard gate.',
            'status' => ElementRoundStatus::READY,
        ];
    }

    public function status(ElementRoundStatus $status): static
    {
        return $this->state(fn(): array => ['status' => $status]);
    }

    public function type(ElementType $type): static
    {
        return $this->state(fn(): array => ['type' => $type]);
    }
}
