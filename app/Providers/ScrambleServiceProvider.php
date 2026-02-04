<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

class ScrambleServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();
    }
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->configure();
    }

    private function configure()
    {
        $apis = [
            'game' => ['/'],
            'admin' => ['admin', 'auth'],
        ];

        foreach ($apis as $name => $routes) {
            Scramble::registerApi($name)
                ->routes(fn(Route $route) => array_filter($routes, fn($segment) => str_starts_with($route->uri(), $segment)))
                ->expose(
                    ui: '/docs/' . $name,
                    document: '/docs/' . $name . '.json',
                );
        }
    }
}
