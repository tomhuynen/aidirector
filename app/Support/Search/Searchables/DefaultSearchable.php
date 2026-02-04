<?php

declare(strict_types=1);

namespace App\Support\Search\Searchables;

use App\Support\Search\Contracts\Searchable;
use App\Support\Search\Http\Resources\SearchableResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

class DefaultSearchable extends BaseSearchable
{
    /**
     * @param class-string<Model & Searchable> $class
     */
    public function __construct(
        public private(set) string $class,
    ) {}

    public function key(): string
    {
        return Relation::getMorphAlias($this->class);
    }

    public function query(Request $request): Builder
    {
        return $this->class::query();
    }

    public function searchable(): array
    {
        return (new $this->class())->searchableColumns();
    }

    public static function resource(): string
    {
        return SearchableResource::class;
    }
}
