<?php

declare(strict_types=1);

namespace App\Support\Multitenancy;

use App\Enums\Disk;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SetTenantStorage implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        /** @var Tenant $tenant */
        $prefix = $tenant->settings['storage_prefix'];

        Storage::set(Disk::TENANT->value, Storage::build([
            'driver' => 'scoped',
            'disk' => 'local',
            'prefix' => $prefix,
        ]));

        Storage::set(Disk::TENANT_CLOUD->value, Storage::build([
            'driver' => 'scoped',
            'disk' => 's3',
            'prefix' => $prefix,
        ]));

        Storage::set(Disk::TENANT_BACKUP->value, Storage::build([
            'driver' => 'scoped',
            'disk' => 'backup',
            'prefix' => $prefix,
        ]));
    }

    public function forgetCurrent(): void
    {
        Storage::forgetDisk([
            Disk::TENANT->value,
            Disk::TENANT_CLOUD->value,
            Disk::TENANT_BACKUP->value,
        ]);
    }
}
