<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Decides whether a request belongs to the admin or the public frontend.
 *
 * Fortify serves both frontends from the same endpoints, so redirects after
 * login and logout look at where the request came from.
 */
class FrontendRedirect
{
    public static function isAdmin(Request $request): bool
    {
        if (Str::startsWith($request->path(), 'admin')) {
            return true;
        }

        return Str::startsWith(self::previousPath($request), ['admin', 'auth']);
    }

    public static function homeFor(Request $request): string
    {
        return self::isAdmin($request)
            ? route('admin.dashboard.index')
            : route('public.projects.index');
    }

    public static function loginFor(Request $request): string
    {
        return self::isAdmin($request)
            ? route('login')
            : route('public.auth.login');
    }

    private static function previousPath(Request $request): string
    {
        $previous = $request->session()->previousUrl() ?? url()->previous();

        return ltrim((string) parse_url($previous, PHP_URL_PATH), '/');
    }
}
