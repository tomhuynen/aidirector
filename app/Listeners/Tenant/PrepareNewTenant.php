<?php

declare(strict_types=1);

namespace App\Listeners\Tenant;

use App\Events\TenantCreated;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class PrepareNewTenant
{
    /**
     * Handle the event.
     */
    public function handle(TenantCreated $event): void
    {
        $tenant = $event->tenant;

        $this->createDatabase($tenant);
        $this->migrateDatabase($tenant);
        $this->syncPermissions($tenant);
    }

    private function createDatabase(Tenant $tenant)
    {
        Schema::createDatabase($tenant->getDatabaseName());
    }

    private function migrateDatabase(Tenant $tenant)
    {
        $command = vsprintf('tenants:artisan "migrate --database=tenant --path=%s" --tenant=%d', [
            'database/migrations/tenant',
            $tenant->id,
        ]);

        Artisan::call($command);
    }

    private function syncPermissions(Tenant $tenant)
    {
        Artisan::call('app:permission-sync');
    }
}
