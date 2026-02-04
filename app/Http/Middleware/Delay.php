<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Support\Facades\App;

class Delay extends Middleware
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (App::isLocal() && config('blueprint.local_delay_enabled')) {
            usleep(random_int(250_000, 1_500_000));
        }

        return $next($request);
    }
}
