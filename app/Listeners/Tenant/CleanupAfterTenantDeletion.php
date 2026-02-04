<?php

declare(strict_types=1);

namespace App\Listeners\Tenant;

use App\Events\TenantDeleted;
use Illuminate\Support\Facades\Schema;

class CleanupAfterTenantDeletion
{
    /**
     * Handle the event.
     */
    public function handle(TenantDeleted $event): void
    {
        $tenant = $event->tenant;

        Schema::dropDatabaseIfExists($tenant->getDatabaseName());
    }
}
