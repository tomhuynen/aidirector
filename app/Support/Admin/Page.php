<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Support\Admin\Page\BreadcrumbItem;
use App\Support\Admin\Page\Breadcrumbs;
use App\Support\Admin\Page\PageActions;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

#[Singleton]
class Page
{
    protected ?PageActions $actions = null;

    /** @var Collection<int, BreadcrumbItem>|null */
    protected ?Collection $breadcrumbs = null;

    /**
     * Static helper to get singleton from container.
     */
    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * Shorthand to get/create actions builder.
     */
    public static function actions(): PageActions
    {
        return self::instance()->getActions();
    }

    /**
     * Shorthand to set custom breadcrumbs.
     *
     * @param  array<BreadcrumbItem|array{title: string, href?: string|null}>  $items
     */
    public static function breadcrumbs(array $items): void
    {
        self::instance()->setBreadcrumbs($items);
    }

    public function getActions(): PageActions
    {
        return $this->actions ??= new PageActions();
    }

    /**
     * Set custom breadcrumbs (overrides auto-generation).
     *
     * @param  array<BreadcrumbItem|array{title: string, href?: string|null}>  $items
     */
    public function setBreadcrumbs(array $items): void
    {
        $this->breadcrumbs = collect($items)
            ->map(
                fn(BreadcrumbItem|array $item) => $item instanceof BreadcrumbItem
                ? $item
                : new BreadcrumbItem(...$item)
            );
    }

    /**
     * Convert page state to array for Inertia.
     *
     * @return array{breadcrumbs: array<int, array{title: string, href: string|null}>, actions: array<int, array{title: string, action: string, icon: string|null, disabled: bool}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'breadcrumbs' => $this->resolveBreadcrumbs($request),
            'actions' => $this->resolveActions($request),
        ];
    }

    /**
     * @return array<int, array{title: string, href: string|null}>
     */
    protected function resolveBreadcrumbs(Request $request): array
    {
        $breadcrumbs = $this->breadcrumbs ?? Breadcrumbs::generate($request);

        return $breadcrumbs
            ->map(fn(BreadcrumbItem $item) => $item->toArray())
            ->toArray();
    }

    /**
     * @return array<int, array{title: string, action: string, icon: string|null, disabled: bool}>
     */
    protected function resolveActions(Request $request): array
    {
        return $this->actions?->resolve($request->user()) ?? [];
    }

    /**
     * Reset state for next request.
     */
    public function flush(): void
    {
        $this->actions = null;
        $this->breadcrumbs = null;
    }
}
