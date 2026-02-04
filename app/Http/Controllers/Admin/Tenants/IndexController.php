<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Models\Policies\TenantPolicy;
use App\Models\Tenant;
use App\Support\Admin\Page;
use App\Tables\Admin\Tenants;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IndexController
{
    public function index()
    {
        Gate::authorize(TenantPolicy::INDEX, Tenant::class);

        Page::actions()->index('tenants', Tenant::class, TenantPolicy::CREATE);

        return Inertia::render('tenants/index', [
            'tenants' => Tenants::make(),
        ]);
    }
}
