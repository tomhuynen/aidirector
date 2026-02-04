<?php

declare(strict_types=1);

use App\Events\TenantCreated;
use App\Events\TenantCreating;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;

test('a tenant can be created with basic attributes', function () {
    Event::fake([TenantCreated::class]);

    $domain = $this->uniqueDomain('basic');

    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'domain' => $domain,
    ]);

    expect($tenant)->toBeInstanceOf(Tenant::class)
        ->and($tenant->name)->toBe('Test Tenant')
        ->and($tenant->domain)->toBe($domain)
        ->and($tenant->settings)->toBeArray()
        ->and($tenant->settings)->toHaveKey('storage_prefix');
});

test('tenant domains are formatted correctly', function () {
    Event::fake([TenantCreated::class]);

    $domain = $this->uniqueDomain('format');

    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'domain' => "https://{$domain}",
    ]);

    expect($tenant->domain)->toBe($domain);
});

test('tenant creation dispatches creating and created events', function () {
    Event::fake([TenantCreating::class, TenantCreated::class]);

    $domain = $this->uniqueDomain('events');

    $tenant = Tenant::create([
        'name' => 'Event Test Tenant',
        'domain' => $domain,
    ]);

    Event::assertDispatched(TenantCreating::class, fn($event) => $event->tenant->is($tenant));
    Event::assertDispatched(TenantCreated::class, fn($event) => $event->tenant->is($tenant));
});

test('tenant is created with a unique storage prefix', function () {
    Event::fake([TenantCreated::class]);

    $tenant1 = Tenant::create([
        'name' => 'Tenant One',
        'domain' => $this->uniqueDomain('one'),
    ]);

    $tenant2 = Tenant::create([
        'name' => 'Tenant Two',
        'domain' => $this->uniqueDomain('two'),
    ]);

    expect($tenant1->settings['storage_prefix'])->not->toBe($tenant2->settings['storage_prefix'])
        ->and(strlen((string) $tenant1->settings['storage_prefix']))->toBe(10)
        ->and(strlen((string) $tenant2->settings['storage_prefix']))->toBe(10);
});

test('tenant domains must be unique', function () {
    Event::fake([TenantCreated::class]);

    $domain = $this->uniqueDomain('duplicate');

    Tenant::create([
        'name' => 'First Tenant',
        'domain' => $domain,
    ]);

    expect(fn() => Tenant::create([
        'name' => 'Second Tenant',
        'domain' => $domain,
    ]))->toThrow(Exception::class);
});
