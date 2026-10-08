<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\Director;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project API is for directors' tokens; operator tokens have no projects.
 */
class EnsureDirector
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof Director, 403);

        return $next($request);
    }
}
