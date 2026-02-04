<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;

trait RefreshMultitenantDatabase
{
    use RefreshDatabase;

    /**
     * The database connections that should have transactions.
     */
    protected function connectionsToTransact(): array
    {
        return ['landlord', 'tenant'];
    }

    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->performInitialMigration();
            RefreshDatabaseState::$migrated = true;
        }

        $this->ensureTenantContext();

        // Use Laravel's built-in transaction mechanism
        $this->beginDatabaseTransaction();
    }

    protected function performInitialMigration(): void
    {
        // Clean any leftover tenant databases from previous runs
        $this->cleanupTenantDatabases();

        // Migrate landlord database
        $this->artisan('migrate:fresh', [
            '--database' => 'landlord',
            '--path' => 'database/migrations/landlord',
        ]);

        // Create test tenant with process-unique name
        $tenant = Tenant::create([
            'name' => $this->getTestTenantName(),
            'domain' => Config::get('app.url'),
        ]);

        $tenant->makeCurrent();
    }

    protected function getTestTenantName(): string
    {
        $token = ParallelTesting::token();

        return $token ? "Testing-{$token}" : 'Testing';
    }

    protected function cleanupTenantDatabases(): void
    {
        // Drop expected tenant database directly (handles orphaned databases)
        $expectedDbName = $this->getExpectedTenantDatabaseName();
        Schema::dropDatabaseIfExists($expectedDbName);

        if (! Schema::connection('landlord')->hasTable('tenants')) {
            return;
        }

        // Clean tenants matching our naming pattern from the table
        $token = ParallelTesting::token();
        $pattern = $token ? "Testing-{$token}" : 'Testing';

        Tenant::where('name', 'like', $pattern . '%')
            ->get()
            ->each(fn(Tenant $t) => Schema::dropDatabaseIfExists($t->getDatabaseName()));
    }

    protected function getExpectedTenantDatabaseName(): string
    {
        $landlordDb = Config::get('database.connections.landlord.database');
        $tenantSlug = \Illuminate\Support\Str::slug($this->getTestTenantName());

        return "{$landlordDb}-{$tenantSlug}";
    }

    protected function ensureTenantContext(): void
    {
        if (Tenant::current()) {
            return;
        }

        $tenantName = $this->getTestTenantName();
        $tenant = Tenant::where('name', $tenantName)->first();

        $tenant?->makeCurrent();
    }
}
