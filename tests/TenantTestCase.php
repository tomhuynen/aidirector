<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;

/**
 * Test case for tests that need to test tenant CRUD operations.
 *
 * Uses DatabaseMigrations instead of transactions because tenant operations
 * (database creation/deletion) cannot be rolled back by transactions.
 */
abstract class TenantTestCase extends BaseTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runLandlordMigrations();
    }

    protected function runLandlordMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'landlord',
            '--path' => 'database/migrations/landlord',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupTenantDatabases();

        parent::tearDown();
    }

    protected function cleanupTenantDatabases(): void
    {
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            return;
        }

        Tenant::all()->each(function (Tenant $tenant) {
            Schema::dropDatabaseIfExists($tenant->getDatabaseName());
        });
    }

    /**
     * Generate a unique domain for this test to avoid conflicts in parallel execution.
     */
    protected function uniqueDomain(string $base = 'test'): string
    {
        $token = ParallelTesting::token();
        $unique = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);

        return $token
            ? "{$base}-{$token}-{$unique}.test"
            : "{$base}-{$unique}.test";
    }
}
