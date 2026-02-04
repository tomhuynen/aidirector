<?php

declare(strict_types=1);

namespace App\Support\Multitenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\URL;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SetBaseUrl implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        /** @var Tenant $tenant */
        URL::formatHostUsing(fn() => URL::formatScheme() . $tenant->domain);
    }

    public function forgetCurrent(): void
    {
        URL::formatHostUsing(fn() => config('app.url'));
    }
}
