<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Events\TenantCreated;
use App\Models\Tenant;
use App\Support\Multitenancy\SetTenantStorage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

test('tenant storage task configures all tenant disks', function () {
    Event::fake([TenantCreated::class]);

    $tenant = Tenant::create([
        'name' => 'Multi Disk Test',
        'domain' => $this->uniqueDomain('multi-disk'),
    ]);

    $storageTask = new SetTenantStorage();

    $storageTask->makeCurrent($tenant);

    expect(Storage::disk(Disk::TENANT->value))->not->toBeNull()
        ->and(Storage::disk(Disk::TENANT_CLOUD->value))->not->toBeNull()
        ->and(Storage::disk(Disk::TENANT_BACKUP->value))->not->toBeNull();
});

test('it stores files in the tenant storage', function () {
    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'domain' => $this->uniqueDomain('storage'),
    ]);

    $tenant->makeCurrent();

    Storage::fake(Disk::TENANT->value);

    Storage::disk(Disk::TENANT->value)->put('test.txt', 'Hello, world!');

    expect(Storage::disk(Disk::TENANT->value)->exists('test.txt'))->toBeTrue();
});
