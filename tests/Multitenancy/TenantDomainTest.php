<?php

declare(strict_types=1);

use App\Events\TenantCreated;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

test('tenant generates correct database name', function () {
    Event::fake([TenantCreated::class]);

    $landlordDb = config('database.connections.landlord.database');

    $tenant = Tenant::create([
        'name' => 'Database Test',
        'domain' => $this->uniqueDomain('database'),
    ]);

    $dbName = $tenant->getDatabaseName();

    // Database name is based on slugified tenant name
    expect($dbName)->toBe("{$landlordDb}-" . Str::slug($tenant->name));
});
