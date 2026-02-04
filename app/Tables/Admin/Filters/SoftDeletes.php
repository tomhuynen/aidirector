<?php

declare(strict_types=1);

namespace App\Tables\Admin\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes as SoftDeletesTrait;
use InertiaUI\Table\Filters\Clause;

class SoftDeletes
{
    public function __invoke(Builder $resource, string $attribute, Clause $clause, mixed $value)
    {
        if ($clause === Clause::IsTrue && in_array(SoftDeletesTrait::class, class_uses_recursive($resource->getModel()))) {
            // @phpstan-ignore-next-line (Builder supports soft deletes when model uses SoftDeletes trait)
            $resource->withTrashed();
        }
    }
}
