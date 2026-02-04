<?php

declare(strict_types=1);

use App\Events\TenantCreated;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;

test('can make a tenant current', function () {
    Event::fake([TenantCreated::class]);

    $tenant = Tenant::create([
        'name' => 'Current Test',
        'domain' => $this->uniqueDomain('current'),
    ]);

    $tenant->makeCurrent();

    expect(Tenant::current())->not->toBeNull()
        ->and(Tenant::current()->id)->toBe($tenant->id);
});

test('can check if a tenant is current', function () {
    Event::fake([TenantCreated::class]);

    $tenant = Tenant::create([
        'name' => 'Is Current Test',
        'domain' => $this->uniqueDomain('is-current'),
    ]);

    expect($tenant->isCurrent())->toBeFalse();

    $tenant->makeCurrent();

    expect($tenant->isCurrent())->toBeTrue();
});

test('can forget current tenant', function () {
    Event::fake([TenantCreated::class]);

    $tenant = Tenant::create([
        'name' => 'Forget Test',
        'domain' => $this->uniqueDomain('forget'),
    ]);

    $tenant->makeCurrent();
    expect(Tenant::current())->not->toBeNull();

    Tenant::forgetCurrent();

    expect(Tenant::current())->toBeNull();
});

test('can switch between tenants', function () {
    Event::fake([TenantCreated::class]);

    $tenant1 = Tenant::create([
        'name' => 'First Tenant',
        'domain' => $this->uniqueDomain('first'),
    ]);

    $tenant2 = Tenant::create([
        'name' => 'Second Tenant',
        'domain' => $this->uniqueDomain('second'),
    ]);

    $tenant1->makeCurrent();
    expect(Tenant::current()->id)->toBe($tenant1->id);

    $tenant2->makeCurrent();
    expect(Tenant::current()->id)->toBe($tenant2->id);
});
