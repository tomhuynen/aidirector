<?php

declare(strict_types=1);

namespace App\Listeners\Tenant;

use App\Events\TenantDeleting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class CleanupBeforeTenantDeletion
{
    /**
     * Handle the event.
     */
    public function handle(TenantDeleting $event): void
    {
        $tenant = $event->tenant;
        $storagePrefix = Arr::get($tenant->settings, 'storage_prefix');

        if (! $storagePrefix) {
            return;
        }

        // Delete all local files in the tenant storage
        $localDisk = Storage::disk('local');

        if ($localDisk->exists($storagePrefix)) {
            $localDisk->deleteDirectory($storagePrefix);
        }

        // TODO: Handle s3 files
    }
}
