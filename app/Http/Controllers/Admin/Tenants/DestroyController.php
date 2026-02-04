<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Models\Policies\TenantPolicy;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DestroyController
{
    public function destroy(Request $request, Tenant $tenant)
    {
        Gate::authorize(TenantPolicy::DESTROY, $tenant);

        $tenant->delete();

        return redirect()->route('admin.tenants.index');
    }
}
