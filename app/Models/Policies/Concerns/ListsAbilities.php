<?php

declare(strict_types=1);

namespace App\Models\Policies\Concerns;

use ReflectionClass;

trait ListsAbilities
{
    /**
     * Get all ability constants defined on this policy.
     *
     * Uses reflection to discover all public string constants.
     *
     * @return array<string>
     */
    public static function abilities(): array
    {
        return collect((new ReflectionClass(static::class))->getConstants())
            ->filter(fn(mixed $value): bool => is_string($value))
            ->values()
            ->all();
    }
}
