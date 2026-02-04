<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;

class IdentifyTenant
{
    public const SESSION_KEY = 'admin_tenant_id';

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $tenant = $this->getTenantFromUser($request);

        optional($tenant)->makeCurrent();

        return $next($request);
    }

    private function getTenantFromUser(Request $request)
    {
        $user = $request->user();
        if (empty($user)) {
            return;
        }

        $tenantID = $user->tenant_id;

        /** @var Tenant */
        return app(IsTenant::class)::find($tenantID);
    }
}
