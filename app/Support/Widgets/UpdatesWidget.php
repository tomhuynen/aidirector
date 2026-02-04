<?php

declare(strict_types=1);

namespace App\Support\Widgets;

use App\Support\Updates\UpstreamMonitor;
use App\Support\Widgets\Abstracts\Widget;
use App\Support\Widgets\Attributes\Action;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class UpdatesWidget extends Widget
{
    private UpstreamMonitor $upstreamMonitor;

    protected function setup(): void
    {
        $this->upstreamMonitor = new UpstreamMonitor(
            Config::get('blueprint.upstream_monitor.remote'),
            Config::get('blueprint.upstream_monitor.branch'),
        );
    }

    public function description(): string
    {
        return __('Number of commits behind');
    }

    public function componentName(): string
    {
        return 'Updates';
    }

    public function name(): string
    {
        return 'updates';
    }

    public function data(): array
    {
        return [
            'localBranch' => $this->upstreamMonitor->localBranch,
            'upstreamRemote' => $this->upstreamMonitor->upstreamRemote,
            'commitsBehind' => $this->getCommitsBehind(),
        ];
    }

    private function getCommitsBehind(): int
    {
        $cacheKey = 'updates:commits-behind';

        return Cache::remember($cacheKey, now()->addMinutes(10), function () {
            return $this->upstreamMonitor->getCommitsBehind();
        });
    }

    #[Action(title: 'Clear Cache', icon: 'RotateCw')]
    public function clearCache(): bool
    {
        Cache::forget('updates:commits-behind');

        return true;
    }

    public function shouldRender(): bool
    {
        return App::environment('local');
    }
}
