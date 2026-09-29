<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The admin (users, Fortify) and the public app (directors) have separate
 * guards and login pages. Guest and authenticated redirects pick the right
 * one from the requested path.
 */
class FrontendRedirect
{
    public static function isAdmin(Request $request): bool
    {
        return Str::startsWith($request->path(), ['admin', 'auth']);
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
}
