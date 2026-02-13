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

        $tenantConfig = [
            'driver' => 'scoped',
            'disk' => 'local',
            'prefix' => $prefix,
        ];

        $tenantCloudConfig = [
            'driver' => 'scoped',
            'disk' => 's3',
            'prefix' => $prefix,
        ];

        $tenantBackupConfig = [
            'driver' => 'scoped',
            'disk' => 'backup',
            'prefix' => $prefix,
        ];

        // Register disks with Storage facade
        Storage::set(Disk::TENANT->value, Storage::build($tenantConfig));
        Storage::set(Disk::TENANT_CLOUD->value, Storage::build($tenantCloudConfig));
        Storage::set(Disk::TENANT_BACKUP->value, Storage::build($tenantBackupConfig));

        // Also set config so packages like Spatie Media Library can find them
        config([
            'filesystems.disks.' . Disk::TENANT->value => $tenantConfig,
            'filesystems.disks.' . Disk::TENANT_CLOUD->value => $tenantCloudConfig,
            'filesystems.disks.' . Disk::TENANT_BACKUP->value => $tenantBackupConfig,
        ]);
    }

    public function forgetCurrent(): void
    {
        Storage::forgetDisk([
            Disk::TENANT->value,
            Disk::TENANT_CLOUD->value,
            Disk::TENANT_BACKUP->value,
        ]);

        // Also remove from config
        config([
            'filesystems.disks.' . Disk::TENANT->value => null,
            'filesystems.disks.' . Disk::TENANT_CLOUD->value => null,
            'filesystems.disks.' . Disk::TENANT_BACKUP->value => null,
        ]);
    }
}
