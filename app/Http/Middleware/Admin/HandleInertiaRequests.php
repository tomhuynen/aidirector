<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Support\Admin\Navigation;
use App\Support\Admin\NavigationItem;
use App\Support\Admin\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    protected $rootView = 'admin';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        if (str_starts_with($request->path(), 'admin')) {
            if (file_exists($manifest = public_path('assets/admin/manifest.json'))) {
                return hash_file('xxh3', $manifest);
            }
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
                'route' => route('admin.dashboard.index'),
                'account' => fn() => $this->account($request),
                'navigation' => fn() => $this->navigation($request),
            ],
            'isImpersonated' => fn() => Auth::user()?->isImpersonated() ?? false,
            'page' => fn() => $this->page($request),
        ];
    }

    /**
     * Get page state (breadcrumbs and actions) and flush for next request.
     *
     * @return array{breadcrumbs: array<int, array{title: string, href: string|null}>, actions: array<int, array{title: string, action: string, icon: string|null, disabled: bool}>}
     */
    private function page(Request $request): array
    {
        $page = Page::instance();
        $data = $page->toArray($request);
        $page->flush();

        return $data;
    }

    private function navigation(Request $request)
    {
        return Navigation::build($request);
    }

    private function account(Request $request): array
    {
        if (! $user = $request->user()) {
            return [];
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'links' => [
                new NavigationItem([
                    'title' => __('Profile'),
                    'route' => 'admin.settings.profile.edit',
                ], $request),
                new NavigationItem([
                    'title' => __('Security'),
                    'route' => 'admin.settings.security.view',
                ], $request),
                new NavigationItem([
                    'title' => __('Password'),
                    'route' => 'admin.settings.password.edit',
                ], $request),
                new NavigationItem([
                    'title' => __('Appearance'),
                    'route' => 'admin.settings.appearance',
                ], $request),
            ],
        ];
    }
}
