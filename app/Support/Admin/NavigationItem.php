<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class NavigationItem implements Arrayable
{
    public function __construct(
        private readonly array $properties,
        private readonly Request $request
    ) {}

    public function getGroup()
    {
        return Arr::get($this->properties, 'group', __('Application'));
    }

    public function getTitle()
    {
        return Arr::get($this->properties, 'title');
    }

    public function getRouteName()
    {
        return Arr::get($this->properties, 'route');
    }

    public function getRoute()
    {
        $routeName = $this->getRouteName();

        if ($routeName === '#') {
            return '#';
        }

        return $this->isUrl()
            ? $routeName
            : route($routeName);
    }

    public function getTarget()
    {
        return $this->isUrl() || $this->isExternal()
            ? '_blank'
            : '_self';
    }

    public function getItems()
    {
        return collect(Arr::get($this->properties, 'items', []))
            ->map(fn(array $properties) => new NavigationItem($properties, $this->request))
            ->toArray();
    }

    public function getIcon()
    {
        return Arr::get($this->properties, 'icon');
    }

    public function hasIcon()
    {
        return ! empty($this->getIcon());
    }

    public function isUrl()
    {
        return filter_var($this->getRouteName(), FILTER_VALIDATE_URL);
    }

    public function isExternal()
    {
        return Arr::get($this->properties, 'external', false);
    }

    public function isActive()
    {
        $route = $this->request->route();
        if (empty($route)) {
            return false;
        }

        if ($route->getName() === $this->getRouteName()) {
            return true;
        }

        $routeRoot = Str::replaceLast('.index', '', $this->getRouteName());
        if (Route::is($routeRoot . '*')) {
            // this matches the /[route]/{slug} pattern
            return true;
        }

        return false;
    }

    public function toArray()
    {
        return [
            'active' => $this->isActive(),
            'href' => $this->getRoute(),
            'target' => $this->getTarget(),
            'hasIcon' => $this->hasIcon(),
            'icon' => $this->getIcon(),
            'title' => $this->getTitle(),
            'items' => $this->getItems(),
            'group' => $this->getGroup(),
            'external' => $this->isExternal(),
        ];
    }
}
