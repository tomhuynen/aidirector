<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Http\Resources\Admin\TenantResource;
use App\Models\Policies\TenantPolicy;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UpdateController
{
    public function update(Request $request, Tenant $tenant)
    {
        Gate::authorize(TenantPolicy::UPDATE, $tenant);

        return Inertia::render('tenants/update', [
            'tenant' => TenantResource::make($tenant),
        ]);
    }

    public function store(Request $request, Tenant $tenant)
    {
        Gate::authorize(TenantPolicy::UPDATE, $tenant);

        $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('tenants', 'name')->ignore($tenant->id)],
            'domain' => ['required', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
        ]);

        $tenant->fill($request->all());
        $tenant->save();

        return redirect()->route('admin.tenants.view', $tenant);
    }
}
