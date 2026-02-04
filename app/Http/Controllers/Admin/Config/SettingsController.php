<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Config;

use App\Http\Resources\Admin\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\Request;

class SettingsController
{
    public function index(Request $request)
    {
        return [
            'tenants' => $this->listTenants($request),
        ];
    }

    private function listTenants(Request $request)
    {
        $tenants = Tenant::query()
            ->orderBy('name')
            ->get();

        return TenantResource::collection($tenants);
    }
}
