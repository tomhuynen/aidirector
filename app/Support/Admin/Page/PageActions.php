<?php

declare(strict_types=1);

namespace App\Support\Admin\Page;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PageActions
{
    /** @var Collection<int, PageAction> */
    protected Collection $items;

    public function __construct()
    {
        $this->items = collect();
    }

    /**
     * CRUD preset - generates view/edit/delete actions for a model.
     *
     * @param  array<string, string|null>  $policies  Map of action => policy constant (set to null to skip)
     */
    public function crud(Model $model, string $resource, array $policies = []): self
    {
        $policies = array_merge([
            'view' => 'view',
            'update' => 'update',
            'destroy' => 'destroy',
        ], $policies);

        if ($policies['view'] !== null) {
            $this->add(
                PageAction::make(__('View'), route("admin.{$resource}.view", $model))
                    ->icon('Eye')
                    ->can($policies['view'], $model)
            );
        }

        if ($policies['update'] !== null) {
            $this->add(
                PageAction::make(__('Edit'), route("admin.{$resource}.update", $model))
                    ->icon('Pencil')
                    ->can($policies['update'], $model)
            );
        }

        if ($policies['destroy'] !== null) {
            $this->add(
                PageAction::make(__('Delete'), route("admin.{$resource}.destroy", $model))
                    ->icon('Trash')
                    ->can($policies['destroy'], $model)
            );
        }

        return $this;
    }

    /**
     * Index preset - generates create action.
     *
     * @param  class-string<Model>|null  $modelClass  Required for permission check
     */
    public function index(string $resource, ?string $modelClass = null, ?string $createPermission = null): self
    {
        $action = PageAction::make(
            __('Add :resource', ['resource' => __(Str::headline(Str::singular($resource)))]),
            route("admin.{$resource}.create")
        )->icon('Plus');

        if ($createPermission !== null && $modelClass !== null) {
            $action->can($createPermission, $modelClass);
        }

        return $this->add($action);
    }

    public function add(PageAction $action): self
    {
        $this->items->push($action);

        return $this;
    }

    /**
     * @param  array<PageAction>  $actions
     */
    public function append(array $actions): self
    {
        collect($actions)->each(fn(PageAction $action) => $this->add($action));

        return $this;
    }

    /**
     * @param  array<PageAction>  $actions
     */
    public function prepend(array $actions): self
    {
        $this->items = collect($actions)->merge($this->items);

        return $this;
    }

    public function when(bool $condition, Closure $callback): self
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Resolve actions for a user, filtering by authorization.
     *
     * @return array<int, array{title: string, action: string, icon: string|null, disabled: bool}>
     */
    public function resolve(?Authenticatable $user): array
    {
        return $this->items
            ->filter(fn(PageAction $action) => $action->isAuthorized($user))
            ->map(fn(PageAction $action) => $action->toArray())
            ->values()
            ->toArray();
    }
}
