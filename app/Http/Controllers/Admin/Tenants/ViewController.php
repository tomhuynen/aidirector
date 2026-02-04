<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Http\Resources\Admin\TenantResource;
use App\Models\Policies\TenantPolicy;
use App\Models\Tenant;
use App\Support\Admin\Page;
use App\Support\Admin\Page\PageAction;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ViewController
{
    public function view(Tenant $tenant)
    {
        Gate::authorize(TenantPolicy::VIEW, $tenant);

        Page::actions()
            ->add(
                PageAction::make(__('Edit'), route('admin.tenants.update', $tenant))
                    ->icon('Pencil')
                    ->can(TenantPolicy::UPDATE, $tenant)
            );

        return Inertia::render('tenants/view', [
            'tenant' => TenantResource::make($tenant),
        ]);
    }
}
