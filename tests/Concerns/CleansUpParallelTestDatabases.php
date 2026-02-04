<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait CleansUpParallelTestDatabases
{
    /**
     * Register parallel testing cleanup callbacks.
     * Should be called from a service provider's boot method.
     */
    public static function registerCleanupCallbacks(): void
    {
        $cleanup = fn(int $token) => static::dropParallelTestDatabases($token);

        ParallelTesting::setUpProcess($cleanup);
        ParallelTesting::tearDownProcess($cleanup);
    }

    /**
     * Drop both tenant and landlord databases for a specific parallel testing token.
     */
    protected static function dropParallelTestDatabases(int $token): void
    {
        $baseLandlordDb = env('DB_DATABASE', 'testing');
        $parallelLandlordDb = "{$baseLandlordDb}_test_{$token}";
        $tenantDb = "{$parallelLandlordDb}-" . Str::slug("Testing-{$token}");

        // Drop tenant database first (may have foreign key references)
        Schema::dropDatabaseIfExists($tenantDb);

        // Drop the parallel landlord database
        Schema::dropDatabaseIfExists($parallelLandlordDb);
    }
}
