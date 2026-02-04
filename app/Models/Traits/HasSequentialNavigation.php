<?php

declare(strict_types=1);

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** @mixin Model */
trait HasSequentialNavigation
{
    /**
     * Get the previous model instance.
     */
    public function previous(string $column = 'id', string $direction = 'asc', bool $useCache = true): ?static
    {
        return $this->getSequentialModel('previousBy', $column, $direction, $useCache);
    }

    /**
     * Get the next model instance.
     */
    public function next(string $column = 'id', string $direction = 'asc', bool $useCache = true): ?static
    {
        return $this->getSequentialModel('nextBy', $column, $direction, $useCache);
    }

    /**
     * Get both previous and next models efficiently.
     */
    public function siblings(string $column = 'id', string $direction = 'asc', bool $useCache = true): array
    {
        if (! $useCache) {
            return [
                'previous' => $this->previous($column, $direction, false),
                'next' => $this->next($column, $direction, false),
            ];
        }

        $cacheKey = $this->getSequentialCacheKey($column, $direction);

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($column, $direction) {
            return [
                'previous' => $this->previous($column, $direction, false),
                'next' => $this->next($column, $direction, false),
            ];
        });
    }

    /**
     * Scope for getting the previous model.
     */
    #[Scope]
    protected function previousBy(Builder $query, mixed $value, string $column = 'id', string $direction = 'asc'): Builder
    {
        $operator = $direction === 'asc' ? '<' : '>';
        $orderDirection = $direction === 'asc' ? 'desc' : 'asc';

        return $query
            ->where($column, $operator, $value)
            ->orderBy($column, $orderDirection);
    }

    /**
     * Scope for getting the next model.
     */
    #[Scope]
    protected function nextBy(Builder $query, mixed $value, string $column = 'id', string $direction = 'asc'): Builder
    {
        $operator = $direction === 'asc' ? '>' : '<';

        return $query
            ->where($column, $operator, $value)
            ->orderBy($column, $direction);
    }

    /**
     * Get the sequential model with optional caching.
     */
    private function getSequentialModel(string $type, string $column, string $direction, bool $useCache): ?static
    {
        $value = $this->getAttribute($column);

        if ($value === null) {
            return null;
        }

        if (! $useCache) {
            return static::query()
                ->{$type}($value, $column, $direction)
                ->first();
        }

        $cacheKey = $this->getSequentialCacheKey($column, $direction, $type);

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($type, $value, $column, $direction) {
            return static::query()
                ->{$type}($value, $column, $direction)
                ->first();
        });
    }

    /**
     * Generate cache key for sequential navigation.
     */
    private function getSequentialCacheKey(string $column, string $direction, ?string $type = null): string
    {
        $parts = [
            'sequential',
            static::class,
            $this->getKey(),
            $column,
            $direction,
        ];

        if ($type) {
            $parts[] = $type;
        }

        return implode(':', $parts);
    }

    /**
     * Clear sequential navigation cache for this model.
     */
    public function clearSequentialCache(): void
    {
        $patterns = [
            $this->getSequentialCacheKey('id', 'asc'),
            $this->getSequentialCacheKey('id', 'desc'),
            $this->getSequentialCacheKey('created_at', 'asc'),
            $this->getSequentialCacheKey('created_at', 'desc'),
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
            Cache::forget($pattern . ':previous');
            Cache::forget($pattern . ':next');
        }
    }

    /**
     * Boot the trait to clear cache on model changes.
     */
    protected static function bootHasSequentialNavigation(): void
    {
        static::saved(function ($model) {
            $model->clearSequentialCache();
        });

        static::deleted(function ($model) {
            $model->clearSequentialCache();
        });
    }
}
