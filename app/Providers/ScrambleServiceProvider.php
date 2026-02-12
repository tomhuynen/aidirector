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
            'api' => fn(Route $r) => str_starts_with($r->uri(), 'api'),
            'admin' => fn(Route $r) => str_starts_with($r->uri(), 'admin') || str_starts_with($r->uri(), 'auth'),
            'public' => fn(Route $r) => ! collect(['admin', 'api', 'auth'])
                ->contains(fn(string $prefix) => str_starts_with($r->uri(), $prefix)),        ];

        foreach ($apis as $name => $routes) {
            Scramble::registerApi($name)
                ->routes($routes)
                ->expose(ui: "/docs/{$name}", document: "/docs/{$name}.json");
        }
    }
}
