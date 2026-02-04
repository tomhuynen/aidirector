<?php

declare(strict_types=1);

namespace App\Support\Admin\Page;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class Breadcrumbs
{
    /**
     * Segments that don't appear in breadcrumb trail.
     *
     * @var array<string>
     */
    protected static array $skip = ['index', 'edit', 'create', 'update', 'view', 'store', 'destroy'];

    /**
     * Override titles for specific segments (optional).
     *
     * @var array<string, string>
     */
    protected static array $titles = [];

    /**
     * Generate breadcrumbs from the current route.
     *
     * @return Collection<int, BreadcrumbItem>
     */
    public static function generate(Request $request): Collection
    {
        $route = $request->route();
        $name = $route?->getName();

        if (! $name || ! Str::startsWith($name, 'admin.')) {
            return collect();
        }

        $segments = Str::of($name)
            ->after('admin.')
            ->explode('.');

        $parameters = collect($route->parameters());

        return self::buildTrail($segments, $parameters);
    }

    /**
     * Build the breadcrumb trail from route segments.
     *
     * @param  Collection<int, string>  $segments
     * @param  Collection<string, mixed>  $parameters
     * @return Collection<int, BreadcrumbItem>
     */
    protected static function buildTrail(Collection $segments, Collection $parameters): Collection
    {
        $routeParts = collect(['admin']);
        $breadcrumbs = collect();

        $segments
            ->reject(fn(string $segment) => in_array($segment, self::$skip, true))
            ->each(function (string $segment) use ($parameters, $routeParts, $breadcrumbs) {
                $routeParts->push($segment);
                $title = self::$titles[$segment] ?? Str::headline($segment);

                // Try index route, fall back to no link if it doesn't exist
                $indexRouteName = collect($routeParts)->push('index')->implode('.');
                $href = self::routeExists($indexRouteName) ? route($indexRouteName) : null;

                $breadcrumbs->push(BreadcrumbItem::make(__($title), $href));

                // Check for model parameter (accounts → account)
                $modelKey = Str::singular($segment);

                if ($parameters->has($modelKey)) {
                    $model = $parameters->get($modelKey);
                    $viewRouteName = collect($routeParts)->push('view')->implode('.');

                    // Only add model breadcrumb if view route exists
                    if (self::routeExists($viewRouteName)) {
                        $breadcrumbs->push(BreadcrumbItem::fromModel($model, $viewRouteName));
                    }
                }
            });

        return $breadcrumbs;
    }

    /**
     * Check if a named route exists.
     */
    protected static function routeExists(string $name): bool
    {
        return Route::has($name);
    }
}
