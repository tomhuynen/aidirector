<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

class Navigation
{
    private function __construct(
        private readonly Request $request,
    ) {}

    public static function build(Request $request)
    {
        return new self($request)->generate();
    }

    protected function generate()
    {
        return collect($this->getNavigationConfig())
            ->map(function ($group) {
                $items = Arr::get($group, 'items', []);
                $items = $this->processItems(collect($items));

                if ($items->isEmpty()) {
                    return;
                }

                return (object) [
                    'title' => $group['title'],
                    'items' => $items,
                ];
            })
            ->filter();
    }

    private function processItems(Collection $items)
    {
        $user = $this->request->user();
        if (empty($user)) {
            return collect([]);
        }

        return $items
            ->map(function ($item) use ($user) {
                if (! $this->hasAccessToItem($item, $user)) {
                    return;
                }

                if (Arr::has($item, 'items')) {
                    $items = collect(Arr::get($item, 'items', []))
                        ->filter(fn($item) => $this->hasAccessToItem($item, $user))
                        ->toArray();

                    if (empty($items)) {
                        return;
                    }

                    $item['items'] = $items;
                }

                return new NavigationItem($item, $this->request);
            })
            ->filter();
    }

    private function hasAccessToItem(array $item, User $user)
    {
        $permission = Arr::get($item, 'permission');

        if (! empty($permission) && ! $user->can($permission)) {
            return false;
        }

        return true;
    }

    private function getNavigationConfig()
    {
        return Cache::rememberForever(__METHOD__, function () {
            $path = resource_path('config/admin/navigation.yaml');

            return Yaml::parseFile($path);
        });
    }
}
