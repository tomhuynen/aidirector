<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Events\TenantDeleted;
use App\Events\TenantDeleting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

test('tenant deletion dispatches deleting and deleted events', function () {
    Event::fake([TenantDeleting::class, TenantDeleted::class]);

    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'domain' => $this->uniqueDomain('delete-events'),
    ]);

    $tenant->makeCurrent();
    $tenant->delete();

    Event::assertDispatched(TenantDeleting::class, fn($event) => $event->tenant->is($tenant));
    Event::assertDispatched(TenantDeleted::class, fn($event) => $event->tenant->is($tenant));

    // Handle cleanup manually since events are faked
    Schema::dropDatabaseIfExists($tenant->getDatabaseName());
});

test('the tenant database is dropped', function () {
    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'domain' => $this->uniqueDomain('delete-db'),
    ]);

    $tenant->makeCurrent();

    expect(DB::select("SHOW DATABASES LIKE '{$tenant->getDatabaseName()}'"))->not->toBeEmpty();

    $tenant->delete();

    expect(DB::select("SHOW DATABASES LIKE '{$tenant->getDatabaseName()}'"))->toBeEmpty();
});

test('the tenant storage is deleted', function () {
    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'domain' => $this->uniqueDomain('delete-storage'),
    ]);

    $tenant->makeCurrent();

    Storage::disk(Disk::TENANT->value)->put('test.txt', 'Hello, world!');

    expect(Storage::disk(Disk::TENANT->value)->exists('test.txt'))->toBeTrue();

    $storagePrefix = $tenant->settings->storagePrefix;

    $tenant->delete();

    expect(Storage::disk('local')->exists($storagePrefix))->toBeFalse();
});
