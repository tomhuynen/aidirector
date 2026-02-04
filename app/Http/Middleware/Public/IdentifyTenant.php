<?php

declare(strict_types=1);

namespace App\Http\Middleware\Public;

use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Exceptions\NoCurrentTenant;
use Spatie\Multitenancy\TenantFinder\DomainTenantFinder;

class IdentifyTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $tenant = new DomainTenantFinder()->findForRequest($request);

        if (empty($tenant)) {
            throw NoCurrentTenant::make();
        }

        $tenant->makeCurrent();

        return $next($request);
    }
}
