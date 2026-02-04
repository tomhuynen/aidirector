<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Models\Policies\TenantPolicy;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SwitchController
{
    public function update(Request $request, Tenant $tenant)
    {
        Gate::authorize(TenantPolicy::SWITCH, $tenant);

        $user = $request->user();
        $user->update([
            'tenant_id' => $tenant->id,
        ]);

        return redirect('/admin');
    }
}
