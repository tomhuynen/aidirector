<?php

declare(strict_types=1);

namespace App\Http\Middleware\Public;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'public';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        if (file_exists($manifest = public_path('assets/public/manifest.json'))) {
            return hash_file('xxh3', $manifest);
        }

        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'env' => config('app.env'),
                'title' => config('app.title'),
                'route' => route('public.home'),
            ],
            'account' => fn() => $this->account($request),
        ];
    }

    /**
     * @return array{name: string, email: string, links: array{projects: string, logout: string}}|null
     */
    private function account(Request $request): ?array
    {
        if (! $director = $request->user('director')) {
            return null;
        }

        return [
            'name' => $director->name,
            'email' => $director->email,
            'links' => [
                'projects' => route('public.projects.index'),
                'logout' => route('public.auth.logout'),
            ],
        ];
    }
}
