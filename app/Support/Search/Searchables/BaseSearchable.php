<?php

declare(strict_types=1);

namespace App\Support\Search\Searchables;

use App\Support\Search\Http\Resources\SearchableResource;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\CursorPaginator;
use RedExplosion\Sqids\Concerns\HasSqids;

abstract class BaseSearchable
{
    /**
     * The entity key used in the url.
     */
    abstract public function key(): string;

    /**
     * Query builder for the search.
     */
    abstract public function query(Request $request): Builder;

    /**
     * Columns that can be searched.
     */
    abstract public function searchable(): array;

    /**
     * The API resource class for type generation.
     *
     * @return class-string<JsonResource>
     */
    abstract public static function resource(): string;

    /**
     * Per page default
     */
    public function perPage(): int
    {
        return 15;
    }

    /**
     * Find a single record by ID.
     */
    public function find(Request $request, string|int $id)
    {
        $model = $this->query($request)->getModel();

        if (in_array(HasSqids::class, class_uses_recursive($model)) && is_string($id)) {
            /** @phpstan-ignore staticMethod.notFound */
            $id = $model::keyFromSqid($id);
        }

        $record = $this->query($request)
            ->where($model->getQualifiedKeyName(), $id)
            ->firstOrFail();

        return new (static::resource())($record);
    }

    /**
     * List all chat messages for a given entity.
     *
     * @return AnonymousResourceCollection<CursorPaginator<SearchableResource>>
     */
    public function search(Request $request)
    {
        $query = $this->query($request);

        if ($search = $request->string('q')->trim()->value()) {
            $model = $query->getModel();

            // Collect unique relationships to join
            $relationships = collect($this->searchable())
                ->filter(fn(string $column) => str_contains($column, '.'))
                ->map(fn(string $column) => str($column)->beforeLast('.')->value())
                ->unique()
                ->values();

            // Join relationships once
            foreach ($relationships as $relationship) {
                $query->leftJoinRelationship($relationship);
            }

            // Apply search conditions with qualified column names
            $query->where(function (Builder $query) use ($search, $model) {
                foreach ($this->searchable() as $column) {
                    $qualifiedColumn = $this->qualifyColumn($column, $model);
                    $query->orWhere($qualifiedColumn, 'like', "%{$search}%");
                }
            });
        }

        $results = $query->cursorPaginate(
            perPage: $this->perPage(),
        );

        return static::resource()::collection($results);
    }

    /**
     * Qualify a column with its table name.
     *
     * @param string $column Column name (e.g., 'name' or 'tenant.name')
     * @param Model $model The base model
     */
    protected function qualifyColumn(string $column, Model $model): string
    {
        // Local column: qualify with main table
        if (! str_contains($column, '.')) {
            return $model->qualifyColumn($column);
        }

        // Related column: resolve relationship to get table name
        $parts = explode('.', $column);
        $columnName = array_pop($parts);
        $relationPath = implode('.', $parts);

        // Traverse the relationship path to get the final related model
        $relatedModel = $this->resolveRelatedModel($model, $relationPath);

        return $relatedModel->qualifyColumn($columnName);
    }

    /**
     * Resolve the related model from a relationship path.
     */
    protected function resolveRelatedModel(Model $model, string $relationPath): Model
    {
        $relations = explode('.', $relationPath);

        foreach ($relations as $relation) {
            /** @var Relation $relationship */
            $relationship = $model->{$relation}();
            $model = $relationship->getRelated();
        }

        return $model;
    }
}
